<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'is_customer_facing',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_customer_facing' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'department_user')
            ->withPivot(['membership_role', 'is_primary'])
            ->withTimestamps();
    }

    public function brandAssignments(): HasMany
    {
        return $this->hasMany(DepartmentBrand::class);
    }
}