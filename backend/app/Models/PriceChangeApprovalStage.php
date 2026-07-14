<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriceChangeApprovalStage extends Model
{
    protected $fillable = [
        'key',
        'name',
        'sort_order',
        'assignee_mode',
        'role_names',
        'user_ids',
        'require_comment_on_reject',
        'sla_hours',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'role_names' => 'array',
            'user_ids' => 'array',
            'require_comment_on_reject' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
