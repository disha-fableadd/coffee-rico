<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BulkMessage;
use App\Models\Contact;
use App\Models\Template;
use App\Models\ActivePackage;
use App\Models\MessageDeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    /**
     * Get reports and analytics
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';
            $search = $request->query('search', '');
            $status = $request->query('status', 'all');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');
            $bulkId = $request->query('bulk_id');

            // Set default date range if not provided
            if (!$dateFrom) {
                $dateFrom = now()->subDays(30)->format('Y-m-d');
            }
            if (!$dateTo) {
                $dateTo = now()->format('Y-m-d');
            }

            // Base query for user-specific or admin data
            $userQuery = $isAdmin ? null : $userId;

            // Get message details with template and contact information
            $messages = $this->getMessageDetails($userQuery, $search, $status, $dateFrom, $dateTo, $bulkId);

            // Get summary statistics
            $summary = $this->getSummaryStatistics($userQuery, $dateFrom, $dateTo, $bulkId);

            return response()->json([
                'success' => true,
                'filters' => [
                    'applied' => [
                        'search' => $search,
                        'status' => $status,
                        'bulk_id' => $bulkId
                    ]
                ],
                'summary' => $summary,
                'messages' => $messages
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    public function index1(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';
            $search = $request->query('search', '');
            $status = $request->query('status', 'all');
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');
            $bulkId = $request->query('bulk_id');
            $page = $request->query('page', 1);
            $perPage = $request->query('per_page', 15);

            // Set default date range if not provided
            if (!$dateFrom) {
                $dateFrom = now()->subDays(30)->format('Y-m-d');
            }
            if (!$dateTo) {
                $dateTo = now()->format('Y-m-d');
            }

            // Add time component to include the full end date
            $dateFromWithTime = $dateFrom . ' 00:00:00';
            $dateToWithTime = $dateTo . ' 23:59:59';

            // Base query for campaigns
            $query = BulkMessage::whereBetween('created_at', [$dateFromWithTime, $dateToWithTime])
                ->with(['template:id,name']); // Only load necessary template fields

            // Apply user filter
            if (!$isAdmin) {
                $query->where('userId', $userId);
            }

            // Apply bulk ID filter
            if (!empty($bulkId)) {
                $query->where('id', $bulkId);
            }

            // Apply search filter
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', '%' . $search . '%')
                      ->orWhereHas('template', function($templateQuery) use ($search) {
                          $templateQuery->where('name', 'like', '%' . $search . '%');
                      });
                });
            }

            // Apply status filter
            if ($status === 'all') {
                // When status is 'all', exclude scheduled campaigns explicitly
                $query->whereNotIn('status', ['scheduled'])
                      ->whereNotNull('status'); // Ensure status is not null
            } else {
                // When specific status is selected, filter by that status
                $query->where('status', $status);
            }

            // Get paginated campaigns
            $campaigns = $query->orderBy('created_at', 'desc')
                ->paginate($perPage, ['*'], 'page', $page);

            // Get campaign IDs for the current page only
            $campaignIds = collect($campaigns->items())->pluck('id')->toArray();

            // Get delivery status summaries in a single query for current page campaigns only
            $deliverySummaries = [];
            if (!empty($campaignIds)) {
                $summaries = MessageDeliveryStatus::whereIn('bulk_message_id', $campaignIds)
                    ->selectRaw('bulk_message_id, status, COUNT(*) as count')
                    ->groupBy('bulk_message_id', 'status')
                    ->get()
                    ->groupBy('bulk_message_id');

                foreach ($summaries as $campaignId => $statusCounts) {
                    $pendingItem = $statusCounts->firstWhere('status', 'pending');
                    $processingItem = $statusCounts->firstWhere('status', 'processing');
                    $sentItem = $statusCounts->firstWhere('status', 'sent');
                    $deliveredItem = $statusCounts->firstWhere('status', 'delivered');
                    $readItem = $statusCounts->firstWhere('status', 'read');
                    $failedItem = $statusCounts->firstWhere('status', 'failed');

                    $deliverySummaries[$campaignId] = [
                        'pending' => $pendingItem ? $pendingItem->count : 0,
                        'processing' => $processingItem ? $processingItem->count : 0,
                        'sent' => $sentItem ? $sentItem->count : 0,
                        'delivered' => $deliveredItem ? $deliveredItem->count : 0,
                        'read' => $readItem ? $readItem->count : 0,
                        'failed' => $failedItem ? $failedItem->count : 0,
                    ];
                }
            }

            // Build campaign list with optimized data
            $messages = [];
            $sr = ($page - 1) * $perPage + 1;

            foreach ($campaigns->items() as $campaign) {
                // Skip scheduled campaigns when status is 'all' (double check to ensure they don't appear)
                if ($status === 'all' && $campaign->status === 'scheduled') {
                    continue;
                }

                $contactIds = $campaign->contactIds ?? [];
                $audienceSize = count($contactIds);

                // Get delivery summary for this campaign
                $deliverySummary = $deliverySummaries[$campaign->id] ?? [
                    'pending' => 0,
                    'processing' => 0,
                    'sent' => 0,
                    'delivered' => 0,
                    'read' => 0,
                    'failed' => 0,
                ];

                // Determine campaign status
                if ($campaign->status === 'scheduled') {
                    $messageStatus = 'scheduled';
                } else {
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
                                    $messageStatus = 'failed';
                                } else {
                                    // High success rate, mark as completed
                                    $messageStatus = 'completed';
                                }
                            } else {
                                // No processed messages, mark as failed
                                $messageStatus = 'failed';
                            }
                        } else {
                            // No failed messages, mark as completed
                            $messageStatus = 'completed';
                        }
                    } else {
                        // Still has pending or processing messages
                        $messageStatus = 'processing';
                    }
                }

                $messages[] = [
                    'sr' => $sr++,
                    'bulk_id' => $campaign->id,
                    'sendingDate' => $campaign->created_at->toISOString(),
                    'scheduleAt' => $campaign->scheduleAt ? $campaign->scheduleAt->toISOString() : null,
                    'status' => $messageStatus,
                    'userId' => $campaign->userId,
                    'campaignName' => $campaign->name,
                    'templateDetails' => $campaign->template ? [
                        'id' => $campaign->template->id,
                        'name' => $campaign->template->name,
                    ] : null,
                    'audienceSize' => $audienceSize,
                    'delivery_summary' => $deliverySummary
                ];
            }

            // Get summary statistics efficiently
            $summary = $this->getOptimizedSummaryStatistics($isAdmin ? null : $userId, $dateFromWithTime, $dateToWithTime, $bulkId, $status);

            return response()->json([
                'success' => true,
                'filters' => [
                    'applied' => [
                        'search' => $search,
                        'status' => $status,
                        'bulk_id' => $bulkId,
                        'date_from' => $dateFrom,
                        'date_to' => $dateTo
                    ]
                ],
                'summary' => $summary,
                'messages' => $messages,
                'pagination' => [
                    'current_page' => $campaigns->currentPage(),
                    'last_page' => $campaigns->lastPage(),
                    'per_page' => $campaigns->perPage(),
                    'total' => $campaigns->total(),
                    'from' => $campaigns->firstItem(),
                    'to' => $campaigns->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get message details with template and contact information
     * Optimized to reduce N+1 queries and improve performance
     */
    private function getMessageDetails($userId, $search, $status, $dateFrom, $dateTo, $bulkId = null)
    {
        // Add time component to include the full end date
        $dateFromWithTime = $dateFrom . ' 00:00:00';
        $dateToWithTime = $dateTo . ' 23:59:59';

        // Build base query with only necessary fields
        // Include campaign details (variables, headerVariables, etc.) when bulk_id is provided
        $selectFields = ['id', 'userId', 'name', 'templateId', 'contactIds', 'status', 'created_at', 'scheduleAt'];
        if (!empty($bulkId)) {
            $selectFields = array_merge($selectFields, ['variables', 'headerVariables', 'contactId']);
        }

        // Build base query
        $query = BulkMessage::select($selectFields)
            ->with(['template:id,name,language,category,status,userId,components']);

        // If bulk_id is provided, skip date filter (user wants specific campaign regardless of date)
        if (empty($bulkId)) {
            $query->whereBetween('created_at', [$dateFromWithTime, $dateToWithTime]);
        }

        if ($userId) {
            $query->where('userId', $userId);
        }

        // Apply bulk ID filter - if provided, only get that specific bulk message
        if (!empty($bulkId)) {
            $query->where('id', $bulkId);
        }

        // Apply search filter
        if (!empty($search)) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhereHas('template', function($templateQuery) use ($search) {
                      $templateQuery->where('name', 'like', '%' . $search . '%');
                  });
            });
        }

        // Apply status filter
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $campaigns = $query->orderBy('created_at', 'desc')->get();

        if ($campaigns->isEmpty()) {
            return [];
        }

        $campaignIds = $campaigns->pluck('id')->toArray();

        // Get all delivery status summaries in a single query
        $deliverySummaries = MessageDeliveryStatus::whereIn('bulk_message_id', $campaignIds)
            ->selectRaw('bulk_message_id, status, COUNT(*) as count')
            ->groupBy('bulk_message_id', 'status')
            ->get()
            ->groupBy('bulk_message_id');

        // Collect all contact IDs from all campaigns
        $allContactIds = [];
        foreach ($campaigns as $campaign) {
            $contactIds = $campaign->contactIds ?? [];
            $allContactIds = array_merge($allContactIds, $contactIds);
        }
        $allContactIds = array_unique($allContactIds);

        // Load all contacts in a single query
        $contactsMap = [];
        if (!empty($allContactIds)) {
            $contacts = Contact::whereIn('id', $allContactIds)
                ->select('id', 'name', 'phone', 'email', 'tags', 'status')
                ->get();
            foreach ($contacts as $contact) {
                $contactsMap[$contact->id] = $contact;
            }
        }

        // Get all delivery statuses in a single query, grouped by bulk_message_id and contact_id
        $deliveryStatusesMap = [];
        if (!empty($allContactIds)) {
            $deliveryStatuses = MessageDeliveryStatus::whereIn('bulk_message_id', $campaignIds)
                ->whereIn('contact_id', $allContactIds)
                ->select('id', 'bulk_message_id', 'contact_id', 'status', 'whatsapp_message_id',
                         'error_message', 'sent_at', 'delivered_at', 'read_at', 'failed_at')
                ->get();

            foreach ($deliveryStatuses as $deliveryStatus) {
                $key = $deliveryStatus->bulk_message_id . '_' . $deliveryStatus->contact_id;
                $deliveryStatusesMap[$key] = $deliveryStatus;
            }
        }

        $messages = [];
        $sr = 1;

        foreach ($campaigns as $campaign) {
            $contactIds = $campaign->contactIds ?? [];

            // Get delivery summary for this campaign from pre-loaded data
            $campaignSummaries = $deliverySummaries->get($campaign->id, collect());
            $deliverySummary = [
                'pending' => 0,
                'processing' => 0,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'failed' => 0,
                'total' => 0
            ];

            foreach ($campaignSummaries as $summary) {
                $statusKey = $summary->status;
                if (isset($deliverySummary[$statusKey])) {
                    $deliverySummary[$statusKey] = $summary->count;
                }
            }
            $deliverySummary['total'] = array_sum($deliverySummary);

            // Get contact details with delivery status from pre-loaded data
            $contactDetails = [];
            if (!empty($contactIds)) {
                foreach ($contactIds as $contactId) {
                    $contact = $contactsMap[$contactId] ?? null;
                    if (!$contact) {
                        continue;
                    }

                    // Get delivery status from pre-loaded map
                    $key = $campaign->id . '_' . $contactId;
                    $deliveryStatus = $deliveryStatusesMap[$key] ?? null;

                    $contactDetails[] = [
                        'id' => $contact->id,
                        'name' => $contact->name,
                        'phone' => $contact->phone,
                        'email' => $contact->email,
                        'tags' => $contact->tags ?? [],
                        'status' => $contact->status,
                        'delivery_status' => $deliveryStatus ? [
                            'status' => $deliveryStatus->status,
                            'whatsapp_message_id' => $deliveryStatus->whatsapp_message_id,
                            'error_message' => $deliveryStatus->error_message,
                            'sent_at' => $deliveryStatus->sent_at,
                            'delivered_at' => $deliveryStatus->delivered_at,
                            'read_at' => $deliveryStatus->read_at,
                            'failed_at' => $deliveryStatus->failed_at
                        ] : null
                    ];
                }
            }

            // Get template details
            $templateDetails = null;
            if ($campaign->template) {
                $template = $campaign->template;
                $templateDetails = [
                    'id' => $template->id,
                    'name' => $template->name,
                    'language' => $template->language,
                    'category' => $template->category,
                    'status' => $template->status,
                    'isCustom' => $template->userId !== null,
                    'components' => $template->components ?? []
                ];
            }

            // Determine message status based on delivery statuses
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
                            $messageStatus = 'failed';
                        } else {
                            // High success rate, mark as completed
                            $messageStatus = 'completed';
                        }
                    } else {
                        // No processed messages, mark as failed
                        $messageStatus = 'failed';
                    }
                } else {
                    // No failed messages, mark as completed
                    $messageStatus = 'completed';
                }
            } else {
                // Still has pending or processing messages
                $messageStatus = 'processing';
            }

            $messageData = [
                'sr' => $sr++,
                'bulk_id' => $campaign->id,
                'sendingDate' => $campaign->created_at->toISOString(),
                'scheduleAt' => $campaign->scheduleAt ? $campaign->scheduleAt->toISOString() : null,
                'status' => $messageStatus,
                'userId' => $campaign->userId,
                'campaignName' => $campaign->name,
                'templateDetails' => $templateDetails,
                'contactDetails' => $contactDetails,
                'delivery_summary' => $deliverySummary
            ];

            // Include campaign details when bulk_id is provided
            if (!empty($bulkId)) {
                $messageData['campaignDetails'] = [
                    'variables' => $campaign->variables ?? [],
                    'headerVariables' => $campaign->headerVariables ?? [],
                    'scheduleAt' => $campaign->scheduleAt ? $campaign->scheduleAt->toISOString() : null,
                    'contactId' => $campaign->contactId
                ];
            }

            $messages[] = $messageData;
        }

        return $messages;
    }

    /**
     * Get summary statistics
     * Optimized to use database aggregations instead of N+1 queries
     */
    private function getSummaryStatistics($userId, $dateFrom, $dateTo, $bulkId = null)
    {
        // Add time component to include the full end date
        $dateFromWithTime = $dateFrom . ' 00:00:00';
        $dateToWithTime = $dateTo . ' 23:59:59';

        // Get campaigns with only necessary fields
        $query = BulkMessage::select('id', 'userId', 'status', 'contactIds');

        // If bulk_id is provided, skip date filter (user wants specific campaign regardless of date)
        if (empty($bulkId)) {
            $query->whereBetween('created_at', [$dateFromWithTime, $dateToWithTime]);
        }

        if ($userId) {
            $query->where('userId', $userId);
        }

        // Apply bulk ID filter - if provided, only get that specific bulk message
        if (!empty($bulkId)) {
            $query->where('id', $bulkId);
        }

        $campaigns = $query->get();

        if ($campaigns->isEmpty()) {
            return [
                'totalMessages' => 0,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'failed' => 0,
                'pending' => 0,
                'processing' => 0,
                'scheduled' => 0,
                'completed' => 0
            ];
        }

        $campaignIds = $campaigns->pluck('id')->toArray();

        // Calculate total messages and scheduled messages from campaigns
        $totalMessages = 0;
        $scheduled = 0;
        foreach ($campaigns as $campaign) {
            $contactIds = $campaign->contactIds ?? [];
            $count = count($contactIds);
            $totalMessages += $count;
            if ($campaign->status === 'scheduled') {
                $scheduled += $count;
            }
        }

        // Get all delivery status summaries in a single aggregated query
        $deliveryStats = MessageDeliveryStatus::whereIn('bulk_message_id', $campaignIds)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $pending = $deliveryStats['pending'] ?? 0;
        $processing = $deliveryStats['processing'] ?? 0;
        $sent = $deliveryStats['sent'] ?? 0;
        $delivered = $deliveryStats['delivered'] ?? 0;
        $read = $deliveryStats['read'] ?? 0;
        $failed = $deliveryStats['failed'] ?? 0;
        $completed = $sent + $delivered + $read;

        return [
            'totalMessages' => $totalMessages,
            'sent' => $sent,
            'delivered' => $delivered,
            'read' => $read,
            'failed' => $failed,
            'pending' => $pending,
            'processing' => $processing,
            'scheduled' => $scheduled,
            'completed' => $completed
        ];
    }

    /**
     * Get optimized summary statistics using database aggregations
     * Summary is always calculated from ALL campaigns, regardless of status filter
     */
    private function getOptimizedSummaryStatistics($userId, $dateFromWithTime, $dateToWithTime, $bulkId = null, $status = 'all')
    {
        // Get ALL campaigns with only necessary fields (ignore status filter for summary)
        $query = BulkMessage::select('id', 'userId', 'status', 'contactIds');

        // If bulk_id is provided, skip date filter (user wants specific campaign regardless of date)
        if (empty($bulkId)) {
            $query->whereBetween('created_at', [$dateFromWithTime, $dateToWithTime]);
        }

        if ($userId) {
            $query->where('userId', $userId);
        }

        if (!empty($bulkId)) {
            $query->where('id', $bulkId);
        }

        // Don't apply status filter - summary should always show ALL campaigns
        $campaigns = $query->get();
        $campaignIds = $campaigns->pluck('id')->toArray();

        // Calculate campaign-level statistics
        $totalCampaigns = $campaigns->count();

        // Get delivery status summaries for all campaigns to determine campaign status
        $campaignDeliverySummaries = [];
        if (!empty($campaignIds)) {
            $summaries = MessageDeliveryStatus::whereIn('bulk_message_id', $campaignIds)
                ->selectRaw('bulk_message_id, status, COUNT(*) as count')
                ->groupBy('bulk_message_id', 'status')
                ->get()
                ->groupBy('bulk_message_id');

            foreach ($summaries as $campaignId => $statusCounts) {
                $pendingItem = $statusCounts->firstWhere('status', 'pending');
                $processingItem = $statusCounts->firstWhere('status', 'processing');
                $sentItem = $statusCounts->firstWhere('status', 'sent');
                $deliveredItem = $statusCounts->firstWhere('status', 'delivered');
                $readItem = $statusCounts->firstWhere('status', 'read');
                $failedItem = $statusCounts->firstWhere('status', 'failed');

                $campaignDeliverySummaries[$campaignId] = [
                    'pending' => $pendingItem ? $pendingItem->count : 0,
                    'processing' => $processingItem ? $processingItem->count : 0,
                    'sent' => $sentItem ? $sentItem->count : 0,
                    'delivered' => $deliveredItem ? $deliveredItem->count : 0,
                    'read' => $readItem ? $readItem->count : 0,
                    'failed' => $failedItem ? $failedItem->count : 0,
                ];
            }
        }

        // Count failed and completed campaigns (excluding scheduled)
        $failedCampaigns = 0;
        $completedCampaigns = 0;

        foreach ($campaigns as $campaign) {
            // Skip scheduled campaigns from failed/completed counts
            if ($campaign->status === 'scheduled') {
                continue;
            }

            $deliverySummary = $campaignDeliverySummaries[$campaign->id] ?? [
                'pending' => 0,
                'processing' => 0,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'failed' => 0,
            ];

            // Determine campaign status (same logic as in index1)
            // Check if processing is complete (no pending or processing messages)
            $isProcessingComplete = $deliverySummary['pending'] === 0 && $deliverySummary['processing'] === 0;

            if ($isProcessingComplete) {
                // Processing is complete - check if there are failed messages
                if ($deliverySummary['failed'] > 0) {
                    // If there are failed messages, check success rate
                    $totalProcessed = $deliverySummary['sent'] + $deliverySummary['failed'];
                    if ($totalProcessed > 0) {
                        $successRate = ($deliverySummary['sent'] / $totalProcessed) * 100;
                        // If success rate is less than 80%, mark as failed
                        if ($successRate < 80) {
                            $failedCampaigns++;
                        } else {
                            // High success rate, mark as completed
                            $completedCampaigns++;
                        }
                    } else {
                        // No processed messages, mark as failed
                        $failedCampaigns++;
                    }
                } else {
                    // No failed messages, mark as completed
                    $completedCampaigns++;
                }
            } else {
                // Pending or processing campaigns are not completed or failed
            }
        }

        // Calculate total messages from contactIds (all campaigns)
        $totalMessages = $campaigns->sum(function($campaign) {
            return count($campaign->contactIds ?? []);
        });

        // Calculate scheduled messages from all campaigns
        $scheduled = $campaigns->where('status', 'scheduled')->sum(function($campaign) {
            return count($campaign->contactIds ?? []);
        });

        // Calculate message-level statistics from campaign delivery summaries
        $deliveryStats = [
            'pending' => 0,
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
        ];

        // Aggregate delivery stats from all campaign summaries
        foreach ($campaignDeliverySummaries as $summary) {
            $deliveryStats['pending'] += $summary['pending'];
            $deliveryStats['sent'] += $summary['sent'];
            $deliveryStats['delivered'] += $summary['delivered'];
            $deliveryStats['read'] += $summary['read'];
            $deliveryStats['failed'] += $summary['failed'];
        }

        return [
            // Campaign-level statistics
            'totalCampaigns' => $totalCampaigns,
            'failedCampaigns' => $failedCampaigns,
            'completedCampaigns' => $completedCampaigns,
            // Message-level statistics
            'totalMessages' => $totalMessages,
            'sent' => $deliveryStats['sent'],
            'delivered' => $deliveryStats['delivered'],
            'read' => $deliveryStats['read'],
            'failed' => $deliveryStats['failed'],
            'pending' => $deliveryStats['pending'],
            'processing' => 0, // Processing is not tracked in delivery stats
            'scheduled' => $scheduled,
            'completed' => $deliveryStats['sent'] + $deliveryStats['delivered'] + $deliveryStats['read']
        ];
    }

    /**
     * Get detailed delivery status for a specific campaign
     */
    public function getCampaignDeliveryStatus($id, Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = BulkMessage::where('id', $id)
                ->with(['deliveryStatuses.contact', 'template']);

            if (!$isAdmin) {
                $query->where('userId', $userId);
            }

            $campaign = $query->first();

            if (!$campaign) {
                return response()->json([
                    'success' => false,
                    'message' => 'Campaign not found'
                ], 404);
            }

            $deliveryStatuses = $campaign->deliveryStatuses()
                ->with('contact')
                ->orderBy('created_at', 'desc')
                ->get();

            $deliverySummary = $campaign->getDeliveryStatusSummary();

            return response()->json([
                'success' => true,
                'data' => [
                    'campaign' => [
                        'id' => $campaign->id,
                        'name' => $campaign->name,
                        'status' => $campaign->status,
                        'created_at' => $campaign->created_at,
                        'template' => $campaign->template
                    ],
                    'delivery_statuses' => $deliveryStatuses,
                    'summary' => $deliverySummary
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

}
