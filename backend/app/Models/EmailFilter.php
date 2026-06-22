<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailFilter extends Model
{
    protected $fillable = [
        'name',
        'type',
        'value',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
