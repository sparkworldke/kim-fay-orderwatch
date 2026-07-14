<?php

namespace App\Services\Team;

use App\Models\AcumaticaInventoryItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class BrandAssignmentScope
{
    public function __construct(
        private readonly OrgScopeService $orgScope,
    ) {}

    public function appliesTo(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (in_array((string) $user->org_level, ['brandsops'], true)) {
            return true;
        }

        if ($user->brandAssignments()->exists()) {
            return true;
        }

        return in_array((string) $user->org_level, ['hod'], true)
            && $user->product_type_scope === 'trading';
    }

    /** @return list<string>|null Null = no enforced brand ceiling */
    public function allowedBrands(?User $user): ?array
    {
        if ($user === null || ! $this->appliesTo($user)) {
            return null;
        }

        $assigned = $user->brandAssignments()->pluck('brand')->all();

        return $assigned !== [] ? $assigned : null;
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<\Illuminate\Database\Eloquent\Model>
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
        if ($brands !== null) {
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