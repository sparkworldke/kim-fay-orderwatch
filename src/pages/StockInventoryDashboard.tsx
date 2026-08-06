import type { ColumnDef } from "@tanstack/react-table";
import { AlertTriangle, Boxes, Layers, PackageCheck, ShieldCheck, Timer, TrendingDown, Package } from "lucide-react";
import { useMemo, useState, type ReactNode } from "react";
import { toast } from "sonner";
import { DataTable } from "@/components/production/DataTable";
import { PageTitle } from "@/components/production/DashboardHeader";
import { FilterDrawer, FilterTrigger } from "@/components/production/FilterDrawer";
import { KpiGrid, type KpiCardProps } from "@/components/production/KpiCard";
import { Panel } from "@/components/production/Panel";
import { ProductDetailPanel } from "@/components/production/ProductDetailPanel";
import { StatusBadge, StatusLegend } from "@/components/production/StatusBadge";
import { TransferRequests } from "@/components/production/TransferRequests";
import { TrendChart } from "@/components/production/TrendChart";
import { yearToCurrentMonth } from "@/utils/Stock/calculations";
import { useMsi } from "@/hooks/useMsiOverrides";
import type { InventoryItem, InventoryMetrics, StockStatus } from "@/types/Stock/inventory";
import { buildMetrics } from "@/utils/Stock/calculations";
import { matchesSearch } from "@/utils/Stock/filters";
import { formatCover, formatNumber } from "@/utils/Stock/format";
import { STATUS_LABEL } from "@/utils/Stock/status";
import { getFgsTransferRecommendation } from "@/utils/Stock/transferRecommendation";
import { ProductionPreloader } from "@/components/production/ProductionPreloader";
import type { ProductionSummary } from "@/hooks/useProductionSummary";

const DEFAULT_COLUMN_VISIBILITY = {
  brand: false,
  category: false,
  businessLine: false,
  // Qty on Hand + Safety Stock stay visible by default (user request).
  lastThreeMonthsTotal: false,
  runRate: false,
  monthsOfCover: false,
  msiStatus: false,
  coverageStatus: false,
};

export interface StockInventoryDashboardProps {
  items: InventoryItem[];
  warehouseIds: string[];
  statuses: StockStatus[];
  search: string;
  onSearchChange: (value: string) => void;
  requirementLabel: string;
  showMachine: boolean;
  showBusinessLineColumn?: boolean;
  allowMsiEdit: boolean;
  extraKpis?: KpiCardProps[];
  filters: ReactNode;
  isLoading?: boolean;
  tableTitle: string;
  pageTitle: string;
  pageSubtitle: string;
  extraActions?: ReactNode;
  enableTransferRequests?: boolean;
  serverSummary?: ProductionSummary;
}

