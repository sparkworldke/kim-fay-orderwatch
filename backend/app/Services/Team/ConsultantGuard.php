<?php

namespace App\Services\Team;

use App\Models\AcumaticaSalesOrder;
use App\Models\ConsultantAssignmentAudit;
use App\Models\User;

class ConsultantGuard
{
    public const TRADITIONAL_ROLE = 'Sales Consultant';

    public function validateConsultant(User $consultant): void
    {
        if (! $consultant->is_active) {
            throw new \InvalidArgumentException('Consultant account is not active.');
        }

        if (! $consultant->is_consultant) {
            throw new \InvalidArgumentException('User is not designated as a consultant.');
        }
    }

    public function assignToOrder(
        AcumaticaSalesOrder $order,
        User $consultant,
        ?User $assignedBy = null,
        string $source = 'manual',
    ): AcumaticaSalesOrder {
        $this->validateConsultant($consultant);

        $order->forceFill([
            'consultant_user_id' => $consultant->id,
            'sales_consultant_name' => $consultant->name,
            'sales_consultant_rep_code' => $consultant->rep_code
                ? strtoupper(trim($consultant->rep_code))
                : $order->sales_consultant_rep_code,
        ])->save();

        ConsultantAssignmentAudit::create([
            'order_id' => $order->id,
            'consultant_user_id' => $consultant->id,
            'consultant_role' => (string) $consultant->role,
            'is_non_traditional_role' => $consultant->role !== self::TRADITIONAL_ROLE,
            'assigned_by' => $assignedBy?->id,
            'source' => $source,
        ]);

        return $order->fresh();
    }
}