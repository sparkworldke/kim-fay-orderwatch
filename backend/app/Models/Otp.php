<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Otp extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'otp_hash',
        'expires_at',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'attempts'   => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
