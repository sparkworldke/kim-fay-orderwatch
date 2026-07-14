<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SoReasonParent extends Model
{
    protected $fillable = ['code', 'label', 'sort_order'];

    public function subReasons(): BelongsToMany
    {
        return $this->belongsToMany(
            SoSubReason::class,
            'so_reason_parent_sub_reason',
            'parent_id',
            'sub_reason_id',
        )->orderBy('so_sub_reasons.sort_order');
    }
}