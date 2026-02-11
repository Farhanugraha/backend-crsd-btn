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
use App\Http\Controllers\Api\PaymentSettingsController; 
use Illuminate\Support\Facades\Auth;
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
        'timestamp' => now()->toIso8601String()
    ]);
});

/*
|--------------------------------------------------------------------------
| EMAIL VERIFICATION ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('email')->group(function () {
    Route::get('verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware('signed')
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
    // Public routes
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

    // Authenticated routes
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])
            ->name('auth.logout');
        
        Route::get('session', [AuthController::class, 'session'])
            ->name('auth.session');
        
        Route::get('me', [AuthController::class, 'me'])
            ->name('auth.me');
        
        Route::post('refresh', [AuthController::class, 'refresh'])
            ->name('auth.refresh');
        
        Route::put('profile', [AuthController::class, 'updateProfile'])
            ->name('auth.profile.update');
        
        Route::patch('profile', [AuthController::class, 'updateProfile'])
            ->name('auth.profile.update-patch');
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
        ->whereNumber('id')
        ->name('areas.show');
    
    Route::get('slug/{slug}', [AreaController::class, 'showBySlug'])
        ->name('areas.showBySlug');
    
    Route::get('{id}/restaurants', [AreaController::class, 'getRestaurants'])
        ->whereNumber('id')
        ->name('areas.restaurants');
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [AreaController::class, 'store'])
            ->name('areas.store');
        
        Route::put('{id}', [AreaController::class, 'update'])
            ->whereNumber('id')
            ->name('areas.update');
        
        Route::delete('{id}', [AreaController::class, 'destroy'])
            ->whereNumber('id')
            ->name('areas.destroy');
    });
});

/*
|--------------------------------------------------------------------------
| RESTAURANT ROUTES
|--------------------------------------------------------------------------
*/
Route::prefix('restaurants')->group(function () {
    // Public routes
    Route::get('search', [RestaurantController::class, 'search'])
        ->name('restaurants.search');
    
    Route::get('', [RestaurantController::class, 'index'])
        ->name('restaurants.index');
    
    Route::get('area/{areaId}', [RestaurantController::class, 'getByArea'])
        ->whereNumber('areaId')
        ->name('restaurants.byArea');
    
    Route::get('{id}', [RestaurantController::class, 'show'])
        ->whereNumber('id')
        ->name('restaurants.show');
    
    Route::get('{id}/stats', [RestaurantController::class, 'getStats'])
        ->whereNumber('id')
        ->name('restaurants.stats');
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::get('all', [RestaurantController::class, 'getAllRestaurants'])
            ->name('restaurants.all');
        
        Route::post('', [RestaurantController::class, 'store'])
            ->name('restaurants.store');
        
        Route::put('{id}', [RestaurantController::class, 'update'])
            ->whereNumber('id')
            ->name('restaurants.update');
        
        Route::patch('{id}/toggle-status', [RestaurantController::class, 'toggleStatus'])
            ->whereNumber('id')
            ->name('restaurants.toggleStatus');
        
        Route::delete('{id}', [RestaurantController::class, 'destroy'])
            ->whereNumber('id')
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
        ->whereNumber('restaurantId')
        ->name('menus.index');
    
    Route::get('{id}', [MenuController::class, 'show'])
        ->whereNumber('id')
        ->name('menus.show');
    
    // Superadmin only routes
    Route::middleware(['auth:api', 'role:superadmin'])->group(function () {
        Route::post('', [MenuController::class, 'store'])
            ->name('menus.store');
        
        Route::put('{id}', [MenuController::class, 'update'])
            ->whereNumber('id')
            ->name('menus.update');
        
        Route::patch('{id}/toggle', [MenuController::class, 'toggleAvailability'])
            ->whereNumber('id')
            ->name('menus.toggleAvailability');
        
        Route::delete('{id}', [MenuController::class, 'destroy'])
            ->whereNumber('id')
            ->name('menus.destroy');

        Route::post('upload-image', [MenuController::class, 'uploadImage'])
            ->name('menus.uploadImage');
    });
});

