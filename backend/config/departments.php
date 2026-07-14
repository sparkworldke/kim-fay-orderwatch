<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Customer-facing department slugs (portfolio scoping applies)
    |--------------------------------------------------------------------------
    */
    'customer_facing_slugs' => [
        'mt_consumer_sales',
        'gt',
        'kp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Org levels that receive org-wide data visibility (same as executive).
    |--------------------------------------------------------------------------
    */
    'org_wide_org_levels' => [
        'executive',
        'c_suite',
        'operations',
    ],

    /*
    |--------------------------------------------------------------------------
    | Org levels that can see their reportees' scoped data (subtree union).
    |--------------------------------------------------------------------------
    */
    'org_levels_with_subtree_visibility' => [
        'executive',
        'c_suite',
        'hod',
    ],

    'fail_closed_org_level' => 'gap',

    'default_product_type_by_function' => [
        'partner_brands' => 'trading',
        'mt_consumer_sales' => 'both',
        'gt' => 'both',
        'kp' => 'both',
    ],

    /*
    |--------------------------------------------------------------------------
    | Shared mailboxes — no login; deny_all data scope; remain inactive.
    |--------------------------------------------------------------------------
    */
    'shared_mailbox_emails' => [
        'orders@kimfay.com',
        'sales@kimfay.com',
        'orderwatch@kimfay.com',
    ],

    'shared_mailbox_local_parts' => [
        'dispatchclerk',
        'orders',
    ],

    /*
    |--------------------------------------------------------------------------
    | Map Acumatica customer_class prefixes to department slugs.
    | Longest prefix wins. Override via customer_department_overrides table.
    |--------------------------------------------------------------------------
    */
    'class_prefix_map' => [
        'KP' => 'kp',
        'MT' => 'mt_consumer_sales',
        'GT' => 'gt',
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles that always have org-wide customer data access.
    |--------------------------------------------------------------------------
    */
    'executive_roles' => [
        'Executive',
        'Administrator',
    ],

    /*
    |--------------------------------------------------------------------------
    | Department roles with org-wide oversight within customer-facing depts.
    |--------------------------------------------------------------------------
    */
    'executive_department_roles' => [
        'executive',
    ],

    /*
    |--------------------------------------------------------------------------
    | Menu visibility by department slug (hidden route slugs).
    | Permissions are additive — department hides, role permissions gate access.
    |--------------------------------------------------------------------------
    */
    'hidden_menus_by_department' => [
        'production' => ['mailbox', 'order-match', 'so-imports', 'administration'],
        'stores' => ['mailbox', 'order-match', 'so-imports', 'administration'],
        'dispatch' => ['mailbox', 'order-match', 'administration'],
        'marketing' => ['order-match', 'administration'],
        'mt_consumer_sales' => ['administration'],
        'gt' => ['administration'],
        'kp' => ['administration'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles that should have revenue values masked in API responses.
    |--------------------------------------------------------------------------
    */
    'mask_revenue_roles' => [
        'Customer Service Agent',
    ],

    /*
    |--------------------------------------------------------------------------
    | Idle session timeout (minutes) — frontend reads via capabilities API.
    |--------------------------------------------------------------------------
    */
    'idle_timeout_minutes' => (int) env('SESSION_IDLE_MINUTES', 60),
];