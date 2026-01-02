<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role)
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - User not authenticated'
            ], 401);
        }
        
        $roles = explode('|', $role);
        $userRole = $request->user()->role;
        
        if ($userRole === 'superadmin') {
            return $next($request);
        }
        
   
        if (!in_array($userRole, $roles)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - Insufficient role',
                'user_role' => $userRole,
                'required_role' => $role
            ], 403);
        }
        
        return $next($request);
    }
}