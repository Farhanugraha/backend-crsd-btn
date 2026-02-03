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
     * Get user's data access from metadata
     */
    private function getUserDataAccess()
    {
        $user = auth()->guard('api')->user();
        
        if (!$user) {
            return [];
        }
        
        return $user->getEffectiveDataAccess();
    }
    
    /**
     * Apply CRSD filter to query based on user's data access
     */
    private function applyCRSDFilter($query)
    {
        $user = auth()->guard('api')->user();
        
        if (!$user) {
            return $query;
        }
        
        // Superadmin can see everything
        if ($user->role === 'superadmin') {
            return $query;
        }
        
        // Admin users - filter based on data access
        if ($user->role === 'admin') {
            $dataAccess = $this->getUserDataAccess();
            
            if (empty($dataAccess)) {
                // No data access - return empty results
                return $query->whereRaw('1 = 0');
            }
            
            // Filter based on available data access
            $crsdAccess = array_filter($dataAccess, function($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });
            
            if (count($crsdAccess) === 2) {
                // Admin with both access can see both CRSD 1 and CRSD 2
                return $query->whereHas('user', function($q) {
                    $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                });
            } elseif (count($crsdAccess) === 1) {
                $crsdType = reset($crsdAccess);
                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                
                return $query->whereHas('user', function($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
            }
        }
        
        return $query;
    }
    
    /**
     * ==================== DASHBOARD METHODS ====================
     */
    
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
            
            $dataAccess = $this->getUserDataAccess();
            
            // Check if admin needs to select module
            if ($user->role === 'admin') {
                $crsdAccess = array_filter($dataAccess, function($item) {
                    return in_array($item, ['crsd1', 'crsd2']);
                });
                
                if (count($crsdAccess) > 1) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Pilih module CRSD',
                        'requires_module_selection' => true,
                        'available_modules' => $dataAccess,
                    ], 200);
                }
            }

            // For admin with single access or superadmin
            $ordersQuery = Orders::query();
            $this->applyCRSDFilter($ordersQuery);
            
            $totalOrders = $ordersQuery->count();
            $pendingOrders = $ordersQuery->clone()->where('status', 'pending')->count();
            $processingOrders = $ordersQuery->clone()->where('order_status', 'processing')->count();
            $completedOrders = $ordersQuery->clone()->where('order_status', 'completed')->count();
            $canceledOrders = $ordersQuery->clone()->where('order_status', 'canceled')->count();
            
            $revenueQuery = Orders::where('status', 'paid');
            $this->applyCRSDFilter($revenueQuery);
            $totalRevenue = $revenueQuery->sum('total_price');
            
            $pendingPayments = Payments::where('payment_status', 'pending')
                ->whereHas('order', function($q) {
                    $this->applyCRSDFilter($q);
                })->count();
            
            $usersQuery = User::where('role', 'user');
            if ($user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    $usersQuery->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                } elseif (in_array('crsd1', $dataAccess)) {
                    $usersQuery->where('divisi', 'CRSD 1');
                } elseif (in_array('crsd2', $dataAccess)) {
                    $usersQuery->where('divisi', 'CRSD 2');
                } else {
                    $usersQuery->whereRaw('1 = 0');
                }
            }
            $totalUsers = $usersQuery->count();
            
            $totalAdmins = User::whereIn('role', ['admin', 'superadmin'])->count();

            return response()->json([
                'success' => true,
                'message' => 'Admin dashboard loaded successfully',
                'data_access' => $dataAccess,
                'user_role' => $user->role,
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
     * CRSD 1 Dashboard
     */
    public function dashboardCRSD1()
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            // Check if admin has access to CRSD 1
            if ($user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (!in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke CRSD 1'
                    ], 403);
                }
            }

            $ordersQuery = Orders::whereHas('user', function($q) {
                $q->where('divisi', 'CRSD 1');
            });
            
            $totalOrders = $ordersQuery->count();
            $pendingOrders = $ordersQuery->clone()->where('status', 'pending')->count();
            $processingOrders = $ordersQuery->clone()->where('order_status', 'processing')->count();
            $completedOrders = $ordersQuery->clone()->where('order_status', 'completed')->count();
            $canceledOrders = $ordersQuery->clone()->where('order_status', 'canceled')->count();
            
            $revenueQuery = Orders::where('status', 'paid')
                ->whereHas('user', function($q) {
                    $q->where('divisi', 'CRSD 1');
                });
            $totalRevenue = $revenueQuery->sum('total_price');
            
            $pendingPayments = Payments::where('payment_status', 'pending')
                ->whereHas('order.user', function($q) {
                    $q->where('divisi', 'CRSD 1');
                })->count();
            
            $totalUsers = User::where('role', 'user')
                ->where('divisi', 'CRSD 1')
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'CRSD 1 Dashboard loaded successfully',
                'crsd_type' => 'crsd1',
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
                    'users' => $totalUsers,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('CRSD 1 Dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard CRSD 1',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * CRSD 2 Dashboard
     */
    public function dashboardCRSD2()
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            // Check if admin has access to CRSD 2
            if ($user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (!in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke CRSD 2'
                    ], 403);
                }
            }

            $ordersQuery = Orders::whereHas('user', function($q) {
                $q->where('divisi', 'CRSD 2');
            });
            
            $totalOrders = $ordersQuery->count();
            $pendingOrders = $ordersQuery->clone()->where('status', 'pending')->count();
            $processingOrders = $ordersQuery->clone()->where('order_status', 'processing')->count();
            $completedOrders = $ordersQuery->clone()->where('order_status', 'completed')->count();
            $canceledOrders = $ordersQuery->clone()->where('order_status', 'canceled')->count();
            
            $revenueQuery = Orders::where('status', 'paid')
                ->whereHas('user', function($q) {
                    $q->where('divisi', 'CRSD 2');
                });
            $totalRevenue = $revenueQuery->sum('total_price');
            
            $pendingPayments = Payments::where('payment_status', 'pending')
                ->whereHas('order.user', function($q) {
                    $q->where('divisi', 'CRSD 2');
                })->count();
            
            $totalUsers = User::where('role', 'user')
                ->where('divisi', 'CRSD 2')
                ->count();

            return response()->json([
                'success' => true,
                'message' => 'CRSD 2 Dashboard loaded successfully',
                'crsd_type' => 'crsd2',
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
                    'users' => $totalUsers,
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('CRSD 2 Dashboard error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard CRSD 2',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * ==================== ORDER METHODS ====================
     */
    
    /**
     * Get all orders with CRSD filtering
     */
    public function getAllOrders(Request $request)
    {
        try {
            Log::info('=== getAllOrders Method Called ===');
            
            $user = auth()->guard('api')->user();

            if (!$user) {
                Log::error('No authenticated user');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            Log::info('User: ' . $user->id . ' - ' . $user->email . ' - Role: ' . $user->role . ' - Divisi: ' . $user->divisi);

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            // Test basic query first
            $testQuery = Orders::with('user')->limit(5)->get();
            Log::info('Test query count: ' . $testQuery->count());
            
            foreach ($testQuery as $order) {
                Log::info('Order ' . $order->id . ': User Divisi = ' . ($order->user->divisi ?? 'NULL'));
            }

            // HAPUS 'areas' dari with() untuk menghindari error
            $query = Orders::with(['user', 'items.menu', 'items.menu.restaurant']) // Hapus .area dan areas
                ->orderBy('created_at', 'desc');
            
            // Apply CRSD filter
            $this->applyCRSDFilter($query);
            
            Log::info('After CRSD filter, query count: ' . $query->count());
            
            // Apply filters from request
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('order_status', $request->status);
                Log::info('Applied status filter: ' . $request->status);
            }
            
            // Filter berdasarkan area (jika diperlukan nanti, tapi table order_areas tidak ada)
            // if ($request->has('area_id') && $request->area_id !== 'all') {
            //     $query->whereHas('areas', function($q) use ($request) {
            //         $q->where('id', $request->area_id);
            //     });
            //     Log::info('Applied area filter: ' . $request->area_id);
            // }
            
            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
                Log::info('Applied date filter: ' . $request->date);
            }
            
            if ($request->has('crsd_type') && $request->crsd_type !== 'all') {
                $crsdType = $request->crsd_type;
                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                $query->whereHas('user', function($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
                Log::info('Applied CRSD filter: ' . $crsdType);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      })
                      ->orWhereHas('items.menu.restaurant', function($q3) use ($search) {
                          $q3->where('name', 'like', "%{$search}%");
                      });
                });
                Log::info('Applied search filter: ' . $search);
            }

            $orders = $query->get();
            
            Log::info('Final orders count: ' . $orders->count());
            
            // Add crsd_type to each order
            $orders->each(function($order) {
                if ($order->user && $order->user->divisi === 'CRSD 2') {
                    $order->crsd_type = 'crsd2';
                } else {
                    $order->crsd_type = 'crsd1';
                }
            });
            
            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'debug' => [
                    'user_id' => $user->id,
                    'user_role' => $user->role,
                    'user_divisi' => $user->divisi,
                    'total_orders' => $orders->count(),
                ],
                'data' => $orders
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error in getAllOrders: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pesanan',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * Get orders for specific CRSD
     */
    public function getCRSDOrders(Request $request, $crsdType)
    {
        try {
            Log::info('=== getCRSDOrders Method Called ===');
            Log::info('CRSD Type: ' . $crsdType);
            
            $user = auth()->guard('api')->user();

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
            
            // Check if admin has access to this CRSD
            if ($user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (!in_array($crsdType, $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Anda tidak memiliki akses ke CRSD " . strtoupper($crsdType)
                    ], 403);
                }
            }
            
            $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
            
            Log::info('Filtering for divisi: ' . $divisiName);
            
            $query = Orders::with(['user', 'items.menu', 'items.menu.restaurant'])
                ->whereHas('user', function($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                })
                ->orderBy('created_at', 'desc');
            
            // Apply filters from request
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('order_status', $request->status);
            }
            
            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      })
                      ->orWhereHas('items.menu.restaurant', function($q3) use ($search) {
                          $q3->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->get();
            
            Log::info('Orders found for ' . $divisiName . ': ' . $orders->count());
            
            // Add crsd_type to each order
            $orders->each(function($order) use ($crsdType) {
                $order->crsd_type = $crsdType;
            });
            
            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully for ' . strtoupper($crsdType),
                'crsd_type' => $crsdType,
                'data' => $orders
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error in getCRSDOrders: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pesanan',
                'error' => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
    
    /**
     * ==================== STATISTICS & REPORTS ====================
     */
    
    /**
     * Get Statistics with CRSD filtering
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

            // Default to today if not provided
            if (!$startDateInput || !$endDateInput) {
                $endDateInput = now()->toDateString();
                $startDateInput = now()->toDateString();
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

            // Base query with CRSD filter
            $baseQuery = Orders::whereBetween('created_at', [$startDate, $endDate]);
            $this->applyCRSDFilter($baseQuery);
            
            $totalOrders = $baseQuery->count();
            
            // Total Revenue (paid orders) with CRSD filter
            $revenueQuery = Orders::where('status', 'paid')
                ->whereBetween('created_at', [$startDate, $endDate]);
            $this->applyCRSDFilter($revenueQuery);
            $totalRevenue = (int) $revenueQuery->sum('total_price');
            
            // Orders by order_status with CRSD filter
            $completedOrders = $baseQuery->clone()->where('order_status', 'completed')->count();
            $processingOrders = $baseQuery->clone()->where('order_status', 'processing')->count();
            $canceledOrders = $baseQuery->clone()->where('order_status', 'canceled')->count();
            
            // Average order value
            $averageOrderValue = $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0;
            
            // Today's statistics with CRSD filter
            $todayStart = now()->startOfDay();
            $todayEnd = now()->endOfDay();
            
            $todayOrdersQuery = Orders::whereBetween('created_at', [$todayStart, $todayEnd]);
            $this->applyCRSDFilter($todayOrdersQuery);
            $todayOrders = $todayOrdersQuery->count();
            
            $todayRevenueQuery = Orders::where('status', 'paid')
                ->whereBetween('created_at', [$todayStart, $todayEnd]);
            $this->applyCRSDFilter($todayRevenueQuery);
            $todayRevenue = (int) $todayRevenueQuery->sum('total_price');
            
            // Calculate growth (previous period vs current period)
            $periodDays = $endDate->diffInDays($startDate) + 1;
            $previousPeriodStart = (clone $startDate)->subDays($periodDays)->startOfDay();
            $previousPeriodEnd = (clone $startDate)->subDay()->endOfDay();
            
            $previousRevenueQuery = Orders::where('status', 'paid')
                ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]);
            $this->applyCRSDFilter($previousRevenueQuery);
            $previousPeriodRevenue = (int) $previousRevenueQuery->sum('total_price');
                
            $previousOrdersQuery = Orders::whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]);
            $this->applyCRSDFilter($previousOrdersQuery);
            $previousPeriodOrders = $previousOrdersQuery->count();
            
            $revenueGrowth = $previousPeriodRevenue > 0 
                ? (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue * 100)
                : ($totalRevenue > 0 ? 100 : 0);
            
            $orderGrowth = $previousPeriodOrders > 0
                ? (($totalOrders - $previousPeriodOrders) / $previousPeriodOrders * 100)
                : ($totalOrders > 0 ? 100 : 0);

            // Chart data - Orders and Revenue per day with CRSD filter
            $chartData = [];
            $currentDate = clone $startDate;
            
            while ($currentDate <= $endDate) {
                $dayStart = (clone $currentDate)->startOfDay();
                $dayEnd = (clone $currentDate)->endOfDay();
                
                $dayOrdersQuery = Orders::whereBetween('created_at', [$dayStart, $dayEnd]);
                $this->applyCRSDFilter($dayOrdersQuery);
                $dayOrders = $dayOrdersQuery->count();
                    
                $dayRevenueQuery = Orders::where('status', 'paid')
                    ->whereBetween('created_at', [$dayStart, $dayEnd]);
                $this->applyCRSDFilter($dayRevenueQuery);
                $dayRevenue = (int) $dayRevenueQuery->sum('total_price');
                
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
     * Get Reports with CRSD filtering
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
            $this->applyCRSDFilter($query);

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

            // Orders by order_status with CRSD filter
            $ordersByStatus = Orders::selectRaw('order_status as status, COUNT(*) as total')
                ->when($startDateInput && $endDateInput, function($q) use ($startDateInput, $endDateInput) {
                    try {
                        $startDate = Carbon::parse($startDateInput)->startOfDay();
                        $endDate = Carbon::parse($endDateInput)->endOfDay();
                        $q->whereBetween('created_at', [$startDate, $endDate]);
                    } catch (\Exception $e) {
                        // Ignore invalid dates
                    }
                })
                ->tap(function($q) {
                    $this->applyCRSDFilter($q);
                })
                ->groupBy('order_status')
                ->get()
                ->map(function ($item) {
                    return [
                        'status' => $item->status ?? 'unknown',
                        'total' => $item->total,
                    ];
                })
                ->toArray();

            // Payment summary with CRSD filter
            $paymentQuery = Payments::query();
            if ($startDateInput && $endDateInput) {
                try {
                    $startDate = Carbon::parse($startDateInput)->startOfDay();
                    $endDate = Carbon::parse($endDateInput)->endOfDay();
                    $paymentQuery->whereBetween('created_at', [$startDate, $endDate]);
                } catch (\Exception $e) {
                    // Ignore invalid dates
                }
            }
            
            $paymentQuery->whereHas('order', function($q) {
                $this->applyCRSDFilter($q);
            });

            $paymentSummary = $paymentQuery->selectRaw('payment_status as status, COUNT(*) as total, SUM(amount) as total_amount')
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

            // User statistics with CRSD filter
            $userQuery = User::where('role', 'user');
            $user = auth()->guard('api')->user();
            if ($user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    $userQuery->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                } elseif (in_array('crsd1', $dataAccess)) {
                    $userQuery->where('divisi', 'CRSD 1');
                } elseif (in_array('crsd2', $dataAccess)) {
                    $userQuery->where('divisi', 'CRSD 2');
                } else {
                    $userQuery->whereRaw('1 = 0');
                }
            }
            
            $userStatistics = [
                'total_users' => $userQuery->count(),
                'active_users' => $userQuery->clone()->where('is_active', 1)->count(),
            ];

            // Top users with order count and CRSD filter
            $topUsers = User::withCount(['orders' => function($q) {
                $this->applyCRSDFilter($q);
            }])
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
     * Get Orders Detail with CRSD filtering
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

            // Get all orders with items and menu, apply CRSD filter
            $query = Orders::with(['items.menu', 'user'])
                ->whereBetween('created_at', [$startDate, $endDate]);
            
            $this->applyCRSDFilter($query);
            
            $orders = $query->orderBy('created_at', 'asc')->get();

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
     * Export Reports to Excel
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

            // Get date range
            $startDate = $request->get('start_date', now()->subMonth()->toDateString());
            $endDate = $request->get('end_date', now()->toDateString());

            try {
                $start = Carbon::parse($startDate)->startOfDay();
                $end = Carbon::parse($endDate)->endOfDay();
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Format tanggal tidak valid'
                ], 400);
            }

            // Get orders with CRSD filter
            $query = Orders::with(['user', 'items.menu'])
                ->whereBetween('created_at', [$start, $end]);
            
            $this->applyCRSDFilter($query);
            
            $orders = $query->orderBy('created_at', 'asc')->get();

            // Format data for export
            $exportData = $orders->map(function($order) {
                return [
                    'Order Code' => $order->order_code,
                    'Customer Name' => $order->user->name,
                    'Customer Email' => $order->user->email,
                    'Customer Phone' => $order->user->phone,
                    'Customer Divisi' => $order->user->divisi,
                    'Order Status' => $order->order_status,
                    'Payment Status' => $order->status,
                    'Total Amount' => (int) $order->total_price,
                    'Order Date' => $order->created_at->format('Y-m-d H:i:s'),
                    'Items Count' => $order->items->count(),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Export data ready',
                'data' => $exportData,
                'filename' => 'orders_export_' . date('Ymd_His') . '.xlsx'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Export reports error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyiapkan data export',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ==================== USER MANAGEMENT ====================
     */
    
    /**
     * List all users with CRSD filtering for admin
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
                // Admin hanya bisa lihat user biasa
                $query->where('role', 'user');
                
                // Apply CRSD filter based on data access
                $dataAccess = $this->getUserDataAccess();
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    // Can see users from both divisi
                    $query->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                } elseif (in_array('crsd1', $dataAccess)) {
                    $query->where('divisi', 'CRSD 1');
                } elseif (in_array('crsd2', $dataAccess)) {
                    $query->where('divisi', 'CRSD 2');
                } else {
                    $query->whereRaw('1 = 0'); // No access
                }
            } elseif ($user->role === 'superadmin') {
                // Superadmin can see all users
                // No filter needed
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('divisi', 'like', "%{$search}%");
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
            Log::error('Error in listUsers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Show user detail with access control
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
            
            // Admin can only see regular users
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            // Check CRSD access for admin
            if ($authUser->role === 'admin' && $user->role === 'user') {
                $dataAccess = $this->getUserDataAccess();
                $userDivisi = $user->divisi;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error in showUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update user with access control
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
            
            // Admin can only update regular users
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa mengubah user ini'
                ], 403);
            }
            
            // Check CRSD access for admin
            if ($authUser->role === 'admin' && $user->role === 'user') {
                $dataAccess = $this->getUserDataAccess();
                $userDivisi = $user->divisi;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
                
                // Admin cannot change divisi to something they don't have access to
                if ($request->has('divisi')) {
                    $newDivisi = $request->divisi;
                    if ($newDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Anda hanya dapat mengubah user divisi CRSD 1'
                        ], 403);
                    }
                    if ($newDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                        return response()->json([
                            'success' => false,
                            'message' => 'Anda hanya dapat mengubah user divisi CRSD 2'
                        ], 403);
                    }
                }
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
            Log::error('Error in updateUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete user with access control
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
            
            // Admin can only delete regular users
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa menghapus user ini'
                ], 403);
            }
            
            // Check CRSD access for admin
            if ($authUser->role === 'admin' && $user->role === 'user') {
                $dataAccess = $this->getUserDataAccess();
                $userDivisi = $user->divisi;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
            }

            $user->delete();

            return response()->json([
                'success' => true,
                'message' => 'User deleted successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error in deleteUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate user with access control
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
            
            // Admin can only deactivate regular users
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa menonaktifkan user ini'
                ], 403);
            }
            
            // Check CRSD access for admin
            if ($authUser->role === 'admin' && $user->role === 'user') {
                $dataAccess = $this->getUserDataAccess();
                $userDivisi = $user->divisi;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
            }

            $user->update(['is_active' => 0]);

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error in deactivateUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate user with access control
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
            
            // Admin can only activate regular users
            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak bisa mengaktifkan user ini'
                ], 403);
            }
            
            // Check CRSD access for admin
            if ($authUser->role === 'admin' && $user->role === 'user') {
                $dataAccess = $this->getUserDataAccess();
                $userDivisi = $user->divisi;
                
                if ($userDivisi === 'CRSD 1' && !in_array('crsd1', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
                
                if ($userDivisi === 'CRSD 2' && !in_array('crsd2', $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Anda tidak memiliki akses ke user ini'
                    ], 403);
                }
            }

            $user->update(['is_active' => 1]);

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            Log::error('Error in activateUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ==================== CRSD USER METHODS ====================
     */
    
    /**
     * Get CRSD Users
     */
    public function getCRSDUsers(Request $request, $crsdType)
    {
        try {
            $user = auth()->guard('api')->user();

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
            
            // Check if admin has access to this CRSD
            if ($user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (!in_array($crsdType, $dataAccess)) {
                    return response()->json([
                        'success' => false,
                        'message' => "Anda tidak memiliki akses ke CRSD " . strtoupper($crsdType)
                    ], 403);
                }
            }
            
            $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
            
            $query = User::where('role', 'user')
                ->where('divisi', $divisiName);
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }
            
            $users = $query->paginate($request->get('per_page', 15));
            
            return response()->json([
                'success' => true,
                'message' => "Users retrieved successfully for " . strtoupper($crsdType),
                'crsd_type' => $crsdType,
                'data' => $users
            ], 200);
        } catch (\Exception $e) {
            Log::error("Get CRSD users error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data users',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Select Module for admin with multiple access
     */
    public function selectModule()
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            $dataAccess = $this->getUserDataAccess();
            $crsdTypes = array_filter($dataAccess, function($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });
            
            if (count($crsdTypes) === 1) {
                $module = reset($crsdTypes);
                return response()->json([
                    'success' => true,
                    'message' => 'Redirecting to ' . strtoupper($module) . ' dashboard',
                    'redirect_to' => "/admin/{$module}/dashboard",
                    'requires_selection' => false
                ], 200);
            }
            
            if (count($crsdTypes) > 1) {
                return response()->json([
                    'success' => true,
                    'message' => 'Silakan pilih module CRSD',
                    'requires_selection' => true,
                    'available_modules' => array_values($crsdTypes)
                ], 200);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada module CRSD yang tersedia'
            ], 400);
        } catch (\Exception $e) {
            Log::error('Select module error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat pilihan module',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}