<?php

namespace App\Services\Team;

use App\Models\Department;
use App\Models\OrgChartAudit;
use App\Models\User;
use App\Models\UserSectorScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserOrgService
{
    public function __construct(
        private readonly OrgTreeService $orgTree,
    ) {}

    /** @param  array<string, mixed>  $payload */
    public function applyOrgConfig(User $user, array $payload, ?User $actor = null): User
    {
        return DB::transaction(function () use ($user, $payload, $actor) {
            $before = $this->orgSnapshot($user);

            if (array_key_exists('reports_to_user_id', $payload)) {
                $reportsTo = $payload['reports_to_user_id'];
                $reportsToId = $reportsTo === null || $reportsTo === ''
                    ? null
                    : (int) $reportsTo;
                // Dynamic org: any user → any active manager (cycle / self only blocked).
                $this->orgTree->assertValidReportsTo($user->id, $reportsToId);
                $user->reports_to_user_id = $reportsToId;
            }

            foreach (['org_level', 'product_type_scope', 'data_scope_mode', 'is_shared_mailbox'] as $field) {
                if (array_key_exists($field, $payload)) {
                    $user->{$field} = $payload[$field];
                }
            }

            if (array_key_exists('org_level', $payload) && ! array_key_exists('data_scope_mode', $payload)) {
                $user->data_scope_mode = $this->defaultDataScopeMode((string) $payload['org_level']);
            }

            if (array_key_exists('department_id', $payload)) {
                $user->department_id = $payload['department_id'];
            }

            if (array_key_exists('department_role', $payload)) {
                $user->department_role = $payload['department_role'];
            }

            $user->save();

            if (array_key_exists('department_ids', $payload)) {
                $this->syncDepartments(
                    $user,
                    array_map('intval', (array) $payload['department_ids']),
                    isset($payload['department_id']) ? (int) $payload['department_id'] : $user->department_id,
                    (string) ($payload['department_role'] ?? $user->department_role ?? 'member'),
                );
            } elseif (array_key_exists('department_id', $payload) && $payload['department_id'] !== null) {
                $this->syncDepartments(
                    $user,
                    [(int) $payload['department_id']],
                    (int) $payload['department_id'],
                    (string) ($payload['department_role'] ?? $user->department_role ?? 'member'),
                );
            }

            if (array_key_exists('sector_scopes', $payload)) {
                $this->syncSectorScopes($user, (array) $payload['sector_scopes']);
            } elseif (array_key_exists('org_level', $payload)) {
                $this->syncSectorScopes($user, $this->defaultSectorsForOrgLevel(
                    (string) $payload['org_level'],
                    $user->department_id,
                ));
            }

            $after = $this->orgSnapshot($user->fresh());
            if ($before !== $after) {
                OrgChartAudit::create([
                    'user_id' => $user->id,
                    'changed_by' => $actor?->id,
                    'before' => $before,
                    'after' => $after,
                    'change_type' => 'org_config_updated',
                ]);
            }

            return $user->fresh([
                'department',
                'departments',
                'sectorScopes',
                'reportsTo:id,name,email',
            ]);
        });
    }

    /** @param  list<int>  $departmentIds */
    public function syncDepartments(User $user, array $departmentIds, ?int $primaryDepartmentId, string $membershipRole = 'member'): void
    {
        $departmentIds = array_values(array_unique(array_filter($departmentIds)));

        if ($primaryDepartmentId !== null && ! in_array($primaryDepartmentId, $departmentIds, true)) {
            $departmentIds[] = $primaryDepartmentId;
        }

        $sync = [];
        foreach ($departmentIds as $departmentId) {
            $sync[$departmentId] = [
                'membership_role' => $membershipRole,
                'is_primary' => $primaryDepartmentId !== null && $departmentId === $primaryDepartmentId,
            ];
        }

        $user->departments()->sync($sync);

        if ($primaryDepartmentId !== null) {
            $user->forceFill(['department_id' => $primaryDepartmentId])->save();
        }
    }

    /** @param  list<string>  $sectors */
    public function syncSectorScopes(User $user, array $sectors): void
    {
        $sectors = array_values(array_unique(array_filter(array_map(
            fn ($s) => strtoupper(trim((string) $s)),
            $sectors,
        ))));

        UserSectorScope::query()->where('user_id', $user->id)->delete();

        foreach ($sectors as $sector) {
            UserSectorScope::create([
                'user_id' => $user->id,
                'sector' => $sector,
            ]);
        }
    }

    public function defaultDataScopeMode(string $orgLevel): string
    {
        if (in_array($orgLevel, config('departments.org_wide_org_levels', []), true)) {
            return 'org_wide';
        }

        if ($orgLevel === 'gap') {
            return 'deny_all';
        }

        return 'scoped';
    }

    /** @return list<string> */
    public function defaultSectorsForOrgLevel(string $orgLevel, ?int $departmentId): array
    {
        if (in_array($orgLevel, config('departments.org_wide_org_levels', []), true)) {
            return ['ALL'];
        }

        if ($departmentId === null) {
            return [];
        }

        $slug = Department::query()->whereKey($departmentId)->value('slug');
        $map = [
            'gt' => ['GT'],
            'mt_consumer_sales' => ['MT'],
            'kp' => ['KP'],
            'partner_brands' => ['GT', 'MT', 'KP'],
        ];

        return $map[$slug] ?? [];
    }

    /** @return array<string, mixed> */
    public function orgSnapshot(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $user->loadMissing(['departments', 'sectorScopes']);

        return [
            'org_level' => $user->org_level,
            'reports_to_user_id' => $user->reports_to_user_id,
            'product_type_scope' => $user->product_type_scope,
            'data_scope_mode' => $user->data_scope_mode,
            'department_id' => $user->department_id,
            'department_role' => $user->department_role,
            'department_ids' => $user->departments->pluck('id')->all(),
            'sector_scopes' => $user->sectorScopes->pluck('sector')->all(),
        ];
    }
}