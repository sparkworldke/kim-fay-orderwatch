<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesManagementPromptEvent extends Model
{
    protected $fillable = [
        'sales_management_prompt_id',
        'event_type',
        'actor_user_id',
        'comment',
        'payload_json',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
        ];
    }

    public function prompt(): BelongsTo
    {
        return $this->belongsTo(SalesManagementPrompt::class, 'sales_management_prompt_id');
    }
}
