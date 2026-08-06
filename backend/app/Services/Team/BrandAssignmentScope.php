<?php

namespace App\Services\Team;

use App\Models\AcumaticaInventoryItem;
use App\Models\Brand;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class BrandAssignmentScope
{
    public function __construct(
        private readonly AccessTierService $accessTier,
    ) {}

    public function appliesTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        // Partner Brands has cross-channel customer visibility, but product data
        // must still be capped to assigned trading brands. Only true platform
        // executives/administrators bypass that product ceiling.
        if ($user->is_super_admin
            || $user->role === 'Administrator'
            || $user->role === 'Executive'
            || in_array((string) $user->org_level, ['executive', 'c_suite'], true)) {
            return false;
        }

        if (in_array((string) $user->org_level, ['brandsops'], true)) {
            return true;
        }

        if ($user->brandAssignments()->exists()) {
            return true;
        }

        return $this->accessTier->hasCrossChannelBrandAccess($user);
    }

    /** @return list<string>|null Null = no enforced brand ceiling */
    public function allowedBrands(?User $user): ?array
    {
        if ($user === null || ! $this->appliesTo($user)) {
            return null;
        }

        if ($user->department_role === 'hod' && $this->accessTier->hasCrossChannelBrandAccess($user)) {
            return Brand::query()
                ->where('ownership', 'partner')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        $assigned = $user->brandAssignments()->pluck('brand')->all();

        // Partner Brands is customer/open-channel access plus a dynamic product
        // brand layer. Until a member receives an explicit allocation, keep the
        // partner-brand layer open instead of tying visibility to customer books.
        if ($assigned === [] && $this->accessTier->hasCrossChannelBrandAccess($user)) {
            return Brand::query()
                ->where('ownership', 'partner')
                ->where('is_active', true)
                ->orderBy('name')
                ->pluck('name')
                ->all();
        }

        return $assigned;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function applyInventoryScope(Builder $query, ?User $user, string $brandColumn = 'brand'): Builder
    {
        if (! $this->appliesTo($user)) {
            return $query;
        }

        if ($user->product_type_scope === 'trading') {
            $query->where('product_type', 'trading');
        } elseif ($user->product_type_scope === 'manufactured') {
            $query->where('product_type', 'manufactured');
        }

        $brands = $this->allowedBrands($user);
        if ($brands === []) {
            $query->whereRaw('1 = 0');
        } elseif ($brands !== null) {
            $query->whereIn($brandColumn, $brands);
        }

        return $query;
    }

    /** @return list<string>|null Inventory IDs visible to user; null = unrestricted; [] = nothing */
    public function inventoryIdsForUser(?User $user): ?array
    {
        if (! $this->appliesTo($user)) {
            return null;
        }

        $query = AcumaticaInventoryItem::query()->select('inventory_id');
        $this->applyInventoryScope($query, $user);

        return $query->pluck('inventory_id')->all();
    }

    /**
     * Intersect optional UI brand filter inventory IDs with user assignment ceiling.
     *
     * @param  list<string>|null  $uiFilterIds
     * @return list<string>|null
     */
    public function intersectInventoryIds(?User $user, ?array $uiFilterIds): ?array
    {
        $userIds = $this->inventoryIdsForUser($user);

        if ($userIds === null) {
            return $uiFilterIds;
        }

        if ($uiFilterIds === null) {
            return $userIds;
        }

        return array_values(array_intersect($uiFilterIds, $userIds));
    }
}
