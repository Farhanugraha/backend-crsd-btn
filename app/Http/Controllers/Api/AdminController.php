<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AdminController extends Controller
{
    /**
     * Admin Dashboard
     */
    public function dashboard()
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            $totalOrders = Orders::count();
            $pendingOrders = Orders::where('status', 'pending')->count();
            $processingOrders = Orders::where('order_status', 'processing')->count();
            $completedOrders = Orders::where('order_status', 'completed')->count();
            $canceledOrders = Orders::where('order_status', 'canceled')->count();
            
            $totalRevenue = Orders::where('status', 'paid')->sum('total_price');
            $pendingPayments = Payments::where('payment_status', 'pending')->count();
            
            $totalUsers = User::where('role', 'user')->count();
            $totalAdmins = User::whereIn('role', ['admin', 'superadmin'])->count();

            return response()->json([
                'success' => true,
                'message' => 'Admin dashboard loaded successfully',
                'data' => [
                    'orders' => [
                        'total' => $totalOrders,
                        'pending' => $pendingOrders,
                        'processing' => $processingOrders,
                        'completed' => $completedOrders,
                        'canceled' => $canceledOrders,
                    ],
                    'payments' => [
                        'total_revenue' => $totalRevenue ?? 0,
                        'pending_payments' => $pendingPayments,
                    ],
                    'users' => [
                        'total_users' => $totalUsers,
                        'total_admins' => $totalAdmins,
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Statistics with Chart Data
     * GET /api/admin/statistics?start_date=2025-12-19&end_date=2026-01-19
     */
    public function getStatistics(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            // Get date range from request
            $startDateInput = $request->get('start_date');
            $endDateInput = $request->get('end_date');

            // Default ke bulan ini jika tidak ada parameter
            if (!$startDateInput || !$endDateInput) {
                $endDateInput = now()->toDateString();
                $startDateInput = now()->subMonth()->toDateString();
            }

            // Validate and parse dates
            try {
                $startDate = Carbon::parse($startDateInput)->startOfDay();
                $endDate = Carbon::parse($endDateInput)->endOfDay();

                if ($startDate > $endDate) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tanggal awal harus lebih kecil dari tanggal akhir'
                    ], 400);
                }
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format tanggal tidak valid'
                ], 400);
            }

            // Total Orders in date range
            $totalOrders = Orders::whereBetween('created_at', [$startDate, $endDate])
                ->count();
            
            // Total Revenue (paid orders) in date range
            $totalRevenue = (int) Orders::where('status', 'paid')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->sum('total_price');
            
            // Orders by order_status
            $completedOrders = Orders::where('order_status', 'completed')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();
                
            $processingOrders = Orders::where('order_status', 'processing')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();
                
            $canceledOrders = Orders::where('order_status', 'canceled')
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();
            
            // Average order value
            $averageOrderValue = $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0;
            
            // Today's statistics
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();
            $todayOrders = Orders::whereBetween('created_at', [$todayStart, $todayEnd])
                ->count();
            $todayRevenue = (int) Orders::where('status', 'paid')
                ->whereBetween('created_at', [$todayStart, $todayEnd])
                ->sum('total_price');
            
            // Calculate growth (previous period vs current period)
            $periodDays = $endDate->diffInDays($startDate) + 1;
            $previousPeriodStart = (clone $startDate)->subDays($periodDays)->startOfDay();
            $previousPeriodEnd = (clone $startDate)->subDay()->endOfDay();
            
            $previousPeriodRevenue = (int) Orders::where('status', 'paid')
                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->sum('total_price');
                
            $previousPeriodOrders = Orders::whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd])
                ->count();
            
            $revenueGrowth = $previousPeriodRevenue > 0 
                ? (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue * 100)
                : ($totalRevenue > 0 ? 100 : 0);
            
            $orderGrowth = $previousPeriodOrders > 0
                ? (($totalOrders - $previousPeriodOrders) / $previousPeriodOrders * 100)
                : ($totalOrders > 0 ? 100 : 0);

            // Chart data - Orders and Revenue per day
            $chartData = [];
            $currentDate = clone $startDate;
            
            while ($currentDate <= $endDate) {
                $dayStart = (clone $currentDate)->startOfDay();
                $dayEnd = (clone $currentDate)->endOfDay();
                
                $dayOrders = Orders::whereBetween('created_at', [$dayStart, $dayEnd])
                    ->count();
                    
                $dayRevenue = (int) Orders::where('status', 'paid')
                    ->whereBetween('created_at', [$dayStart, $dayEnd])
                    ->sum('total_price');
                
                $chartData[] = [
                    'date' => $currentDate->format('d M'),
                    'orders' => $dayOrders,
                    'revenue' => $dayRevenue
                ];
                
                $currentDate->addDay();
            }

            return response()->json([
                'success' => true,
                'message' => 'Statistik berhasil dimuat',
                'data' => [
                    'totalOrders' => $totalOrders,
                    'totalRevenue' => $totalRevenue,
                    'completedOrders' => $completedOrders,
                    'processingOrders' => $processingOrders,
                    'canceledOrders' => $canceledOrders,
                    'averageOrderValue' => $averageOrderValue,
                    'todayOrders' => $todayOrders,
                    'todayRevenue' => $todayRevenue,
                    'revenueGrowth' => round($revenueGrowth, 2),
                    'orderGrowth' => round($orderGrowth, 2),
                    'chartData' => $chartData,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Statistics error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Reports
     * GET /api/admin/reports
     */
    public function getReports(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            // Get date range from request (optional)
            $startDateInput = $request->get('start_date');
            $endDateInput = $request->get('end_date');
            
            $query = Orders::query();

            // Filter by date range jika ada
            if ($startDateInput && $endDateInput) {
                try {
                    $startDate = Carbon::parse($startDateInput)->startOfDay();
                    $endDate = Carbon::parse($endDateInput)->endOfDay();
                    
                    if ($startDate <= $endDate) {
                        $query->whereBetween('created_at', [$startDate, $endDate]);
                    }
                } catch (\Exception $e) {
                    // Ignore invalid dates
                }
            }

            // Total orders
            $totalOrders = $query->count();

            // Orders by order_status
            $ordersByStatus = Orders::selectRaw('order_status as status, COUNT(*) as total')
                ->groupBy('order_status')
                ->get()
                ->map(function ($item) {
                    return [
                        'status' => $item->status ?? 'unknown',
                        'total' => $item->total,
                    ];
                })
                ->toArray();

            // Payment summary
            $paymentSummary = Payments::selectRaw('payment_status as status, COUNT(*) as total, SUM(amount) as total_amount')
                ->groupBy('payment_status')
                ->get()
                ->map(function ($item) {
                    return [
                        'status' => $item->status ?? 'unknown',
                        'total' => $item->total,
                        'total_amount' => (int) ($item->total_amount ?? 0),
                    ];
                })
                ->toArray();

            // User statistics
            $userStatistics = [
                'total_users' => User::where('role', 'user')->count(),
                'total_admins' => User::whereIn('role', ['admin', 'superadmin'])->count(),
                'active_users' => User::where('is_active', 1)->where('role', 'user')->count(),
            ];

            // Top users with order count
            $topUsers = User::withCount('orders')
                ->where('role', 'user')
                ->orderBy('orders_count', 'desc')
                ->limit(10)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'orders_count' => $user->orders_count ?? 0,
                    ];
                })
                ->toArray();

            return response()->json([
                'success' => true,
                'message' => 'Reports retrieved successfully',
                'data' => [
                    'total_orders' => $totalOrders,
                    'orders_by_status' => $ordersByStatus,
                    'payment_summary' => $paymentSummary,
                    'user_statistics' => $userStatistics,
                    'top_users' => $topUsers,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('getReports error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil laporan: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Orders Detail with Items (untuk export)
     * GET /api/admin/orders-detail?start_date=2025-12-19&end_date=2026-01-19
     */
    public function getOrdersDetail(Request $request)
{
    try {
        $user = auth()->guard('api')->user();

        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses'
            ], 403);
        }

        // Get date range from request
        $startDateInput = $request->get('start_date');
        $endDateInput = $request->get('end_date');

        // Default ke bulan ini
        if (!$startDateInput || !$endDateInput) {
            $endDateInput = now()->toDateString();
            $startDateInput = now()->subMonth()->toDateString();
        }

        try {
            $startDate = Carbon::parse($startDateInput)->startOfDay();
            $endDate = Carbon::parse($endDateInput)->endOfDay();

            if ($startDate > $endDate) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tanggal awal harus lebih kecil dari tanggal akhir'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Format tanggal tidak valid'
            ], 400);
        }

        // Get all orders with items and menu
        $orders = Orders::with(['items.menu', 'user'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'asc')
            ->get();

        // Group by date
        $ordersByDate = [];
        $cumulativeTotal = 0;

        $orders->groupBy(function($order) {
            return $order->created_at->format('Y-m-d');
        })->each(function($dayOrders, $date) use (&$cumulativeTotal, &$ordersByDate) {
            $dailyTotal = 0;
            
            $mappedOrders = $dayOrders->map(function($order) use (&$dailyTotal) {
                $orderTotal = (int) $order->total_price;
                $dailyTotal += $orderTotal;
                
                return [
                    'order_id' => (int) $order->id,
                    'order_number' => $order->order_code ?? 'ORD-' . $order->id,
                    'customer' => $order->user->name ?? 'Guest',
                    'status' => $order->order_status ?? 'pending',
                    'items' => $order->items->map(function($item) {
                        $price = (int) ($item->price ?? 0);
                        $quantity = (int) ($item->quantity ?? 1);
                        $subtotal = $price * $quantity;
                        
                        return [
                            'name' => $item->menu?->name ?? 'Unknown Product',
                            'quantity' => $quantity,
                            'price' => $price,
                            'subtotal' => $subtotal
                        ];
                    })->toArray(),
                    'total' => $orderTotal,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s')
                ];
            })->toArray();
            
            $cumulativeTotal += $dailyTotal;
            
            $ordersByDate[] = [
                'date' => $date,
                'total_orders' => count($mappedOrders),
                'daily_total' => (int) $dailyTotal,
                'cumulative_total' => (int) $cumulativeTotal,
                'orders' => $mappedOrders
            ];
        });

        // Overall summary
        $totalOrders = $orders->count();
        $overallTotal = (int) $orders->sum('total_price');

        // Debug log
        Log::info('Orders Detail Response', [
            'total_orders' => $totalOrders,
            'total_revenue' => $overallTotal,
            'orders_by_date_count' => count($ordersByDate),
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d')
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Orders detail retrieved successfully',
            'data' => [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_orders' => $totalOrders,
                    'total_revenue' => $overallTotal,
                    'average_order_value' => $totalOrders > 0 ? (int) ($overallTotal / $totalOrders) : 0,
                ],
                'orders_by_date' => $ordersByDate
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('getOrdersDetail error: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil detail orders: ' . $e->getMessage(),
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Export Reports to CSV/PDF (placeholder)
     */
    public function exportReports(Request $request) 
    {
        try {
            $user = auth()->guard('api')->user();
            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }  
            
            $format = $request->get('format', 'csv');
            $type = $request->get('type', 'all');

            return response()->json([
                'success' => true,
                'message' => 'Export dimulai',
                'data' => [
                    'format' => $format,
                    'type' => $type,
                ]
            ], 200);   
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal export laporan',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all regular users
     */
    public function listUsers(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', null);
            $status = $request->get('status', null);

            $query = User::query();

            $user = auth()->guard('api')->user();
            if ($user->role === 'admin') {
                $query->where('role', 'user');
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($status !== null) {
                $query->where('is_active', $status);
            }

            $users = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show user detail
     */
    public function showUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            $authUser = auth()->guard('api')->user();
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            return response()->json([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            $authUser = auth()->guard('api')->user();
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa mengubah user ini'
                ], 403);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|required|string|max:255',
                'email' => 'sometimes|required|email|unique:users,email,' . $id,
                'phone' => 'nullable|string|max:20',
                'divisi' => 'nullable|string|max:255',
                'unit_kerja' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            if ($request->has('name')) {
                $user->name = $request->name;
            }
            if ($request->has('email')) {
                $user->email = $request->email;
            }
            if ($request->has('phone')) {
                $user->phone = $request->phone;
            }
            if ($request->has('divisi')) {
                $user->divisi = $request->divisi;
            }
            if ($request->has('unit_kerja')) {
                $user->unit_kerja = $request->unit_kerja;
            }

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user
     */
    public function deleteUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            $authUser = auth()->guard('api')->user();
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa menghapus user ini'
                ], 403);
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate user
     */
    public function deactivateUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            $authUser = auth()->guard('api')->user();
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa menonaktifkan user ini'
                ], 403);
            }

            $user->update(['is_active' => 0]);

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate user
     */
    public function activateUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            $authUser = auth()->guard('api')->user();
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa mengaktifkan user ini'
                ], 403);
            }

            $user->update(['is_active' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}