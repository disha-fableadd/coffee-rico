<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Get all users (admin only)
     */
    public function index(Request $request)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $users = User::with(['contacts', 'groups', 'bulkMessages', 'activePackage'])
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'number' => $user->number,
                        'role' => $user->role,
                        'status' => $user->status,
                        'bio' => $user->bio,
                        'companyName' => $user->companyName,
                        'companyAddress' => $user->companyAddress,
                        'companyEmail' => $user->companyEmail,
                        'companyMobile' => $user->companyMobile,
                        'profileImage' => $user->profile_image_url,
                        'companyLogo' => $user->company_logo_url,
                        'created_at' => $user->created_at,
                        'updated_at' => $user->updated_at,
                        'contacts' => $user->contacts,
                        'groups' => $user->groups,
                        'bulkMessages' => $user->bulkMessages,
                        'activePackage' => $user->activePackage
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $users
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new user (admin only)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|string|min:6',
            'number' => 'required|string|unique:users',
            'role' => 'sometimes|string|in:user,admin',
            'status' => 'sometimes|string|in:active,inactive,pending',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'number' => $request->number,
                'role' => $request->role ?? 'user',
                'status' => $request->status ?? 'active',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'User created successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'number' => $user->number,
                    'role' => $user->role,
                    'status' => $user->status,
                    'bio' => $user->bio,
                    'companyName' => $user->companyName,
                    'companyAddress' => $user->companyAddress,
                    'companyEmail' => $user->companyEmail,
                    'companyMobile' => $user->companyMobile,
                    'profileImage' => $user->profile_image_url,
                    'companyLogo' => $user->company_logo_url,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific user
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            // Users can only view their own profile unless they're admin
            if (!$isAdmin && $id != $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $user = User::with(['contacts', 'groups', 'bulkMessages', 'activePackage'])->find($id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'number' => $user->number,
                    'role' => $user->role,
                    'status' => $user->status,
                    'bio' => $user->bio,
                    'companyName' => $user->companyName,
                    'companyAddress' => $user->companyAddress,
                    'companyEmail' => $user->companyEmail,
                    'companyMobile' => $user->companyMobile,
                    'profileImage' => $user->profile_image_url,
                    'companyLogo' => $user->company_logo_url,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                    'contacts' => $user->contacts,
                    'groups' => $user->groups,
                    'bulkMessages' => $user->bulkMessages,
                    'activePackage' => $user->activePackage
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
     * Update a user
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'number' => 'sometimes|string|unique:users,number,' . $id,
            'role' => 'sometimes|string|in:user,admin',
            'status' => 'sometimes|string|in:active,inactive,pending',
            'bio' => 'nullable|string',
            'companyName' => 'nullable|string',
            'companyAddress' => 'nullable|string',
            'companyEmail' => 'nullable|email',
            'companyMobile' => 'nullable|string',
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

            // Users can only update their own profile unless they're admin
            if (!$isAdmin && $id != $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $user->update($request->only([
                'name', 'email', 'number', 'role', 'status', 'bio',
                'companyName', 'companyAddress', 'companyEmail', 'companyMobile'
            ]));

            return response()->json([
                'status' => true,
                'message' => 'User updated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'number' => $user->number,
                    'role' => $user->role,
                    'status' => $user->status,
                    'bio' => $user->bio,
                    'companyName' => $user->companyName,
                    'companyAddress' => $user->companyAddress,
                    'companyEmail' => $user->companyEmail,
                    'companyMobile' => $user->companyMobile,
                    'profileImage' => $user->profile_image_url,
                    'companyLogo' => $user->company_logo_url,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
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
     * Delete a user (admin only)
     */
    public function destroy(Request $request, $id)
    {
        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $user->delete();

            return response()->json([
                'status' => true,
                'message' => 'User deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user statistics
     */
    public function getStats(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            // Users can only view their own stats unless they're admin
            if (!$isAdmin && $id != $userId) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $stats = [
                'total_contacts' => $user->contacts()->count(),
                'total_groups' => $user->groups()->count(),
                'total_campaigns' => $user->bulkMessages()->count(),
                'active_package' => $user->activePackage ? $user->activePackage->package : null,
                'account_status' => $user->status,
                'created_at' => $user->created_at,
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
     * Update user status
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:active,inactive,pending',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized. Admin access required.'
                ], 403);
            }

            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $user->update(['status' => $request->status]);

            return response()->json([
                'status' => true,
                'message' => 'User status updated successfully',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'number' => $user->number,
                    'role' => $user->role,
                    'status' => $user->status,
                    'bio' => $user->bio,
                    'companyName' => $user->companyName,
                    'companyAddress' => $user->companyAddress,
                    'companyEmail' => $user->companyEmail,
                    'companyMobile' => $user->companyMobile,
                    'profileImage' => $user->profile_image_url,
                    'companyLogo' => $user->company_logo_url,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at
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
