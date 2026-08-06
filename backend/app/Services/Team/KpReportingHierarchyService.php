<?php

namespace App\Services\Team;

use App\Models\CustomerData;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/** KP-only portfolio rollups without changing existing non-KP access tiers. */
class KpReportingHierarchyService
{
    public const VIGNESH_EMPLOYEE_NUMBER = 'P320';

    public const SUSAN_EMPLOYEE_NUMBER = 'P025';

    /** @var list<string> */
    public const TEAM_EMPLOYEE_NUMBERS = [
        'P022', 'P051', 'P096', 'P104', 'P163', 'P193', 'P201',
        'P317', 'P369', 'P460', 'P483', 'P489', 'P504', 'P505',
    ];

    public function __construct(
        private readonly OrgTreeService $orgTree,
        private readonly CustomerAttributionService $attribution,
    ) {}

    public function isVignesh(User $user): bool
    {
        return strtoupper(trim((string) $user->employee_number)) === self::VIGNESH_EMPLOYEE_NUMBER;
    }

    public function isSusan(User $user): bool
    {
        return strtoupper(trim((string) $user->employee_number)) === self::SUSAN_EMPLOYEE_NUMBER;
    }

    public function isTeamMember(User $user): bool
    {
        return in_array(strtoupper(trim((string) $user->employee_number)), self::TEAM_EMPLOYEE_NUMBERS, true);
    }

    public function isKpHierarchyUser(User $user): bool
    {
        return $this->isVignesh($user) || $this->isSusan($user) || $this->isTeamMember($user);
    }

    /** Susan is broadly privileged, so only her KP slice needs an additional portfolio gate. */
    public function requiresPrivilegedKpOverlay(User $user): bool
    {
        return $this->isSusan($user);
    }

    /** @return list<int> */
    public function visibleKpUserIds(User $viewer): array
    {
        if ($this->isVignesh($viewer) || $this->isSusan($viewer)) {
            return $this->orgTree->descendantIds($viewer->id, true);
        }

        return $this->isTeamMember($viewer) ? [$viewer->id] : [];
    }

    /** @return list<string> */
    public function visibleKpCustomerIds(User $viewer): array
    {
        $ids = collect($this->visibleKpUserIds($viewer))
            ->flatMap(fn (int $userId) => $this->attribution->directCustomerIds($userId))
            ->map(fn (mixed $id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return CustomerData::query()
            ->where('customer_group', 'Kim-Fay Professional')
            ->whereIn('customer_acumatica_id', $ids->all())
            ->pluck('customer_acumatica_id')
            ->map(fn (mixed $id) => (string) $id)
            ->all();
    }

    /** @return Collection<int, User> */
    public function filterVisibleSalesBooks(User $viewer, Collection $salesBooks): Collection
    {
        if (! $this->isKpHierarchyUser($viewer)) {
            return $salesBooks;
        }

        $kpEmployeeNumbers = [
            self::VIGNESH_EMPLOYEE_NUMBER,
            self::SUSAN_EMPLOYEE_NUMBER,
            ...self::TEAM_EMPLOYEE_NUMBERS,
        ];
        $visibleKpIds = $this->visibleKpUserIds($viewer);

        return $salesBooks->filter(function (User $candidate) use ($viewer, $kpEmployeeNumbers, $visibleKpIds): bool {
            if ($this->isTeamMember($viewer)) {
                return (int) $candidate->id === (int) $viewer->id;
            }

            $candidateIsKp = in_array(
                strtoupper(trim((string) $candidate->employee_number)),
                $kpEmployeeNumbers,
                true,
            );

            return ! $candidateIsKp || in_array((int) $candidate->id, $visibleKpIds, true);
        })->values();
    }

    /** @param Builder<Model> $query */
    public function applyPrivilegedKpOverlay(Builder $query, User $viewer, string $idColumn): Builder
    {
        $visibleKpIds = $this->visibleKpCustomerIds($viewer);
        $allKpIds = CustomerData::query()
            ->where('customer_group', 'Kim-Fay Professional')
            ->select('customer_acumatica_id');

        return $query->where(function (Builder $scope) use ($idColumn, $allKpIds, $visibleKpIds): void {
            $scope->whereNotIn($idColumn, $allKpIds);
            if ($visibleKpIds !== []) {
                $scope->orWhereIn($idColumn, $visibleKpIds);
            }
        });
    }
}
