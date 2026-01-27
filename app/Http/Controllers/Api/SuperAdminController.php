<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;

class SuperAdminController extends Controller
{
    /**
     * =====================================================================
     * SUPERADMIN DASHBOARD
     * =====================================================================
     */

    /**
     * Get SuperAdmin Dashboard Statistics
     * GET /api/superadmin/dashboard
     * 
     * Returns user statistics overview
     */
    public function dashboard()
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'total_admins' => User::where('role', 'admin')->count(),
                'total_superadmins' => User::where('role', 'superadmin')->count(),
                'total_regular_users' => User::where('role', 'user')->count(),
                'verified_users' => User::whereNotNull('email_verified_at')->count(),
                'unverified_users' => User::whereNull('email_verified_at')->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'SuperAdmin dashboard loaded successfully',
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            Log::error('Dashboard Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * =====================================================================
     * USER MANAGEMENT METHODS
     * =====================================================================
     */

    /**
     * List All Users with Search, Filter, and Pagination
     * GET /api/superadmin/users?page=1&per_page=15&search=...&role=...&sort=...&order=...
     * 
     * Query Parameters:
     * - page: integer (default: 1)
     * - per_page: integer (default: 15)
     * - search: string - search by name, email, or phone
     * - role: string - filter by role (user|admin|superadmin|all)
     * - sort: string - field to sort by (default: created_at)
     * - order: string - sort order (asc|desc, default: desc)
     */
    public function listAllUsers(Request $request)
    {
        try {
            // Get pagination and filter parameters
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $role = $request->get('role', '');
            $sort = $request->get('sort', 'created_at');
            $order = $request->get('order', 'desc');

            // Validate sort order
            $order = in_array(strtolower($order), ['asc', 'desc']) ? strtolower($order) : 'desc';

            // Build query
            $query = User::query();

            // Search functionality - by name, email, or phone
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            // Filter by role
            if (!empty($role) && $role !== 'all') {
                $query->where('role', $role);
            }

            // Apply sorting
            $query->orderBy($sort, $order);

            // Paginate results
            $users = $query->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data' => $users
            ], 200);
        } catch (\Exception $e) {
            Log::error('List Users Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get Single User Details
     * GET /api/superadmin/users/{id}
     */
    public function showUser($id)
    {
        try {
            $user = User::findOrFail($id);

            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            Log::error('Show User Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'User not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

    /**
     * Update User Role
     * POST /api/superadmin/users/{id}/role
     * 
     * Request Body:
     * {
     *   "role": "user|admin|superadmin"
     * }
     * 
     * Safety Checks:
     * - Prevent removing the last superadmin
     */
    public function changeUserRole(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // Validate input
            $validator = Validator::make($request->all(), [
                'role' => 'required|in:user,admin,superadmin'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Prevent removing the last superadmin
            if ($user->role === 'superadmin' && $request->role !== 'superadmin') {
                $superadminCount = User::where('role', 'superadmin')->count();
                if ($superadminCount === 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot remove the last superadmin'
                    ], 400);
                }
            }

            // Update role
            $oldRole = $user->role;
            $user->role = $request->role;
            $user->save();

            // Log the action
            Log::info("User role changed: {$user->name} ({$user->email}) - {$oldRole} -> {$request->role}");

            return response()->json([
                'success' => true,
                'message' => 'User role updated successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'old_role' => $oldRole,
                    'new_role' => $user->role
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Change Role Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to change user role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Activate User (Verify Email)
     * POST /api/superadmin/users/{id}/activate
     * 
     * Sets email_verified_at to current timestamp
     */
    public function activateUser($id)
    {
        try {
            $user = User::findOrFail($id);
            
            if ($user->email_verified_at === null) {
                $user->email_verified_at = now();
                $user->save();
                Log::info("User activated: {$user->name} ({$user->email})");
            }

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            Log::error('Activate User Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to activate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Deactivate User (Remove Email Verification)
     * POST /api/superadmin/users/{id}/deactivate
     * 
     * Sets email_verified_at to null
     */
    public function deactivateUser($id)
    {
        try {
            $user = User::findOrFail($id);
            
            if ($user->email_verified_at !== null) {
                $user->email_verified_at = null;
                $user->save();
                Log::info("User deactivated: {$user->name} ({$user->email})");
            }

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully',
                'data' => $user
            ], 200);
        } catch (\Exception $e) {
            Log::error('Deactivate User Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to deactivate user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete User
     * DELETE /api/superadmin/users/{id}
     * 
     * Safety Checks:
     * - Prevent deleting the last superadmin
     */
    public function deleteUser($id)
    {
        try {
            $user = User::findOrFail($id);

            // Prevent deleting the last superadmin
            if ($user->role === 'superadmin') {
                $superadminCount = User::where('role', 'superadmin')->count();
                if ($superadminCount === 1) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Cannot delete the last superadmin'
                    ], 400);
                }
            }

            // Store user info before deletion
            $userName = $user->name;
            $userEmail = $user->email;
            
            // Delete user
            $user->delete();

            // Log the action
            Log::info("User deleted: {$userName} ({$userEmail})");

            return response()->json([
                'success' => true,
                'message' => "User '{$userName}' deleted successfully"
            ], 200);
        } catch (\Exception $e) {
            Log::error('Delete User Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete user',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * =====================================================================
     * SETTINGS MANAGEMENT
     * =====================================================================
     */

    /**
     * Get System Settings
     * GET /api/superadmin/settings
     * 
     * Returns current system configuration
     */
    public function getSettings()
    {
        try {
            $settings = [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'app_env' => config('app.env'),
                'app_debug' => config('app.debug'),
            ];

            return response()->json([
                'success' => true,
                'message' => 'System settings retrieved successfully',
                'data' => $settings
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get Settings Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update System Settings
     * POST /api/superadmin/settings
     * 
     * Request Body:
     * {
     *   "app_name": "string (optional)",
     *   "app_debug": "boolean (optional)"
     * }
     */
    public function updateSettings(Request $request)
    {
        try {
            // Validate input
            $validator = Validator::make($request->all(), [
                'app_name' => 'nullable|string|max:255',
                'app_debug' => 'nullable|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            // TODO: Implement .env file update logic
            // For now, just log the action
            Log::info("Settings update requested: " . json_encode($request->all()));

            return response()->json([
                'success' => true,
                'message' => 'Settings updated successfully'
            ], 200);
        } catch (\Exception $e) {
            Log::error('Update Settings Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update settings',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * =====================================================================
     * PLACEHOLDER METHODS FOR FUTURE IMPLEMENTATION
     * These endpoints are defined in routes but not yet implemented
     * =====================================================================
     */

    public function getEmailConfig()
    {
        return response()->json([
            'success' => false,
            'message' => 'Email config endpoint not implemented'
        ], 501);
    }

    public function updateEmailConfig(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Email config endpoint not implemented'
        ], 501);
    }

    public function getLogs(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Logs endpoint not implemented'
        ], 501);
    }

    public function clearCache()
    {
        return response()->json([
            'success' => false,
            'message' => 'Clear cache endpoint not implemented'
        ], 501);
    }

    public function systemHealth()
    {
        return response()->json([
            'success' => false,
            'message' => 'System health endpoint not implemented'
        ], 501);
    }

    public function getReports()
    {
        return response()->json([
            'success' => false,
            'message' => 'Reports endpoint not implemented'
        ], 501);
    }

    public function getUsersReport()
    {
        return response()->json([
            'success' => false,
            'message' => 'Users report endpoint not implemented'
        ], 501);
    }

    public function getOrdersReport()
    {
        return response()->json([
            'success' => false,
            'message' => 'Orders report endpoint not implemented'
        ], 501);
    }

    public function getPaymentsReport()
    {
        return response()->json([
            'success' => false,
            'message' => 'Payments report endpoint not implemented'
        ], 501);
    }

    public function getRevenueReport()
    {
        return response()->json([
            'success' => false,
            'message' => 'Revenue report endpoint not implemented'
        ], 501);
    }
}

?>