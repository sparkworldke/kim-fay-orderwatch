<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCustomerAssignment extends Model
{
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
    ];

    protected function casts(): array
    {
        return [
            'last_so_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
