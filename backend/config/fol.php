<?php

/**
 * FOL defaults. Runtime values are overridable by Admin via system_settings
 * (FolSettingsService) and fol_approval_stages — edit in Administration → FOL.
 */
return [
    'mail_from_address' => env('FOL_MAIL_FROM_ADDRESS', 'kp@fayshop.co.ke'),
    'mail_from_name' => env('FOL_MAIL_FROM_NAME', 'FOL KP Approvals'),
    'attachment_mimes' => [
        'pdf',
        'xlsx',
        'xls',
        'csv',
        'jpg',
        'jpeg',
        'png',
    ],
    'max_attachment_kb' => (int) env('FOL_MAX_ATTACHMENT_KB', 15360),
    'invoicing_roles' => [
        'Administrator',
        'Customer Service Manager',
        'Customer Service Agent',
        'Sales Operations',
    ],
    // Admin-added emails that always receive FOL step notifications (N1–N6).
    // Comma-separated via FOL_CC_WATCHER_EMAILS. Default: testing admin.
    'cc_watcher_emails' => array_values(array_filter(array_map(
        static fn ($e) => strtolower(trim((string) $e)),
        explode(',', (string) env('FOL_CC_WATCHER_EMAILS', 'commercialtechlead@kimfay.com')),
    ))),
    'duplicate_policy' => env('FOL_DUPLICATE_POLICY', 'warn'), // block|warn|allow
    'consumables_months' => (int) env('FOL_CONSUMABLES_MONTHS', 3),
    'require_attachment' => filter_var(env('FOL_REQUIRE_ATTACHMENT', false), FILTER_VALIDATE_BOOLEAN),
    // Admin may create, approve any stage, assign technicians (testing + break-glass)
    'allow_admin_on_all_stages' => filter_var(env('FOL_ALLOW_ADMIN_ON_ALL_STAGES', true), FILTER_VALIDATE_BOOLEAN),

    // Testing: when true, ALL FOL workflow emails go only to mail_testing_recipient
    // (intended recipients are still logged). Set FOL_MAIL_TESTING_MODE=false for production.
    'mail_testing_mode' => filter_var(env('FOL_MAIL_TESTING_MODE', true), FILTER_VALIDATE_BOOLEAN),
    'mail_testing_recipient' => env('FOL_MAIL_TESTING_RECIPIENT', 'commercialtechlead@kimfay.com'),

    // On final CCO/COO approval: PUT SalesOrder to Acumatica (customer + FOL lines), then
    // email sales with the SO number. Disable with FOL_CREATE_SO_ON_FINAL_APPROVAL=false.
    'create_so_on_final_approval' => filter_var(env('FOL_CREATE_SO_ON_FINAL_APPROVAL', true), FILTER_VALIDATE_BOOLEAN),
    'so_order_type' => env('FOL_SO_ORDER_TYPE', 'SO'),
    'so_zero_unit_price' => filter_var(env('FOL_SO_ZERO_UNIT_PRICE', true), FILTER_VALIDATE_BOOLEAN),
    'so_default_warehouse_id' => env('FOL_SO_DEFAULT_WAREHOUSE_ID'),

    // Cron retry for FOLs final-approved without an attached SO number.
    'so_retry_limit' => (int) env('FOL_SO_RETRY_LIMIT', 50),
];
