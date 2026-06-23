<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class EmailMatchAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'email_id', 'order_id', 'actor_user_id', 'cron_run_log_id', 'rule_version', 'searched_po', 'candidates',
        'sources', 'normalization', 'conflicts', 'reason_codes', 'classification', 'confidence',
    ];

    protected function casts(): array
    {
        return [
            'candidates' => 'array', 'sources' => 'array', 'normalization' => 'array',
            'conflicts' => 'array', 'reason_codes' => 'array', 'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Match attempts are append-only.'));
        static::deleting(fn () => throw new LogicException('Match attempts are append-only.'));
    }
}
