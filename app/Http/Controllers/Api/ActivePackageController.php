<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivePackage;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ActivePackageController extends Controller
{
    /**
     * Get all active packages (global view)
     */
    public function index(Request $request)
    {
        try {
            // For global plan, show all active packages to everyone
            $activePackages = ActivePackage::with(['package', 'user'])
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'status' => true,
                'data' => $activePackages
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new active package (subscribe to a package)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'sometimes|exists:users,id',
            'packageId' => 'required|exists:packages,id',
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

            // Fetch user
            $user = User::find($targetUserId);
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Fetch package
            $package = Package::find($request->packageId);
            if (!$package) {
                return response()->json([
                    'status' => false,
                    'message' => 'Package not found'
                ], 404);
            }

            // Check if user already has an active package
            $existingActivePackage = ActivePackage::where('userId', $targetUserId)
                ->where('status', 1)
                ->first();

            if ($existingActivePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'User already has an active package. Please deactivate the current package first.'
                ], 400);
            }

            $startDate = now();
            $endDate = now()->addDays($package->day);

            $activePackage = ActivePackage::create([
                'userId' => $targetUserId,
                'packageId' => $request->packageId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'day' => $package->day,
                'status' => 1,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Package activated successfully',
                'data' => $activePackage->load(['package', 'user'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific active package
     */
    public function show(Request $request, $id)
    {
        try {
            // For global plan, allow access to any active package
            $activePackage = ActivePackage::with(['package', 'user'])->find($id);

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Active package not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $activePackage
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update an active package
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|integer|in:0,1',
            'endDate' => 'sometimes|date|after:startDate',
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

            $query = $isAdmin ? ActivePackage::query() : ActivePackage::where('userId', $userId);
            $activePackage = $query->find($id);

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Active package not found'
                ], 404);
            }

            $activePackage->update($request->only(['status', 'endDate']));

            return response()->json([
                'status' => true,
                'message' => 'Active package updated successfully',
                'data' => $activePackage->load(['package', 'user'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete an active package
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? ActivePackage::query() : ActivePackage::where('userId', $userId);
            $activePackage = $query->find($id);

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Active package not found'
                ], 404);
            }

            $activePackage->delete();

            return response()->json([
                'status' => true,
                'message' => 'Active package deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Renew an active package
     */
    public function renew(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Check password for renewal
            if ($request->password !== env('RENEW_PLAN_PASSWORD', 'renew123')) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: Invalid password'
                ], 401);
            }

            $activePackage = ActivePackage::with('package')->find($id);

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'Active package not found'
                ], 404);
            }

            $package = $activePackage->package;
            if (!$package) {
                return response()->json([
                    'status' => false,
                    'message' => 'Package not found'
                ], 404);
            }

            // Renew by adding package days to current end date
            $currentEndDate = $activePackage->endDate;
            $newEndDate = $currentEndDate->addDays($package->day);

            $activePackage->update([
                'endDate' => $newEndDate,
                'status' => 1, // Make sure status is active after renewal
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Package renewed successfully',
                'data' => $activePackage->load(['package', 'user'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get package renewal status and information
     */
    public function getRenewalStatus(Request $request)
    {
        try {
            $activePackage = ActivePackage::where('status', 1)
                ->with('package')
                ->latest()
                ->first();

            if (!$activePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active plan found'
                ], 404);
            }

            $now = now();
            $daysUntilExpiry = $activePackage->getDaysUntilExpiry();
            $isInRenewalPeriod = $activePackage->isInMonthlyRenewalPeriod();
            $needsManualReactivation = $activePackage->needsManualReactivation();

            // Calculate next monthly renewal date
            $nextRenewalDate = null;
            if ($activePackage->lastMonthlyReset) {
                $nextRenewalDate = $activePackage->lastMonthlyReset->addDays(30);
            }

            return response()->json([
                'status' => true,
                'data' => [
                    'package' => [
                        'id' => $activePackage->id,
                        'name' => $activePackage->package->packageName,
                        'startDate' => $activePackage->startDate,
                        'endDate' => $activePackage->endDate,
                        'daysUntilExpiry' => $daysUntilExpiry,
                        'isInRenewalPeriod' => $isInRenewalPeriod,
                        'needsManualReactivation' => $needsManualReactivation,
                        'lastMonthlyReset' => $activePackage->lastMonthlyReset,
                        'nextRenewalDate' => $nextRenewalDate,
                    ],
                    'usage' => [
                        'monthlyUsedMessages' => $activePackage->monthlyUsedMsgCount,
                        'monthlyUsedTemplates' => $activePackage->monthlyUsedTemplateCount,
                        'monthlyUsedContacts' => $activePackage->monthlyUsedContactCount,
                        'totalUsedMessages' => $activePackage->usedMsgCount,
                    ],
                    'limits' => [
                        'messageLimit' => $activePackage->package->msgCount,
                        'templateLimit' => $activePackage->package->templateCount,
                        'contactLimit' => $activePackage->package->contactCount,
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

    /**
     * Get global active plan (same for all users)
     */
    public function getUserPlan(Request $request, $userId = null)
    {
        try {
            // For global plan, show the current active plan to everyone
            $plans = ActivePackage::where('status', 1)
                ->with('package')
                ->orderBy('created_at', 'desc')
                ->get();

            // Update status if expired
            $now = now();
            foreach ($plans as $plan) {
                if ($plan->endDate < $now) {
                    $plan->update(['status' => 0]);
                }
            }

            return response()->json([
                'status' => true,
                'data' => $plans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Assign a package to a user (Admin only)
     */
    public function assignPackage(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'required|exists:users,id',
            'packageId' => 'required|exists:packages,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            // Check if user is admin
            if ($request->user()->role !== 'admin') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only admins can assign packages to users'
                ], 403);
            }

            $targetUserId = $request->userId;
            $packageId = $request->packageId;

            // Fetch user
            $user = User::find($targetUserId);
            if (!$user) {
                return response()->json([
                    'status' => false,
                    'message' => 'User not found'
                ], 404);
            }

            // Fetch package
            $package = Package::find($packageId);
            if (!$package) {
                return response()->json([
                    'status' => false,
                    'message' => 'Package not found'
                ], 404);
            }

            // Check if user already has an active package
            $existingActivePackage = ActivePackage::where('userId', $targetUserId)
                ->where('status', 1)
                ->first();

            if ($existingActivePackage) {
                return response()->json([
                    'status' => false,
                    'message' => 'User already has an active package. Please deactivate the current package first.'
                ], 400);
            }

            $startDate = now();
            $endDate = now()->addDays($package->day);

            $activePackage = ActivePackage::create([
                'userId' => $targetUserId,
                'packageId' => $packageId,
                'startDate' => $startDate,
                'endDate' => $endDate,
                'day' => $package->day,
                'status' => 1,
                'usedMsgCount' => 0
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Package assigned successfully to user',
                'data' => $activePackage->load(['package', 'user'])
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get global plan history (all active packages)
     */
    public function getUserPlanHistory(Request $request, $userId = null)
    {
        try {
            $isAdmin = $request->user()->role === 'admin';

            // For global plan, show all active packages to everyone
            $history = ActivePackage::with(['package', 'user'])
                ->orderBy('created_at', 'desc')
                ->get();

            // Transform data to match the desired format
            $transformedData = $history->map(function ($activePackage) {
                return [
                    'readBy' => $activePackage->readBy ?? [],
                    '_id' => $activePackage->id,
                    'userId' => $activePackage->userId,
                    'packageId' => [
                        '_id' => $activePackage->package->id,
                        'packageName' => $activePackage->package->packageName,
                        'packageDesc' => $activePackage->package->packageDesc,
                        'day' => $activePackage->package->day,
                        'msgCount' => $activePackage->package->msgCount,
                        'templateCount' => $activePackage->package->templateCount,
						'createdAt' => optional($activePackage->package->created_at)->toISOString(),
						'updatedAt' => optional($activePackage->package->updated_at)->toISOString(),
                        '__v' => 0
                    ],
					'startDate' => optional($activePackage->startDate)->toISOString(),
					'endDate' => optional($activePackage->endDate)->toISOString(),
                    'day' => $activePackage->day,
                    'status' => $activePackage->status,
					'createdAt' => optional($activePackage->created_at)->toISOString(),
					'updatedAt' => optional($activePackage->updated_at)->toISOString(),
                    '__v' => 0
                ];
            });

            return response()->json([
                'success' => true,
                'count' => $transformedData->count(),
                'data' => $transformedData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
