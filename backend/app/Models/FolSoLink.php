<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolSoLink extends Model
{
    protected $fillable = [
        'fol_request_id',
        'sales_order_id',
        'acumatica_order_nbr',
        'po_number',
        'link_type',
        'matched_at',
        'matched_by',
    ];

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(FolRequest::class, 'fol_request_id');
    }

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(AcumaticaSalesOrder::class, 'sales_order_id');
    }
}
