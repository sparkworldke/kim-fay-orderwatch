/**
 * useInventoryByWarehouse
 *
 * Wraps the existing `useInventory` hook and adds client-side grouping by
 * `default_warehouse_id` and ABC/D band classification.
 *
 * Requirements: 1.1, 1.2, 1.3
 */

import { useMemo } from "react";
import { useInventory, type InventoryItem } from "@/hooks/useOperations";
import { deriveBand } from "@/utils/inventoryUtils";

// ─────────────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────────────

/** Band classification for an inventory item. */
export type BandLabel = "A" | "B" | "C" | "D" | "Unclassified";

/**
 * Extended inventory item that includes the new fields added in task 2.1.
 * These fields come from the backend but are not yet in the base `InventoryItem`
 * type — we extend it here so components can type-safely consume them.
 */
export type InventoryItemExtended = InventoryItem & {
  item_status: string | null;
  last_cost: string | null;
  average_cost: string | null;
  last_modified_at: string | null;
};

/** Params accepted by `useInventoryByWarehouse` — identical to `useInventory`. */
export type UseInventoryByWarehouseParams = Parameters<typeof useInventory>[0];

/**
 * Grouped inventory structure:
 *   Map<warehouseId, Map<BandLabel, InventoryItemExtended[]>>
 *
 * Items with a null / empty `default_warehouse_id` are placed under the
 * sentinel key `"UNKNOWN"`.
 */
export type GroupedInventory = Map<string, Map<BandLabel, InventoryItemExtended[]>>;

/** Return value of `useInventoryByWarehouse`. */
export type UseInventoryByWarehouseResult = {
  /** Items grouped by warehouse then band. Empty Map while loading. */
  groupedItems: GroupedInventory;
  /** Flat list of all items from the API response (convenience). */
  items: InventoryItemExtended[];
  isLoading: boolean;
  isFetching: boolean;
  isError: boolean;
  error: Error | null;
  /** Re-fetch from the server. */
  refetch: () => void;
  /** Pagination metadata from the API. */
  total: number;
  currentPage: number;
  lastPage: number;
  perPage: number;
};

// ─────────────────────────────────────────────────────────────────────────────
// Grouping helper (pure — also used in PBT)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Groups an array of `InventoryItemExtended` by `default_warehouse_id` then
 * by band.
 *
 * - Items with a null / empty `default_warehouse_id` go into the `"UNKNOWN"`
 *   warehouse group.
 * - Every item appears in exactly one group (exhaustive, non-overlapping).
 *
 * @requirements 1.2, 1.3
 */
export function groupByWarehouseAndBand(
  items: InventoryItemExtended[],
): GroupedInventory {
  const grouped: GroupedInventory = new Map();

  for (const item of items) {
    const warehouseKey =
      item.default_warehouse_id?.trim() || "UNKNOWN";

    if (!grouped.has(warehouseKey)) {
      grouped.set(warehouseKey, new Map());
    }

    const bandMap = grouped.get(warehouseKey)!;
    const band = deriveBand(item.item_class) as BandLabel;

    if (!bandMap.has(band)) {
      bandMap.set(band, []);
    }

    bandMap.get(band)!.push(item);
  }

  return grouped;
}

// ─────────────────────────────────────────────────────────────────────────────
// Hook
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Fetches inventory items and returns them grouped by warehouse and band.
 *
 * Reuses the `["operations-inventory", params]` query key so it shares the
 * cache with any other consumer of `useInventory` with the same params — no
 * duplicate network requests.
 *
 * @requirements 1.1, 1.2, 1.3
 */
export function useInventoryByWarehouse(
  params: UseInventoryByWarehouseParams,
): UseInventoryByWarehouseResult {
  const query = useInventory(params);

  const rawItems = query.data?.data ?? [];

  // Cast to extended type — the backend already returns these fields; we are
  // just making them visible to TypeScript consumers.
  const items = rawItems as InventoryItemExtended[];

  const groupedItems = useMemo(
    () => groupByWarehouseAndBand(items),
    [items],
  );

  return {
    groupedItems,
    items,
    isLoading: query.isLoading,
    isFetching: query.isFetching,
    isError: query.isError,
    error: query.error as Error | null,
    refetch: query.refetch,
    total: query.data?.total ?? 0,
    currentPage: query.data?.current_page ?? 1,
    lastPage: query.data?.last_page ?? 1,
    perPage: query.data?.per_page ?? 50,
  };
}
