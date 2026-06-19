<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignInLog extends Model
{
    // sign_in_logs only has created_at, no updated_at
    const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'email_hash',
        'ip_address',
        'user_agent',
        'login_mode',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
