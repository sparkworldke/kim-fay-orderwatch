/**
 * SkuDetailPanel
 *
 * A right-hand slide-over panel (Radix Sheet) that opens when a SKU row is
 * clicked. Renders the item metadata header, a date-range picker
 * (min 7 days, max 730 days), and the SalesHistoryChart, ComparisonTable,
 * and InsightsSection driven by `useSkuDetail` and `useSkuInsights`.
 *
 * Closing:
 * - Escape key (wired both via Radix Sheet `onOpenChange` and an explicit
 *   `keydown` listener per the spec).
 * - Overlay click (handled by Radix Sheet, surfaced through `onOpenChange`).
 * - Close (X) button in the header.
 *
 * On `useSkuDetail` failure an inline error with a retry button is shown while
 * the panel stays open.
 *
 * Requirements: 2.1, 2.2, 2.3, 2.12, 2.13
 */

import { useEffect, useMemo, useState } from "react";
import { differenceInCalendarDays, subDays } from "date-fns";
import { AlertCircle, RefreshCw } from "lucide-react";
import { useSkuDetail } from "@/hooks/useSkuDetail";
import {
  formatCost,
  formatLastModified,
  mapValuationMethod,
} from "@/utils/inventoryUtils";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { SalesHistoryChart } from "@/components/inventory/SalesHistoryChart";
import { ComparisonTable } from "@/components/inventory/ComparisonTable";
import { InsightsSection } from "@/components/inventory/InsightsSection";

export type SkuDetailPanelProps = {
  inventoryId: string | null;
  onClose: () => void;
};

const MIN_RANGE_DAYS = 7;
const MAX_RANGE_DAYS = 730;
const DEFAULT_RANGE_DAYS = 90;

function toIsoDate(d: Date): string {
  return d.toISOString().slice(0, 10);
}

function parseIsoDate(iso: string): Date {
  // Treat the YYYY-MM-DD as a local date to avoid timezone shifts.
  return new Date(`${iso}T00:00:00`);
}

