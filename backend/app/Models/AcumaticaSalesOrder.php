<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcumaticaSalesOrder extends Model
{
    protected $fillable = [
        'acumatica_order_nbr',
        'order_type',
        'customer_acumatica_id',
        'customer_name',
        'customer_order',
        'location_id',
        'status',
        'order_date',
        'last_modified_at',
        'ship_date',
        'requested_on',
        'order_total',
        'currency_id',
        'sync_run_id',
        'raw_payload',
        'synced_at',
        'approved_at',
        'shipped_at',
        'completed_at',
        'match_status',
        'flag_source',
        'rejection_reason',
        'on_hold_reason',
        'email_subject',
        'email_received_at',
    ];

    protected function casts(): array
    {
        return [
            'email_received_at' => 'datetime',
            'order_date'        => 'datetime',
            'ship_date'         => 'datetime',
            'requested_on'      => 'datetime',
            'last_modified_at'  => 'datetime',
            'synced_at'        => 'datetime',
            'approved_at'      => 'datetime',
            'shipped_at'       => 'datetime',
            'completed_at'     => 'datetime',
            'order_total'      => 'decimal:2',
            'raw_payload'      => 'array',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AcumaticaSalesOrderLine::class, 'sales_order_id');
    }

    /** Resolved customer record from the customers table. */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(AcumaticaCustomer::class, 'customer_acumatica_id', 'acumatica_id');
    }
}
