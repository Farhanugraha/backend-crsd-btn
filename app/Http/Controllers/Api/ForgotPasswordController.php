<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

class ForgotPasswordController extends Controller
{
    public function sendResetLinkEmail(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $rateLimitKey = 'forgot-password:' . $request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 5)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);
            return response()->json([
                'success'     => false,
                'message'     => 'Terlalu banyak permintaan. Coba lagi dalam ' . $seconds . ' detik.',
                'retry_after' => $seconds
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 300);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'success' => true,
                'message' => 'Jika email terdaftar, link reset password akan dikirimkan.'
            ], 200);
        }

        if ($status === Password::RESET_THROTTLED) {
            return response()->json([
                'success' => false,
                'message' => 'Permintaan reset password terlalu sering. Silakan cek email Anda atau coba beberapa saat lagi.'
            ], 429);
        }

        return response()->json([
            'success' => true,
            'message' => 'Jika email terdaftar, link reset password akan dikirimkan.'
        ], 200);
    }
}