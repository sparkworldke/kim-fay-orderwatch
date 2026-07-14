/**
 * InventoryWarehouseView
 *
 * Two-panel inventory layout matching the run-rate design:
 *
 * 1. Warehouse Summary Table — a clickable table listing every warehouse with
 *    its total SKU count. Clicking a row selects that warehouse so the detail
 *    table below drills into it.
 *
 * 2. Inventory Detail Table — a server-paginated data table showing every SKU
 *    for the selected warehouse (or all warehouses when none is highlighted).
 *    Columns: Product, Brand, Warehouse, Stock, UOM, Run rate / day, Days left, Status.
 *
 * The component is stateless with respect to data fetching — the parent owns
 * the query, filters, and pagination. It receives the current page of items
 * plus the warehouse summary counts and renders both tables.
 */

import { useMemo } from "react";
import { ArrowLeft, Boxes, ChevronRight, Search, TrendingDown } from "lucide-react";
import type { InventoryItemExtended } from "@/hooks/useInventoryByWarehouse";
import type { InventoryItem } from "@/hooks/useOperations";
import {
  InventoryBrandColumnCell,
  InventoryProductColumnCell,
} from "@/components/inventory/InventorySkuTableCells";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { predictionStatusLabel } from "@/hooks/useOperations";

export type WarehouseCount = {
  warehouse_id: string;
  label?: string;
  sku_count: number;
  configured?: boolean;
};

export type InventoryWarehouseViewProps = {
  /** Current page of inventory items for the detail table. */
  items: InventoryItemExtended[];
  onSkuClick: (item: InventoryItem) => void;
  /** Show loading skeletons for the detail table. */
  isLoading?: boolean;
  /** Per-warehouse SKU counts from the summary endpoint. */
  warehouseCounts?: WarehouseCount[];
  /** Currently highlighted warehouse (null = all warehouses). */
  selectedWarehouse: string | null;
  /** Called when the user clicks a warehouse row. */
  onWarehouseSelect: (warehouse: string | null) => void;
  /** Client-side search value for the detail table filter. */
  searchInput: string;
  onSearchChange: (value: string) => void;
  /** Stockout tab: tighten empty-state copy and emphasize risk columns. */
  stockoutMode?: boolean;
};

const LOW_STOCKOUT_DAYS = 14;

function isLowDays(item: InventoryItemExtended): boolean {
  const d = item.prediction?.days_until_stockout;
  return typeof d === "number" && d <= LOW_STOCKOUT_DAYS;
}

