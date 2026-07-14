<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAcumaticaRepMapping extends Model
{
    protected $fillable = [
        'user_id',
        'acumatica_consultant_id',
        'acumatica_rep_code',
        'is_primary',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}