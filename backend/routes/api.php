<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\Admin\AcumaticaController;
use App\Http\Controllers\Api\Admin\AiConnectorController;
use App\Http\Controllers\Api\ActivityController;
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
use App\Http\Controllers\Api\Admin\AdoptionReportController;
use App\Http\Controllers\Api\Admin\SalesManagementSettingsController;
use App\Http\Controllers\Api\Admin\BrandsController;
use App\Http\Controllers\Api\Admin\CategoriesController;
use App\Http\Controllers\Api\Admin\TradingGroupsController;
use App\Http\Controllers\Api\Admin\ProductsController;
use App\Http\Controllers\Api\Admin\SalesConsultantDigestController;
use App\Http\Controllers\Api\AiChatController;
use App\Http\Controllers\Api\AiIntelligenceController;
use App\Http\Controllers\Api\KimfayGeniusController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CapabilitiesController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\KpItemsNotOrderedController;
use App\Http\Controllers\Api\Admin\CustomerDepartmentController;
use App\Http\Controllers\Api\Admin\DepartmentController;
use App\Http\Controllers\Api\Admin\TeamImportController;
use App\Http\Controllers\Api\Admin\PortfolioAdministrationController;
use App\Http\Controllers\Api\Admin\UserAssignmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ExecutiveController;
use App\Http\Controllers\Api\CustomerBrandController;
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
use App\Http\Controllers\Api\ProductionIntelligenceController;
use App\Http\Controllers\Api\ItemsNotDeliveredController;
use App\Http\Controllers\Api\SalesConsultantController;
use App\Http\Controllers\Api\SalesManagementPromptController;
use App\Http\Controllers\Api\SalesIntelligenceController;
use App\Http\Controllers\Api\SfaDashboardController;
use App\Http\Controllers\Api\Admin\SfaSyncController;
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

// --- Public signed FOL attachment links (for outbound email: view + download) ---
Route::get('kp/fol/attachments/{attachment}/public', [FolController::class, 'publicAttachment'])
    ->middleware('signed')
    ->name('fol.attachments.public')
    ->whereNumber('attachment');

// Opaque, expiring report links generated for email recipients.
Route::get('public/downloads/{token}', [\App\Http\Controllers\Api\ExportDownloadController::class, 'publicFile'])
    ->where('token', '[A-Za-z0-9]{64}');

