<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string|array  $roles
     * @param  string|null  $permission  // Optional: untuk permission tambahan
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
        
        // Check superadmin bypass (optional config)
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
        
        // Check data access for admin users (CRSD1, CRSD2, etc.)
        if ($userRole === 'admin' && $request->route()) {
            $dataType = $this->getDataTypeFromRoute($request);
            if ($dataType && !$this->checkDataAccess($user, $dataType)) {
                return $this->forbiddenDataAccessResponse($user, $dataType);
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
     * Check if superadmin can bypass all checks
     */
    private function allowSuperadminBypass()
    {
        // Configurable - bisa dari config atau env
        return config('app.superadmin_bypass', false); // default false untuk keamanan
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
        
        // Check if admin has 'all' access or specific access
        return in_array('all', $dataAccess) || in_array($dataType, $dataAccess);
    }
    
    /**
     * Extract data type from route name or parameters
     */
    private function getDataTypeFromRoute(Request $request)
    {
        $route = $request->route();
    
        if ($route && $route->hasParameter('dataType')) {
            return $route->parameter('dataType');
        }
        
        //search route name (example: 'crsd1.index' -> 'crsd1')
        $routeName = $route->getName();
        if ($routeName) {
            $parts = explode('.', $routeName);
            if (in_array($parts[0], ['crsd1', 'crsd2', 'crsd3', 'crsd4'])) {
                return $parts[0];
            }
        }
        $path = $request->path();
        if (preg_match('/\/(crsd1|crsd2|crsd3|crsd4)/', $path, $matches)) {
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
        $dataAccess = json_decode($user->data_access, true) ?? [];
        
        return response()->json([
            'success' => false,
            'message' => 'Forbidden - No access to ' . strtoupper($dataType) . ' data',
            'user_role' => $user->role,
            'required_data_access' => $dataType,
            'user_data_access' => $dataAccess,
            'user_id' => $user->id,
            'user_email' => $user->email
        ], 403);
    }
}