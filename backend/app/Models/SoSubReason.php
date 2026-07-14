<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SoSubReason extends Model
{
    protected $fillable = ['code', 'label', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parents(): BelongsToMany
    {
        return $this->belongsToMany(
            SoReasonParent::class,
            'so_reason_parent_sub_reason',
            'sub_reason_id',
            'parent_id',
        );
    }
}