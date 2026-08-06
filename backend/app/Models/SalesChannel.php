<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SalesChannel extends Model
{
    public const MT = 'MT';
    public const MT1 = 'MT1';
    public const MT2 = 'MT2';
    public const GT = 'GT';
    public const DTC_DTB = 'DTC_DTB';
    public const ECOMMERCE = 'ECOMMERCE';
    public const KP = 'KP';

    protected $fillable = [
        'code',
        'name',
        'parent_code',
        'sort_order',
        'is_active',
        'department_slug',
        'notes',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', $code)->first();
    }
}
