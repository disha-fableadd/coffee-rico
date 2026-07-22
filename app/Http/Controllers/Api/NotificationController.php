<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use App\Models\Template;
use App\Models\BulkMessage;
use App\Models\ActivePackage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user (advanced system)
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            // Template notifications (pending approval)
            $templateQuery = Template::query();
            if ($isAdmin) {
                $templateQuery->where('status', 'PENDING')
                             ->where('isRequest', true);
            } else {
                $templateQuery->where('userId', $userId)
                             ->where('status', 'PENDING');
            }
            $templates = $templateQuery->select('id', 'name', 'status', 'isRequest', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($template) use ($userId) {
                    return [
                        'id' => $template->id,
                        'name' => $template->name,
                        'status' => $template->status,
                        'isRequest' => $template->isRequest,
                        'createdAt' => $template->created_at,
                        'read' => false, // You can implement read tracking here
                        'type' => 'template'
                    ];
                });

            // Message notifications (last 24h)
            $yesterday = now()->subDay();
            $messageQuery = BulkMessage::query();
            if ($isAdmin) {
                $messageQuery->where('created_at', '>=', $yesterday);
            } else {
                $messageQuery->where('userId', $userId)
                            ->where('created_at', '>=', $yesterday);
            }
            $messages = $messageQuery->select('id', 'name', 'sentStatus', 'created_at')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($message) use ($userId) {
                    return [
                        'id' => $message->id,
                        'name' => $message->name,
                        'sentStatus' => $message->sentStatus,
                        'createdAt' => $message->created_at,
                        'read' => false, // You can implement read tracking here
                        'type' => 'message'
                    ];
                });

            // Package notifications (expiring in next 3 days)
            $today = now();
            $next3Days = now()->addDays(3);
            $packageQuery = ActivePackage::query();
            if ($isAdmin) {
                $packageQuery->whereBetween('endDate', [$today, $next3Days])
                             ->where('status', 1);
            } else {
                $packageQuery->where('userId', $userId)
                            ->whereBetween('endDate', [$today, $next3Days])
                            ->where('status', 1);
            }
            $packages = $packageQuery->with('package')
                ->orderBy('endDate', 'asc')
                ->limit(10)
                ->get()
                ->map(function ($package) use ($userId) {
                    return [
                        'id' => $package->id,
                        'packageName' => $package->package->name ?? 'Unknown',
                        'endDate' => $package->endDate,
                        'createdAt' => $package->created_at,
                        'read' => false, // You can implement read tracking here
                        'type' => 'package'
                    ];
                });

            return response()->json([
                'status' => true,
                'message' => 'Notifications fetched successfully',
                'data' => [
                    'templates' => $templates,
                    'messages' => $messages,
                    'packages' => $packages,
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
     * Create a new notification
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'sometimes|exists:users,id',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'type' => 'required|string|in:info,warning,success,error',
            'isRead' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $isAdmin = $request->user()->role === 'admin';
            $targetUserId = $isAdmin && $request->userId ? $request->userId : $request->user()->id;

            $notification = Notification::create([
                'userId' => $targetUserId,
                'title' => $request->title,
                'message' => $request->message,
                'type' => $request->type,
                'isRead' => $request->isRead ?? false,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Notification created successfully',
                'data' => $notification
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific notification
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $notification = Notification::where('userId', $userId)->find($id);

            if (!$notification) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a notification
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'isRead' => 'sometimes|boolean',
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
            $notification = Notification::where('userId', $userId)->find($id);

            if (!$notification) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found'
                ], 404);
            }

            $notification->update($request->only(['isRead']));

            return response()->json([
                'status' => true,
                'message' => 'Notification updated successfully',
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $notification = Notification::where('userId', $userId)->find($id);

            if (!$notification) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found'
                ], 404);
            }

            $notification->delete();

            return response()->json([
                'status' => true,
                'message' => 'Notification deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $notification = Notification::where('userId', $userId)->find($id);

            if (!$notification) {
                return response()->json([
                    'status' => false,
                    'message' => 'Notification not found'
                ], 404);
            }

            $notification->update(['isRead' => true]);

            return response()->json([
                'status' => true,
                'message' => 'Notification marked as read',
                'data' => $notification
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $userId = $request->user()->id;
            Notification::where('userId', $userId)->update(['isRead' => true]);

            return response()->json([
                'status' => true,
                'message' => 'All notifications marked as read'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
