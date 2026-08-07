<?php

return [
    'timezone' => env('SFA_SYNC_TIMEZONE', 'Africa/Nairobi'),
    'queue' => env('SFA_SYNC_QUEUE', 'sfa-sync'),
    'late_threshold' => env('SFA_LATE_THRESHOLD', '08:30'),
    'channels' => ['1', '2'],
    'visible_team' => 'GT',
    'tables' => [
        'regions', 'territories', 'channels', 'rolegroups', 'products', 'uoms',
        'uom_quantities', 'routes', 'reps', 'customers', 'shop_routes', 'user_routes',
        'customer_visits', 'start_end_days', 'sales_entries', 'daily_performances',
    ],
    // Intentionally narrow until each source field map has passed production validation.
    'manual_tables' => ['reps', 'customers'],
];
