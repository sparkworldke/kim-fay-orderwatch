<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentBrand extends Model
{
    protected $fillable = [
        'department_id',
        'brand',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}