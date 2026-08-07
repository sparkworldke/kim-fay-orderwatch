import { PackageSearch, Pencil, Truck } from "lucide-react";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Sheet, SheetContent, SheetHeader, SheetTitle } from "@/components/ui/sheet";
import { useMsi } from "@/hooks/useMsiOverrides";
import type { InventoryMetrics } from "@/types/Stock/inventory";
import { formatNumber, formatPercent } from "@/utils/Stock/format";
import { yearToCurrentMonth } from "@/utils/Stock/calculations";
import { getFgsTransferRecommendation } from "@/utils/Stock/transferRecommendation";
import { MsiEditDialog } from "./MsiEditDialog";
import { EmptyState, Panel } from "./Panel";
import { StatusBadge } from "./StatusBadge";
import { WarehouseBreakdown } from "./WarehouseBreakdown";

function Field({ label, value, detail }: { label: string; value: string; detail?: string }) {
  return (
    <div className="min-w-0 rounded-md bg-secondary/70 px-2 py-1.5">
      <p className="text-[9px] leading-tight text-muted-foreground">{label}</p>
      <p className="text-[9px] font-semibold text-navy tabular-nums">{value}</p>
      {detail ? <p className="text-[9px] text-muted-foreground tabular-nums">{detail}</p> : null}
    </div>
  );
}

