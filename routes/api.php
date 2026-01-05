<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\OrdersController;
use App\Http\Controllers\Api\PaymentsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| LOGIN FALLBACK ROUTE (UNTUK API)
|--------------------------------------------------------------------------
*/
Route::get('login', function () {
    return response()->json([
        'success' => false,
        'message' => 'Unauthenticated - Please login first'
    ], 401);
})->name('login');

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
    // Public Auth
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
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
| RESTAURANT ROUTES (PUBLIC READ + SUPERADMIN WRITE)
|--------------------------------------------------------------------------
*/
Route::prefix('restaurants')->group(function () {
    // Public routes - siapa saja bisa lihat
    Route::get('', [RestaurantController::class, 'index']);
    Route::get('{id}', [RestaurantController::class, 'show']);
    
    // SuperAdmin only
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [RestaurantController::class, 'store']);
        Route::put('{id}', [RestaurantController::class, 'update']);
        Route::delete('{id}', [RestaurantController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| MENU ROUTES (PUBLIC READ + SUPERADMIN WRITE)
|--------------------------------------------------------------------------
*/
Route::prefix('menus')->group(function () {
    // Public routes - siapa saja bisa lihat
    Route::get('restaurant/{restaurantId}', [MenuController::class, 'index']);
    Route::get('{id}', [MenuController::class, 'show']);
    
    // SuperAdmin only
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [MenuController::class, 'store']);
        Route::put('{id}', [MenuController::class, 'update']);
        Route::delete('{id}', [MenuController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| CART ROUTES (USER ONLY)
|--------------------------------------------------------------------------
*/
Route::prefix('cart')
    ->middleware(['auth:api', 'role:user'])  
    ->group(function () {
        Route::get('', [CartController::class, 'getCart']);
        Route::post('add-item', [CartController::class, 'addItem']);
        Route::put('items/{cartItemId}', [CartController::class, 'updateItem']);
        Route::delete('items/{cartItemId}', [CartController::class, 'removeItem']);
        Route::delete('clear', [CartController::class, 'clearCart']);
    });

/*
|--------------------------------------------------------------------------
| ORDER ROUTES (USER)
|--------------------------------------------------------------------------
*/
Route::prefix('orders')
    ->middleware(['auth:api', 'role:user']) 
    ->group(function () {
        Route::get('', [OrdersController::class, 'index']);
        Route::get('{id}', [OrdersController::class, 'show']);
        Route::post('', [OrdersController::class, 'store']);
        Route::post('{id}/cancel', [OrdersController::class, 'cancel']);

         // Edit notes if status pending / before payment
        Route::put('{id}/notes', [OrdersController::class, 'updateNotes']);
        Route::put('{id}/items/{itemId}/notes', [OrdersController::class, 'updateItemNotes']);
    });

/*
|--------------------------------------------------------------------------
| PAYMENT ROUTES (USER)
|--------------------------------------------------------------------------
*/
Route::prefix('payments')
    ->middleware(['auth:api', 'role:user'])  
    ->group(function () {
        Route::get('orders/{orderId}', [PaymentsController::class, 'show']);
        Route::post('orders/{orderId}/process', [PaymentsController::class, 'process']);
    });

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES - LIHAT ORDERS & DASHBOARD
|--------------------------------------------------------------------------
*/
Route::prefix('admin')
    ->middleware(['auth:api', 'role:admin|superadmin'])  
    ->group(function () {
        Route::get('dashboard', [AdminController::class, 'dashboard']);
        Route::get('orders', [OrdersController::class, 'getAllOrders']);
    });

/*
|--------------------------------------------------------------------------
| SUPERADMIN ROUTES - FULL AKSES
|--------------------------------------------------------------------------
*/
Route::prefix('superadmin')
    ->middleware(['auth:api', 'role:superadmin'])
    ->group(function () {
        Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
        
        // User Management
        Route::get('users', [SuperAdminController::class, 'listAllUsers']);
        Route::post('users/{id}/role', [SuperAdminController::class, 'changeUserRole']);
        Route::delete('users/{id}', [SuperAdminController::class, 'deleteUser']);
        
        // Settings
        Route::get('settings', [SuperAdminController::class, 'getSettings']);
        Route::post('settings', [SuperAdminController::class, 'updateSettings']);
    });