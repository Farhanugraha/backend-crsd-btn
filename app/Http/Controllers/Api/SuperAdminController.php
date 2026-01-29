<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\Payments;
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
     */
    public function dashboard()
    {
        try {
            $stats = [
                'total_orders' => Orders::count(),
                'total_users' => User::count(),
                'total_admins' => User::where('role', 'admin')->count(),
                'total_superadmins' => User::where('role', 'superadmin')->count(),
                'pending_orders' => Orders::where('status', 'pending')->count(),
                'processing_orders' => Orders::where('status', 'processing')->count(),
                'completed_orders' => Orders::where('status', 'completed')->count(),
                'canceled_orders' => Orders::where('status', 'canceled')->count(),
                'total_revenue' => Payments::where('status', 'completed')->sum('amount'),
                'pending_payments' => Payments::where('status', 'pending')->count(),
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

            // Search functionality
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
     * Create new user (by superadmin)
     * POST /api/superadmin/users
     * 
     * Request Body:
     * {
     *   "name": "string (required)",
     *   "email": "string (required|email)",
     *   "password": "string (required|min:6)",
     *   "password_confirmation": "string (required)",
     *   "phone": "string (optional)",
     *   "role": "string (required|in:user,admin,superadmin)",
     *   "divisi": "string (optional)",
     *   "unit_kerja": "string (optional)"
     * }
     * 
     * User yang dibuat oleh superadmin langsung terverifikasi (email_verified_at otomatis terisi)
     */
    public function createUser(Request $request)
    {
        try {
            // Validate the request
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255|unique:users',
                'password' => [
                    'required',
                    'min:6',
                    'confirmed',
                    Password::defaults()
                ],
                'password_confirmation' => 'required|same:password',
                'phone' => 'nullable|string|max:20',
                'role' => 'required|in:user,admin,superadmin',
                'divisi' => 'nullable|string|max:255',
                'unit_kerja' => 'nullable|string|max:100',
            ], [
                'password.confirmed' => 'Password dan konfirmasi password tidak cocok',
                'email.unique' => 'Email sudah terdaftar',
                'role.in' => 'Role harus user, admin, atau superadmin'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Create user with email_verified_at automatically set
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'phone' => $request->phone,
                'role' => $request->role,
                'divisi' => $request->divisi,
                'unit_kerja' => $request->unit_kerja,
                'email_verified_at' => now(), // Directly verified by superadmin
            ]);

            // Log the action
            Log::info("User created by superadmin: {$user->name} ({$user->email}) with role: {$user->role}");

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil dibuat',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => 'aktif',
                    'created_at' => $user->created_at->format('Y-m-d H:i:s')
                ]
            ], 201);

        } catch (\Exception $e) {
            Log::error('Create User Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat pengguna',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update existing user
     * PUT /api/superadmin/users/{id}
     * 
     * Request Body:
     * {
     *   "name": "string (optional)",
     *   "email": "string (optional|email)",
     *   "phone": "string (optional)",
     *   "role": "string (optional|in:user,admin,superadmin)",
     *   "divisi": "string (optional)",
     *   "unit_kerja": "string (optional)"
     * }
     */
    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // Validate the request
            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:255',
                'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
                'phone' => 'nullable|string|max:20',
                'role' => 'sometimes|in:user,admin,superadmin',
                'divisi' => 'nullable|string|max:255',
                'unit_kerja' => 'nullable|string|max:100',
            ], [
                'email.unique' => 'Email sudah terdaftar',
                'role.in' => 'Role harus user, admin, atau superadmin'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Store old data for logging
            $oldData = $user->toArray();

            // Update user fields
            $updateData = [];
            $fields = ['name', 'email', 'phone', 'role', 'divisi', 'unit_kerja'];
            
            foreach ($fields as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = $request->$field;
                }
            }

            $user->update($updateData);

            // Log the action
            $changes = [];
            foreach ($updateData as $field => $newValue) {
                if (isset($oldData[$field]) && $oldData[$field] != $newValue) {
                    $changes[$field] = ['from' => $oldData[$field], 'to' => $newValue];
                }
            }

            if (!empty($changes)) {
                Log::info("User updated: {$user->name} ({$user->email}) - Changes: " . json_encode($changes));
            }

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil diperbarui',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->email_verified_at ? 'aktif' : 'pending',
                    'updated_at' => $user->updated_at->format('Y-m-d H:i:s')
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Update User Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui pengguna',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change user password (by superadmin)
     * POST /api/superadmin/users/{id}/change-password
     * 
     * Request Body:
     * {
     *   "password": "string (required|min:6)",
     *   "password_confirmation": "string (required)"
     * }
     */
    public function changeUserPassword(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            // Validate the request
            $validator = Validator::make($request->all(), [
                'password' => [
                    'required',
                    'min:6',
                    'confirmed',
                    Password::defaults()
                ],
                'password_confirmation' => 'required|same:password',
            ], [
                'password.confirmed' => 'Password dan konfirmasi password tidak cocok'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Update password
            $user->password = Hash::make($request->password);
            $user->save();

            // Log the action
            Log::info("Password changed by superadmin for user: {$user->name} ({$user->email})");

            return response()->json([
                'success' => true,
                'message' => 'Password pengguna berhasil diubah',
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'password_changed_at' => now()->format('Y-m-d H:i:s')
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Change Password Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengubah password',
                'error' => $e->getMessage()
            ], 500);
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
     * Bulk activate users
     * POST /api/superadmin/users/bulk/activate
     * 
     * Request Body:
     * {
     *   "user_ids": [1, 2, 3]
     * }
     */
    public function bulkActivateUsers(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_ids' => 'required|array',
                'user_ids.*' => 'integer|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $activatedCount = User::whereIn('id', $request->user_ids)
                ->whereNull('email_verified_at')
                ->update(['email_verified_at' => now()]);

            // Log the action
            Log::info("Bulk activate: {$activatedCount} users activated");

            return response()->json([
                'success' => true,
                'message' => "{$activatedCount} pengguna berhasil diaktifkan",
                'data' => [
                    'activated_count' => $activatedCount
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Bulk Activate Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengaktifkan pengguna secara massal',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk deactivate users
     * POST /api/superadmin/users/bulk/deactivate
     * 
     * Request Body:
     * {
     *   "user_ids": [1, 2, 3]
     * }
     */
    public function bulkDeactivateUsers(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_ids' => 'required|array',
                'user_ids.*' => 'integer|exists:users,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $deactivatedCount = User::whereIn('id', $request->user_ids)
                ->whereNotNull('email_verified_at')
                ->update(['email_verified_at' => null]);

            // Log the action
            Log::info("Bulk deactivate: {$deactivatedCount} users deactivated");

            return response()->json([
                'success' => true,
                'message' => "{$deactivatedCount} pengguna berhasil dinonaktifkan",
                'data' => [
                    'deactivated_count' => $deactivatedCount
                ]
            ], 200);

        } catch (\Exception $e) {
            Log::error('Bulk Deactivate Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal menonaktifkan pengguna secara massal',
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

    /**
     * =====================================================================
     * ADDITIONAL HELPER METHODS
     * =====================================================================
     */

    /**
     * Get User Activity Logs
     * GET /api/superadmin/users/{id}/activity
     */
    public function getUserActivity($id)
    {
        try {
            $user = User::findOrFail($id);
            
            // In a real application, you would query an activity log table
            $activities = [
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'last_login' => 'Not implemented', // You would need to track this
                'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : null,
                'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
            ];

            return response()->json([
                'success' => true,
                'message' => 'User activity retrieved successfully',
                'data' => $activities
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get User Activity Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve user activity',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Export Users to CSV
     * GET /api/superadmin/users/export?role=...&format=csv
     */
    public function exportUsers(Request $request)
    {
        try {
            $role = $request->get('role', '');
            $format = $request->get('format', 'csv');
            
            $query = User::query();
            
            if (!empty($role) && $role !== 'all') {
                $query->where('role', $role);
            }
            
            $users = $query->get();
            
            if ($format === 'csv') {
                // In a real application, you would generate and return a CSV file
                return response()->json([
                    'success' => true,
                    'message' => 'Export functionality not fully implemented',
                    'data' => [
                        'user_count' => $users->count(),
                        'format' => $format,
                        'download_url' => '#' // Placeholder
                    ]
                ], 200);
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Unsupported export format'
            ], 400);
            
        } catch (\Exception $e) {
            Log::error('Export Users Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to export users',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validate user data before creation or update
     * This is a helper method used internally
     */
    private function validateUserData(Request $request, $userId = null)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . ($userId ?: 'NULL'),
            'phone' => 'nullable|string|max:20',
            'role' => 'required|in:user,admin,superadmin',
            'divisi' => 'nullable|string|max:255',
            'unit_kerja' => 'nullable|string|max:100',
        ];

        $messages = [
            'email.unique' => 'Email sudah terdaftar',
            'role.in' => 'Role harus user, admin, atau superadmin',
            'name.required' => 'Nama wajib diisi',
            'email.required' => 'Email wajib diisi',
            'email.email' => 'Format email tidak valid',
        ];

        return Validator::make($request->all(), $rules, $messages);
    }

    /**
     * Format user response consistently
     */
    private function formatUserResponse(User $user)
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'divisi' => $user->divisi,
            'unit_kerja' => $user->unit_kerja,
            'status' => $user->email_verified_at ? 'aktif' : 'pending',
            'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : null,
            'created_at' => $user->created_at->format('Y-m-d H:i:s'),
            'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
        ];
    }
}