export function ProductDetailBody({
  metrics,
  warehouseIds,
  allowMsiEdit,
}: {
  metrics: InventoryMetrics;
  warehouseIds: string[];
  allowMsiEdit: boolean;
}) {
  const { canEditMsi } = useMsi();
  const [editing, setEditing] = useState(false);
  const stocks = metrics.item.warehouseStocks.filter((w) => warehouseIds.includes(w.warehouseId));
  const transfer = getFgsTransferRecommendation(metrics.item);
  const yearSales = yearToCurrentMonth(metrics.item.monthlySales);
  const availableSales = yearSales.filter((month) => Number.isFinite(month.quantity));
  const yearTotal = availableSales.reduce((total, month) => total + month.quantity, 0);
  const yearAverage = availableSales.length ? yearTotal / availableSales.length : Number.NaN;
  const firstSale = availableSales[0]?.quantity;
  const latestSale = availableSales.at(-1)?.quantity;
  const change =
    firstSale !== undefined && latestSale !== undefined && firstSale > 0
      ? ((latestSale - firstSale) / firstSale) * 100
      : Number.NaN;
  const missedVolume = yearSales.reduce(
    (total, month) => total + (Number.isFinite(month.missedOpportunityQuantity) ? month.missedOpportunityQuantity : 0),
    0,
  );
  const revenueMonths = yearSales.filter((month) => Number.isFinite(month.missedOpportunityRevenue));
  const missedRevenue = revenueMonths.reduce((total, month) => total + month.missedOpportunityRevenue, 0);
  const revenueComplete = revenueMonths.length > 0 && revenueMonths.every((month) => month.missedRevenueComplete);
  const currency = revenueMonths.find((month) => month.currencyId)?.currencyId ?? "KES";
  const formattedRevenue = revenueMonths.length
    ? new Intl.NumberFormat("en-KE", { style: "currency", currency, maximumFractionDigits: 0 }).format(missedRevenue)
    : "—";

  return (
    <div className="space-y-2">
      <Panel title={metrics.productName} subtitle={`${metrics.inventoryId} Â· ${metrics.brand} Â· ${metrics.category}`} icon={PackageSearch}>
        <div className="mb-2 flex flex-wrap items-center gap-1.5">
          {metrics.msiConfigured ? <StatusBadge status={metrics.msiStatus} /> : <span>MSI —</span>}
          <span className="text-[11px] text-muted-foreground">MSI</span>
          <StatusBadge status={metrics.coverageStatus} />
          <span className="text-[11px] text-muted-foreground">Coverage</span>
          <StatusBadge status={metrics.planningStatus} />
          <span className="text-[11px] text-muted-foreground">Planning</span>
        </div>
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          <Field label="Days of Cover" value={Number.isFinite(metrics.daysOfCover) ? formatNumber(Math.ceil(metrics.daysOfCover)) : "—"} />
          <Field label="Daily Run Rate" value={formatNumber(metrics.dailyRunRate, 1)} />
          <Field label="Stock Needed" value={metrics.msiConfigured ? formatNumber(metrics.requirement) : "—"} />
        </div>
        {allowMsiEdit && canEditMsi ? (
          <Button variant="outline" size="sm" className="mt-3 gap-1.5" onClick={() => setEditing(true)}>
            <Pencil className="size-3.5" /> Edit MSI
          </Button>
        ) : null}
        {editing ? (
          <MsiEditDialog
            open={editing}
            onOpenChange={setEditing}
            inventoryId={metrics.inventoryId}
            productName={metrics.productName}
            currentMsi={metrics.msi}
          />
        ) : null}
      </Panel>

      {transfer ? (
        <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-amber-950">
          <Truck className="mt-0.5 size-4 shrink-0" />
          <div className="min-w-0">
            <p className="text-xs font-bold">FGS has zero on-hand or available stock</p>
            <p className="text-xs leading-5">
              Pick {formatNumber(transfer.quantity)} units from {transfer.sourceWarehouse} and
              transfer them to FGS.
            </p>
          </div>
        </div>
      ) : null}

      <WarehouseBreakdown stocks={stocks} subtitle="Across currently selected warehouses" />

      <Panel title="Sales Summary (January–Current Month)">
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          {yearSales.map((month) => (
            <Field
              key={`${month.year}-${month.monthIndex}`}
              label={month.month}
              value={`Ordered: ${Number.isFinite(month.orderedQuantity) ? formatNumber(month.orderedQuantity) : "—"}`}
              detail={`Shipped: ${Number.isFinite(month.quantity) ? formatNumber(month.quantity) : "—"} · Missed: ${Number.isFinite(month.orderedQuantity) && Number.isFinite(month.quantity) ? formatNumber(month.orderedQuantity - month.quantity) : "—"}`}
            />
          ))}
          <Field label="Year-to-Date Total" value={availableSales.length ? formatNumber(yearTotal) : "—"} />
          <Field label="Monthly Average" value={Number.isFinite(yearAverage) ? formatNumber(Math.round(yearAverage)) : "—"} />
          <Field label="Change (First → Latest)" value={Number.isFinite(change) ? formatPercent(change) : "—"} />
          <div className="rounded-md border border-red-200 bg-red-50 px-2 py-1.5">
            <p className="text-[9px] leading-tight text-red-700">YTD Missed Opportunity Volume</p>
            <p className="text-[9px] font-semibold text-red-700 tabular-nums">{formatNumber(missedVolume)}</p>
          </div>
          <div className="rounded-md border border-red-200 bg-red-50 px-2 py-1.5">
            <p className="text-[9px] leading-tight text-red-700">YTD Missed Opportunity Revenue</p>
            <p className="text-[9px] font-semibold text-red-700 tabular-nums">{formattedRevenue}</p>
            {!revenueComplete && revenueMonths.length ? <p className="text-[9px] text-red-600">Partial pricing</p> : null}
          </div>
        </div>
      </Panel>
    </div>
  );
}

export function ProductDetailPanel(props: {
  metrics: InventoryMetrics | null;
  warehouseIds: string[];
  showMachine: boolean;
  requirementLabel: string;
  allowMsiEdit: boolean;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const { metrics, open, onOpenChange, ...rest } = props;
  return (
    <>
      <div className="hidden min-h-0 flex-col gap-3 xl:flex xl:overflow-y-auto">
        {metrics ? (
          <ProductDetailBody metrics={metrics} {...rest} />
        ) : (
          <Panel title="Product Details" icon={PackageSearch}>
            <EmptyState
              title="Select a product"
              description="Choose a row from the table to see warehouse breakdown, demand trend and planning detail."
            />
          </Panel>
        )}
      </div>
      <Sheet open={open && !!metrics} onOpenChange={onOpenChange}>
        <SheetContent hideOverlay side="bottom" className="max-h-[92vh] overflow-y-auto rounded-t-2xl xl:hidden">
          <SheetHeader className="pb-0">
            <SheetTitle>Product Details</SheetTitle>
          </SheetHeader>
          <div className="p-4 pt-2">
            {metrics ? <ProductDetailBody metrics={metrics} {...rest} /> : null}
          </div>
        </SheetContent>
      </Sheet>
    </>
  );
}
