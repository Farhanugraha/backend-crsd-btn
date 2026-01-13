<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\Payments;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log; 

class PaymentsController extends Controller
{
    /**
     * Upload payment proof (bukti pembayaran)
     * FLOW: User upload bukti → Payment record dibuat → Order status = paid
     */
    public function uploadProof(Request $request, $orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            // Validasi input
            $validator = Validator::make($request->all(), [
                'proof_image' => 'required|image|mimes:jpeg,png,jpg|max:5120',
                'payment_method' => 'required|in:qris,transfer',
                'notes' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Ambil order milik user
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
                    'message' => 'Pesanan sudah dalam status ' . $order->status
                ], 400);
            }

            // Upload bukti pembayaran
            $file = $request->file('proof_image');
            $path = Storage::disk('public')->putFileAs(
                'payments/' . $order->id,
                $file,
                time() . '_' . $file->getClientOriginalName()
            );

            // Buat atau update payment record
            $payment = Payments::updateOrCreate(
                ['order_id' => $orderId],
                [
                    'payment_method' => $request->payment_method,
                    'proof_image' => $path,
                    'notes' => $request->notes ?? null,
                    'payment_status' => 'completed',
                    'paid_at' => now(),
                    'transaction_id' => 'TXN-' . $order->id . '-' . time()
                ]
            );

            // Update order status menjadi 'paid'
            $order->update(['status' => 'paid']);

            return response()->json([
                'success' => true,
                'message' => 'Bukti pembayaran berhasil diunggah dan order status diubah menjadi paid',
                'data' => [
                    'order_id' => $order->id,
                    'order_code' => $order->order_code,
                    'order_status' => $order->status,
                    'payment_id' => $payment->id,
                    'payment_status' => $payment->payment_status,
                    'transaction_id' => $payment->transaction_id,
                    'proof_image' => $payment->proof_image,
                    'paid_at' => $payment->paid_at
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Payment uploadProof error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunggah bukti pembayaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment history for user
     */
    public function history()
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $payments = Payments::with(['order' => function($q) {
                $q->with('items.menu');
            }])
            ->whereHas('order', function($q) use ($user) {
                $q->where('user_id', $user->id);
            })
            ->latest()
            ->get();

            return response()->json([
                'success' => true,
                'message' => 'Payment history retrieved',
                'data' => $payments
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment history',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment details (user - by order_id)
     */
    public function show($orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            $order = Orders::with('items.menu')
                ->where('user_id', $user->id)
                ->find($orderId);

            if (!$order) {
                return response()->json([
                    'success' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            $payment = Payments::where('order_id', $orderId)->first();

            return response()->json([
                'success' => true,
                'message' => 'Payment info retrieved successfully',
                'data' => [
                    'order' => $order,
                    'payment' => $payment
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment info',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Initiate payment (create payment record dengan status pending)
     */
    public function initiate(Request $request, $orderId)
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

            if ($order->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Pesanan ini sudah dibayar atau dibatalkan'
                ], 400);
            }

            $existingPayment = Payments::where('order_id', $orderId)->first();

            if ($existingPayment && $existingPayment->payment_status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment sudah berhasil untuk pesanan ini'
                ], 400);
            }

            $payment = Payments::updateOrCreate(
                ['order_id' => $orderId],
                [
                    'payment_method' => 'bank_transfer',
                    'payment_status' => 'pending'
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Siap untuk melakukan pembayaran',
                'data' => [
                    'order_id' => $orderId,
                    'order_code' => $order->order_code,
                    'total_price' => $order->total_price,
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memulai pembayaran',
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
            $query = Payments::with(['order' => function($q) {
                $q->with('user', 'items.menu');
            }]);

            if ($request->get('status')) {
                $query->where('payment_status', $request->get('status'));
            }

            $payments = $query->latest()->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'message' => 'All payments retrieved',
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

    /**
     * Admin get payment detail by ORDER ID (dari orders list)
     * PENTING: Ini dipanggil dari admin dashboard dengan order_id
     */
    public function getPaymentByOrder($orderId)
    {
        try {
            $payment = Payments::with(['order' => function($q) {
                $q->with(['user', 'items.menu']);
            }])->where('order_id', $orderId)->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found for this order'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment detail retrieved',
                'data' => $payment
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get payment by order failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin get payment detail by PAYMENT ID
     * (jika butuh akses langsung by payment id)
     */
    public function getPaymentDetail($paymentId)
    {
        try {
            $payment = Payments::with(['order' => function($q) {
                $q->with(['user', 'items.menu']);
            }])->find($paymentId);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Payment detail retrieved',
                'data' => $payment
            ], 200);

        } catch (\Exception $e) {
            Log::error('Get payment detail failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin confirm or reject payment (by payment_id)
     */
    public function confirmPayment(Request $request, $paymentId)
    {
        try {
            $payment = Payments::with(['order' => function($q) {
                $q->with(['user', 'items.menu']);
            }])->find($paymentId);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            // Set status berdasarkan action
            $newStatus = 'completed';
            $payment->update(['payment_status' => $newStatus]);

            // Update order status ke paid
            $payment->order->update(['status' => 'paid']);

            // Reload data
            $payment = Payments::with(['order' => function($q) {
                $q->with(['user', 'items.menu']);
            }])->find($paymentId);

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated to completed',
                'data' => $payment
            ], 200);

        } catch (\Exception $e) {
            Log::error('Confirm payment failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin reject payment (by payment_id)
     */
    public function rejectPayment(Request $request, $paymentId)
    {
        try {
            $payment = Payments::with(['order' => function($q) {
                $q->with(['user', 'items.menu']);
            }])->find($paymentId);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            // Set status to rejected
            $newStatus = 'rejected';
            $payment->update(['payment_status' => $newStatus]);

            // Update order status kembali ke pending
            $payment->order->update(['status' => 'pending']);

            // Reload data
            $payment = Payments::with(['order' => function($q) {
                $q->with(['user', 'items.menu']);
            }])->find($paymentId);

            return response()->json([
                'success' => true,
                'message' => 'Payment status updated to rejected',
                'data' => $payment
            ], 200);

        } catch (\Exception $e) {
            Log::error('Reject payment failed:', ['error' => $e->getMessage()]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject payment',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}