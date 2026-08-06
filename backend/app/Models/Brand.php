<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Brand extends Model
{
    protected $fillable = [
        'name',
        'ownership',
        'partner_brand_group_id',
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

    public function userAssignments(): HasMany
    {
        return $this->hasMany(UserBrandAssignment::class);
    }

    public function partnerGroup(): BelongsTo
    {
        return $this->belongsTo(TradingGroup::class, 'partner_brand_group_id');
    }
}
