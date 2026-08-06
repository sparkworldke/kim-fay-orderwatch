<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntelligenceBriefing extends Model
{
    protected $fillable = [
        'date_from',
        'date_to',
        'insights',
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
            'date_from' => 'date',
            'date_to' => 'date',
            'insights' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed>|null */
    public function insightPayload(): ?array
    {
        if (! is_array($this->insights) || $this->insights === []) {
            return null;
        }

        return $this->insights;
    }
}
