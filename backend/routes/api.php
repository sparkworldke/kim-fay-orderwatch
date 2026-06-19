<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\Admin\AcumaticaController;
use App\Http\Controllers\Api\Admin\AiConnectorController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\HealthController;
use App\Http\Controllers\Api\Admin\NotificationRuleController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Kim-Fay OrderWatch — API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api automatically by Laravel.
| Protected routes require a valid Sanctum Bearer token.
|
*/

// --- Public: Auth ---
Route::prefix('auth')->group(function () {
    Route::post('login',         [AuthController::class, 'login']);
    Route::post('email/check',   [OtpController::class, 'checkEmail'])->middleware('throttle:email-check');
    Route::post('otp/request',   [OtpController::class, 'request'])->middleware('throttle:otp-request');
    Route::post('otp/verify',    [OtpController::class, 'verify'])->middleware('throttle:otp-verify');
});

// --- Protected ---
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('me',     [AuthController::class, 'me']);
        Route::post('logout',[AuthController::class, 'logout']);
    });

    // Dashboard KPIs
    Route::get('dashboard/kpis', [DashboardController::class, 'kpis']);

    // Orders
    Route::apiResource('orders', OrderController::class);

    // Customers
    Route::apiResource('customers', CustomerController::class);

    // Profile
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::get('profile/sign-in-logs', [ProfileController::class, 'signInLogs']);

    // Admin
    Route::prefix('admin')->middleware('admin.only')->group(function () {
        Route::get('users/{user}/sign-in-logs', [AdminController::class, 'userSignInLogs']);

        Route::get('health', [HealthController::class, 'index']);

        Route::get('ai-keys', [AiConnectorController::class, 'index']);
        Route::post('ai-keys', [AiConnectorController::class, 'store']);
        Route::delete('ai-keys/{id}', [AiConnectorController::class, 'destroy']);

        Route::get('acumatica', [AcumaticaController::class, 'index']);
        Route::put('acumatica', [AcumaticaController::class, 'update']);
        Route::post('acumatica/validate', [AcumaticaController::class, 'validateCredentials']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [PermissionController::class, 'index']);

        Route::get('notification-rules', [NotificationRuleController::class, 'index']);
        Route::put('notification-rules/{id}', [NotificationRuleController::class, 'update']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);
    });
});
