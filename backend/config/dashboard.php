<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Customers excluded from the main SO dashboard
    |--------------------------------------------------------------------------
    |
    | These Acumatica customer IDs are tracked separately (e.g. Goods Lost in
    | Transit) and must not inflate normal sales-order KPI / trend totals.
    |
    */

    'excluded_customer_ids' => [
        'CUST102641', // Goods Lost in Transit
    ],

    /*
    |--------------------------------------------------------------------------
    | Goods Lost in Transit tab
    |--------------------------------------------------------------------------
    */

    'goods_lost_in_transit' => [
        'customer_id' => 'CUST102641',
        'label' => 'Goods Lost in Transit',
    ],

];
