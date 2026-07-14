<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffImportGap extends Model
{
    protected $fillable = [
        'email',
        'employee_number',
        'display_name',
        'gap_reason',
        'match_score',
        'source_payload',
        'resolution_status',
        'resolved_user_id',
    ];

    protected function casts(): array
    {
        return [
            'match_score' => 'float',
            'source_payload' => 'array',
        ];
    }

    public function resolvedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_user_id');
    }
}