/**
 * InventoryWarehouseView
 *
 * Replaces the flat inventory table with a warehouse-grouped, band-sub-grouped
 * view. Each warehouse is a collapsible section containing a WarehouseStatCard
 * (total SKU count, total qty_on_hand, count of SKUs with
 * days_until_stockout ≤ 14) and collapsible BandSubGroup sections (A, B, C, D,
 * Unclassified).
 *
 * Client-side filters:
 * - Search input (300ms debounce) matching inventory_id or description.
 * - Warehouse multi-select dropdown.
 *
 * States:
 * - Loading skeleton while `isLoading` is true.
 * - Empty state when zero SKUs remain after filters.
 *
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.11, 1.12
 */

import { useEffect, useMemo, useState } from "react";
import { Boxes, ChevronDown, ChevronRight, Package, Search, TrendingDown } from "lucide-react";
import {
  groupByWarehouseAndBand,
  type BandLabel,
  type InventoryItemExtended,
} from "@/hooks/useInventoryByWarehouse";
import type { InventoryItem } from "@/hooks/useOperations";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";

export type InventoryWarehouseViewProps = {
  items: InventoryItemExtended[];
  onSkuClick: (item: InventoryItem) => void;
  /** Show loading skeletons for the whole view. */
  isLoading?: boolean;
  /** Distinct warehouse ids available for the filter dropdown. */
  warehouseOptions?: string[];
};

const BAND_ORDER: BandLabel[] = ["A", "B", "C", "D", "Unclassified"];

const LOW_STOCKOUT_DAYS = 14;
const SEARCH_DEBOUNCE_MS = 300;

/** Threshold for "low days" stat card. */
function isLowDays(item: InventoryItemExtended): boolean {
  const d = item.prediction?.days_until_stockout;
  return typeof d === "number" && d <= LOW_STOCKOUT_DAYS;
}

// ─────────────────────────────────────────────────────────────────────────────
// WarehouseStatCard
// ─────────────────────────────────────────────────────────────────────────────

function WarehouseStatCard({ items }: { items: InventoryItemExtended[] }) {
  const totalSkuCount = items.length;
  const totalQtyOnHand = items.reduce(
    (sum, item) => sum + Number(item.qty_on_hand ?? 0),
    0,
  );
  const lowDaysCount = items.filter(isLowDays).length;

  return (
    <div className="grid grid-cols-3 gap-2 sm:max-w-md">
      <Stat label="Total SKUs" value={totalSkuCount.toLocaleString()} icon={Boxes} />
      <Stat label="Qty on hand" value={totalQtyOnHand.toLocaleString()} icon={Package} />
      <Stat
        label="Low days (≤14)"
        value={lowDaysCount.toLocaleString()}
        icon={TrendingDown}
        warn={lowDaysCount > 0}
      />
    </div>
  );
}

