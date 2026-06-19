<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiApiKey extends Model
{
    protected $fillable = [
        'provider',
        'key_encrypted',
        'created_by',
        'last_used_at',
        'health_status',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
