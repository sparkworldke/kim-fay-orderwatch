<?php

namespace App\Support;

use App\Models\AcumaticaCustomer;
use App\Models\CustomerDepartmentOverride;
use App\Models\Department;
use App\Models\User;
use App\Services\Team\DepartmentResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class DepartmentScope
{
    public static function hasOrgWideAccess(?User $user): bool
    {
        return app(\App\Services\Team\OrgScopeService::class)->hasOrgWideAccess($user);
    }

    public static function appliesTo(?User $user): bool
    {
        if ($user === null || self::hasOrgWideAccess($user)) {
            return false;
        }

        return $user->department_id !== null;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyCustomerScope(Builder $query, ?User $user, string $idColumn = 'acumatica_id'): Builder
    {
        if (! self::appliesTo($user)) {
            return $query;
        }

        $departmentId = (int) $user->department_id;
        $overrideIds = CustomerDepartmentOverride::query()
            ->where('department_id', $departmentId)
            ->pluck('customer_acumatica_id');

        $prefixes = self::prefixesForDepartment($departmentId);

        return $query->where(function (Builder $scoped) use ($idColumn, $overrideIds, $prefixes) {
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

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyOrderScope(Builder $query, ?User $user, string $customerColumn = 'customer_acumatica_id'): Builder
    {
        if (! self::appliesTo($user)) {
            return $query;
        }

        $customerIds = AcumaticaCustomer::query()
            ->select('acumatica_id');
        $customerIds = self::applyCustomerScope($customerIds, $user);

        return $query->whereIn($customerColumn, $customerIds);
    }

    public static function customerAccessible(?User $user, string $customerId, ?string $customerClass = null): bool
    {
        return app(\App\Services\Team\OrgScopeService::class)
            ->customerAccessible($user, $customerId, $customerClass);
    }

    public static function denyUnlessCustomerAccessible(?User $user, string $customerId, ?string $customerClass = null): ?JsonResponse
    {
        if (! self::customerAccessible($user, $customerId, $customerClass)) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        return null;
    }

    /** @return list<string> */
    private static function prefixesForDepartment(int $departmentId): array
    {
        $slug = Department::query()->whereKey($departmentId)->value('slug');
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