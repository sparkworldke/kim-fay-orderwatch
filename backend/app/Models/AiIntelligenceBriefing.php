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
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'date_from'    => 'date',
            'date_to'      => 'date',
            'insights'     => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /** @return array<string, mixed> */
    public function insightPayload(): array
    {
        return is_array($this->insights) ? $this->insights : [];
    }
}