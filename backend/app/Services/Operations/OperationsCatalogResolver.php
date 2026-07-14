<?php

namespace App\Services\Operations;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use Illuminate\Support\Collection;

/**
 * @phpstan-type InventoryStockRow array{
 *   qty_on_hand: string,
 *   qty_available: string|null,
 *   default_uom: string|null,
 *   synced_at: string|null,
 * }
 */

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
     * @param  list<string|null>  $inventoryIds
     * @return Collection<string, array{
     *   description: string|null,
     *   brand: string|null,
     *   posting_class: string|null,
     *   sub_trading_group: string|null,
     *   supplier: string|null
     * }>
     */
    public function classificationsForInventoryIds(array $inventoryIds): Collection
    {
        $ids = array_values(array_unique(array_filter($inventoryIds)));
        if ($ids === []) {
            return collect();
        }

        return AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $ids)
            ->get(['inventory_id', 'description', 'brand', 'posting_class', 'sub_trading_group', 'supplier'])
            ->keyBy('inventory_id')
            ->map(fn (AcumaticaInventoryItem $item) => [
                'description'       => $item->description,
                'brand'             => $item->brand,
                'posting_class'     => $item->posting_class,
                'sub_trading_group' => $item->sub_trading_group,
                'supplier'          => $item->supplier,
            ]);
    }

    /**
     * @param  Collection<string, array{
     *   description: string|null,
     *   brand: string|null,
     *   posting_class: string|null,
     *   sub_trading_group: string|null,
     *   supplier: string|null
     * }>  $classifications
     * @return array{
     *   brand: string|null,
     *   posting_class: string|null,
     *   sub_trading_group: string|null,
     *   supplier: string|null
     * }
     */
    public function classificationFieldsFor(?string $inventoryId, Collection $classifications): array
    {
        if ($inventoryId === null || ! $classifications->has($inventoryId)) {
            return [
                'brand'             => null,
                'posting_class'     => null,
                'sub_trading_group' => null,
                'supplier'          => null,
            ];
        }

        $row = $classifications->get($inventoryId);

        return [
            'brand'             => $row['brand'] ?? null,
            'posting_class'     => $row['posting_class'] ?? null,
            'sub_trading_group' => $row['sub_trading_group'] ?? null,
            'supplier'          => $row['supplier'] ?? null,
        ];
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

    /**
     * @param  list<string|null>  $inventoryIds
     * @return Collection<string, InventoryStockRow>
     */
    public function stockForInventoryIds(array $inventoryIds): Collection
    {
        $ids = array_values(array_unique(array_filter($inventoryIds)));
        if ($ids === []) {
            return collect();
        }

        return AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $ids)
            ->get(['inventory_id', 'qty_on_hand', 'qty_available', 'default_uom', 'synced_at'])
            ->keyBy('inventory_id')
            ->map(fn (AcumaticaInventoryItem $item) => [
                'qty_on_hand'   => (string) $item->qty_on_hand,
                'qty_available' => $item->qty_available !== null ? (string) $item->qty_available : null,
                'default_uom'   => $item->default_uom,
                'synced_at'     => $item->synced_at?->toIso8601String(),
            ]);
    }

    public function resolveUom(?string $lineUom, ?string $inventoryId, Collection $inventoryStock): ?string
    {
        if ($lineUom !== null && trim($lineUom) !== '') {
            return trim($lineUom);
        }

        if ($inventoryId !== null && $inventoryStock->has($inventoryId)) {
            $uom = $inventoryStock->get($inventoryId)['default_uom'] ?? null;

            return is_string($uom) && trim($uom) !== '' ? trim($uom) : null;
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