function statusBadgeClass(status: string | null | undefined): string {
  switch (status) {
    case "critical":
      return "bg-red-100 text-red-700 border-red-200";
    case "at_risk":
      return "bg-amber-100 text-amber-700 border-amber-200";
    case "healthy":
      return "bg-green-100 text-green-700 border-green-200";
    default:
      return "";
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Warehouse Summary Table
// ─────────────────────────────────────────────────────────────────────────────

function WarehouseSummaryTable({
  warehouseCounts,
  selectedWarehouse,
  onWarehouseSelect,
  isLoading,
}: {
  warehouseCounts: WarehouseCount[];
  selectedWarehouse: string | null;
  onWarehouseSelect: (w: string | null) => void;
  isLoading: boolean;
}) {
  const totalSkus = useMemo(
    () => warehouseCounts.reduce((sum, w) => sum + w.sku_count, 0),
    [warehouseCounts],
  );

  return (
    <div className="rounded-lg border">
      <div className="flex items-center justify-between border-b px-4 py-3">
        <div className="flex items-center gap-2">
          <Boxes className="h-4 w-4 text-muted-foreground" />
          <h3 className="text-sm font-semibold">Warehouses</h3>
        </div>
        <span className="text-xs text-muted-foreground">
          {warehouseCounts.length} warehouse{warehouseCounts.length === 1 ? "" : "s"} ·{" "}
          {totalSkus.toLocaleString()} total SKUs
        </span>
      </div>

      {isLoading ? (
        <div className="space-y-2 p-4">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-10 w-full" />
          ))}
        </div>
      ) : (
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="w-[60%]">Warehouse</TableHead>
              <TableHead className="text-right">SKUs</TableHead>
              <TableHead className="w-8" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {/* "All warehouses" row */}
            <TableRow
              className={`cursor-pointer ${selectedWarehouse === null ? "bg-muted/50" : ""}`}
              onClick={() => onWarehouseSelect(null)}
            >
              <TableCell className="font-medium">All warehouses</TableCell>
              <TableCell className="text-right tabular-nums text-muted-foreground">
                {totalSkus.toLocaleString()}
              </TableCell>
              <TableCell>
                {selectedWarehouse === null && (
                  <ChevronRight className="h-4 w-4 text-muted-foreground" />
                )}
              </TableCell>
            </TableRow>

            {warehouseCounts.map((wh) => (
              <TableRow
                key={wh.warehouse_id}
                className={`cursor-pointer ${selectedWarehouse === wh.warehouse_id ? "bg-muted/50" : ""}`}
                onClick={() => onWarehouseSelect(wh.warehouse_id)}
              >
                <TableCell className="font-medium">
                  {wh.label?.trim() || wh.warehouse_id}
                  {wh.label && wh.label !== wh.warehouse_id && (
                    <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                      ({wh.warehouse_id})
                    </span>
                  )}
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  {wh.sku_count.toLocaleString()} SKU{wh.sku_count === 1 ? "" : "s"}
                </TableCell>
                <TableCell>
                  {selectedWarehouse === wh.warehouse_id && (
                    <ChevronRight className="h-4 w-4 text-muted-foreground" />
                  )}
                </TableCell>
              </TableRow>
            ))}

            {warehouseCounts.length === 0 && (
              <TableRow>
                <TableCell colSpan={3} className="py-8 text-center text-sm text-muted-foreground">
                  No warehouses yet — run a sync to pull inventory from Acumatica.
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      )}
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Inventory Detail Table
// ─────────────────────────────────────────────────────────────────────────────

function InventoryDetailTable({
  items,
  onSkuClick,
  isLoading,
  stockoutMode = false,
}: {
  items: InventoryItemExtended[];
  onSkuClick: (item: InventoryItem) => void;
  isLoading: boolean;
  stockoutMode?: boolean;
}) {
  if (isLoading) {
    return (
      <div className="space-y-2" aria-busy="true">
        {Array.from({ length: 8 }).map((_, i) => (
          <Skeleton key={i} className="h-12 w-full" />
        ))}
      </div>
    );
  }

  if (items.length === 0) {
    return (
      <div className="rounded-lg border bg-muted/20 px-4 py-10 text-center text-sm text-muted-foreground">
        {stockoutMode
          ? "No critical or out-of-stock items for this warehouse and filter."
          : "No inventory items found matching the current filters."}
      </div>
    );
  }

  return (
    <div className="overflow-x-auto rounded-lg border">
      <Table className="min-w-[720px]">
        <TableHeader>
          <TableRow>
            <TableHead className="min-w-[200px]">Product</TableHead>
            <TableHead className="min-w-[140px]">Brand</TableHead>
            <TableHead className="hidden min-w-[100px] md:table-cell">Warehouse</TableHead>
            <TableHead className="min-w-[72px] text-right">Stock</TableHead>
            <TableHead className="hidden min-w-[64px] sm:table-cell">UOM</TableHead>
            <TableHead className="min-w-[88px] text-right">Run rate / day</TableHead>
            <TableHead className="min-w-[72px] text-right">Days left</TableHead>
            <TableHead className="min-w-[88px]">Status</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {items.map((item) => {
            const pred = item.prediction;
            const lowDays = isLowDays(item);
            const qty = Number(item.qty_on_hand ?? 0);
            const isZero = qty <= 0;

            return (
              <TableRow
                key={item.id ?? item.inventory_id}
                className="cursor-pointer align-top"
                onClick={() => onSkuClick(item)}
              >
                <TableCell className="max-w-[280px] py-3">
                  <InventoryProductColumnCell item={item} />
                </TableCell>
                <TableCell className="max-w-[180px] py-3">
                  <InventoryBrandColumnCell item={item} />
                </TableCell>
                <TableCell className="hidden py-3 text-sm md:table-cell">
                  {item.default_warehouse_id?.trim() || "—"}
                </TableCell>
                <TableCell
                  className={`py-3 text-right tabular-nums text-sm font-medium ${
                    isZero ? "text-red-600" : ""
                  }`}
                >
                  {qty.toLocaleString()}
                  {isZero && stockoutMode && (
                    <span className="ml-1 text-[10px] font-semibold uppercase tracking-wide">
                      OOS
                    </span>
                  )}
                </TableCell>
                <TableCell className="hidden py-3 text-sm text-muted-foreground sm:table-cell">
                  {item.default_uom?.trim() || "—"}
                </TableCell>
                <TableCell className="py-3 text-right tabular-nums text-sm text-muted-foreground">
                  {pred?.daily_run_rate != null
                    ? Number(pred.daily_run_rate).toLocaleString(undefined, { maximumFractionDigits: 1 })
                    : "—"}
                </TableCell>
                <TableCell
                  className={`py-3 text-right tabular-nums text-sm ${
                    isZero
                      ? "font-semibold text-red-600"
                      : lowDays
                        ? "font-semibold text-amber-600"
                        : ""
                  }`}
                >
                  {isZero
                    ? "0d"
                    : pred?.days_until_stockout != null
                      ? `${pred.days_until_stockout}d`
                      : "—"}
                </TableCell>
                <TableCell className="py-3">
                  {isZero && stockoutMode ? (
                    <Badge variant="outline" className="bg-red-100 text-red-700 border-red-200">
                      No stock
                    </Badge>
                  ) : pred?.prediction_status ? (
                    <Badge variant="outline" className={statusBadgeClass(pred.prediction_status)}>
                      {predictionStatusLabel(pred.prediction_status)}
                    </Badge>
                  ) : (
                    <span className="text-xs text-muted-foreground">—</span>
                  )}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </div>
  );
}

// ─────────────────────────────────────────────────────────────────────────────
// Main component
// ─────────────────────────────────────────────────────────────────────────────

export function InventoryWarehouseView({
  items,
  onSkuClick,
  isLoading = false,
  warehouseCounts,
  selectedWarehouse,
  onWarehouseSelect,
  searchInput,
  onSearchChange,
  stockoutMode = false,
}: InventoryWarehouseViewProps) {
  const selectedLabel =
    warehouseCounts?.find((w) => w.warehouse_id === selectedWarehouse)?.label
    ?? selectedWarehouse;

  return (
    <div className="space-y-6">
      {/* ── Warehouse Summary Table (hidden on stockout tab — chips used instead) ── */}
      {!stockoutMode && (
        <WarehouseSummaryTable
          warehouseCounts={warehouseCounts ?? []}
          selectedWarehouse={selectedWarehouse}
          onWarehouseSelect={onWarehouseSelect}
          isLoading={isLoading && !warehouseCounts}
        />
      )}

      {/* ── Detail table header + search ────────────────────────────────── */}
      <div className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-3">
            {selectedWarehouse && !stockoutMode && (
              <Button
                variant="ghost"
                size="sm"
                onClick={() => onWarehouseSelect(null)}
              >
                <ArrowLeft className="mr-1 h-4 w-4" />
                All warehouses
              </Button>
            )}
            <h3 className="text-sm font-semibold">
              {stockoutMode
                ? selectedWarehouse
                  ? `${selectedLabel} — Stockout risk`
                  : "Stockout risk — all warehouses"
                : selectedWarehouse
                  ? `${selectedLabel} — Inventory`
                  : "Inventory detail"}
            </h3>
          </div>

          <div className="w-full max-w-xs">
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                className="pl-8"
                placeholder="Filter items…"
                value={searchInput}
                onChange={(e) => onSearchChange(e.target.value)}
              />
            </div>
          </div>
        </div>

        <InventoryDetailTable
          items={items}
          onSkuClick={onSkuClick}
          isLoading={isLoading}
          stockoutMode={stockoutMode}
        />
      </div>
    </div>
  );
}
