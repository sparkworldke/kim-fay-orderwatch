<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAssignmentBatch extends Model
{
    protected $fillable = [
        'uuid',
        'source',
        'mode',
        'status',
        'initiated_by',
        'target_user_id',
        'filename',
        'stats_json',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'stats_json' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(CustomerAssignmentBatchRow::class, 'batch_id');
    }
}
