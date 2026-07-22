<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Credit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class CreditController extends Controller
{
    /**
     * Get all credits for the authenticated user
     */
    public function index(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? Credit::query() : Credit::where('userId', $userId);

            $credits = $query->with('user')
                ->orderBy('created_at', 'desc')
                ->paginate(15);

            return response()->json([
                'status' => true,
                'data' => $credits
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new credit entry
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userId' => 'sometimes|exists:users,id',
            'amount' => 'required|numeric',
            'type' => 'required|string|in:purchased,earned,bonus,refund,used,expired',
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
            $isAdmin = $request->user()->role === 'admin';
            $targetUserId = $isAdmin && $request->userId ? $request->userId : $request->user()->id;

            $credit = Credit::create([
                'userId' => $targetUserId,
                'amount' => $request->amount,
                'type' => $request->type,
                'description' => $request->description,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Credit added successfully',
                'data' => $credit->load('user')
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific credit
     */
    public function show(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? Credit::query() : Credit::where('userId', $userId);
            $credit = $query->with('user')->find($id);

            if (!$credit) {
                return response()->json([
                    'status' => false,
                    'message' => 'Credit not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'data' => $credit
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a credit
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'sometimes|numeric',
            'type' => 'sometimes|string|in:purchased,earned,bonus,refund,used,expired',
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
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? Credit::query() : Credit::where('userId', $userId);
            $credit = $query->find($id);

            if (!$credit) {
                return response()->json([
                    'status' => false,
                    'message' => 'Credit not found'
                ], 404);
            }

            $credit->update($request->only(['balance', 'history', 'payments']));

            return response()->json([
                'status' => true,
                'message' => 'Credit updated successfully',
                'data' => $credit->load('user')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a credit
     */
    public function destroy(Request $request, $id)
    {
        try {
            $userId = $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            $query = $isAdmin ? Credit::query() : Credit::where('userId', $userId);
            $credit = $query->find($id);

            if (!$credit) {
                return response()->json([
                    'status' => false,
                    'message' => 'Credit not found'
                ], 404);
            }

            $credit->delete();

            return response()->json([
                'status' => true,
                'message' => 'Credit deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's credit balance
     */
    public function getBalance(Request $request, $userId = null)
    {
        try {
            $targetUserId = $userId ?? $request->user()->id;
            $isAdmin = $request->user()->role === 'admin';

            // Only allow users to view their own balance or admins to view any balance
            if (!$isAdmin && $targetUserId != $request->user()->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $totalCredits = Credit::where('userId', $targetUserId)->sum('balance');
            $creditsByType = Credit::where('userId', $targetUserId)
                ->selectRaw('type, SUM(balance) as total')
                ->groupBy('type')
                ->get();

            return response()->json([
                'status' => true,
                'data' => [
                    'total_credits' => $totalCredits,
                    'credits_by_type' => $creditsByType
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
     * Create payment order
     */
    public function createOrder(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'currency' => 'sometimes|string|in:INR,USD',
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
            $amount = $request->amount;
            $currency = $request->currency ?? 'INR';

            // Generate order ID
            $orderId = 'ORDER_' . time() . '_' . $userId;

            // Create order record
            $order = [
                'order_id' => $orderId,
                'userId' => $userId,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'pending',
                'created_at' => now()
            ];

            return response()->json([
                'status' => true,
                'message' => 'Order created successfully',
                'data' => $order
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verify payment
     */
    public function verifyPayment(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'order_id' => 'required|string',
            'payment_id' => 'required|string',
            'signature' => 'required|string',
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
            $orderId = $request->order_id;
            $paymentId = $request->payment_id;
            $signature = $request->signature;

            // Verify payment signature (implement your payment gateway logic)
            $isValid = true; // Placeholder for actual verification

            if ($isValid) {
                // Add credits to user account
                $amount = 100; // Get from order
                Credit::create([
                    'userId' => $userId,
                    'amount' => $amount,
                    'type' => 'purchased',
                    'description' => "Payment verified for order {$orderId}",
                    'reference_id' => $paymentId
                ]);

                return response()->json([
                    'status' => true,
                    'message' => 'Payment verified successfully',
                    'data' => [
                        'payment_id' => $paymentId,
                        'credits_added' => $amount
                    ]
                ]);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Payment verification failed'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deduct credits
     */
    public function deductCredits(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|integer|min:1',
            'description' => 'nullable|string',
            'reference_id' => 'nullable|string',
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
            $amount = $request->amount;

            // Check if user has sufficient credits
            $currentBalance = Credit::where('userId', $userId)
                ->where('type', 'purchased')
                ->sum('amount') -
                Credit::where('userId', $userId)
                ->where('type', 'deduct')
                ->sum('amount');

            if ($currentBalance < $amount) {
                return response()->json([
                    'status' => false,
                    'message' => 'Insufficient credits'
                ], 400);
            }

            Credit::create([
                'userId' => $userId,
                'amount' => $amount,
                'type' => 'deduct',
                'description' => $request->description ?? 'Credits deducted',
                'reference_id' => $request->reference_id
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Credits deducted successfully',
                'data' => [
                    'amount_deducted' => $amount,
                    'remaining_balance' => $currentBalance - $amount
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
     * Get credit history
     */
    public function getHistory(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $type = $request->query('type');
            $limit = $request->query('limit', 50);

            $query = Credit::where('userId', $userId);

            if ($type) {
                $query->where('type', $type);
            }

            $credits = $query->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'status' => true,
                'data' => $credits
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get credit report
     */
    public function creditReport(Request $request)
    {
        try {
            $userId = $request->user()->id;
            $dateFrom = $request->query('date_from');
            $dateTo = $request->query('date_to');

            $query = Credit::where('userId', $userId);

            if ($dateFrom) {
                $query->where('created_at', '>=', $dateFrom);
            }

            if ($dateTo) {
                $query->where('created_at', '<=', $dateTo);
            }

            $credits = $query->get();

            $purchased = $credits->where('type', 'purchased')->sum('balance');
            $deducted = $credits->where('type', 'deduct')->sum('balance');
            $balance = $purchased - $deducted;

            $report = [
                'total_purchased' => $purchased,
                'total_deducted' => $deducted,
                'current_balance' => $balance,
                'transactions_count' => $credits->count(),
                'transactions' => $credits->groupBy('type')
            ];

            return response()->json([
                'status' => true,
                'data' => $report
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
