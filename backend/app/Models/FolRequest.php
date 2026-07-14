<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FolRequest extends Model
{
    protected $fillable = [
        'public_ref',
        'customer_acumatica_id',
        'customer_name',
        'sales_consultant_user_id',
        'sales_consultant_email',
        'sales_consultant_rep_code',
        'request_origin',
        'request_origin_other',
        'requestor_first_name',
        'requestor_last_name',
        'requestor_phone',
        'requestor_email',
        'issue_types',
        'reason_text',
        'installation_required',
        'installation_location',
        'assigned_technician_user_id',
        'technician_assigned_by',
        'technician_assigned_at',
        'customer_has_submitted_po',
        'consumables_last_purchase_date',
        'consumables_sales_6m_kes',
        'consumables_volume_6m',
        'consumables_metrics_source',
        'consumables_override_reason',
        'debt_explanation',
        'status',
        'current_stage_key',
        'linked_so_order_nbrs',
        'linked_so_status_summary',
        'form_json',
        'submitted_at',
        'decided_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'issue_types' => 'array',
            'installation_required' => 'boolean',
            'customer_has_submitted_po' => 'boolean',
            'consumables_last_purchase_date' => 'date',
            'consumables_sales_6m_kes' => 'decimal:2',
            'consumables_volume_6m' => 'decimal:4',
            'linked_so_order_nbrs' => 'array',
            'form_json' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'technician_assigned_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FolRequestLine::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FolRequestAttachment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(FolRequestEvent::class);
    }

    public function approvalActions(): HasMany
    {
        return $this->hasMany(FolApprovalAction::class);
    }

    public function soLinks(): HasMany
    {
        return $this->hasMany(FolSoLink::class);
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sales_consultant_user_id');
    }

    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_technician_user_id');
    }

    public function technicianAssigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_assigned_by');
    }
}
