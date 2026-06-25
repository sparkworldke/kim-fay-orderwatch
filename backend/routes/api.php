<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\Admin\AcumaticaController;
use App\Http\Controllers\Api\Admin\AiConnectorController;
use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\HealthController;
use App\Http\Controllers\Api\Admin\NotificationRuleController;
use App\Http\Controllers\Api\Admin\PermissionController;
use App\Http\Controllers\Api\Admin\RoleController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AcumaticaImportController;
use App\Http\Controllers\Api\Admin\EmailImportConfigController;
use App\Http\Controllers\Api\Admin\OrderMatchingController;
use App\Http\Controllers\Api\Admin\MailboxFolderController;
use App\Http\Controllers\Api\Admin\AiPromptLogController;
use App\Http\Controllers\Api\Admin\CronJobController;
use App\Http\Controllers\Api\Admin\DailyReportController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiIntelligenceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerFeedController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\EmailFilterController;
use App\Http\Controllers\Api\MailboxController;
use App\Http\Controllers\Api\OrderMatchController;
use App\Http\Controllers\Api\OperationsController;
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

    // Customer Feed — grouped customer performance
    Route::get('customer-feed',                    [CustomerFeedController::class, 'index']);
    Route::get('customer-feed/{groupKey}/insights', [CustomerFeedController::class, 'insights']);

    // Operations — inventory, backorders, fill rate
    Route::prefix('operations')->group(function () {
        Route::get('inventory/summary',              [OperationsController::class, 'inventorySummary']);
        Route::get('inventory',                       [OperationsController::class, 'inventory']);
        Route::get('inventory/{id}/prediction',       [OperationsController::class, 'inventoryPrediction']);
        Route::get('backorders/summary',              [OperationsController::class, 'backordersSummary']);
        Route::get('backorders',                      [OperationsController::class, 'backorders']);
        Route::get('backorders/by-account',           [OperationsController::class, 'backordersByAccount']);
        Route::get('fill-rate/summary',               [OperationsController::class, 'fillRateSummary']);
        Route::get('fill-rate',                       [OperationsController::class, 'fillRate']);
        Route::get('status',                          [OperationsController::class, 'opsStatus']);
        Route::get('business-optimization',           [OperationsController::class, 'businessOptimization']);
    });

    // Orders
    Route::get('orders/stats', [OrderController::class, 'stats']);
    Route::apiResource('orders', OrderController::class);

    // Sales order import history (Acumatica sync results)
    Route::get('so-imports',              [AcumaticaImportController::class, 'index']);
    Route::get('so-imports/customers',    [AcumaticaImportController::class, 'customers']);
    Route::get('so-imports/emails',       [AcumaticaImportController::class, 'emails']);
    Route::get('so-imports/workflow',     [AcumaticaImportController::class, 'workflow']);

    // Order Match (PRD)
    Route::prefix('order-match')->group(function () {
        Route::get('folders', [OrderMatchController::class, 'listFolders']);
        Route::post('folders', [OrderMatchController::class, 'registerFolder']);
        Route::post('folders/{folderId}/sync', [OrderMatchController::class, 'syncFolder']);
        Route::post('run', [OrderMatchController::class, 'runPipeline']);
        Route::get('queue', [OrderMatchController::class, 'queue']);
        Route::get('audit-log', [OrderMatchController::class, 'auditLog']);
        Route::get('audit-log/export', [OrderMatchController::class, 'exportAuditLog']);
        Route::post('matches/{email}/accept', [OrderMatchController::class, 'accept']);
        Route::post('matches/{email}/reject', [OrderMatchController::class, 'reject']);
        Route::post('matches/{email}/duplicate', [OrderMatchController::class, 'markDuplicate']);
        Route::post('matches/{email}/rerun', [OrderMatchController::class, 'rerun']);
    });

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
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
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
        Route::post('acumatica/sync/inventory',        [AcumaticaController::class, 'syncInventory']);
        Route::post('acumatica/sync/inventory-stocks', [AcumaticaController::class, 'syncInventoryStocks']);
        Route::post('acumatica/sync/backorders',      [AcumaticaController::class, 'syncBackorders']);
        Route::post('acumatica/sync/fill-rate',       [AcumaticaController::class, 'syncFillRate']);
        Route::post('acumatica/sync/credit-notes-more', [AcumaticaController::class, 'syncCreditNotesAndMore']);
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
        Route::post('order-matching/{email}/review',      [OrderMatchingController::class, 'review']);
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
        Route::get('cron-jobs', [CronJobController::class, 'index']);
        Route::get('cron-jobs/{cronJob}', [CronJobController::class, 'show']);
        Route::patch('cron-jobs/{cronJob}', [CronJobController::class, 'update']);
        Route::post('cron-jobs/{cronJob}/run', [CronJobController::class, 'run']);
        Route::get('cron-jobs/{cronJob}/runs', [CronJobController::class, 'runs']);

        // Daily management report
        Route::get('daily-reports/config', [DailyReportController::class, 'show']);
        Route::put('daily-reports/config', [DailyReportController::class, 'update']);
        Route::post('daily-reports/test-send', [DailyReportController::class, 'testSend']);
        Route::post('daily-reports/resend-last', [DailyReportController::class, 'resendLast']);
        Route::get('daily-reports/runs', [DailyReportController::class, 'runs']);

        // AI prompt logs
        Route::get('ai-prompt-logs',       [AiPromptLogController::class, 'index']);
        Route::get('ai-prompt-logs/stats', [AiPromptLogController::class, 'stats']);

        // Mailboxes (Outlook OAuth + sync)
        Route::get('mailboxes',                           [MailboxController::class, 'index']);
        Route::post('mailboxes/oauth/start',              [MailboxController::class, 'startOAuth']);
        Route::match(['get', 'post'], 'mailboxes/oauth/check', [MailboxController::class, 'checkOAuth']);
        Route::match(['put', 'patch'], 'mailboxes/{mailbox}', [MailboxController::class, 'update']);
        Route::post('mailboxes/sync-all',                 [MailboxController::class, 'syncAll']);
        Route::post('mailboxes/{mailbox}/sync',           [MailboxController::class, 'sync']);
        Route::post('mailboxes/{mailbox}/sync-logs/{logId}/stop', [MailboxController::class, 'stopSync']);
        Route::get('mailboxes/{mailbox}/sync-logs',       [MailboxController::class, 'syncLogs']);
        Route::get('mailboxes/{mailbox}/folders',         [MailboxFolderController::class, 'index']);
        Route::post('mailboxes/{mailbox}/folders/discover', [MailboxFolderController::class, 'discover']);
        Route::patch('mailbox-folders/{folder}',          [MailboxFolderController::class, 'update']);
        Route::post('mailbox-folders/{folder}/sync',       [MailboxFolderController::class, 'sync']);
        Route::get('mailbox-folder-sync-runs/{run}', [MailboxFolderController::class, 'syncRun']);
        Route::get('mailbox-folder-sync-runs/{run}/emails', [MailboxFolderController::class, 'syncRunEmails']);
        Route::post('mailbox-folders/{folder}/test',      [MailboxFolderController::class, 'test']);
        Route::post('mailbox-rule-mappings',              [MailboxFolderController::class, 'storeRule']);
        Route::patch('mailbox-rule-mappings/{rule}',      [MailboxFolderController::class, 'updateRule']);
        Route::delete('mailbox-rule-mappings/{rule}',     [MailboxFolderController::class, 'destroyRule']);
        Route::get('ingestion-reviews',                   [MailboxFolderController::class, 'reviews']);
        Route::post('ingestion-reviews/{email}',          [MailboxFolderController::class, 'review']);
        Route::delete('mailboxes/{mailbox}',              [MailboxController::class, 'destroy']);
    });

    // AI chat — available to all authenticated users
    Route::post('ai/chat', [AiChatController::class, 'chat']);
    Route::get('ai/intelligence', [AiIntelligenceController::class, 'briefing']);
    Route::post('ai/intelligence/generate', [AiIntelligenceController::class, 'generate']);

    // Emails (readable by any authenticated user)
    Route::get('emails/inbox-groups',                     [EmailController::class, 'inboxGroups']);
    Route::get('emails',                                  [EmailController::class, 'index']);

    // Email filters
    Route::get('email-filters',                           [EmailFilterController::class, 'index']);
    Route::post('email-filters',                          [EmailFilterController::class, 'store']);
    Route::patch('email-filters/{emailFilter}',           [EmailFilterController::class, 'update']);
    Route::post('email-filters/{emailFilter}/sync',       [EmailFilterController::class, 'sync']);
    Route::delete('email-filters/{emailFilter}',          [EmailFilterController::class, 'destroy']);
});
