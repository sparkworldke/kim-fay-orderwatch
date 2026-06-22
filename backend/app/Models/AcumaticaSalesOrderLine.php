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
        'unit_price',
        'ext_cost',
        'discount_amount',
        'discount_code',
    ];

    protected function casts(): array
    {
        return [
            'order_qty'       => 'decimal:4',
            'unit_price'      => 'decimal:4',
            'ext_cost'        => 'decimal:2',
            'discount_amount' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'sales_order_id');
    }
}
