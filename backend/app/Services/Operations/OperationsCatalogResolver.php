<?php

namespace App\Services\Operations;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use Illuminate\Support\Collection;

class OperationsCatalogResolver
{
    /**
     * @param  list<string|null>  $inventoryIds
     * @return Collection<string, string|null>
     */
    public function descriptionsForInventoryIds(array $inventoryIds): Collection
    {
        $ids = array_values(array_unique(array_filter($inventoryIds)));
        if ($ids === []) {
            return collect();
        }

        return AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $ids)
            ->pluck('description', 'inventory_id');
    }

    /**
     * @param  list<string|null>  $customerIds
     * @return Collection<string, string|null>
     */
    public function namesForCustomerIds(array $customerIds): Collection
    {
        $ids = array_values(array_unique(array_filter($customerIds)));
        if ($ids === []) {
            return collect();
        }

        return AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $ids)
            ->pluck('name', 'acumatica_id');
    }

    public function resolveProductName(
        ?string $inventoryId,
        ?string $lineDescription,
        Collection $inventoryDescriptions,
    ): ?string {
        if ($inventoryId !== null && $inventoryDescriptions->has($inventoryId)) {
            $description = $inventoryDescriptions->get($inventoryId);
            if (is_string($description) && trim($description) !== '') {
                return trim($description);
            }
        }

        if ($lineDescription !== null && trim($lineDescription) !== '') {
            return trim($lineDescription);
        }

        return null;
    }

    public function resolveCustomerName(
        ?string $storedName,
        ?string $customerId,
        Collection $customerNames,
    ): ?string {
        if ($storedName !== null && trim($storedName) !== '') {
            return trim($storedName);
        }

        if ($customerId !== null && $customerNames->has($customerId)) {
            $name = $customerNames->get($customerId);

            return is_string($name) && trim($name) !== '' ? trim($name) : $customerId;
        }

        return $customerId;
    }
}