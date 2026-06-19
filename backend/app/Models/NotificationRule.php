<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationRule extends Model
{
    protected $fillable = [
        'rule_key',
        'label',
        'channels',
        'is_enabled',
        'last_evaluated_at',
        'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'channels'           => 'array',
            'is_enabled'         => 'boolean',
            'last_evaluated_at'  => 'datetime',
            'last_triggered_at'  => 'datetime',
        ];
    }

    public function dispatchLogs(): HasMany
    {
        return $this->hasMany(NotificationDispatchLog::class, 'rule_id');
    }
}
