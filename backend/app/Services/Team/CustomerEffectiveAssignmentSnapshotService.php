<?php

namespace App\Services\Team;

use Illuminate\Support\Facades\DB;

class CustomerEffectiveAssignmentSnapshotService
{
    public function __construct(private readonly CustomerAttributionService $attribution) {}

    /** @return array{resolved: int, unresolved: int} */
    public function rebuild(): array
    {
        $stats = ['resolved' => 0, 'unresolved' => 0];
        $customers = DB::table('acumatica_customers')
            ->get(['acumatica_id', 'sales_channel_code']);

        DB::transaction(function () use ($customers, &$stats) {
            foreach ($customers as $customer) {
                $resolution = $this->attribution->resolveCustomerAssignment((string) $customer->acumatica_id);
                $status = $resolution->resolved() ? 'resolved' : ($resolution->ambiguous() ? 'ambiguous' : 'unresolved');
                $stats[$resolution->resolved() ? 'resolved' : 'unresolved']++;
                $payload = $resolution->toArray();

                DB::table('customer_effective_assignments')->updateOrInsert(
                    [
                        'customer_acumatica_id' => $customer->acumatica_id,
                        'assignment_type' => 'servicing',
                    ],
                    [
                        'resolved_user_id' => $resolution->userId,
                        'winning_source' => $resolution->winningSource,
                        'assignment_rule_id' => $resolution->assignmentRuleId,
                        'sales_channel_code' => $customer->sales_channel_code,
                        'resolution_status' => $status,
                        'source_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
                        'resolved_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        });

        return $stats;
    }
}
