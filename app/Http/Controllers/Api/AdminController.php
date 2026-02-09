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
     private function getUserDataAccess()
    {
        $user = auth()->guard('api')->user();
        
        if (!$user) {
            return [];
        }
        
        return $user->getEffectiveDataAccess();
    }
    
     private function applyCRSDFilter($query)
    {
        $user = auth()->guard('api')->user();
        
        if (!$user) {
            return $query;
        }
        
        if ($user->role === 'superadmin') {
            return $query;
        }
        
        if ($user->role === 'admin') {
            $dataAccess = $this->getUserDataAccess();
            
            if (empty($dataAccess)) {
                return $query->whereRaw('1 = 0');
            }
            
            $crsdAccess = array_filter($dataAccess, function($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });
            
            if (count($crsdAccess) === 2) {
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
    
         public function dashboard(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi'
                ], 401);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            // Get user's data access
            $dataAccess = $this->getUserDataAccess();
            
            // Start orders query with CRSD filter
            $ordersQuery = Orders::query();
            $this->applyCRSDFilter($ordersQuery);
            
            // Count orders by status - GUNAKAN FIELD YANG KONSISTEN
            $totalOrders = $ordersQuery->count();
            $pendingOrders = $ordersQuery->clone()->where('status', 'pending')->count();
            
            // Untuk status processing, completed, canceled - coba kedua field
            $processingOrders = $ordersQuery->clone()
                ->where(function($q) {
                    $q->where('order_status', 'processing')
                      ->orWhere('status', 'processing');
                })->count();
                
            $completedOrders = $ordersQuery->clone()
                ->where(function($q) {
                    $q->where('order_status', 'completed')
                      ->orWhere('status', 'completed');
                })->count();
                
            $canceledOrders = $ordersQuery->clone()
                ->where(function($q) {
                    $q->where('order_status', 'canceled')
                      ->orWhere('status', 'canceled');
                })->count();
            
            // Untuk revenue, gunakan orders yang sudah dibayar (status 'paid' atau 'completed')
            $revenueQuery = Orders::query();
            $this->applyCRSDFilter($revenueQuery);
            
            // Cari revenue dari orders yang sudah dibayar
            $totalRevenue = $revenueQuery->where(function($q) {
                $q->where('status', 'paid')
                  ->orWhere('status', 'completed');
            })->sum('total_price');
            
            // Count pending payments
            $pendingPayments = Payments::where('payment_status', 'pending')
                ->whereHas('order', function($q) use ($dataAccess) {
                    // Filter berdasarkan data_access user
                    if (!empty($dataAccess)) {
                        if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                            // Filter by user's divisi instead of crsd_type field
                            $q->whereHas('user', function($q2) {
                                $q2->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                            });
                        } elseif (in_array('crsd1', $dataAccess)) {
                            $q->whereHas('user', function($q2) {
                                $q2->where('divisi', 'CRSD 1');
                            });
                        } elseif (in_array('crsd2', $dataAccess)) {
                            $q->whereHas('user', function($q2) {
                                $q2->where('divisi', 'CRSD 2');
                            });
                        } else {
                            $q->whereRaw('1 = 0');
                        }
                    }
                })->count();
            
            // Count users based on user's data_access
            $usersQuery = User::where('role', 'user');
            
            if (!empty($dataAccess)) {
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    // Jika user bisa akses kedua divisi, tampilkan semua user
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
            
            // Count all admins (tanpa filter divisi)
            $totalAdmins = User::whereIn('role', ['admin', 'superadmin'])->count();

            // Tentukan apakah user memiliki akses ke multiple CRSD
            $hasMultipleAccess = count($dataAccess) > 1;
            $requiresModuleSelection = $hasMultipleAccess && !$request->has('crsd_type');

            return response()->json([
                'success' => true,
                'message' => 'Admin dashboard loaded successfully',
                'data_access' => $dataAccess,
                'user_role' => $user->role,
                'requires_selection' => $requiresModuleSelection,
                'requires_module_selection' => $requiresModuleSelection,
                'available_modules' => $dataAccess,
                'shows_all_crsd' => $hasMultipleAccess,
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
            Log::error('Dashboard error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }


        public function dashboardCRSD1()
    {
        try {
            $user = auth()->guard('api')->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak terautentikasi'
                ], 401);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
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
            
            // Gunakan kedua field untuk kompatibilitas
            $processingOrders = $ordersQuery->clone()
                ->where(function($q) {
                    $q->where('order_status', 'processing')
                      ->orWhere('status', 'processing');
                })->count();
                
            $completedOrders = $ordersQuery->clone()
                ->where(function($q) {
                    $q->where('order_status', 'completed')
                      ->orWhere('status', 'completed');
                })->count();
                
            $canceledOrders = $ordersQuery->clone()
                ->where(function($q) {
                    $q->where('order_status', 'canceled')
                      ->orWhere('status', 'canceled');
                })->count();
            
            $revenueQuery = Orders::where(function($q) {
                    $q->where('status', 'paid')
                      ->orWhere('status', 'completed');
                })
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
            
            $totalAdmins = User::whereIn('role', ['admin', 'superadmin'])->count();

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
                    'users' => [
                        'total_users' => $totalUsers,
                        'total_admins' => $totalAdmins,
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('CRSD 1 Dashboard error: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard CRSD 1',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    
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
                    'users' => [
                        'total_users' => $totalUsers,
                        'total_admins' => 0,
                    ]
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

        $startDateInput = $request->get('start_date');
        $endDateInput = $request->get('end_date');

        if (!$startDateInput || !$endDateInput) {
            $endDateInput = now()->toDateString();
            $startDateInput = now()->toDateString();
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
            
            // Validasi maksimum range tanggal (maksimal 365 hari)
            if ($endDate->diffInDays($startDate) > 365) {
                return response()->json([
                    'success' => false,
                    'message' => 'Rentang tanggal maksimum adalah 365 hari'
                ], 400);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Format tanggal tidak valid'
            ], 400);
        }

        // OPTIMISASI: Gunakan query aggregate untuk semua statistik sekaligus
        $mainStats = Orders::selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as total_revenue,
                SUM(CASE WHEN order_status = "completed" THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN order_status = "processing" THEN 1 ELSE 0 END) as processing_orders,
                SUM(CASE WHEN order_status = "canceled" THEN 1 ELSE 0 END) as canceled_orders,
                SUM(CASE WHEN status = "paid" THEN 1 ELSE 0 END) as paid_orders
            ')
            ->whereBetween('created_at', [$startDate, $endDate]);
        
        $this->applyCRSDFilter($mainStats);
        $mainStatsResult = $mainStats->first();
        
        $totalOrders = (int) $mainStatsResult->total_orders;
        $totalRevenue = (int) $mainStatsResult->total_revenue;
        $completedOrders = (int) $mainStatsResult->completed_orders;
        $processingOrders = (int) $mainStatsResult->processing_orders;
        $canceledOrders = (int) $mainStatsResult->canceled_orders;
        $paidOrders = (int) $mainStatsResult->paid_orders;
        
        // PERBAIKAN: Average order value menggunakan totalOrders bukan paidOrders (sesuai kode lama)
        $averageOrderValue = $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0;
        
        // OPTIMISASI: Today stats dengan query yang lebih efisien
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();
        
        $todayStats = Orders::selectRaw('
                COUNT(*) as orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as revenue
            ')
            ->whereBetween('created_at', [$todayStart, $todayEnd]);
        $this->applyCRSDFilter($todayStats);
        $todayResult = $todayStats->first();
        
        $todayOrders = (int) $todayResult->orders;
        $todayRevenue = (int) $todayResult->revenue;
        
        $periodDays = $endDate->diffInDays($startDate) + 1;
        
        // PERBAIKAN: Periode sebelumnya dengan durasi yang sama
        $previousPeriodStart = (clone $startDate)->subDays($periodDays)->startOfDay();
        $previousPeriodEnd = (clone $startDate)->subDay()->endOfDay();
        
        // OPTIMISASI: Query periode sebelumnya untuk orders dan revenue
        $previousStats = Orders::selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as total_revenue
            ')
            ->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]);
        $this->applyCRSDFilter($previousStats);
        $previousResult = $previousStats->first();
        
        $previousPeriodRevenue = (int) $previousResult->total_revenue;
        $previousPeriodOrders = (int) $previousResult->total_orders;
        
        // PERBAIKAN: Growth calculation sesuai logika lama
        $revenueGrowth = 0;
        if ($previousPeriodRevenue > 0) {
            $revenueGrowth = (($totalRevenue - $previousPeriodRevenue) / $previousPeriodRevenue) * 100;
        } elseif ($totalRevenue > 0) {
            $revenueGrowth = 100;
        }
        
        $orderGrowth = 0;
        if ($previousPeriodOrders > 0) {
            $orderGrowth = (($totalOrders - $previousPeriodOrders) / $previousPeriodOrders) * 100;
        } elseif ($totalOrders > 0) {
            $orderGrowth = 100;
        }

        // OPTIMISASI: Chart data dengan single query menggunakan GROUP BY
        $chartQuery = Orders::selectRaw('
                DATE(created_at) as date,
                COUNT(*) as orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as revenue
            ')
            ->whereBetween('created_at', [$startDate, $endDate]);
        $this->applyCRSDFilter($chartQuery);
        
        $chartResults = $chartQuery->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date')
            ->get();
        
        // Format chart data - sesuai format kode lama
        $chartData = [];
        $chartResultsMap = $chartResults->keyBy('date');
        
        $currentDate = clone $startDate;
        while ($currentDate <= $endDate) {
            $dateString = $currentDate->format('Y-m-d');
            $dayData = $chartResultsMap->get($dateString);
            
            $chartData[] = [
                'date' => $currentDate->format('d M'),
                'orders' => $dayData ? (int) $dayData->orders : 0,
                'revenue' => $dayData ? (int) $dayData->revenue : 0
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
                'periodInfo' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'days' => $periodDays,
                    'previous_period' => [
                        'start_date' => $previousPeriodStart->format('Y-m-d'),
                        'end_date' => $previousPeriodEnd->format('Y-m-d')
                    ]
                ]
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

        $startDateInput = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDateInput = $request->get('end_date', now()->toDateString());

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

        // PERBAIKAN: Load restaurant melalui items
        $baseQuery = Orders::with(['user', 'items.menu.restaurant.area'])
            ->whereBetween('created_at', [$startDate, $endDate]);
        
        $this->applyCRSDFilter($baseQuery);
        
        // Filter area berdasarkan items
        if ($request->has('area_id') && $request->area_id !== 'all') {
            $areaId = $request->area_id;
            $baseQuery->whereHas('items.menu.restaurant.area', function($q) use ($areaId) {
                $q->where('id', $areaId);
            });
        }
        
        // Filter restaurant berdasarkan items
        if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
            $restaurantId = $request->restaurant_id;
            $baseQuery->whereHas('items.menu.restaurant', function($q) use ($restaurantId) {
                $q->where('id', $restaurantId);
            });
        }
        
        $totalOrders = $baseQuery->count();
        
        $revenueQuery = Orders::where('status', 'paid')
            ->whereBetween('created_at', [$startDate, $endDate]);
        $this->applyCRSDFilter($revenueQuery);
        
        // Filter revenue query juga
        if ($request->has('area_id') && $request->area_id !== 'all') {
            $areaId = $request->area_id;
            $revenueQuery->whereHas('items.menu.restaurant.area', function($q) use ($areaId) {
                $q->where('id', $areaId);
            });
        }
        
        if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
            $restaurantId = $request->restaurant_id;
            $revenueQuery->whereHas('items.menu.restaurant', function($q) use ($restaurantId) {
                $q->where('id', $restaurantId);
            });
        }
        
        $totalRevenue = (int) $revenueQuery->sum('total_price');
        
        $completedOrders = $baseQuery->clone()->where('order_status', 'completed')->count();
        $processingOrders = $baseQuery->clone()->where('order_status', 'processing')->count();
        $pendingOrders = $baseQuery->clone()->where('status', 'pending')->count();
        $canceledOrders = $baseQuery->clone()->where('order_status', 'canceled')->count();
        
        $ordersQuery = Orders::with(['user', 'items.menu.restaurant.area'])
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc');
        
        $this->applyCRSDFilter($ordersQuery);
        
        // Filter untuk orders query juga
        if ($request->has('area_id') && $request->area_id !== 'all') {
            $areaId = $request->area_id;
            $ordersQuery->whereHas('items.menu.restaurant.area', function($q) use ($areaId) {
                $q->where('id', $areaId);
            });
        }
        
        if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
            $restaurantId = $request->restaurant_id;
            $ordersQuery->whereHas('items.menu.restaurant', function($q) use ($restaurantId) {
                $q->where('id', $restaurantId);
            });
        }
        
        if ($request->has('status') && $request->status !== 'all') {
            $ordersQuery->where('order_status', $request->status);
        }
        
        if ($request->has('payment_status') && $request->payment_status !== 'all') {
            $ordersQuery->where('status', $request->payment_status);
        }
        
        $orders = $ordersQuery->get();
        
        // Proses data untuk handle multiple restaurants/areas
        $orders->each(function($order) {
            // CRSD type
            if ($order->user && $order->user->divisi === 'CRSD 2') {
                $order->crsd_type = 'crsd2';
            } else {
                $order->crsd_type = 'crsd1';
            }
            
            // Ambil restaurant pertama untuk backward compatibility
            $firstRestaurant = null;
            $firstArea = null;
            
            foreach ($order->items as $item) {
                if ($item->menu && $item->menu->restaurant) {
                    if (!$firstRestaurant) {
                        $firstRestaurant = $item->menu->restaurant;
                        if ($firstRestaurant->area) {
                            $firstArea = $firstRestaurant->area;
                        }
                    }
                    break;
                }
            }
            
            // Untuk response
            $order->restaurant = $firstRestaurant;
            $order->area = $firstArea;
            $order->area_name = $firstArea ? $firstArea->name : 'Multiple Areas';
            $order->area_icon = $firstArea ? $firstArea->icon : '📍';
        });

        return response()->json([
            'success' => true,
            'message' => 'Laporan berhasil dimuat',
            'data' => [
                'summary' => [
                    'period' => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date' => $endDate->format('Y-m-d'),
                    ],
                    'total_orders' => $totalOrders,
                    'total_revenue' => $totalRevenue,
                    'completed_orders' => $completedOrders,
                    'processing_orders' => $processingOrders,
                    'pending_orders' => $pendingOrders,
                    'canceled_orders' => $canceledOrders,
                    'average_order_value' => $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0,
                ],
                'orders' => $orders,
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('getReports error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil laporan: ' . $e->getMessage()
        ], 500);
    }
}
    
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

            $startDateInput = $request->get('start_date', now()->subMonth()->toDateString());
            $endDateInput = $request->get('end_date', now()->toDateString());

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

            $query = Orders::with(['user', 'items.menu'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'desc');
            
            $this->applyCRSDFilter($query);
            
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('order_status', $request->status);
            }
            
            if ($request->has('payment_status') && $request->payment_status !== 'all') {
                $query->where('status', $request->payment_status);
            }
            
            if ($request->has('crsd_type') && $request->crsd_type !== 'all') {
                $crsdType = $request->crsd_type;
                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                $query->whereHas('user', function($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
            }
            
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->get();
            
            // Format response sesuai dengan frontend
            $ordersByDate = [];
            $cumulativeTotal = 0;
            $totalRevenue = 0;

            foreach ($orders as $order) {
                $date = $order->created_at->format('Y-m-d');
                
                if (!isset($ordersByDate[$date])) {
                    $ordersByDate[$date] = [
                        'date' => $date,
                        'orders' => [],
                        'daily_total' => 0,
                        'total_orders' => 0
                    ];
                }
                
                $orderTotal = (int) $order->total_price;
                $ordersByDate[$date]['daily_total'] += $orderTotal;
                $ordersByDate[$date]['total_orders']++;
                $totalRevenue += $orderTotal;
                
                $orderData = [
                    'order_id' => $order->id,
                    'order_number' => $order->order_code ?? 'ORD-' . $order->id,
                    'customer' => $order->user->name ?? 'Guest',
                    'status' => $order->order_status ?? 'pending',
                    'payment_status' => $order->status ?? 'pending',
                    'items' => $order->items->map(function($item) {
                        return [
                            'name' => $item->menu?->name ?? 'Unknown Product',
                            'quantity' => (int) $item->quantity,
                            'price' => (int) $item->price,
                            'subtotal' => (int) $item->quantity * (int) $item->price
                        ];
                    })->toArray(),
                    'total' => $orderTotal,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s')
                ];
                
                $ordersByDate[$date]['orders'][] = $orderData;
            }

            // Convert to array and calculate cumulative total
            $formattedOrdersByDate = [];
            ksort($ordersByDate);
            
            foreach ($ordersByDate as $date => $data) {
                $cumulativeTotal += $data['daily_total'];
                $formattedOrdersByDate[] = [
                    'date' => $date,
                    'total_orders' => $data['total_orders'],
                    'daily_total' => $data['daily_total'],
                    'cumulative_total' => $cumulativeTotal,
                    'orders' => $data['orders']
                ];
            }

            $responseData = [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_orders' => $orders->count(),
                    'total_revenue' => $totalRevenue,
                    'average_order_value' => $orders->count() > 0 ? (int) ($totalRevenue / $orders->count()) : 0,
                ],
                'orders_by_date' => $formattedOrdersByDate
            ];

            return response()->json([
                'success' => true,
                'message' => 'Orders detail retrieved successfully',
                'data' => $responseData
            ], 200);

        } catch (\Exception $e) {
            Log::error('getOrdersDetail error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil detail orders: ' . $e->getMessage()
            ], 500);
        }
    }
    
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

            $startDateInput = $request->get('start_date', now()->subMonth()->toDateString());
            $endDateInput = $request->get('end_date', now()->toDateString());
            $exportType = $request->get('export_type', 'excel');

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

            $query = Orders::with(['user', 'items.menu'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'desc');
            
            $this->applyCRSDFilter($query);
            
            $orders = $query->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data untuk diekspor pada periode ini'
                ], 404);
            }

            // Format data untuk export - SESUAI DENGAN FRONTEND
            $exportData = $orders->map(function($order) {
                return [
                    'Order ID' => $order->id,
                    'Order Code' => $order->order_code,
                    'Customer Name' => $order->user->name ?? 'Unknown',
                    'Customer Email' => $order->user->email ?? 'Unknown',
                    'Customer Divisi' => $order->user->divisi ?? 'Unknown',
                    'Order Status' => $order->order_status,
                    'Payment Status' => $order->status,
                    'Total Amount' => (int) $order->total_price,
                    'Order Date' => $order->created_at->format('Y-m-d H:i:s'),
                    'Items Count' => $order->items->count(),
                    'Items' => $order->items->map(function($item) {
                        return $item->menu?->name ?? 'Unknown Product';
                    })->implode(', ')
                ];
            });

            $summaryData = [
                'Period' => $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d'),
                'Total Orders' => $orders->count(),
                'Total Revenue' => (int) $orders->where('status', 'paid')->sum('total_price'),
                'Completed Orders' => $orders->where('order_status', 'completed')->count(),
                'Pending Orders' => $orders->where('order_status', 'pending')->count(),
                'Canceled Orders' => $orders->where('order_status', 'canceled')->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Export data ready',
                'data' => [
                    'summary' => $summaryData,
                    'orders' => $exportType === 'detailed' ? $exportData : $exportData->take(100), // Limit untuk response
                ],
                'export_type' => $exportType,
                'filename' => 'orders_export_' . $startDate->format('Ymd') . '_to_' . $endDate->format('Ymd') . '.json',
                'download_url' => $exportType === 'excel' ? 
                    url("/api/admin/export/excel?start_date={$startDateInput}&end_date={$endDateInput}") : null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Export reports error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyiapkan data export: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function checkModuleSelection()
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
            
            if (count($crsdTypes) > 1) {
                return response()->json([
                    'success' => true,
                    'requires_selection' => true,
                    'requires_module_selection' => true,
                    'available_modules' => array_values($crsdTypes)
                ], 200);
            }
            
            return response()->json([
                'success' => true,
                'requires_selection' => false,
                'requires_module_selection' => false,
                'available_modules' => array_values($crsdTypes)
            ], 200);
        } catch (\Exception $e) {
            Log::error('Check module selection error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa pilihan module',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function selectModule(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }
            
            $validator = Validator::make($request->all(), [
                'module' => 'required|in:crsd1,crsd2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }
            
            $dataAccess = $this->getUserDataAccess();
            
            if ($user->role === 'admin' && !in_array($request->module, $dataAccess)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke module ini'
                ], 403);
            }
            
            return response()->json([
                'success' => true,
                'message' => 'Module berhasil dipilih',
                'selected_module' => $request->module,
                'redirect_url' => '/admin/' . $request->module . '/dashboard'
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Select module error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memilih module',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    
    public function getAllOrders(Request $request)
{
    try {
        $user = auth()->guard('api')->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses'
            ], 403);
        }

        // PERBAIKAN: Load restaurant melalui items.menu.restaurant agar dapat semua restaurants
        $query = Orders::with([
            'user', 
            'items.menu.restaurant.area' // Load restaurant melalui menu
        ])->orderBy('created_at', 'desc');
        
        $this->applyCRSDFilter($query);
        
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('order_status', $request->status);
        }
        
        if ($request->has('date')) {
            $query->whereDate('created_at', $request->date);
        }
        
        if ($request->has('crsd_type') && $request->crsd_type !== 'all') {
            $crsdType = $request->crsd_type;
            $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
            $query->whereHas('user', function($q) use ($divisiName) {
                $q->where('divisi', $divisiName);
            });
        }
        
        // Filter berdasarkan area (cek dari semua items)
        if ($request->has('area_id') && $request->area_id !== 'all') {
            $areaId = $request->area_id;
            $query->whereHas('items.menu.restaurant.area', function($q) use ($areaId) {
                $q->where('id', $areaId);
            });
        }
        
        // Filter berdasarkan restaurant (cek dari semua items)
        if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
            $restaurantId = $request->restaurant_id;
            $query->whereHas('items.menu.restaurant', function($q) use ($restaurantId) {
                $q->where('id', $restaurantId);
            });
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
                  })
                  ->orWhereHas('items.menu.restaurant.area', function($q4) use ($search) {
                      $q4->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->get();
        
        // Proses data untuk menambahkan area information dari SEMUA restaurants
        $orders->each(function($order) {
            // CRSD type dari user divisi
            $order->crsd_type = $order->getCrsdTypeAttribute();
            
            // Helper function untuk mendapatkan semua restaurants unik dari order
            $getAllRestaurants = function($order) {
                $restaurants = [];
                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant) {
                        $restaurantId = $item->menu->restaurant->id;
                        if (!isset($restaurants[$restaurantId])) {
                            $restaurants[$restaurantId] = $item->menu->restaurant;
                        }
                    }
                }
                return array_values($restaurants);
            };
            
            // Helper function untuk mendapatkan semua areas unik dari order
            $getAllAreas = function($order) {
                $areas = [];
                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant && $item->menu->restaurant->area) {
                        $areaId = $item->menu->restaurant->area->id;
                        if (!isset($areas[$areaId])) {
                            $areas[$areaId] = $item->menu->restaurant->area;
                        }
                    }
                }
                return array_values($areas);
            };
            
            // Ambil semua restaurants dari order
            $allRestaurants = $getAllRestaurants($order);
            $allAreas = $getAllAreas($order);
            
            // Untuk backward compatibility: ambil restaurant pertama jika ada
            $firstRestaurant = !empty($allRestaurants) ? $allRestaurants[0] : null;
            $firstArea = !empty($allAreas) ? $allAreas[0] : null;
            
            // Set untuk response
            $order->restaurant = $firstRestaurant;
            $order->area = $firstArea;
            $order->area_name = $firstArea ? $firstArea->name : 'Multiple Areas';
            $order->area_icon = $firstArea ? $firstArea->icon : '📍';
            
            // Tambahkan informasi semua restaurants dan areas
            $order->all_restaurants = $allRestaurants;
            $order->all_areas = $allAreas;
            $order->restaurants_count = count($allRestaurants);
            $order->areas_count = count($allAreas);
            
            // Hitung jumlah item
            $order->items_count = $order->items->count();
        });
        
        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully',
            'data' => $orders
        ], 200);
        
    } catch (\Exception $e) {
        Log::error('Error in getAllOrders: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data pesanan',
            'error' => $e->getMessage()
        ], 500);
    }
}
    
    public function getCRSDOrders(Request $request, $crsdType)
{
    try {
        $user = auth()->guard('api')->user();

        if (!in_array($user->role, ['admin', 'superadmin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses'
            ], 403);
        }
        
        if (!in_array($crsdType, ['crsd1', 'crsd2'])) {
            return response()->json([
                'success' => false,
                'message' => 'Tipe CRSD tidak valid'
            ], 400);
        }
        
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
        
        $query = Orders::with(['user', 'items.menu.restaurant.area']) // UPDATED
            ->whereHas('user', function($q) use ($divisiName) {
                $q->where('divisi', $divisiName);
            })
            ->orderBy('created_at', 'desc');
        
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('order_status', $request->status);
        }
        
        if ($request->has('date')) {
            $query->whereDate('created_at', $request->date);
        }
        
        // Filter area berdasarkan semua items
        if ($request->has('area_id') && $request->area_id !== 'all') {
            $areaId = $request->area_id;
            $query->whereHas('items.menu.restaurant.area', function($q) use ($areaId) {
                $q->where('id', $areaId);
            });
        }
        
        // Filter restaurant berdasarkan semua items
        if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
            $restaurantId = $request->restaurant_id;
            $query->whereHas('items.menu.restaurant', function($q) use ($restaurantId) {
                $q->where('id', $restaurantId);
            });
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
                  })
                  ->orWhereHas('items.menu.restaurant.area', function($q4) use ($search) {
                      $q4->where('name', 'like', "%{$search}%");
                  });
            });
        }

        $orders = $query->get();
        
        // Proses data untuk handle multiple restaurants/areas
        $orders->each(function($order) use ($crsdType) {
            $order->crsd_type = $crsdType;
            
            // Helper function untuk mendapatkan semua restaurants unik
            $getAllRestaurants = function($order) {
                $restaurants = [];
                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant) {
                        $restaurantId = $item->menu->restaurant->id;
                        if (!isset($restaurants[$restaurantId])) {
                            $restaurants[$restaurantId] = $item->menu->restaurant;
                        }
                    }
                }
                return array_values($restaurants);
            };
            
            // Helper function untuk mendapatkan semua areas unik
            $getAllAreas = function($order) {
                $areas = [];
                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant && $item->menu->restaurant->area) {
                        $areaId = $item->menu->restaurant->area->id;
                        if (!isset($areas[$areaId])) {
                            $areas[$areaId] = $item->menu->restaurant->area;
                        }
                    }
                }
                return array_values($areas);
            };
            
            $allRestaurants = $getAllRestaurants($order);
            $allAreas = $getAllAreas($order);
            
            // Untuk backward compatibility
            $firstRestaurant = !empty($allRestaurants) ? $allRestaurants[0] : null;
            $firstArea = !empty($allAreas) ? $allAreas[0] : null;
            
            $order->restaurant = $firstRestaurant;
            $order->area = $firstArea;
            $order->area_name = $firstArea ? $firstArea->name : 'Multiple Areas';
            $order->area_icon = $firstArea ? $firstArea->icon : '📍';
            $order->items_count = $order->items->count();
            
            // Tambahan informasi
            $order->all_restaurants = $allRestaurants;
            $order->all_areas = $allAreas;
            $order->restaurants_count = count($allRestaurants);
            $order->areas_count = count($allAreas);
        });
        
        return response()->json([
            'success' => true,
            'message' => 'Orders retrieved successfully for ' . strtoupper($crsdType),
            'crsd_type' => $crsdType,
            'data' => $orders
        ], 200);
        
    } catch (\Exception $e) {
        Log::error('Error in getCRSDOrders: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengambil data pesanan',
            'error' => $e->getMessage()
        ], 500);
    }
}
    
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
                
                $dataAccess = $this->getUserDataAccess();
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    $query->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                } elseif (in_array('crsd1', $dataAccess)) {
                    $query->where('divisi', 'CRSD 1');
                } elseif (in_array('crsd2', $dataAccess)) {
                    $query->where('divisi', 'CRSD 2');
                } else {
                    $query->whereRaw('1 = 0');
                }
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
            
            if (!in_array($crsdType, ['crsd1', 'crsd2'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tipe CRSD tidak valid'
                ], 400);
            }
            
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

    // New method for actual file export/download
    public function exportExcel(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            $startDateInput = $request->get('start_date', now()->subMonth()->toDateString());
            $endDateInput = $request->get('end_date', now()->toDateString());

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

            $query = Orders::with(['user', 'items.menu'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->orderBy('created_at', 'desc');
            
            $this->applyCRSDFilter($query);
            
            $orders = $query->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data untuk diekspor'
                ], 404);
            }

            // Format CSV content
            $csvData = [];
            
            // Header
            $csvData[] = [
                'Order ID',
                'Order Code',
                'Customer Name',
                'Customer Email',
                'Customer Divisi',
                'Order Status',
                'Payment Status',
                'Total Amount',
                'Order Date',
                'Items Count',
                'Items'
            ];
            
            // Data rows
            foreach ($orders as $order) {
                $csvData[] = [
                    $order->id,
                    $order->order_code,
                    $order->user->name ?? 'Unknown',
                    $order->user->email ?? 'Unknown',
                    $order->user->divisi ?? 'Unknown',
                    $order->order_status,
                    $order->status,
                    (int) $order->total_price,
                    $order->created_at->format('Y-m-d H:i:s'),
                    $order->items->count(),
                    $order->items->map(function($item) {
                        return $item->menu?->name ?? 'Unknown Product';
                    })->implode(', ')
                ];
            }

            // Convert to CSV string
            $csvContent = '';
            foreach ($csvData as $row) {
                $csvContent .= implode(',', array_map(function($value) {
                    return '"' . str_replace('"', '""', $value) . '"';
                }, $row)) . "\n";
            }

            $filename = 'orders_export_' . $startDate->format('Ymd') . '_to_' . $endDate->format('Ymd') . '.csv';

            return response($csvContent)
                ->header('Content-Type', 'text/csv; charset=utf-8')
                ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');

        } catch (\Exception $e) {
            Log::error('Export Excel error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal export ke Excel: ' . $e->getMessage()
            ], 500);
        }
    }
}