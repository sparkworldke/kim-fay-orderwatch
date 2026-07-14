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
    'cc_watcher_emails' => [],
    'duplicate_policy' => env('FOL_DUPLICATE_POLICY', 'warn'), // block|warn|allow
    'consumables_months' => (int) env('FOL_CONSUMABLES_MONTHS', 6),
    'require_attachment' => filter_var(env('FOL_REQUIRE_ATTACHMENT', true), FILTER_VALIDATE_BOOLEAN),
    // Admin may create, approve any stage, assign technicians (testing + break-glass)
    'allow_admin_on_all_stages' => filter_var(env('FOL_ALLOW_ADMIN_ON_ALL_STAGES', true), FILTER_VALIDATE_BOOLEAN),
];
