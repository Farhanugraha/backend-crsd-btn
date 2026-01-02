<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Orders;
use App\Models\Payments;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;

class PaymentsController extends Controller
{
    /**
     * Get payment for order
     */
    public function show($orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            $order = Orders::where('user_id', $user->id)->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $payment = Payments::where('order_id', $orderId)->first();

            return response()->json([
                'success' => true,
                'message' => 'Payment retrieved successfully',
                'data' => $payment
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process payment for order
     */
    public function process(Request $request, $orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'payment_method' => 'required|in:credit_card,debit_card,bank_transfer,e_wallet',
                'transaction_id' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $order = Orders::where('user_id', $user->id)->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Can only process payment for pending orders'
                ], 400);
            }

            // Check if payment already exists
            $payment = Payments::where('order_id', $orderId)->first();

            if ($payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment already exists for this order'
                ], 400);
            }

            // Create payment
            $payment = Payments::create([
                'order_id' => $orderId,
                'payment_method' => $request->payment_method,
                'payment_status' => 'pending',
                'transaction_id' => $request->transaction_id ?? null
            ]);

            // Simulate payment processing (in real app, integrate with payment gateway)
            // For now, mark as completed
            $payment->payment_status = 'completed';
            $payment->save();

            // Update order status to paid
            $order->status = 'paid';
            $order->save();

            return response()->json([
                'success' => true,
                'message' => 'Payment processed successfully',
                'data' => $payment
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to process payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get all payments (admin only)
     */
    public function getAllPayments(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);
            $status = $request->get('status');

            $query = Payments::with('order.user');

            if ($status) {
                $query->where('payment_status', $status);
            }

            $payments = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'All payments retrieved successfully',
                'data' => $payments
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
