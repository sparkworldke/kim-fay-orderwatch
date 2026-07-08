<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventorySkuInsight extends Model
{
    protected $table = 'inventory_sku_insights';

    protected $fillable = [
        'inventory_id',
        'date_from',
        'date_to',
        'ai_response',
        'ai_status',
        'data_gaps',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'ai_response'  => 'array',
            'data_gaps'    => 'array',
            'generated_at' => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }
}
