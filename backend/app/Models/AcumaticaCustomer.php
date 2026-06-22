<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AcumaticaCustomer extends Model
{
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
}
