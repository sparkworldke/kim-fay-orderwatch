<?php

namespace App\Services\Team;

use App\Models\AcumaticaCustomer;
use App\Models\CustomerData;
use App\Models\CustomerDepartmentOverride;
use App\Models\Department;
use App\Models\User;
use App\Models\UserCustomerAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class OrgScopeService
{
    public function __construct(
        private readonly OrgTreeService $orgTree,
        private readonly CustomerAttributionService $attribution,
        private readonly AccessTierService $accessTier,
        private readonly KpReportingHierarchyService $kpHierarchy,
    ) {}

    public function hasOrgWideAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->accessTier->hasUnrestrictedBusinessAccess($user)
            || $this->accessTier->hasCrossChannelBrandAccess($user);
    }

    public function appliesTo(?User $user): bool
    {
        if ($user === null || $this->hasOrgWideAccess($user)) {
            return false;
        }

        // Every non-unrestricted account is scoped. Users without a valid
        // department/sector/portfolio fall through to a deny-all clause.
        return true;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyCustomerScope(Builder $query, ?User $user, string $idColumn = 'acumatica_id'): Builder
    {
        if ($user === null) {
            return $query;
        }

        // An explicitly attached portfolio is the strongest visibility gate for
        // every non-super-admin role. Managers receive the de-duplicated union of
        // their own and their reportees' attached outlets; an org_wide legacy flag
        // must never broaden that set.
        $attached = $this->attachedPortfolioCustomerIds($user);
        if ($attached !== null) {
            return $attached === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn($idColumn, $attached);
        }

        // Susan keeps her existing broad non-KP access. Only the KP slice is
        // narrowed to the de-duplicated union of her own and her reportees' books.
        if ($this->kpHierarchy->requiresPrivilegedKpOverlay($user)) {
            return $this->kpHierarchy->applyPrivilegedKpOverlay($query, $user, $idColumn);
        }

        // §7.3 precedence: the mapped-only gate takes priority over any broad
        // access implied by an ordinary secondary role. A mapped-only consultant
        // always sees exactly their effective mapped customer set.
        if ($this->hasOrgWideAccess($user)
            && ! $this->kpHierarchy->requiresPrivilegedKpOverlay($user)) {
            return $query;
        }

        $mapped = $this->mappedOnlyCustomerIds($user);
        if ($mapped !== null) {
            return $mapped === []
                ? $query->whereRaw('1 = 0')
                : $query->whereIn($idColumn, $mapped);
        }

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
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyOrderScope(Builder $query, ?User $user, string $customerColumn = 'customer_acumatica_id'): Builder
    {
        if ($user === null) {
            return $query;
        }

        if ($this->hasOrgWideAccess($user)
            && ! $this->kpHierarchy->requiresPrivilegedKpOverlay($user)) {
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
        if ($user === null) {
            return true;
        }

        $attached = $this->attachedPortfolioCustomerIds($user);
        if ($attached !== null) {
            return in_array($customerId, $attached, true);
        }

        if ($this->kpHierarchy->requiresPrivilegedKpOverlay($user)) {
            $isKp = CustomerData::query()
                ->where('customer_acumatica_id', $customerId)
                ->where('customer_group', 'Kim-Fay Professional')
                ->exists();

            return ! $isKp || in_array($customerId, $this->kpHierarchy->visibleKpCustomerIds($user), true);
        }

        if ($this->hasOrgWideAccess($user)) {
            return true;
        }

        $mapped = $this->mappedOnlyCustomerIds($user);
        if ($mapped !== null) {
            return in_array($customerId, $mapped, true);
        }

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
     * Exact outlet portfolio attached to this viewer's reporting subtree.
     * Null means no attachment gate is configured and normal department scope
     * should be used. True super-admins retain their emergency org-wide view.
     *
     * @return list<string>|null
     */
    public function attachedPortfolioCustomerIds(User $user): ?array
    {
        if ($this->accessTier->hasUnrestrictedBusinessAccess($user)) {
            return null;
        }

        $userIds = $this->orgTree->descendantIds($user->id, true);
        $hasAttachments = UserCustomerAssignment::query()
            ->whereIn('user_id', $userIds)
            ->where(function (Builder $query) {
                $query->where('is_manual_override', true)
                    ->orWhereIn('assignment_type', [
                        UserCustomerAssignment::TYPE_SERVICING,
                        UserCustomerAssignment::TYPE_LEGACY_PRIMARY,
                    ]);
            })
            ->exists();

        return $hasAttachments
            ? $this->attribution->visibleCustomerIds($user->id)
            : null;
    }

    /**
     * §7.3 gate result for a viewer: null when the gate does not apply (the
     * viewer keeps their normal scope), otherwise the exact mapped customer IDs.
     *
     * @return list<string>|null
     */
    private function mappedOnlyCustomerIds(User $user): ?array
    {
        if (! $this->attribution->isMappedOnlyConsultant($user)) {
            return null;
        }

        return $this->attribution->directCustomerIds($user->id);
    }

    /** A user subject to the mapped-only consultant scoping path (canonical role or legacy flag). */
    private function isConsultant(User $user): bool
    {
        return $user->hasRole((string) config('attribution.sales_consultant_role'))
            || (bool) $user->is_consultant;
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applySingleUserCustomerScope(Builder $query, User $user, string $idColumn): void
    {
        // §7.3 mapped-only gate (applied per node, including descendants during a
        // manager rollup): a Sales Consultant is scoped to exactly their effective
        // mapped customer set. Historical SO rep-code matches are NEVER unioned in;
        // an unmapped rep alias is a reconciliation exception, not visible data.
        if ($this->isConsultant($user)) {
            $mappedIds = $this->attribution->directCustomerIds($user->id);

            if ($mappedIds === []) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn($idColumn, $mappedIds);

            return;
        }

        $assignedIds = $user->customerAssignments()->pluck('customer_acumatica_id');

        if ($assignedIds->isNotEmpty()) {
            $query->whereIn($idColumn, $assignedIds);

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
                $scoped->orWhere('customer_class', 'like', $prefix.'%');
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
