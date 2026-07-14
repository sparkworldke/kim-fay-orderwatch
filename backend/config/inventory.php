<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Acumatica warehouses for stock-position sync
    |--------------------------------------------------------------------------
    |
    | IDs must match Acumatica WarehouseID / DefaultWarehouseID values.
    | Each warehouse gets its own cron job. Morning wave starts 08:30 EAT and
    | midday wave starts 12:00 EAT, with a 30-minute gap between warehouses.
    |
    | Source list confirmed for Kim-Fay Acumatica (incl. MSA, FGS2, Export,
    | FGS2 Returns) plus existing FG / raw-material sites.
    |
    */

    'warehouses' => [
        'DTC',
        'FGS',
        'FGS2',
        'FGS2 RETURNS',
        'MSA',
        'EXPORT',
        'PRMS',
        'RMS1',
        'TRMS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Optional display labels (Acumatica ID => UI label)
    |--------------------------------------------------------------------------
    */

    'warehouse_labels' => [
        'DTC' => 'DTC',
        'FGS' => 'FGS',
        'FGS2' => 'FGS2',
        'FGS2 RETURNS' => 'FGS2 Returns',
        'MSA' => 'MSA',
        'EXPORT' => 'Export',
        'PRMS' => 'PRMS',
        'RMS1' => 'RMS1',
        'TRMS' => 'TRMS',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-warehouse stock sync schedule
    |--------------------------------------------------------------------------
    */

    'stock_sync' => [
        // First warehouse (index 0) morning start — HH:MM 24h, app/cron timezone
        'morning_start' => '08:30',
        // First warehouse midday start
        'midday_start' => '12:00',
        // Minutes between consecutive warehouse jobs
        'stagger_minutes' => 30,
    ],

];
