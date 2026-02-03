<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\Payments;
use App\Models\User;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class PaymentsController extends Controller
{
    /**
     * Helper method untuk apply CRSD filter
     */
    private function applyCRSDFilter($query)
    {
        $user = Auth::guard('api')->user();
        
        if (!$user) {
            return $query;
        }
        
        // Superadmin bisa lihat semua
        if ($user->role === 'superadmin') {
            return $query;
        }
        
        // Admin users - filter berdasarkan data access
        if ($user->role === 'admin') {
            $dataAccess = $user->getEffectiveDataAccess();
            
            if (empty($dataAccess)) {
                // No data access - return empty results
                return $query->whereRaw('1 = 0');
            }
            
            // Filter berdasarkan available data access
            $crsdAccess = array_filter($dataAccess, function($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });
            
            if (count($crsdAccess) === 2) {
                // Admin dengan kedua akses bisa lihat CRSD 1 dan CRSD 2
                return $query->whereHas('order.user', function($q) {
                    $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                });
            } elseif (count($crsdAccess) === 1) {
                $crsdType = reset($crsdAccess);
                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                
                return $query->whereHas('order.user', function($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
            }
        }
        
        return $query;
    }
    
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
     * Get all payments with CRSD filtering (admin only)
     */
    public function getAllPayments(Request $request)
    {
        try {
            $user = Auth::guard('api')->user();
            
            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            $query = Payments::with(['order' => function($q) {
                $q->with('user', 'items.menu');
            }]);

            // Apply CRSD filter
            $this->applyCRSDFilter($query);

            if ($request->get('status')) {
                $query->where('payment_status', $request->get('status'));
            }
            
            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('payment_code', 'like', "%{$search}%")
                      ->orWhereHas('order', function($q2) use ($search) {
                          $q2->where('order_code', 'like', "%{$search}%")
                             ->orWhereHas('user', function($q3) use ($search) {
                                 $q3->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                             });
                      });
                });
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
     * Get CRSD payments (admin only)
     */
    public function getCRSDPayments(Request $request, $crsdType)
    {
        try {
            $user = Auth::guard('api')->user();
            
            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            // Validate CRSD type
            if (!in_array($crsdType, ['crsd1', 'crsd2'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipe CRSD tidak valid'
                ], 400);
            }
            
            // Check access for admin
            if ($user->role === 'admin') {
                $dataAccess = $user->getEffectiveDataAccess();
                if (!in_array($crsdType, $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Anda tidak memiliki akses ke CRSD " . strtoupper($crsdType)
                    ], 403);
                }
            }
            
            $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
            
            $query = Payments::with(['order' => function($q) {
                $q->with('user', 'items.menu');
            }])
            ->whereHas('order.user', function($q) use ($divisiName) {
                $q->where('divisi', $divisiName);
            });

            if ($request->get('status')) {
                $query->where('payment_status', $request->get('status'));
            }
            
            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('payment_code', 'like', "%{$search}%")
                      ->orWhereHas('order', function($q2) use ($search) {
                          $q2->where('order_code', 'like', "%{$search}%")
                             ->orWhereHas('user', function($q3) use ($search) {
                                 $q3->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                             });
                      });
                });
            }

            $payments = $query->latest()->paginate($request->get('per_page', 15));

            return response()->json([
                'success' => true,
                'message' => 'Payments retrieved successfully for ' . strtoupper($crsdType),
                'crsd_type' => $crsdType,
                'data' => $payments
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get CRSD payments error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get payments by status with CRSD filtering
     */
    public function getPaymentsByStatus(Request $request, $status)
    {
        try {
            $user = Auth::guard('api')->user();
            
            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            $validStatuses = ['pending', 'completed', 'rejected', 'failed', 'expired'];
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status pembayaran tidak valid'
                ], 400);
            }
            
            $query = Payments::with(['order' => function($q) {
                $q->with('user', 'items.menu');
            }])
            ->where('payment_status', $status);
            
            // Apply CRSD filter
            $this->applyCRSDFilter($query);
            
            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('payment_code', 'like', "%{$search}%")
                      ->orWhereHas('order', function($q2) use ($search) {
                          $q2->where('order_code', 'like', "%{$search}%")
                             ->orWhereHas('user', function($q3) use ($search) {
                                 $q3->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                             });
                      });
                });
            }
            
            $payments = $query->latest()->paginate($request->get('per_page', 15));
            
            return response()->json([
                'success' => true,
                'message' => ucfirst($status) . ' payments retrieved successfully',
                'status' => $status,
                'data' => $payments
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get payments by status error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve payments',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get CRSD payments by status
     */
    public function getCRSDPaymentsByStatus(Request $request, $crsdType, $status)
    {
        try {
            $user = Auth::guard('api')->user();
            
            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            // Validate CRSD type
            if (!in_array($crsdType, ['crsd1', 'crsd2'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipe CRSD tidak valid'
                ], 400);
            }
            
            $validStatuses = ['pending', 'completed', 'rejected', 'failed', 'expired'];
            if (!in_array($status, $validStatuses)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Status pembayaran tidak valid'
                ], 400);
            }
            
            // Check access for admin
            if ($user->role === 'admin') {
                $dataAccess = $user->getEffectiveDataAccess();
                if (!in_array($crsdType, $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Anda tidak memiliki akses ke CRSD " . strtoupper($crsdType)
                    ], 403);
                }
            }
            
            $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
            
            $query = Payments::with(['order' => function($q) {
                $q->with('user', 'items.menu');
            }])
            ->where('payment_status', $status)
            ->whereHas('order.user', function($q) use ($divisiName) {
                $q->where('divisi', $divisiName);
            });
            
            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('payment_code', 'like', "%{$search}%")
                      ->orWhereHas('order', function($q2) use ($search) {
                          $q2->where('order_code', 'like', "%{$search}%")
                             ->orWhereHas('user', function($q3) use ($search) {
                                 $q3->where('name', 'like', "%{$search}%")
                                    ->orWhere('email', 'like', "%{$search}%");
                             });
                      });
                });
            }
            
            $payments = $query->latest()->paginate($request->get('per_page', 15));
            
            return response()->json([
                'success' => true,
                'message' => ucfirst($status) . ' payments retrieved successfully for ' . strtoupper($crsdType),
                'crsd_type' => $crsdType,
                'status' => $status,
                'data' => $payments
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get CRSD payments by status error: ' . $e->getMessage());
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
            
            // Check CRSD access for admin
            $user = Auth::guard('api')->user();
            if ($user->role === 'admin') {
                $dataAccess = $user->getEffectiveDataAccess();
                $userDivisi = $payment->order->user->divisi ?? null;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
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
            
            // Check CRSD access for admin
            $user = Auth::guard('api')->user();
            if ($user->role === 'admin') {
                $dataAccess = $user->getEffectiveDataAccess();
                $userDivisi = $payment->order->user->divisi ?? null;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
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
            
            // Check CRSD access for admin
            $user = Auth::guard('api')->user();
            if ($user->role === 'admin') {
                $dataAccess = $user->getEffectiveDataAccess();
                $userDivisi = $payment->order->user->divisi ?? null;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
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
            
            // Check CRSD access for admin
            $user = Auth::guard('api')->user();
            if ($user->role === 'admin') {
                $dataAccess = $user->getEffectiveDataAccess();
                $userDivisi = $payment->order->user->divisi ?? null;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke pembayaran ini'
                    ], 403);
                }
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