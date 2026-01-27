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

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
| Base: /api
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
    Route::get('verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->name('verification.verify');

    Route::middleware('auth:api')->group(function () {
        Route::get('status', [EmailVerificationController::class, 'status'])
            ->name('verification.status');

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

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
        
        Route::get('session', [AuthController::class, 'session'])
            ->name('auth.session');
        
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
    
    // SUPERADMIN ONLY - Area Management
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
    
    // SUPERADMIN ONLY - Restaurant Management
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
    Route::get('restaurant/{restaurantId}', [MenuController::class, 'index'])
        ->where('restaurantId', '[0-9]+')
        ->name('menus.index');
    
    Route::get('{id}', [MenuController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('menus.show');
    
    // SUPERADMIN ONLY - Menu Management
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

        Route::post('upload-image', [MenuController::class, 'uploadImage'])
            ->name('menus.uploadImage');
    });
});

/*
|--------------------------------------------------------------------------
| USER CART ROUTES - HANYA USER BIASA
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
| USER ORDER ROUTES - UNTUK SEMUA AUTHENTICATED USERS
|--------------------------------------------------------------------------
*/
Route::prefix('orders')
    ->middleware(['auth:api'])
    ->group(function () {
        Route::get('', [OrdersController::class, 'index'])
            ->name('orders.index');
        
        Route::post('', [OrdersController::class, 'store'])
            ->name('orders.store');
        
        Route::get('{id}', [OrdersController::class, 'show'])
            ->where('id', '[0-9]+')
            ->name('orders.show');
        
        Route::put('{id}/payment-status', [OrdersController::class, 'updatePaymentStatus'])
            ->where('id', '[0-9]+')
            ->name('orders.updatePaymentStatus');
        
        Route::post('{id}/cancel', [OrdersController::class, 'cancel'])
            ->where('id', '[0-9]+')
            ->name('orders.cancel');
        
        Route::put('{id}/notes', [OrdersController::class, 'updateNotes'])
            ->where('id', '[0-9]+')
            ->name('orders.updateNotes');
        
        Route::put('{id}/items/{itemId}/notes', [OrdersController::class, 'updateItemNotes'])
            ->where(['id' => '[0-9]+', 'itemId' => '[0-9]+'])
            ->name('orders.updateItemNotes');
    });

/*
|--------------------------------------------------------------------------
| USER PAYMENT ROUTES - UNTUK SEMUA AUTHENTICATED USERS
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
| ADMIN ROUTES - ADMIN & SUPERADMIN
| Akses Admin untuk: Orders, Payments, Statistics, Reports
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:admin,superadmin'])
    ->prefix('admin')
    ->group(function () {
        // Dashboard
        Route::get('dashboard', [AdminController::class, 'dashboard'])
            ->name('admin.dashboard');
        
        // Statistics & Reports
        Route::get('statistics', [AdminController::class, 'getStatistics'])
            ->name('admin.statistics');
        
        Route::get('reports', [AdminController::class, 'getReports'])
            ->name('admin.reports');
        
        Route::get('orders-detail', [AdminController::class, 'getOrdersDetail'])
            ->name('admin.ordersDetail');
        
        Route::post('export-reports', [AdminController::class, 'exportReports'])
            ->name('admin.exportReports');
        
        // Orders Management
        Route::prefix('orders')->group(function () {
            Route::get('', [OrdersController::class, 'getAllOrders'])
                ->name('admin.orders.index');
            
            Route::post('batch-update-status', [OrdersController::class, 'batchUpdateStatus'])
                ->name('admin.orders.batchUpdate');
            
            Route::get('pending', [OrdersController::class, 'getPendingOrders'])
                ->name('admin.orders.pending');
            
            Route::get('status/{status}', [OrdersController::class, 'getOrdersByStatus'])
                ->where('status', 'processing|completed|canceled')
                ->name('admin.orders.byStatus');
            
            Route::get('{id}', [OrdersController::class, 'getOrderDetail'])
                ->where('id', '[0-9]+')
                ->name('admin.orders.show');
            
            Route::put('{id}/status', [OrdersController::class, 'updateOrderStatus'])
                ->where('id', '[0-9]+')
                ->name('admin.orders.updateStatus');
            
            Route::put('{id}/items/{itemId}/toggle-check', [OrdersController::class, 'toggleItemChecked'])
                ->where(['id' => '[0-9]+', 'itemId' => '[0-9]+'])
                ->name('admin.orders.toggleItemChecked');
            
            Route::get('{id}/checked-items-count', [OrdersController::class, 'getCheckedItemsCount'])
                ->where('id', '[0-9]+')
                ->name('admin.orders.checkedItemsCount');
        });
        
        // Payments Management
        Route::prefix('payments')->group(function () {
            Route::get('', [PaymentsController::class, 'getAllPayments'])
                ->name('admin.payments.index');
            
            Route::put('{paymentId}/confirm', [PaymentsController::class, 'confirmPayment'])
                ->where('paymentId', '[0-9]+')
                ->name('admin.payments.confirm');
            
            Route::put('{paymentId}/reject', [PaymentsController::class, 'rejectPayment'])
                ->where('paymentId', '[0-9]+')
                ->name('admin.payments.reject');
            
            Route::get('{paymentId}', [PaymentsController::class, 'getPaymentDetail'])
                ->where('paymentId', '[0-9]+')
                ->name('admin.payments.show');
        });
    });

/*
|--------------------------------------------------------------------------
| SUPERADMIN ROUTES - SUPERADMIN ONLY
| 
| Akses eksklusif untuk fitur superadmin
| Prefix: /api/superadmin
| Middleware: auth:api, role:superadmin
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:superadmin'])
    ->prefix('superadmin')
    ->group(function () {
        
        // Dashboard
        Route::get('dashboard', [SuperAdminController::class, 'dashboard'])
            ->name('superadmin.dashboard');
        
        /*
        |======================================================================
        | USER MANAGEMENT ROUTES - COMPLETE CRUD
        |======================================================================
        */
        Route::prefix('users')->group(function () {
            // ===== ROUTES TANPA PARAMETER DULU =====
            // Read Operations (GET semua users)
            Route::get('', [SuperAdminController::class, 'listAllUsers'])
                ->name('superadmin.users.index');
            
            // Create Operation (POST create user) - HARUS SEBELUM {id}
            Route::post('', [SuperAdminController::class, 'createUser'])
                ->name('superadmin.users.create');
            
            // Bulk Actions (tanpa parameter)
            Route::post('bulk/activate', [SuperAdminController::class, 'bulkActivateUsers'])
                ->name('superadmin.users.bulkActivate');
            
            Route::post('bulk/deactivate', [SuperAdminController::class, 'bulkDeactivateUsers'])
                ->name('superadmin.users.bulkDeactivate');
            
            // Export Users
            Route::get('export', [SuperAdminController::class, 'exportUsers'])
                ->name('superadmin.users.export');
            
            // ===== ROUTES DENGAN PARAMETER {id} =====
            // Show single user
            Route::get('{id}', [SuperAdminController::class, 'showUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.show');
            
            // Update Operations
            Route::put('{id}', [SuperAdminController::class, 'updateUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.update');
            
            Route::patch('{id}', [SuperAdminController::class, 'updateUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.update-patch');
            
            // Password Management
            Route::post('{id}/change-password', [SuperAdminController::class, 'changeUserPassword'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.changePassword');
            
            // User Role Management
            Route::post('{id}/role', [SuperAdminController::class, 'changeUserRole'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.changeRole');
            
            // User Status Management
            Route::post('{id}/activate', [SuperAdminController::class, 'activateUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.activate');
            
            Route::post('{id}/deactivate', [SuperAdminController::class, 'deactivateUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.deactivate');
            
            // Delete Operation
            Route::delete('{id}', [SuperAdminController::class, 'deleteUser'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.delete');
            
            // User Activity Logs
            Route::get('{id}/activity', [SuperAdminController::class, 'getUserActivity'])
                ->where('id', '[0-9]+')
                ->name('superadmin.users.activity');
        });
        /*
        |======================================================================
        | SETTINGS ROUTES
        |======================================================================
        | 
        | System settings management
        | Prefix: /api/superadmin/settings
        */
        Route::prefix('settings')->group(function () {
            Route::get('', [SuperAdminController::class, 'getSettings'])
                ->name('superadmin.settings.index');
            
            Route::post('', [SuperAdminController::class, 'updateSettings'])
                ->name('superadmin.settings.update');
            
            Route::put('', [SuperAdminController::class, 'updateSettings'])
                ->name('superadmin.settings.update-put');
            
            // Placeholder for future: Email configuration
            Route::get('email-config', [SuperAdminController::class, 'getEmailConfig'])
                ->name('superadmin.settings.emailConfig');
            
            Route::post('email-config', [SuperAdminController::class, 'updateEmailConfig'])
                ->name('superadmin.settings.updateEmailConfig');
            
            Route::put('email-config', [SuperAdminController::class, 'updateEmailConfig'])
                ->name('superadmin.settings.updateEmailConfig-put');
        });
        
        /*
        |======================================================================
        | SYSTEM MANAGEMENT ROUTES (PLACEHOLDERS)
        |======================================================================
        | 
        | System management features for future implementation
        | Prefix: /api/superadmin/system
        */
        Route::prefix('system')->group(function () {
            Route::get('logs', [SuperAdminController::class, 'getLogs'])
                ->name('superadmin.system.logs');
            
            Route::post('clear-cache', [SuperAdminController::class, 'clearCache'])
                ->name('superadmin.system.clearCache');
            
            Route::get('health', [SuperAdminController::class, 'systemHealth'])
                ->name('superadmin.system.health');
            
            // Database Maintenance
            Route::get('database/stats', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Database stats endpoint not implemented'
                ], 501);
            })->name('superadmin.system.databaseStats');
            
            // Backup Management
            Route::post('backup/create', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup create endpoint not implemented'
                ], 501);
            })->name('superadmin.system.backupCreate');
            
            Route::get('backup/list', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Backup list endpoint not implemented'
                ], 501);
            })->name('superadmin.system.backupList');
        });
        
        /*
        |======================================================================
        | REPORTS ROUTES (PLACEHOLDERS)
        |======================================================================
        | 
        | Reporting features for future implementation
        | Prefix: /api/superadmin/reports
        */
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
            
            // Date Range Reports
            Route::get('date-range', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Date range reports endpoint not implemented'
                ], 501);
            })->name('superadmin.reports.dateRange');
            
            // Export Reports
            Route::post('export', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Export reports endpoint not implemented'
                ], 501);
            })->name('superadmin.reports.export');
        });
        
        /*
        |======================================================================
        | AUDIT LOGS ROUTES (PLACEHOLDERS)
        |======================================================================
        | 
        | Audit and activity logs for system monitoring
        | Prefix: /api/superadmin/audit
        */
        Route::prefix('audit')->group(function () {
            Route::get('', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Audit logs endpoint not implemented'
                ], 501);
            })->name('superadmin.audit.index');
            
            Route::get('user-activity', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'User activity logs endpoint not implemented'
                ], 501);
            })->name('superadmin.audit.userActivity');
            
            Route::get('login-history', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Login history endpoint not implemented'
                ], 501);
            })->name('superadmin.audit.loginHistory');
            
            Route::get('system-events', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'System events endpoint not implemented'
                ], 501);
            })->name('superadmin.audit.systemEvents');
        });
        
        /*
        |======================================================================
        | NOTIFICATION MANAGEMENT (PLACEHOLDERS)
        |======================================================================
        | 
        | System notifications and alerts management
        | Prefix: /api/superadmin/notifications
        */
        Route::prefix('notifications')->group(function () {
            Route::get('', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Notifications endpoint not implemented'
                ], 501);
            })->name('superadmin.notifications.index');
            
            Route::post('send', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Send notifications endpoint not implemented'
                ], 501);
            })->name('superadmin.notifications.send');
            
            Route::get('templates', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Notification templates endpoint not implemented'
                ], 501);
            })->name('superadmin.notifications.templates');
        });
        
        /*
        |======================================================================
        | API MANAGEMENT (PLACEHOLDERS)
        |======================================================================
        | 
        | API keys and access management
        | Prefix: /api/superadmin/api
        */
        Route::prefix('api')->group(function () {
            Route::get('keys', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'API keys endpoint not implemented'
                ], 501);
            })->name('superadmin.api.keys');
            
            Route::post('keys/generate', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Generate API key endpoint not implemented'
                ], 501);
            })->name('superadmin.api.generateKey');
            
            Route::delete('keys/{id}', function($id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Delete API key endpoint not implemented'
                ], 501);
            })->name('superadmin.api.deleteKey');
        });
        
        /*
        |======================================================================
        | ANALYTICS DASHBOARD (PLACEHOLDERS)
        |======================================================================
        | 
        | Advanced analytics and insights
        | Prefix: /api/superadmin/analytics
        */
        Route::prefix('analytics')->group(function () {
            Route::get('overview', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Analytics overview endpoint not implemented'
                ], 501);
            })->name('superadmin.analytics.overview');
            
            Route::get('user-growth', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'User growth analytics endpoint not implemented'
                ], 501);
            })->name('superadmin.analytics.userGrowth');
            
            Route::get('revenue-trends', function() {
                return response()->json([
                    'success' => false,
                    'message' => 'Revenue trends analytics endpoint not implemented'
                ], 501);
            })->name('superadmin.analytics.revenueTrends');
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