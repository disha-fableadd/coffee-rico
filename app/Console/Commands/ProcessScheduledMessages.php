<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\BulkMessage;
use App\Models\Contact;
use App\Models\Template;
use App\Models\ActivePackage;
use App\Models\Settings;
use App\Models\MessageDeliveryStatus;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ProcessScheduledMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bulk:process-scheduled-messages';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process scheduled bulk messages and send them when their scheduled time arrives';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (!config('whatsapp.bulk_send_enabled')) {
            $this->info('Bulk send is disabled (BULK_SEND_ENABLED=false). No messages will be sent.');
            return 0;
        }

        $this->info('Starting to process scheduled messages...');

        // Set timezone to UTC to avoid server time issues
        date_default_timezone_set('Asia/Kolkata');

        try {
            // Get current time with correct timezone
            $now = Carbon::now('Asia/Kolkata');

            $this->info('Current server time: ' . $now->format('Y-m-d H:i:s T'));

            // Find all scheduled messages that are due to be sent
            // Compare scheduleAt directly with current time (both should be in same timezone)
            $scheduledMessages = BulkMessage::where('status', 'scheduled')
                ->whereNotNull('scheduleAt')
                ->where('scheduleAt', '<=', $now)
                ->with(['template', 'user'])
                ->get();

            if ($scheduledMessages->isEmpty()) {
                $this->info('No scheduled messages found to process.');
                return;
            }

            $this->info("Found {$scheduledMessages->count()} scheduled messages to process.");

            // Debug: Show scheduled times
            foreach ($scheduledMessages as $campaign) {
                $this->info("Campaign '{$campaign->name}' scheduled for: " . $campaign->scheduleAt);
            }

            foreach ($scheduledMessages as $campaign) {
                $this->processScheduledCampaign($campaign);
            }

            $this->info('Scheduled messages processing completed.');

        } catch (\Exception $e) {
            $this->error('Error processing scheduled messages: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }

    /**
     * Process a single scheduled campaign
     */
    private function processScheduledCampaign($campaign)
    {
        try {
            $this->info("Processing campaign: {$campaign->name} (ID: {$campaign->id})");

            // Atomically update campaign status to prevent race conditions
            // Only update if status is still 'scheduled' (prevents duplicate processing)
            $updated = BulkMessage::where('id', $campaign->id)
                ->where('status', 'scheduled')
                ->update([
                    'status' => 'sending',
                    'sendingDate' => Carbon::now()
                ]);

            // If update failed, another process is already handling this campaign
            if ($updated === 0) {
                $this->info("Campaign {$campaign->name} (ID: {$campaign->id}) is already being processed by another process. Skipping.");
                return;
            }

            // Refresh the campaign model to get updated status
            $campaign->refresh();

            // Get the template
            $template = $campaign->template;
            if (!$template) {
                $this->error("Template not found for campaign ID: {$campaign->id}");
                $campaign->update(['status' => 'failed']);
                return;
            }

            if ($template->status !== 'APPROVED') {
                $this->error("Template is not approved for campaign ID: {$campaign->id}");
                $campaign->update(['status' => 'failed']);
                return;
            }

            // Reset messages stuck in "processing" for more than 5 minutes back to "pending"
            MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                ->where('status', 'processing')
                ->where('updated_at', '<', now()->subMinutes(5))
                ->update(['status' => 'pending']);

            // Fetch contacts that have pending delivery status (to prevent duplicate sends)
            // Only process contacts that haven't been sent yet
            $pendingDeliveryStatuses = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                ->whereIn('status', ['pending', 'processing'])
                ->pluck('contact_id');

            if ($pendingDeliveryStatuses->isEmpty()) {
                // Check if all messages are already processed
                $allStatuses = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)->get();
                $pendingCount = $allStatuses->whereIn('status', ['pending', 'processing'])->count();

                if ($pendingCount === 0) {
                    // All messages already processed, update campaign status
                    $this->updateCampaignStatus($campaign);
                    $this->info("Campaign {$campaign->name} already processed.");
                    return;
                }
            }

            // Fetch only contacts that have pending delivery status
            $contacts = Contact::whereIn('id', $pendingDeliveryStatuses)
                ->where('userId', $campaign->userId)
                ->get();

            if ($contacts->isEmpty()) {
                $this->error("No pending contacts found for campaign ID: {$campaign->id}");
                $campaign->update(['status' => 'failed']);
                return;
            }

            // Check active package
            $activePlan = ActivePackage::where('userId', $campaign->userId)
                ->where('status', 1)
                ->with('package')
                ->first();

            if (!$activePlan) {
                $this->error("No active plan found for user ID: {$campaign->userId}");
                $campaign->update(['status' => 'failed']);
                return;
            }

            // Check if package is still active
            $currentDate = Carbon::now();
            if ($activePlan->endDate && $currentDate->gt($activePlan->endDate)) {
                $activePlan->update(['status' => 0]);
                $this->error("Package has expired for user ID: {$campaign->userId}");
                $campaign->update(['status' => 'failed']);
                return;
            }

            $allowedMsgCount = $activePlan->package->msgCount ?? 0;
            $usedMsgCount = $activePlan->monthlyUsedMsgCount ?? 0;
            $remainingMsg = $allowedMsgCount - $usedMsgCount;

            if ($contacts->count() > $remainingMsg) {
                $this->error("Insufficient message quota for campaign ID: {$campaign->id}");
                $campaign->update(['status' => 'failed']);
                return;
            }

            // Process the messages
            $this->processBulkMessages($campaign, $contacts, $template, $activePlan);

            $this->info("Campaign {$campaign->name} processed successfully.");

        } catch (\Exception $e) {
            $this->error("Error processing campaign ID {$campaign->id}: " . $e->getMessage());
            $campaign->update(['status' => 'failed']);
        }
    }

    /**
     * Process bulk messages (send WhatsApp messages) in batches
     */
    private function processBulkMessages($campaign, $contacts, $template, $activePlan)
    {
        // Get batch processing configuration from settings
        $settings = Settings::where('isActive', true)->first();
        $batchSize = $settings->batch_size ?? 50; // Default to 50 if not configured
        $batchDelay = $settings->batch_delay_seconds ?? 2; // Default to 2 seconds if not configured
        $enableBatchProcessing = $settings->enable_batch_processing ?? true; // Default to true

        $totalContacts = $contacts->count();
        $successCount = 0;
        $failCount = 0;
        $whatsappService = new WhatsAppService();

        // Update campaign status to processing
        $campaign->update(['status' => 'processing']);

        $this->info("Starting batch processing for campaign: {$campaign->name}");
        $this->info("Total contacts: {$totalContacts}, Batch size: {$batchSize}, Batch processing: " . ($enableBatchProcessing ? 'enabled' : 'disabled'));

        // If batch processing is disabled, process all at once
        if (!$enableBatchProcessing) {
            $batchSize = $totalContacts; // Process all at once
        }

        // Process contacts in batches
        $contactChunks = $contacts->chunk($batchSize);
        $totalBatches = $contactChunks->count();

        $this->info("Processing {$totalBatches} batches...");

        Log::info("Starting batch processing", [
            'campaign_id' => $campaign->id,
            'total_contacts' => $totalContacts,
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches
        ]);

        foreach ($contactChunks as $batchIndex => $batchContacts) {
            $batchNumber = $batchIndex + 1;
            $this->info("Processing batch {$batchNumber}/{$totalBatches} ({$batchContacts->count()} contacts)");

            Log::info("Processing batch {$batchNumber}/{$totalBatches}", [
                'batch_contacts' => $batchContacts->count(),
                'campaign_id' => $campaign->id
            ]);

            // Process each contact in the current batch
            foreach ($batchContacts as $contact) {
                try {
                    // Atomically update delivery status to 'processing' to prevent duplicate sends
                    // Only update if status is still 'pending' (prevents race conditions)
                    $updated = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                        ->where('contact_id', $contact->id)
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

                    // Get the delivery status after atomic update
                    $deliveryStatus = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                        ->where('contact_id', $contact->id)
                        ->first();

                    if (!$deliveryStatus) {
                        continue; // Safety check
                    }

                    $result = null;

                    // Format phone number with country code
                    $formattedPhone = $this->formatPhoneNumber($contact->phone);

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
                        $successCount++;
                        $deliveryStatus->updateStatus('sent', [
                            'whatsapp_message_id' => $result['message_id'] ?? null,
                            'sent_at' => now()->toISOString(),
                            'api_response' => $result
                        ]);
                    } else {
                        $failCount++;
                        $deliveryStatus->updateStatus('failed', [
                            'error' => $result['error'] ?? 'Unknown error',
                            'failed_at' => now()->toISOString(),
                            'api_response' => $result
                        ]);
                    }
                } catch (\Exception $e) {
                    $failCount++;
                    $deliveryStatus = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                        ->where('contact_id', $contact->id)
                        ->first();
                    if ($deliveryStatus) {
                        $deliveryStatus->updateStatus('failed', [
                            'error' => $e->getMessage(),
                            'failed_at' => now()->toISOString()
                        ]);
                    }
                }
            }

            // Update campaign status based on current delivery statuses
            $this->updateCampaignStatus($campaign);

            // Add delay between batches (except for the last batch and if batch processing is enabled)
            if ($batchNumber < $totalBatches && $enableBatchProcessing) {
                $this->info("Waiting {$batchDelay} seconds before next batch...");
                Log::info("Waiting {$batchDelay} seconds before next batch...");
                sleep($batchDelay);
            }
        }

        // Final update to campaign status based on delivery statuses
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
                        $finalStatus = 'failed';
                    } else {
                        // High success rate, mark as completed
                        $finalStatus = 'completed';
                    }
                } else {
                    // No processed messages, mark as failed
                    $finalStatus = 'failed';
                }
            } else {
                // No failed messages, mark as completed
                $finalStatus = 'completed';
            }
        } else {
            // Still has pending or processing messages
            $finalStatus = 'processing';
        }

        $campaign->update([
            'status' => $finalStatus
        ]);

        // Update used message count
        if ($successCount > 0) {
            $activePlan->increment('usedMsgCount', $successCount);
            $activePlan->increment('monthlyUsedMsgCount', $successCount);
        }

        Log::info("Batch processing completed", [
            'campaign_id' => $campaign->id,
            'total_contacts' => $totalContacts,
            'success_count' => $successCount,
            'fail_count' => $failCount,
            'final_status' => $finalStatus
        ]);

        $this->info("Campaign completed: {$successCount} sent, {$failCount} failed, Status: {$finalStatus}");
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
}
