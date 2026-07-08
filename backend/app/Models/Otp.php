<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Otp extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'purpose',
        'otp_hash',
        'expires_at',
        'attempts',
        'resend_attempts',
        'resend_window_start',
    ];

    protected function casts(): array
    {
        return [
            'expires_at'         => 'datetime',
            'resend_window_start' => 'datetime',
            'attempts'           => 'integer',
            'resend_attempts'    => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
