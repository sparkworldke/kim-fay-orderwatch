<?php

namespace App\Services\Team;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\CustomerDepartmentOverride;
use App\Models\Department;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use App\Support\SalesConsultantScope;
use Illuminate\Database\Eloquent\Builder;

class OrgScopeService
{
    public function __construct(
        private readonly OrgTreeService $orgTree,
    ) {}

    public function hasOrgWideAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        if (in_array((string) $user->role, config('departments.executive_roles', []), true)) {
            return true;
        }

        if ($user->data_scope_mode === 'org_wide') {
            return true;
        }

        if (in_array((string) $user->org_level, config('departments.org_wide_org_levels', []), true)) {
            return true;
        }

        if ($user->department_role === 'executive') {
            return true;
        }

        if ($user->department_id === null) {
            return true;
        }

        $department = Department::query()->find($user->department_id);

        return $department === null || ! $department->is_customer_facing;
    }

    public function appliesTo(?User $user): bool
    {
        if ($user === null || $this->hasOrgWideAccess($user)) {
            return false;
        }

        if ($user->data_scope_mode === 'deny_all' || $user->org_level === 'gap') {
            return true;
        }

        return $user->department_id !== null
            || $user->sectorScopes()->exists()
            || $user->customerAssignments()->exists()
            || SalesConsultantScope::appliesTo($user);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyCustomerScope(Builder $query, ?User $user, string $idColumn = 'acumatica_id'): Builder
    {
        if (! $this->appliesTo($user)) {
            return $query;
        }

        if ($user->data_scope_mode === 'deny_all' || $user->org_level === 'gap') {
            return $query->whereRaw('1 = 0');
        }

        $userIds = $this->effectiveScopeUserIds($user);

        return $query->where(function (Builder $scoped) use ($userIds, $idColumn) {
            $hasClause = false;

            foreach ($userIds as $scopeUserId) {
                $scopeUser = User::query()->find($scopeUserId);
                if ($scopeUser === null) {
                    continue;
                }

                $scoped->orWhere(function (Builder $inner) use ($scopeUser, $idColumn) {
                    $this->applySingleUserCustomerScope($inner, $scopeUser, $idColumn);
                });
                $hasClause = true;
            }

            if (! $hasClause) {
                $scoped->whereRaw('1 = 0');
            }
        });
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public function applyOrderScope(Builder $query, ?User $user, string $customerColumn = 'customer_acumatica_id'): Builder
    {
        if (! $this->appliesTo($user)) {
            return $query;
        }

        if ($user->data_scope_mode === 'deny_all' || $user->org_level === 'gap') {
            return $query->whereRaw('1 = 0');
        }

        $customerIds = AcumaticaCustomer::query()->select('acumatica_id');
        $this->applyCustomerScope($customerIds, $user);

        return $query->whereIn($customerColumn, $customerIds);
    }

    public function customerAccessible(?User $user, string $customerId, ?string $customerClass = null): bool
    {
        if (! $this->appliesTo($user)) {
            return true;
        }

        if ($user->data_scope_mode === 'deny_all' || $user->org_level === 'gap') {
            return false;
        }

        foreach ($this->effectiveScopeUserIds($user) as $scopeUserId) {
            $scopeUser = User::query()->find($scopeUserId);
            if ($scopeUser !== null && $this->singleUserCanAccessCustomer($scopeUser, $customerId, $customerClass)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public function effectiveScopeUserIds(User $user): array
    {
        $levels = config('departments.org_levels_with_subtree_visibility', ['executive', 'c_suite', 'hod']);

        if (in_array((string) $user->org_level, $levels, true)
            || in_array((string) $user->department_role, ['hod', 'executive'], true)) {
            return $this->orgTree->descendantIds($user->id, true);
        }

        return [$user->id];
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applySingleUserCustomerScope(Builder $query, User $user, string $idColumn): void
    {
        $assignedIds = $user->customerAssignments()->pluck('customer_acumatica_id');

        if ($assignedIds->isNotEmpty()) {
            $query->whereIn($idColumn, $assignedIds);

            return;
        }

        if (SalesConsultantScope::appliesTo($user) || $user->is_consultant) {
            $repCode = strtoupper(trim((string) ($user->rep_code ?? '')));
            if ($repCode === '') {
                $repCode = $user->acumaticaRepMappings()
                    ->where('is_primary', true)
                    ->value('acumatica_rep_code');
                $repCode = $repCode ? strtoupper(trim($repCode)) : '';
            }

            if ($repCode !== '') {
                $query->whereIn($idColumn, function ($sub) use ($repCode) {
                    $sub->select('customer_acumatica_id')
                        ->from('acumatica_sales_orders')
                        ->where('sales_consultant_rep_code', $repCode)
                        ->whereNotNull('customer_acumatica_id')
                        ->distinct();
                });

                return;
            }

            $query->whereRaw('1 = 0');

            return;
        }

        $sectors = $user->sectorScopes()->pluck('sector')->all();
        if (in_array('ALL', $sectors, true)) {
            return;
        }

        $departmentId = $user->department_id;
        if ($departmentId === null && $sectors === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $overrideIds = $departmentId !== null
            ? CustomerDepartmentOverride::query()
                ->where('department_id', $departmentId)
                ->pluck('customer_acumatica_id')
            : collect();

        $prefixes = $this->prefixesForUser($user, $sectors);

        $query->where(function (Builder $scoped) use ($idColumn, $overrideIds, $prefixes) {
            if ($overrideIds->isNotEmpty()) {
                $scoped->orWhereIn($idColumn, $overrideIds);
            }

            foreach ($prefixes as $prefix) {
                $scoped->orWhere('customer_class', 'like', $prefix . '%');
            }

            if ($overrideIds->isEmpty() && $prefixes === []) {
                $scoped->whereRaw('1 = 0');
            }
        });
    }

    private function singleUserCanAccessCustomer(User $user, string $customerId, ?string $customerClass): bool
    {
        $query = AcumaticaCustomer::query()->where('acumatica_id', $customerId);
        $this->applySingleUserCustomerScope($query, $user, 'acumatica_id');

        return $query->exists();
    }

    /** @return list<string> */
    private function prefixesForUser(User $user, array $sectors): array
    {
        if ($sectors !== []) {
            return array_values(array_filter(array_map(
                fn (string $sector) => $sector === 'ALL' ? null : strtoupper($sector),
                $sectors,
            )));
        }

        if ($user->department_id === null) {
            return [];
        }

        $slug = Department::query()->whereKey($user->department_id)->value('slug');
        if ($slug === null) {
            return [];
        }

        $prefixes = [];
        foreach (config('departments.class_prefix_map', []) as $prefix => $mappedSlug) {
            if ($mappedSlug === $slug) {
                $prefixes[] = strtoupper($prefix);
            }
        }

        return $prefixes;
    }
}