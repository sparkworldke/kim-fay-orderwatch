<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FolApprovalStage extends Model
{
    protected $fillable = [
        'key',
        'name',
        'sort_order',
        'is_active',
        'assignee_mode',
        'role_names',
        'user_ids',
        'require_comment',
        'sla_hours',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'role_names' => 'array',
            'user_ids' => 'array',
            'require_comment' => 'boolean',
        ];
    }
}
