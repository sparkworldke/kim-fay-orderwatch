<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentHodAssignment extends Model
{
    protected $fillable = [
        'department_id',
        'hod_user_id',
        'effective_from',
        'effective_to',
        'is_active',
        'assigned_by',
        'change_reason',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'is_active' => 'boolean',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function hod(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hod_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** The single active HOD for a department (PRD §8.2). */
    public static function activeHodIdFor(int $departmentId): ?int
    {
        return static::query()
            ->where('department_id', $departmentId)
            ->active()
            ->value('hod_user_id');
    }
}
