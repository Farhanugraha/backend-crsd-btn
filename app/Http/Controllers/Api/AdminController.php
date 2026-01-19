<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\User;
use App\Models\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Statistics with Chart Data
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
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Reports
     */
    public function getReports()
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            $report = [
                'total_orders' => Orders::count(),
                'orders_by_status' => Orders::select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')
                    ->get(),
                'payment_summary' => Payments::select(
                    'status', 
                    DB::raw('count(*) as total'), 
                    DB::raw('sum(amount) as total_amount')
                )
                    ->groupBy('status')
                    ->get(),
                'user_statistics' => [
                    'total_users' => User::where('role', 'user')->count(),
                    'total_admins' => User::where('role', 'admin')->count(),
                    'active_users' => User::where('is_active', 1)->where('role', 'user')->count(),
                ],
                'top_users' => User::withCount('orders')
                    ->where('role', 'user')
                    ->orderBy('orders_count', 'desc')
                    ->limit(10)
                    ->get(['id', 'name', 'email', 'orders_count']),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Reports retrieved successfully',
                'data' => $report
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil laporan',
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

            // Filter by role
            $user = auth()->guard('api')->user();
            if ($user->role === 'admin') {
                $query->where('role', 'user');
            }

            // Search by name or email
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            // Filter by active status
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