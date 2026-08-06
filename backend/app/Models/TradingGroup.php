<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TradingGroup extends Model
{
    protected $fillable = [
        'name',
        'is_active',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function partnerBrands(): HasMany
    {
        return $this->hasMany(Brand::class, 'partner_brand_group_id');
    }
}
