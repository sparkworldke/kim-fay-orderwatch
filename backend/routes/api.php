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
use App\Http\Controllers\Api\Admin\DataManagementController;
use App\Http\Controllers\Api\Admin\DeliverySlaConfigController;
use App\Http\Controllers\Api\Admin\FolSettingsController;
use App\Http\Controllers\Api\Admin\ImpersonationController;
use App\Http\Controllers\Api\Admin\PriceChangeSettingsController;
use App\Http\Controllers\Api\Admin\SalesManagementSettingsController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiIntelligenceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapabilitiesController;
use App\Http\Controllers\Api\Admin\CustomerDepartmentController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\TeamImportController;
use App\Http\Controllers\Api\Admin\UserAssignmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerFeedController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\EmailController;
use App\Http\Controllers\Api\EmailFilterController;
use App\Http\Controllers\Api\FolController;
use App\Http\Controllers\Api\MailboxController;
use App\Http\Controllers\Api\OrderMatchController;
use App\Http\Controllers\Api\InventoryInsightController;
use App\Http\Controllers\Api\InventorySkuDetailController;
use App\Http\Controllers\Api\OperationsController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OtpController;
use App\Http\Controllers\Api\PriceChangeRequestController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SalesConsultantController;
use App\Http\Controllers\Api\SalesManagementPromptController;
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
    Route::middleware('view.only')->group(function () {

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('me',     [AuthController::class, 'me']);
        Route::get('capabilities', CapabilitiesController::class);
        Route::post('logout',[AuthController::class, 'logout']);
        // Stop must work while impersonating (token is the target user, not admin)
        Route::post('impersonate/stop', [ImpersonationController::class, 'stop']);
    });

    // Dashboard KPIs + trend
    Route::get('dashboard/kpis',              [DashboardController::class, 'kpis']);
    Route::get('dashboard/trend',             [DashboardController::class, 'trend']);
    Route::get('dashboard/orders-by-status',  [DashboardController::class, 'ordersByStatus']);
    Route::get('dashboard/goods-lost-in-transit', [DashboardController::class, 'goodsLostInTransit']);

    // Customer Feed — grouped customer performance
    Route::get('customer-feed',                    [CustomerFeedController::class, 'index']);
    Route::get('customer-feed/{groupKey}/insights', [CustomerFeedController::class, 'insights']);

    // Operations — inventory, backorders, fill rate
    Route::prefix('operations')->group(function () {
        Route::prefix('sales-management')->group(function () {
            Route::get('prompts/dashboard', [SalesManagementPromptController::class, 'dashboard']);
            Route::get('prompts', [SalesManagementPromptController::class, 'index']);
            Route::post('prompts/{prompt}/resolve', [SalesManagementPromptController::class, 'resolve'])->whereNumber('prompt');
            Route::post('prompts/{prompt}/snooze', [SalesManagementPromptController::class, 'snooze'])->whereNumber('prompt');
            Route::post('prompts/{prompt}/dismiss', [SalesManagementPromptController::class, 'dismiss'])->whereNumber('prompt');
        });
        Route::prefix('price-change-requests')->group(function () {
            Route::get('dashboard', [PriceChangeRequestController::class, 'dashboard']);
            Route::get('customers/search', [PriceChangeRequestController::class, 'searchCustomers']);
            Route::get('inventory/search', [PriceChangeRequestController::class, 'searchInventory']);
            Route::get('resolve-price', [PriceChangeRequestController::class, 'resolvePrice']);
            Route::get('/', [PriceChangeRequestController::class, 'index']);
            Route::post('/', [PriceChangeRequestController::class, 'store']);
            Route::get('{priceChangeRequest}', [PriceChangeRequestController::class, 'show'])->whereNumber('priceChangeRequest');
            Route::post('{priceChangeRequest}/decisions', [PriceChangeRequestController::class, 'decision'])->whereNumber('priceChangeRequest');
            Route::post('{priceChangeRequest}/acknowledge-duplicate', [PriceChangeRequestController::class, 'acknowledgeDuplicate'])->whereNumber('priceChangeRequest');
            Route::post('{priceChangeRequest}/mark-applied-erp', [PriceChangeRequestController::class, 'markAppliedErp'])->whereNumber('priceChangeRequest');
        });
        Route::get('inventory/summary',              [OperationsController::class, 'inventorySummary']);
        Route::get('inventory/export',               [OperationsController::class, 'exportInventory']);
        Route::get('inventory',                       [OperationsController::class, 'inventory']);
        Route::get('inventory/{id}/prediction',       [OperationsController::class, 'inventoryPrediction']);
        Route::get('inventory/{inventoryId}/sku-detail', [InventorySkuDetailController::class, 'show']);
        Route::get('inventory/{inventoryId}/insights',   [InventoryInsightController::class, 'show']);
        Route::get('backorders/summary',              [OperationsController::class, 'backordersSummary']);
        Route::get('backorders/analytics',            [OperationsController::class, 'backordersAnalytics']);
        Route::get('backorders/sku-breakdown',         [OperationsController::class, 'backordersSkuBreakdown']);
        Route::get('backorders/sku-breakdown/export',  [OperationsController::class, 'exportBackordersSkuBreakdown'])->middleware('admin.or.manager');
        Route::get('backorders/export',               [OperationsController::class, 'exportBackorders']);
        Route::get('backorders',                      [OperationsController::class, 'backorders']);
        Route::patch('backorders/{backorderLine}',    [OperationsController::class, 'updateBackorderReason']);
        Route::get('backorders/by-account',           [OperationsController::class, 'backordersByAccount']);
        Route::get('brand-filter-options',           [OperationsController::class, 'brandFilterOptions']);
        Route::get('reason-taxonomy',                [OperationsController::class, 'reasonTaxonomy']);
        Route::get('so-reason-audit',                 [OperationsController::class, 'soReasonAudit']);
        Route::get('fill-rate/summary',               [OperationsController::class, 'fillRateSummary']);
        Route::get('fill-rate/sku-breakdown',         [OperationsController::class, 'fillRateSkuBreakdown']);
        Route::get('fill-rate/sku-breakdown/export',  [OperationsController::class, 'exportFillRateSkuBreakdown'])->middleware('admin.or.manager');
        Route::get('fill-rate/out-of-stock',          [OperationsController::class, 'fillRateOutOfStockReport']);
        Route::get('fill-rate/out-of-stock/export',   [OperationsController::class, 'exportFillRateOutOfStockReport'])->middleware('admin.or.manager');
        Route::get('fill-rate/export',                [OperationsController::class, 'exportFillRate'])->middleware('admin.or.manager');
        Route::get('fill-rate',                       [OperationsController::class, 'fillRate']);
        Route::get('status',                          [OperationsController::class, 'opsStatus']);
        Route::get('business-optimization',           [OperationsController::class, 'businessOptimization']);
        Route::get('sales-consultants',               [SalesConsultantController::class, 'index']);
        Route::post('sales-consultants/import',       [SalesConsultantController::class, 'import'])->middleware('admin.or.manager');
        Route::get('sales-consultants/{id}',          [SalesConsultantController::class, 'show'])->whereNumber('id');
        Route::get('sales-consultants/{id}/customers', [SalesConsultantController::class, 'customersById'])->whereNumber('id');
        Route::get('sales-consultants/{repCode}/customers', [SalesConsultantController::class, 'customers']);
        Route::get('sales-consultants/{repCode}',     [SalesConsultantController::class, 'showByRepCode']);
    });

    // Orders
    Route::get('orders/stats', [OrderController::class, 'stats']);
    Route::post('orders-status-refresh', [AcumaticaController::class, 'refreshOrderStatuses']);
    Route::post('orders/status-refresh', [AcumaticaController::class, 'refreshOrderStatuses']);
    Route::patch('orders/{id}/consultant', [OrderController::class, 'assignConsultant'])->whereNumber('id');
    Route::apiResource('orders', OrderController::class);

    // KP Free On Loan (FOL)
    Route::prefix('kp/fol')->group(function () {
        Route::get('customers/search', [FolController::class, 'searchCustomers']);
        Route::get('inventory/search', [FolController::class, 'searchInventory']);
        Route::get('metrics', [FolController::class, 'metrics']);
        Route::get('technicians', [FolController::class, 'technicians']);
        Route::get('technician/calendar', [FolController::class, 'technicianCalendar']);
        Route::get('/', [FolController::class, 'index']);
        Route::post('/', [FolController::class, 'store']);
        Route::get('{folRequest}', [FolController::class, 'show'])->whereNumber('folRequest');
        Route::post('{folRequest}/submit', [FolController::class, 'submit'])->whereNumber('folRequest');
        Route::post('{folRequest}/decision', [FolController::class, 'decision'])->whereNumber('folRequest');
        Route::post('{folRequest}/attachments', [FolController::class, 'attach'])->whereNumber('folRequest');
        Route::post('{folRequest}/so-links', [FolController::class, 'linkSalesOrder'])->whereNumber('folRequest');
        Route::post('{folRequest}/po-links', [FolController::class, 'matchPurchaseOrder'])->whereNumber('folRequest');
        Route::post('{folRequest}/technician', [FolController::class, 'assignTechnician'])->whereNumber('folRequest');
        Route::post('{folRequest}/technician/resolve', [FolController::class, 'resolveTechnician'])->whereNumber('folRequest');
    });

    // Sales order import history (Acumatica sync results)
    Route::get('so-imports',              [AcumaticaImportController::class, 'index']);
    Route::get('so-imports/customers',    [AcumaticaImportController::class, 'customers']);
    Route::get('so-imports/emails',       [AcumaticaImportController::class, 'emails']);
    Route::get('so-imports/workflow',     [AcumaticaImportController::class, 'workflow']);

    // Order Match (PRD)
    Route::prefix('order-match')->middleware('admin.or.cs')->group(function () {
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
    Route::get('customers/shipping-zones',           [CustomerController::class, 'shippingZones']);
    Route::get('customers/categories',               [CustomerController::class, 'categories']);
    Route::get('customers/by-category/{class}',      [CustomerController::class, 'byCategory']);
    Route::patch('customers/{id}/set-parent',        [CustomerController::class, 'setParent']);
    Route::get('customers/{id}/suggested-orders',    [CustomerController::class, 'suggestedOrders']);
    Route::get('customers/{id}/common-products',     [CustomerController::class, 'commonProducts']);
    Route::apiResource('customers', CustomerController::class);

    // Profile
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::get('profile/sign-in-logs', [ProfileController::class, 'signInLogs']);
    Route::get('profile/sessions', [ProfileController::class, 'sessions']);
    Route::post('profile/password/otp', [ProfileController::class, 'requestPasswordUpdateOtp']);
    Route::post('profile/password/otp/verify', [ProfileController::class, 'verifyPasswordUpdateOtp']);
    Route::patch('profile/password', [ProfileController::class, 'updatePassword']);

    // Admin
    Route::prefix('admin')->middleware('admin.or.cs')->group(function () {
        Route::get('health', [HealthController::class, 'index']);
        Route::get('mail-settings', [HealthController::class, 'mailSettings']);

        // Email import configuration
        Route::get('email-import-configs',               [EmailImportConfigController::class, 'index']);
        Route::get('email-import-configs/metrics',       [EmailImportConfigController::class, 'metrics']);
        Route::post('email-import-configs',              [EmailImportConfigController::class, 'store']);
        Route::put('email-import-configs/{id}',          [EmailImportConfigController::class, 'update']);
        Route::post('email-import-configs/{id}/approve', [EmailImportConfigController::class, 'approve']);
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

        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('cron-jobs', [CronJobController::class, 'index']);
        Route::get('cron-jobs/{cronJob}', [CronJobController::class, 'show']);
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

    Route::prefix('admin')->middleware('admin.or.manager')->group(function () {
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        // Dynamic reports-to options (create form — any active user)
        Route::get('users/reports-to-options', [UserController::class, 'reportsToOptions']);
        // Bulk activate users + set email_verified_at
        Route::post('users/bulk-activate', [UserController::class, 'bulkActivate']);
        Route::patch('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/resend-welcome', [UserController::class, 'resendWelcomeEmail']);
        Route::post('users/{user}/password', [UserController::class, 'updatePassword']);
        Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::get('users/{user}/reports-to-options', [UserController::class, 'reportsToOptions']);
        Route::get('users/{user}/rep-code-history', [UserController::class, 'repCodeHistory']);
        Route::post('users/{user}/rep-code-history/{historyEntry}/restore', [UserController::class, 'restoreRepCode']);
        Route::get('users/{user}/acumatica-rep-mappings', [UserController::class, 'acumaticaRepMappings']);
        Route::post('users/{user}/acumatica-rep-mappings', [UserController::class, 'storeAcumaticaRepMapping']);
        Route::get('departments', [DepartmentController::class, 'index']);
        Route::put('departments/{department}/brands', [DepartmentController::class, 'syncBrands']);
        Route::post('team/import-staff', [TeamImportController::class, 'import']);
        Route::get('team/import-gaps', [TeamImportController::class, 'gaps']);
        Route::patch('team/import-gaps/{gap}', [TeamImportController::class, 'resolveGap']);
        Route::post('team/import-gaps/{gap}/create-user', [TeamImportController::class, 'createUserFromGap']);
        Route::post('team/seed-org-tree', [TeamImportController::class, 'seedOrgTree']);
        Route::get('customers/search', [UserAssignmentController::class, 'searchCustomers']);
        Route::get('customer-assignments/sources', [UserAssignmentController::class, 'assignmentSources']);
        Route::post('customer-assignments/upload', [UserAssignmentController::class, 'uploadCustomerAssignments']);
        Route::get('customer-assignments/batches/{batch}', [UserAssignmentController::class, 'showAssignmentBatch'])->whereNumber('batch');
        Route::post('customer-assignments/batches/{batch}/apply', [UserAssignmentController::class, 'applyAssignmentBatch'])->whereNumber('batch');
        Route::get('brand-options', [UserAssignmentController::class, 'brandOptions']);
        Route::get('users/{user}/brand-assignments', [UserAssignmentController::class, 'brandAssignments']);
        Route::put('users/{user}/brand-assignments', [UserAssignmentController::class, 'syncBrandAssignments']);
        Route::get('users/{user}/customer-assignments', [UserAssignmentController::class, 'customerAssignments']);
        Route::put('users/{user}/customer-assignments', [UserAssignmentController::class, 'syncCustomerAssignments']);
        Route::post('users/{user}/backfill-customers', [UserAssignmentController::class, 'backfillCustomers']);
        Route::post('users/{user}/customer-assignments/match-so', [UserAssignmentController::class, 'previewSalesOrderMatch']);
        Route::post('users/{user}/customer-assignments/match-customer-endpoint', [UserAssignmentController::class, 'previewCustomerEndpointMatch']);
        Route::get('customers/{customerId}/department', [CustomerDepartmentController::class, 'show']);
        Route::patch('customers/{customerId}/department', [CustomerDepartmentController::class, 'update']);
        Route::get('roles', [RoleController::class, 'index']);
    });

    Route::prefix('admin')->middleware('admin.only')->group(function () {
        Route::get('users/{user}/sign-in-logs', [AdminController::class, 'userSignInLogs']);
        Route::get('users/{user}/sessions', [AdminController::class, 'userSessions']);

        Route::get('data-management/export', [DataManagementController::class, 'export']);
        Route::post('data-management/sales-orders/import', [DataManagementController::class, 'importSalesOrders']);

        Route::get('ai-keys', [AiConnectorController::class, 'index']);
        Route::post('ai-keys', [AiConnectorController::class, 'store']);
        Route::delete('ai-keys/{id}', [AiConnectorController::class, 'destroy']);

        // Acumatica config
        Route::get('acumatica',           [AcumaticaController::class, 'index']);
        Route::put('acumatica',           [AcumaticaController::class, 'update']);
        Route::post('acumatica/validate', [AcumaticaController::class, 'validateCredentials']);

        // Acumatica sync triggers
        Route::post('acumatica/sync/customers',       [AcumaticaController::class, 'syncCustomers']);
        Route::post('acumatica/sync/shipping-zones',  [AcumaticaController::class, 'syncShippingZones']);
        Route::post('acumatica/sync/orders',          [AcumaticaController::class, 'syncOrders']);
        Route::post('acumatica/sync/customer-orders', [AcumaticaController::class, 'syncCustomerOrders']);
        Route::post('acumatica/sync/inventory',        [AcumaticaController::class, 'syncInventory']);
        Route::post('acumatica/sync/inventory-stocks', [AcumaticaController::class, 'syncInventoryStocks']);
        Route::post('acumatica/sync/backorders',      [AcumaticaController::class, 'syncBackorders']);
        Route::post('acumatica/sync/fill-rate',       [AcumaticaController::class, 'syncFillRate']);
        Route::post('acumatica/sync/credit-notes-more', [AcumaticaController::class, 'syncCreditNotesAndMore']);
        Route::get('acumatica/sync/logs',             [AcumaticaController::class, 'syncLogs']);
        Route::post('acumatica/sync/logs/{syncLog}/stop', [AcumaticaController::class, 'stopSync']);
        Route::post('acumatica/sync/diagnose',        [AcumaticaController::class, 'diagnoseSyncHealth']);

        // Reconciliation
        Route::get('acumatica/reconciliation',        [AcumaticaController::class, 'reconciliation']);
        Route::patch('acumatica/reconciliation/{id}', [AcumaticaController::class, 'updateReconciliationStatus']);

        // Dead letters
        Route::get('acumatica/dead-letters',          [AcumaticaController::class, 'deadLetters']);

        // Customer search for selective sync
        Route::get('acumatica/customers/search',         [AcumaticaController::class, 'searchCustomers']);
        Route::get('acumatica/lookup',                   [AcumaticaController::class, 'lookup']);
        Route::get('acumatica/customers/{customerId}',   [AcumaticaController::class, 'previewCustomer']);
        Route::get('acumatica/orders/{orderNbr}',        [AcumaticaController::class, 'previewOrder']);

        // Data truncation (admin only)
        Route::post('so-imports/truncate/orders',    [AcumaticaImportController::class, 'truncateOrders']);
        Route::post('so-imports/truncate/customers', [AcumaticaImportController::class, 'truncateCustomers']);
        Route::post('so-imports/truncate/emails',    [AcumaticaImportController::class, 'truncateEmails']);

        Route::get('permissions', [PermissionController::class, 'index']);

        Route::get('notification-rules', [NotificationRuleController::class, 'index']);
        Route::post('notification-rules/send-config', [NotificationRuleController::class, 'sendConfig']);
        Route::put('notification-rules/{id}', [NotificationRuleController::class, 'update']);

        Route::patch('mail-settings', [HealthController::class, 'updateMailSettings']);
        Route::patch('cron-jobs/{cronJob}', [CronJobController::class, 'update']);

        Route::get('delivery-sla-config', [DeliverySlaConfigController::class, 'index']);
        Route::put('delivery-sla-config', [DeliverySlaConfigController::class, 'update']);

        // FOL settings (dynamic stages, mail, attachments — admin editable)
        Route::get('fol/settings', [FolSettingsController::class, 'show']);
        Route::put('fol/settings', [FolSettingsController::class, 'update']);
        Route::put('fol/stages', [FolSettingsController::class, 'updateStages']);

        Route::get('pricing/pcr-settings', [PriceChangeSettingsController::class, 'show']);
        Route::put('pricing/pcr-settings', [PriceChangeSettingsController::class, 'update']);

        Route::get('sales-management/settings', [SalesManagementSettingsController::class, 'show']);
        Route::put('sales-management/settings', [SalesManagementSettingsController::class, 'update']);
        Route::post('sales-management/prompts/generate', [SalesManagementSettingsController::class, 'generate']);

        // Impersonation — start only (stop lives under auth/* for non-admin tokens)
        Route::get('impersonate/candidates', [ImpersonationController::class, 'candidates']);
        Route::post('impersonate', [ImpersonationController::class, 'start']);
    });

    // AI chat — available to all authenticated users
    Route::post('ai/chat', [AiChatController::class, 'chat']);
    Route::get('ai/intelligence', [AiIntelligenceController::class, 'briefing']);
    Route::post('ai/intelligence/generate', [AiIntelligenceController::class, 'generate']);

    Route::middleware('admin.or.cs')->group(function () {
        // Emails
        Route::get('emails/inbox-groups',                     [EmailController::class, 'inboxGroups']);
        Route::get('emails',                                  [EmailController::class, 'index']);

        // Email filters
        Route::get('email-filters',                           [EmailFilterController::class, 'index']);
        Route::post('email-filters',                          [EmailFilterController::class, 'store']);
        Route::patch('email-filters/{emailFilter}',           [EmailFilterController::class, 'update']);
        Route::post('email-filters/{emailFilter}/sync',       [EmailFilterController::class, 'sync']);
        Route::delete('email-filters/{emailFilter}',          [EmailFilterController::class, 'destroy']);
    });
    });
});
