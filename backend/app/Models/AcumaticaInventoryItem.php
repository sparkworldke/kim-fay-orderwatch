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
        'posting_class',
        'brand',
        'product_type',
        'product_category_id',
        'item_group',
        'sub_item_group',
        'trading_group',
        'sub_trading_group',
        'conversion_factor',
        'profit_margin_target',
        'supplier',
        'default_uom',
        'valuation_method',
        'is_stock_item',
        'sales_price',
        'default_warehouse_id',
        'item_status',
        'is_fol_eligible',
        'fol_category',
        'last_cost',
        'average_cost',
        'last_modified_at',
        'qty_on_hand',
        'qty_available',
        'sync_run_id',
        'synced_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'is_stock_item'      => 'boolean',
            'is_fol_eligible'    => 'boolean',
            'sales_price'        => 'decimal:4',
            'last_cost'          => 'decimal:4',
            'average_cost'       => 'decimal:4',
            'qty_on_hand'        => 'decimal:4',
            'qty_available'      => 'decimal:4',
            'conversion_factor'  => 'decimal:4',
            'last_modified_at'   => 'datetime',
            'synced_at'          => 'datetime',
        ];
    }

    public function runRateLogs(): HasMany
    {
        return $this->hasMany(AcumaticaInventoryRunRateLog::class, 'inventory_item_id');
    }

    public function productCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(AcumaticaProductCategory::class, 'product_category_id');
    }

    /**
     * Scope: items whose trading group matches one of the supplied values.
     * Used to align inventory records with Fillrate segmentation data.
     */
    public function scopeInTradingGroups(
        \Illuminate\Database\Eloquent\Builder $query,
        array $groups,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->whereIn('trading_group', $groups);
    }
}
