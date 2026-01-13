<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\User;
use App\Models\Payments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Admin Dashboard
     */
        public function dashboard() {
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
     * Get Statistics
     */
    public function getStatistics()
    {
        try {
            $user = auth()->guard('api')->user();

            if (!in_array($user->role, ['admin', 'superadmin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses'
                ], 403);
            }

            // Statistik orders per hari (7 hari terakhir)
            $ordersPerDay = Orders::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as total')
            )
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();

            // Statistik revenue per hari (7 hari terakhir)
            $revenuePerDay = Payments::select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('sum(amount) as total')
            )
            ->where('status', 'confirmed')
            ->where('created_at', '>=', now()->subDays(7))
            ->groupBy(DB::raw('DATE(created_at)'))
            ->orderBy('date', 'asc')
            ->get();

            // Orders by status
            $ordersByStatus = Orders::select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->get();

            // Top users by orders
            $topUsers = User::withCount('orders')
                ->where('role', 'user')
                ->orderBy('orders_count', 'desc')
                ->limit(5)
                ->get(['id', 'name', 'email', 'orders_count']);

            return response()->json([
                'success' => true,
                'message' => 'Statistics retrieved successfully',
                'data' => [
                    'orders_per_day' => $ordersPerDay,
                    'revenue_per_day' => $revenuePerDay,
                    'orders_by_status' => $ordersByStatus,
                    'top_users' => $topUsers,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik',
                'error' => $e->getMessage()
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
     * List all regular users (admin can view all users)
     */
    public function listUsers(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', null);
            $status = $request->get('status', null);

            $query = User::query();

            // Filter by role - admin hanya bisa lihat regular users
            // superadmin bisa lihat semua
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

            // Admin hanya bisa lihat regular users
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
     * Update user (admin can only update regular users)
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

            // Admin tidak bisa update admin/superadmin
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

            // Update fields
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
     * Delete user (admin can only delete regular users)
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

            // Admin tidak bisa delete admin/superadmin
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

            // Admin tidak bisa deactivate admin/superadmin
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

            // Admin tidak bisa activate admin/superadmin
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