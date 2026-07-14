<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaCustomer extends Model
{
    use HasFactory;
    protected $fillable = [
        'acumatica_id',
        'parent_acumatica_id',
        'is_main_account',
        'name',
        'status',
        'email',
        'phone',
        'customer_class',
        'payment_terms',
        'tax_zone',
        'shipping_zone_id',
        'route_code',
        'billing_address',
        'shipping_address',
        'sync_run_id',
        'acumatica_last_modified',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_address'         => 'array',
            'shipping_address'        => 'array',
            'acumatica_last_modified' => 'datetime',
            'synced_at'               => 'datetime',
            'is_main_account'         => 'boolean',
        ];
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSyncLog::class, 'sync_run_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_acumatica_id', 'acumatica_id');
    }

    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(AcumaticaShippingZone::class, 'shipping_zone_id', 'acumatica_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(AcumaticaRoute::class, 'route_code', 'route_code');
    }

    public function customerData(): BelongsTo
    {
        return $this->belongsTo(CustomerData::class, 'acumatica_id', 'customer_acumatica_id');
    }

    public function branches(): HasMany
    {
        return $this->hasMany(self::class, 'parent_acumatica_id', 'acumatica_id');
    }
}
