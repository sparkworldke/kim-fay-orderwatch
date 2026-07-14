<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolApprovalAction extends Model
{
    protected $fillable = [
        'fol_request_id',
        'stage_key',
        'actor_user_id',
        'decision',
        'comment',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FolRequest::class, 'fol_request_id');
    }
}
