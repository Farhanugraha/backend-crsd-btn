<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ...$params)
    {
        try {
            // Check if user is authenticated
            if (!$request->user()) {
                return $this->unauthorizedResponse('User not authenticated');
            }
            
            $user = $request->user();
            $userRole = $user->role;
            
            // Parse parameters
            $roles = $this->parseRoles($params);
            $permission = $this->parsePermission($params);
            $dataAccessType = $this->parseDataAccessType($params);
            
            // Log untuk debugging
            Log::info('RoleMiddleware check', [
                'user_id' => $user->id,
                'user_role' => $userRole,
                'required_roles' => $roles,
                'permission' => $permission,
                'data_access_type' => $dataAccessType,
                'path' => $request->path(),
                'params' => $params,
                'user_data_access' => $user->data_access,
                'user_metadata' => $user->metadata
            ]);
            
            // Check superadmin bypass
            if ($this->allowSuperadminBypass() && $userRole === 'superadmin') {
                Log::info('Superadmin bypass activated');
                return $next($request);
            }
            
            // Check role
            if (!in_array($userRole, $roles)) {
                Log::warning('User role not in required roles', [
                    'user_role' => $userRole,
                    'required_roles' => $roles
                ]);
                return $this->forbiddenRoleResponse($user, $roles);
            }
            
            // Check additional permission if provided
            if ($permission && !$this->checkPermission($user, $permission)) {
                Log::warning('User missing required permission', [
                    'user_role' => $userRole,
                    'required_permission' => $permission
                ]);
                return $this->forbiddenPermissionResponse($user, $permission);
            }
            
            // ========== MODIFIKASI: Handle CRSD access check ==========
            
            // 1. Check jika ada data_access di parameter middleware
            if ($dataAccessType && $userRole === 'admin') {
                if (!$this->checkDataAccess($user, $dataAccessType)) {
                    Log::warning('Admin missing data_access from middleware param', [
                        'user_id' => $user->id,
                        'required_data_access' => $dataAccessType,
                        'user_data_access' => $user->data_access
                    ]);
                    return $this->forbiddenDataAccessResponse($user, $dataAccessType);
                }
            }
            
            // 2. Check jika route mengandung CRSD pattern (admin/crsd1/... atau admin/crsd2/...)
            $crsdFromRoute = $this->getCRSDFromPath($request->path());
            if ($crsdFromRoute && $userRole === 'admin') {
                if (!$this->checkDataAccess($user, $crsdFromRoute)) {
                    Log::warning('Admin missing data_access from route pattern', [
                        'user_id' => $user->id,
                        'route_path' => $request->path(),
                        'detected_crsd' => $crsdFromRoute,
                        'user_data_access' => $user->data_access
                    ]);
                    return $this->forbiddenDataAccessResponse($user, $crsdFromRoute);
                }
            }
            
            Log::info('RoleMiddleware passed all checks for user: ' . $user->id);
            return $next($request);
            
        } catch (\Exception $e) {
            Log::error('RoleMiddleware Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'user_id' => $request->user() ? $request->user()->id : 'none'
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Middleware Error',
                'error' => env('APP_DEBUG') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }
    
    /**
     * Parse roles from parameters
     */
    private function parseRoles($params)
    {
        $roles = [];
        
        foreach ($params as $param) {
            // Check if parameter contains role definition
            if (strpos($param, 'role:') === 0) {
                $roleString = str_replace('role:', '', $param);
                $roles = array_merge($roles, explode('|', $roleString));
            } 
            // Check for data access type
            elseif (strpos($param, 'data_access:') === 0) {
                // Skip, handled separately
                continue;
            }
            // If parameter is just role without prefix
            elseif (in_array($param, ['superadmin', 'admin', 'user', 'guest'])) {
                $roles[] = $param;
            }
        }
        
        // Default: if no roles specified, check first parameter
        if (empty($roles) && isset($params[0]) && !str_contains($params[0], ':')) {
            $roles = explode('|', $params[0]);
        }
        
        return array_unique($roles);
    }
    
    /**
     * Parse additional permission from parameters
     */
    private function parsePermission($params)
    {
        foreach ($params as $param) {
            if (strpos($param, 'permission:') === 0) {
                return str_replace('permission:', '', $param);
            }
        }
        
        return null;
    }
    
    /**
     * Parse data access type from parameters
     */
    private function parseDataAccessType($params)
    {
        foreach ($params as $param) {
            if (strpos($param, 'data_access:') === 0) {
                return str_replace('data_access:', '', $param);
            }
        }
        
        return null;
    }
    
    /**
     * Check if superadmin can bypass all checks
     */
    private function allowSuperadminBypass()
    {
        return config('app.superadmin_bypass', true); // Default true untuk superadmin bypass
    }
    
    /**
     * Check additional permissions
     */
    private function checkPermission($user, $permission)
    {
        $permissions = [
            'create_user' => ['superadmin'],
            'manage_users' => ['superadmin'],
            'view_reports' => ['superadmin', 'admin'],
            'edit_profile' => ['superadmin', 'admin', 'user'],
        ];
        
        if (isset($permissions[$permission])) {
            return in_array($user->role, $permissions[$permission]);
        }
        
        return false;
    }
    
    /**
     * Check data access for admin (CRSD1, CRSD2, etc.)
     * PERBAIKAN: Handle both string JSON dan array
     */
    private function checkDataAccess($user, $dataType)
    {
        // Log untuk debugging
        Log::info('Checking data access in middleware', [
            'user_id' => $user->id,
            'data_type' => $dataType,
            'data_access_field_type' => gettype($user->data_access),
            'data_access_value' => $user->data_access,
            'metadata_field_type' => gettype($user->metadata),
            'metadata_value' => $user->metadata
        ]);
        
        // Method 1: Check data_access field
        if ($user->data_access) {
            $dataAccess = $user->data_access;
            
            // Jika sudah array, langsung gunakan
            if (is_array($dataAccess)) {
                Log::info('data_access is already array', ['data_access' => $dataAccess]);
                return in_array($dataType, $dataAccess);
            }
            
            // Jika string, coba decode JSON
            if (is_string($dataAccess)) {
                $decodedData = json_decode($dataAccess, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decodedData)) {
                    Log::info('data_access decoded from JSON', ['decoded_data' => $decodedData]);
                    return in_array($dataType, $decodedData);
                }
            }
        }
        
        // Method 2: Check metadata field
        if ($user->metadata) {
            $metadata = $user->metadata;
            
            // Jika sudah array, langsung cek
            if (is_array($metadata) && isset($metadata['data_access'])) {
                $dataAccessFromMeta = $metadata['data_access'];
                Log::info('Found data_access in metadata array', ['data_access' => $dataAccessFromMeta]);
                return is_array($dataAccessFromMeta) && in_array($dataType, $dataAccessFromMeta);
            }
            
            // Jika string, coba decode JSON
            if (is_string($metadata)) {
                $decodedMeta = json_decode($metadata, true);
                if (json_last_error() === JSON_ERROR_NONE && 
                    is_array($decodedMeta) && 
                    isset($decodedMeta['data_access'])) {
                    $dataAccessFromMeta = $decodedMeta['data_access'];
                    Log::info('Found data_access in decoded metadata', ['data_access' => $dataAccessFromMeta]);
                    return is_array($dataAccessFromMeta) && in_array($dataType, $dataAccessFromMeta);
                }
            }
        }
        
        // Method 3: Cek menggunakan method di User model jika ada
        if (method_exists($user, 'getEffectiveDataAccess')) {
            $effectiveDataAccess = $user->getEffectiveDataAccess();
            Log::info('Using getEffectiveDataAccess method', ['data_access' => $effectiveDataAccess]);
            return is_array($effectiveDataAccess) && in_array($dataType, $effectiveDataAccess);
        }
        
        Log::warning('No data access found for user', ['user_id' => $user->id]);
        return false;
    }
    
    /**
     * Extract CRSD type from route path
     * Deteksi pattern: /api/admin/crsd1/* atau /api/admin/crsd2/*
     */
    private function getCRSDFromPath($path)
    {
        // Remove /api/ prefix if exists
        if (strpos($path, 'api/') === 0) {
            $path = substr($path, 4);
        }
        
        // Check for admin/crsd1/ or admin/crsd2/ pattern
        if (preg_match('/^admin\/(crsd[0-9]+)/', $path, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    /**
     * Response helpers
     */
    private function unauthorizedResponse($message)
    {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized - ' . $message
        ], 401);
    }
    
    private function forbiddenRoleResponse($user, $requiredRoles)
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden - Insufficient role permissions',
            'user_role' => $user->role,
            'required_roles' => $requiredRoles,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'timestamp' => now()->toISOString()
        ], 403);
    }
    
    private function forbiddenPermissionResponse($user, $permission)
    {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden - Missing permission: ' . $permission,
            'user_role' => $user->role,
            'required_permission' => $permission,
            'user_id' => $user->id
        ], 403);
    }
    
    private function forbiddenDataAccessResponse($user, $dataType)
    {
        // Get data access untuk response (handle semua kemungkinan)
        $dataAccess = [];
        
        if ($user->data_access) {
            if (is_array($user->data_access)) {
                $dataAccess = $user->data_access;
            } elseif (is_string($user->data_access)) {
                $decoded = json_decode($user->data_access, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $dataAccess = $decoded;
                }
            }
        }
        
        return response()->json([
            'success' => false,
            'message' => 'Forbidden - No access to ' . strtoupper($dataType) . ' data',
            'user_role' => $user->role,
            'required_data_access' => $dataType,
            'user_data_access' => $dataAccess,
            'user_id' => $user->id,
            'user_email' => $user->email,
            'timestamp' => now()->toISOString()
        ], 403);
    }
}