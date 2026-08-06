import { useQuery } from "@tanstack/react-query";
import type { ColumnDef } from "@tanstack/react-table";
import { Award, BarChart3, CheckCircle2, Layers, Lightbulb, PieChart as PieIcon, TrendingUp, Trophy } from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import {
  Bar,
  BarChart,
  Cell,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { PageTitle } from "@/components/production/DashboardHeader";
import { DataTable } from "@/components/production/DataTable";
import { FilterDrawer, FilterTrigger } from "@/components/production/FilterDrawer";
import { KpiGrid, type KpiCardProps } from "@/components/production/KpiCard";
import { MultiSelect } from "@/components/ui/multi-select";
import { Panel } from "@/components/production/Panel";
import { SegmentedControl } from "@/components/production/SegmentedControl";
import { TrendBadge } from "@/components/production/StatusBadge";
import { CHANNELS } from "@/data/Stock/channels";
import { CURRENT_MONTH, CURRENT_YEAR, TIMELINE, type MonthKey } from "@/data/Stock/months";
import { usePersistentState } from "@/hooks/usePersistentState";
import { salesKeys, salesService } from "@/services/Stock/sales.service";
import type { OwnershipView, SalesFilters, SalesPeriod } from "@/types/Stock/filters";
import type { SalesRecord, SalesRow, TrendStatus } from "@/types/Stock/sales";
import { growthPercent } from "@/utils/Stock/calculations";
import { matchesSearch, reconcileSelection } from "@/utils/Stock/filters";
import { compactNumber, formatNumber, formatPercent, formatShare } from "@/utils/Stock/format";

const EMPTY_SALES_RECORDS: SalesRecord[] = [];

const DEFAULTS: SalesFilters = {
  ownership: "combined",
  businessLines: ["Consumer Sales", "Kim-Fay Professional"],
  brands: [],
  categories: [],
  warehouses: [],
  period: "YTD 2026",
  channels: [...CHANNELS],
  search: "",
};

const PERIODS: SalesPeriod[] = ["YTD 2026", "Last 12 Months", "2025", "2024"];

const yearWindow = (year: number, maxMonth = 12) =>
  TIMELINE.filter((m) => m.year === year && m.monthIndex <= maxMonth);

function periodWindow(period: SalesPeriod): { current: MonthKey[]; prior: MonthKey[] } {
  if (period === "Last 12 Months") return { current: TIMELINE.slice(-12), prior: TIMELINE.slice(-24, -12) };
  if (period === "YTD 2026") return { current: yearWindow(CURRENT_YEAR, CURRENT_MONTH), prior: yearWindow(CURRENT_YEAR - 1, CURRENT_MONTH) };
  if (period === "2025") return { current: yearWindow(2025), prior: yearWindow(2024) };
  return { current: yearWindow(2024), prior: yearWindow(2023) };
}

const trendOf = (growth: number): TrendStatus =>
  growth > 5 ? "growing" : growth < -5 ? "declining" : "stable";

export function StockSalesIntelligencePage({ resetToken }: { resetToken: number }) {
  const { data: rawRecords = EMPTY_SALES_RECORDS, isLoading } = useQuery({
    queryKey: salesKeys.records,
    queryFn: salesService.getSalesRecords,
  });

  const { value: filters, setValue, reset } = usePersistentState<SalesFilters>(
    "sales-intelligence-filters",
    DEFAULTS,
  );
  const [topMode, setTopMode] = useState<"Brands" | "Categories" | "Products">("Brands");
  const [topCount, setTopCount] = useState<5 | 10>(5);
  const [activeCategory, setActiveCategory] = useState<string | null>(null);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [quantityView, setQuantityView] = useState<"Shipped" | "Ordered">("Shipped");
  const records = useMemo(() => rawRecords.map((record) => ({
    ...record,
    quantity: quantityView === "Ordered" ? (record.orderedQuantity ?? 0) : (record.shippedQuantity ?? 0),
  })), [rawRecords, quantityView]);

  useEffect(() => {
    if (resetToken > 0) reset();
  }, [resetToken, reset]);

  const validBrands = useMemo(() => [...new Set(records
    .filter((record) => filters.ownership === "combined" || record.brandOwnership === filters.ownership)
    .map((record) => record.brand).filter(Boolean))].sort(), [records, filters.ownership]);
  const warehouseOptions = useMemo(() => [...new Set(records.map((record) => record.warehouseId).filter(Boolean))].sort(), [records]);

  const validCategories = useMemo(
    () =>
      [...new Set(
        records
          .filter(
            (r) =>
              validBrands.includes(r.brand) &&
              filters.brands.includes(r.brand) &&
              (!r.businessLine || filters.businessLines.includes(r.businessLine)),
          )
          .map((r) => r.category),
      )].sort(),
    [records, validBrands, filters.brands, filters.businessLines],
  );

  useEffect(() => {
    setValue((prev) => {
      const brands = reconcileSelection(prev.brands, validBrands);
      const nextBrands = brands.length ? brands : validBrands;
      const categories = prev.categories.length
        ? reconcileSelection(prev.categories, validCategories)
        : validCategories;
      const warehouses = prev.warehouses.length ? reconcileSelection(prev.warehouses, warehouseOptions) : warehouseOptions;
      if (nextBrands === prev.brands && categories === prev.categories && warehouses === prev.warehouses) return prev;
      return { ...prev, brands: nextBrands, categories, warehouses };
    });
  }, [validBrands, validCategories, warehouseOptions, setValue]);

  const warehouseIds = useMemo(
    () => new Set(filters.warehouses),
    [filters.warehouses],
  );

  const win = useMemo(() => periodWindow(filters.period), [filters.period]);

  const scoped = useMemo(
    () =>
      records.filter(
        (r) =>
          (filters.ownership === "combined" || r.brandOwnership === filters.ownership) &&
          (!r.businessLine || filters.businessLines.includes(r.businessLine)) &&
          filters.brands.includes(r.brand) &&
          filters.categories.includes(r.category) &&
          warehouseIds.has(r.warehouseId) &&
          (!r.channel || filters.channels.includes(r.channel)) &&
          (!activeCategory || r.category === activeCategory) &&
          matchesSearch(filters.search, [r.productName, r.inventoryId, r.brand, r.category]),
      ),
    [records, filters, warehouseIds, activeCategory],
  );

  const currentKeys = useMemo(() => new Set(win.current.map((m) => `${m.year}-${m.monthIndex}`)), [win]);
  const priorKeys = useMemo(() => new Set(win.prior.map((m) => `${m.year}-${m.monthIndex}`)), [win]);

  const currentRecords = useMemo(
    () => scoped.filter((r) => currentKeys.has(`${r.year}-${r.monthIndex}`)),
    [scoped, currentKeys],
  );
  const priorRecords = useMemo(
    () => scoped.filter((r) => priorKeys.has(`${r.year}-${r.monthIndex}`)),
    [scoped, priorKeys],
  );

  const totalCurrent = currentRecords.reduce((a, r) => a + r.quantity, 0);
  const totalPrior = priorRecords.reduce((a, r) => a + r.quantity, 0);
  const growth = growthPercent(totalCurrent, totalPrior);

  const lastMonth = win.current[win.current.length - 1];

  const rows: SalesRow[] = useMemo(() => {
    const map = new Map<string, SalesRow>();
    currentRecords.forEach((r) => {
      const existing =
        map.get(r.inventoryId) ??
        ({
          salesId: `SL-${r.inventoryId}`,
          inventoryId: r.inventoryId,
          productName: r.productName,
          brand: r.brand,
          category: r.category,
          brandOwnership: r.brandOwnership,
          businessLine: r.businessLine,
          periodSales: 0,
          currentMonthSales: 0,
          averageMonthlySales: 0,
          priorPeriodSales: 0,
          growth: 0,
          contribution: 0,
          trendStatus: "stable",
        } satisfies SalesRow);
      existing.periodSales += r.quantity;
      if (lastMonth && r.year === lastMonth.year && r.monthIndex === lastMonth.monthIndex) existing.currentMonthSales += r.quantity;
      map.set(r.inventoryId, existing);
    });
    priorRecords.forEach((r) => {
      const row = map.get(r.inventoryId);
      if (row) row.priorPeriodSales += r.quantity;
    });
    return [...map.values()]
      .map((row) => {
        const g = growthPercent(row.periodSales, row.priorPeriodSales);
        return {
          ...row,
          averageMonthlySales: Math.round(row.periodSales / win.current.length),
          growth: g,
          contribution: totalCurrent ? (row.periodSales / totalCurrent) * 100 : 0,
          trendStatus: trendOf(g),
        };
      })
      .sort((a, b) => b.periodSales - a.periodSales);
  }, [currentRecords, priorRecords, win, lastMonth, totalCurrent]);

  const monthly = useMemo(
    () =>
      win.current.map((point, i) => {
        const priorPoint = win.prior[i];
        const current = currentRecords
          .filter((r) => r.year === point.year && r.monthIndex === point.monthIndex)
          .reduce((a, r) => a + r.quantity, 0);
        const prior = priorPoint
          ? priorRecords
              .filter((r) => r.year === priorPoint.year && r.monthIndex === priorPoint.monthIndex)
              .reduce((a, r) => a + r.quantity, 0)
          : 0;
        return { month: point.label, current, prior };
      }),
    [currentRecords, priorRecords, win],
  );

  const groupTotals = (key: "brand" | "category" | "productName") => {
    const map = new Map<string, number>();
    currentRecords.forEach((r) => map.set(r[key], (map.get(r[key]) ?? 0) + r.quantity));
    return [...map.entries()]
      .map(([name, value]) => ({ name, value }))
      .sort((a, b) => b.value - a.value);
  };

  const byBrand = useMemo(() => groupTotals("brand"), [currentRecords]);
  const byCategory = useMemo(() => groupTotals("category"), [currentRecords]);
  const byProduct = useMemo(() => groupTotals("productName"), [currentRecords]);

  const manufacturedUnits = currentRecords.filter((r) => r.brandOwnership === "manufactured").reduce((a, r) => a + r.quantity, 0);
  const partnerUnits = totalCurrent - manufacturedUnits;

  const categoryGrowth = useMemo(() => {
    const cur = new Map<string, number>();
    const pri = new Map<string, number>();
    currentRecords.forEach((r) => cur.set(r.category, (cur.get(r.category) ?? 0) + r.quantity));
    priorRecords.forEach((r) => pri.set(r.category, (pri.get(r.category) ?? 0) + r.quantity));
    return [...cur.entries()]
      .map(([name, value]) => ({ name, value, growth: growthPercent(value, pri.get(name) ?? 0) }))
      .sort((a, b) => b.growth - a.growth);
  }, [currentRecords, priorRecords]);

  const bestMonth = monthly.reduce((a, b) => (b.current > a.current ? b : a), monthly[0] ?? { month: "â€”", current: 0, prior: 0 });
  const proUnits = currentRecords.filter((r) => r.businessLine === "Kim-Fay Professional").reduce((a, r) => a + r.quantity, 0);

  const insights = useMemo(() => {
    if (!totalCurrent) return [];
    const out = [
      `Manufactured brands contributed ${formatShare((manufacturedUnits / totalCurrent) * 100)} of selected volume.`,
      `Total selected volume changed by ${formatPercent(growth)} versus the same period last year.`,
      byBrand[0] ? `${byBrand[0].name} is the best-performing brand with ${formatNumber(byBrand[0].value)} units.` : "",
      byCategory[0]
        ? `${byCategory[0].name} contributed ${formatShare((byCategory[0].value / totalCurrent) * 100)} of selected volume.`
        : "",
      categoryGrowth[0] ? `${categoryGrowth[0].name} is the fastest growing category at ${formatPercent(categoryGrowth[0].growth)}.` : "",
      categoryGrowth.at(-1) && categoryGrowth.at(-1)!.growth < 0
        ? `${categoryGrowth.at(-1)!.name} declined by ${formatPercent(categoryGrowth.at(-1)!.growth)} versus the prior year.`
        : "",
      `${bestMonth.month} recorded the highest sales volume in the selected period.`,
      `Kim-Fay Professional contributed ${formatShare((proUnits / totalCurrent) * 100)} of selected volume.`,
    ];
    return out.filter(Boolean);
  }, [totalCurrent, manufacturedUnits, growth, byBrand, byCategory, categoryGrowth, bestMonth, proUnits]);

  const kpis: KpiCardProps[] = [
    { label: "Period Sales Volume", value: formatNumber(totalCurrent), caption: `${filters.period} Â· units`, icon: BarChart3, tone: "primary", compact: true },
    { label: "Current Month Sales", value: formatNumber(rows.reduce((a, r) => a + r.currentMonthSales, 0)), caption: "units", icon: TrendingUp, tone: "primary", compact: true },
    { label: "Average Monthly Sales", value: formatNumber(Math.round(totalCurrent / win.current.length)), caption: "units", icon: BarChart3, tone: "neutral", compact: true },
    { label: "Active Brands", value: formatNumber(byBrand.length), caption: "with volume", icon: Layers, tone: "neutral", compact: true },
    { label: "Best Performing Brand", value: byBrand[0]?.name ?? "â€”", caption: byBrand[0] ? `${formatNumber(byBrand[0].value)} units` : "", icon: Trophy, tone: "primary", compact: true },
    { label: "Best Category", value: byCategory[0]?.name ?? "â€”", caption: byCategory[0] ? `${formatNumber(byCategory[0].value)} units` : "", icon: Award, tone: "primary", compact: true },
    { label: "Growth vs Prior Year", value: formatPercent(growth), caption: "same period", icon: TrendingUp, tone: growth >= 0 ? "success" : "critical", compact: true },
    {
      label: "Manufactured vs Partner",
      value: totalCurrent ? `${Math.round((manufacturedUnits / totalCurrent) * 100)}% / ${Math.round((partnerUnits / totalCurrent) * 100)}%` : "â€”",
      caption: "share of units",
      icon: PieIcon,
      tone: "neutral",
      compact: true,
    },
  ];

  const columns: ColumnDef<SalesRow, unknown>[] = [
    { accessorKey: "inventoryId", header: "Inventory ID", meta: { width: "8%" }, cell: (c) => <span className="font-bold text-primary">{c.row.original.inventoryId}</span> },
    { accessorKey: "productName", header: "Product", meta: { width: "16%" }, cell: (c) => <span className="block truncate font-medium text-navy">{c.row.original.productName}</span> },
    { accessorKey: "brand", header: "Brand", meta: { width: "10%" } },
    { accessorKey: "category", header: "Category", meta: { width: "11%" } },
    { accessorKey: "brandOwnership", header: "Ownership", meta: { width: "9%" }, cell: (c) => (c.row.original.brandOwnership === "manufactured" ? "Manufactured" : "Partner / Trading") },
    { accessorKey: "businessLine", header: "Business Line", meta: { width: "10%" } },
    { accessorKey: "periodSales", header: "Period Sales", meta: { align: "right", width: "7%" }, cell: (c) => <b className="tabular-nums">{formatNumber(c.row.original.periodSales)}</b> },
    { accessorKey: "currentMonthSales", header: "Current Month", meta: { align: "right", width: "7%" }, cell: (c) => formatNumber(c.row.original.currentMonthSales) },
    { accessorKey: "averageMonthlySales", header: "Avg / Month", meta: { align: "right", width: "6%" }, cell: (c) => formatNumber(c.row.original.averageMonthlySales) },
    { accessorKey: "growth", header: "Growth vs LY", meta: { align: "right", width: "6%" }, cell: (c) => formatPercent(c.row.original.growth) },
    { accessorKey: "contribution", header: "Contribution", meta: { align: "right", width: "7%" }, cell: (c) => formatShare(c.row.original.contribution) },
    { accessorKey: "trendStatus", header: "Trend", meta: { width: "7%" }, cell: (c) => <TrendBadge status={c.row.original.trendStatus} /> },
  ];

  const topData = (topMode === "Brands" ? byBrand : topMode === "Categories" ? byCategory : byProduct).slice(0, topCount);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-3">
      <PageTitle title="Sales Volume Intel" subtitle="YTD volume trends across Kim-Fay manufactured and partner brands" />

      <div className="production-page-toolbar flex shrink-0 flex-wrap items-center justify-between gap-2">
        {activeCategory ? (
          <button
            type="button"
            onClick={() => setActiveCategory(null)}
            className="h-8 rounded-lg border border-primary bg-brand-soft px-3 text-xs font-semibold text-primary"
          >
            Clear category drill-down: {activeCategory}
          </button>
        ) : (
          <span />
        )}
        <FilterTrigger onClick={() => setFiltersOpen(true)} />
      </div>
      <FilterDrawer open={filtersOpen} onOpenChange={setFiltersOpen} subtitle="All values are units or volume â€” no currency">
        <SegmentedControl
          label="Quantity View"
          value={quantityView}
          onChange={(value) => setQuantityView(value as "Shipped" | "Ordered")}
          options={[{ value: "Shipped", label: "Shipped" }, { value: "Ordered", label: "Ordered" }]}
        />
        <SegmentedControl
          label="Brand Ownership View"
          value={filters.ownership}
          onChange={(ownership) => setValue((p) => ({ ...p, ownership: ownership as OwnershipView, brands: [], categories: [] }))}
          options={[
            { value: "manufactured", label: "Manufactured" },
            { value: "partner", label: "Partner / Trading" },
            { value: "combined", label: "Combined" },
          ]}
        />
        <MultiSelect label="Business Line" options={["Consumer Sales", "Kim-Fay Professional"]} selected={filters.businessLines} onChange={(v) => setValue((p) => ({ ...p, businessLines: v as SalesFilters["businessLines"] }))} allLabel="All Lines" />
        <MultiSelect label="Brands" hint="(Multi-select)" options={validBrands} selected={filters.brands} onChange={(brands) => setValue((p) => ({ ...p, brands }))} allLabel="All Brands" />
        <MultiSelect label="Product Category" options={validCategories} selected={filters.categories} onChange={(categories) => setValue((p) => ({ ...p, categories }))} allLabel="All Categories" />
        <MultiSelect label="Warehouses" options={warehouseOptions} selected={filters.warehouses} onChange={(warehouses) => setValue((p) => ({ ...p, warehouses }))} allLabel="All Warehouses" />
        <MultiSelect label="Period" options={PERIODS} selected={[filters.period]} onChange={(v) => setValue((p) => ({ ...p, period: (v.at(-1) as SalesPeriod) ?? p.period }))} maxChips={1} />
        <MultiSelect label="Channel" options={CHANNELS} selected={filters.channels} onChange={(channels) => setValue((p) => ({ ...p, channels }))} allLabel="All Channels" />
      </FilterDrawer>

      <KpiGrid items={kpis} compact loading={isLoading} />

      {/* Charts strip â€” fixed height, never grows the page. */}
      <div className="grid shrink-0 gap-3 xl:grid-cols-3">
        <Panel
          className="xl:col-span-2"
          title="Monthly Sales Trend"
          subtitle="Current period vs equivalent prior-year period"
          icon={TrendingUp}
        >
          <div className="h-[170px] w-full">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={monthly} margin={{ top: 8, right: 8, left: -16, bottom: 0 }}>
                <CartesianGrid stroke="var(--border)" vertical={false} />
                <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} />
                <YAxis tickFormatter={compactNumber} tickLine={false} axisLine={false} width={48} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} />
                <Tooltip formatter={(v: number) => formatNumber(v)} contentStyle={{ borderRadius: 10, fontSize: 12 }} />
                <Legend wrapperStyle={{ fontSize: 11 }} />
                <Line type="monotone" dataKey="current" name="Current period" stroke="var(--primary)" strokeWidth={2.5} dot={{ r: 2.5 }} />
                <Line type="monotone" dataKey="prior" name="Prior year" stroke="var(--muted-foreground)" strokeDasharray="4 4" strokeWidth={2} dot={false} />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </Panel>

        <Panel title="Sales Mix by Brand Ownership" icon={PieIcon}>
          <div className="grid h-[170px] items-center gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="h-full">
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie
                    data={[
                      { name: "Manufactured", value: manufacturedUnits },
                      { name: "Partner", value: partnerUnits },
                    ]}
                    dataKey="value"
                    innerRadius={40}
                    outerRadius={64}
                    paddingAngle={2}
                  >
                    <Cell fill="var(--primary)" />
                    <Cell fill="var(--navy)" />
                  </Pie>
                  <Tooltip formatter={(v: number) => formatNumber(v)} contentStyle={{ borderRadius: 10, fontSize: 12 }} />
                </PieChart>
              </ResponsiveContainer>
            </div>
            <div className="space-y-1.5 text-xs">
              <p className="text-[11px] text-muted-foreground">Total selected volume</p>
              <p className="text-lg font-bold text-primary tabular-nums">{formatNumber(totalCurrent)}</p>
              <p><b>{totalCurrent ? formatShare((manufacturedUnits / totalCurrent) * 100) : "â€”"}</b> Manufactured Â· {formatNumber(manufacturedUnits)}</p>
              <p><b>{totalCurrent ? formatShare((partnerUnits / totalCurrent) * 100) : "â€”"}</b> Partner Â· {formatNumber(partnerUnits)}</p>
            </div>
          </div>
        </Panel>
      </div>

      {/* Main area â€” table on the left, rankings + insights on the right; both scroll internally. */}
      <div className="grid gap-3 lg:min-h-0 lg:flex-1 lg:grid-rows-[minmax(0,1fr)] xl:grid-cols-3">
        <Panel
          className="xl:col-span-2"
          title="Sales Overview by SKU / Brand"
          icon={BarChart3}
          fill
          compact
          bodyClassName="p-0"
        >
          <DataTable
            data={rows}
            columns={columns}
            getRowId={(r) => r.inventoryId}
            search={filters.search}
            onSearchChange={(search) => setValue((p) => ({ ...p, search }))}
            searchPlaceholder="Search by product or brand..."
            isLoading={isLoading}
            renderMobileCard={(r) => (
              <div className="space-y-1.5">
                <div className="grid grid-cols-[minmax(0,1fr)_auto] items-start gap-2">
                  <div className="min-w-0">
                    <p className="truncate text-sm font-semibold text-navy">{r.productName}</p>
                    <p className="truncate text-[11px] text-muted-foreground">{r.brand} Â· {r.category}</p>
                  </div>
                  <TrendBadge status={r.trendStatus} />
                </div>
                <div className="grid grid-cols-3 gap-2 text-[11px] text-muted-foreground">
                  <span>Period<br /><b className="text-sm text-primary tabular-nums">{formatNumber(r.periodSales)}</b></span>
                  <span>Current Month<br /><b className="text-sm text-foreground tabular-nums">{formatNumber(r.currentMonthSales)}</b></span>
                  <span>Avg/Month<br /><b className="text-sm text-foreground tabular-nums">{formatNumber(r.averageMonthlySales)}</b></span>
                  <span>Growth<br /><b className="text-sm text-foreground tabular-nums">{formatPercent(r.growth)}</b></span>
                  <span>Contribution<br /><b className="text-sm text-foreground tabular-nums">{formatShare(r.contribution)}</b></span>
                  <span>Ownership<br /><b className="text-sm text-foreground">{r.brandOwnership === "manufactured" ? "Manufactured" : "Partner / Trading"}</b></span>
                </div>
              </div>
            )}
          />
        </Panel>

        <div className="flex min-h-0 flex-col gap-3">
          <Panel
            title={`Top ${topCount} ${topMode}`}
            icon={Trophy}
            subtitle="Click a bar to drill into a category"
            actions={
              <div className="flex items-center gap-1">
                {(["Brands", "Categories", "Products"] as const).map((m) => (
                  <button key={m} type="button" onClick={() => setTopMode(m)} className={`rounded-md px-2 py-1 text-xs font-semibold ${topMode === m ? "bg-primary text-primary-foreground" : "text-muted-foreground"}`}>
                    {m}
                  </button>
                ))}
                <button type="button" onClick={() => setTopCount(topCount === 5 ? 10 : 5)} className="rounded-md border border-border px-2 py-1 text-xs font-semibold">
                  Top {topCount === 5 ? 10 : 5}
                </button>
              </div>
            }
          >
            <div style={{ height: topCount * 28 + 24 }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={topData} layout="vertical" margin={{ left: 8, right: 24 }}>
                  <XAxis type="number" tickFormatter={compactNumber} tick={{ fontSize: 11, fill: "var(--muted-foreground)" }} axisLine={false} tickLine={false} />
                  <YAxis type="category" dataKey="name" width={120} tick={{ fontSize: 11, fill: "var(--foreground)" }} axisLine={false} tickLine={false} />
                  <Tooltip formatter={(v: number) => formatNumber(v)} contentStyle={{ borderRadius: 10, fontSize: 12 }} />
                  <Bar
                    dataKey="value"
                    fill="var(--primary)"
                    radius={[0, 4, 4, 0]}
                    onClick={(d: { name?: string }) => topMode === "Categories" && d?.name && setActiveCategory(d.name)}
                  />
                </BarChart>
              </ResponsiveContainer>
            </div>
          </Panel>

          <Panel title="Key Insights" icon={Lightbulb} subtitle="Rules-based observations from the selected data" fill>
            <ul className="space-y-2">
              {insights.map((insight) => (
                <li key={insight} className="flex items-start gap-2 text-xs text-foreground sm:text-sm">
                  <CheckCircle2 className="mt-0.5 size-3.5 shrink-0 text-success sm:size-4" />
                  <span className="min-w-0">{insight}</span>
                </li>
              ))}
              {insights.length === 0 ? (
                <li className="text-sm text-muted-foreground">No insights for the current selection.</li>
              ) : null}
            </ul>
          </Panel>
        </div>
      </div>
    </div>
  );
}
