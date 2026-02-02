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
            // Helper function untuk count admins dengan data_access
            $countAdminsWithAccess = function($accessType) {
                return User::where('role', 'admin')
                    ->where(function ($query) use ($accessType) {
                        $dataAccess = $this->parseDataAccessToString($accessType);
                        $query->where('data_access', 'like', '%' . $accessType . '%')
                              ->orWhere('data_access', 'like', '%"' . $accessType . '"%');
                    })->count();
            };

            $stats = [
                'total_orders' => Orders::count(),
                'total_users' => User::count(),
                'total_admins' => User::where('role', 'admin')->count(),
                'total_superadmins' => User::where('role', 'superadmin')->count(),
                'total_crsd1_admins' => $countAdminsWithAccess('crsd1'),
                'total_crsd2_admins' => $countAdminsWithAccess('crsd2'),
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
     * USER MANAGEMENT METHODS (WITH DATA ACCESS SUPPORT)
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
            $dataAccess = $request->get('data_access', '');
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

            // Filter by data access (hanya untuk admin)
            if (!empty($dataAccess) && $dataAccess !== 'all') {
                if ($dataAccess === 'has_access') {
                    $query->whereNotNull('data_access')
                         ->where('data_access', '!=', '')
                         ->where('data_access', '!=', '[]')
                         ->where('data_access', '!=', 'null');
                } elseif ($dataAccess === 'no_access') {
                    $query->where(function ($q) {
                        $q->whereNull('data_access')
                          ->orWhere('data_access', '')
                          ->orWhere('data_access', '[]')
                          ->orWhere('data_access', 'null');
                    });
                } else {
                    $query->where(function ($q) use ($dataAccess) {
                        $q->where('data_access', 'like', '%' . $dataAccess . '%')
                          ->orWhere('data_access', 'like', '%"' . $dataAccess . '"%');
                    });
                }
            }

            // Apply sorting
            $query->orderBy($sort, $order);

            // Paginate results
            $users = $query->paginate($perPage, ['*'], 'page', $page);

            // Transform response to include readable data_access
            $users->getCollection()->transform(function ($user) {
                return $this->formatUserResponse($user);
            });

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
                'data' => $this->formatUserResponse($user)
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
            'data_access' => 'nullable|array',
            'data_access.*' => 'string|in:crsd1,crsd2' // Hanya CRSD 1 dan CRSD 2
        ], [
            'password.confirmed' => 'Password dan konfirmasi password tidak cocok',
            'email.unique' => 'Email sudah terdaftar',
            'role.in' => 'Role harus user, admin, atau superadmin',
            'data_access.*.in' => 'Data access harus crsd1 atau crsd2'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Validate data_access hanya untuk admin
        if ($request->role === 'admin') {
            if (empty($request->data_access) || !is_array($request->data_access)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data access wajib diisi untuk role admin',
                    'errors' => ['data_access' => ['Data access wajib diisi untuk admin']]
                ], 422);
            }
            
            // Pastikan hanya crsd1 dan/atau crsd2 yang dipilih
            $invalidTypes = array_diff($request->data_access, ['crsd1', 'crsd2']);
            if (!empty($invalidTypes)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data access tidak valid. Hanya crsd1 dan crsd2 yang diperbolehkan.',
                    'errors' => ['data_access' => ['Data access tidak valid']]
                ], 422);
            }
            
            // Pastikan array tidak kosong
            if (count($request->data_access) === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data access tidak boleh kosong untuk role admin',
                    'errors' => ['data_access' => ['Data access tidak boleh kosong']]
                ], 422);
            }
        }

        // Prepare user data
        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->role,
            'divisi' => $request->divisi,
            'unit_kerja' => $request->unit_kerja,
            'email_verified_at' => now(),
        ];

        // Add data_access if provided and user is admin
        if ($request->role === 'admin' && !empty($request->data_access) && is_array($request->data_access)) {
            // JANGAN menggunakan json_encode() di sini
            // Karena Model User sudah punya casting 'array' untuk data_access
            $userData['data_access'] = $request->data_access; // Langsung array
        } else {
            $userData['data_access'] = null;
        }

        // Create user
        $user = User::create($userData);

        // Log the action
        $dataAccessStr = $user->data_access ? json_encode($user->data_access) : '[]';
        Log::info("User created by superadmin: {$user->name} ({$user->email}) with role: {$user->role} data_access: {$dataAccessStr}");

        return response()->json([
            'success' => true,
            'message' => 'Pengguna berhasil dibuat',
            'data' => $this->formatUserResponse($user)
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
            'data_access' => 'nullable|array',
            'data_access.*' => 'string|in:crsd1,crsd2' // Hanya CRSD 1 dan CRSD 2
        ], [
            'email.unique' => 'Email sudah terdaftar',
            'role.in' => 'Role harus user, admin, atau superadmin',
            'data_access.*.in' => 'Data access harus crsd1 atau crsd2'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Store old data for logging
        $oldData = $user->getAttributes();
        $oldDataAccess = $user->data_access;

        // Prepare update data
        $updateData = [];
        $fields = ['name', 'email', 'phone', 'role', 'divisi', 'unit_kerja'];

        foreach ($fields as $field) {
            if ($request->has($field)) {
                $updateData[$field] = $request->$field;
            }
        }

        // Handle data_access update
        if ($request->has('data_access')) {
            $newRole = $request->has('role') ? $request->role : $user->role;
            
            if ($newRole === 'admin') {
                if (empty($request->data_access) || !is_array($request->data_access)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data access wajib diisi untuk role admin',
                        'errors' => ['data_access' => ['Data access wajib diisi untuk admin']]
                    ], 422);
                }
                
                // Validasi data_access hanya crsd1 dan crsd2
                $invalidTypes = array_diff($request->data_access, ['crsd1', 'crsd2']);
                if (!empty($invalidTypes)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Data access tidak valid. Hanya crsd1 dan crsd2 yang diperbolehkan.',
                        'errors' => ['data_access' => ['Data access tidak valid']]
                    ], 422);
                }
                
                // JANGAN menggunakan json_encode() di sini
                $updateData['data_access'] = $request->data_access; // Langsung array
            } else {
                // Clear data_access for non-admin users
                $updateData['data_access'] = null;
            }
        } elseif ($request->has('role') && $request->role === 'admin') {
            // Jika role berubah menjadi admin, cek apakah sudah punya data_access
            $currentDataAccess = $user->data_access;
            if (empty($currentDataAccess)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data access wajib diisi untuk role admin',
                    'errors' => ['data_access' => ['Data access wajib diisi untuk admin']]
                ], 422);
            }
        }

        // Update user
        $user->update($updateData);

        // Log the action
        $changes = [];
        foreach ($updateData as $field => $newValue) {
            if (isset($oldData[$field]) && $oldData[$field] != $newValue) {
                $changes[$field] = ['from' => $oldData[$field], 'to' => $newValue];
            }
        }

        // Log data_access changes separately
        if ($request->has('data_access')) {
            $newAccess = $user->data_access;
            if ($oldDataAccess != $newAccess) {
                $changes['data_access'] = [
                    'from' => $oldDataAccess,
                    'to' => $newAccess
                ];
            }
        }

        if (!empty($changes)) {
            Log::info("User updated: {$user->name} ({$user->email}) - Changes: " . json_encode($changes));
        }

        return response()->json([
            'success' => true,
            'message' => 'Pengguna berhasil diperbarui',
            'data' => $this->formatUserResponse($user)
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
 * NEW: Set Data Access for Admin
 * POST /api/superadmin/users/{id}/data-access
 */
public function setDataAccess(Request $request, $id)
{
    try {
        $user = User::findOrFail($id);

        // Validasi: Hanya untuk admin
        if ($user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Data access hanya dapat diatur untuk user dengan role admin'
            ], 400);
        }

        // Validate the request
        $validator = Validator::make($request->all(), [
            'data_access' => 'required|array|min:1',
            'data_access.*' => 'string|in:crsd1,crsd2' // Hanya CRSD 1 dan CRSD 2
        ], [
            'data_access.required' => 'Data access wajib diisi',
            'data_access.min' => 'Minimal satu data access harus dipilih',
            'data_access.*.in' => 'Data access harus crsd1 atau crsd2'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $validator->errors()
            ], 422);
        }

        // Update data access - JANGAN gunakan json_encode()
        $oldAccess = $user->data_access;
        $user->data_access = $request->data_access; // Langsung array
        $user->save();

        // Log the action
        Log::info("Data access updated for admin: {$user->name} ({$user->email}) - " .
            "Old: " . json_encode($oldAccess) . " New: " . json_encode($request->data_access));

        return response()->json([
            'success' => true,
            'message' => 'Data access berhasil diatur',
            'data' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $user->role,
                'data_access' => $user->data_access, // Sudah array dari model
                'has_all_access' => in_array('crsd1', $user->data_access) && in_array('crsd2', $user->data_access),
                'updated_at' => $user->updated_at->format('Y-m-d H:i:s')
            ]
        ], 200);

    } catch (\Exception $e) {
        Log::error('Set Data Access Error: ' . $e->getMessage());
        return response()->json([
            'success' => false,
            'message' => 'Gagal mengatur data access',
            'error' => $e->getMessage()
        ], 500);
    }
}

    /**
     * NEW: Get Admin Data Access
     * GET /api/superadmin/users/{id}/data-access
     */
    public function getDataAccess($id)
    {
        try {
            $user = User::findOrFail($id);

            $dataAccess = $this->parseDataAccess($user->data_access);

            return response()->json([
                'success' => true,
                'message' => 'Data access retrieved successfully',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'role' => $user->role,
                    'data_access' => $dataAccess,
                    'has_all_access' => in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess),
                    'accessible_data_types' => $this->getAccessibleDataTypes($dataAccess)
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get Data Access Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data access',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * NEW: List Admins with Data Access Filter
     * GET /api/superadmin/admins?data_access=crsd1&page=1&per_page=15
     */
    public function listAdminsWithAccess(Request $request)
    {
        try {
            $page = $request->get('page', 1);
            $perPage = $request->get('per_page', 15);
            $search = $request->get('search', '');
            $dataAccess = $request->get('data_access', '');
            $sort = $request->get('sort', 'created_at');
            $order = $request->get('order', 'desc');

            // Build query for admin users only
            $query = User::where('role', 'admin');

            // Search functionality
            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                });
            }

            // Filter by data access
            if (!empty($dataAccess)) {
                if ($dataAccess === 'has_access') {
                    $query->whereNotNull('data_access')
                         ->where('data_access', '!=', '')
                         ->where('data_access', '!=', '[]')
                         ->where('data_access', '!=', 'null');
                } elseif ($dataAccess === 'no_access') {
                    $query->where(function ($q) {
                        $q->whereNull('data_access')
                          ->orWhere('data_access', '')
                          ->orWhere('data_access', '[]')
                          ->orWhere('data_access', 'null');
                    });
                } else {
                    $query->where(function ($q) use ($dataAccess) {
                        $q->where('data_access', 'like', '%' . $dataAccess . '%')
                          ->orWhere('data_access', 'like', '%"' . $dataAccess . '"%');
                    });
                }
            }

            // Apply sorting
            $query->orderBy($sort, $order);

            // Paginate results
            $admins = $query->paginate($perPage, ['*'], 'page', $page);

            // Transform data untuk menampilkan data_access yang readable
            $admins->getCollection()->transform(function ($admin) {
                return $this->formatUserResponse($admin);
            });

            // Get statistics
            $stats = [
                'total_admins' => User::where('role', 'admin')->count(),
                'crsd1_admins' => User::where('role', 'admin')
                    ->where(function ($q) {
                        $q->where('data_access', 'like', '%crsd1%')
                          ->orWhere('data_access', 'like', '%"crsd1"%');
                    })->count(),
                'crsd2_admins' => User::where('role', 'admin')
                    ->where(function ($q) {
                        $q->where('data_access', 'like', '%crsd2%')
                          ->orWhere('data_access', 'like', '%"crsd2"%');
                    })->count(),
                'both_access_admins' => User::where('role', 'admin')
                    ->where(function ($q) {
                        $q->where('data_access', 'like', '%crsd1%')
                          ->where('data_access', 'like', '%crsd2%');
                    })->count(),
            ];

            return response()->json([
                'success' => true,
                'message' => 'Admins retrieved successfully',
                'data' => [
                    'admins' => $admins,
                    'statistics' => $stats
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('List Admins Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve admins',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * NEW: Check if user has access to specific data type
     * POST /api/superadmin/users/{id}/check-access
     * 
     * Request Body:
     * {
     *   "data_type": "crsd1"
     * }
     */
    public function checkUserAccess(Request $request, $id)
    {
        try {
            $user = User::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'data_type' => 'required|string|in:crsd1,crsd2'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validasi gagal',
                    'errors' => $validator->errors()
                ], 422);
            }

            $dataType = $request->data_type;
            $dataAccess = $this->parseDataAccess($user->data_access);
            $hasAccess = in_array($dataType, $dataAccess);

            return response()->json([
                'success' => true,
                'message' => 'Access check completed',
                'data' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'user_role' => $user->role,
                    'data_type' => $dataType,
                    'has_access' => $hasAccess,
                    'data_access' => $dataAccess,
                    'access_reason' => $hasAccess 
                        ? 'Has access to ' . strtoupper($dataType)
                        : 'No access'
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error('Check User Access Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to check user access',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * NEW: Get available data types for selection
     * GET /api/superadmin/data-types
     */
    public function getDataTypes()
    {
        try {
            $dataTypes = [
                [
                    'value' => 'crsd1',
                    'label' => 'CRSD 1',
                    'description' => 'Customer Relationship System Data 1'
                ],
                [
                    'value' => 'crsd2',
                    'label' => 'CRSD 2',
                    'description' => 'Customer Relationship System Data 2'
                ]
            ];

            return response()->json([
                'success' => true,
                'message' => 'Data types retrieved successfully',
                'data' => $dataTypes
            ], 200);
        } catch (\Exception $e) {
            Log::error('Get Data Types Error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve data types',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change user password (by superadmin)
     * POST /api/superadmin/users/{id}/change-password
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

        // Handle data_access when changing role
        $updateData = ['role' => $request->role];
        
        if ($request->role !== 'admin') {
            // Clear data_access if changing from admin to non-admin
            $updateData['data_access'] = null;
        } elseif ($request->role === 'admin') {
            // Cek apakah sudah punya data_access
            $currentDataAccess = $user->data_access;
            if (empty($currentDataAccess)) {
                // Jika belum punya data_access, kembalikan error
                return response()->json([
                    'success' => false,
                    'message' => 'Data access is required for admin role. Please set data_access first.',
                    'requires_data_access' => true
                ], 422);
            }
            // Jika sudah punya data_access, pertahankan
            // Tidak perlu set karena sudah ada
        }

        // Update role (and possibly data_access)
        $oldRole = $user->role;
        $user->update($updateData);

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
                'new_role' => $user->role,
                'data_access' => $user->data_access // Langsung dari model
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
                'data' => $this->formatUserResponse($user)
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
                'data' => $this->formatUserResponse($user)
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
            $userInfo = $this->formatUserResponse($user);
            
            // Delete user
            $user->delete();

            // Log the action
            $dataAccess = $this->parseDataAccess($user->data_access);
            Log::info("User deleted: {$user->name} ({$user->email}) with data_access: " . json_encode($dataAccess));

            return response()->json([
                'success' => true,
                'message' => "User '{$user->name}' deleted successfully",
                'deleted_user' => $userInfo
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
     * HELPER METHODS
     * =====================================================================
     */

        /**
     * Helper: Parse data_access field to array
     * @param mixed $dataAccess
     * @return array
     */
    private function parseDataAccess($dataAccess)
    {
        // Jika null atau kosong, return array kosong
        if ($dataAccess === null || $dataAccess === '' || $dataAccess === 'null') {
            return [];
        }
        
        // Jika sudah array, langsung return
        if (is_array($dataAccess)) {
            return $dataAccess;
        }
        
        // Jika string, coba decode JSON
        if (is_string($dataAccess)) {
            $decoded = json_decode($dataAccess, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            
            // Jika decode gagal, return array kosong
            return [];
        }
        
        return [];
    }

    /**
     * Helper: Convert data access to searchable string
     * @param string $accessType
     * @return string
     */
    private function parseDataAccessToString($accessType)
    {
        return '%' . $accessType . '%';
    }

        /**
 * Format user response consistently
 */
private function formatUserResponse(User $user)
{
    // Langsung ambil data_access dari model (sudah array karena casting)
    $dataAccess = $user->data_access ?? [];

    return [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'phone' => $user->phone,
        'role' => $user->role,
        'divisi' => $user->divisi,
        'unit_kerja' => $user->unit_kerja,
        'data_access' => $dataAccess, // Sudah array
        'has_crsd1_access' => in_array('crsd1', $dataAccess),
        'has_crsd2_access' => in_array('crsd2', $dataAccess),
        'has_both_access' => in_array('crsd1', $dataAccess) && in_array('crsd2', $dataAccess),
        'accessible_data_types' => $this->getAccessibleDataTypes($dataAccess),
        'status' => $user->email_verified_at ? 'aktif' : 'pending',
        'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : null,
        'created_at' => $user->created_at->format('Y-m-d H:i:s'),
        'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
    ];
}

    /**
     * Get readable accessible data types
     */
    private function getAccessibleDataTypes($dataAccess)
    {
        // Pastikan dataAccess adalah array
        if (!is_array($dataAccess)) {
            $dataAccess = $this->parseDataAccess($dataAccess);
        }
        
        if (!is_array($dataAccess) || empty($dataAccess)) {
            return [];
        }
        
        return array_map(function ($type) {
            return [
                'value' => $type,
                'label' => strtoupper($type),
                'description' => "Access to " . strtoupper($type) . " data"
            ];
        }, $dataAccess);
    }

    /**
     * Get User Activity Logs
     * GET /api/superadmin/users/{id}/activity
     */
    public function getUserActivity($id)
    {
        try {
            $user = User::findOrFail($id);

            $dataAccess = $this->parseDataAccess($user->data_access);

            // In a real application, you would query an activity log table
            $activities = [
                'created_at' => $user->created_at->format('Y-m-d H:i:s'),
                'last_login' => 'Not implemented',
                'email_verified_at' => $user->email_verified_at ? $user->email_verified_at->format('Y-m-d H:i:s') : null,
                'updated_at' => $user->updated_at->format('Y-m-d H:i:s'),
                'role_changes' => [],
                'data_access_changes' => $dataAccess,
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
            $dataAccess = $request->get('data_access', '');
            $format = $request->get('format', 'csv');

            $query = User::query();

            if (!empty($role) && $role !== 'all') {
                $query->where('role', $role);
            }

            if (!empty($dataAccess) && $dataAccess !== 'all') {
                $query->where(function ($q) use ($dataAccess) {
                    $q->where('data_access', 'like', '%' . $dataAccess . '%')
                      ->orWhere('data_access', 'like', '%"' . $dataAccess . '"%');
                });
            }

            $users = $query->get();

            if ($format === 'csv') {
                // Simple CSV implementation
                $csvData = "ID,Name,Email,Role,CRSD 1 Access,CRSD 2 Access,Status,Created At\n";
                foreach ($users as $user) {
                    $dataAccess = $this->parseDataAccess($user->data_access);
                    $hasCrsd1 = in_array('crsd1', $dataAccess) ? 'Yes' : 'No';
                    $hasCrsd2 = in_array('crsd2', $dataAccess) ? 'Yes' : 'No';
                    
                    $csvData .= "{$user->id},{$user->name},{$user->email},{$user->role}," .
                        "{$hasCrsd1},{$hasCrsd2}," .
                        ($user->email_verified_at ? 'Active' : 'Inactive') . "," .
                        $user->created_at->format('Y-m-d H:i:s') . "\n";
                }

                return response($csvData, 200, [
                    'Content-Type' => 'text/csv',
                    'Content-Disposition' => 'attachment; filename="users_export_' . date('Y-m-d') . '.csv"',
                ]);
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
     * Placeholder methods for other routes
     */
    public function getSettings()
    {
        return response()->json([
            'success' => false,
            'message' => 'Settings endpoint not implemented'
        ], 501);
    }

    public function updateSettings(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Update settings endpoint not implemented'
        ], 501);
    }

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
            'message' => 'Update email config endpoint not implemented'
        ], 501);
    }

    public function getLogs()
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