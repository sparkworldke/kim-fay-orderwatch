<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsultantAssignmentAudit extends Model
{
    protected $fillable = [
        'order_id',
        'consultant_user_id',
        'consultant_role',
        'is_non_traditional_role',
        'assigned_by',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'is_non_traditional_role' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'order_id');
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_user_id');
    }
}