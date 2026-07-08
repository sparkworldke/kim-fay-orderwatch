<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesConsultantScope
{
    public const ROLE = 'Sales Consultant';

    public static function appliesTo(?User $user): bool
    {
        return $user !== null && $user->role === self::ROLE;
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
    public static function applyOrderScope(Builder $query, ?User $user, string $column = 'sales_consultant_rep_code'): Builder
    {
        if (! self::appliesTo($user)) {
            return $query;
        }

        $repCode = self::repCode($user);
        if ($repCode === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, $repCode);
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

        $repCode = self::repCode($user);
        if ($repCode === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('acumatica_id', self::customerIdsSubquery($repCode));
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

        $repCode = self::repCode($user);
        if ($repCode === null) {
            return false;
        }

        return \App\Models\AcumaticaSalesOrder::query()
            ->where('sales_consultant_rep_code', $repCode)
            ->where('customer_acumatica_id', $customerId)
            ->exists();
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
}