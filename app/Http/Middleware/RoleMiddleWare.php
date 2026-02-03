<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  mixed  ...$params
     * @return mixed
     */
    public function handle(Request $request, Closure $next, ...$params)
    {
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
            'path' => $request->path()
        ]);
        
        // Check superadmin bypass
        if ($this->allowSuperadminBypass() && $userRole === 'superadmin') {
            return $next($request);
        }
        
        // Check role
        if (!in_array($userRole, $roles)) {
            return $this->forbiddenRoleResponse($user, $roles);
        }
        
        // Check additional permission if provided
        if ($permission && !$this->checkPermission($user, $permission)) {
            return $this->forbiddenPermissionResponse($user, $permission);
        }
        
        // Check CRSD data access for admin users
        if ($userRole === 'admin' && $dataAccessType) {
            if (!$this->checkDataAccess($user, $dataAccessType)) {
                return $this->forbiddenDataAccessResponse($user, $dataAccessType);
            }
        }
        
        // Check CRSD data access from route if no explicit type in params
        if ($userRole === 'admin' && !$dataAccessType) {
            $dataTypeFromRoute = $this->getDataTypeFromRoute($request);
            if ($dataTypeFromRoute && !$this->checkDataAccess($user, $dataTypeFromRoute)) {
                return $this->forbiddenDataAccessResponse($user, $dataTypeFromRoute);
            }
        }
        
        return $next($request);
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
        if (empty($roles) && isset($params[0])) {
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
        return config('app.superadmin_bypass', false);
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
     */
    private function checkDataAccess($user, $dataType)
    {
        if (!$user->data_access) {
            return false;
        }
        
        $dataAccess = json_decode($user->data_access, true) ?? [];
        
        // Check if user has access to specific data type
        return in_array($dataType, $dataAccess);
    }
    
    /**
     * Extract data type from route name or parameters
     */
    private function getDataTypeFromRoute(Request $request)
    {
        $path = $request->path();
        
        // Check for CRSD patterns in path
        if (preg_match('/api\/crsd\/(crsd[0-9]+)/', $path, $matches)) {
            return $matches[1];
        }
        
        if (preg_match('/admin\/(crsd[0-9]+)/', $path, $matches)) {
            return $matches[1];
        }
        
        // Check route name
        $route = $request->route();
        if ($route) {
            $routeName = $route->getName();
            if ($routeName) {
                $parts = explode('.', $routeName);
                if (in_array($parts[0], ['crsd1', 'crsd2', 'crsd3', 'crsd4'])) {
                    return $parts[0];
                }
            }
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
        $dataAccess = json_decode($user->data_access, true) ?? [];
        
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