<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaBackorderLine extends Model
{
    protected $fillable = [
        'order_nbr',
        'inventory_id',
        'customer_acumatica_id',
        'customer_name',
        'order_qty',
        'shipped_qty',
        'open_qty',
        'cancelled_qty',
        'backorder_qty',
        'fulfillment_status',
        'qty_at_approval',
        'unit_price',
        'revenue_at_risk',
        'warehouse_id',
        'uom',
        'currency_id',
        'scheduled_shipment_date',
        'requested_on',
        'sync_run_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'order_qty'               => 'decimal:4',
            'shipped_qty'             => 'decimal:4',
            'open_qty'                => 'decimal:4',
            'cancelled_qty'           => 'decimal:4',
            'backorder_qty'           => 'decimal:4',
            'qty_at_approval'         => 'decimal:4',
            'unit_price'              => 'decimal:4',
            'revenue_at_risk'         => 'decimal:2',
            'scheduled_shipment_date' => 'date',
            'requested_on'            => 'date',
            'synced_at'               => 'datetime',
        ];
    }
}