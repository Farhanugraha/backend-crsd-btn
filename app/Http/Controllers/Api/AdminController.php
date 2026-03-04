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

        if ($user->role === 'superadmin') {
            return ['crsd1', 'crsd2', 'general'];
        }

        if ($user->role === 'admin') {
            $dataAccess = $user->getEffectiveDataAccess();

            if (!is_array($dataAccess)) {
                return [];
            }

            $filteredAccess = array_filter($dataAccess, function ($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });

            $filteredAccess = array_values($filteredAccess);

            if (in_array('crsd1', $filteredAccess) && in_array('crsd2', $filteredAccess)) {
                if (!in_array('general', $filteredAccess)) {
                    $filteredAccess[] = 'general';
                }
            }

            return $filteredAccess;
        }

        return [];
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

            $crsdAccess = array_filter($dataAccess, function ($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            });

            if (count($crsdAccess) === 2) {
                return $query->whereHas('user', function ($q) {
                    $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                });
            } elseif (count($crsdAccess) === 1) {
                $crsdType = reset($crsdAccess);
                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';

                return $query->whereHas('user', function ($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
            }
        }

        return $query;
    }

    private function applyCRSDFilterWithModule($query, $crsdType = null)
    {
        $user = auth()->guard('api')->user();

        if (!$user) {
            return $query;
        }

        if ($user->role === 'superadmin') {
            if ($crsdType === 'general' || !$crsdType) {
                return $query;
            }

            if ($crsdType === 'crsd1') {
                return $query->whereHas('user', function ($q) {
                    $q->where('divisi', 'CRSD 1');
                });
            }

            if ($crsdType === 'crsd2') {
                return $query->whereHas('user', function ($q) {
                    $q->where('divisi', 'CRSD 2');
                });
            }

            return $query;
        }

        if ($user->role === 'admin') {
            $dataAccess = $this->getUserDataAccess();

            if (empty($dataAccess)) {
                return $query->whereRaw('1 = 0');
            }

            if ($crsdType && $crsdType !== 'general') {
                if (!in_array($crsdType, $dataAccess)) {
                    return $query->whereRaw('1 = 0');
                }

                $divisiName = $crsdType === 'crsd1' ? 'CRSD 1' : 'CRSD 2';
                return $query->whereHas('user', function ($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                });
            }

            if ($crsdType === 'general') {
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    return $query->whereHas('user', function ($q) {
                        $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                    });
                } elseif (in_array('crsd1', $dataAccess)) {
                    return $query->whereHas('user', function ($q) {
                        $q->where('divisi', 'CRSD 1');
                    });
                } elseif (in_array('crsd2', $dataAccess)) {
                    return $query->whereHas('user', function ($q) {
                        $q->where('divisi', 'CRSD 2');
                    });
                }
            }

            if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                return $query->whereHas('user', function ($q) {
                    $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                });
            } elseif (in_array('crsd1', $dataAccess)) {
                return $query->whereHas('user', function ($q) {
                    $q->where('divisi', 'CRSD 1');
                });
            } elseif (in_array('crsd2', $dataAccess)) {
                return $query->whereHas('user', function ($q) {
                    $q->where('divisi', 'CRSD 2');
                });
            }
        }

        return $query;
    }

    public function dashboard(Request $request)
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

            $dataAccess = $this->getUserDataAccess();
            $requestedCrsd = $request->query('crsd_type');

            Log::info('===== DASHBOARD ACCESS =====');
            Log::info('User: ' . $user->email . ', Role: ' . $user->role);
            Log::info('Data Access: ' . json_encode($dataAccess));
            Log::info('Requested CRSD: ' . ($requestedCrsd ?? 'null'));

            $hasMultipleAccess = count($dataAccess) > 1;
            $requiresModuleSelection = $hasMultipleAccess && !$requestedCrsd;

            if ($requiresModuleSelection) {
                Log::info('Dashboard - User requires module selection - returning with default data');
                return response()->json([
                    'success' => true,
                    'message' => 'Silakan pilih module',
                    'data_access' => $dataAccess,
                    'user_role' => $user->role,
                    'selected_module' => null,
                    'requires_selection' => true,
                    'requires_module_selection' => true,
                    'available_modules' => $dataAccess,
                    'shows_all_crsd' => true,
                    'data' => [
                        'orders' => [
                            'total' => 0,
                            'pending' => 0,
                            'processing' => 0,
                            'completed' => 0,
                            'canceled' => 0,
                        ],
                        'payments' => [
                            'total_revenue' => 0,
                            'pending_payments' => 0,
                        ],
                        'users' => [
                            'total_users' => 0,
                            'total_admins' => User::whereIn('role', ['admin', 'superadmin'])->count(),
                        ]
                    ]
                ], 200);
            }

            if (!$requestedCrsd && count($dataAccess) === 1) {
                $requestedCrsd = $dataAccess[0];
                Log::info("Dashboard - Single access using default module: {$requestedCrsd}");
            }

            if (!$requestedCrsd) {
                $requestedCrsd = 'general';
                Log::info("Dashboard - Using general as default module");
            }

            $ordersQuery = Orders::query();
            $this->applyCRSDFilterWithModule($ordersQuery, $requestedCrsd);

            $orderStats = (clone $ordersQuery)->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN order_status = "processing" THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN order_status = "completed" AND status = "paid" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN order_status = "canceled" OR status = "canceled" THEN 1 ELSE 0 END) as canceled,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as total_revenue
            ')->first();

            $totalOrders    = (int) ($orderStats->total ?? 0);
            $pendingOrders  = (int) ($orderStats->pending ?? 0);
            $processingOrders = (int) ($orderStats->processing ?? 0);
            $completedOrders  = (int) ($orderStats->completed ?? 0);
            $canceledOrders   = (int) ($orderStats->canceled ?? 0);
            $totalRevenue     = (int) ($orderStats->total_revenue ?? 0);

            $pendingPaymentsQuery = Payments::where('payment_status', 'pending');

            if ($requestedCrsd === 'crsd1') {
                $pendingPaymentsQuery->whereHas('order.user', function ($q) {
                    $q->where('divisi', 'CRSD 1');
                });
            } elseif ($requestedCrsd === 'crsd2') {
                $pendingPaymentsQuery->whereHas('order.user', function ($q) {
                    $q->where('divisi', 'CRSD 2');
                });
            } elseif ($requestedCrsd === 'general' && $user->role === 'admin') {
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    $pendingPaymentsQuery->whereHas('order.user', function ($q) {
                        $q->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                    });
                } elseif (in_array('crsd1', $dataAccess)) {
                    $pendingPaymentsQuery->whereHas('order.user', function ($q) {
                        $q->where('divisi', 'CRSD 1');
                    });
                } elseif (in_array('crsd2', $dataAccess)) {
                    $pendingPaymentsQuery->whereHas('order.user', function ($q) {
                        $q->where('divisi', 'CRSD 2');
                    });
                }
            }

            $pendingPayments = $pendingPaymentsQuery->count();

            $usersQuery = User::where('role', 'user');
            if ($requestedCrsd === 'crsd1') {
                $usersQuery->where('divisi', 'CRSD 1');
            } elseif ($requestedCrsd === 'crsd2') {
                $usersQuery->where('divisi', 'CRSD 2');
            } elseif ($requestedCrsd === 'general' && $user->role === 'admin') {
                $dataAccess = $this->getUserDataAccess();
                if (in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess)) {
                    $usersQuery->whereIn('divisi', ['CRSD 1', 'CRSD 2']);
                } elseif (in_array('crsd1', $dataAccess)) {
                    $usersQuery->where('divisi', 'CRSD 1');
                } elseif (in_array('crsd2', $dataAccess)) {
                    $usersQuery->where('divisi', 'CRSD 2');
                }
            }

            $totalUsers  = $usersQuery->count();
            $totalAdmins = User::whereIn('role', ['admin', 'superadmin'])->count();

            return response()->json([
                'success' => true,
                'message' => 'Admin dashboard loaded successfully',
                'data_access' => $dataAccess,
                'user_role' => $user->role,
                'selected_module' => $requestedCrsd,
                'requires_selection' => false,
                'requires_module_selection' => false,
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
                        'total_revenue' => $totalRevenue,
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

    public function getStatistics(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!$user) {
                return $this->errorResponse('Unauthenticated', 401);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            $startDateInput = $request->get('start_date');
            $endDateInput   = $request->get('end_date');

            if (!$startDateInput || !$endDateInput) {
                $endDateInput   = now()->toDateString();
                $startDateInput = now()->toDateString();
            }

            try {
                $startDate = Carbon::parse($startDateInput)->startOfDay();
                $endDate   = Carbon::parse($endDateInput)->endOfDay();

                if ($startDate > $endDate) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Tanggal awal harus lebih kecil dari tanggal akhir'
                    ], 400);
                }

                if ($startDate->diffInDays($endDate) > 365) {
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

            $requestedCrsd = $request->query('crsd_type');

            $mainStats = Orders::selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as total_revenue,
                SUM(CASE WHEN order_status = "completed" AND status = "paid" THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN order_status = "processing" THEN 1 ELSE 0 END) as processing_orders,
                SUM(CASE WHEN order_status = "canceled" OR status = "canceled" THEN 1 ELSE 0 END) as canceled_orders,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_orders
            ')->whereBetween('created_at', [$startDate, $endDate]);

            $this->applyCRSDFilterWithModule($mainStats, $requestedCrsd);

            $mainStatsResult = $mainStats->first();

            $totalOrders      = (int) ($mainStatsResult->total_orders ?? 0);
            $totalRevenue     = (int) ($mainStatsResult->total_revenue ?? 0);
            $completedOrders  = (int) ($mainStatsResult->completed_orders ?? 0);
            $processingOrders = (int) ($mainStatsResult->processing_orders ?? 0);
            $canceledOrders   = (int) ($mainStatsResult->canceled_orders ?? 0);
            $pendingOrders    = (int) ($mainStatsResult->pending_orders ?? 0);

            $averageOrderValue = $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0;

            $todayStart = now()->startOfDay();
            $todayEnd   = now()->endOfDay();

            $todayStats = Orders::selectRaw('
                COUNT(*) as orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as revenue
            ')->whereBetween('created_at', [$todayStart, $todayEnd]);

            $this->applyCRSDFilterWithModule($todayStats, $requestedCrsd);

            $todayResult  = $todayStats->first();
            $todayOrders  = (int) ($todayResult->orders ?? 0);
            $todayRevenue = (int) ($todayResult->revenue ?? 0);

            $periodDays = $startDate->diffInDays($endDate) + 1;

            $previousPeriodStart = (clone $startDate)->subDays($periodDays)->startOfDay();
            $previousPeriodEnd   = (clone $startDate)->subDay()->endOfDay();

            $previousStats = Orders::selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as total_revenue
            ')->whereBetween('created_at', [$previousPeriodStart, $previousPeriodEnd]);

            $this->applyCRSDFilterWithModule($previousStats, $requestedCrsd);

            $previousResult       = $previousStats->first();
            $previousPeriodRevenue = (int) ($previousResult->total_revenue ?? 0);
            $previousPeriodOrders  = (int) ($previousResult->total_orders ?? 0);

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

            $chartQuery = Orders::selectRaw('
                DATE(created_at) as date,
                COUNT(*) as orders,
                SUM(CASE WHEN status = "paid" THEN total_price ELSE 0 END) as revenue
            ')->whereBetween('created_at', [$startDate, $endDate]);

            $this->applyCRSDFilterWithModule($chartQuery, $requestedCrsd);

            $chartResults    = $chartQuery->groupBy(DB::raw('DATE(created_at)'))->orderBy('date')->get();
            $chartResultsMap = $chartResults->keyBy('date');

            $chartData   = [];
            $currentDate = clone $startDate;

            while ($currentDate <= $endDate) {
                $dateString = $currentDate->format('Y-m-d');
                $dayData    = $chartResultsMap->get($dateString);

                $chartData[] = [
                    'date'    => $currentDate->format('d M'),
                    'orders'  => $dayData ? (int) $dayData->orders : 0,
                    'revenue' => $dayData ? (int) $dayData->revenue : 0
                ];

                $currentDate->addDay();
            }

            return response()->json([
                'success' => true,
                'message' => 'Statistik berhasil dimuat',
                'data' => [
                    'totalOrders'       => $totalOrders,
                    'totalRevenue'      => $totalRevenue,
                    'completedOrders'   => $completedOrders,
                    'processingOrders'  => $processingOrders,
                    'canceledOrders'    => $canceledOrders,
                    'pendingOrders'     => $pendingOrders,
                    'averageOrderValue' => $averageOrderValue,
                    'todayOrders'       => $todayOrders,
                    'todayRevenue'      => $todayRevenue,
                    'revenueGrowth'     => round($revenueGrowth, 2),
                    'orderGrowth'       => round($orderGrowth, 2),
                    'chartData'         => $chartData,
                    'periodInfo'        => [
                        'start_date' => $startDate->format('Y-m-d'),
                        'end_date'   => $endDate->format('Y-m-d'),
                        'days'       => $periodDays,
                        'previous_period' => [
                            'start_date' => $previousPeriodStart->format('Y-m-d'),
                            'end_date'   => $previousPeriodEnd->format('Y-m-d')
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

            if (!$user) {
                return $this->errorResponse('Unauthenticated', 401);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return $this->errorResponse('Anda tidak memiliki akses', 403);
            }

            $startDateInput = $request->get('start_date', now()->startOfMonth()->toDateString());
            $endDateInput   = $request->get('end_date', now()->toDateString());
            $crsdType       = $request->query('crsd_type');
            $dataAccess     = $this->getUserDataAccess();

            Log::info('===== REPORTS ACCESS =====');
            Log::info('User: ' . $user->email . ', Role: ' . $user->role);
            Log::info('Data Access: ' . json_encode($dataAccess));
            Log::info('Requested CRSD: ' . ($crsdType ?? 'null'));

            $hasMultipleAccess       = count($dataAccess) > 1;
            $requiresModuleSelection = $hasMultipleAccess && !$crsdType;

            if ($requiresModuleSelection) {
                Log::info('Reports - User requires module selection');
                return response()->json([
                    'success'          => true,
                    'message'          => 'Silakan pilih module terlebih dahulu',
                    'requires_selection' => true,
                    'available_modules'  => $dataAccess,
                    'data'             => null
                ], 200);
            }

            if ($user->role === 'admin' && $crsdType && $crsdType !== 'general') {
                if (!in_array($crsdType, $dataAccess)) {
                    Log::warning("getReports - Admin attempted to access unauthorized module: {$crsdType}");
                    return $this->errorResponse('Anda tidak memiliki akses ke modul ini', 403);
                }
            }

            if ($user->role === 'admin' && !$crsdType && count($dataAccess) === 1) {
                $crsdType = $dataAccess[0];
                Log::info("getReports - Admin with single access using default module: {$crsdType}");
            }

            if ($user->role === 'superadmin' && (!$crsdType || $crsdType === 'general')) {
                $crsdType = 'general';
            }

            try {
                $startDate = Carbon::parse($startDateInput)->startOfDay();
                $endDate   = Carbon::parse($endDateInput)->endOfDay();

                if ($startDate > $endDate) {
                    return $this->errorResponse('Tanggal awal harus lebih kecil dari tanggal akhir', 400);
                }
            } catch (\Exception $e) {
                return $this->errorResponse('Format tanggal tidak valid', 400);
            }

            $baseQuery = Orders::with(['user', 'items.menu.restaurant.area'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('order_status', 'completed')
                ->where('status', 'paid');

            $this->applyCRSDFilterWithModule($baseQuery, $crsdType);

            Log::info('Reports SQL: ' . $baseQuery->toSql());
            Log::info('Reports Bindings: ' . json_encode($baseQuery->getBindings()));

            if ($request->has('area_id') && $request->area_id !== 'all') {
                $areaId = $request->area_id;
                $baseQuery->whereHas('items.menu.restaurant.area', function ($q) use ($areaId) {
                    $q->where('id', $areaId);
                });
            }

            if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
                $restaurantId = $request->restaurant_id;
                $baseQuery->whereHas('items.menu.restaurant', function ($q) use ($restaurantId) {
                    $q->where('id', $restaurantId);
                });
            }

            $totalOrders  = $baseQuery->count();
            $totalRevenue = (int) $baseQuery->sum('total_price');

            Log::info('Reports Total Orders: ' . $totalOrders);
            Log::info('Reports Total Revenue: ' . $totalRevenue);

            $orders = (clone $baseQuery)->orderBy('created_at', 'desc')->get();

            Log::info('Reports Orders Count: ' . $orders->count());

            $orders->each(function ($order) use ($crsdType) {
                $order->crsd_type = $crsdType ?? ($order->user->divisi === 'CRSD 1' ? 'crsd1' : 'crsd2');

                $allRestaurants = [];
                $allAreas       = [];

                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant) {
                        $restaurant   = $item->menu->restaurant;
                        $restaurantId = $restaurant->id;
                        if (!isset($allRestaurants[$restaurantId])) {
                            $allRestaurants[$restaurantId] = $restaurant;
                        }

                        if ($restaurant->area) {
                            $areaId = $restaurant->area->id;
                            if (!isset($allAreas[$areaId])) {
                                $allAreas[$areaId] = $restaurant->area;
                            }
                        }
                    }
                }

                $allRestaurants = array_values($allRestaurants);
                $allAreas       = array_values($allAreas);
                $firstRestaurant = !empty($allRestaurants) ? $allRestaurants[0] : null;
                $firstArea       = !empty($allAreas) ? $allAreas[0] : null;

                $order->restaurant       = $firstRestaurant;
                $order->area             = $firstArea;
                $order->area_name        = $firstArea ? $firstArea->name : 'Multiple Areas';
                $order->area_icon        = $firstArea ? $firstArea->icon : '📍';
                $order->all_restaurants  = $allRestaurants;
                $order->all_areas        = $allAreas;
                $order->restaurants_count = count($allRestaurants);
                $order->areas_count      = count($allAreas);
                $order->items_count      = $order->items->count();
            });

            return response()->json([
                'success' => true,
                'message' => 'Laporan berhasil dimuat',
                'data'    => [
                    'summary' => [
                        'period' => [
                            'start_date' => $startDate->format('Y-m-d'),
                            'end_date'   => $endDate->format('Y-m-d'),
                        ],
                        'total_orders'       => $totalOrders,
                        'total_revenue'      => $totalRevenue,
                        'completed_orders'   => $totalOrders,
                        'processing_orders'  => 0,
                        'pending_orders'     => 0,
                        'canceled_orders'    => 0,
                        'average_order_value' => $totalOrders > 0 ? (int) ($totalRevenue / $totalOrders) : 0,
                    ],
                    'orders' => $orders,
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('getReports error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return $this->errorResponse('Gagal mengambil laporan: ' . $e->getMessage(), 500);
        }
    }

    public function getOrdersDetail(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!$user) {
                return $this->errorResponse('Unauthenticated', 401);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return $this->errorResponse('Anda tidak memiliki akses', 403);
            }

            $startDateInput = $request->get('start_date', now()->subMonth()->toDateString());
            $endDateInput   = $request->get('end_date', now()->toDateString());
            $crsdType       = $request->query('crsd_type');
            $dataAccess     = $this->getUserDataAccess();

            Log::info('===== ORDERS DETAIL ACCESS =====');
            Log::info('User: ' . $user->email . ', Role: ' . $user->role);
            Log::info('Data Access: ' . json_encode($dataAccess));
            Log::info('Requested CRSD: ' . ($crsdType ?? 'null'));

            $hasMultipleAccess       = count($dataAccess) > 1;
            $requiresModuleSelection = $hasMultipleAccess && !$crsdType;

            if ($requiresModuleSelection) {
                Log::info('Orders Detail - User requires module selection');
                return response()->json([
                    'success'            => true,
                    'message'            => 'Silakan pilih module terlebih dahulu',
                    'requires_selection' => true,
                    'available_modules'  => $dataAccess,
                    'data'               => null
                ], 200);
            }

            if ($user->role === 'admin' && $crsdType && $crsdType !== 'general') {
                if (!in_array($crsdType, $dataAccess)) {
                    Log::warning("getOrdersDetail - Admin attempted to access unauthorized module: {$crsdType}");
                    return $this->errorResponse('Anda tidak memiliki akses ke modul ini', 403);
                }
            }

            if ($user->role === 'admin' && !$crsdType && count($dataAccess) === 1) {
                $crsdType = $dataAccess[0];
                Log::info("getOrdersDetail - Admin with single access using default module: {$crsdType}");
            }

            if ($user->role === 'superadmin' && (!$crsdType || $crsdType === 'general')) {
                $crsdType = 'general';
            }

            try {
                $startDate = Carbon::parse($startDateInput)->startOfDay();
                $endDate   = Carbon::parse($endDateInput)->endOfDay();

                if ($startDate > $endDate) {
                    return $this->errorResponse('Tanggal awal harus lebih kecil dari tanggal akhir', 400);
                }
            } catch (\Exception $e) {
                return $this->errorResponse('Format tanggal tidak valid', 400);
            }

            $query = Orders::with(['user', 'items.menu'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('order_status', 'completed')
                ->where('status', 'paid')
                ->orderBy('created_at', 'desc');

            $this->applyCRSDFilterWithModule($query, $crsdType);

            Log::info('Orders Detail SQL: ' . $query->toSql());
            Log::info('Orders Detail Bindings: ' . json_encode($query->getBindings()));

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->get();
            Log::info('Orders Detail Count: ' . $orders->count());

            $ordersByDate    = [];
            $cumulativeTotal = 0;
            $totalRevenue    = 0;

            foreach ($orders as $order) {
                $date = $order->created_at->format('Y-m-d');

                if (!isset($ordersByDate[$date])) {
                    $ordersByDate[$date] = [
                        'date'         => $date,
                        'orders'       => [],
                        'daily_total'  => 0,
                        'total_orders' => 0
                    ];
                }

                $orderTotal = (int) $order->total_price;
                $ordersByDate[$date]['daily_total']  += $orderTotal;
                $ordersByDate[$date]['total_orders'] += 1;
                $totalRevenue += $orderTotal;

                $orderData = [
                    'order_id'       => $order->id,
                    'order_number'   => $order->order_code ?? 'ORD-' . $order->id,
                    'customer'       => $order->user->name ?? 'Guest',
                    'order_status'   => $order->order_status,
                    'payment_status' => $order->status,
                    'items'          => $order->items->map(function ($item) {
                        return [
                            'name'     => $item->menu?->name ?? 'Unknown Product',
                            'quantity' => (int) $item->quantity,
                            'price'    => (int) $item->price,
                            'subtotal' => (int) $item->quantity * (int) $item->price
                        ];
                    })->toArray(),
                    'total'      => $orderTotal,
                    'created_at' => $order->created_at->format('Y-m-d H:i:s')
                ];

                $ordersByDate[$date]['orders'][] = $orderData;
            }

            $formattedOrdersByDate = [];
            ksort($ordersByDate);

            foreach ($ordersByDate as $date => $data) {
                $cumulativeTotal += $data['daily_total'];
                $formattedOrdersByDate[] = [
                    'date'             => $date,
                    'total_orders'     => $data['total_orders'],
                    'daily_total'      => $data['daily_total'],
                    'cumulative_total' => $cumulativeTotal,
                    'orders'           => $data['orders']
                ];
            }

            $responseData = [
                'period' => [
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date'   => $endDate->format('Y-m-d'),
                ],
                'summary' => [
                    'total_orders'       => $orders->count(),
                    'total_revenue'      => $totalRevenue,
                    'average_order_value' => $orders->count() > 0 ? (int) ($totalRevenue / $orders->count()) : 0,
                ],
                'orders_by_date' => $formattedOrdersByDate
            ];

            return response()->json([
                'success' => true,
                'message' => 'Orders detail retrieved successfully',
                'data'    => $responseData
            ], 200);

        } catch (\Exception $e) {
            Log::error('getOrdersDetail error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());

            return $this->errorResponse('Gagal mengambil detail orders: ' . $e->getMessage(), 500);
        }
    }

    public function exportReports(Request $request)
    {
        try {
            $user = auth()->guard('api')->user();

            if (!$user) {
                return $this->errorResponse('Unauthenticated', 401);
            }

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return $this->errorResponse('Anda tidak memiliki akses', 403);
            }

            $startDateInput = $request->get('start_date', now()->subMonth()->toDateString());
            $endDateInput   = $request->get('end_date', now()->toDateString());
            $crsdType       = $request->query('crsd_type');
            $exportType     = $request->get('export_type', 'excel');

            try {
                $startDate = Carbon::parse($startDateInput)->startOfDay();
                $endDate   = Carbon::parse($endDateInput)->endOfDay();

                if ($startDate > $endDate) {
                    return $this->errorResponse('Tanggal awal harus lebih kecil dari tanggal akhir', 400);
                }
            } catch (\Exception $e) {
                return $this->errorResponse('Format tanggal tidak valid', 400);
            }

            $query = Orders::with(['user', 'items.menu'])
                ->whereBetween('created_at', [$startDate, $endDate])
                ->where('order_status', 'completed')
                ->where('status', 'paid')
                ->orderBy('created_at', 'desc');

            $this->applyCRSDFilterWithModule($query, $crsdType);

            $orders = $query->get();

            if ($orders->isEmpty()) {
                return $this->errorResponse('Tidak ada data untuk diekspor pada periode ini', 404);
            }

            $exportData = $orders->map(function ($order) {
                Log::info('Export Order - ID: ' . $order->id .
                    ', Order Status: ' . $order->order_status .
                    ', Payment Status: ' . $order->status);

                return [
                    'Order ID'        => $order->id,
                    'Order Code'      => $order->order_code,
                    'Customer Name'   => $order->user->name ?? 'Unknown',
                    'Customer Email'  => $order->user->email ?? 'Unknown',
                    'Customer Divisi' => $order->user->divisi ?? 'Unknown',
                    'Order Status'    => $order->order_status,
                    'Payment Status'  => $order->status,
                    'Total Amount'    => (int) $order->total_price,
                    'Order Date'      => $order->created_at->format('Y-m-d H:i:s'),
                    'Items Count'     => $order->items->count(),
                    'Items'           => $order->items->map(function ($item) {
                        return $item->menu?->name ?? 'Unknown Product';
                    })->implode(', ')
                ];
            });

            $summaryData = [
                'Period'         => $startDate->format('Y-m-d') . ' to ' . $endDate->format('Y-m-d'),
                'Total Orders'   => $orders->count(),
                'Total Revenue'  => (int) $orders->sum('total_price'),
                'Completed Orders' => $orders->count(),
                'Pending Orders' => 0,
                'Canceled Orders' => 0,
            ];

            return response()->json([
                'success'     => true,
                'message'     => 'Export data ready',
                'data'        => [
                    'summary' => $summaryData,
                    'orders'  => $exportData,
                ],
                'export_type' => $exportType,
                'filename'    => 'orders_export_' . $startDate->format('Ymd') . '_to_' . $endDate->format('Ymd') . '.json',
                'download_url' => $exportType === 'excel'
                    ? url("/api/admin/export/excel?start_date={$startDateInput}&end_date={$endDateInput}")
                    : null
            ], 200);

        } catch (\Exception $e) {
            Log::error('Export reports error: ' . $e->getMessage());
            return $this->errorResponse('Gagal menyiapkan data export: ' . $e->getMessage(), 500);
        }
    }

    public function checkModuleSelection()
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

            $dataAccess = $this->getUserDataAccess();
            $crsdTypes  = array_values(array_filter($dataAccess, function ($item) {
                return in_array($item, ['crsd1', 'crsd2']);
            }));

            if (count($crsdTypes) > 1) {
                return response()->json([
                    'success'                  => true,
                    'requires_selection'       => true,
                    'requires_module_selection' => true,
                    'available_modules'        => $crsdTypes
                ], 200);
            }

            return response()->json([
                'success'                  => true,
                'requires_selection'       => false,
                'requires_module_selection' => false,
                'available_modules'        => $crsdTypes
            ], 200);

        } catch (\Exception $e) {
            Log::error('Check module selection error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memeriksa pilihan module',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function selectModule(Request $request)
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

            $validator = Validator::make($request->all(), [
                'module' => 'required|in:crsd1,crsd2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors'  => $validator->errors()
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
                'success'         => true,
                'message'         => 'Module berhasil dipilih',
                'selected_module' => $request->module,
                'redirect_url'    => '/admin/' . $request->module . '/dashboard'
            ], 200);

        } catch (\Exception $e) {
            Log::error('Select module error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memilih module',
                'error'   => $e->getMessage()
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

            $crsdType = $request->query('crsd_type');

            $query = Orders::with([
                'user',
                'items.menu.restaurant.area'
            ])->orderBy('created_at', 'desc');

            $this->applyCRSDFilterWithModule($query, $crsdType);

            if ($request->has('status') && $request->status !== 'all') {
                $query->where('order_status', $request->status);
            }

            if ($request->has('payment_status') && $request->payment_status !== 'all') {
                $query->where('status', $request->payment_status);
            }

            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }

            if ($request->has('area_id') && $request->area_id !== 'all') {
                $areaId = $request->area_id;
                $query->whereHas('items.menu.restaurant.area', function ($q) use ($areaId) {
                    $q->where('id', $areaId);
                });
            }

            if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
                $restaurantId = $request->restaurant_id;
                $query->whereHas('items.menu.restaurant', function ($q) use ($restaurantId) {
                    $q->where('id', $restaurantId);
                });
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      })
                      ->orWhereHas('items.menu.restaurant', function ($q3) use ($search) {
                          $q3->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('items.menu.restaurant.area', function ($q4) use ($search) {
                          $q4->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->get();

            $orders->each(function ($order) {
                $divisi = $order->user->divisi ?? null;
                $order->crsd_type = $divisi === 'CRSD 1' ? 'crsd1' : ($divisi === 'CRSD 2' ? 'crsd2' : null);

                $allRestaurants = [];
                $allAreas       = [];

                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant) {
                        $restaurant   = $item->menu->restaurant;
                        $restaurantId = $restaurant->id;
                        if (!isset($allRestaurants[$restaurantId])) {
                            $allRestaurants[$restaurantId] = $restaurant;
                        }

                        if ($restaurant->area) {
                            $areaId = $restaurant->area->id;
                            if (!isset($allAreas[$areaId])) {
                                $allAreas[$areaId] = $restaurant->area;
                            }
                        }
                    }
                }

                $allRestaurants  = array_values($allRestaurants);
                $allAreas        = array_values($allAreas);
                $firstRestaurant = !empty($allRestaurants) ? $allRestaurants[0] : null;
                $firstArea       = !empty($allAreas) ? $allAreas[0] : null;

                $order->restaurant        = $firstRestaurant;
                $order->area              = $firstArea;
                $order->area_name         = $firstArea ? $firstArea->name : 'Multiple Areas';
                $order->area_icon         = $firstArea ? $firstArea->icon : '📍';
                $order->all_restaurants   = $allRestaurants;
                $order->all_areas         = $allAreas;
                $order->restaurants_count = count($allRestaurants);
                $order->areas_count       = count($allAreas);
                $order->items_count       = $order->items->count();
            });

            return response()->json([
                'success' => true,
                'message' => 'Orders retrieved successfully',
                'data'    => $orders
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in getAllOrders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pesanan',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getCRSDOrders(Request $request, $crsdType)
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

            $query = Orders::with(['user', 'items.menu.restaurant.area'])
                ->whereHas('user', function ($q) use ($divisiName) {
                    $q->where('divisi', $divisiName);
                })
                ->orderBy('created_at', 'desc');

            if ($request->has('status') && $request->status !== 'all') {
                $query->where('order_status', $request->status);
            }

            if ($request->has('payment_status') && $request->payment_status !== 'all') {
                $query->where('status', $request->payment_status);
            }

            if ($request->has('date')) {
                $query->whereDate('created_at', $request->date);
            }

            if ($request->has('area_id') && $request->area_id !== 'all') {
                $areaId = $request->area_id;
                $query->whereHas('items.menu.restaurant.area', function ($q) use ($areaId) {
                    $q->where('id', $areaId);
                });
            }

            if ($request->has('restaurant_id') && $request->restaurant_id !== 'all') {
                $restaurantId = $request->restaurant_id;
                $query->whereHas('items.menu.restaurant', function ($q) use ($restaurantId) {
                    $q->where('id', $restaurantId);
                });
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('order_code', 'like', "%{$search}%")
                      ->orWhereHas('user', function ($q2) use ($search) {
                          $q2->where('name', 'like', "%{$search}%")
                             ->orWhere('email', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      })
                      ->orWhereHas('items.menu.restaurant', function ($q3) use ($search) {
                          $q3->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('items.menu.restaurant.area', function ($q4) use ($search) {
                          $q4->where('name', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->get();

            $orders->each(function ($order) use ($crsdType) {
                $order->crsd_type = $crsdType;

                $allRestaurants = [];
                $allAreas       = [];

                foreach ($order->items as $item) {
                    if ($item->menu && $item->menu->restaurant) {
                        $restaurant   = $item->menu->restaurant;
                        $restaurantId = $restaurant->id;
                        if (!isset($allRestaurants[$restaurantId])) {
                            $allRestaurants[$restaurantId] = $restaurant;
                        }

                        if ($restaurant->area) {
                            $areaId = $restaurant->area->id;
                            if (!isset($allAreas[$areaId])) {
                                $allAreas[$areaId] = $restaurant->area;
                            }
                        }
                    }
                }

                $allRestaurants  = array_values($allRestaurants);
                $allAreas        = array_values($allAreas);
                $firstRestaurant = !empty($allRestaurants) ? $allRestaurants[0] : null;
                $firstArea       = !empty($allAreas) ? $allAreas[0] : null;

                $order->restaurant        = $firstRestaurant;
                $order->area              = $firstArea;
                $order->area_name         = $firstArea ? $firstArea->name : 'Multiple Areas';
                $order->area_icon         = $firstArea ? $firstArea->icon : '📍';
                $order->all_restaurants   = $allRestaurants;
                $order->all_areas         = $allAreas;
                $order->restaurants_count = count($allRestaurants);
                $order->areas_count       = count($allAreas);
                $order->items_count       = $order->items->count();
            });

            return response()->json([
                'success'   => true,
                'message'   => 'Orders retrieved successfully for ' . strtoupper($crsdType),
                'crsd_type' => $crsdType,
                'data'      => $orders
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in getCRSDOrders: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data pesanan',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function listUsers(Request $request)
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

            $page    = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search  = $request->get('search', null);
            $status  = $request->get('status', null);

            $query = User::query();

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
                'data'    => $users
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in listUsers: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve users',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function getCRSDUsers(Request $request, $crsdType)
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

            $query = User::where('role', 'user')->where('divisi', $divisiName);

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            $users = $query->paginate($request->get('per_page', 15));

            return response()->json([
                'success'   => true,
                'message'   => "Users retrieved successfully for " . strtoupper($crsdType),
                'crsd_type' => $crsdType,
                'data'      => $users
            ], 200);

        } catch (\Exception $e) {
            Log::error("Get CRSD users error: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data users',
                'error'   => $e->getMessage()
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

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            if ($authUser->role === 'admin' && $user->role !== 'user') {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            if ($authUser->role === 'admin' && $user->role === 'user') {
                $dataAccess  = $this->getUserDataAccess();
                $userDivisi  = $user->divisi;

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
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in showUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user',
                'error'   => $e->getMessage()
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

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

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
                'name'       => 'sometimes|required|string|max:255',
                'email'      => 'sometimes|required|email|unique:users,email,' . $id,
                'phone'      => 'nullable|string|max:20',
                'divisi'     => 'nullable|string|max:255',
                'unit_kerja' => 'nullable|string|max:100',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            if ($request->has('name'))       $user->name       = $request->name;
            if ($request->has('email'))      $user->email      = $request->email;
            if ($request->has('phone'))      $user->phone      = $request->phone;
            if ($request->has('divisi'))     $user->divisi     = $request->divisi;
            if ($request->has('unit_kerja')) $user->unit_kerja = $request->unit_kerja;

            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'User updated successfully',
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in updateUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update user',
                'error'   => $e->getMessage()
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

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

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
                'error'   => $e->getMessage()
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

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

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
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in deactivateUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate user',
                'error'   => $e->getMessage()
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

            if (!$authUser) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

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
                'data'    => $user
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error in activateUser: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate user',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function exportExcel(Request $request)
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

            $startDateInput = $request->get('start_date', now()->subMonth()->toDateString());
            $endDateInput   = $request->get('end_date', now()->toDateString());
            $crsdType       = $request->query('crsd_type');

            try {
                $startDate = Carbon::parse($startDateInput)->startOfDay();
                $endDate   = Carbon::parse($endDateInput)->endOfDay();

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
                ->where('order_status', 'completed')
                ->where('status', 'paid')
                ->orderBy('created_at', 'desc');

            $this->applyCRSDFilterWithModule($query, $crsdType);

            $orders = $query->get();

            if ($orders->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data untuk diekspor'
                ], 404);
            }

            $csvData = [];

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
                    $order->items->map(function ($item) {
                        return $item->menu?->name ?? 'Unknown Product';
                    })->implode(', ')
                ];
            }

            $csvContent = '';
            foreach ($csvData as $row) {
                $csvContent .= implode(',', array_map(function ($value) {
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

    private function errorResponse(string $message, int $statusCode = 400): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message
        ], $statusCode);
    }
}