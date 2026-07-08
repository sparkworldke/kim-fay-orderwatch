<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaProductCategory extends Model
{
    protected $fillable = [
        'acumatica_id',
        'description',
        'item_type',
        'default_uom',
        'sync_run_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(AcumaticaInventoryItem::class, 'product_category_id');
    }
}
