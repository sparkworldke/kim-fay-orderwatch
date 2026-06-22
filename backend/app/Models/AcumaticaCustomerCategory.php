<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcumaticaCustomerCategory extends Model
{
    protected $fillable = [
        'acumatica_id',
        'description',
        'sync_run_id',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'synced_at' => 'datetime',
        ];
    }
}
