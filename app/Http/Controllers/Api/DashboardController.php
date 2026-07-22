<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Template;
use App\Models\BulkMessage;
use App\Models\ActivePackage;
use App\Models\MessageDeliveryStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard data
     */
    public function getDashboard(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';
            $today = now();
            $lastWeek = now()->subDays(6);

            // Total Contacts Count
            $totalContacts = $isAdmin
                ? Contact::where('status', 'active')->count()
                : Contact::where('userId', $userId)
                    ->where('status', 'active')
                    ->count();

            // Active Templates Count (admin = all; user = user templates + system templates)
            $activeTemplates = $isAdmin
                ? Template::whereIn('status', ['active', 'APPROVED'])->count()
                : Template::where(function($query) use ($userId) {
                        $query->where('userId', $userId)
                              ->orWhere('userId', null); // Include system templates
                    })
                    ->whereIn('status', ['active', 'APPROVED'])
                    ->count();

            // Method 1: From BulkMessage.sentStatus arrays (legacy data)
            $messagesQuery = BulkMessage::query();
            if (!$isAdmin) {
                $messagesQuery->where('userId', $userId);
            }
            $messagesData = $messagesQuery->get();

            $legacyMessagesSent = 0;
            $legacyMessagesFailed = 0;
            $legacyMessagesPending = 0;
            $totalMessages = 0;

            foreach ($messagesData as $message) {
                $contactCount = count($message->contactIds ?? []);
                $totalMessages += $contactCount;

                if ($message->sentStatus && !empty($message->sentStatus)) {
                    $legacyMessagesSent += collect($message->sentStatus)->where('status', 'SENT')->count();
                    $legacyMessagesFailed += collect($message->sentStatus)->where('status', 'FAILED')->count();
                    $legacyMessagesPending += collect($message->sentStatus)->where('status', 'PENDING')->count();
                } else {
                    // If no sentStatus, all contacts are pending
                    $legacyMessagesPending += $contactCount;
                }
            }

            // Method 2: From MessageDeliveryStatus table (new accurate data)
            $deliveryStatusQuery = MessageDeliveryStatus::query();
            if (!$isAdmin) {
                $deliveryStatusQuery->whereHas('bulkMessage', function($query) use ($userId) {
                    $query->where('userId', $userId);
                });
            }

            $tableMessagesSent = $deliveryStatusQuery->clone()->where('status', 'sent')->count();
            $tableMessagesFailed = $deliveryStatusQuery->clone()->where('status', 'failed')->count();
            $tableMessagesPending = $deliveryStatusQuery->clone()->where('status', 'pending')->count();
            $tableMessagesDelivered = $deliveryStatusQuery->clone()->where('status', 'delivered')->count();
            $tableMessagesRead = $deliveryStatusQuery->clone()->where('status', 'read')->count();

            // Combine both methods (prioritize table data, fallback to legacy)
            $messagesSent = $tableMessagesSent > 0 ? $tableMessagesSent : $legacyMessagesSent;
            $messagesFailed = $tableMessagesFailed > 0 ? $tableMessagesFailed : $legacyMessagesFailed;
            $messagesPending = $tableMessagesPending > 0 ? $tableMessagesPending : $legacyMessagesPending;

            // Calculate delivery rate based on sent vs failed messages
            $totalAttempts = $messagesSent + $messagesFailed + $messagesPending;
            $deliveryRate = $totalAttempts > 0
                ? round(($messagesSent / $totalAttempts) * 100, 1)
                : 0;

            // Message Analytics - Last 7 Days (based on actual sent dates)
            $analyticsQuery = MessageDeliveryStatus::query()
                ->where('sent_at', '>=', $lastWeek)
                ->whereNotNull('sent_at');

            if (!$isAdmin) {
                $analyticsQuery->whereHas('bulkMessage', function($query) use ($userId) {
                    $query->where('userId', $userId);
                });
            }

            $messageAnalytics = $analyticsQuery->get()
                ->groupBy(function ($deliveryStatus) {
                    return $deliveryStatus->sent_at->format('Y-m-d');
                })
                ->map(function ($statuses, $date) {
                    $sentCount = $statuses->where('status', 'sent')->count();
                    $failedCount = $statuses->where('status', 'failed')->count();

                    return [
                        'date' => $date,
                        'sent' => $sentCount,
                        'failed' => $failedCount,
                    ];
                });

            // Format analytics data for chart (always 7 days)
            $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            $chartData = [];

            for ($i = 6; $i >= 0; $i--) {
                $date = $today->copy()->subDays($i);
                $dateStr = $date->format('Y-m-d');

                $analytics = $messageAnalytics->get($dateStr, ['sent' => 0, 'failed' => 0]);

                $chartData[] = [
                    'day' => $dayNames[$date->dayOfWeek],
                    'date' => $dateStr,
                    'sent' => $analytics['sent'],
                    'failed' => $analytics['failed'],
                    'total' => $analytics['sent'] + $analytics['failed']
                ];
            }

            // Active Campaigns
            $campaignsQuery = BulkMessage::query()
                ->with('template')
                ->orderBy('created_at', 'desc')
                ->limit(10);
            if (!$isAdmin) {
                $campaignsQuery->where('userId', $userId);
            }
            $activeCampaigns = $campaignsQuery->get()
                ->map(function ($campaign) {
                    $sentCount = collect($campaign->sentStatus)->where('status', 'SENT')->count();
                    $failedCount = collect($campaign->sentStatus)->where('status', 'FAILED')->count();
                    $totalContacts = count($campaign->contactIds ?? []);
                    $readCount = floor($sentCount * 0.4); // mock read count


                    return [
                        'id' => $campaign->id,
                        'name' => $campaign->name,
                        'templateName' => $campaign->template->name ?? 'Unknown',
                        'status' => $campaign->status,
                        'sent' => $sentCount,
                        'read' => $readCount,
                        'failed' => $failedCount,
                        'total' => $totalContacts,
                        'progress' => $totalContacts > 0 ? round(($sentCount / $totalContacts) * 100) : 0,
                        'createdAt' => $campaign->created_at->toISOString()
                    ];
                });

            // Latest Template
            $latestTemplate = null;
            if ($isAdmin) {
                $latestTemplate = Template::whereIn('status', ['active', 'APPROVED'])
                    ->orderBy('created_at', 'desc')
                    ->first();
            } else {
                $latestTemplate = Template::where(function($query) use ($userId) {
                        $query->where('userId', $userId)
                              ->orWhere('userId', null); // Include system templates
                    })
                    ->whereIn('status', ['active', 'APPROVED'])
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            $latestTemplateDetails = [];
            if ($latestTemplate) {
                $latestTemplateDetails = [[
                    'id' => $latestTemplate->id,
                    'name' => $latestTemplate->name,
                    'language' => $latestTemplate->language ?? 'en_US',
                    'category' => $latestTemplate->category ?? 'UTILITY',
                    'status' => strtoupper($latestTemplate->status),
                    'isCustom' => $latestTemplate->type === 'custom',
                    'components' => $latestTemplate->components ?? [
                        [
                            'type' => 'HEADER',
                            'format' => 'TEXT',
                            'text' => 'Sample Header',
                            'example' => [
                                'header_text' => ['Sample']
                            ]
                        ],
                        [
                            'type' => 'BODY',
                            'text' => 'Sample body text with {{1}} variable',
                            'example' => [
                                'body_text' => [
                                    ['Sample Variable']
                                ]
                            ]
                        ]
                    ],
                    'createdAt' => $latestTemplate->created_at->toISOString()
                ]];
            }

            return response()->json([
                'status' => true,
                'message' => 'Dashboard data fetched successfully',
                'data' => [
                    'stats' => [
                        'totalContacts' => $totalContacts,
                        'activeTemplates' => $activeTemplates,
                        'messagesSent' => $messagesSent, // Combined: table data prioritized over legacy
                        'deliveryRate' => $deliveryRate . '%'
                    ],
                    'debug' => [
                        'legacyData' => [
                            'messagesSent' => $legacyMessagesSent,
                            'messagesFailed' => $legacyMessagesFailed,
                            'messagesPending' => $legacyMessagesPending,
                            'totalMessages' => $totalMessages
                        ],
                        'tableData' => [
                            'messagesSent' => $tableMessagesSent,
                            'messagesFailed' => $tableMessagesFailed,
                            'messagesPending' => $tableMessagesPending,
                            'messagesDelivered' => $tableMessagesDelivered,
                            'messagesRead' => $tableMessagesRead
                        ],
                        'finalCounts' => [
                            'messagesSent' => $messagesSent,
                            'messagesFailed' => $messagesFailed,
                            'messagesPending' => $messagesPending
                        ]
                    ],
                    'latestTemplate' => $latestTemplateDetails,
                    'messageAnalytics' => [
                        'title' => 'Daily message performance over the past week',
                        'data' => $chartData
                    ],
                    'activeCampaigns' => [
                        'title' => 'Current campaign status overview',
                        'data' => $activeCampaigns
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
