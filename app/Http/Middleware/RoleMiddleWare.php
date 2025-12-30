<?php
// 1. RoleMiddleware
// File: app/Http/Middleware/RoleMiddleware.php

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

        // Jika role superadmin, boleh akses semua
        if ($request->user()->role === 'superadmin') {
            return $next($request);
        }

        // Jika role tidak sesuai
        if ($request->user()->role !== $role) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden - Insufficient role',
                'user_role' => $request->user()->role,
                'required_role' => $role
            ], 403);
        }

        return $next($request);
    }
}

?>