<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCustomerAssignment extends Model
{
    public const TYPE_OWNER = 'owner';
    public const TYPE_SERVICING = 'servicing';
    public const TYPE_LEGACY_PRIMARY = 'primary';
    public const ASSIGNABLE_TYPES = [self::TYPE_OWNER, self::TYPE_SERVICING];

    protected $fillable = [
        'user_id',
        'customer_acumatica_id',
        'assignment_type',
        'assigned_by',
        'notes',
        'source',
        'source_batch_id',
        'last_so_date',
        'so_order_count',
        'confidence',
        'effective_from',
        'effective_to',
        'priority',
        'is_manual_override',
        'assignment_rule_id',
    ];

    protected function casts(): array
    {
        return [
            'last_so_date' => 'date',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'priority' => 'integer',
            'is_manual_override' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(CustomerAssignmentRule::class, 'assignment_rule_id');
    }
}
