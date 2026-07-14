<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceChangeRequest extends Model
{
    protected $fillable = [
        'public_ref',
        'customer_acumatica_id',
        'customer_name',
        'customer_price_class',
        'customer_payment_terms',
        'inventory_id',
        'product_description',
        'current_selling_price',
        'proposed_selling_price',
        'base_price_snapshot',
        'margin_pct_snapshot',
        'margin_kes_snapshot',
        'currency_id',
        'justification',
        'effective_date_requested',
        'status',
        'current_stage_key',
        'submitted_by_user_id',
        'duplicate_ack_required',
        'duplicate_acked_by',
        'duplicate_acked_at',
        'submitted_at',
        'decided_at',
        'acumatica_apply_notified_at',
        'acumatica_applied_at',
        'acumatica_applied_by',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'current_selling_price' => 'decimal:4',
            'proposed_selling_price' => 'decimal:4',
            'base_price_snapshot' => 'decimal:4',
            'margin_pct_snapshot' => 'decimal:4',
            'margin_kes_snapshot' => 'decimal:4',
            'duplicate_ack_required' => 'boolean',
            'duplicate_acked_at' => 'datetime',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'effective_date_requested' => 'date',
            'acumatica_apply_notified_at' => 'datetime',
            'acumatica_applied_at' => 'datetime',
        ];
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvalActions(): HasMany
    {
        return $this->hasMany(PriceChangeApprovalAction::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PriceChangeEvent::class);
    }
}
