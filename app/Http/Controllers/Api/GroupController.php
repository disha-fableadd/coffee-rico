<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GroupController extends Controller
{
    /**
     * Get all groups for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? Group::query() : Group::where('userId', $userId);

            $groups = $query->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($group) {
                    // Get contacts that have this group in their groupIds
                    $contacts = $group->contacts;
                    
                    return [
                        'id' => $group->id,
                        'name' => $group->name,
                        'description' => $group->description,
                        'memberCount' => $contacts->count(),
                        'members' => $contacts,
                        'created_at' => $group->created_at,
                        'updated_at' => $group->updated_at
                    ];
                });

            return response()->json([
                'status' => true,
                'count' => $groups->count(),
                'data' => $groups
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new group
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
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

            // Check for duplicate group name for the same user
            $exists = Group::where('name', $request->name)
                ->where('userId', $userId)
                ->exists();

            if ($exists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group name already exists for this user'
                ], 400);
            }

            $group = Group::create([
                'name' => $request->name,
                'description' => $request->description,
                'userId' => $userId
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Group created successfully',
                'data' => $group
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific group
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? Group::query() : Group::where('userId', $userId);
            $group = $query->find($id);

            if (!$group) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            // Get contacts that have this group in their groupIds
            $contacts = $group->contacts;

            return response()->json([
                'status' => true,
                'data' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'description' => $group->description,
                    'status' => $group->status,
                    'memberCount' => $contacts->count(),
                    'members' => $contacts,
                    'created_at' => $group->created_at,
                    'updated_at' => $group->updated_at
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
     * Update a group
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
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
            $group = Group::where('userId', $userId)->find($id);

            if (!$group) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            // Prevent duplicate group name
            if ($request->has('name')) {
                $exists = Group::where('name', $request->name)
                    ->where('id', '!=', $id)
                    ->where('userId', $userId)
                    ->exists();

                if ($exists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Group name already exists'
                    ], 400);
                }
            }

            $group->update($request->only(['name', 'description']));

            return response()->json([
                'status' => true,
                'message' => 'Group updated successfully',
                'data' => $group
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a group
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $group = Group::where('userId', $userId)->find($id);

            if (!$group) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            // Remove group reference from all contacts that have this group in their groupIds
            $contactsWithGroup = Contact::whereJsonContains('groupIds', $group->id)->get();
            foreach ($contactsWithGroup as $contact) {
                $currentGroups = $contact->groupIds ?? [];
                $updatedGroups = array_filter($currentGroups, function($groupId) use ($group) {
                    return $groupId != $group->id;
                });
                $contact->update(['groupIds' => array_values($updatedGroups)]);
            }

            $group->delete();

            return response()->json([
                'status' => true,
                'message' => 'Group deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add contacts to a group
     */
    public function addContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'groupId' => 'required|exists:groups,id',
            'contactIds' => 'required|array',
            'contactIds.*' => 'exists:contacts,id'
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
            $group = Group::where('userId', $userId)->find($request->groupId);

            if (!$group) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            // Add contacts to group
            $contacts = Contact::whereIn('id', $request->contactIds)
                ->where('userId', $userId)
                ->get();

            foreach ($contacts as $contact) {
                $currentGroups = $contact->groupIds ?? [];
                if (!in_array($request->groupId, $currentGroups)) {
                    $currentGroups[] = $request->groupId;
                    $contact->update(['groupIds' => $currentGroups]);
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Contacts added to group successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove contacts from a group
     */
    public function removeContacts(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'groupId' => 'required|exists:groups,id',
            'contactIds' => 'required|array',
            'contactIds.*' => 'exists:contacts,id'
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
            $group = Group::where('userId', $userId)->find($request->groupId);

            if (!$group) {
                return response()->json([
                    'status' => false,
                    'message' => 'Group not found'
                ], 404);
            }

            // Remove contacts from group
            $contacts = Contact::whereIn('id', $request->contactIds)
                ->where('userId', $userId)
                ->get();

            foreach ($contacts as $contact) {
                $currentGroups = $contact->groupIds ?? [];
                $updatedGroups = array_filter($currentGroups, function($groupId) use ($request) {
                    return $groupId != $request->groupId;
                });
                $contact->update(['groupIds' => array_values($updatedGroups)]);
            }

            return response()->json([
                'status' => true,
                'message' => 'Contacts removed from group successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
