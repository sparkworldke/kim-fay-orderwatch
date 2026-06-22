<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\Admin\AcumaticaController;
use App\Http\Controllers\Api\Admin\AiConnectorController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\HealthController;
use App\Http\Controllers\Api\Admin\NotificationRuleController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\AcumaticaImportController;
use App\Http\Controllers\Api\Admin\EmailImportConfigController;
use App\Http\Controllers\Api\Admin\OrderMatchingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\EmailFilterController;
use App\Http\Controllers\Api\MailboxController;
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

// --- Public: Microsoft OAuth Callback (no token — redirect flow) ---
Route::get('admin/mailboxes/oauth/callback', [MailboxController::class, 'handleCallback'])
    ->name('mailbox.oauth.callback');

// --- Protected ---
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('me',     [AuthController::class, 'me']);
        Route::post('logout',[AuthController::class, 'logout']);
    });

    // Dashboard KPIs + trend
    Route::get('dashboard/kpis',   [DashboardController::class, 'kpis']);
    Route::get('dashboard/trend',  [DashboardController::class, 'trend']);

    // Orders
    Route::get('orders/stats', [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class);

    // Sales order import history (Acumatica sync results)
    Route::get('so-imports',              [AcumaticaImportController::class, 'index']);
    Route::get('so-imports/customers',    [AcumaticaImportController::class, 'customers']);
    Route::get('so-imports/emails',       [AcumaticaImportController::class, 'emails']);
    Route::get('so-imports/workflow',     [AcumaticaImportController::class, 'workflow']);

    // Customers
    Route::get('customers/categories',               [CustomerController::class, 'categories']);
    Route::get('customers/by-category/{class}',      [CustomerController::class, 'byCategory']);
    Route::patch('customers/{id}/set-parent',        [CustomerController::class, 'setParent']);
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

        // Acumatica config
        Route::get('acumatica',           [AcumaticaController::class, 'index']);
        Route::put('acumatica',           [AcumaticaController::class, 'update']);
        Route::post('acumatica/validate', [AcumaticaController::class, 'validateCredentials']);

        // Acumatica sync triggers
        Route::post('acumatica/sync/customers',       [AcumaticaController::class, 'syncCustomers']);
        Route::post('acumatica/sync/orders',          [AcumaticaController::class, 'syncOrders']);
        Route::post('acumatica/sync/customer-orders', [AcumaticaController::class, 'syncCustomerOrders']);
        Route::get('acumatica/sync/logs',             [AcumaticaController::class, 'syncLogs']);

        // Reconciliation
        Route::get('acumatica/reconciliation',        [AcumaticaController::class, 'reconciliation']);
        Route::patch('acumatica/reconciliation/{id}', [AcumaticaController::class, 'updateReconciliationStatus']);

        // Dead letters
        Route::get('acumatica/dead-letters',          [AcumaticaController::class, 'deadLetters']);

        // Customer search for selective sync
        Route::get('acumatica/customers/search',         [AcumaticaController::class, 'searchCustomers']);
        Route::get('acumatica/customers/{customerId}',   [AcumaticaController::class, 'previewCustomer']);

        // Email import configuration
        Route::get('email-import-configs',               [EmailImportConfigController::class, 'index']);
        Route::post('email-import-configs',              [EmailImportConfigController::class, 'store']);
        Route::put('email-import-configs/{id}',          [EmailImportConfigController::class, 'update']);
        Route::delete('email-import-configs/{id}',       [EmailImportConfigController::class, 'destroy']);
        Route::post('email-import-configs/test-sender',  [EmailImportConfigController::class, 'testSender']);

        // Order matching & PO extraction
        Route::post('order-matching/extract-po',         [OrderMatchingController::class, 'extractPo']);
        Route::post('order-matching/match',              [OrderMatchingController::class, 'matchOrders']);
        Route::post('order-matching/run-all',            [OrderMatchingController::class, 'runAll']);
        Route::post('order-matching/override-po',        [OrderMatchingController::class, 'overridePo']);
        Route::get('order-matching/history',             [OrderMatchingController::class, 'history']);
        Route::get('order-matching/pending-manual',      [OrderMatchingController::class, 'pendingManual']);
        Route::get('acumatica/orders/{orderNbr}',        [AcumaticaController::class, 'previewOrder']);

        // Data truncation (admin only)
        Route::post('so-imports/truncate/orders',    [AcumaticaImportController::class, 'truncateOrders']);
        Route::post('so-imports/truncate/customers', [AcumaticaImportController::class, 'truncateCustomers']);
        Route::post('so-imports/truncate/emails',    [AcumaticaImportController::class, 'truncateEmails']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::get('permissions', [PermissionController::class, 'index']);

        Route::get('notification-rules', [NotificationRuleController::class, 'index']);
        Route::put('notification-rules/{id}', [NotificationRuleController::class, 'update']);

        Route::get('audit-logs', [AuditLogController::class, 'index']);

        // Mailboxes (Outlook OAuth + sync)
        Route::get('mailboxes',                           [MailboxController::class, 'index']);
        Route::post('mailboxes/oauth/start',              [MailboxController::class, 'startOAuth']);
        Route::get('mailboxes/oauth/check',               [MailboxController::class, 'checkOAuth']);
        Route::match(['put', 'patch'], 'mailboxes/{mailbox}', [MailboxController::class, 'update']);
        Route::post('mailboxes/{mailbox}/sync',           [MailboxController::class, 'sync']);
        Route::get('mailboxes/{mailbox}/sync-logs',       [MailboxController::class, 'syncLogs']);
        Route::delete('mailboxes/{mailbox}',              [MailboxController::class, 'destroy']);
    });

    // Emails (readable by any authenticated user)
    Route::get('emails',                                  [EmailController::class, 'index']);

    // Email filters
    Route::get('email-filters',                           [EmailFilterController::class, 'index']);
    Route::post('email-filters',                          [EmailFilterController::class, 'store']);
    Route::patch('email-filters/{emailFilter}',           [EmailFilterController::class, 'update']);
    Route::post('email-filters/{emailFilter}/sync',       [EmailFilterController::class, 'sync']);
    Route::delete('email-filters/{emailFilter}',          [EmailFilterController::class, 'destroy']);
});
