<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\ForgotPasswordController as ApiForgotPasswordController;
use App\Http\Controllers\Api\ResetPasswordController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| EMAIL VERIFICATION (PUBLIC)
|--------------------------------------------------------------------------
*/
Route::get('/email/verify/{id}/{hash}', function ($id, $hash) {
    $user = User::find($id);

    if (! $user) {
        return response()->json([
            'success' => false,
            'message' => 'User tidak ditemukan'
        ], 404);
    }

    if (! hash_equals(sha1($user->email), $hash)) {
        return response()->json([
            'success' => false,
            'message' => 'Link verifikasi tidak valid'
        ], 400);
    }

    if ($user->hasVerifiedEmail()) {
        return response()->json([
            'success' => false,
            'message' => 'Email sudah diverifikasi'
        ], 400);
    }

    $user->markEmailAsVerified();

    return response()->json([
        'success' => true,
        'message' => 'Email berhasil diverifikasi'
    ]);
})->name('verification.verify');

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {

    // Auth
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);

    // Password Reset
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
    Route::post('reset-password', [ResetPasswordController::class, 'reset']);

    // Authenticated
    Route::middleware('auth:api')->group(function () {

        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::match(['put', 'patch'], 'profile', [AuthController::class, 'updateProfile']);

        Route::post('email/resend', function (Request $request) {
            if ($request->user()->hasVerifiedEmail()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Email sudah diverifikasi'
                ], 400);
            }

            $request->user()->sendEmailVerificationNotification();

            return response()->json([
                'success' => true,
                'message' => 'Email verifikasi berhasil dikirim ulang'
            ]);
        });
    });
});

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('admin')
    ->middleware(['auth:api', 'role:admin'])
    ->group(function () {

        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('users', [AdminController::class, 'listUsers']);
        Route::get('users/{id}', [AdminController::class, 'showUser']);
        Route::put('users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('users/{id}', [AdminController::class, 'deleteUser']);
    });

/*
|--------------------------------------------------------------------------
| SUPERADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('superadmin')
    ->middleware(['auth:api', 'role:superadmin'])
    ->group(function () {

        Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
        Route::get('users', [SuperAdminController::class, 'listAllUsers']);
        Route::post('users/{id}/role', [SuperAdminController::class, 'changeUserRole']);
        Route::delete('users/{id}', [SuperAdminController::class, 'deleteUser']);
        Route::get('settings', [SuperAdminController::class, 'getSettings']);
        Route::post('settings', [SuperAdminController::class, 'updateSettings']);
    });
