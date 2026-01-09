<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\SuperAdminController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\Api\EmailVerificationController;
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
| API Routes
|--------------------------------------------------------------------------
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

/*
|--------------------------------------------------------------------------
| HEALTH CHECK
|--------------------------------------------------------------------------
*/
Route::get('/health', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is running',
        'timestamp' => now()
    ]);
});

/*
|--------------------------------------------------------------------------
| EMAIL VERIFICATION ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('email')->group(function () {
    // Public route - handle email verification link click
    Route::get('verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
             ->name('verification.verify');

    // Protected routes
    Route::middleware('auth:api')->group(function () {
        // Check verification status
        Route::get('status', [EmailVerificationController::class, 'status'])
            ->name('verification.status');

        // Resend verification email
        Route::post('resend', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:3,1')
            ->name('verification.resend');
    });
});

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    // Public Auth Routes
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:5,1')
        ->name('auth.register');
    
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')
        ->name('auth.login');
    
    Route::post('forgot-password', [ForgotPasswordController::class, 'sendResetLinkEmail'])
        ->middleware('throttle:3,1')
        ->name('password.email');
    
    Route::post('reset-password', [ResetPasswordController::class, 'reset'])
        ->middleware('throttle:3,1')
        ->name('password.reset');

    // Protected Auth Routes
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
        
        Route::get('me', [AuthController::class, 'me'])
            ->name('auth.me');
        
        Route::post('refresh', [AuthController::class, 'refresh'])
            ->name('auth.refresh');
        
        Route::match(['put', 'patch'], 'profile', [AuthController::class, 'updateProfile'])
            ->name('auth.profile.update');
    });
});

/*
|--------------------------------------------------------------------------
| AREAS ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('areas')->group(function () {
    // Public routes
    Route::get('', [AreaController::class, 'index'])
        ->name('areas.index');
    
    Route::get('{id}', [AreaController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('areas.show');
    
    Route::get('slug/{slug}', [AreaController::class, 'showBySlug'])
        ->name('areas.showBySlug');
    
    Route::get('{id}/restaurants', [AreaController::class, 'getRestaurants'])
        ->where('id', '[0-9]+')
        ->name('areas.restaurants');
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [AreaController::class, 'store'])
            ->name('areas.store');
        
        Route::put('{id}', [AreaController::class, 'update'])
            ->where('id', '[0-9]+')
            ->name('areas.update');
        
        Route::delete('{id}', [AreaController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->name('areas.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| RESTAURANT ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('restaurants')->group(function () {
    // Public routes - Search must be before {id}
    Route::get('search', [RestaurantController::class, 'search'])
        ->name('restaurants.search');
    
    Route::get('', [RestaurantController::class, 'index'])
        ->name('restaurants.index');
    
    Route::get('area/{areaId}', [RestaurantController::class, 'getByArea'])
        ->where('areaId', '[0-9]+')
        ->name('restaurants.byArea');
    
    Route::get('{id}', [RestaurantController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('restaurants.show');
    
    Route::get('{id}/stats', [RestaurantController::class, 'getStats'])
        ->where('id', '[0-9]+')
        ->name('restaurants.stats');
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::get('all', [RestaurantController::class, 'getAllRestaurants'])
            ->name('restaurants.all');
        
        Route::post('', [RestaurantController::class, 'store'])
            ->name('restaurants.store');
        
        Route::put('{id}', [RestaurantController::class, 'update'])
            ->where('id', '[0-9]+')
            ->name('restaurants.update');
        
        Route::patch('{id}/toggle-status', [RestaurantController::class, 'toggleStatus'])
            ->where('id', '[0-9]+')
            ->name('restaurants.toggleStatus');
        
        Route::delete('{id}', [RestaurantController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->name('restaurants.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| MENU ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('menus')->group(function () {
    // Public routes
    Route::get('restaurant/{restaurantId}', [MenuController::class, 'index'])
        ->where('restaurantId', '[0-9]+')
        ->name('menus.index');
    
    Route::get('{id}', [MenuController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('menus.show');
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [MenuController::class, 'store'])
            ->name('menus.store');
        
        Route::put('{id}', [MenuController::class, 'update'])
            ->where('id', '[0-9]+')
            ->name('menus.update');
        
        Route::patch('{id}/toggle', [MenuController::class, 'toggleAvailability'])
            ->where('id', '[0-9]+')
            ->name('menus.toggleAvailability');
        
        Route::delete('{id}', [MenuController::class, 'destroy'])
            ->where('id', '[0-9]+')
            ->name('menus.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| USER CART ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('cart')
    ->middleware(['auth:api', 'role:user'])
    ->group(function () {
        Route::get('', [CartController::class, 'getCart'])
            ->name('cart.index');
        
        Route::post('add-item', [CartController::class, 'addItem'])
            ->name('cart.addItem');
        
        Route::put('items/{cartItemId}', [CartController::class, 'updateItem'])
            ->where('cartItemId', '[0-9]+')
            ->name('cart.updateItem');
        
        Route::delete('items/{cartItemId}', [CartController::class, 'removeItem'])
            ->where('cartItemId', '[0-9]+')
            ->name('cart.removeItem');
        
        Route::delete('clear', [CartController::class, 'clearCart'])
            ->name('cart.clear');
        
        Route::post('apply-coupon', [CartController::class, 'applyCoupon'])
            ->name('cart.applyCoupon');
        
        Route::delete('remove-coupon', [CartController::class, 'removeCoupon'])
            ->name('cart.removeCoupon');
    });

/*
|--------------------------------------------------------------------------
| USER ORDER ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('orders')
    ->middleware(['auth:api'])
    ->group(function () {
        Route::get('', [OrdersController::class, 'index'])
            ->name('orders.index');
        
        Route::get('{id}', [OrdersController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('orders.show');
        
        Route::post('', [OrdersController::class, 'store'])
            ->name('orders.store');
        
        Route::post('{id}/cancel', [OrdersController::class, 'cancel'])
            ->where('id', '[0-9]+')
            ->name('orders.cancel');
        
        Route::put('{id}/notes', [OrdersController::class, 'updateNotes'])
            ->where('id', '[0-9]+')
            ->name('orders.updateNotes');
        
        Route::put('{id}/items/{itemId}/notes', [OrdersController::class, 'updateItemNotes'])
            ->where(['id' => '[0-9]+', 'itemId' => '[0-9]+'])
            ->name('orders.updateItemNotes');
        
        Route::post('{id}/rate', [OrdersController::class, 'rateOrder'])
            ->where('id', '[0-9]+')
            ->name('orders.rate');
    });

/*
|--------------------------------------------------------------------------
| USER PAYMENT ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('payments')
    ->middleware(['auth:api'])
    ->group(function () {
        Route::get('orders/{orderId}', [PaymentsController::class, 'show'])
            ->where('orderId', '[0-9]+')
            ->name('payments.show');
        
        Route::post('orders/{orderId}/initiate', [PaymentsController::class, 'initiate'])
            ->where('orderId', '[0-9]+')
            ->name('payments.initiate');
        
        Route::post('orders/{orderId}/upload-proof', [PaymentsController::class, 'uploadProof'])
            ->where('orderId', '[0-9]+')
            ->name('payments.uploadProof');
        
        Route::get('history', [PaymentsController::class, 'history'])
            ->name('payments.history');
    });

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('admin')
    ->middleware(['auth:api', 'role:admin,superadmin'])
    ->group(function () {
        Route::get('dashboard', [AdminController::class, 'dashboard'])
            ->name('admin.dashboard');
        
        Route::get('orders', [OrdersController::class, 'getAllOrders'])
            ->name('admin.orders.index');
        
        Route::get('orders/{id}', [OrdersController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('admin.orders.show');
        
        Route::put('orders/{id}/status', [OrdersController::class, 'updateStatus'])
            ->where('id', '[0-9]+')
            ->name('admin.orders.updateStatus');
        
        Route::get('payments', [PaymentsController::class, 'getAllPayments'])
            ->name('admin.payments.index');
        
        Route::get('payments/{id}', [PaymentsController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('admin.payments.show');
        
        Route::put('payments/{paymentId}/confirm', [PaymentsController::class, 'confirmPayment'])
            ->where('paymentId', '[0-9]+')
            ->name('admin.payments.confirm');
        
        Route::put('payments/{paymentId}/reject', [PaymentsController::class, 'rejectPayment'])
            ->where('paymentId', '[0-9]+')
            ->name('admin.payments.reject');
        
        Route::get('statistics', [AdminController::class, 'getStatistics'])
            ->name('admin.statistics');
        
        Route::get('reports', [AdminController::class, 'getReports'])
            ->name('admin.reports');
    });

/*
|--------------------------------------------------------------------------
| SUPERADMIN ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('superadmin')
    ->middleware(['auth:api', 'role:superadmin'])
    ->group(function () {
        // Dashboard
        Route::get('dashboard', [SuperAdminController::class, 'dashboard'])
            ->name('superadmin.dashboard');
        
        // User Management
        Route::prefix('users')->group(function () {
            Route::get('', [SuperAdminController::class, 'listAllUsers'])
                ->name('superadmin.users.index');
            
            Route::get('{id}', [SuperAdminController::class, 'showUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.show');
            
            Route::post('{id}/role', [SuperAdminController::class, 'changeUserRole'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.changeRole');
            
            Route::delete('{id}', [SuperAdminController::class, 'deleteUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.delete');
            
            Route::post('{id}/deactivate', [SuperAdminController::class, 'deactivateUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.deactivate');
            
            Route::post('{id}/activate', [SuperAdminController::class, 'activateUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.activate');
        });
        
        // Settings
        Route::prefix('settings')->group(function () {
            Route::get('', [SuperAdminController::class, 'getSettings'])
                ->name('superadmin.settings.index');
            
            Route::post('', [SuperAdminController::class, 'updateSettings'])
                ->name('superadmin.settings.update');
            
            Route::get('email-config', [SuperAdminController::class, 'getEmailConfig'])
                ->name('superadmin.settings.emailConfig');
            
            Route::post('email-config', [SuperAdminController::class, 'updateEmailConfig'])
                ->name('superadmin.settings.updateEmailConfig');
        });
        
        // System
        Route::prefix('system')->group(function () {
            Route::get('logs', [SuperAdminController::class, 'getLogs'])
                ->name('superadmin.system.logs');
            
            Route::post('clear-cache', [SuperAdminController::class, 'clearCache'])
                ->name('superadmin.system.clearCache');
            
            Route::get('health', [SuperAdminController::class, 'systemHealth'])
                ->name('superadmin.system.health');
        });
        
        // Reports & Analytics
        Route::prefix('reports')->group(function () {
            Route::get('', [SuperAdminController::class, 'getReports'])
                ->name('superadmin.reports.index');
            
            Route::get('users', [SuperAdminController::class, 'getUsersReport'])
                ->name('superadmin.reports.users');
            
            Route::get('orders', [SuperAdminController::class, 'getOrdersReport'])
                ->name('superadmin.reports.orders');
            
            Route::get('payments', [SuperAdminController::class, 'getPaymentsReport'])
                ->name('superadmin.reports.payments');
            
            Route::get('revenue', [SuperAdminController::class, 'getRevenueReport'])
                ->name('superadmin.reports.revenue');
        });
    });

/*
|--------------------------------------------------------------------------
| FALLBACK ROUTE
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->json([
        'success' => false,
        'message' => 'Endpoint not found',
        'status' => 404
    ], 404);
});