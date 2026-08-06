<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryWarehouseBalance extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'inventory_id',
        'warehouse_id',
        'qty_on_hand',
        'qty_available',
        'uom',
        'sync_run_id',
        'synced_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'qty_on_hand' => 'decimal:4',
            'qty_available' => 'decimal:4',
            'synced_at' => 'datetime',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(AcumaticaInventoryItem::class, 'inventory_item_id');
    }
}