function Stat({
  label,
  value,
  icon: Icon,
  warn,
}: {
  label: string;
  value: string;
  icon?: React.ComponentType<{ className?: string }>;
  warn?: boolean;
}) {
  return (
    <div className="rounded-md border bg-card px-3 py-2">
      <div className="flex items-center gap-1 text-[10px] uppercase tracking-wide text-muted-foreground">
        {Icon && <Icon className="h-3 w-3" />}
        {label}
      </div>
      <p className={`mt-0.5 text-base font-semibold ${warn ? "text-amber-600" : ""}`}>{value}</p>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// BandSubGroup
// ─────────────────────────────────────────────────────────────────────────────

function BandSubGroup({
  band,
  items,
  onSkuClick,
}: {
  band: BandLabel;
  items: InventoryItemExtended[];
  onSkuClick: (item: InventoryItem) => void;
}) {
  return (
    <Collapsible defaultOpen={false} className="rounded-md border">
      <CollapsibleTrigger className="flex w-full items-center justify-between px-3 py-2 text-sm font-medium hover:bg-muted/30">
        <span className="flex items-center gap-2">
          <Badge variant="outline" className="font-mono">{band}</Badge>
          <span className="text-muted-foreground">{items.length} SKU{items.length === 1 ? "" : "s"}</span>
        </span>
        <ChevronDown className="h-4 w-4 text-muted-foreground" />
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className="divide-y border-t">
          {items.map((item) => (
            <button
              key={item.id ?? item.inventory_id}
              type="button"
              onClick={() => onSkuClick(item)}
              className="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              <div className="min-w-0 flex-1">
                <div className="font-medium">{item.inventory_id}</div>
                <div className="truncate text-xs text-muted-foreground">
                  {item.description ?? "—"}
                </div>
              </div>
              <div className="hidden text-right text-xs text-muted-foreground sm:block">
                {item.prediction?.days_until_stockout != null
                  ? `${item.prediction.days_until_stockout}d left`
                  : "—"}
              </div>
              <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
            </button>
          ))}
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// WarehouseSection
// ─────────────────────────────────────────────────────────────────────────────

function WarehouseSection({
  warehouseId,
  bandMap,
  onSkuClick,
}: {
  warehouseId: string;
  bandMap: Map<BandLabel, InventoryItemExtended[]>;
  onSkuClick: (item: InventoryItem) => void;
}) {
  const allItems = useMemo(
    () => BAND_ORDER.flatMap((band) => bandMap.get(band) ?? []),
    [bandMap],
  );

  return (
    <Collapsible defaultOpen className="rounded-lg border">
      <CollapsibleTrigger className="flex w-full items-center justify-between gap-3 px-4 py-3 hover:bg-muted/30">
        <span className="flex items-center gap-2">
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
          <span className="font-semibold">{warehouseId}</span>
          <Badge variant="secondary" className="font-normal">
            {allItems.length} SKU{allItems.length === 1 ? "" : "s"}
          </Badge>
        </span>
        <ChevronRight className="hidden h-0 w-0" />
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className="space-y-4 border-t p-4">
          <WarehouseStatCard items={allItems} />
          <div className="space-y-2">
            {BAND_ORDER.filter((band) => bandMap.has(band)).map((band) => (
              <BandSubGroup
                key={band}
                band={band}
                items={bandMap.get(band)!}
                onSkuClick={onSkuClick}
              />
            ))}
          </div>
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Main component
// ─────────────────────────────────────────────────────────────────────────────

export function InventoryWarehouseView({
  items,
  onSkuClick,
  isLoading = false,
  warehouseOptions,
}: InventoryWarehouseViewProps) {
  // Immediate input value + debounced applied value (300ms).
  const [searchInput, setSearchInput] = useState("");
  const [debouncedSearch, setDebouncedSearch] = useState("");
  const [selectedWarehouses, setSelectedWarehouses] = useState<string[]>([]);

  useEffect(() => {
    const handle = setTimeout(() => setDebouncedSearch(searchInput.trim()), SEARCH_DEBOUNCE_MS);
    return () => clearTimeout(handle);
  }, [searchInput]);

  const availableWarehouses = useMemo(() => {
    if (warehouseOptions && warehouseOptions.length > 0) return warehouseOptions;
    const set = new Set<string>();
    for (const item of items) {
      const wh = item.default_warehouse_id?.trim();
      if (wh) set.add(wh);
    }
    return Array.from(set).sort();
  }, [items, warehouseOptions]);

  function toggleWarehouse(warehouse: string) {
    setSelectedWarehouses((current) =>
      current.includes(warehouse)
        ? current.filter((w) => w !== warehouse)
        : [...current, warehouse],
    );
  }

  // Apply client-side search + warehouse filters.
  const filteredItems = useMemo(() => {
    const q = debouncedSearch.toLowerCase();
    return items.filter((item) => {
      if (
        selectedWarehouses.length > 0 &&
        !selectedWarehouses.includes((item.default_warehouse_id ?? "").trim())
      ) {
        return false;
      }
      if (q) {
        const id = (item.inventory_id ?? "").toLowerCase();
        const desc = (item.description ?? "").toLowerCase();
        if (!id.includes(q) && !desc.includes(q)) return false;
      }
      return true;
    });
  }, [items, debouncedSearch, selectedWarehouses]);

  const grouped = useMemo(
    () => groupByWarehouseAndBand(filteredItems as InventoryItemExtended[]),
    [filteredItems],
  );

  if (isLoading) {
    return (
      <div className="space-y-3" aria-busy="true">
        {Array.from({ length: 3 }).map((_, i) => (
          <Skeleton key={i} className="h-24 w-full rounded-lg" />
        ))}
      </div>
    );
  }

  return (
    <div className="space-y-4">
      {/* Client-side filters */}
      <div className="flex flex-wrap items-end gap-3">
        <div className="flex-1 min-w-[200px]">
          <Label htmlFor="inv-wh-search">Filter items</Label>
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              id="inv-wh-search"
              className="pl-8"
              placeholder="Item ID or description…"
              value={searchInput}
              onChange={(e) => setSearchInput(e.target.value)}
            />
          </div>
        </div>
        <div className="w-52">
          <Label>Warehouse</Label>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" className="w-full justify-between font-normal">
                <span className="truncate">
                  {selectedWarehouses.length === 0
                    ? "All warehouses"
                    : selectedWarehouses.length === 1
                      ? selectedWarehouses[0]
                      : `${selectedWarehouses.length} warehouses`}
                </span>
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              <DropdownMenuItem
                onSelect={(e) => { e.preventDefault(); setSelectedWarehouses([]); }}
                disabled={selectedWarehouses.length === 0}
              >
                All warehouses
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              {availableWarehouses.map((warehouse) => (
                <DropdownMenuCheckboxItem
                  key={warehouse}
                  checked={selectedWarehouses.includes(warehouse)}
                  onSelect={(e) => e.preventDefault()}
                  onCheckedChange={() => toggleWarehouse(warehouse)}
                >
                  {warehouse}
                </DropdownMenuCheckboxItem>
              ))}
              {availableWarehouses.length === 0 && (
                <DropdownMenuItem disabled>No warehouses</DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </div>

      {/* Grouped sections or empty state */}
      {grouped.size === 0 ? (
        <div className="rounded-lg border bg-muted/20 px-4 py-10 text-center text-sm text-muted-foreground">
          No inventory items found
          {debouncedSearch || selectedWarehouses.length > 0
            ? " matching the current filters."
            : " — run a sync to pull from Acumatica."}
        </div>
      ) : (
        <div className="space-y-3">
          {Array.from(grouped.entries()).map(([warehouseId, bandMap]) => (
            <WarehouseSection
              key={warehouseId}
              warehouseId={warehouseId}
              bandMap={bandMap}
              onSkuClick={onSkuClick}
            />
          ))}
        </div>
      )}
    </div>
  );
}
