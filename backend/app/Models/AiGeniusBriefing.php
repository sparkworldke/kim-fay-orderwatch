<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiGeniusBriefing extends Model
{
    protected $fillable = [
        'consultant_user_id',
        'week_start',
        'insights',
        'metrics_snapshot',
        'ai_status',
        'provider',
        'model',
        'error_message',
        'queue_uuid',
        'generated_at',
        'generated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'insights' => 'array',
            'metrics_snapshot' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }

    /** @return array<string, mixed> */
    public function insightPayload(): array
    {
        return is_array($this->insights) ? $this->insights : [];
    }
}
