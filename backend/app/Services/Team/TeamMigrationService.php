<?php

namespace App\Services\Team;

use App\Models\Department;
use App\Models\DepartmentHodAssignment;
use App\Models\TeamMigrationBatch;
use App\Models\User;
use App\Services\Cache\DomainCache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TeamMigrationService
{
    public function __construct(
        private readonly OrgTreeService $orgTree,
        private readonly CustomerAttributionService $attribution,
    ) {}

    /** @param list<int> $memberIds @param array<string, bool> $includeSubtrees */
    public function preview(
        User $actor,
        string $operation,
        ?int $sourceDepartmentId,
        int $destinationDepartmentId,
        ?int $newHodId,
        array $memberIds,
        array $includeSubtrees,
        string $effectiveDate,
        string $reason,
    ): TeamMigrationBatch {
        $destination = Department::query()->findOrFail($destinationDepartmentId);
        $newHod = $newHodId ? User::query()->findOrFail($newHodId) : null;
        $errors = [];
        $oldHodId = null;

        if ($newHod && ! $newHod->is_active) {
            $errors[] = 'The destination manager/HOD is inactive.';
        }

        $expandedIds = [];
        foreach (array_values(array_unique($memberIds)) as $memberId) {
            $member = User::query()->find($memberId);
            if (! $member || ! $member->is_active) {
                $errors[] = "Member {$memberId} is missing or inactive.";
                continue;
            }
            $expandedIds[] = $memberId;
            $descendants = $this->orgTree->descendantIds($memberId);
            if ($descendants !== [] && ! array_key_exists((string) $memberId, $includeSubtrees)) {
                $errors[] = "Choose Member only or Member with subtree for {$member->name}.";
            }
            if (($includeSubtrees[(string) $memberId] ?? false) === true) {
                $expandedIds = [...$expandedIds, ...$descendants];
            }
            if ($newHod && in_array($newHod->id, [$memberId, ...$descendants], true)) {
                $errors[] = "Moving {$member->name} under {$newHod->name} would create a reporting cycle.";
            }
        }
        $expandedIds = array_values(array_unique($expandedIds));

        if ($operation === TeamMigrationBatch::OPERATION_REPLACE_HOD) {
            $sourceHod = DepartmentHodAssignment::query()
                ->where('department_id', $sourceDepartmentId ?? $destinationDepartmentId)
                ->where('is_active', true)
                ->latest('id')
                ->first();
            if (! $sourceHod || ! $newHod) {
                $errors[] = 'An active old HOD and a new HOD are required.';
            } elseif ($sourceHod->hod_user_id === $newHod->id) {
                $errors[] = 'The new HOD must differ from the current HOD.';
            } else {
                $oldHodId = $sourceHod->hod_user_id;
                $memberIds = User::query()->where('reports_to_user_id', $sourceHod->hod_user_id)->pluck('id')->all();
                $expandedIds = $memberIds;
            }
        }

        return TeamMigrationBatch::create([
            'source_department_id' => $sourceDepartmentId,
            'destination_department_id' => $destination->id,
            'old_hod_user_id' => $oldHodId,
            'new_hod_user_id' => $newHod?->id,
            'operation' => $operation,
            'member_ids' => array_values(array_unique($memberIds)),
            'include_subtrees' => $includeSubtrees,
            'preview_statistics' => [
                'affected_user_ids' => $expandedIds,
                'reparent_user_ids' => array_values(array_unique($memberIds)),
                'affected_users' => User::query()->whereIn('id', $expandedIds)->get(['id', 'name', 'email', 'department_id', 'reports_to_user_id']),
                'customer_mappings_preserved' => DB::table('user_customer_assignments')->whereIn('user_id', $expandedIds)->count(),
                'visible_customer_count_before' => count(array_unique(array_merge(...array_map(
                    fn ($id) => $this->attribution->directCustomerIds($id),
                    $expandedIds ?: [0],
                )))),
                'destination_department' => ['id' => $destination->id, 'slug' => $destination->slug, 'name' => $destination->name],
                'new_manager_id' => $newHod?->id,
            ],
            'validation_errors' => $errors,
            'effective_date' => $effectiveDate,
            'change_reason' => $reason,
            'status' => TeamMigrationBatch::STATUS_PREVIEW,
            'created_by' => $actor->id,
        ]);
    }

    public function apply(User $actor, TeamMigrationBatch $batch): TeamMigrationBatch
    {
        if ($batch->status !== TeamMigrationBatch::STATUS_PREVIEW || ($batch->validation_errors ?? []) !== []) {
            throw ValidationException::withMessages(['batch' => ['Only a valid preview can be applied.']]);
        }

        return DB::transaction(function () use ($actor, $batch) {
            $affectedIds = $batch->preview_statistics['affected_user_ids'] ?? [];
            $destinationId = (int) $batch->destination_department_id;
            $managerId = $batch->new_hod_user_id;
            $reparentIds = $batch->preview_statistics['reparent_user_ids'] ?? [];

            foreach ($affectedIds as $userId) {
                DB::table('department_user')->where('user_id', $userId)->update(['is_primary' => false]);
                DB::table('department_user')->updateOrInsert(
                    ['department_id' => $destinationId, 'user_id' => $userId],
                    ['membership_role' => 'member', 'is_primary' => true, 'updated_at' => now(), 'created_at' => now()],
                );
                $changes = ['department_id' => $destinationId];
                if (in_array($userId, $reparentIds, true)) {
                    $changes['reports_to_user_id'] = $managerId;
                }
                User::query()->whereKey($userId)->update($changes);
            }

            if ($batch->operation === TeamMigrationBatch::OPERATION_REPLACE_HOD) {
                DepartmentHodAssignment::query()
                    ->where('department_id', $destinationId)
                    ->where('is_active', true)
                    ->update(['is_active' => false, 'effective_to' => $batch->effective_date]);
                DepartmentHodAssignment::create([
                    'department_id' => $destinationId,
                    'hod_user_id' => $managerId,
                    'effective_from' => $batch->effective_date,
                    'is_active' => true,
                    'assigned_by' => $actor->id,
                    'change_reason' => $batch->change_reason,
                ]);
            }

            $batch->update([
                'status' => TeamMigrationBatch::STATUS_APPLIED,
                'applied_by' => $actor->id,
                'applied_at' => now(),
            ]);
            app(DomainCache::class)->bump(
                DomainCache::CUSTOMER_ANALYTICS,
                DomainCache::SALES_PORTFOLIO,
                DomainCache::SALES_INTELLIGENCE,
                DomainCache::KP_CRM,
            );

            return $batch->fresh();
        });
    }
}
