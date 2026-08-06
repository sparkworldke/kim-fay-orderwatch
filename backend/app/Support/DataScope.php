<?php

namespace App\Support;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Services\Team\BrandAssignmentScope;
use App\Services\Team\CustomerAttributionService;
use App\Services\Team\KpReportingHierarchyService;
use App\Services\Team\OrgScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Unified data scoping — org chart, sector, customer, and subtree visibility.
 */
class DataScope
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyCustomerScope(Builder $query, ?User $user, string $idColumn = 'acumatica_id'): Builder
    {
        return app(OrgScopeService::class)->applyCustomerScope($query, $user, $idColumn);
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function applyOrderScope(Builder $query, ?User $user, string $repColumn = 'sales_consultant_rep_code', string $customerColumn = 'customer_acumatica_id'): Builder
    {
        app(OrgScopeService::class)->applyOrderScope($query, $user, $customerColumn);

        if ($query->getModel() instanceof AcumaticaSalesOrder) {
            $inventoryIds = app(BrandAssignmentScope::class)->inventoryIdsForUser($user);
            if ($inventoryIds !== null) {
                $query->whereHas('lines', fn (Builder $lines) => $inventoryIds === []
                    ? $lines->whereRaw('1 = 0')
                    : $lines->whereIn('inventory_id', $inventoryIds));
            }
        }

        return $query;
    }

    /** @return list<string>|null */
    public static function scopedInventoryIds(?User $user): ?array
    {
        return app(BrandAssignmentScope::class)->inventoryIdsForUser($user);
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

        // §7.3 precedence: the mapped-only gate overrides any broad access implied
        // by an ordinary secondary role, so it is evaluated before the org-wide
        // short-circuit below.
        $orgScope = app(OrgScopeService::class);
        if (app(KpReportingHierarchyService::class)->requiresPrivilegedKpOverlay($user)) {
            $query = AcumaticaCustomer::query()->select('acumatica_id');
            $orgScope->applyCustomerScope($query, $user);

            return $query->pluck('acumatica_id')->all();
        }

        if ($orgScope->hasOrgWideAccess($user)) {
            return null;
        }

        $attribution = app(CustomerAttributionService::class);
        if ($attribution->isMappedOnlyConsultant($user)) {
            return $attribution->directCustomerIds($user->id);
        }

        $attached = $orgScope->attachedPortfolioCustomerIds($user);
        if ($attached !== null) {
            return $attached;
        }

        if (! $orgScope->appliesTo($user)) {
            return null;
        }

        $query = AcumaticaCustomer::query()->select('acumatica_id');
        $orgScope->applyCustomerScope($query, $user);

        return $query->pluck('acumatica_id')->all();
    }
}
