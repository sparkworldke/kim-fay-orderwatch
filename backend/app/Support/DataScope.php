<?php

namespace App\Support;

use App\Models\AcumaticaCustomer;
use App\Models\User;
use App\Services\Team\OrgScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

/**
 * Unified data scoping — org chart, sector, customer, and subtree visibility.
 */
class DataScope
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyCustomerScope(Builder $query, ?User $user, string $idColumn = 'acumatica_id'): Builder
    {
        return app(OrgScopeService::class)->applyCustomerScope($query, $user, $idColumn);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyOrderScope(Builder $query, ?User $user, string $repColumn = 'sales_consultant_rep_code', string $customerColumn = 'customer_acumatica_id'): Builder
    {
        return app(OrgScopeService::class)->applyOrderScope($query, $user, $customerColumn);
    }

    public static function customerAccessible(?User $user, string $customerId, ?string $customerClass = null): bool
    {
        return app(OrgScopeService::class)->customerAccessible($user, $customerId, $customerClass);
    }

    public static function denyUnlessCustomerAccessible(?User $user, string $customerId, ?string $customerClass = null): ?JsonResponse
    {
        if (! self::customerAccessible($user, $customerId, $customerClass)) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        return null;
    }

    public static function orderBelongsToUser(?User $user, ?string $orderRepCode, ?string $customerId = null, ?string $customerClass = null): bool
    {
        if ($customerId !== null && ! self::customerAccessible($user, $customerId, $customerClass)) {
            return false;
        }

        if (! SalesConsultantScope::appliesTo($user)) {
            return true;
        }

        return SalesConsultantScope::orderBelongsToUser($user, $orderRepCode);
    }

    /**
     * Acumatica customer IDs visible to the user, or null when org-wide access applies.
     *
     * @return list<string>|null
     */
    public static function scopedCustomerAcumaticaIds(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        $orgScope = app(OrgScopeService::class);

        if (! $orgScope->appliesTo($user)) {
            return null;
        }

        $query = AcumaticaCustomer::query()->select('acumatica_id');
        $orgScope->applyCustomerScope($query, $user);

        return $query->pluck('acumatica_id')->all();
    }
}