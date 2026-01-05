<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\Payments;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class PaymentsController extends Controller
{
    /**
     * Get payment page info (QRIS + Bank Details)
     */
    public function show($orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();
            
            $order = Orders::with('items')
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
                    'payment' => $payment,
                    'payment_info' => [
                        'bank_account' => '1234567890',
                        'bank_name' => 'BCA',
                        'account_name' => 'Restaurant ABC',
                        'qris_image' => 'https://via.placeholder.com/300x300?text=QRIS',
                        'instructions' => [
                            'Scan QRIS dengan aplikasi perbankan kamu',
                            'Atau transfer manual ke rekening BCA di atas',
                            'Upload bukti pembayaran untuk konfirmasi'
                        ]
                    ]
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
     * Initiate payment (create payment record)
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

            // Check if payment already exists
            $existingPayment = Payments::where('order_id', $orderId)->first();

            if ($existingPayment && $existingPayment->payment_status === 'completed') {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment sudah berhasil untuk pesanan ini'
                ], 400);
            }

            // Create payment record dengan status pending
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
                    'payment_info' => [
                        'bank_account' => '1234567890',
                        'bank_name' => 'BCA',
                        'account_name' => 'Restaurant ABC',
                        'qris_image' => 'https://via.placeholder.com/300x300?text=QRIS'
                    ]
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
     * Upload payment proof (bukti pembayaran)
     */
    public function uploadProof(Request $request, $orderId)
    {
        try {
            $user = JWTAuth::parseToken()->authenticate();

            $validator = Validator::make($request->all(), [
                'proof_image' => 'required|image|mimes:jpeg,png,jpg|max:2048',
                'payment_notes' => 'nullable|string|max:500'
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

            $payment = Payments::where('order_id', $orderId)->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment belum diinisiasi'
                ], 404);
            }

            // Upload image
            $path = $request->file('proof_image')->store('payments', 'public');

            // Update payment dengan bukti
            $payment->update([
                'proof_image' => $path,
                'payment_notes' => $request->payment_notes ?? null,
                'payment_status' => 'completed',
                'paid_at' => now()
            ]);

            // Update order status to paid
            $order->update(['status' => 'paid']);

            return response()->json([
                'success' => true,
                'message' => 'Bukti pembayaran berhasil diunggah. Menunggu konfirmasi admin',
                'data' => [
                    'payment' => $payment,
                    'order_status' => $order->status
                ]
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengunggah bukti pembayaran',
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

            $query = Payments::with(['order' => function($q) {
                $q->with('user', 'items');
            }]);

            if ($status) {
                $query->where('payment_status', $status);
            }

            $payments = $query->latest()->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Semua pembayaran berhasil diambil',
                'data' => $payments
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pembayaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin confirm payment
     */
    public function confirmPayment(Request $request, $paymentId)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:completed,rejected'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $payment = Payments::with('order')->find($paymentId);

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found'
                ], 404);
            }

            $payment->update(['payment_status' => $request->status]);

            // Jika completed, order status jadi paid
            // Jika rejected, order status kembali pending
            if ($request->status === 'completed') {
                $payment->order->update(['status' => 'paid']);
            } elseif ($request->status === 'rejected') {
                $payment->order->update(['status' => 'pending']);
            }

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran ' . $request->status,
                'data' => $payment
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengkonfirmasi pembayaran',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}