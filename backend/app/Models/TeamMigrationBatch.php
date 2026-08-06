<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMigrationBatch extends Model
{
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    public const OPERATION_REPLACE_HOD = 'replace_hod';
    public const OPERATION_TRANSFER_MEMBERS = 'transfer_members';

    protected $fillable = [
        'uuid',
        'source_department_id',
        'destination_department_id',
        'old_hod_user_id',
        'new_hod_user_id',
        'operation',
        'member_ids',
        'include_subtrees',
        'secondary_membership_decisions',
        'preview_statistics',
        'validation_errors',
        'effective_date',
        'change_reason',
        'status',
        'created_by',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'member_ids' => 'array',
        'include_subtrees' => 'array',
        'secondary_membership_decisions' => 'array',
        'preview_statistics' => 'array',
        'validation_errors' => 'array',
        'effective_date' => 'date',
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $batch) {
            if ($batch->uuid === null) {
                $batch->uuid = (string) \Illuminate\Support\Str::orderedUuid();
            }
        });
    }

    public function sourceDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'source_department_id');
    }

    public function destinationDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'destination_department_id');
    }

    public function oldHod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_hod_user_id');
    }

    public function newHod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_hod_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }
}
