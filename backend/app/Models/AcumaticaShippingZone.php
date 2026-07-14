<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaShippingZone extends Model
{
    protected $fillable = [
        'acumatica_id',
        'description',
        'name',
        'region',
        'sync_run_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSyncLog::class, 'sync_run_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(AcumaticaCustomer::class, 'shipping_zone_id', 'acumatica_id');
    }

    /**
     * A shipping zone owns many delivery routes (Zone ID / Customer Zone).
     */
    public function routes(): HasMany
    {
        return $this->hasMany(AcumaticaRoute::class, 'shipping_zone_id', 'acumatica_id');
    }
}