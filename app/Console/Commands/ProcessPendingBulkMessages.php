<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BulkMessage;
use App\Models\MessageDeliveryStatus;
use App\Models\Contact;
use App\Models\Template;
use App\Models\ActivePackage;
use App\Models\Settings;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class ProcessPendingBulkMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bulk:process-pending-messages {--limit=100 : Number of messages to process per run}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process pending bulk messages in small batches to avoid timeouts';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('whatsapp.bulk_send_enabled')) {
            $this->info('Bulk send is disabled (BULK_SEND_ENABLED=false). No messages will be sent.');
            return 0;
        }

        $limit = (int) $this->option('limit');

        $this->info("Processing up to {$limit} pending messages...");

        try {
            // Get batch processing configuration from settings
            $settings = Settings::where('isActive', true)->first();
            $batchSize = $settings->batch_size ?? 100; // Default to 100 messages per run
            $messagesPerRun = max($limit, $batchSize); // Use the larger value

            // Find campaigns that are in 'pending' or 'processing' status
            // Exclude scheduled campaigns (those with scheduleAt) - they should only be processed by ProcessScheduledMessages
            $pendingCampaigns = BulkMessage::whereIn('status', ['pending', 'processing'])
                ->whereNull('scheduleAt') // Exclude scheduled campaigns
                ->with(['template', 'user'])
                ->orderBy('created_at', 'asc')
                ->get();

            if ($pendingCampaigns->isEmpty()) {
                $this->info('No pending campaigns found.');
                return 0;
            }

            $this->info("Found {$pendingCampaigns->count()} pending/processing campaigns.");

            $processedCount = 0;
            $whatsappService = new WhatsAppService();

            // Pre-load active plans for all campaigns to avoid repeated queries
            $campaignUserIds = $pendingCampaigns->pluck('userId')->unique();
            $activePlans = ActivePackage::whereIn('userId', $campaignUserIds)
                ->where('status', 1)
                ->get()
                ->keyBy('userId');

            foreach ($pendingCampaigns as $campaign) {
                if ($processedCount >= $messagesPerRun) {
                    break;
                }

                // Skip scheduled campaigns - they should only be processed by ProcessScheduledMessages
                if ($campaign->scheduleAt !== null) {
                    continue;
                }

                // Get pending delivery statuses for this campaign
                // Also reset messages stuck in "processing" for more than 5 minutes back to "pending"
                MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                    ->where('status', 'processing')
                    ->where('updated_at', '<', now()->subMinutes(5))
                    ->update(['status' => 'pending']);

                $pendingStatuses = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->with('contact')
                    ->limit($messagesPerRun - $processedCount)
                    ->get();

                if ($pendingStatuses->isEmpty()) {
                    // Check if all messages are processed
                    $allStatuses = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)->get();
                    $pendingCount = $allStatuses->whereIn('status', ['pending', 'processing'])->count();

                    if ($pendingCount === 0) {
                        // All messages processed, update campaign status
                        $this->updateCampaignStatus($campaign);
                    }
                    continue;
                }

                // Only log if processing more than 5 messages to reduce log noise
                if ($pendingStatuses->count() > 5) {
                    $this->info("Processing campaign: {$campaign->name} (ID: {$campaign->id}) - {$pendingStatuses->count()} messages");
                }

                // Atomically update campaign status to processing if it was pending
                // This prevents race conditions if multiple processes try to process the same campaign
                BulkMessage::where('id', $campaign->id)
                    ->where('status', 'pending')
                    ->update(['status' => 'processing']);
                
                // Refresh campaign to get updated status
                $campaign->refresh();

                // Process each pending message
                foreach ($pendingStatuses as $deliveryStatus) {
                    if ($processedCount >= $messagesPerRun) {
                        break;
                    }

                    try {
                        // Atomically update delivery status to 'processing' to prevent duplicate sends
                        // Only update if status is still 'pending' (prevents race conditions)
                        $updated = MessageDeliveryStatus::where('id', $deliveryStatus->id)
                            ->whereIn('status', ['pending'])
                            ->update([
                                'status' => 'processing',
                                'metadata' => ['processing_started_at' => now()->toISOString()],
                                'updated_at' => now()
                            ]);

                        // If update failed, this contact is already being processed or sent
                        if ($updated === 0) {
                            continue; // Skip this contact as it's already processed
                        }

                        // Refresh the delivery status after atomic update
                        $deliveryStatus->refresh();

                        $contact = $deliveryStatus->contact;
                        if (!$contact) {
                            $deliveryStatus->updateStatus('failed', [
                                'error' => 'Contact not found',
                                'failed_at' => now()->toISOString()
                            ]);
                            $processedCount++;
                            continue;
                        }

                        $template = $campaign->template;
                        if (!$template) {
                            $deliveryStatus->updateStatus('failed', [
                                'error' => 'Template not found',
                                'failed_at' => now()->toISOString()
                            ]);
                            $processedCount++;
                            continue;
                        }

                        // Format phone number with country code
                        $formattedPhone = $this->formatPhoneNumber($contact->phone);

                        $result = null;

                        // Check if template is custom or system
                        if ($template->type === 'custom') {
                            $result = $whatsappService->sendCustomTemplate(
                                $formattedPhone,
                                $template->name,
                                $campaign->variables,
                                $contact->toArray()
                            );
                        } else {
                            // System template with components
                            $result = $whatsappService->sendWhatsAppMessageSelected(
                                $formattedPhone,
                                $template->name,
                                $template->language ?? 'en',
                                $template->components ?? [],
                                $campaign->variables,
                                $campaign->headerVariables,
                                $contact->toArray()
                            );
                        }

                        // Update delivery status based on result
                        if ($result && ($result['success'] ?? false)) {
                            $deliveryStatus->updateStatus('sent', [
                                'whatsapp_message_id' => $result['message_id'] ?? null,
                                'sent_at' => now()->toISOString(),
                                'api_response' => $result
                            ]);

                            // Increment used message count (use pre-loaded active plan)
                            if (isset($activePlans[$campaign->userId])) {
                                $activePlans[$campaign->userId]->increment('usedMsgCount');
                                $activePlans[$campaign->userId]->increment('monthlyUsedMsgCount');
                            }
                        } else {
                            $error = $result['error'] ?? 'Unknown error';
                            $deliveryStatus->updateStatus('failed', [
                                'error' => $error,
                                'failed_at' => now()->toISOString(),
                                'api_response' => $result
                            ]);
                        }

                        $processedCount++;

                    } catch (\Exception $e) {
                        $deliveryStatus->updateStatus('failed', [
                            'error' => $e->getMessage(),
                            'failed_at' => now()->toISOString()
                        ]);
                        $processedCount++;
                    }

                    // Minimal delay between messages to avoid rate limiting
                    // Reduced delay for faster processing (0.05s = 20 messages/second max)
                    usleep(50000); // 0.05 seconds
                }

                // Update campaign status after processing batch
                $this->updateCampaignStatus($campaign);
            }

            $this->info("Processed {$processedCount} messages.");

            return 0;

        } catch (\Exception $e) {
            $this->error('Error processing pending messages: ' . $e->getMessage());
            Log::error('ProcessPendingBulkMessages error:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }
    }

    /**
     * Update campaign status based on delivery statuses
     */
    private function updateCampaignStatus($campaign)
    {
        $deliverySummary = $campaign->getDeliveryStatusSummary();

        // Check if processing is complete (no pending or processing messages)
        $isProcessingComplete = $deliverySummary['pending'] === 0 && $deliverySummary['processing'] === 0;

        if ($isProcessingComplete) {
            // Processing is complete - check if there are failed messages
            if ($deliverySummary['failed'] > 0) {
                // If there are failed messages, mark as failed
                // Only mark as completed if sent messages significantly outnumber failed (e.g., >80% success rate)
                $totalProcessed = $deliverySummary['sent'] + $deliverySummary['failed'];
                if ($totalProcessed > 0) {
                    $successRate = ($deliverySummary['sent'] / $totalProcessed) * 100;
                    // If success rate is less than 80%, mark as failed
                    if ($successRate < 80) {
                        $status = 'failed';
                    } else {
                        // High success rate, mark as completed
                        $status = 'completed';
                    }
                } else {
                    // No processed messages, mark as failed
                    $status = 'failed';
                }
            } else {
                // No failed messages, mark as completed
                $status = 'completed';
            }
        } else {
            // Still has pending or processing messages
            $status = 'processing';
        }

        $campaign->update(['status' => $status]);

        Log::info("Campaign status updated", [
            'campaign_id' => $campaign->id,
            'new_status' => $status,
            'delivery_summary' => $deliverySummary
        ]);
    }

    /**
     * Format phone number with country code if not present
     */
    private function formatPhoneNumber($phone)
    {
        // Remove any existing + or spaces
        $phone = preg_replace('/[\s\+]/', '', $phone);

        // If phone doesn't start with country code, add +91
        if (!preg_match('/^91\d{10}$/', $phone) && !preg_match('/^\+\d+/', $phone)) {
            // Remove leading 0 if present
            $phone = ltrim($phone, '0');
            $phone = '+91' . $phone;
        } elseif (preg_match('/^91\d{10}$/', $phone)) {
            // If it starts with 91 but no +, add +
            $phone = '+' . $phone;
        }

        return $phone;
    }
}

