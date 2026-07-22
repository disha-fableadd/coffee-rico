<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Media;
use App\Models\Message;
use App\Services\WhatsAppService;
use App\Support\UploadPath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MessageController extends Controller
{
    protected $whatsappService;

    public function __construct(WhatsAppService $whatsappService)
    {
        $this->whatsappService = $whatsappService;
    }

    /**
     * Map file extension to WhatsApp message type (image, video, document).
     */
    private function getMessageTypeFromExtension(string $extension): string
    {
        $ext = strtolower($extension);
        $imageExts = ['jpeg', 'jpg', 'png', 'gif', 'svg', 'webp', 'bmp', 'tiff'];
        $videoExts = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'];
        if (in_array($ext, $imageExts)) {
            return 'image';
        }
        if (in_array($ext, $videoExts)) {
            return 'video';
        }
        return 'document';
    }

    /**
     * Store uploaded file and return full URL, mime type, and filename.
     */
    private function storeMessageFile(\Illuminate\Http\UploadedFile $file): array
    {
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $filename = time() . '_' . Str::uuid() . '.' . $extension;

        $mediaPath = public_path('uploads/media');
        if (!file_exists($mediaPath)) {
            mkdir($mediaPath, 0755, true);
        }
        $file->move($mediaPath, $filename);

        $storedPath = UploadPath::store('uploads/media/' . $filename);

        Media::create([
            'name' => $originalName,
            'filename' => $filename,
            'path' => $storedPath,
            'url' => $storedPath,
            'mime_type' => $mimeType,
            'size' => $size,
            'extension' => $extension,
        ]);

        $media = Media::where('filename', $filename)->first();
        $fullUrl = $media ? $media->getFullUrlAttribute() : UploadPath::url($storedPath);

        return [
            'url' => $fullUrl,
            'mime_type' => $mimeType,
            'filename' => $originalName,
        ];
    }

    /**
     * Send a free-form message to a conversation
     * Supports optional "file" upload: store file, get URL, set type (image/video/document) by extension, then send.
     */
    public function sendMessage(Request $request)
    {
        try {
            $rules = [
                'conversation_id' => 'required|exists:conversations,id',
                'message' => 'required_without:file|nullable|string|max:4096',
                'file' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp,bmp,tiff,mp4,avi,mov,wmv,flv,webm,mkv,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv|max:51200',
                'type' => 'in:text,image,video,audio,document,location,contact,sticker',
                'media_url' => 'nullable|url',
                'media_type' => 'nullable|string',
                'media_filename' => 'nullable|string',
            ];

            $validator = Validator::make($request->all(), $rules);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $userId = $request->user()->id;
            $conversationId = $request->input('conversation_id');
            $message = (string) $request->input('message', '');
            $type = $request->input('type', 'text');
            $mediaUrl = $request->input('media_url');
            $mediaType = $request->input('media_type');
            $mediaFilename = $request->input('media_filename');

            // If "file" is uploaded: store it, get URL, set type from extension (image/video/document)
            if ($request->hasFile('file')) {
                $file = $request->file('file');
                $stored = $this->storeMessageFile($file);
                $mediaUrl = $stored['url'];
                $mediaType = $stored['mime_type'];
                $mediaFilename = $stored['filename'];
                $type = $this->getMessageTypeFromExtension($file->getClientOriginalExtension());
            }

            // Get conversation and verify ownership
            $conversation = Conversation::where('id', $conversationId)
                ->where('user_id', $userId)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            // Check if we can send free-form message (within 24 hours)
            $lastInboundMessage = Message::where('conversation_id', $conversationId)
                ->where('direction', 'inbound')
                ->latest('whatsapp_timestamp')
                ->first();

            if (!$lastInboundMessage || !$lastInboundMessage->isWithin24Hours()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot send free-form message. The 24-hour window has expired. Use a template message instead.',
                    'can_send_free_form' => false
                ], 400);
            }

            // Send message via WhatsApp API
            $result = $this->whatsappService->sendFreeFormMessage(
                $conversation->phone_number,
                $message,
                $type,
                $mediaUrl,
                $mediaType,
                $mediaFilename
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to send message: ' . $result['error']
                ], 500);
            }

            // Store the outbound message
            $outboundMessage = Message::create([
                'conversation_id' => $conversationId,
                'whatsapp_message_id' => $result['message_id'],
                'direction' => 'outbound',
                'type' => $type,
                'content' => $message,
                'media_url' => $mediaUrl,
                'media_type' => $mediaType,
                'media_filename' => $mediaFilename,
                'status' => 'sent',
                'whatsapp_timestamp' => now(),
                'is_read' => true
            ]);

            // Update conversation
            $preview = $message !== '' ? substr($message, 0, 100) : '[' . ucfirst($type) . ']';
            $conversation->update([
                'last_message_at' => now(),
                'last_message_preview' => $preview,
                'is_unread' => false
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Message sent successfully',
                'data' => $outboundMessage
            ]);

        } catch (\Exception $e) {
            Log::error('Message send error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send message: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Enrich messages with signed media URLs so links work when opened directly (no Bearer token).
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
     * Get messages for a conversation
     */
    public function getMessages(Request $request, $conversationId)
    {
        try {
            $userId = $request->user()->id;
            $limit = $request->query('limit', 50);
            $offset = $request->query('offset', 0);

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

            $messages = Message::where('conversation_id', $conversationId)
                ->orderBy('whatsapp_timestamp', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            $messages = $this->enrichMessagesWithMediaUrls($messages, (int) $conversationId, $userId);

            return response()->json([
                'success' => true,
                'data' => $messages
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(Request $request, $conversationId)
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

            // Mark all inbound messages as read
            Message::where('conversation_id', $conversationId)
                ->where('direction', 'inbound')
                ->update(['is_read' => true]);

            // Update conversation
            $conversation->update(['is_unread' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Messages marked as read'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get conversations that can receive free-form messages (within 24 hours)
     */
    public function getEligibleConversations(Request $request)
    {
        try {
            $userId = $request->user()->id;

            $conversations = Conversation::where('user_id', $userId)
                ->whereHas('messages', function($q) {
                    $q->where('direction', 'inbound')
                      ->where('whatsapp_timestamp', '>=', now()->subHours(24));
                })
                ->with(['latestMessage'])
                ->orderBy('last_message_at', 'desc')
                ->get();

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
     * Check if a conversation is eligible for free-form messaging
     */
    public function checkEligibility(Request $request, $conversationId)
    {
        try {
            $userId = $request->user()->id;

            $conversation = Conversation::where('id', $conversationId)
                ->where('user_id', $userId)
                ->first();

            if (!$conversation) {
                return response()->json([
                    'success' => false,
                    'message' => 'Conversation not found'
                ], 404);
            }

            $lastInboundMessage = Message::where('conversation_id', $conversationId)
                ->where('direction', 'inbound')
                ->latest('whatsapp_timestamp')
                ->first();

            $isEligible = $lastInboundMessage && $lastInboundMessage->isWithin24Hours();
            $hoursRemaining = $lastInboundMessage ?
                max(0, 24 - $lastInboundMessage->whatsapp_timestamp->diffInHours(now())) : 0;

            return response()->json([
                'success' => true,
                'data' => [
                    'conversation_id' => $conversationId,
                    'is_eligible' => $isEligible,
                    'hours_remaining' => $hoursRemaining,
                    'last_message_time' => $lastInboundMessage ? $lastInboundMessage->whatsapp_timestamp : null
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
