<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolRequestLine extends Model
{
    protected $fillable = [
        'fol_request_id',
        'line_no',
        'inventory_id',
        'product_description',
        'qty_requested',
        'qty_previously_issued',
        'date_last_issue',
        'previous_source',
        'commitment_sku_ids',
    ];

    protected function casts(): array
    {
        return [
            'qty_requested' => 'decimal:4',
            'qty_previously_issued' => 'decimal:4',
            'date_last_issue' => 'date',
            'commitment_sku_ids' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FolRequest::class, 'fol_request_id');
    }
}
