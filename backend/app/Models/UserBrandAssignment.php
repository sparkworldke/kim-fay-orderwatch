<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBrandAssignment extends Model
{
    protected $fillable = [
        'user_id',
        'brand',
        'brand_id',
        'assigned_by',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Named brandRecord (not brand) because `brand` is already a real string
     * column on this table — Eloquent would always resolve ->brand to the
     * column value, never this relation, if they shared a name.
     */
    public function brandRecord(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }
}