// --- Protected ---
Route::middleware('auth:sanctum')->group(function () {
    Route::middleware('view.only')->group(function () {
    Route::get('sfa/dashboard', [SfaDashboardController::class, 'index']);
    Route::get('operations/items-not-delivered', [ItemsNotDeliveredController::class, 'index'])->middleware('response.cache:not-delivered,120');
    Route::get('operations/items-not-delivered/export', [ItemsNotDeliveredController::class, 'export']);

    Route::prefix('kp/commissions')->middleware('kp.crm')->group(function () {
        Route::get('/', [CommissionController::class, 'summary']);
        Route::get('rules', [CommissionController::class, 'rules']);
        Route::post('rules', [CommissionController::class, 'saveRule']);
        Route::put('rules/{rule}', [CommissionController::class, 'saveRule']);
        Route::put('targets', [CommissionController::class, 'saveTarget']);
        Route::post('periods', [CommissionController::class, 'createPeriod']);
        Route::post('periods/{period}/calculate', [CommissionController::class, 'recalculate']);
        Route::post('periods/{period}/transition', [CommissionController::class, 'transition']);
        Route::get('statements/{statement}', [CommissionController::class, 'show']);
        Route::post('statements/{statement}/adjustments', [CommissionController::class, 'adjust']);
    });
    Route::get('kp/items-not-ordered', [KpItemsNotOrderedController::class, 'index'])->middleware(['kp.crm', 'response.cache:kp-crm,300']);
    Route::get('admin/adoption-report', [AdoptionReportController::class, 'index'])->middleware('super.admin');
    Route::post('admin/adoption-report/users/{user}/trained', [AdoptionReportController::class, 'markTrained'])->middleware('super.admin');

    // Auth
    Route::prefix('auth')->group(function () {
        Route::get('me',     [AuthController::class, 'me']);
        Route::get('capabilities', CapabilitiesController::class)->middleware('response.cache:capabilities,600');
        Route::post('logout',[AuthController::class, 'logout']);
        Route::post('onboarding/complete', [ProfileController::class, 'completeOnboarding']);
        // Stop must work while impersonating (token is the target user, not admin)
        Route::post('impersonate/stop', [ImpersonationController::class, 'stop']);
    });

    // Dashboard KPIs + trend
    Route::get('dashboard/kpis',              [DashboardController::class, 'kpis']);
    Route::get('dashboard/filter-options',     [DashboardController::class, 'filterOptions'])->middleware('response.cache:references,1800');
    Route::get('dashboard/trend',             [DashboardController::class, 'trend']);
    Route::get('dashboard/orders-by-status',  [DashboardController::class, 'ordersByStatus']);
    Route::get('dashboard/orders/{order}/lines', [DashboardController::class, 'orderLines'])->whereNumber('order');
    Route::get('dashboard/goods-lost-in-transit', [DashboardController::class, 'goodsLostInTransit']);
    Route::get('dashboard/zone-routes',          [DashboardController::class, 'zoneRoutes']);
    Route::get('dashboard/customer-brands',      [CustomerBrandController::class, 'index']);
    Route::get('dashboard/customer-brands/{customerId}', [CustomerBrandController::class, 'show']);

    // Customer Feed — grouped customer performance
    Route::get('customer-feed',                    [CustomerFeedController::class, 'index'])->middleware('response.cache:customer-analytics,120');
    Route::get('customer-feed/{groupKey}/insights', [CustomerFeedController::class, 'insights'])->middleware('response.cache:customer-analytics,300');

    // Background Excel downloads (large exports)
    Route::prefix('downloads')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\ExportDownloadController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\ExportDownloadController::class, 'store']);
        Route::get('{download}', [\App\Http\Controllers\Api\ExportDownloadController::class, 'show'])->whereNumber('download');
        Route::get('{download}/file', [\App\Http\Controllers\Api\ExportDownloadController::class, 'file'])->whereNumber('download');
        Route::delete('{download}', [\App\Http\Controllers\Api\ExportDownloadController::class, 'destroy'])->whereNumber('download');
    });

    // Operations — inventory, backorders, fill rate
    Route::prefix('operations')->group(function () {
        Route::prefix('production')->group(function () {
            Route::get('version', [ProductionIntelligenceController::class, 'version']);
            Route::get('reference', [ProductionIntelligenceController::class, 'reference'])->middleware('response.cache:references,3600');
            Route::get('summary', [ProductionIntelligenceController::class, 'summary']);
            Route::get('inventory', [ProductionIntelligenceController::class, 'inventory']);
            Route::get('inventory/{inventoryId}/trend', [ProductionIntelligenceController::class, 'trend']);
            Route::get('inventory/{inventoryId}/warehouses', [ProductionIntelligenceController::class, 'warehouses']);
            Route::get('inventory/{inventoryId}', [ProductionIntelligenceController::class, 'show']);
            Route::get('sales', [ProductionIntelligenceController::class, 'sales']);
            Route::get('plans', [ProductionIntelligenceController::class, 'plans']);
            Route::post('plans/bulk-msi', [ProductionIntelligenceController::class, 'bulkMsi']);
            Route::post('plans', [ProductionIntelligenceController::class, 'store']);
            Route::put('plans/{plan}', [ProductionIntelligenceController::class, 'update']);
            Route::delete('plans/{plan}', [ProductionIntelligenceController::class, 'destroy']);
            Route::post('transfer-requests/email', [ProductionIntelligenceController::class, 'emailTransferRequests']);
        });
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
            Route::post('{priceChangeRequest}/counter-response', [PriceChangeRequestController::class, 'respondCounter'])->whereNumber('priceChangeRequest');
            Route::post('{priceChangeRequest}/acknowledge-duplicate', [PriceChangeRequestController::class, 'acknowledgeDuplicate'])->whereNumber('priceChangeRequest');
            Route::post('{priceChangeRequest}/mark-applied-erp', [PriceChangeRequestController::class, 'markAppliedErp'])->whereNumber('priceChangeRequest');
        });
        Route::get('inventory/summary',              [OperationsController::class, 'inventorySummary'])->middleware('response.cache:inventory,60');
        Route::get('inventory/export',               [OperationsController::class, 'exportInventory']);
        Route::get('inventory',                       [OperationsController::class, 'inventory'])->middleware('response.cache:inventory,60');
        Route::get('inventory/{id}/prediction',       [OperationsController::class, 'inventoryPrediction']);
        Route::get('inventory/{inventoryId}/sku-detail', [InventorySkuDetailController::class, 'show']);
        Route::get('inventory/{inventoryId}/insights',   [InventoryInsightController::class, 'show']);
        Route::get('backorders/summary',              [OperationsController::class, 'backordersSummary'])->middleware('response.cache:backorders,120');
        Route::post('backorders/executive-image',     [OperationsController::class, 'backordersExecutiveImage'])->middleware('throttle:10,1');
        Route::get('backorders/reconciliation',       [OperationsController::class, 'backordersReconciliation']);
        Route::get('backorders/analytics',            [OperationsController::class, 'backordersAnalytics'])->middleware('response.cache:backorders,120');
        Route::get('backorders/sku-breakdown',         [OperationsController::class, 'backordersSkuBreakdown'])->middleware('response.cache:backorders,120');
        Route::get('backorders/sku-breakdown/export',  [OperationsController::class, 'exportBackordersSkuBreakdown'])->middleware('admin.or.manager');
        Route::get('backorders/export',               [OperationsController::class, 'exportBackorders']);
        Route::get('backorders',                      [OperationsController::class, 'backorders'])->middleware('response.cache:backorders,120');
        Route::get('backorders/resolved',              [OperationsController::class, 'backordersResolved'])->middleware('response.cache:backorders,120');
        Route::patch('backorders/{backorderLine}',    [OperationsController::class, 'updateBackorderReason']);
        Route::get('backorders/by-account',           [OperationsController::class, 'backordersByAccount'])->middleware(['admin.or.manager', 'response.cache:backorders,120']);
        Route::get('brand-filter-options',           [OperationsController::class, 'brandFilterOptions'])->middleware('response.cache:references,3600');
        Route::get('reason-taxonomy',                [OperationsController::class, 'reasonTaxonomy'])->middleware('response.cache:references,3600');
        Route::get('so-reason-audit',                 [OperationsController::class, 'soReasonAudit'])->middleware('response.cache:orders,300');
        Route::get('fill-rate/summary',               [OperationsController::class, 'fillRateSummary'])->middleware('response.cache:fill-rate,120');
        Route::get('fill-rate/sku-breakdown',         [OperationsController::class, 'fillRateSkuBreakdown'])->middleware('response.cache:fill-rate,120');
        Route::get('fill-rate/sku-breakdown/export',  [OperationsController::class, 'exportFillRateSkuBreakdown'])->middleware('admin.or.manager');
        Route::get('fill-rate/out-of-stock',          [OperationsController::class, 'fillRateOutOfStockReport'])->middleware('response.cache:fill-rate,120');
        Route::get('fill-rate/out-of-stock/export',   [OperationsController::class, 'exportFillRateOutOfStockReport'])->middleware('admin.or.manager');
        Route::get('fill-rate/export',                [OperationsController::class, 'exportFillRate'])->middleware('admin.or.manager');
        Route::get('fill-rate',                       [OperationsController::class, 'fillRate'])->middleware('response.cache:fill-rate,120');
        Route::get('status',                          [OperationsController::class, 'opsStatus'])->middleware('response.cache:business-optimization,60');
        Route::get('business-optimization',           [OperationsController::class, 'businessOptimization'])->middleware('response.cache:business-optimization,120');
        Route::get('sales-consultants',               [SalesConsultantController::class, 'index']);
        Route::post('sales-consultants/import',       [SalesConsultantController::class, 'import'])->middleware('admin.or.manager');
        Route::get('sales-consultants/{id}',          [SalesConsultantController::class, 'show'])->whereNumber('id');
        Route::get('sales-consultants/{id}/customers', [SalesConsultantController::class, 'customersById'])->whereNumber('id');
        Route::get('sales-consultants/{repCode}/customers', [SalesConsultantController::class, 'customers']);
        Route::get('sales-consultants/{repCode}',     [SalesConsultantController::class, 'showByRepCode']);
    });

    // Orders
    Route::get('orders/stats', [OrderController::class, 'stats'])->middleware('response.cache:orders,60');
    Route::get('orders/filter-options', [OrderController::class, 'filterOptions'])->middleware('response.cache:orders,300');
    Route::post('orders-status-refresh', [AcumaticaController::class, 'refreshOrderStatuses']);
    Route::post('orders/status-refresh', [AcumaticaController::class, 'refreshOrderStatuses']);
    Route::patch('orders/{id}/consultant', [OrderController::class, 'assignConsultant'])->whereNumber('id');
    Route::apiResource('orders', OrderController::class)
        ->middlewareFor(['index'], 'response.cache:orders,60');

    // KP CRM Accounts (portfolio of KP customers)
    Route::get('kp/accounts', [\App\Http\Controllers\Api\KpAccountsController::class, 'index'])->middleware('response.cache:customer-analytics,300');
    Route::get('kp/accounts/by-rep', [\App\Http\Controllers\Api\KpAccountsController::class, 'byRep'])->middleware('response.cache:customer-analytics,300');

    // Sales consultant / HOD portfolio dashboard (PRD My Portfolio)
    Route::get('sales/portfolio/summary', [\App\Http\Controllers\Api\SalesPortfolioController::class, 'summary'])->middleware('response.cache:sales-portfolio,120');
    Route::get('sales/portfolio/orders', [\App\Http\Controllers\Api\SalesPortfolioController::class, 'orders'])->middleware('response.cache:sales-portfolio,120');
    Route::get('sales/portfolio/backorders', [\App\Http\Controllers\Api\SalesPortfolioController::class, 'backorders'])->middleware('response.cache:sales-portfolio,120');
    Route::get('sales/portfolio/items-not-ordered', [\App\Http\Controllers\Api\SalesPortfolioController::class, 'itemsNotOrdered'])->middleware('response.cache:sales-portfolio,300');
    Route::get('sales/intelligence/metrics', [SalesIntelligenceController::class, 'metrics'])
        ->middleware('response.cache:sales-intelligence,120');
    Route::get('executive/metrics', [ExecutiveController::class, 'metrics'])->middleware('response.cache:executive,300');
    Route::get('kp/dormant-customers', [\App\Http\Controllers\Api\KpDormantCustomersController::class, 'index'])->middleware(['kp.crm', 'response.cache:kp-crm,180']);
    Route::get('kp/dormant-customers/{customerId}/attempts', [\App\Http\Controllers\Api\KpDormantCustomersController::class, 'attempts'])->middleware(['kp.crm', 'response.cache:kp-crm,120']);
    Route::post('kp/dormant-customers/{customerId}/feedback', [\App\Http\Controllers\Api\KpDormantCustomersController::class, 'feedback'])->middleware('kp.crm');
    Route::post('kp/dormant-customers/{customerId}/handoff', [\App\Http\Controllers\Api\KpDormantCustomersController::class, 'handoff'])->middleware('kp.crm');
    Route::middleware(['kp.crm', 'response.cache:kp-crm,120'])->group(function () {
        Route::get('kp/meetings', [\App\Http\Controllers\Api\KpMeetingsController::class, 'index'])->name('kp.meetings.index');
        Route::get('kp/meetings-dashboard', [\App\Http\Controllers\Api\KpMeetingsController::class, 'dashboard']);
        Route::get('kp/meetings-meta', [\App\Http\Controllers\Api\KpMeetingsController::class, 'meta']);
        Route::get('kp/meetings-customer-search', [\App\Http\Controllers\Api\KpMeetingsController::class, 'searchCustomers']);
        Route::post('kp/meetings', [\App\Http\Controllers\Api\KpMeetingsController::class, 'store']);
        Route::put('kp/meetings/{meeting}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'update'])->whereNumber('meeting');
        Route::delete('kp/meetings/{meeting}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'destroy'])->whereNumber('meeting');
        Route::patch('kp/meeting-actions/{action}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'updateAction'])->whereNumber('action');
        Route::post('kp/meeting-participants/{participant}/respond', [\App\Http\Controllers\Api\KpMeetingsController::class, 'respond'])->whereNumber('participant');
        Route::put('kp/meeting-target', [\App\Http\Controllers\Api\KpMeetingsController::class, 'saveTarget']);
        Route::post('kp/meeting-purposes', [\App\Http\Controllers\Api\KpMeetingsController::class, 'savePurpose']);
        Route::put('kp/meeting-purposes/{purpose}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'savePurpose'])->whereNumber('purpose');
        Route::post('kp/meeting-action-categories', [\App\Http\Controllers\Api\KpMeetingsController::class, 'saveActionCategory']);
        Route::put('kp/meeting-action-categories/{category}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'saveActionCategory'])->whereNumber('category');
        Route::get('kp/activities', [\App\Http\Controllers\Api\KpMeetingsController::class, 'index'])->name('kp.activities.index');
        Route::get('kp/activities/meta', [\App\Http\Controllers\Api\KpMeetingsController::class, 'meta'])->name('kp.activities.meta');
        Route::get('kp/activities/dashboard', [\App\Http\Controllers\Api\KpMeetingsController::class, 'activityDashboard'])->name('kp.activities.dashboard');
        Route::get('kp/activities/follow-ups', [\App\Http\Controllers\Api\KpMeetingsController::class, 'followUps'])->name('kp.activities.follow-ups');
        Route::post('kp/activities', [\App\Http\Controllers\Api\KpMeetingsController::class, 'store'])->name('kp.activities.store');
        Route::put('kp/activities/{meeting}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'update'])->whereNumber('meeting')->name('kp.activities.update');
        Route::delete('kp/activities/{meeting}', [\App\Http\Controllers\Api\KpMeetingsController::class, 'destroy'])->whereNumber('meeting')->name('kp.activities.destroy');
        Route::post('kp/activity-questionnaires', [\App\Http\Controllers\Api\KpMeetingsController::class, 'saveQuestionnaire'])->name('kp.activities.questionnaires.store');
    });
    Route::get('kp/calendar', [\App\Http\Controllers\Api\KpCalendarController::class, 'index'])->middleware(['kp.crm', 'response.cache:kp-crm,120']);

    Route::prefix('kp/dtc-calltronix')->group(function () {
        Route::get('meta', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'meta']);
        Route::get('stats', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'stats']);
        Route::get('customers', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'customers']);
        Route::get('prices', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'priceList']);
        Route::post('prices/sync-products', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'syncProducts']);
        Route::post('prices/refresh', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'refreshPrices']);
        Route::post('prices/import-excel', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'importPricesExcel'])->middleware('throttle:5,60');
        Route::get('prices/import-jobs', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'priceImportJobs']);
        Route::get('prices/import-jobs/{syncLog}', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'priceImportJob'])->whereNumber('syncLog');
        Route::get('prices/export.pdf', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'exportPriceListPdf']);
        Route::get('quotes', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'quotes']);
        Route::post('quotes', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'store']);
        Route::post('quotes/import', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'importQuotes']);
        Route::post('sales-orders/import', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'importPosOrders']);
        Route::get('quotes/{quote}', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'show'])->whereNumber('quote');
        Route::put('quotes/{quote}', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'update'])->whereNumber('quote');
        Route::post('quotes/{quote}/submit', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'submit'])->whereNumber('quote');
        Route::post('quotes/{quote}/convert', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'convert'])->whereNumber('quote');
        Route::put('quotes/{quote}/converted-customer', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'updateConvertedCustomer'])->whereNumber('quote');
        Route::get('sales-orders', [\App\Http\Controllers\Api\DtcCalltronixController::class, 'salesOrders']);
    });

    // KP Free On Loan (FOL)
    Route::prefix('kp/fol')->middleware(['kp.crm', 'response.cache:kp-operations,60'])->group(function () {
        Route::get('customers/search', [FolController::class, 'searchCustomers']);
        Route::get('inventory/search', [FolController::class, 'searchInventory']);
        Route::get('metrics', [FolController::class, 'metrics']);
        Route::get('technicians', [FolController::class, 'technicians']);
        Route::get('technician/calendar', [FolController::class, 'technicianCalendar']);
        Route::get('/', [FolController::class, 'index']);
        Route::post('/', [FolController::class, 'store']);
        Route::get('{folRequest}', [FolController::class, 'show'])->whereNumber('folRequest');
        Route::put('{folRequest}', [FolController::class, 'update'])->whereNumber('folRequest');
        Route::post('{folRequest}/submit', [FolController::class, 'submit'])->whereNumber('folRequest');
        Route::post('{folRequest}/decision', [FolController::class, 'decision'])->whereNumber('folRequest');
        Route::post('{folRequest}/attachments', [FolController::class, 'attach'])->whereNumber('folRequest');
        Route::delete('attachments/{attachment}', [FolController::class, 'destroyAttachment'])->whereNumber('attachment');
        Route::get('attachments/{attachment}/download', [FolController::class, 'downloadAttachment'])->whereNumber('attachment');
        Route::get('attachments/{attachment}/view', [FolController::class, 'viewAttachment'])->whereNumber('attachment');
        Route::get('attachments/{attachment}/preview', [FolController::class, 'previewAttachment'])->whereNumber('attachment');
        Route::post('{folRequest}/so-links', [FolController::class, 'linkSalesOrder'])->whereNumber('folRequest');
        Route::post('{folRequest}/sales-order', [FolController::class, 'createSalesOrder'])->whereNumber('folRequest');
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
    Route::get('customers/{id}/whitespace',          [CustomerController::class, 'whitespace']);
    // CRM contacts on account
    Route::get('customer-contact-designations', [\App\Http\Controllers\Api\CustomerContactController::class, 'designations']);
    Route::get('customers/{customerId}/contacts', [\App\Http\Controllers\Api\CustomerContactController::class, 'index']);
    Route::post('customers/{customerId}/contacts', [\App\Http\Controllers\Api\CustomerContactController::class, 'store']);
    Route::put('contacts/{id}', [\App\Http\Controllers\Api\CustomerContactController::class, 'update'])->whereNumber('id');
    Route::delete('contacts/{id}', [\App\Http\Controllers\Api\CustomerContactController::class, 'destroy'])->whereNumber('id');
    Route::apiResource('customers', CustomerController::class);

    // Profile
    Route::get('profile', [ProfileController::class, 'show']);
    Route::patch('profile', [ProfileController::class, 'update']);
    Route::get('profile/sign-in-logs', [ProfileController::class, 'signInLogs']);
    Route::get('profile/sessions', [ProfileController::class, 'sessions']);
    Route::post('profile/password/otp', [ProfileController::class, 'requestPasswordUpdateOtp']);
    Route::post('profile/password/otp/verify', [ProfileController::class, 'verifyPasswordUpdateOtp']);
    Route::patch('profile/password', [ProfileController::class, 'updatePassword']);

    // Lightweight page / activity tracking (for admin Login + Activity export)
    Route::post('activity/page-view', [ActivityController::class, 'store'])->middleware('throttle:120,1');

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
        Route::get('audit-logs/export', [AuditLogController::class, 'export']);
        Route::get('cron-jobs', [CronJobController::class, 'index']);
        Route::get('cron-jobs/{cronJob}', [CronJobController::class, 'show']);
        Route::post('cron-jobs/{cronJob}/run', [CronJobController::class, 'run']);
        Route::get('cron-jobs/{cronJob}/runs', [CronJobController::class, 'runs']);

        // Daily management report
        Route::get('daily-reports/config', [DailyReportController::class, 'show']);
        Route::put('daily-reports/config', [DailyReportController::class, 'update']);
        Route::post('daily-reports/send', [DailyReportController::class, 'send']);
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
        Route::get('brands', [BrandsController::class, 'index']);
        Route::middleware('super.admin')->group(function () {
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
        Route::get('portfolio/users/{user}', [PortfolioAdministrationController::class, 'userPortfolio'])->middleware('response.cache:sales-portfolio,120');
        Route::get('portfolio/kp-crm-access', [PortfolioAdministrationController::class, 'kpAccess'])->middleware('response.cache:kp-crm,120');
        Route::post('team-migrations/preview', [PortfolioAdministrationController::class, 'previewMigration']);
        Route::post('team-migrations/{batch}/apply', [PortfolioAdministrationController::class, 'applyMigration']);
        });
        Route::get('portfolio/channel-classification', [PortfolioAdministrationController::class, 'channelClassification'])->middleware('response.cache:references,300');
        Route::post('portfolio/channel-category-rules', [PortfolioAdministrationController::class, 'storeCategoryRule'])->middleware('response.cache:references,300');
        Route::post('portfolio/customer-channel-overrides', [PortfolioAdministrationController::class, 'storeCustomerChannelOverride'])->middleware('response.cache:references,300');
        Route::middleware('super.admin')->group(function () {
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
    });

    Route::prefix('admin')->middleware('admin.only')->group(function () {
        Route::get('sfa/status', [SfaSyncController::class, 'status']);
        Route::post('sfa/run', [SfaSyncController::class, 'run']);
        Route::get('sfa/matches', [SfaSyncController::class, 'matches']);
        Route::post('sfa/matches/suggest', [SfaSyncController::class, 'suggest']);
        Route::post('sfa/matches/{customer}/confirm', [SfaSyncController::class, 'confirm'])->whereNumber('customer');
        Route::post('sfa/matches/{customer}/ignore', [SfaSyncController::class, 'ignore'])->whereNumber('customer');
        Route::post('sfa/matches/{customer}/unlink', [SfaSyncController::class, 'unlink'])->whereNumber('customer');
        Route::get('sales-consultant-digests', [SalesConsultantDigestController::class, 'index']);
        Route::put('sales-consultant-digests/bulk', [SalesConsultantDigestController::class, 'bulk']);
        Route::put('sales-consultant-digests/{user}', [SalesConsultantDigestController::class, 'update']);
        Route::post('brands', [BrandsController::class, 'store'])->middleware('response.cache:references,1');
        Route::put('brands/{brand}', [BrandsController::class, 'update'])->middleware('response.cache:references,1');
        Route::delete('brands/{brand}', [BrandsController::class, 'destroy'])->middleware('response.cache:references,1');
        Route::apiResource('categories', CategoriesController::class)->except(['show']);
        Route::apiResource('trading-groups', TradingGroupsController::class)->except(['show'])
            ->parameters(['trading-groups' => 'tradingGroup']);
        Route::get('products', [ProductsController::class, 'index']);
        Route::put('products/{product}', [ProductsController::class, 'update']);
        Route::post('products/{product}/unlock', [ProductsController::class, 'unlock']);
        Route::post('products/imports', [ProductsController::class, 'upload']);
        Route::get('product-imports', [ProductsController::class, 'imports']);
        Route::get('product-imports/{productImportLog}', [ProductsController::class, 'import']);
        Route::get('product-imports/{productImportLog}/errors', [ProductsController::class, 'errors']);
        Route::get('users/{user}/sign-in-logs', [AdminController::class, 'userSignInLogs'])->middleware('super.admin');
        Route::get('users/{user}/sessions', [AdminController::class, 'userSessions'])->middleware('super.admin');

        Route::get('data-management/export', [DataManagementController::class, 'export']);
        Route::post('data-management/sales-orders/import', [DataManagementController::class, 'importSalesOrders']);

        Route::get('ai-keys', [AiConnectorController::class, 'index']);
        Route::post('ai-keys', [AiConnectorController::class, 'store']);
        Route::post('ai-keys/health', [AiConnectorController::class, 'health']);
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
        Route::post('so-imports/truncate/backorders', [AcumaticaImportController::class, 'truncateBackorders']);
        Route::post('so-imports/truncate/fill-rate', [AcumaticaImportController::class, 'truncateFillRate']);

        Route::get('permissions', [PermissionController::class, 'index'])->middleware('super.admin');

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
        Route::get('fol/products', [\App\Http\Controllers\Api\Admin\FolProductsController::class, 'index']);
        Route::put('fol/products/{inventoryId}', [\App\Http\Controllers\Api\Admin\FolProductsController::class, 'update']);
        Route::post('fol/products/bulk-upload', [\App\Http\Controllers\Api\Admin\FolProductsController::class, 'bulkUpload']);

        Route::get('pricing/pcr-settings', [PriceChangeSettingsController::class, 'show']);
        Route::put('pricing/pcr-settings', [PriceChangeSettingsController::class, 'update']);

        Route::get('sales-management/settings', [SalesManagementSettingsController::class, 'show']);
        Route::put('sales-management/settings', [SalesManagementSettingsController::class, 'update']);
        Route::post('sales-management/prompts/generate', [SalesManagementSettingsController::class, 'generate']);

        // Impersonation — start only (stop lives under auth/* for non-admin tokens)
        Route::get('impersonate/candidates', [ImpersonationController::class, 'candidates'])->middleware('super.admin');
        Route::post('impersonate', [ImpersonationController::class, 'start'])->middleware('super.admin');
    });

    // AI chat — available to all authenticated users
    Route::post('ai/chat', [AiChatController::class, 'chat']);
    Route::get('ai/intelligence', [AiIntelligenceController::class, 'briefing']);
    Route::post('ai/intelligence/generate', [AiIntelligenceController::class, 'generate']);
    Route::get('ai/intelligence/jobs/{uuid}', [AiIntelligenceController::class, 'jobStatus']);

    // Kimfay Genius — per-consultant weekly AI coaching
    Route::get('ai/genius/consultants', [KimfayGeniusController::class, 'consultants']);
    Route::get('ai/genius/consultants/{user}', [KimfayGeniusController::class, 'show'])->whereNumber('user');
    Route::post('ai/genius/consultants/{user}/generate', [KimfayGeniusController::class, 'generate'])->whereNumber('user');
    Route::get('ai/genius/jobs/{uuid}', [KimfayGeniusController::class, 'jobStatus']);

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
