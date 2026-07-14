<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAssignmentBatchRow extends Model
{
    protected $fillable = [
        'batch_id',
        'row_no',
        'rep_code',
        'customer_acumatica_id',
        'customer_name',
        'resolved_user_id',
        'action',
        'status',
        'source',
        'message',
        'details_json',
    ];

    protected function casts(): array
    {
        return [
            'details_json' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CustomerAssignmentBatch::class, 'batch_id');
    }
}
