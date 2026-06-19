<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationDispatchLog extends Model
{
    protected $fillable = [
        'rule_id',
        'evaluated_at',
        'channel',
        'recipient_user_id',
        'delivery_status',
    ];

    protected function casts(): array
    {
        return [
            'evaluated_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'rule_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
