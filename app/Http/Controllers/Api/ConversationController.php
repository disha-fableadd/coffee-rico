<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Contact;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class ConversationController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }
    /**
     * Normalize phone number for comparison
     */
    private function normalizePhoneNumber($phoneNumber)
    {
        // Remove all non-numeric characters
        $normalized = preg_replace('/[^0-9]/', '', $phoneNumber);

        // Remove leading zeros and country codes if present
        $normalized = ltrim($normalized, '0');

        // If it starts with country code, remove it (assuming +91 for India, +1 for US, etc.)
        if (strlen($normalized) > 10) {
            // Remove common country codes
            if (str_starts_with($normalized, '91') && strlen($normalized) == 12) {
                $normalized = substr($normalized, 2);
            } elseif (str_starts_with($normalized, '1') && strlen($normalized) == 11) {
                $normalized = substr($normalized, 1);
            }
        }

        return $normalized;
    }
    
    /**
     * Get a specific conversation with messages
     */
    public function show_old(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $limit = $request->query('limit', 50); // Default limit of 50 messages
            $offset = $request->query('offset', 0); // For pagination

            // Get total message count first
            $totalMessages = Message::where('conversation_id', $id)->count();

            // If no messages exist, return empty result
            if ($totalMessages == 0) {
                $conversation = Conversation::where('id', $id)
                    ->where('user_id', $userId)
                    ->first();

                if (!$conversation) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Conversation not found'
                    ], 404);
                }

                return response()->json([
                    'success' => true,
                    'data' => $conversation,
                    'pagination' => [
                        'total_messages' => 0,
                        'current_limit' => $limit,
                        'current_offset' => 0,
                        'has_more' => false
                    ]
                ]);
            }

            // Calculate smart offset - if requested offset is beyond total messages,
            // start from the latest messages
            $effectiveOffset = min($offset, max(0, $totalMessages - $limit));

            $conversation = Conversation::where('id', $id)
                ->where('user_id', $userId)
                ->with(['messages' => function($q) use ($limit, $effectiveOffset) {
                    $q->orderBy('whatsapp_timestamp', 'asc')
                      ->limit($limit)
                      ->offset($effectiveOffset);
                }])
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            // Mark conversation as read
            $conversation->markAsRead();

            // Add contact details
            $contact = $this->getContactByPhone($conversation->phone_number, $userId);
            $conversation->contact_details = $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'tags' => $contact->tags,
                'groups' => $contact->groups,
                'status' => $contact->status
            ] : null;

            // Process messages to include full media URLs and display information
            if ($conversation->messages) {
                $conversation->messages->transform(function ($message) use ($conversation) {
                    if ($message->type !== 'text' && $message->media_url) {
                        // Check if media_url is a WhatsApp media ID (numeric)
                        if (is_numeric($message->media_url)) {
                            // First try to download and get the filename
                            $downloadResult = $this->whatsappService->downloadWhatsAppMedia($message->media_url);

                            if ($downloadResult['success']) {
                                $message->media_download_url = url("/api/media/{$downloadResult['filename']}");
                                $message->media_display_url = url("/api/media/{$downloadResult['filename']}");
                                $message->media_filename = $downloadResult['filename'];
                                $message->media_type = $downloadResult['mime_type'];
                            } else {
                                // Fallback to download endpoint if download fails
                                $message->media_download_url = url("/api/conversations/{$conversation->id}/media/{$message->media_url}");
                                $message->media_display_url = url("/api/conversations/{$conversation->id}/media/{$message->media_url}");
                            }
                        } else {
                            // If it's already a URL, use it directly
                            $message->media_download_url = $message->media_url;
                            $message->media_display_url = $message->media_url;
                        }

                        // Add media display information
                        $message->media_display = [
                            'type' => $message->type,
                            'mime_type' => $message->media_type,
                            'filename' => $message->media_filename,
                            'url' => $message->media_display_url,
                            'download_url' => $message->media_download_url,
                            'is_image' => in_array($message->type, ['image']),
                            'is_video' => in_array($message->type, ['video']),
                            'is_audio' => in_array($message->type, ['audio']),
                            'is_document' => in_array($message->type, ['document']),
                            'caption' => $message->content,
                            'thumbnail_url' => $message->type === 'video' ? $message->media_display_url : null
                        ];
                    } else {
                        // For text messages, add basic display info
                        $message->media_display = [
                            'type' => 'text',
                            'content' => $message->content,
                            'is_text' => true
                        ];
                    }

                    return $message;
                });
            }

            return response()->json([
                'success' => true,
                'data' => $conversation,
                'pagination' => [
                    'total_messages' => $totalMessages,
                    'current_limit' => $limit,
                    'requested_offset' => $offset,
                    'effective_offset' => $effectiveOffset,
                    'has_more' => ($effectiveOffset + $limit) < $totalMessages,
                    'showing_latest' => $effectiveOffset != $offset,
                    'messages_returned' => $conversation->messages ? $conversation->messages->count() : 0
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
     * Get a conversation by ID with its messages
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $limit = (int) $request->query('limit', 50);
            $pageParam = $request->query('page');
            $offsetParam = $request->query('offset');

            // Determine page index (0-based). If 'page' provided (1-based), use it; otherwise use 'offset' as page index
            if (!is_null($pageParam)) {
                $page = max(1, (int) $pageParam);
                $pageIndex = $page - 1; // 0-based
            } else {
                $pageIndex = max(0, (int) ($offsetParam ?? 0)); // treat offset as page index (0,1,2,...)
                $page = $pageIndex + 1; // 1-based page for metadata
            }

            $recordOffset = $pageIndex * max(1, $limit);

            // Verify conversation ownership
            $conversation = Conversation::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            // Add contact details
            $contact = $this->getContactByPhone($conversation->phone_number, $userId);
            $conversation->contact_details = $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'tags' => $contact->tags,
                'groups' => $contact->groups,
                'status' => $contact->status
            ] : null;

            // 24-hour messaging window status (portal active = can send free-form messages)
            $lastInbound = Message::where('conversation_id', $id)
                ->where('direction', 'inbound')
                ->latest('whatsapp_timestamp')
                ->first();
            $conversation->messaging_window_status = ($lastInbound && $lastInbound->isWithin24Hours()) ? 'active' : 'inactive';
            $conversation->messaging_window_active = $lastInbound && $lastInbound->isWithin24Hours();
            $conversation->messaging_window_hours_remaining = $lastInbound && $lastInbound->isWithin24Hours()
                ? max(0, (int) (24 - $lastInbound->whatsapp_timestamp->diffInHours(now())))
                : 0;
            $conversation->last_inbound_at = $lastInbound ? $lastInbound->whatsapp_timestamp : null;

            // Fetch messages with pagination (newest first)
            $baseQuery = Message::where('conversation_id', $id);
            $total = (clone $baseQuery)->count();

            $messages = (clone $baseQuery)
                ->orderBy('whatsapp_timestamp', 'desc')
                ->offset($recordOffset)
                ->limit($limit)
                ->get();

            // Return the selected window in ascending order (oldest to newest within the page)
            $messages = $messages
                ->sortBy(function ($m) { return [$m->whatsapp_timestamp, $m->id]; })
                ->values();

            // Enrich messages with media display URLs for frontend (images, files, etc.)
            $messages = $this->enrichMessagesWithMediaUrls($messages, (int) $id, $request->user()->id);

            $totalPages = $limit > 0 ? (int) ceil($total / $limit) : 1;
            $hasMore = ($pageIndex + 1) < $totalPages;
            $nextOffset = $hasMore ? ($pageIndex + 1) : null; // next page index

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation' => $conversation,
                    'messages' => $messages,
                    'pagination' => [
                        'total' => $total,
                        'limit' => (int) $limit,
                        'offset' => (int) $pageIndex, // page index (0-based)
                        'next_offset' => $nextOffset, // next page index
                        'has_more' => $hasMore,
                        'page' => $page,
                        'total_pages' => $totalPages
                    ]
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
     * Enrich messages with media_display_url and media_display for frontend (images, files, etc.)
     * Uses signed URLs so links work when opened directly (new tab / <img>) without sending Bearer token.
     */
    private function enrichMessagesWithMediaUrls($messages, int $conversationId, int $userId)
    {
        return $messages->map(function ($message) use ($conversationId, $userId) {
            if ($message->type === 'text' || empty($message->media_url)) {
                $message->media_display = [
                    'type' => 'text',
                    'content' => $message->content,
                    'is_text' => true,
                ];
                return $message;
            }

            $displayUrl = null;
            $downloadUrl = null;

            if (is_numeric($message->media_url)) {
                // Signed URL: works when opened directly or in <img> without auth
                $displayUrl = URL::temporarySignedRoute('conversations.media.signed', now()->addHours(24), [
                    'conversationId' => $conversationId,
                    'mediaId' => $message->media_url,
                    'user_id' => $userId,
                ]);
                $downloadUrl = URL::temporarySignedRoute('conversations.media.signed', now()->addHours(24), [
                    'conversationId' => $conversationId,
                    'mediaId' => $message->media_url,
                    'user_id' => $userId,
                    'download' => 1,
                ]);
            } else {
                // Already a full URL (e.g. our stored file)
                $displayUrl = $message->media_url;
                $downloadUrl = $message->media_url;
            }

            $message->media_display_url = $displayUrl;
            $message->media_download_url = $downloadUrl;
            $message->media_display = [
                'type' => $message->type,
                'mime_type' => $message->media_type,
                'filename' => $message->media_filename,
                'url' => $displayUrl,
                'download_url' => $downloadUrl,
                'is_image' => in_array($message->type, ['image']),
                'is_video' => in_array($message->type, ['video']),
                'is_audio' => in_array($message->type, ['audio']),
                'is_document' => in_array($message->type, ['document']),
                'caption' => $message->content,
                'thumbnail_url' => $message->type === 'video' ? $displayUrl : null,
            ];

            return $message;
        });
    }

    /**
     * Serve conversation media via signed URL (no auth required).
     * Allows direct link / <img> access without Bearer token.
     */
    public function serveSignedMedia(Request $request, $conversationId, $mediaId)
    {
        if (!$request->hasValidSignature()) {
            return response()->json([
                'status' => false,
                'message' => 'Invalid or expired link',
                'error' => 'Authentication required'
            ], 401);
        }

        $userId = (int) $request->query('user_id');
        if (!$userId) {
            return response()->json(['status' => false, 'message' => 'Invalid link'], 400);
        }

        $conversation = Conversation::where('id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        if (!$conversation) {
            return response()->json(['status' => false, 'message' => 'Conversation not found'], 404);
        }

        $result = $this->whatsappService->downloadWhatsAppMedia($mediaId);
        if (!$result['success']) {
            return response()->json([
                'status' => false,
                'message' => $result['error'] ?? 'Failed to load media'
            ], 400);
        }

        $filePath = public_path('uploads/media/' . $result['filename']);
        if (!file_exists($filePath)) {
            return response()->json(['status' => false, 'message' => 'File not found'], 404);
        }

        $disposition = $request->query('download') === '1' ? 'attachment' : 'inline';
        return response()->file($filePath, [
            'Content-Type' => $result['mime_type'],
            'Content-Disposition' => $disposition . '; filename="' . ($result['filename'] ?? 'media') . '"',
            'Cache-Control' => 'public, max-age=31536000',
        ]);
    }

    /**
     * Get contact details by phone number
     */
    private function getContactByPhone($phoneNumber, $userId)
    {
        $normalizedPhone = $this->normalizePhoneNumber($phoneNumber);

        // Try exact match first
        $contact = Contact::where('userId', $userId)
            ->where('phone', $phoneNumber)
            ->first();

        if ($contact) {
            return $contact;
        }

        // Try normalized match
        $contacts = Contact::where('userId', $userId)->get();
        foreach ($contacts as $contact) {
            if ($this->normalizePhoneNumber($contact->phone) === $normalizedPhone) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Get all conversations for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $status = $request->query('status', 'active');
            $search = $request->query('search');
            $limit = $request->query('limit', 20);

            $query = Conversation::where('user_id', $userId)
                ->with(['latestMessage' => function($q) {
                    $q->latest('whatsapp_timestamp')->limit(1);
                }]);

            if ($status) {
                $query->where('status', $status);
            }

            if ($search) {
                $query->where(function($q) use ($search) {
                    $q->where('phone_number', 'like', "%{$search}%")
                      ->orWhere('contact_name', 'like', "%{$search}%");
                });
            }

            $conversations = $query->orderBy('last_message_at', 'desc')
                ->paginate($limit);

            // Add contact details to each conversation
            $conversations->getCollection()->transform(function ($conversation) use ($userId) {
                $contact = $this->getContactByPhone($conversation->phone_number, $userId);
                $conversation->contact_details = $contact ? [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'tags' => $contact->tags,
                    'groups' => $contact->groups,
                    'status' => $contact->status
                ] : null;
                return $conversation;
            });

            return response()->json([
                'success' => true,
                'data' => $conversations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Get latest contacts (conversations with recent activity)
     */
    public function getLatestContacts(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $limit = $request->query('limit', 10);
            $hours = $request->query('hours', 24); // Last 24 hours by default

            $conversations = Conversation::where('user_id', $userId)
                ->where('last_message_at', '>=', now()->subHours($hours))
                ->with(['latestMessage' => function($q) {
                    $q->latest('whatsapp_timestamp')->limit(1);
                }])
                ->orderBy('last_message_at', 'desc')
                ->limit($limit)
                ->get();

            // Add contact details to each conversation
            $conversations->transform(function ($conversation) use ($userId) {
                $contact = $this->getContactByPhone($conversation->phone_number, $userId);
                $conversation->contact_details = $contact ? [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'tags' => $contact->tags,
                    'groups' => $contact->groups,
                    'status' => $contact->status
                ] : null;
                return $conversation;
            });

            return response()->json([
                'success' => true,
                'data' => $conversations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get conversations with unread messages
     */
    public function getUnreadConversations(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $conversations = Conversation::where('user_id', $userId)
                ->where('is_unread', true)
                ->with(['latestMessage' => function($q) {
                    $q->latest('whatsapp_timestamp')->limit(1);
                }])
                ->orderBy('last_message_at', 'desc')
                ->get();

            // Add contact details to each conversation
            $conversations->transform(function ($conversation) use ($userId) {
                $contact = $this->getContactByPhone($conversation->phone_number, $userId);
                $conversation->contact_details = $contact ? [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phone' => $contact->phone,
                    'email' => $contact->email,
                    'tags' => $contact->tags,
                    'groups' => $contact->groups,
                    'status' => $contact->status
                ] : null;
                return $conversation;
            });

            return response()->json([
                'success' => true,
                'data' => $conversations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update conversation status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $status = $request->input('status');

            $conversation = Conversation::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            $conversation->update(['status' => $status]);

            // Add contact details
            $contact = $this->getContactByPhone($conversation->phone_number, $userId);
            $conversation->contact_details = $contact ? [
                'id' => $contact->id,
                'name' => $contact->name,
                'phone' => $contact->phone,
                'email' => $contact->email,
                'tags' => $contact->tags,
                'groups' => $contact->groups,
                'status' => $contact->status
            ] : null;

            return response()->json([
                'success' => true,
                'message' => 'Conversation status updated successfully',
                'data' => $conversation
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Download WhatsApp media
     */
    public function downloadMedia(Request $request, $conversationId, $mediaId)
    {
        try {
            $userId = $request->user()->id;

            // Verify conversation ownership
            $conversation = Conversation::where('id', $conversationId)
                ->where('user_id', $userId)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            // Download the media using WhatsApp service
            $result = $this->whatsappService->downloadWhatsAppMedia($mediaId);

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error']
                ], 400);
            }

            // Return file for direct display (e.g. <img src="..."> or link open in browser)
            $wantsFile = $request->query('stream') === '1' || $request->query('download') === '1' || !$request->expectsJson();
            if ($wantsFile) {
                $filePath = public_path('uploads/media/' . $result['filename']);
                if (file_exists($filePath)) {
                    $disposition = $request->query('download') === '1' ? 'attachment' : 'inline';
                    return response()->file($filePath, [
                        'Content-Type' => $result['mime_type'],
                        'Content-Disposition' => $disposition . '; filename="' . ($result['filename'] ?? 'media') . '"',
                        'Cache-Control' => 'public, max-age=31536000',
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'media_id' => $mediaId,
                    'filename' => $result['filename'],
                    'url' => $result['url'],
                    'full_url' => url('/api/conversations/' . $conversationId . '/media/' . $mediaId),
                    'mime_type' => $result['mime_type'],
                    'size' => $result['size']
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
     * Get conversation statistics
     */
    public function getStats(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $stats = [
                'total_conversations' => Conversation::where('user_id', $userId)->count(),
                'active_conversations' => Conversation::where('user_id', $userId)->where('status', 'active')->count(),
                'unread_conversations' => Conversation::where('user_id', $userId)->where('is_unread', true)->count(),
                'recent_conversations' => Conversation::where('user_id', $userId)
                    ->where('last_message_at', '>=', now()->subHours(24))
                    ->count(),
                'total_messages_today' => Message::whereHas('conversation', function($q) use ($userId) {
                    $q->where('user_id', $userId);
                })->whereDate('created_at', today())->count()
            ];

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