/*
|--------------------------------------------------------------------------
| USER CART ROUTES - USER ONLY
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
            ->whereNumber('cartItemId')
            ->name('cart.updateItem');
        
        Route::delete('items/{cartItemId}', [CartController::class, 'removeItem'])
            ->whereNumber('cartItemId')
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
| USER ORDER ROUTES - ALL AUTHENTICATED USERS
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
            ->whereNumber('id')
            ->name('orders.show');
        
        Route::put('{id}/payment-status', [OrdersController::class, 'updatePaymentStatus'])
            ->whereNumber('id')
            ->name('orders.updatePaymentStatus');
        
        Route::post('{id}/cancel', [OrdersController::class, 'cancel'])
            ->whereNumber('id')
            ->name('orders.cancel');
        
        Route::put('{id}/notes', [OrdersController::class, 'updateNotes'])
            ->whereNumber('id')
            ->name('orders.updateNotes');
        
        Route::put('{id}/items/{itemId}/notes', [OrdersController::class, 'updateItemNotes'])
            ->whereNumber(['id', 'itemId'])
            ->name('orders.updateItemNotes');
    });

/*
|--------------------------------------------------------------------------
| USER PAYMENT ROUTES - ALL AUTHENTICATED USERS
|--------------------------------------------------------------------------
*/
Route::prefix('payments')
    ->middleware(['auth:api'])
    ->group(function () {
        Route::get('orders/{orderId}', [PaymentsController::class, 'show'])
            ->whereNumber('orderId')
            ->name('payments.show');
        
        Route::post('orders/{orderId}/initiate', [PaymentsController::class, 'initiate'])
            ->whereNumber('orderId')
            ->name('payments.initiate');
        
        Route::post('orders/{orderId}/upload-proof', [PaymentsController::class, 'uploadProof'])
            ->whereNumber('orderId')
            ->name('payments.uploadProof');
        
        Route::get('history', [PaymentsController::class, 'history'])
            ->name('payments.history');
    });

