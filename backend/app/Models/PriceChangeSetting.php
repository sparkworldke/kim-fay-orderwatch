<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChangeSetting extends Model
{
    protected $fillable = ['key', 'value_json'];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
        ];
    }
}
