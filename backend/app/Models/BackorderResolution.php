<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackorderResolution extends Model
{
    protected $fillable = [
        'order_nbr',
        'inventory_id',
        'customer_acumatica_id',
        'customer_name',
        'warehouse_id',
        'uom',
        'currency_id',
        'reason_code',
        'reason_notes',
        'unit_price',
        'revenue_at_risk',
        'order_qty',
        'last_open_qty',
        'last_backorder_qty',
        'last_fulfillment_status',
        'first_backordered_at',
        'first_backordered_at_is_backfilled',
        'resolved_at',
        'days_to_resolve',
        'sync_run_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:4',
            'revenue_at_risk' => 'decimal:2',
            'order_qty' => 'decimal:4',
            'last_open_qty' => 'decimal:4',
            'last_backorder_qty' => 'decimal:4',
            'first_backordered_at' => 'datetime',
            'first_backordered_at_is_backfilled' => 'boolean',
            'resolved_at' => 'datetime',
            'days_to_resolve' => 'integer',
        ];
    }
}
