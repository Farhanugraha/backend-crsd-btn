<?php
// SuperAdminController
// File: app/Http/Controllers/Api/SuperAdminController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SuperAdminController extends Controller
{
    /**
     * SuperAdmin Dashboard
     */
    public function dashboard()
    {
        try {
            $stats = [
                'total_users' => User::count(),
                'total_admins' => User::where('role', 'admin')->count(),
                'total_superadmins' => User::where('role', 'superadmin')->count(),
                'total_regular_users' => User::where('role', 'user')->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'SuperAdmin dashboard',
                'data' => $stats
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load dashboard',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all users with all roles
     */
    public function listAllUsers(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 10);

            $users = User::paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'success' => true,
                'message' => 'All users retrieved successfully',
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
     * Change user role (superadmin only)
     */
    public function changeUserRole(Request $request, $id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'role' => 'required|in:user,admin,superadmin'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $oldRole = $user->role;
            $user->role = $request->role;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'User role changed successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'old_role' => $oldRole,
                    'new_role' => $user->role
                ]
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to change user role',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete any user (superadmin only)
     */
    public function deleteUser($id)
    {
        try {
            $user = User::find($id);

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
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
     * Get system settings
     */
    public function getSettings()
    {
        return response()->json([
            'success' => true,
            'message' => 'System settings retrieved',
            'data' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'jwt_secret' => '***hidden***'
            ]
        ], 200);
    }

    /**
     * Update system settings
     */
    public function updateSettings(Request $request)
    {
        // Logic untuk update settings
        return response()->json([
            'success' => true,
            'message' => 'Settings updated successfully'
        ], 200);
    }
}
?>