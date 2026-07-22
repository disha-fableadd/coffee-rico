<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BulkMessage;
use App\Models\Contact;
use App\Models\Template;
use App\Models\ActivePackage;
use App\Models\MessageDeliveryStatus;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Settings;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BulkMessageController extends Controller
{
    /**
     * Get all bulk messages for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $status = $request->query('status');

            $query = BulkMessage::where('userId', $userId)->with(['template', 'contact']);

            if ($status) {
                $query->where('status', $status);
            }

            $bulkMessages = $query->orderBy('created_at', 'desc')->paginate(15);

            return response()->json([
                'status' => true,
                'data' => $bulkMessages
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new bulk message campaign
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'templateId' => 'required|exists:templates,id',
            'variables' => 'nullable|array',
            'headervariables' => 'nullable|array',
            'contacts' => 'required|array',
            'contacts.*' => 'exists:contacts,id',
            'scheduleAt' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        if (!config('whatsapp.bulk_send_enabled')) {
            return response()->json([
                'status' => false,
                'message' => 'Bulk send is currently disabled. No messages will be sent and no charges will be applied.',
            ], 500);
        }

        try {
            $userId = $request->user()->id;

            // 1. Load template
            $template = Template::find($request->templateId);
            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            if ($template->status !== 'APPROVED') {
                return response()->json([
                    'status' => false,
                    'message' => 'Template is not approved. Cannot send campaign.'
                ], 400);
            }

            // 2. Fetch contacts
            $contacts = Contact::whereIn('id', $request->contacts)
                ->where('userId', $userId)
                ->get();

            if ($contacts->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No valid contacts found'
                ], 400);
            }

            // 3. Check global active package
            $activePlan = ActivePackage::where('status', 1)
                ->with('package')
                ->latest()
                ->first();

            if (!$activePlan) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active plan found. Please purchase a plan first.'
                ], 400);
            }

            // Check if package is still active based on dates
            $currentDate = now();
            if ($activePlan->endDate && $currentDate->gt($activePlan->endDate)) {
                // Package has expired, update status
                $activePlan->update(['status' => 0]);
                return response()->json([
                    'status' => false,
                    'message' => 'Your package has expired. Please renew your plan.'
                ], 400);
            }

            $allowedMsgCount = $activePlan->package->msgCount ?? 0;
            // Get monthly used message count (all users combined)
            $usedMsgCount = ActivePackage::where('status', 1)->sum('monthlyUsedMsgCount');
            $remainingMsg = $allowedMsgCount - $usedMsgCount;

            // Debug information
            Log::info("Message Limit Check", [
                'userId' => $userId,
                'userName' => $request->user()->name,
                'packageName' => $activePlan->package->packageName,
                'allowedMsgCount' => $allowedMsgCount,
                'usedMsgCount' => $usedMsgCount,
                'remainingMsg' => $remainingMsg,
                'requestedContacts' => $contacts->count()
            ]);

            if ($contacts->count() > $remainingMsg) {
                return response()->json([
                    'status' => false,
                    'message' => "Your plan allows only {$remainingMsg} more messages. You are trying to send {$contacts->count()} messages. Please upgrade your plan."
                ], 500);
            }

            // 4. Create campaign
            $campaign = BulkMessage::create([
                'userId' => $userId,
                'name' => $request->name,
                'templateId' => $request->templateId,
                'variables' => $request->variables ?? [],
                'headerVariables' => $request->headervariables ?? [],
                'contactIds' => $request->contacts,
                'scheduleAt' => $request->scheduleAt,
                'status' => $request->scheduleAt ? 'scheduled' : 'pending', // Mark as pending for scheduled command to process
            ]);

            // 5. Create delivery status records for each contact
            foreach ($contacts as $contact) {
                MessageDeliveryStatus::create([
                    'bulk_message_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'status' => 'pending',
                ]);
            }

            // 6. Messages will be processed by the scheduled command (bulk:process-pending-messages)
            // This prevents timeout errors by processing in small batches

            return response()->json([
                'status' => true,
                'message' => 'Campaign created successfully',
                'data' => $campaign->load(['template', 'contact'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a campaign (only scheduled campaigns can be updated)
     */
    public function updateCampaign(Request $request, $id)
    {
        // Handle both JSON and form-data formats
        // If variables/contacts come as JSON strings (from form-data), decode them
        $data = $request->all();

        // Handle variables array - could be JSON string or array
        if (isset($data['variables'])) {
            if (is_string($data['variables'])) {
                $decoded = json_decode($data['variables'], true);
                $data['variables'] = $decoded !== null ? $decoded : [];
            }
        }

        // Handle headervariables array - could be JSON string or array
        if (isset($data['headervariables'])) {
            if (is_string($data['headervariables'])) {
                $decoded = json_decode($data['headervariables'], true);
                $data['headervariables'] = $decoded !== null ? $decoded : [];
            }
        }

        // Handle contacts array - could be JSON string or array
        if (isset($data['contacts'])) {
            if (is_string($data['contacts'])) {
                $decoded = json_decode($data['contacts'], true);
                $data['contacts'] = $decoded !== null ? $decoded : [];
            }
        }

        // Merge decoded data back into request
        $request->merge($data);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'templateId' => 'required|exists:templates,id',
            'variables' => 'nullable|array',
            'headervariables' => 'nullable|array',
            'contacts' => 'required|array',
            'contacts.*' => 'exists:contacts,id',
            'scheduleAt' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            // Find the campaign - admin can update any campaign, regular users can only update their own
            $query = BulkMessage::where('id', $id);
            if (!$isAdmin) {
                $query->where('userId', $userId);
            }

            $campaign = $query->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            // Check if campaign is scheduled - only scheduled campaigns can be updated
            if ($campaign->status !== 'scheduled') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only scheduled campaigns can be updated'
                ], 403);
            }

            // 1. Load template
            $template = Template::find($request->templateId);
            if (!$template) {
                return response()->json([
                    'status' => false,
                    'message' => 'Template not found'
                ], 404);
            }

            if ($template->status !== 'APPROVED') {
                return response()->json([
                    'status' => false,
                    'message' => 'Template is not approved. Cannot update campaign.'
                ], 400);
            }

            // 2. Fetch contacts
            $contacts = Contact::whereIn('id', $request->contacts)
                ->where('userId', $userId)
                ->get();

            if ($contacts->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No valid contacts found'
                ], 400);
            }

            // 3. Check global active package
            $activePlan = ActivePackage::where('status', 1)
                ->with('package')
                ->latest()
                ->first();

            if (!$activePlan) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active plan found. Please purchase a plan first.'
                ], 400);
            }

            // Check if package is still active based on dates
            $currentDate = now();
            if ($activePlan->endDate && $currentDate->gt($activePlan->endDate)) {
                // Package has expired, update status
                $activePlan->update(['status' => 0]);
                return response()->json([
                    'status' => false,
                    'message' => 'Your package has expired. Please renew your plan.'
                ], 400);
            }

            $allowedMsgCount = $activePlan->package->msgCount ?? 0;
            // Get monthly used message count (all users combined)
            $usedMsgCount = ActivePackage::where('status', 1)->sum('monthlyUsedMsgCount');
            $remainingMsg = $allowedMsgCount - $usedMsgCount;

            // Debug information
            Log::info("Message Limit Check (Update Campaign)", [
                'userId' => $userId,
                'userName' => $request->user()->name,
                'packageName' => $activePlan->package->packageName,
                'allowedMsgCount' => $allowedMsgCount,
                'usedMsgCount' => $usedMsgCount,
                'remainingMsg' => $remainingMsg,
                'requestedContacts' => $contacts->count()
            ]);

            if ($contacts->count() > $remainingMsg) {
                return response()->json([
                    'status' => false,
                    'message' => "Your plan allows only {$remainingMsg} more messages. You are trying to send {$contacts->count()} messages. Please upgrade your plan."
                ], 403);
            }

            // 4. Update campaign (same data structure as create)
            $campaign->update([
                'name' => $request->name,
                'templateId' => $request->templateId,
                'variables' => $request->variables ?? [],
                'headerVariables' => $request->headervariables ?? [],
                'contactIds' => $request->contacts,
                'scheduleAt' => $request->scheduleAt,
                'status' => $request->scheduleAt ? 'scheduled' : 'sending',
            ]);

            // 5. Delete old delivery status records and create new ones
            MessageDeliveryStatus::where('bulk_message_id', $campaign->id)->delete();

            foreach ($contacts as $contact) {
                MessageDeliveryStatus::create([
                    'bulk_message_id' => $campaign->id,
                    'contact_id' => $contact->id,
                    'status' => 'pending',
                ]);
            }

            // 6. If sending immediately, process messages
            if (!$request->scheduleAt) {
                $this->processBulkMessages($campaign, $contacts, $template, $activePlan);
            }

            return response()->json([
                'status' => true,
                'message' => 'Campaign updated successfully',
                'data' => $campaign->load(['template', 'contact'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific bulk message
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $bulkMessage = BulkMessage::where('userId', $userId)
                ->with(['template', 'contact'])
                ->find($id);

            if (!$bulkMessage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Bulk message not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $bulkMessage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a bulk message
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'status' => 'sometimes|string|in:scheduled,sending,processing,completed,completed_with_errors,failed',
            'scheduleAt' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $userId = $request->user()->id;
            $bulkMessage = BulkMessage::where('userId', $userId)->find($id);

            if (!$bulkMessage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Bulk message not found'
                ], 404);
            }

            $bulkMessage->update($request->only(['name', 'status', 'scheduleAt']));

            return response()->json([
                'status' => true,
                'message' => 'Bulk message updated successfully',
                'data' => $bulkMessage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a bulk message (only scheduled campaigns can be deleted)
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            // Build query - admin can delete any campaign, regular users can only delete their own
            $query = BulkMessage::where('id', $id);
            if (!$isAdmin) {
                $query->where('userId', $userId);
            }

            $bulkMessage = $query->first();

            if (!$bulkMessage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            // Check if campaign is scheduled - only scheduled campaigns can be deleted
            if ($bulkMessage->status !== 'scheduled') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only scheduled campaigns can be deleted'
                ], 403);
            }

            // Delete related delivery statuses first
            MessageDeliveryStatus::where('bulk_message_id', $bulkMessage->id)->delete();

            // Delete the campaign
            $bulkMessage->delete();

            return response()->json([
                'status' => true,
                'message' => 'Campaign deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
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
     * Process bulk messages (send WhatsApp messages) in batches
     */
    private function processBulkMessages($campaign, $contacts, $template, $activePlan)
    {
        if (!config('whatsapp.bulk_send_enabled')) {
            Log::info('Bulk send is disabled. Skipping sending for campaign.', ['campaign_id' => $campaign->id]);
            return;
        }

        // Get batch processing configuration from settings
        $settings = \App\Models\Settings::where('isActive', true)->first();
        $batchSize = $settings->batch_size ?? 50; // Default to 50 if not configured
        $batchDelay = $settings->batch_delay_seconds ?? 2; // Default to 2 seconds if not configured
        $enableBatchProcessing = $settings->enable_batch_processing ?? true; // Default to true
        $totalContacts = $contacts->count();
        $successCount = 0;
        $failCount = 0;
        $whatsappService = new WhatsAppService();

        // Update campaign status to processing
        $campaign->update(['status' => 'processing']);

        // If batch processing is disabled, process all at once
        if (!$enableBatchProcessing) {
            $batchSize = $totalContacts; // Process all at once
        }

        // Process contacts in batches
        $contactChunks = $contacts->chunk($batchSize);
        $totalBatches = $contactChunks->count();

        Log::info("Starting batch processing", [
            'campaign_id' => $campaign->id,
            'total_contacts' => $totalContacts,
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches
        ]);

        foreach ($contactChunks as $batchIndex => $batchContacts) {
            $batchNumber = $batchIndex + 1;
            Log::info("Processing batch {$batchNumber}/{$totalBatches}", [
                'batch_contacts' => $batchContacts->count(),
                'campaign_id' => $campaign->id
            ]);

            // Process each contact in the current batch
            foreach ($batchContacts as $contact) {
                try {
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
                            $template->language ?? 'en', // Use template's actual language
                            $template->components ?? [],
                            $campaign->variables,
                            $campaign->headerVariables,
                            $contact->toArray()
                        );
                    }

                    // Update delivery status based on result
                    $deliveryStatus = MessageDeliveryStatus::where('bulk_message_id', $campaign->id)
                        ->where('contact_id', $contact->id)
                        ->first();

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
                    $deliveryStatus->updateStatus('failed', [
                        'error' => $e->getMessage(),
                        'failed_at' => now()->toISOString()
                    ]);
                }
            }

            // Update campaign status based on current delivery statuses
            $this->updateCampaignStatus($campaign);

            // Add delay between batches (except for the last batch and if batch processing is enabled)
            if ($batchNumber < $totalBatches && $enableBatchProcessing) {
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
     * Update batch processing settings
     */
    public function updateBatchSettings(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'batch_size' => 'required|integer|min:1|max:100',
            'batch_delay_seconds' => 'required|integer|min:0|max:60',
            'enable_batch_processing' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $settings = \App\Models\Settings::where('isActive', true)->first();

            if (!$settings) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active settings found'
                ], 404);
            }

            $settings->update([
                'batch_size' => $request->batch_size,
                'batch_delay_seconds' => $request->batch_delay_seconds,
                'enable_batch_processing' => $request->enable_batch_processing,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Batch processing settings updated successfully',
                'data' => [
                    'batch_size' => $settings->batch_size,
                    'batch_delay_seconds' => $settings->batch_delay_seconds,
                    'enable_batch_processing' => $settings->enable_batch_processing,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get batch processing settings
     */
    public function getBatchSettings(Request $request)
    {
        try {
            $settings = \App\Models\Settings::where('isActive', true)->first();

            if (!$settings) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active settings found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'batch_size' => $settings->batch_size ?? 50,
                    'batch_delay_seconds' => $settings->batch_delay_seconds ?? 2,
                    'enable_batch_processing' => $settings->enable_batch_processing ?? true,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get campaign statistics
     */
    public function getStats(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $bulkMessage = BulkMessage::where('userId', $userId)->find($id);

            if (!$bulkMessage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Bulk message not found'
                ], 404);
            }

            $sentStatus = $bulkMessage->sentStatus ?? [];
            $sentCount = collect($sentStatus)->where('status', 'SENT')->count();
            $failedCount = collect($sentStatus)->where('status', 'FAILED')->count();
            $totalContacts = count($bulkMessage->contacts ?? []);

            $stats = [
                'total_contacts' => $totalContacts,
                'sent' => $sentCount,
                'failed' => $failedCount,
                'pending' => $totalContacts - $sentCount - $failedCount,
                'success_rate' => $totalContacts > 0 ? round(($sentCount / $totalContacts) * 100, 2) : 0
            ];

            return response()->json([
                'status' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get delivery status for a specific campaign
     */
    public function getDeliveryStatus($id)
    {
        try {
            $userId = request()->user()->id;

            $campaign = BulkMessage::where('id', $id)
                ->where('userId', $userId)
                ->with(['deliveryStatuses.contact'])
                ->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $deliveryStatuses = $campaign->deliveryStatuses()
                ->with('contact')
                ->get();

            $summary = $campaign->getDeliveryStatusSummary();

            return response()->json([
                'status' => true,
                'data' => [
                    'campaign' => $campaign,
                    'delivery_statuses' => $deliveryStatuses,
                    'summary' => $summary
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get delivery status for a specific contact in a campaign
     */
    public function getContactDeliveryStatus($campaignId, $contactId)
    {
        try {
            $userId = request()->user()->id;

            $campaign = BulkMessage::where('id', $campaignId)
                ->where('userId', $userId)
                ->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $deliveryStatus = MessageDeliveryStatus::where('bulk_message_id', $campaignId)
                ->where('contact_id', $contactId)
                ->with('contact')
                ->first();

            if (!$deliveryStatus) {
                return response()->json([
                    'status' => false,
                    'message' => 'Delivery status not found for this contact'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $deliveryStatus
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get delivery status summary for a campaign
     */
    public function getDeliveryStatusSummary($id)
    {
        try {
            $userId = request()->user()->id;

            $campaign = BulkMessage::where('id', $id)
                ->where('userId', $userId)
                ->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $summary = $campaign->getDeliveryStatusSummary();

            return response()->json([
                'status' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'summary' => $summary
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update campaign status manually
     */
    public function updateCampaignStatusManually($id)
    {
        try {
            $userId = request()->user()->id;

            $campaign = BulkMessage::where('id', $id)
                ->where('userId', $userId)
                ->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $this->updateCampaignStatus($campaign);
            $deliverySummary = $campaign->getDeliveryStatusSummary();

            return response()->json([
                'status' => true,
                'message' => 'Campaign status updated successfully',
                'data' => [
                    'campaign_id' => $campaign->id,
                    'status' => $campaign->status,
                    'delivery_summary' => $deliverySummary
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get campaign status with delivery summary
     */
    public function getCampaignStatus($id)
    {
        try {
            $userId = request()->user()->id;

            $campaign = BulkMessage::where('id', $id)
                ->where('userId', $userId)
                ->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $deliverySummary = $campaign->getDeliveryStatusSummary();

            return response()->json([
                'status' => true,
                'data' => [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'status' => $campaign->status,
                    'delivery_summary' => $deliverySummary,
                    'created_at' => $campaign->created_at,
                    'updated_at' => $campaign->updated_at
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Resend messages to failed and pending users for a specific campaign
     */
    public function resendFailedAndPending(Request $request, $id)
    {
        try {
            if (!config('whatsapp.bulk_send_enabled')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Bulk send is currently disabled. No messages will be sent.',
                ], 500);
            }

            $userId = $request->user()->id;

            // Find the campaign
            $campaign = BulkMessage::where('id', $id)
                ->where('userId', $userId)
                ->with(['template', 'deliveryStatuses.contact'])
                ->first();

            if (!$campaign) {
                return response()->json([
                    'status' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            // Get failed and pending delivery statuses
            $failedAndPendingStatuses = $campaign->deliveryStatuses()
                ->whereIn('status', ['failed', 'pending'])
                ->with('contact')
                ->get();

            if ($failedAndPendingStatuses->isEmpty()) {
                return response()->json([
                    'status' => false,
                    'message' => 'No failed or pending messages found for this campaign'
                ], 400);
            }

            // Check if template is still approved
            if ($campaign->template->status !== 'APPROVED') {
                return response()->json([
                    'status' => false,
                    'message' => 'Template is not approved. Cannot resend messages.'
                ], 400);
            }

            // Check global active package
            $activePlan = ActivePackage::where('status', 1)
                ->with('package')
                ->latest()
                ->first();

            if (!$activePlan) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active plan found. Please purchase a plan first.'
                ], 400);
            }

            // Check if package is still active
            $currentDate = now();
            if ($activePlan->endDate && $currentDate->gt($activePlan->endDate)) {
                $activePlan->update(['status' => 0]);
                return response()->json([
                    'status' => false,
                    'message' => 'Your package has expired. Please renew your plan.'
                ], 400);
            }

            $allowedMsgCount = $activePlan->package->msgCount ?? 0;
            $usedMsgCount = ActivePackage::where('status', 1)->sum('monthlyUsedMsgCount');
            $remainingMsg = $allowedMsgCount - $usedMsgCount;

            if ($failedAndPendingStatuses->count() > $remainingMsg) {
                return response()->json([
                    'status' => false,
                    'message' => "Your plan allows only {$remainingMsg} more messages. You are trying to resend {$failedAndPendingStatuses->count()} messages. Please upgrade your plan."
                ], 403);
            }

            // Reset failed and pending statuses back to pending for resend
            // The ProcessPendingBulkMessages command will process them in small batches to avoid timeouts
            foreach ($failedAndPendingStatuses as $deliveryStatus) {
                $deliveryStatus->updateStatus('pending', [
                    'resend' => true,
                    'resend_at' => now()->toISOString()
                ]);
            }

            // Update campaign status to pending and CLEAR scheduleAt so ProcessPendingBulkMessages picks it up
            // (ProcessPendingBulkMessages excludes campaigns with scheduleAt set)
            $campaign->update([
                'status' => 'pending',
                'scheduleAt' => null  // Clear schedule so it processes immediately via ProcessPendingBulkMessages
            ]);

            Log::info("Resend queued for processing", [
                'campaign_id' => $campaign->id,
                'total_failed_pending' => $failedAndPendingStatuses->count(),
                'message' => 'Messages will be processed by ProcessPendingBulkMessages command in small batches'
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Campaign messages queued for resend. They will be processed in small batches to avoid timeouts.',
                'data' => [
                    'campaign_id' => $campaign->id,
                    'campaign_name' => $campaign->name,
                    'total_queued' => $failedAndPendingStatuses->count(),
                    'status' => 'pending'
                ]
            ]);

        } catch (\Exception $e) {
            Log::error("Resend process failed", [
                'campaign_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Resend process failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * WhatsApp webhook verification
     */
    public function webhookVerification(Request $request)
    {
        $hubMode = $request->query('hub_mode');
        $hubChallenge = $request->query('hub_challenge');
        $hubVerifyToken = $request->query('hub_verify_token');

        // Debug: Log the verification attempt
        Log::info('Webhook verification attempt:', [
            'hub_mode' => $hubMode,
            'hub_challenge' => $hubChallenge,
            'hub_verify_token' => $hubVerifyToken,
            'all_params' => $request->all()
        ]);

        // Fixed token for verification
        $fixedToken = 'test';

        if ($hubMode === 'subscribe' && $hubVerifyToken === $fixedToken) {
            Log::info('Webhook verification successful, returning challenge: ' . $hubChallenge);
            return response($hubChallenge, 200);
        }

        Log::warning('Webhook verification failed:', [
            'expected_mode' => 'subscribe',
            'received_mode' => $hubMode,
            'expected_token' => $fixedToken,
            'received_token' => $hubVerifyToken
        ]);

        return response()->json([
            'status' => false,
            'message' => 'Webhook verification failed'
        ], 403);
    }

    /**
     * Handle incoming WhatsApp webhook events (both verification and messages)
     */
    public function handleWebhook(Request $request)
    {
        // Handle webhook verification (GET request)
        if ($request->isMethod('GET')) {
            $hubMode = $request->query('hub_mode');
            $hubChallenge = $request->query('hub_challenge');
            $hubVerifyToken = $request->query('hub_verify_token');

            // Debug: Log the verification attempt
            Log::info('Webhook verification attempt:', [
                'hub_mode' => $hubMode,
                'hub_challenge' => $hubChallenge,
                'hub_verify_token' => $hubVerifyToken,
                'all_params' => $request->all()
            ]);

            // Fixed token for verification
            $fixedToken = 'test';

            if ($hubMode === 'subscribe' && $hubVerifyToken === $fixedToken) {
                Log::info('Webhook verification successful, returning challenge: ' . $hubChallenge);
                return response($hubChallenge, 200);
            }

            Log::warning('Webhook verification failed:', [
                'expected_mode' => 'subscribe',
                'received_mode' => $hubMode,
                'expected_token' => $fixedToken,
                'received_token' => $hubVerifyToken
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Webhook verification failed'
            ], 403);
        }

        // Handle incoming messages (POST request)
        try {
            $body = $request->all();
            Log::info('WhatsApp Webhook received:', $body);

            // Process webhook data
            if (isset($body['entry'])) {
                foreach ($body['entry'] as $entry) {
                    if (!isset($entry['changes'])) {
                        continue;
                    }

                    foreach ($entry['changes'] as $change) {
                        // Only process message / status payloads
                        if (($change['field'] ?? null) !== 'messages' || !isset($change['value'])) {
                            continue;
                        }

                        $value = $change['value'];
                        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                        // Ignore Meta sample test payloads that use placeholder phone IDs
                        if ($phoneNumberId && $this->isMetaSamplePhoneNumberId($phoneNumberId)) {
                            Log::info('Ignored Meta sample webhook payload', [
                                'phone_number_id' => $phoneNumberId,
                            ]);
                            continue;
                        }

                        // Prefer matching configured business number when present
                        if ($phoneNumberId && !$this->isKnownPhoneNumberId($phoneNumberId)) {
                            Log::warning('Webhook phone_number_id does not match active settings', [
                                'phone_number_id' => $phoneNumberId,
                            ]);
                        }

                        if (!empty($value['messages'])) {
                            $this->processIncomingMessages($value['messages'], $value);
                        }

                        if (!empty($value['statuses'])) {
                            $this->processStatusUpdates($value['statuses']);
                        }
                    }
                }
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('WhatsApp webhook processing error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            // Always ACK to Meta so it does not disable the webhook
            return response()->json(['status' => 'success']);
        }
    }

    /**
     * Process incoming messages
     */
    private function processIncomingMessages($messages, $value)
    {
        $contactName = $value['contacts'][0]['profile']['name'] ?? null;

        foreach ($messages as $message) {
            try {
                $phoneNumber = $message['from'] ?? null;
                $messageId = $message['id'] ?? null;
                $timestamp = $message['timestamp'] ?? time();
                $type = $message['type'] ?? 'text';

                if (!$phoneNumber || !$messageId) {
                    Log::warning('Inbound message missing from/id', $message);
                    continue;
                }

                // Deduplicate Meta retries
                if (Message::where('whatsapp_message_id', $messageId)->exists()) {
                    Log::info("Skipping duplicate inbound message: {$messageId}");
                    continue;
                }

                $content = $this->extractMessageContent($message, $type);

                // Find or create conversation
                $conversation = $this->findOrCreateConversation($phoneNumber, $contactName);

                if (!$conversation) {
                    Log::warning("Could not create conversation for phone: {$phoneNumber}");
                    continue;
                }

                if ($contactName && empty($conversation->contact_name)) {
                    $conversation->contact_name = $contactName;
                }

                // Create message record
                Message::create([
                    'conversation_id' => $conversation->id,
                    'whatsapp_message_id' => $messageId,
                    'direction' => 'inbound',
                    'type' => in_array($type, ['text', 'image', 'video', 'audio', 'document', 'location', 'contact', 'sticker'], true)
                        ? $type
                        : 'text',
                    'content' => $content !== '' ? $content : ('[' . $type . ']'),
                    'media_url' => $this->extractMediaUrl($message, $type),
                    'media_type' => $this->extractMediaType($message, $type),
                    'media_filename' => $this->extractMediaFilename($message, $type),
                    'metadata' => $message,
                    'status' => 'delivered',
                    'whatsapp_timestamp' => date('Y-m-d H:i:s', (int) $timestamp),
                    'is_read' => false
                ]);

                // Update conversation
                $conversation->update([
                    'contact_name' => $conversation->contact_name,
                    'last_message_at' => now(),
                    'last_message_preview' => substr($content !== '' ? $content : ('[' . $type . ']'), 0, 100),
                    'is_unread' => true
                ]);

                Log::info("Processed inbound message from {$phoneNumber}");

            } catch (\Exception $e) {
                Log::error("Error processing message: " . $e->getMessage(), [
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }
    }

    /**
     * Process status updates from WhatsApp webhook
     * Handles: sent, delivered, read, failed statuses
     */
    private function processStatusUpdates($statuses)
    {
        foreach ($statuses as $status) {
            try {
                $messageId = $status['id'] ?? null;
                $statusType = $status['status'] ?? null; // sent, delivered, read, failed
                $recipientId = $status['recipient_id'] ?? null;
                $timestamp = $status['timestamp'] ?? null;
                $error = $status['errors'] ?? null;

                if (!$messageId || !$statusType) {
                    Log::warning('Status update missing required fields:', $status);
                    continue;
                }

                // Map WhatsApp status to our status
                $mappedStatus = $this->mapWhatsAppStatus($statusType);
                
                if (!$mappedStatus) {
                    Log::warning("Unknown status type received: {$statusType}", $status);
                    continue;
                }

                // Prepare metadata
                $metadata = [
                    'whatsapp_status' => $statusType,
                    'recipient_id' => $recipientId,
                    'timestamp' => $timestamp,
                    'updated_at' => now()->toISOString()
                ];

                if ($error) {
                    $metadata['error'] = $error;
                }

                // Update campaign delivery status if this was a bulk/campaign send
                $deliveryStatus = MessageDeliveryStatus::where('whatsapp_message_id', $messageId)->first();

                if ($deliveryStatus) {
                    if ($error) {
                        $deliveryStatus->error_message = is_array($error) ? json_encode($error) : $error;
                    }

                    if ($this->shouldAdvanceStatus($deliveryStatus->status, $mappedStatus)) {
                        $deliveryStatus->updateStatus($mappedStatus, $metadata);

                        Log::info("✅ Campaign status updated for message", [
                            'message_id' => $messageId,
                            'new_status' => $mappedStatus,
                            'whatsapp_status' => $statusType,
                            'campaign_id' => $deliveryStatus->bulk_message_id,
                            'contact_id' => $deliveryStatus->contact_id
                        ]);

                        if ($deliveryStatus->bulkMessage) {
                            $this->updateCampaignStatus($deliveryStatus->bulkMessage);
                        }
                    }
                } else {
                    Log::info("No campaign delivery row for message_id (may be chat-only): {$messageId}", [
                        'status' => $statusType,
                        'recipient_id' => $recipientId
                    ]);
                }

                // Also update chat Message rows (1:1 conversations)
                $chatMessage = Message::where('whatsapp_message_id', $messageId)->first();
                if ($chatMessage && $this->shouldAdvanceStatus($chatMessage->status, $mappedStatus)) {
                    $chatMessage->update([
                        'status' => $mappedStatus,
                        'metadata' => array_merge(
                            is_array($chatMessage->metadata) ? $chatMessage->metadata : [],
                            ['status_update' => $metadata]
                        ),
                    ]);

                    Log::info("✅ Chat message status updated", [
                        'message_id' => $messageId,
                        'new_status' => $mappedStatus,
                    ]);
                }

            } catch (\Exception $e) {
                Log::error("Error processing status update: " . $e->getMessage(), [
                    'status' => $status,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }

    /**
     * Only move status forward (pending/processing/sent -> delivered -> read), or to failed.
     */
    private function shouldAdvanceStatus(?string $current, string $incoming): bool
    {
        if ($incoming === 'failed') {
            return true;
        }

        $rank = [
            'pending' => 0,
            'processing' => 1,
            'sent' => 2,
            'delivered' => 3,
            'read' => 4,
            'failed' => 5,
        ];

        $currentRank = $rank[$current] ?? -1;
        $incomingRank = $rank[$incoming] ?? -1;

        return $incomingRank >= $currentRank;
    }

    private function isMetaSamplePhoneNumberId(string $phoneNumberId): bool
    {
        return in_array($phoneNumberId, ['123456123', '0'], true);
    }

    private function isKnownPhoneNumberId(string $phoneNumberId): bool
    {
        return Settings::where('phoneNumberId', $phoneNumberId)
            ->where('isActive', true)
            ->exists();
    }

    /**
     * Map WhatsApp status to our internal status
     */
    private function mapWhatsAppStatus($whatsappStatus)
    {
        $statusMap = [
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed'
        ];

        return $statusMap[strtolower($whatsappStatus)] ?? null;
    }

    /**
     * Find or create conversation
     */
    private function findOrCreateConversation($phoneNumber, $contactName = null)
    {
        // Prefer admin owner; fall back to first user (single-tenant WhatsApp setup)
        $user = User::where('role', 'admin')->first() ?: User::first();

        if (!$user) {
            return null;
        }

        $normalizedPhone = preg_replace('/\D+/', '', (string) $phoneNumber);

        $conversation = Conversation::where('user_id', $user->id)
            ->where(function ($q) use ($phoneNumber, $normalizedPhone) {
                $q->where('phone_number', $phoneNumber);
                if ($normalizedPhone) {
                    $q->orWhere('phone_number', $normalizedPhone)
                        ->orWhere('phone_number', '+' . $normalizedPhone);
                }
            })
            ->first();

        if (!$conversation) {
            // Best-effort contact name from CRM contacts
            if (!$contactName && $normalizedPhone) {
                $contact = Contact::where('userId', $user->id)
                    ->where(function ($q) use ($phoneNumber, $normalizedPhone) {
                        $q->where('phone', $phoneNumber)
                            ->orWhere('phone', $normalizedPhone)
                            ->orWhere('phone', '+' . $normalizedPhone)
                            ->orWhere('phone', 'like', '%' . $normalizedPhone);
                    })
                    ->first();
                $contactName = $contact->name ?? null;
            }

            $conversation = Conversation::create([
                'whatsapp_id' => 'conv_' . $normalizedPhone . '_' . time(),
                'phone_number' => $normalizedPhone ?: $phoneNumber,
                'contact_name' => $contactName,
                'user_id' => $user->id,
                'status' => 'active',
                'is_unread' => true
            ]);
        }

        return $conversation;
    }

    /**
     * Extract message content based on type
     */
    private function extractMessageContent($message, $type)
    {
        switch ($type) {
            case 'text':
                return $message['text']['body'] ?? '';

            case 'image':
            case 'video':
            case 'audio':
            case 'document':
                return $message[$type]['caption'] ?? '';

            case 'button':
            case 'interactive':
                return $message['button']['text']
                    ?? $message['interactive']['button_reply']['title']
                    ?? $message['interactive']['list_reply']['title']
                    ?? '[' . $type . ']';

            case 'location':
                $location = $message['location'] ?? [];
                return "Location: " . ($location['name'] ?? '') . ' - ' . ($location['address'] ?? '');

            case 'contacts':
            case 'contact':
                $contact = $message['contacts'][0] ?? $message['contact'] ?? [];
                $name = $contact['name']['formatted_name'] ?? 'Contact';
                $phone = $contact['phones'][0]['phone'] ?? '';
                return "Contact: {$name} - {$phone}";

            case 'sticker':
                return '[Sticker]';

            case 'reaction':
                return $message['reaction']['emoji'] ?? '[Reaction]';

            default:
                return '[Unsupported message type: ' . $type . ']';
        }
    }

    /**
     * Extract media URL
     */
    private function extractMediaUrl($message, $type)
    {
        if (in_array($type, ['image', 'video', 'audio', 'document'])) {
            return $message[$type]['id'] ?? null;
        }
        return null;
    }

    /**
     * Extract media type
     */
    private function extractMediaType($message, $type)
    {
        if (in_array($type, ['image', 'video', 'audio', 'document'])) {
            return $message[$type]['mime_type'] ?? null;
        }
        return null;
    }

    /**
     * Extract media filename
     */
    private function extractMediaFilename($message, $type)
    {
        if (in_array($type, ['image', 'video', 'audio', 'document'])) {
            return $message[$type]['filename'] ?? null;
        }
        return null;
    }
}
