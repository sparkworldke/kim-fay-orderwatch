<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesManagementPrompt extends Model
{
    public const TYPE_ORDER_CYCLE = 'order_cycle_follow_up';
    public const TYPE_NOT_BILLED = 'not_billed_month';

    protected $fillable = [
        'prompt_type',
        'status',
        'severity',
        'idempotency_key',
        'period_key',
        'customer_acumatica_id',
        'customer_name',
        'consultant_user_id',
        'consultant_rep_code',
        'consultant_name',
        'source_from',
        'source_to',
        'last_order_date',
        'expected_cycle_days',
        'days_since_last_order',
        'due_date',
        'snoozed_until',
        'value_snapshot',
        'order_count_snapshot',
        'reason',
        'payload_json',
        'notified_at',
        'resolved_at',
        'resolved_by',
        'resolution_note',
        'dismissed_at',
        'dismissed_by',
        'dismiss_reason',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'source_from' => 'date',
            'source_to' => 'date',
            'last_order_date' => 'date',
            'due_date' => 'date',
            'snoozed_until' => 'datetime',
            'value_snapshot' => 'decimal:2',
            'payload_json' => 'array',
            'notified_at' => 'datetime',
            'resolved_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SalesManagementPromptEvent::class);
    }
}
