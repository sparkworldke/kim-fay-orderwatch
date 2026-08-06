<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerAssignmentRule extends Model
{
    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_MAIN_ACCOUNT = 'main_account';
    public const TYPE_REGION = 'region';
    public const TYPE_REP_ALIAS = 'rep_alias';

    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_EXCEL = 'excel';
    public const SOURCE_ACUMATICA = 'acumatica';
    public const SOURCE_SEEDER = 'seeder';

    protected $fillable = [
        'uuid',
        'user_id',
        'rule_type',
        'match_value',
        'secondary_match_json',
        'priority',
        'source',
        'source_batch_id',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by',
        'change_reason',
    ];

    protected $casts = [
        'secondary_match_json' => 'array',
        'priority' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $rule) {
            if ($rule->uuid === null) {
                $rule->uuid = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    /** @return list<string> */
    public static function ruleTypes(): array
    {
        return [
            self::TYPE_CUSTOMER,
            self::TYPE_MAIN_ACCOUNT,
            self::TYPE_REGION,
            self::TYPE_REP_ALIAS,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserCustomerAssignment::class, 'assignment_rule_id');
    }
}
