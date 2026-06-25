<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcumaticaFillRateSnapshot extends Model
{
    protected $fillable = [
        'sales_order_id',
        'order_nbr',
        'customer_acumatica_id',
        'status',
        'total_ordered_qty',
        'total_shipped_qty',
        'fill_rate_pct',
        'fill_rate_status',
        'revenue_not_shipped',
        'currency_id',
        'sync_run_id',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'total_ordered_qty'   => 'decimal:4',
            'total_shipped_qty'   => 'decimal:4',
            'fill_rate_pct'       => 'decimal:2',
            'revenue_not_shipped' => 'decimal:2',
            'computed_at'         => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'sales_order_id');
    }
}