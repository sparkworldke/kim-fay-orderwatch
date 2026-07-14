<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerData extends Model
{
    protected $table = 'customer_data';

    protected $fillable = [
        'customer_acumatica_id',
        'route_code',
        'shipping_zone_id',
        'customer_zone',
        'customer_group',
        'tax_registration_id',
        'currency_id',
        'price_class_id',
        'price_class_name',
        'main_ac_owner',
        'category',
        'customer_region',
        'sage_code',
        'business_account_id',
        'credit_limit',
        'statement_type',
        'statement_cycle',
        'shipping_rule',
        'delivery',
        'country',
        'city',
        'address_line_1',
        'address_line_2',
        'address_line_3',
        'email',
        'created_by',
        'created_on',
        'source',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'credit_limit' => 'decimal:2',
            'created_on'   => 'datetime',
            'synced_at'    => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AcumaticaCustomer::class, 'customer_acumatica_id', 'acumatica_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(AcumaticaRoute::class, 'route_code', 'route_code');
    }

    public function shippingZone(): BelongsTo
    {
        return $this->belongsTo(AcumaticaShippingZone::class, 'shipping_zone_id', 'acumatica_id');
    }
}
