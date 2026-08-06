<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionMonthlyOutput extends Model
{
    protected $fillable = [
        'inventory_item_id', 'month', 'qty_produced', 'source', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['month' => 'date', 'qty_produced' => 'decimal:4'];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(AcumaticaInventoryItem::class, 'inventory_item_id');
    }
}