/*
|--------------------------------------------------------------------------
| ADMIN ROUTES - ADMIN & SUPERADMIN
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api'])
    ->prefix('admin')
    ->group(function () {
        
        // ==================== GENERAL ADMIN ROUTES (tanpa data_access check) ====================
        // Route ini auto filter CRSD di controller berdasarkan user's data_access
        Route::middleware(['role:admin,superadmin'])->group(function () {
            // Dashboard & module selection
            Route::get('dashboard', [AdminController::class, 'dashboard'])
                ->name('admin.dashboard');
            
            Route::get('check-module-selection', [AdminController::class, 'checkModuleSelection'])
                ->name('admin.checkModuleSelection');
            
            Route::get('select-module', [AdminController::class, 'selectModule'])
                ->name('admin.selectModule');
            
            // Statistics & reports
            Route::get('statistics', [AdminController::class, 'getStatistics'])
                ->name('admin.statistics');
            
            Route::get('reports', [AdminController::class, 'getReports'])
                ->name('admin.reports');
            
            Route::get('orders-detail', [AdminController::class, 'getOrdersDetail'])
                ->name('admin.ordersDetail');
            
            Route::post('export-reports', [AdminController::class, 'exportReports'])
                ->name('admin.exportReports');
            
            // Orders with CRSD filtering (auto filter di controller)
            Route::get('orders', [AdminController::class, 'getAllOrders'])
                ->name('admin.orders.index');
            
            // Payments with CRSD filtering (auto filter di controller)
            Route::get('payments', [PaymentsController::class, 'getAllPayments'])
                ->name('admin.payments.index');
            
            Route::get('payments/status/{status}', [PaymentsController::class, 'getPaymentsByStatus'])
                ->whereIn('status', ['pending', 'completed', 'rejected', 'failed', 'expired'])
                ->name('admin.payments.byStatus');
            
            // Users with CRSD filtering (auto filter di controller)
            Route::get('users', [AdminController::class, 'listUsers'])
                ->name('admin.users.index');
            
            // ==================== ORDERS MANAGEMENT ====================
            Route::prefix('orders')->group(function () {
                Route::post('batch-update-status', [OrdersController::class, 'batchUpdateStatus'])
                    ->name('admin.orders.batchUpdate');
                
                Route::get('pending', [OrdersController::class, 'getPendingOrders'])
                    ->name('admin.orders.pending');
                
                Route::get('status/{status}', [OrdersController::class, 'getOrdersByStatus'])
                    ->whereIn('status', ['processing', 'completed', 'canceled'])
                    ->name('admin.orders.byStatus');
                
                Route::get('{id}', [OrdersController::class, 'getOrderDetail'])
                    ->whereNumber('id')
                    ->name('admin.orders.show');
                
                Route::put('{id}/status', [OrdersController::class, 'updateOrderStatus'])
                    ->whereNumber('id')
                    ->name('admin.orders.updateStatus');
                
                Route::put('{id}/items/{itemId}/toggle-check', [OrdersController::class, 'toggleItemChecked'])
                    ->whereNumber(['id', 'itemId'])
                    ->name('admin.orders.toggleItemChecked');
                
                Route::get('{id}/checked-items-count', [OrdersController::class, 'getCheckedItemsCount'])
                    ->whereNumber('id')
                    ->name('admin.orders.checkedItemsCount');
            });
            
            // ==================== PAYMENTS MANAGEMENT ====================
            Route::prefix('payments')->group(function () {
                // Payment detail by order_id
                Route::get('order/{orderId}', [PaymentsController::class, 'getPaymentByOrder'])
                    ->whereNumber('orderId')
                    ->name('admin.payments.byOrder');
                
                // Payment actions
                Route::put('{paymentId}/confirm', [PaymentsController::class, 'confirmPayment'])
                    ->whereNumber('paymentId')
                    ->name('admin.payments.confirm');
                
                Route::put('{paymentId}/reject', [PaymentsController::class, 'rejectPayment'])
                    ->whereNumber('paymentId')
                    ->name('admin.payments.reject');
                
                Route::get('{paymentId}', [PaymentsController::class, 'getPaymentDetail'])
                    ->whereNumber('paymentId')
                    ->name('admin.payments.show');
            });
            
            // ==================== USER MANAGEMENT ====================
            Route::prefix('users')->group(function () {
                Route::get('{id}', [AdminController::class, 'showUser'])
                    ->whereNumber('id')
                    ->name('admin.users.show');
                
                Route::put('{id}', [AdminController::class, 'updateUser'])
                    ->whereNumber('id')
                    ->name('admin.users.update');
                
                Route::delete('{id}', [AdminController::class, 'deleteUser'])
                    ->whereNumber('id')
                    ->name('admin.users.delete');
                
                Route::post('{id}/activate', [AdminController::class, 'activateUser'])
                    ->whereNumber('id')
                    ->name('admin.users.activate');
                
                Route::post('{id}/deactivate', [AdminController::class, 'deactivateUser'])
                    ->whereNumber('id')
                    ->name('admin.users.deactivate');
            });
        });
        
        // ==================== CRSD 1 ROUTES (dengan data_access check) ====================
        // Route ini khusus untuk CRSD 1, middleware akan cek data_access:crsd1
        Route::middleware(['role:admin,superadmin|data_access:crsd1'])->group(function () {
            Route::get('crsd1/dashboard', [AdminController::class, 'dashboardCRSD1'])
                ->name('admin.crsd1.dashboard');
            
            Route::get('crsd1/orders', function(Request $request) {
                return app(AdminController::class)->getCRSDOrders($request, 'crsd1');
            })->name('admin.crsd1.orders');
            
            Route::get('crsd1/payments', function(Request $request) {
                return app(PaymentsController::class)->getCRSDPayments($request, 'crsd1');
            })->name('admin.crsd1.payments');
            
            Route::get('crsd1/payments/status/{status}', function(Request $request, $status) {
                return app(PaymentsController::class)->getCRSDPaymentsByStatus($request, 'crsd1', $status);
            })->whereIn('status', ['pending', 'completed', 'rejected', 'failed', 'expired'])
              ->name('admin.payments.crsd1.byStatus');
            
            Route::get('crsd1/users', function(Request $request) {
                return app(AdminController::class)->getCRSDUsers($request, 'crsd1');
            })->name('admin.crsd1.users');
        });
        
        // ==================== CRSD 2 ROUTES (dengan data_access check) ====================
        // Route ini khusus untuk CRSD 2, middleware akan cek data_access:crsd2
        Route::middleware(['role:admin,superadmin|data_access:crsd2'])->group(function () {
            Route::get('crsd2/dashboard', [AdminController::class, 'dashboardCRSD2'])
                ->name('admin.crsd2.dashboard');
            
            Route::get('crsd2/orders', function(Request $request) {
                return app(AdminController::class)->getCRSDOrders($request, 'crsd2');
            })->name('admin.crsd2.orders');
            
            Route::get('crsd2/payments', function(Request $request) {
                return app(PaymentsController::class)->getCRSDPayments($request, 'crsd2');
            })->name('admin.crsd2.payments');
            
            Route::get('crsd2/payments/status/{status}', function(Request $request, $status) {
                return app(PaymentsController::class)->getCRSDPaymentsByStatus($request, 'crsd2', $status);
            })->whereIn('status', ['pending', 'completed', 'rejected', 'failed', 'expired'])
              ->name('admin.payments.crsd2.byStatus');
            
            Route::get('crsd2/users', function(Request $request) {
                return app(AdminController::class)->getCRSDUsers($request, 'crsd2');
            })->name('admin.crsd2.users');
        });
    });

/*
|--------------------------------------------------------------------------
| SUPERADMIN ROUTES - SUPERADMIN ONLY
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api', 'role:superadmin'])
    ->prefix('superadmin')
    ->name('superadmin.')
    ->group(function () {
        
        // Dashboard
        Route::get('dashboard', [SuperAdminController::class, 'dashboard'])
            ->name('dashboard');
        
        // Data types - moved outside users prefix for easier access
        Route::get('data-types', [SuperAdminController::class, 'getDataTypes'])
            ->name('dataTypes');
        
        /*
        |----------------------------------------------------------------------
        | USER MANAGEMENT ROUTES
        |----------------------------------------------------------------------
        */
        Route::prefix('users')->name('users.')->group(function () {
            // Resource routes without parameters first
            Route::get('', [SuperAdminController::class, 'listAllUsers'])
                ->name('index');
            
            Route::post('', [SuperAdminController::class, 'createUser'])
                ->name('store');
            
            // Bulk operations
            Route::post('bulk/activate', [SuperAdminController::class, 'bulkActivateUsers'])
                ->name('bulk.activate');
            
            Route::post('bulk/deactivate', [SuperAdminController::class, 'bulkDeactivateUsers'])
                ->name('bulk.deactivate');
            
            // Export
            Route::get('export', [SuperAdminController::class, 'exportUsers'])
                ->name('export');
            
            // Admins with data access
            Route::get('admins', [SuperAdminController::class, 'listAdminsWithAccess'])
                ->name('admins.index');
            
            // Routes with parameters
            Route::prefix('{user}')->whereNumber('user')->group(function () {
                Route::get('', [SuperAdminController::class, 'showUser'])
                    ->name('show');
                
                Route::put('', [SuperAdminController::class, 'updateUser'])
                    ->name('update');
                
                Route::patch('', [SuperAdminController::class, 'updateUser'])
                    ->name('update-patch');
                
                // Password management
                Route::post('change-password', [SuperAdminController::class, 'changeUserPassword'])
                    ->name('changePassword');
                
                // Role management
                Route::post('role', [SuperAdminController::class, 'changeUserRole'])
                    ->name('changeRole');
                
                // Status management
                Route::post('activate', [SuperAdminController::class, 'activateUser'])
                    ->name('activate');
                
                Route::post('deactivate', [SuperAdminController::class, 'deactivateUser'])
                    ->name('deactivate');
                
                // Data access management
                Route::get('data-access', [SuperAdminController::class, 'getDataAccess'])
                    ->name('dataAccess.get');
                
                Route::post('data-access', [SuperAdminController::class, 'setDataAccess'])
                    ->name('dataAccess.set');
                
                // Check access
                Route::post('check-access', [SuperAdminController::class, 'checkUserAccess'])
                    ->name('checkAccess');
                
                // Activity logs
                Route::get('activity', [SuperAdminController::class, 'getUserActivity'])
                    ->name('activity');
                
                // Delete
                Route::delete('', [SuperAdminController::class, 'deleteUser'])
                    ->name('destroy');
            });
        });
        
        /*
        |----------------------------------------------------------------------
        | SETTINGS ROUTES
        |----------------------------------------------------------------------
        */
        Route::prefix('settings')->name('settings.')->group(function () {
            Route::get('', [SuperAdminController::class, 'getSettings'])
                ->name('index');
            
            Route::post('', [SuperAdminController::class, 'updateSettings'])
                ->name('update');
            
            Route::put('', [SuperAdminController::class, 'updateSettings'])
                ->name('update-put');
            
            // Email configuration
            Route::prefix('email-config')->group(function () {
                Route::get('', [SuperAdminController::class, 'getEmailConfig'])
                    ->name('emailConfig.index');
                
                Route::post('', [SuperAdminController::class, 'updateEmailConfig'])
                    ->name('emailConfig.update');
                
                Route::put('', [SuperAdminController::class, 'updateEmailConfig'])
                    ->name('emailConfig.update-put');
            });
        });

        /*
        |----------------------------------------------------------------------
        | PAYMENT SETTINGS ROUTES
        |----------------------------------------------------------------------
        */
        Route::prefix('payment-settings')->name('payment-settings.')->group(function () {
            Route::get('/', [PaymentSettingsController::class, 'getSettings'])
                ->name('get');
            
            Route::post('/', [PaymentSettingsController::class, 'update']) 
                ->name('store');
            
            Route::put('/', [PaymentSettingsController::class, 'update']) 
                ->name('update');
            
            Route::post('/upload-qris', [PaymentSettingsController::class, 'uploadQrisImage'])
                ->name('upload.qris');
            
            Route::delete('/delete-qris', [PaymentSettingsController::class, 'deleteQrisImage'])
                ->name('delete.qris');
        });
        
        /*
        |----------------------------------------------------------------------
        | SYSTEM MANAGEMENT ROUTES
        |----------------------------------------------------------------------
        */
        Route::prefix('system')->name('system.')->group(function () {
            Route::get('logs', [SuperAdminController::class, 'getLogs'])
                ->name('logs');
            
            Route::post('clear-cache', [SuperAdminController::class, 'clearCache'])
                ->name('clearCache');
            
            Route::get('health', [SuperAdminController::class, 'systemHealth'])
                ->name('health');
            
            // Database maintenance
            Route::prefix('database')->group(function () {
                Route::get('stats', [SuperAdminController::class, 'getDatabaseStats'])
                    ->name('database.stats');
            });
            
            // Backup management
            Route::prefix('backup')->group(function () {
                Route::post('create', [SuperAdminController::class, 'createBackup'])
                    ->name('backup.create');
                
                Route::get('list', [SuperAdminController::class, 'listBackups'])
                    ->name('backup.list');
            });
        });
        
        /*
        |----------------------------------------------------------------------
        | REPORTS ROUTES
        |----------------------------------------------------------------------
        */
        Route::prefix('reports')->name('reports.')->group(function () {
            Route::get('', [SuperAdminController::class, 'getReports'])
                ->name('index');
            
            Route::get('users', [SuperAdminController::class, 'getUsersReport'])
                ->name('users');
            
            Route::get('orders', [SuperAdminController::class, 'getOrdersReport'])
                ->name('orders');
            
            Route::get('payments', [SuperAdminController::class, 'getPaymentsReport'])
                ->name('payments');
            
            Route::get('revenue', [SuperAdminController::class, 'getRevenueReport'])
                ->name('revenue');
            
            // Date range reports
            Route::get('date-range', [SuperAdminController::class, 'getDateRangeReport'])
                ->name('dateRange');
            
            // Export
            Route::post('export', [SuperAdminController::class, 'exportReports'])
                ->name('export');
        });
        
        /*
        |----------------------------------------------------------------------
        | AUDIT LOGS ROUTES
        |----------------------------------------------------------------------
        */
        Route::prefix('audit')->name('audit.')->group(function () {
            Route::get('', [SuperAdminController::class, 'getAuditLogs'])
                ->name('index');
            
            Route::get('user-activity', [SuperAdminController::class, 'getUserActivityLogs'])
                ->name('userActivity');
            
            Route::get('login-history', [SuperAdminController::class, 'getLoginHistory'])
                ->name('loginHistory');
            
            Route::get('system-events', [SuperAdminController::class, 'getSystemEvents'])
                ->name('systemEvents');
        });
        
        /*
        |----------------------------------------------------------------------
        | NOTIFICATION MANAGEMENT
        |----------------------------------------------------------------------
        */
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('', [SuperAdminController::class, 'getNotifications'])
                ->name('index');
            
            Route::post('send', [SuperAdminController::class, 'sendNotification'])
                ->name('send');
            
            Route::get('templates', [SuperAdminController::class, 'getNotificationTemplates'])
                ->name('templates');
        });
        
        /*
        |----------------------------------------------------------------------
        | API MANAGEMENT
        |----------------------------------------------------------------------
        */
        Route::prefix('api')->name('api.')->group(function () {
            Route::get('keys', [SuperAdminController::class, 'getApiKeys'])
                ->name('keys.index');
            
            Route::post('keys/generate', [SuperAdminController::class, 'generateApiKey'])
                ->name('keys.generate');
            
            Route::delete('keys/{id}', [SuperAdminController::class, 'deleteApiKey'])
                ->whereNumber('id')
                ->name('keys.delete');
        });
        
        /*
        |----------------------------------------------------------------------
        | ANALYTICS DASHBOARD
        |----------------------------------------------------------------------
        */
        Route::prefix('analytics')->name('analytics.')->group(function () {
            Route::get('overview', [SuperAdminController::class, 'getAnalyticsOverview'])
                ->name('overview');
            
            Route::get('user-growth', [SuperAdminController::class, 'getUserGrowthAnalytics'])
                ->name('userGrowth');
            
            Route::get('revenue-trends', [SuperAdminController::class, 'getRevenueTrendsAnalytics'])
                ->name('revenueTrends');
        });
    });

