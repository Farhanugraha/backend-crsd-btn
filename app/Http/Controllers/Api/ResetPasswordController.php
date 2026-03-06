<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

class ResetPasswordController extends Controller
{
    public function reset(Request $request)
    {
        // Validasi dengan aturan yang lebih ketat
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email|exists:users,email',
            'token'    => 'required|string',
            'password' => [
                'required',
                'string',
                'min:6',
                'confirmed',
                'regex:/[A-Z]/',      
                'regex:/[a-z]/',      
                'regex:/[0-9]/',      
                'regex:/[@$!%*?&]/',  
            ],
        ], [
            'email.required' => 'Email harus diisi',
            'email.email' => 'Format email tidak valid',
            'email.exists' => 'Email tidak ditemukan',
            'token.required' => 'Token reset password diperlukan',
            'password.required' => 'Password harus diisi',
            'password.min' => 'Password minimal 6 karakter',
            'password.confirmed' => 'Konfirmasi password tidak cocok',
            'password.regex' => 'Password harus mengandung huruf besar, huruf kecil, angka, dan simbol (@$!%*?&)',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $rateLimitKey = 'reset-password:' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'success'     => false,
                'message'     => 'Terlalu banyak percobaan. Coba lagi dalam ' . $seconds . ' detik.',
                'retry_after' => $seconds
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 300);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password'       => Hash::make($password),
                    'remember_token' => null,
                ])->save();
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            RateLimiter::clear($rateLimitKey);

            return response()->json([
                'success' => true,
                'message' => 'Password berhasil direset. Silakan login dengan password baru.'
            ], 200);
        }

        if ($status === Password::INVALID_TOKEN) {
            return response()->json([
                'success' => false,
                'message' => 'Token reset password tidak valid atau sudah kadaluarsa.'
            ], 422);
        }

        if ($status === Password::INVALID_USER) {
            return response()->json([
                'success' => false,
                'message' => 'Email tidak ditemukan.'
            ], 422);
        }

        return response()->json([
            'success' => false,
            'message' => 'Gagal mereset password. Silakan coba lagi.'
        ], 422);
    }
}