<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Auth\Events\Registered;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users',
            'password' => 'required|min:6|confirmed',
            'phone' => 'nullable|string|max:20',
            'divisi' => 'nullable|string|max:255',
            'unit_kerja' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'divisi' => $request->divisi,
            'unit_kerja' => $request->unit_kerja,
            'role' => 'user',
        ]);

        event(new Registered($user));

        return response()->json([
            'success' => true,
            'message' => 'Registrasi berhasil. Silakan cek email untuk verifikasi.'
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if (!$token = JWTAuth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Email atau password salah'
            ], 401);
        }

        $user = JWTAuth::user();

        if (!$user->hasVerifiedEmail()) {
            JWTAuth::invalidate($token);
            return response()->json([
                'success' => false,
                'message' => 'Silakan verifikasi email terlebih dahulu'
            ], 403);
        }

        return response()->json([
            'success' => true,
            'token' => $token,
            'token_type' => 'bearer',
            'expires_in' => (int) config('jwt.ttl') * 60,
            'user' => $user
        ]);
    }

    public function logout()
    {
        JWTAuth::invalidate(JWTAuth::getToken());

        return response()->json([
            'success' => true,
            'message' => 'Logout berhasil'
        ]);
    }

    public function me()
    {
        return response()->json([
            'success' => true,
            'user' => JWTAuth::parseToken()->authenticate()
        ]);
    }

    public function refresh()
    {
        $newToken = JWTAuth::refresh(JWTAuth::getToken());

        return response()->json([
            'success' => true,
            'token' => $newToken,
            'token_type' => 'bearer',
            'expires_in' => config('jwt.ttl') * 60,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|max:255|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:20',
            'divisi' => 'nullable|string|max:255',
            'unit_kerja' => 'nullable|string|max:100',
            'password' => 'sometimes|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->filled('password')) {
            $request->merge([
                'password' => Hash::make($request->password)
            ]);
        }

        $user->update($request->only([
            'name','email','phone','divisi','unit_kerja','password'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil diperbarui'
        ]);
    }
}
