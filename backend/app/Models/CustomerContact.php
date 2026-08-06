<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    public const DESIGNATION_CEO_MD = 'ceo_md';

    public const DESIGNATION_CFO_FINANCE = 'cfo_finance';

    public const DESIGNATION_CCO_COO = 'cco_coo';

    public const DESIGNATION_HEAD_PROCUREMENT = 'head_procurement';

    public const DESIGNATION_CUSTOM = 'custom';

    /** @var array<string, string> */
    public const DESIGNATIONS = [
        self::DESIGNATION_CEO_MD => 'CEO/MD',
        self::DESIGNATION_CFO_FINANCE => 'CFO/Head of Finance',
        self::DESIGNATION_CCO_COO => 'CCO/COO',
        self::DESIGNATION_HEAD_PROCUREMENT => 'Head of Procurement',
        self::DESIGNATION_CUSTOM => 'Custom',
    ];

    protected $fillable = [
        'customer_acumatica_id',
        'designation_key',
        'designation_label',
        'first_name',
        'last_name',
        'phone',
        'email',
        'is_primary',
        'is_active',
        'notes',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(AcumaticaCustomer::class, 'customer_acumatica_id', 'acumatica_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function designationDisplay(): string
    {
        return $this->designation_label
            ?: (self::DESIGNATIONS[$this->designation_key] ?? $this->designation_key);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'customer_acumatica_id' => $this->customer_acumatica_id,
            'designation_key' => $this->designation_key,
            'designation_label' => $this->designationDisplay(),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->displayName(),
            'phone' => $this->phone,
            'email' => $this->email,
            'is_primary' => $this->is_primary,
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
