<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends Controller
{
    private const CACHE_DASHBOARD      = 'superadmin_dashboard_stats';
    private const CACHE_ADMINS_STATS   = 'superadmin_admins_list_stats';
    private const CACHE_DURATION       = 300;
    private const CACHE_DURATION_SHORT = 60;

    public function dashboard()
    {
        try {
            $stats = Cache::remember(self::CACHE_DASHBOARD, self::CACHE_DURATION_SHORT, function () {
                $orderStats = Orders::selectRaw('
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_orders,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() AND order_status = "processing" THEN 1 ELSE 0 END) as today_processing,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() AND order_status = "completed"  THEN 1 ELSE 0 END) as today_completed,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() AND order_status = "canceled"   THEN 1 ELSE 0 END) as today_canceled
                ')->first();

                $userStats = User::selectRaw('
                    COUNT(*) as total_users,
                    SUM(CASE WHEN role = "admin"      THEN 1 ELSE 0 END) as total_admins,
                    SUM(CASE WHEN role = "superadmin" THEN 1 ELSE 0 END) as total_superadmins
                ')->first();

                $crsd1Admins = User::where('role', 'admin')
                    ->where(fn($q) => $q->whereJsonContains('data_access', 'crsd1')
                                        ->orWhere('data_access', 'like', '%"crsd1"%'))
                    ->count();

                $crsd2Admins = User::where('role', 'admin')
                    ->where(fn($q) => $q->whereJsonContains('data_access', 'crsd2')
                                        ->orWhere('data_access', 'like', '%"crsd2"%'))
                    ->count();

                return [
                    'total_orders'            => (int) ($orderStats->total_orders    ?? 0),
                    'today_orders'            => (int) ($orderStats->today_orders    ?? 0),
                    'total_users'             => (int) ($userStats->total_users      ?? 0),
                    'total_admins'            => (int) ($userStats->total_admins     ?? 0),
                    'total_superadmins'       => (int) ($userStats->total_superadmins ?? 0),
                    'total_crsd1_admins'      => $crsd1Admins,
                    'total_crsd2_admins'      => $crsd2Admins,
                    'today_processing_orders' => (int) ($orderStats->today_processing ?? 0),
                    'today_completed_orders'  => (int) ($orderStats->today_completed  ?? 0),
                    'today_canceled_orders'   => (int) ($orderStats->today_canceled   ?? 0),
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'SuperAdmin dashboard loaded successfully',
                'data'    => $stats,
            ]);

        } catch (\Exception $e) {
            Log::error('Dashboard Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to load dashboard', 'error' => $e->getMessage()], 500);
        }
    }

    public function listAllUsers(Request $request)
    {
        try {
            $perPage    = (int) $request->get('per_page', 15);
            $search     = trim($request->get('search', ''));
            $role       = trim($request->get('role', ''));
            $dataAccess = trim($request->get('data_access', ''));
            $sort       = $request->get('sort', 'created_at');
            $order      = in_array(strtolower($request->get('order', 'desc')), ['asc', 'desc'])
                            ? strtolower($request->get('order', 'desc'))
                            : 'desc';

            $query = User::query();

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name',  'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            if ($role !== '' && $role !== 'all') {
                $query->where('role', $role);
            }

            if ($dataAccess !== '' && $dataAccess !== 'all') {
                $this->applyDataAccessFilter($query, $dataAccess);
            }

            $users = $query
                ->orderBy($sort, $order)
                ->paginate($perPage)
                ->through(fn($user) => $this->formatUserResponse($user));

            return response()->json([
                'success' => true,
                'message' => 'Users retrieved successfully',
                'data'    => $users,
            ]);

        } catch (\Exception $e) {
            Log::error('List Users Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to retrieve users', 'error' => $e->getMessage()], 500);
        }
    }

    public function showUser($id)
    {
        try {
            $user = User::findOrFail($id);
            return response()->json([
                'success' => true,
                'message' => 'User details retrieved successfully',
                'data'    => $this->formatUserResponse($user),
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'User not found', 'error' => $e->getMessage()], 404);
        }
    }

    public function createUser(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'name'                  => 'required|string|max:255',
                'email'                 => 'required|email|max:255|unique:users',
                'password'              => ['required', 'min:6', 'confirmed', Password::defaults()],
                'password_confirmation' => 'required|same:password',
                'phone'                 => 'nullable|string|max:20',
                'role'                  => 'required|in:user,admin,superadmin',
                'divisi'                => 'nullable|string|max:255',
                'unit_kerja'            => 'nullable|string|max:100',
                'data_access'           => 'nullable|array',
                'data_access.*'         => 'string|in:crsd1,crsd2',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
            }

            if ($request->role === 'admin' && empty($request->data_access)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data access wajib diisi untuk role admin',
                    'errors'  => ['data_access' => ['Data access wajib diisi untuk admin']],
                ], 422);
            }

            DB::beginTransaction();

            $userData = [
                'name'              => $request->name,
                'email'             => $request->email,
                'password'          => Hash::make($request->password),
                'phone'             => $request->phone,
                'role'              => $request->role,
                'divisi'            => $request->divisi,
                'unit_kerja'        => $request->unit_kerja,
                'email_verified_at' => now(),
            ];

            if ($request->role === 'admin' && !empty($request->data_access)) {
                $userData['data_access'] = $request->data_access;
            }

            $user = User::create($userData);
            DB::commit();

            $this->clearUserCache();
            Log::info("User created: {$user->name} ({$user->email})");

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil dibuat',
                'data'    => $this->formatUserResponse($user),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Create User Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal membuat pengguna', 'error' => $e->getMessage()], 500);
        }
    }

    public function updateUser(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name'        => 'sometimes|required|string|max:255',
                'email'       => 'sometimes|required|email|max:255|unique:users,email,' . $user->id,
                'phone'       => 'nullable|string|max:20',
                'role'        => 'sometimes|required|in:user,admin,superadmin',
                'divisi'      => 'nullable|string|max:255',
                'unit_kerja'  => 'nullable|string|max:100',
                'data_access' => 'nullable',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $updateData = [];
            foreach (['name', 'email', 'phone', 'role', 'divisi', 'unit_kerja'] as $field) {
                if ($request->has($field)) {
                    $updateData[$field] = is_string($request->$field) ? trim($request->$field) : $request->$field;
                }
            }

            if ($request->has('data_access')) {
                $newRole    = $request->has('role') ? $request->role : $user->role;
                $rawAccess  = $request->data_access;

                if (is_array($rawAccess)) {
                    $dataAccess = $rawAccess;
                } elseif (is_string($rawAccess)) {
                    $decoded    = json_decode($rawAccess, true);
                    $dataAccess = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
                } else {
                    $dataAccess = [];
                }

                $updateData['data_access'] = ($newRole === 'admin' && !empty($dataAccess)) ? $dataAccess : null;
            }

            $user->update($updateData);
            DB::commit();
            $this->clearUserCache();

            return response()->json([
                'success' => true,
                'message' => 'Pengguna berhasil diperbarui',
                'data'    => $this->formatUserResponse($user->fresh()),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Update User Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memperbarui pengguna', 'error' => $e->getMessage()], 500);
        }
    }

    public function activateUser($id)
    {
        try {
            $user = User::findOrFail($id);

            $user->email_verified_at = now();
            $user->save();

            $this->clearUserCache();
            Log::info("User activated: {$user->name} ({$user->email})");

            return response()->json([
                'success' => true,
                'message' => 'User activated successfully',
                'data'    => $this->formatUserResponse($user),
            ]);

        } catch (\Exception $e) {
            Log::error('Activate User Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to activate user', 'error' => $e->getMessage()], 500);
        }
    }

    public function deactivateUser($id)
    {
        try {
            $user = User::findOrFail($id);

            $user->email_verified_at = null;
            $user->save();

            $this->clearUserCache();
            Log::info("User deactivated: {$user->name} ({$user->email})");

            return response()->json([
                'success' => true,
                'message' => 'User deactivated successfully',
                'data'    => $this->formatUserResponse($user),
            ]);

        } catch (\Exception $e) {
            Log::error('Deactivate User Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to deactivate user', 'error' => $e->getMessage()], 500);
        }
    }

    public function deleteUser($id)
    {
        try {
            $user = User::findOrFail($id);

            if ($user->role === 'superadmin') {
                $superadminCount = User::where('role', 'superadmin')->count();
                if ($superadminCount === 1) {
                    return response()->json(['success' => false, 'message' => 'Cannot delete the last superadmin'], 400);
                }
            }

            DB::beginTransaction();
            $userInfo = $this->formatUserResponse($user);
            $user->delete();
            DB::commit();

            $this->clearUserCache();
            Log::info("User deleted: {$userInfo['name']} ({$userInfo['email']})");

            return response()->json([
                'success'      => true,
                'message'      => "User '{$userInfo['name']}' deleted successfully",
                'deleted_user' => $userInfo,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Delete User Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to delete user', 'error' => $e->getMessage()], 500);
        }
    }

    public function bulkActivateUsers(Request $request)   { return $this->bulkUpdateUsers($request, true); }
    public function bulkDeactivateUsers(Request $request) { return $this->bulkUpdateUsers($request, false); }

    private function bulkUpdateUsers(Request $request, bool $activate)
    {
        try {
            $validator = Validator::make($request->all(), [
                'user_ids'   => 'required|array|min:1|max:100',
                'user_ids.*' => 'integer|exists:users,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
            }

            DB::beginTransaction();

            $updatedCount = User::whereIn('id', $request->user_ids)->update([
                'email_verified_at' => $activate ? now() : null,
            ]);

            DB::commit();
            $this->clearUserCache();

            $action = $activate ? 'diaktifkan' : 'dinonaktifkan';
            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} pengguna berhasil {$action}",
                'data'    => ['updated_count' => $updatedCount],
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bulk Update Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal memperbarui pengguna secara massal', 'error' => $e->getMessage()], 500);
        }
    }

    public function setDataAccess(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            if ($user->role !== 'admin') {
                return response()->json(['success' => false, 'message' => 'Data access hanya dapat diatur untuk user dengan role admin'], 400);
            }

            $validator = Validator::make($request->all(), [
                'data_access'   => 'required|array|min:1',
                'data_access.*' => 'string|in:crsd1,crsd2',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
            }

            $user->data_access = $request->data_access;
            $user->save();
            $this->clearUserCache();

            $parsedAccess = $this->parseDataAccess($user->fresh()->data_access);

            Log::info("Data access updated for admin: {$user->name} ({$user->email})");

            return response()->json([
                'success' => true,
                'message' => 'Data access berhasil diatur',
                'data'    => [
                    'user_id'        => $user->id,
                    'user_name'      => $user->name,
                    'user_email'     => $user->email,
                    'role'           => $user->role,
                    'data_access'    => $parsedAccess,
                    'has_all_access' => in_array('crsd1', $parsedAccess) && in_array('crsd2', $parsedAccess),
                    'updated_at'     => $user->updated_at->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Set Data Access Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengatur data access', 'error' => $e->getMessage()], 500);
        }
    }

    public function getDataAccess($id)
    {
        try {
            $user       = User::findOrFail($id);
            $dataAccess = $this->parseDataAccess($user->data_access);

            return response()->json([
                'success' => true,
                'message' => 'Data access retrieved successfully',
                'data'    => [
                    'user_id'        => $user->id,
                    'user_name'      => $user->name,
                    'user_email'     => $user->email,
                    'role'           => $user->role,
                    'data_access'    => $dataAccess,
                    'has_all_access' => in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Data Access Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengambil data access', 'error' => $e->getMessage()], 500);
        }
    }

    public function listAdminsWithAccess(Request $request)
    {
        try {
            $perPage    = (int) $request->get('per_page', 15);
            $search     = trim($request->get('search', ''));
            $dataAccess = trim($request->get('data_access', ''));
            $sort       = $request->get('sort', 'created_at');
            $order      = in_array(strtolower($request->get('order', 'desc')), ['asc', 'desc'])
                            ? strtolower($request->get('order', 'desc'))
                            : 'desc';

            $stats = Cache::remember(self::CACHE_ADMINS_STATS, self::CACHE_DURATION, fn() => $this->getAdminStatistics());

            $query = User::where('role', 'admin');

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->where('name',  'LIKE', "%{$search}%")
                      ->orWhere('email', 'LIKE', "%{$search}%")
                      ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            if ($dataAccess !== '') {
                $this->applyDataAccessFilter($query, $dataAccess);
            }

            $admins = $query->orderBy($sort, $order)
                ->paginate($perPage)
                ->through(fn($admin) => $this->formatUserResponse($admin));

            return response()->json([
                'success' => true,
                'message' => 'Admins retrieved successfully',
                'data'    => ['admins' => $admins, 'statistics' => $stats],
            ]);

        } catch (\Exception $e) {
            Log::error('List Admins Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to retrieve admins', 'error' => $e->getMessage()], 500);
        }
    }

    public function checkUserAccess(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), ['data_type' => 'required|string|in:crsd1,crsd2']);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
            }

            $dataType   = $request->data_type;
            $dataAccess = $this->parseDataAccess($user->data_access);

            return response()->json([
                'success' => true,
                'message' => 'Access check completed',
                'data'    => [
                    'user_id'     => $user->id,
                    'user_name'   => $user->name,
                    'user_email'  => $user->email,
                    'user_role'   => $user->role,
                    'data_type'   => $dataType,
                    'has_access'  => in_array($dataType, $dataAccess),
                    'data_access' => $dataAccess,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Check User Access Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to check user access', 'error' => $e->getMessage()], 500);
        }
    }

    public function getDataTypes()
    {
        return response()->json([
            'success' => true,
            'message' => 'Data types retrieved successfully',
            'data'    => [
                ['value' => 'crsd1', 'label' => 'CRSD 1', 'description' => 'Customer Relationship System Data 1'],
                ['value' => 'crsd2', 'label' => 'CRSD 2', 'description' => 'Customer Relationship System Data 2'],
            ],
        ]);
    }

    public function changeUserPassword(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'password'              => ['required', 'min:6', 'confirmed', Password::defaults()],
                'password_confirmation' => 'required|same:password',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validasi gagal', 'errors' => $validator->errors()], 422);
            }

            $user->password = Hash::make($request->password);
            $user->save();

            Log::info("Password changed by superadmin for user: {$user->name} ({$user->email})");

            return response()->json([
                'success' => true,
                'message' => 'Password pengguna berhasil diubah',
                'data'    => [
                    'id'                  => $user->id,
                    'name'                => $user->name,
                    'email'               => $user->email,
                    'password_changed_at' => now()->format('Y-m-d H:i:s'),
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Change Password Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Gagal mengubah password', 'error' => $e->getMessage()], 500);
        }
    }

    public function changeUserRole(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), ['role' => 'required|in:user,admin,superadmin']);
            if ($validator->fails()) {
                return response()->json(['success' => false, 'message' => 'Validation failed', 'errors' => $validator->errors()], 422);
            }

            if ($user->role === 'superadmin' && $request->role !== 'superadmin') {
                if (User::where('role', 'superadmin')->count() === 1) {
                    return response()->json(['success' => false, 'message' => 'Cannot remove the last superadmin'], 400);
                }
            }

            $updateData = ['role' => $request->role];

            if ($request->role !== 'admin') {
                $updateData['data_access'] = null;
            } elseif (empty($user->data_access)) {
                return response()->json([
                    'success'              => false,
                    'message'              => 'Data access is required for admin role. Please set data_access first.',
                    'requires_data_access' => true,
                ], 422);
            }

            $oldRole = $user->role;
            $user->update($updateData);
            $this->clearUserCache();

            Log::info("User role changed: {$user->name} ({$user->email}) - {$oldRole} -> {$request->role}");

            return response()->json([
                'success' => true,
                'message' => 'User role updated successfully',
                'data'    => [
                    'user_id'     => $user->id,
                    'user_name'   => $user->name,
                    'user_email'  => $user->email,
                    'old_role'    => $oldRole,
                    'new_role'    => $user->role,
                    'data_access' => $this->parseDataAccess($user->data_access),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Change Role Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to change user role', 'error' => $e->getMessage()], 500);
        }
    }

    public function getUserActivity($id)
    {
        try {
            $user       = User::findOrFail($id);
            $dataAccess = $this->parseDataAccess($user->data_access);

            return response()->json([
                'success' => true,
                'message' => 'User activity retrieved successfully',
                'data'    => [
                    'created_at'          => $user->created_at->format('Y-m-d H:i:s'),
                    'last_login'          => 'Not implemented',
                    'email_verified_at'   => $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : null,
                    'updated_at'          => $user->updated_at->format('Y-m-d H:i:s'),
                    'role_changes'        => [],
                    'data_access_changes' => $dataAccess,
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get User Activity Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to retrieve user activity', 'error' => $e->getMessage()], 500);
        }
    }

    public function exportUsers(Request $request)
    {
        try {
            $role       = $request->get('role', '');
            $dataAccess = $request->get('data_access', '');
            $format     = $request->get('format', 'csv');

            if ($format !== 'csv') {
                return response()->json(['success' => false, 'message' => 'Unsupported export format'], 400);
            }

            $query = User::query();

            if (!empty($role) && $role !== 'all') {
                $query->where('role', $role);
            }

            if (!empty($dataAccess) && $dataAccess !== 'all') {
                $this->applyDataAccessFilter($query, $dataAccess);
            }

            $users = $query->orderBy('created_at')->cursor();

            $filename = 'users_export_' . date('Y-m-d_His') . '.csv';
            $headers  = [
                'Content-Type'        => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Pragma'              => 'no-cache',
                'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
                'Expires'             => '0',
            ];

            return response()->stream(function () use ($users) {
                $handle = fopen('php://output', 'w');
                fputs($handle, "\xEF\xBB\xBF");
                fputcsv($handle, ['ID', 'Nama', 'Email', 'Role', 'Divisi', 'Unit Kerja', 'CRSD 1', 'CRSD 2', 'Status', 'Dibuat']);

                foreach ($users as $user) {
                    $da = $this->parseDataAccess($user->data_access);
                    fputcsv($handle, [
                        $user->id,
                        $user->name,
                        $user->email,
                        $user->role,
                        $user->divisi    ?? '-',
                        $user->unit_kerja ?? '-',
                        in_array('crsd1', $da) ? 'Ya' : 'Tidak',
                        in_array('crsd2', $da) ? 'Ya' : 'Tidak',
                        $user->email_verified_at ? 'Aktif' : 'Pending',
                        $user->created_at->format('Y-m-d H:i'),
                    ]);
                }
                fclose($handle);
            }, 200, $headers);

        } catch (\Exception $e) {
            Log::error('Export Users Error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Failed to export users', 'error' => $e->getMessage()], 500);
        }
    }

    public function getSettings()          { return $this->notImplemented(); }
    public function updateSettings()       { return $this->notImplemented(); }
    public function getEmailConfig()       { return $this->notImplemented(); }
    public function updateEmailConfig()    { return $this->notImplemented(); }
    public function getLogs()              { return $this->notImplemented(); }
    public function clearCache()           { return $this->notImplemented(); }
    public function systemHealth()         { return $this->notImplemented(); }
    public function getReports()           { return $this->notImplemented(); }
    public function getUsersReport()       { return $this->notImplemented(); }
    public function getOrdersReport()      { return $this->notImplemented(); }
    public function getPaymentsReport()    { return $this->notImplemented(); }
    public function getRevenueReport()     { return $this->notImplemented(); }

    private function notImplemented()
    {
        return response()->json(['success' => false, 'message' => 'Endpoint not implemented'], 501);
    }

    private function applyDataAccessFilter($query, string $dataAccess)
    {
        if ($dataAccess === 'has_access') {
            return $query->whereNotNull('data_access')
                ->where('data_access', '!=', '')
                ->where('data_access', '!=', '[]')
                ->where('data_access', '!=', 'null');
        }

        if ($dataAccess === 'no_access') {
            return $query->where(function ($q) {
                $q->whereNull('data_access')
                  ->orWhere('data_access', '')
                  ->orWhere('data_access', '[]')
                  ->orWhere('data_access', 'null');
            });
        }

        return $query->where(function ($q) use ($dataAccess) {
            $q->whereJsonContains('data_access', $dataAccess)
              ->orWhere('data_access', 'like', '%"' . $dataAccess . '"%');
        });
    }

    private function getAdminStatistics(): array
    {
        $stats = User::where('role', 'admin')
            ->selectRaw('
                COUNT(*) as total_admins,
                SUM(CASE WHEN JSON_CONTAINS(data_access, \'"crsd1"\') THEN 1 ELSE 0 END) as crsd1_admins,
                SUM(CASE WHEN JSON_CONTAINS(data_access, \'"crsd2"\') THEN 1 ELSE 0 END) as crsd2_admins,
                SUM(CASE WHEN JSON_CONTAINS(data_access, \'"crsd1"\') AND JSON_CONTAINS(data_access, \'"crsd2"\') THEN 1 ELSE 0 END) as both_access_admins
            ')->first();

        return [
            'total_admins'       => (int) ($stats->total_admins       ?? 0),
            'crsd1_admins'       => (int) ($stats->crsd1_admins       ?? 0),
            'crsd2_admins'       => (int) ($stats->crsd2_admins       ?? 0),
            'both_access_admins' => (int) ($stats->both_access_admins ?? 0),
        ];
    }

    private function formatUserResponse(User $user): array
    {
        $dataAccess = $this->parseDataAccess($user->data_access);

        return [
            'id'               => $user->id,
            'name'             => $user->name,
            'email'            => $user->email,
            'phone'            => $user->phone,
            'role'             => $user->role,
            'divisi'           => $user->divisi,
            'unit_kerja'       => $user->unit_kerja,
            'data_access'      => $dataAccess,
            'has_crsd1_access' => in_array('crsd1', $dataAccess),
            'has_crsd2_access' => in_array('crsd2', $dataAccess),
            'has_both_access'  => in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess),
            'status'           => $user->email_verified_at ? 'aktif' : 'pending',
            'email_verified_at'=> $user->email_verified_at
                ? $user->email_verified_at->format('Y-m-d H:i:s')
                : null,
            'created_at'       => $user->created_at->format('Y-m-d H:i:s'),
            'updated_at'       => $user->updated_at->format('Y-m-d H:i:s'),
        ];
    }

    private function parseDataAccess($dataAccess): array
    {
        if (empty($dataAccess) || $dataAccess === 'null') return [];
        if (is_array($dataAccess)) return $dataAccess;
        if (is_string($dataAccess)) {
            $decoded = json_decode($dataAccess, true);
            return (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        }
        return [];
    }

    private function clearUserCache(): void
    {
        Cache::forget(self::CACHE_DASHBOARD);
        Cache::forget(self::CACHE_ADMINS_STATS);
    }
}