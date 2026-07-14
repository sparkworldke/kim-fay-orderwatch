<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceChangeApprovalAction extends Model
{
    protected $fillable = [
        'price_change_request_id',
        'stage_key',
        'actor_user_id',
        'decision',
        'comment',
        'margin_seen_pct',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'margin_seen_pct' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PriceChangeRequest::class, 'price_change_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
