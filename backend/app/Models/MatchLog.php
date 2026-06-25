<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatchLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'match_log';

    protected $fillable = [
        'email_id',
        'prediction_id',
        'order_nbr',
        'status',
        'canonical_po',
        'accepted_by',
        'accepted_at',
        'rejection_reason',
        'canonical_email_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
            'metadata'    => 'array',
            'created_at'  => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('match_log is append-only.'));
        static::deleting(fn () => throw new LogicException('match_log is append-only.'));
    }

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function prediction(): BelongsTo
    {
        return $this->belongsTo(MatchPrediction::class, 'prediction_id');
    }

    public function acceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}