/*
|--------------------------------------------------------------------------
| PUBLIC PAYMENT METHODS ROUTE (untuk checkout page)
|--------------------------------------------------------------------------
*/
Route::get('/payment-methods', [PaymentSettingsController::class, 'getPaymentMethods'])
    ->name('payment.methods');

/*
|--------------------------------------------------------------------------
| DEBUG ROUTES (Temporary)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:api'])->get('/debug/test-orders', function() {
    try {
        $user = Auth::guard('api')->user();
        
        // Test database connection
        $ordersCount = \App\Models\Orders::count();
        $usersCount = \App\Models\User::count();
        
        // Get some sample orders
        $sampleOrders = \App\Models\Orders::with('user')
            ->limit(5)
            ->get()
            ->map(function($order) {
                return [
                    'id' => $order->id,
                    'order_code' => $order->order_code,
                    'user_id' => $order->user_id,
                    'user_name' => $order->user->name ?? 'N/A',
                    'user_divisi' => $order->user->divisi ?? 'N/A',
                    'status' => $order->status,
                    'order_status' => $order->order_status,
                    'total_price' => $order->total_price,
                ];
            });
        
        return response()->json([
            'success' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'divisi' => $user->divisi,
                'metadata' => $user->metadata,
            ],
            'database' => [
                'orders_count' => $ordersCount,
                'users_count' => $usersCount,
            ],
            'sample_orders' => $sampleOrders,
            'can_access_admin' => in_array($user->role, ['admin', 'superadmin']),
            'model_class' => class_exists('\App\Models\Order') ? 'Order model exists' : 'Order model NOT found',
        ]);
        
    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ], 500);
    }
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
        'timestamp' => now()->toIso8601String()
    ], 404);
});