export function StockInventoryDashboard({
  items,
  warehouseIds,
  statuses,
  search,
  onSearchChange,
  requirementLabel,
  showMachine,
  showBusinessLineColumn,
  allowMsiEdit,
  extraKpis = [],
  filters,
  isLoading,
  tableTitle,
  pageTitle,
  pageSubtitle,
  extraActions,
  enableTransferRequests = true,
  serverSummary,
}: StockInventoryDashboardProps) {
  const { overrides } = useMsi();
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [filtersOpen, setFiltersOpen] = useState(false);

  const rows = useMemo(() => {
    return items
      .map((item) => buildMetrics(item, warehouseIds, allowMsiEdit ? overrides[item.inventoryId] : undefined))
      .filter((m) => statuses.includes(m.planningStatus))
      .filter((m) => matchesSearch(search, [m.inventoryId, m.productName, m.brand, m.category]))
      .sort((a, b) => b.qtyOnHand - a.qtyOnHand);
  }, [items, warehouseIds, overrides, statuses, search, allowMsiEdit]);

  const totals = useMemo(() => {
    const qty = rows.reduce((a, r) => a + r.qtyAvailable, 0);
    const finiteCover = rows.filter((r) => Number.isFinite(r.monthsOfCover));
    return {
      qty,
      critical: rows.filter((r) => r.msiConfigured && r.planningStatus === "critical").length,
      atRisk: rows.filter((r) => r.msiConfigured && r.planningStatus === "at-risk").length,
      healthy: rows.filter((r) => r.msiConfigured && r.planningStatus === "healthy").length,
      belowMsi: rows.filter((r) => r.msiConfigured && r.qtyAvailable < r.msi).length,
      requirement: rows.filter((r) => r.msiConfigured).reduce((a, r) => a + r.requirement, 0),
      avgCover: finiteCover.length
        ? finiteCover.reduce((a, r) => a + r.monthsOfCover, 0) / finiteCover.length
        : 0,
    };
  }, [rows]);

  const effectiveTotal = serverSummary?.total_skus ?? rows.length;
  const effectiveTotals = serverSummary ? {
    qty: serverSummary.qty_available,
    critical: serverSummary.critical_skus,
    atRisk: serverSummary.at_risk_skus,
    healthy: serverSummary.healthy_skus,
    belowMsi: serverSummary.skus_below_msi,
    requirement: serverSummary.requirement,
    avgCover: serverSummary.avg_months_of_cover ?? Number.NaN,
  } : totals;
  const pct = (n: number) => (effectiveTotal ? `${Math.round((n / effectiveTotal) * 100)}% of SKUs` : "—");

  const kpis: KpiCardProps[] = [
    { label: "Total SKUs", value: formatNumber(effectiveTotal), caption: "Matching filters", icon: Layers, tone: "primary" },
    { label: "Critical SKUs", value: formatNumber(effectiveTotals.critical), caption: pct(effectiveTotals.critical), icon: AlertTriangle, tone: "critical" },
    { label: "At-Risk SKUs", value: formatNumber(effectiveTotals.atRisk), caption: pct(effectiveTotals.atRisk), icon: TrendingDown, tone: "warning" },
    { label: "Healthy SKUs", value: formatNumber(effectiveTotals.healthy), caption: pct(effectiveTotals.healthy), icon: ShieldCheck, tone: "success" },
    { label: "Qty Available", value: formatNumber(effectiveTotals.qty), caption: "Selected warehouses", icon: Boxes, tone: "primary" },
    { label: "Avg Months of Cover", value: Number.isNaN(effectiveTotals.avgCover) ? "—" : formatCover(effectiveTotals.avgCover), caption: "Across filtered SKUs", icon: Timer, tone: "neutral" },
    { label: "SKUs Below MSI", value: formatNumber(effectiveTotals.belowMsi), caption: pct(effectiveTotals.belowMsi), icon: Package, tone: "warning" },
    { label: requirementLabel, value: formatNumber(effectiveTotals.requirement), caption: "Units to reach MSI", icon: PackageCheck, tone: "primary" },
    ...extraKpis,
  ];

  const selected = rows.find((r) => r.inventoryId === selectedId) ?? null;

  const columns: ColumnDef<InventoryMetrics, unknown>[] = useMemo(() => {
    const base: ColumnDef<InventoryMetrics, unknown>[] = [
      { accessorKey: "inventoryId", header: "Inventory ID", meta: { width: "7%" }, cell: (c) => <span className="font-bold text-primary">{c.row.original.inventoryId}</span> },
      { accessorKey: "productName", header: "Product", meta: { width: "14%" }, cell: (c) => <span className="block truncate font-medium text-navy">{c.row.original.productName}</span> },
      { accessorKey: "brand", header: "Brand", meta: { width: "8%" } },
      { accessorKey: "category", header: "Category", meta: { width: "9%" } },
    ];
    if (showBusinessLineColumn) base.push({ accessorKey: "businessLine", header: "Business Line", meta: { width: "9%" } });
    return [
      ...base,
      { accessorKey: "qtyAvailable", header: "Qty Available", meta: { align: "right", width: "6%" }, cell: (c) => <b className="tabular-nums">{formatNumber(c.row.original.qtyAvailable)}</b> },
      { accessorKey: "qtyOnHand", header: "Qty on Hand", meta: { align: "right", width: "6%" }, cell: (c) => <span className="tabular-nums">{formatNumber(c.row.original.qtyOnHand)}</span> },
      { accessorKey: "safetyStock", header: "Safety Stock", meta: { align: "right", width: "5.5%" }, cell: (c) => c.row.original.item.safetyStockConfigured ? formatNumber(c.row.original.safetyStock) : "—" },
      { accessorKey: "bufferStock", header: "Buffer Stock", meta: { align: "right", width: "5.5%" }, cell: (c) => c.row.original.item.bufferStockConfigured ? formatNumber(c.row.original.bufferStock) : "—" },
      { accessorKey: "msi", header: "MSI", meta: { align: "right", width: "5%" }, cell: (c) => c.row.original.msiConfigured ? formatNumber(c.row.original.msi) : "—" },
      { accessorKey: "lastThreeMonthsTotal", header: "Last 3M Sales", meta: { align: "right", width: "6%" }, cell: (c) => formatNumber(c.row.original.lastThreeMonthsTotal) },
      { accessorKey: "runRate", header: "3M Monthly Run Rate", meta: { align: "right", width: "6%" }, cell: (c) => formatNumber(Math.round(c.row.original.runRate)) },
      {
        accessorKey: "dailyRunRate",
        header: () => <span title="3 Month Data from Today">Daily Run Rate</span>,
        meta: { align: "right", width: "8%" },
        cell: (c) => formatNumber(c.row.original.dailyRunRate, 1),
      },
      { accessorKey: "monthsOfCover", header: "Cover (mo)", meta: { align: "right", width: "5.5%" }, cell: (c) => formatCover(c.row.original.monthsOfCover) },
      { accessorKey: "daysOfCover", header: "Days of Cover", meta: { align: "right", width: "5.5%" }, cell: (c) => formatNumber(Math.ceil(c.row.original.daysOfCover)) },
      { accessorKey: "msiStatus", header: "MSI Status", meta: { width: "6.5%" }, cell: (c) => c.row.original.msiConfigured ? <StatusBadge status={c.row.original.msiStatus} /> : "—" },
      { accessorKey: "coverageStatus", header: "Coverage", meta: { width: "6.5%" }, cell: (c) => <StatusBadge status={c.row.original.coverageStatus} /> },
      { accessorKey: "planningStatus", header: "Planning", meta: { width: "6.5%" }, cell: (c) => c.row.original.msiConfigured ? <StatusBadge status={c.row.original.planningStatus} /> : "—" },
      { accessorKey: "requirement", header: requirementLabel, meta: { align: "right", width: "6.5%" }, cell: (c) => c.row.original.msiConfigured ? formatNumber(c.row.original.requirement) : "—" },
    ];
  }, [requirementLabel, showBusinessLineColumn]);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-2">
      <div className="production-page-toolbar flex shrink-0 flex-wrap items-center justify-end gap-2">
        {pageTitle || pageSubtitle ? <PageTitle title={pageTitle} subtitle={pageSubtitle} /> : null}
        <div className="flex items-center gap-2">
          {extraActions}
          {enableTransferRequests ? <TransferRequests items={items} /> : null}
          <FilterTrigger onClick={() => setFiltersOpen(true)} />
        </div>
      </div>
      <FilterDrawer open={filtersOpen} onOpenChange={setFiltersOpen} subtitle="All selections apply instantly and persist on this device">
        {filters}
      </FilterDrawer>

      {isLoading ? <ProductionPreloader /> : null}
      <KpiGrid items={kpis} compact loading={isLoading} />

      <div className="grid items-start gap-2 lg:min-h-0 lg:flex-1 xl:grid-cols-[minmax(0,2.4fr)_minmax(0,1fr)]">
        <div className="min-w-0 space-y-2">
          <Panel
            title={tableTitle}
            icon={Boxes}
            actions={<StatusLegend />}
            compact
            bodyClassName="p-0"
          >
            <DataTable
            data={rows}
            columns={columns}
            getRowId={(r) => r.inventoryId}
            selectedId={selectedId}
            onSelectRow={(r) => {
              setSelectedId(r.inventoryId);
              setSheetOpen(true);
              const transfer = enableTransferRequests ? getFgsTransferRecommendation(r.item) : null;
              if (transfer) {
                toast.warning("FGS is out of stock", {
                  description: `Transfer ${formatNumber(transfer.quantity)} units of ${r.productName} from ${transfer.sourceWarehouse} to FGS.`,
                });
              }
            }}
            onHoverRow={(r) => setSelectedId(r.inventoryId)}
            search={search}
            onSearchChange={onSearchChange}
            isLoading={isLoading}
            initialColumnVisibility={DEFAULT_COLUMN_VISIBILITY}
            renderMobileCard={(r) => (
              <div className="space-y-1.5">
                <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-navy">{r.productName}</p>
                    <p className="truncate text-[11px] text-muted-foreground">
                      {r.inventoryId} Â· {r.brand} Â· {r.category}
                    </p>
                  </div>
                  <StatusBadge status={r.planningStatus} />
                </div>
                <div className="grid grid-cols-2 gap-2 text-[11px] text-muted-foreground sm:grid-cols-4">
                  <span>Available<br /><b className="text-sm text-primary tabular-nums">{formatNumber(r.qtyAvailable)}</b></span>
                  <span>On Hand<br /><b className="text-sm text-foreground tabular-nums">{formatNumber(r.qtyOnHand)}</b></span>
                  <span>Safety<br /><b className="text-sm text-foreground tabular-nums">{r.item.safetyStockConfigured ? formatNumber(r.safetyStock) : "—"}</b></span>
                  <span>Days Cover<br /><b className="text-sm text-foreground tabular-nums">{formatNumber(Math.ceil(r.daysOfCover))}</b></span>
                </div>
              </div>
            )}
            />
          </Panel>

          {selected ?? rows[0] ? (
            <TrendChart
              series={yearToCurrentMonth((selected ?? rows[0]).item.monthlySales)}
              subtitle={`January to current month · ${(selected ?? rows[0]).productName}`}
            />
          ) : null}
        </div>

        <ProductDetailPanel
          metrics={selected}
          warehouseIds={warehouseIds}
          showMachine={showMachine}
          requirementLabel={requirementLabel}
          allowMsiEdit={allowMsiEdit}
          open={sheetOpen}
          onOpenChange={setSheetOpen}
        />
      </div>
    </div>
  );
}

export const STATUS_OPTIONS = (["healthy", "at-risk", "critical"] as StockStatus[]).map((s) => STATUS_LABEL[s]);