export function SkuDetailPanel({ inventoryId, onClose }: SkuDetailPanelProps) {
  // Default date range: 90 days ending today.
  const [dateRange, setDateRange] = useState<{ from: Date; to: Date }>(() => ({
    to: new Date(),
    from: subDays(new Date(), DEFAULT_RANGE_DAYS),
  }));

  const dateFrom = toIsoDate(dateRange.from);
  const dateTo = toIsoDate(dateRange.to);

  const {
    data,
    isLoading,
    isError,
    error,
    refetch,
    isFetching,
  } = useSkuDetail(inventoryId, dateFrom, dateTo);

  // Explicit Escape handler (in addition to Radix's built-in handling) to
  // satisfy the spec requirement.
  useEffect(() => {
    if (!inventoryId) return;
    function handleKeyDown(e: KeyboardEvent) {
      if (e.key === "Escape") onClose();
    }
    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [inventoryId, onClose]);

  const rangeDays = useMemo(
    () => differenceInCalendarDays(dateRange.to, dateRange.from),
    [dateRange.from, dateRange.to],
  );
  const rangeError =
    rangeDays < MIN_RANGE_DAYS
      ? `Range must be at least ${MIN_RANGE_DAYS} days.`
      : rangeDays > MAX_RANGE_DAYS
        ? `Range must be at most ${MAX_RANGE_DAYS} days.`
        : null;

  const item = data?.item;
  const monthlySales = data?.monthly_sales ?? [];

  return (
    <Sheet open={inventoryId !== null} onOpenChange={(open) => { if (!open) onClose(); }}>
      <SheetContent
        side="right"
        className="w-full gap-0 p-0 sm:max-w-2xl"
        // Prevent closing on pointer-down outside while date inputs are focused
        // from causing accidental dismissal — Radix handles overlay click via
        // onOpenChange above.
      >
        <SheetHeader className="border-b p-5 pr-12">
          <SheetTitle className="flex items-center gap-2">
            <span>{item?.inventory_id ?? (isLoading ? "Loading…" : "SKU detail")}</span>
            {item?.item_status && (
              <Badge variant="outline" className="font-normal">{item.item_status}</Badge>
            )}
          </SheetTitle>
          <SheetDescription className="truncate">
            {item?.description ?? "Sales history, predictions and AI insights"}
          </SheetDescription>
        </SheetHeader>

        <div className="flex-1 space-y-6 overflow-y-auto p-5">
          {/* Metadata header */}
          {isLoading ? (
            <div className="space-y-2">
              <Skeleton className="h-4 w-2/3" />
              <Skeleton className="h-4 w-1/2" />
            </div>
          ) : item ? (
            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
              <Meta label="Warehouse" value={item.default_warehouse_id ?? "—"} />
              <Meta label="Item class" value={item.item_class ?? "—"} />
              <Meta
                label="Valuation method"
                value={mapValuationMethod(item.valuation_method ?? "")}
              />
              <Meta label="Last cost" value={formatCost(item.last_cost)} />
              <Meta label="Average cost" value={formatCost(item.average_cost)} />
              <Meta
                label="Last modified"
                value={formatLastModified(item.last_modified_at)}
              />
            </dl>
          ) : null}

          {/* Inline detail-load error (panel stays open) */}
          {isError && (
            <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4">
              <div className="flex items-center gap-2 text-sm text-destructive">
                <AlertCircle className="h-4 w-4" />
                <span className="font-medium">Unable to load SKU detail</span>
              </div>
              <p className="mt-1 text-xs text-muted-foreground">
                {error instanceof Error ? error.message : "The request failed."}
              </p>
              <Button
                variant="outline"
                size="sm"
                className="mt-3"
                onClick={() => refetch()}
                disabled={isFetching}
              >
                <RefreshCw className={`mr-2 h-3.5 w-3.5 ${isFetching ? "animate-spin" : ""}`} />
                Retry
              </Button>
            </div>
          )}

          {/* Date range picker */}
          <div className="rounded-lg border p-4">
            <Label className="text-xs uppercase tracking-wide text-muted-foreground">
              Analysis period
            </Label>
            <div className="mt-2 flex flex-wrap items-end gap-3">
              <div>
                <Label htmlFor="sku-date-from" className="text-xs">From</Label>
                <Input
                  id="sku-date-from"
                  type="date"
                  value={dateFrom}
                  max={dateTo}
                  onChange={(e) => {
                    const next = parseIsoDate(e.target.value);
                    if (!isNaN(next.getTime())) setDateRange((r) => ({ ...r, from: next }));
                  }}
                  className="h-9 w-40"
                />
              </div>
              <div>
                <Label htmlFor="sku-date-to" className="text-xs">To</Label>
                <Input
                  id="sku-date-to"
                  type="date"
                  value={dateTo}
                  min={dateFrom}
                  onChange={(e) => {
                    const next = parseIsoDate(e.target.value);
                    if (!isNaN(next.getTime())) setDateRange((r) => ({ ...r, to: next }));
                  }}
                  className="h-9 w-40"
                />
              </div>
            </div>
            {rangeError && (
              <p className="mt-2 text-xs text-destructive">{rangeError}</p>
            )}
            {!rangeError && (
              <p className="mt-2 text-xs text-muted-foreground">
                {rangeDays + 1} day range (min {MIN_RANGE_DAYS}, max {MAX_RANGE_DAYS}).
                Future prediction period is equal in length.
              </p>
            )}
          </div>

          {/* Charts + table + insights (only when we have data) */}
          {item && (
            <>
              <section>
                <h3 className="mb-2 text-sm font-semibold">Sales history & prediction</h3>
                <SalesHistoryChart monthlySales={monthlySales} />
              </section>

              <section>
                <h3 className="mb-2 text-sm font-semibold">Predicted vs actual</h3>
                <ComparisonTable monthlySales={monthlySales} />
              </section>

              {inventoryId && (
                <section>
                  <InsightsSection
                    inventoryId={inventoryId}
                    dateRange={dateRange}
                  />
                </section>
              )}
            </>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}

function Meta({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <dt className="text-xs uppercase tracking-wide text-muted-foreground">{label}</dt>
      <dd className="font-medium">{value}</dd>
    </div>
  );
}
