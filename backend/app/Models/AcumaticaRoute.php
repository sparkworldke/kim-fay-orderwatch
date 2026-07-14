<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaRoute extends Model
{
    protected $table = 'acumatica_routes';

    protected $fillable = [
        'route_code',
        'route_name',
        'description',
        'shipping_zone_id',
        'customer_zone',
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

    /**
     * A route belongs to a single shipping zone (Zone ID / Customer Zone).
     */
    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(AcumaticaShippingZone::class, 'shipping_zone_id', 'acumatica_id');
    }

    public function customers(): HasMany
    {
        return $this->hasMany(AcumaticaCustomer::class, 'route_code', 'route_code');
    }
}
