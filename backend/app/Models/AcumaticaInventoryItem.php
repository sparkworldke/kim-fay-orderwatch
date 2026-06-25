<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaInventoryItem extends Model
{
    protected $fillable = [
        'inventory_id',
        'description',
        'item_class',
        'default_uom',
        'valuation_method',
        'is_stock_item',
        'sales_price',
        'default_warehouse_id',
        'qty_on_hand',
        'qty_available',
        'sync_run_id',
        'synced_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'is_stock_item' => 'boolean',
            'sales_price'   => 'decimal:4',
            'qty_on_hand'   => 'decimal:4',
            'qty_available' => 'decimal:4',
            'synced_at'     => 'datetime',
        ];
    }

    public function runRateLogs(): HasMany
    {
        return $this->hasMany(AcumaticaInventoryRunRateLog::class, 'inventory_item_id');
    }
}