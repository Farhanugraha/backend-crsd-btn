<?php
// 2. Route Structure
// File: routes/api.php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\SuperAdminController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Public routes - tidak perlu token
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
    
    // Protected routes - perlu token
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('refresh', [AuthController::class, 'refresh']);
        Route::put('profile', [AuthController::class, 'updateProfile']);
        Route::patch('profile', [AuthController::class, 'updateProfile']);
    });
});

// ============================================
// ROUTES KHUSUS ADMIN
// ============================================
Route::prefix('admin')->middleware(['auth:api', 'role:admin'])->group(function () {
    // Dashboard
    Route::get('dashboard', [AdminController::class, 'dashboard']);
    
    // Manage Users
    Route::get('users', [AdminController::class, 'listUsers']);
    Route::get('users/{id}', [AdminController::class, 'showUser']);
    Route::put('users/{id}', [AdminController::class, 'updateUser']);
    Route::delete('users/{id}', [AdminController::class, 'deleteUser']);
});

// ============================================
// ROUTES KHUSUS SUPERADMIN
// ============================================
Route::prefix('superadmin')->middleware(['auth:api', 'role:superadmin'])->group(function () {
    // Dashboard
    Route::get('dashboard', [SuperAdminController::class, 'dashboard']);
    
    // Manage All Users & Admin
    Route::get('users', [SuperAdminController::class, 'listAllUsers']);
    Route::post('users/{id}/role', [SuperAdminController::class, 'changeUserRole']);
    Route::delete('users/{id}', [SuperAdminController::class, 'deleteUser']);
    
    // System Settings
    Route::get('settings', [SuperAdminController::class, 'getSettings']);
    Route::post('settings', [SuperAdminController::class, 'updateSettings']);
});
?>