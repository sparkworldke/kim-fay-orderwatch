<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSession extends Model
{
    protected $fillable = [
        'user_id',
        'login_at',
        'logout_at',
        'logout_reason',
        'duration_seconds',
        'ip_address',
        'user_agent',
        'login_mode',
    ];

    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'logout_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function close(string $reason = 'manual'): void
    {
        if ($this->logout_at !== null) {
            return;
        }

        $logoutAt = now();
        $this->forceFill([
            'logout_at' => $logoutAt,
            'logout_reason' => $reason,
            'duration_seconds' => max(0, $this->login_at->diffInSeconds($logoutAt)),
        ])->save();
    }
}