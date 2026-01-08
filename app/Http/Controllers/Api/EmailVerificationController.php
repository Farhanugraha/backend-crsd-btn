<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerifyEmailMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use App\Models\User;

class EmailVerificationController extends Controller
{
    /**
     * Handle email verification when user clicks the link
     */
    public function verify($id, $hash)
    {
        try {
            $user = User::findOrFail($id);

            // Validate hash
            if (!hash_equals(sha1($user->email), $hash)) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                return redirect("{$frontendUrl}/auth/email-verify?success=false&message=Link%20verifikasi%20tidak%20valid");
            }

            // Check if already verified
            if ($user->hasVerifiedEmail()) {
                $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
                return redirect("{$frontendUrl}/auth/email-verify?success=true&already=verified");
            }

            // Mark email as verified
            $user->markEmailAsVerified();

            // Redirect ke frontend - SESUAIKAN PATH
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            return redirect("{$frontendUrl}/auth/email-verify?success=true");

        } catch (\Exception $e) {
            $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
            return redirect("{$frontendUrl}/auth/email-verify?success=false&message=" . urlencode('Terjadi kesalahan saat verifikasi'));
        }
    }

    /**
     * Resend verification email
     */
    public function resend(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            if ($user->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email sudah diverifikasi'
                ], 400);
            }

            // Generate verification URL
            $verificationUrl = URL::temporarySignedRoute(
                'verification.verify',
                now()->addHours(24),
                [
                    'id' => $user->id,
                    'hash' => sha1($user->email),
                ]
            );

            // Send email
            Mail::to($user->email)->send(new VerifyEmailMail($user, $verificationUrl));

            return response()->json([
                'success' => true,
                'message' => 'Email verifikasi berhasil dikirim ulang'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat mengirim email',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get verification status
     */
    public function status(Request $request)
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User tidak ditemukan'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'is_verified' => $user->hasVerifiedEmail(),
                    'email' => $user->email,
                    'verified_at' => $user->email_verified_at
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}