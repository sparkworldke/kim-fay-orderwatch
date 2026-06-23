<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiPromptLog extends Model
{
    protected $fillable = [
        'user_id',
        'user_role',
        'prompt',
        'intent',
        'domains',
        'formulas_used',
        'db_query_scope',
        'ai_message',
        'cards_returned',
        'sources',
        'provider',
        'response_time_ms',
        'status',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'domains'       => 'array',
            'formulas_used' => 'array',
            'db_query_scope'=> 'array',
            'cards_returned'=> 'array',
            'sources'       => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
