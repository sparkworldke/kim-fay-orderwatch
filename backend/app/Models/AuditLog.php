<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'timestamp',
        'actor_user_id',
        'actor_ip',
        'action_type',
        'resource_type',
        'resource_id',
        'changes',
    ];

    protected function casts(): array
    {
        return [
            'changes'   => 'array',
            'timestamp' => 'datetime',
        ];
    }

    /**
     * Disable the updated_at column — this table is append-only.
     */
    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
