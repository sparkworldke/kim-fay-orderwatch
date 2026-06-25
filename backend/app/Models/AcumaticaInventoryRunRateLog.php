<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcumaticaInventoryRunRateLog extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'inventory_id',
        'qty_on_hand',
        'qty_delta',
        'daily_run_rate',
        'days_until_stockout',
        'prediction_status',
        'sync_run_id',
        'logged_at',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand'         => 'decimal:4',
            'qty_delta'           => 'decimal:4',
            'daily_run_rate'      => 'decimal:4',
            'days_until_stockout' => 'integer',
            'logged_at'           => 'datetime',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(AcumaticaInventoryItem::class, 'inventory_item_id');
    }
}