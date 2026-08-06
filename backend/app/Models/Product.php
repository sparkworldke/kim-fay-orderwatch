<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    protected $fillable = [
        'inventory_id',
        'acumatica_inventory_item_id',
        'name',
        'item_class',
        'posting_class',
        'brand_id',
        'category_id',
        'sub_category_id',
        'category_path',
        'source_description',
        'portfolio_group',
        'ownership',
        'is_active',
        'import_locked',
        'trading_group_id',
        'conversion_factor',
        'uom',
        'profit_margin_target',
        'supplier',
        'source',
        'last_imported_at',
        'manually_edited_at',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:4',
            'profit_margin_target' => 'decimal:4',
            'last_imported_at' => 'datetime',
            'manually_edited_at' => 'datetime',
            'is_active' => 'boolean',
            'import_locked' => 'boolean',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(AcumaticaInventoryItem::class, 'acumatica_inventory_item_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'sub_category_id');
    }

    public function tradingGroup(): BelongsTo
    {
        return $this->belongsTo(TradingGroup::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
