<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSectorScope extends Model
{
    protected $fillable = [
        'user_id',
        'sector',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}