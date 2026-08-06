<?php

/*
|--------------------------------------------------------------------------
| Customer Portfolio Attribution configuration (PRD §7–§8, §12)
|--------------------------------------------------------------------------
| Central, deterministic policy for resolving Acumatica identity aliases,
| customer servicing assignments, directional visibility, and the
| mapped-only Sales Consultant gate.
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical Sales Consultant role name (PRD §7.3).
    |--------------------------------------------------------------------------
    | Evaluated through the many-to-many user_roles relationship, never only
    | through the legacy users.role string.
    */

    'sales_consultant_role' => 'Sales Consultant',

    /*
    |--------------------------------------------------------------------------
    | Identity resolution priority (PRD §7.1). Lower number = checked first.
    |--------------------------------------------------------------------------
    */

    'identity_priority' => [
        'employee_number' => 1,
        'rep_code' => 2,
        'rep_mapping' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer assignment precedence (PRD §7.2). Lower number wins.
    |--------------------------------------------------------------------------
    | Stored on user_customer_assignments.priority and customer_assignment_rules.priority.
    | A manual override flag always wins regardless of the numeric priority.
    */

    'precedence' => [
        'manual_override' => 1,
        'workbook_customer' => 2,
        'main_account' => 3,
        'region' => 4,
        'customer_rep_alias' => 5,
        'so_rep_alias' => 6,
    ],

    // Default priority applied to legacy/existing assignments without an explicit one.
    'default_assignment_priority' => 2,

    /*
    |--------------------------------------------------------------------------
    | Rule catalogue (PRD §8.2).
    |--------------------------------------------------------------------------
    */

    'rule_types' => ['customer', 'main_account', 'region', 'rep_alias'],
    'rule_sources' => ['manual', 'excel', 'acumatica', 'seeder'],

    /*
    |--------------------------------------------------------------------------
    | Department / team slugs (PRD §7.5).
    |--------------------------------------------------------------------------
    */

    'mt_department_slug' => 'mt_consumer_sales',
    'gt_department_slug' => 'gt',
    'kp_department_slug' => 'kp',

    /*
    |--------------------------------------------------------------------------
    | KP CRM access boundary (PRD §7.6).
    |--------------------------------------------------------------------------
    | A customer is classified KP when its Acumatica category/customer class
    | starts with this prefix. The product-area gate additionally requires the
    | dedicated permission and approved cohort membership.
    */

    'kp_category_prefix' => 'KP',
    'kp_crm_permission' => 'kp.crm.access',
    'kp_crm_route_middleware' => 'can:access-kp-crm',

    // Approved launch leadership cohort (named assignments). KP HOD + KP department
    // members are resolved dynamically through department membership.
    'kp_crm_leadership_emails' => [
        'commercialtechlead@kimfay.com', // Titus Kaleli Mutiso (administrator)
        'cco@kimfay.com',                // Vignesh Ramachandran (commercial executive)
        'susan@kimfay.com',              // Susan Ngina Mwathi (KP HOD)
        'hbains@kimfay.com',             // Hartaj Singh Bains (executive)
        'rbains@kimfay.com',             // Rajdeep Singh Bains (C-suite)
    ],

    /*
    |--------------------------------------------------------------------------
    | Hierarchy / team migration permission (PRD §10, §13).
    |--------------------------------------------------------------------------
    */

    'team_hierarchy_permission' => 'team.manage_hierarchy',

    /*
    |--------------------------------------------------------------------------
    | Effective-assignment cache TTL (seconds). 0 disables caching.
    |--------------------------------------------------------------------------
    */

    'effective_assignment_cache_ttl' => (int) env('ATTRIBUTION_CACHE_TTL', 300),
];
