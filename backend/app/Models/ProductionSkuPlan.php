<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductionSkuPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'inventory_item_id', 'ownership', 'business_line', 'site', 'machine',
        'msi', 'safety_stock', 'buffer_stock', 'export_msi', 'export_requirement',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'msi' => 'decimal:4',
            'safety_stock' => 'decimal:4',
            'buffer_stock' => 'decimal:4',
            'export_msi' => 'decimal:4',
            'export_requirement' => 'decimal:4',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(AcumaticaInventoryItem::class, 'inventory_item_id');
    }

    public function machines(): BelongsToMany
    {
        return $this->belongsToMany(ProductionMachine::class, 'production_machine_plan');
    }
}
