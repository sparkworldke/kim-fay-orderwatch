<?php

namespace App\Support;

use App\Models\User;
use App\Services\Team\AccessTierService;
use App\Services\Team\CustomerAttributionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesConsultantScope
{
    public const ROLE = 'Sales Consultant';

    public static function appliesTo(?User $user): bool
    {
        return app(AccessTierService::class)->isExclusivelySalesConsultant($user);
    }

    public static function repCode(?User $user): ?string
    {
        if (! self::appliesTo($user)) {
            return null;
        }

        $code = strtoupper(trim((string) ($user->rep_code ?? '')));

        return $code !== '' ? $code : null;
    }

    public static function repCodeFromRequest(Request $request): ?string
    {
        return self::repCode($request->user());
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyOrderScope(Builder $query, ?User $user, string $column = 'customer_acumatica_id'): Builder
    {
        if (! self::appliesTo($user)) {
            return $query;
        }

        // §7.8: a transaction is visible only when its customer_acumatica_id is
        // in the effective mapped set, never by rep_code alone.
        $mappedIds = self::attribution()->directCustomerIds($user->id);

        if ($mappedIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $mappedIds);
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    public static function applyCustomerScope(Builder $query, ?User $user): Builder
    {
        if (! self::appliesTo($user)) {
            return $query;
        }

        // §7.3 mapped-only: exact mapped customer set, never rep-code unions.
        $mappedIds = self::attribution()->directCustomerIds($user->id);

        return $mappedIds === []
            ? $query->whereRaw('1 = 0')
            : $query->whereIn('acumatica_id', $mappedIds);
    }

    public static function customerIdsSubquery(string $repCode): \Closure
    {
        return function ($sub) use ($repCode) {
            $sub->select('customer_acumatica_id')
                ->from('acumatica_sales_orders')
                ->where('sales_consultant_rep_code', $repCode)
                ->whereNotNull('customer_acumatica_id')
                ->distinct();
        };
    }

    public static function customerHasOrders(?User $user, string $customerId): bool
    {
        if (! self::appliesTo($user)) {
            return true;
        }

        // §7.3: access is gated by mapped membership, not rep-code order history.
        return in_array($customerId, self::attribution()->directCustomerIds($user->id), true);
    }

    public static function denyUnlessCustomerAccessible(?User $user, string $customerId): ?JsonResponse
    {
        if (! self::customerHasOrders($user, $customerId)) {
            return response()->json(['message' => 'Customer not found.'], 404);
        }

        return null;
    }

    public static function orderBelongsToUser(?User $user, ?string $orderRepCode): bool
    {
        if (! self::appliesTo($user)) {
            return true;
        }

        $repCode = self::repCode($user);
        if ($repCode === null) {
            return false;
        }

        return strtoupper(trim((string) ($orderRepCode ?? ''))) === $repCode;
    }

    private static function attribution(): CustomerAttributionService
    {
        return app(CustomerAttributionService::class);
    }
}
