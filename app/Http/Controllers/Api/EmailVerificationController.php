<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\RateLimiter;
use App\Models\User;

class EmailVerificationController extends Controller
{
    private function frontendUrl(string $query = ''): string
    {
        $base = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        return $base . '/auth/email-verify' . ($query ? '?' . $query : '');
    }

    public function verify(Request $request, $id, $hash)
    {
        try {
            if (!$request->hasValidSignature()) {
                return redirect($this->frontendUrl('success=false&message=' . urlencode('Link verifikasi tidak valid atau sudah kadaluarsa')));
            }

            $user = User::findOrFail($id);

            if (!hash_equals(sha1($user->email), (string) $hash)) {
                return redirect($this->frontendUrl('success=false&message=' . urlencode('Link verifikasi tidak valid')));
            }

            if ($user->hasVerifiedEmail()) {
                return redirect($this->frontendUrl('success=true&already=verified'));
            }

            $user->markEmailAsVerified();

            return redirect($this->frontendUrl('success=true'));

        } catch (\Exception $e) {
            return redirect($this->frontendUrl('success=false&message=' . urlencode('Terjadi kesalahan saat verifikasi')));
        }
    }

    public function resend(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email sudah diverifikasi'
                ], 400);
            }

            $rateLimitKey = 'resend-verification:' . $user->id;

            if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
                $seconds = RateLimiter::availableIn($rateLimitKey);
                return response()->json([
                    'success'    => false,
                    'message'    => 'Terlalu banyak permintaan. Coba lagi dalam ' . $seconds . ' detik.',
                    'retry_after' => $seconds
                ], 429);
            }

            RateLimiter::hit($rateLimitKey, 300);

            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addHours(24),
                [
                    'id'   => $user->id,
                    'hash' => sha1($user->email),
                ]
            );

            Mail::to($user->email)->send(new VerifyEmailMail($user, $verificationUrl));

            return response()->json([
                'success' => true,
                'message' => 'Email verifikasi berhasil dikirim ulang'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengirim email',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    public function status(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'is_verified' => $user->hasVerifiedEmail(),
                    'email'       => $user->email,
                    'verified_at' => $user->email_verified_at
                        ? $user->email_verified_at->format('Y-m-d H:i:s')
                        : null,
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}