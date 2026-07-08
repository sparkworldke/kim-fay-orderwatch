<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcumaticaSalesOrderLine extends Model
{
    protected $fillable = [
        'sales_order_id',
        'line_nbr',
        'inventory_id',
        'description',
        'order_qty',
        'shipped_qty',
        'qty_on_shipments',
        'open_qty',
        'cancelled_qty',
        'qty_at_approval',
        'backorder_qty',
        'fill_rate_pct',
        'unfilled_reason_code',
        'line_type',
        'completed',
        'fulfillment_status',
        'warehouse_id',
        'uom',
        'unit_price',
        'ext_cost',
        'discount_amount',
        'discount_code',
    ];

    protected function casts(): array
    {
        return [
            'order_qty'          => 'decimal:4',
            'shipped_qty'        => 'decimal:4',
            'qty_on_shipments'   => 'decimal:4',
            'open_qty'           => 'decimal:4',
            'cancelled_qty'      => 'decimal:4',
            'qty_at_approval'    => 'decimal:4',
            'backorder_qty'      => 'decimal:4',
            'fill_rate_pct'      => 'decimal:2',
            'completed'          => 'boolean',
            'unit_price'         => 'decimal:4',
            'ext_cost'           => 'decimal:2',
            'discount_amount'    => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'sales_order_id');
    }
}
