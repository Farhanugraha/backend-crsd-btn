<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\AreaController;
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
| EMAIL VERIFICATION
|--------------------------------------------------------------------------
*/
Route::get('/email/verify/{id}/{hash}', function ($id, $hash) {
    $user = User::find($id);

    if (! $user) {
        return redirect(env('FRONTEND_URL') . '/email-verification?success=false&message=User%20not%20found');
    }

    if (! hash_equals(sha1($user->email), $hash)) {
        return redirect(env('FRONTEND_URL') . '/email-verification?success=false&message=Invalid%20link');
    }

    if ($user->hasVerifiedEmail()) {
        return redirect(env('FRONTEND_URL') . '/email-verification?success=true&message=Already%20verified');
    }

    $user->markEmailAsVerified();

    return redirect(env('FRONTEND_URL') . '/email-verification?success=true&message=Email%20verified%20successfully');
})->name('verification.verify');

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // Public Auth Routes
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail']);
    Route::post('reset-password', [ResetPasswordController::class, 'reset']);

    // Protected Auth Routes
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
| AREAS ROUTES (NEW)
|--------------------------------------------------------------------------
*/
Route::prefix('areas')->group(function () {
    // Public routes
    Route::get('', [AreaController::class, 'index']);
    Route::get('{id}', [AreaController::class, 'show']);
    Route::get('slug/{slug}', [AreaController::class, 'showBySlug']);
    Route::get('{id}/restaurants', [AreaController::class, 'getRestaurants']);
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [AreaController::class, 'store']);
        Route::put('{id}', [AreaController::class, 'update']);
        Route::delete('{id}', [AreaController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| RESTAURANT ROUTES (UPDATED)
|--------------------------------------------------------------------------
*/
Route::prefix('restaurants')->group(function () {
    // Public routes
    Route::get('search', [RestaurantController::class, 'search']); // Must be before {id}
    Route::get('', [RestaurantController::class, 'index']); // Can filter by ?area_id=1
    Route::get('area/{areaId}', [RestaurantController::class, 'getByArea']);
    Route::get('{id}', [RestaurantController::class, 'show']);
    Route::get('{id}/stats', [RestaurantController::class, 'getStats']);
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::get('all', [RestaurantController::class, 'getAllRestaurants']);
        Route::post('', [RestaurantController::class, 'store']);
        Route::put('{id}', [RestaurantController::class, 'update']);
        Route::patch('{id}/toggle-status', [RestaurantController::class, 'toggleStatus']);
        Route::delete('{id}', [RestaurantController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| MENU ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('menus')->group(function () {
    // Public routes
    Route::get('restaurant/{restaurantId}', [MenuController::class, 'index']);
    Route::get('{id}', [MenuController::class, 'show']);
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [MenuController::class, 'store']);
        Route::put('{id}', [MenuController::class, 'update']);
        Route::delete('{id}', [MenuController::class, 'destroy']);
    });
});

/*
|--------------------------------------------------------------------------
| USER CART ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('cart')->middleware(['auth:api', 'role:user'])->group(function () {
    Route::get('', [CartController::class, 'getCart']);
    Route::post('add-item', [CartController::class, 'addItem']);
    Route::put('items/{cartItemId}', [CartController::class, 'updateItem']);
    Route::delete('items/{cartItemId}', [CartController::class, 'removeItem']);
    Route::delete('clear', [CartController::class, 'clearCart']);
});

/*
|--------------------------------------------------------------------------
| USER ORDER ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('orders')->middleware(['auth:api', 'role:user'])->group(function () {
    Route::get('', [OrdersController::class, 'index']);
    Route::get('{id}', [OrdersController::class, 'show']);
    Route::post('', [OrdersController::class, 'store']);
    Route::post('{id}/cancel', [OrdersController::class, 'cancel']);
    Route::put('{id}/notes', [OrdersController::class, 'updateNotes']);
    Route::put('{id}/items/{itemId}/notes', [OrdersController::class, 'updateItemNotes']);
});

/*
|--------------------------------------------------------------------------
| USER PAYMENT ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('payments')->middleware(['auth:api', 'role:user'])->group(function () {
    Route::get('orders/{orderId}', [PaymentsController::class, 'show']);
    Route::post('orders/{orderId}/initiate', [PaymentsController::class, 'initiate']);
    Route::post('orders/{orderId}/upload-proof', [PaymentsController::class, 'uploadProof']);
});

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->middleware(['auth:api', 'role:admin,superadmin'])->group(function () {
    Route::get('dashboard', [AdminController::class, 'dashboard']);
    Route::get('orders', [OrdersController::class, 'getAllOrders']);
    Route::get('payments', [PaymentsController::class, 'getAllPayments']);
    Route::put('payments/{paymentId}/confirm', [PaymentsController::class, 'confirmPayment']);
});

/*
|--------------------------------------------------------------------------
| SUPERADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('superadmin')->middleware(['auth:api', 'role:superadmin'])->group(function () {
    Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
    Route::get('users', [SuperAdminController::class, 'listAllUsers']);
    Route::post('users/{id}/role', [SuperAdminController::class, 'changeUserRole']);
    Route::delete('users/{id}', [SuperAdminController::class, 'deleteUser']);
    Route::get('settings', [SuperAdminController::class, 'getSettings']);
    Route::post('settings', [SuperAdminController::class, 'updateSettings']);
});