import { createFileRoute } from "@tanstack/react-router";
import { CustomerLink, InventoryLink, OrderLink } from "@/components/entity-links";
import { useMemo, useState } from "react";
import {
  AlertTriangle,
  BarChart3,
  Boxes,
  FileDown,
  PackageX,
  PencilLine,
  RefreshCw,
  Search,
  TrendingUp,
  Users,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { toast } from "sonner";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { Textarea } from "@/components/ui/textarea";
import {
  type BackorderLine,
  type ContributionRow,
  formatOpsSyncToast,
  useBackorders,
  useBackordersAnalytics,
  useBackordersByAccount,
  useBackordersSummary,
  useSyncBackorders,
  useSyncInventoryStocks,
  useUpdateBackorderReason,
} from "@/hooks/useOperations";
import { formatDateTime, formatKES, formatNumber } from "@/lib/format";
import { useAuth } from "@/lib/auth";
import { downloadApiFile } from "@/lib/api";
import { DATE_PRESETS, type DatePresetId, resolveDatePreset } from "@/lib/date-presets";

export const Route = createFileRoute("/app/backorders")({
  head: () => ({ meta: [{ title: "Backorders — Kim-Fay OrderWatch" }] }),
  component: BackordersPage,
});

function formatKes(n: number | string) {
  return `KES ${Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function qtyWithUom(qty: string | number, uom: string | null | undefined) {
  const n = Number(qty).toLocaleString();
  return uom ? `${n} ${uom}` : n;
}

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

const AXIS_STYLE = { fill: "var(--color-muted-foreground)", fontSize: 11 } as const;
const LINES_COLOR = "var(--color-chart-1)";
const REVENUE_COLOR = "var(--color-chart-5)";
const CATEGORY_RANK_COLOR = "var(--color-chart-1)";

const BACKORDER_REASON_OPTIONS = [
  { value: "supplier_delay", label: "Supplier Delay" },
  { value: "inventory_shortage", label: "Inventory Shortage" },
  { value: "production_issue", label: "Production Issue" },
  { value: "logistics_disruption", label: "Logistics Disruption" },
  { value: "quality_hold", label: "Quality Hold" },
  { value: "forecast_gap", label: "Forecast Gap" },
  { value: "customer_change", label: "Customer Change" },
  { value: "system_allocation", label: "System Allocation" },
] as const;

const BACKORDER_REASON_LABELS = Object.fromEntries(
  BACKORDER_REASON_OPTIONS.map((option) => [option.value, option.label]),
) as Record<string, string>;

function reasonLabel(code: string | null | undefined) {
  if (!code) return "Unassigned";
  return BACKORDER_REASON_LABELS[code] ?? code.replaceAll("_", " ");
}

function formatChartDate(value: string) {
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleDateString("en-KE", { month: "short", day: "numeric" });
}

function topCategoriesWithOther(
  rows: Array<{ product_line: string; revenue_at_risk: number }>,
  n: number,
) {
  const sorted = [...rows].sort((a, b) => b.revenue_at_risk - a.revenue_at_risk);
  const top = sorted.slice(0, n).map((r) => ({ name: r.product_line || "Unclassified", value: r.revenue_at_risk }));
  const rest = sorted.slice(n).reduce((sum, r) => sum + r.revenue_at_risk, 0);
  if (rest > 0) top.push({ name: "Other", value: rest });
  return top;
}

function BackordersPage() {
  const { session } = useAuth();
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [datePreset, setDatePreset] = useState<DatePresetId>("this_month");
  const [productLine, setProductLine] = useState("all");
  const [customerGroup, setCustomerGroup] = useState("all");
  const [warehouseId, setWarehouseId] = useState("all");
  const [reasonCode, setReasonCode] = useState("all");
  const [editingLine, setEditingLine] = useState<BackorderLine | null>(null);
  const [reasonDraftCode, setReasonDraftCode] = useState("none");
  const [reasonDraftNotes, setReasonDraftNotes] = useState("");
  const [isDownloading, setIsDownloading] = useState(false);

  const summary = useBackordersSummary();
  const accounts = useBackordersByAccount(10);
  const analytics = useBackordersAnalytics({
    date_from: dateFrom,
    date_to: dateTo,
    product_line: productLine !== "all" ? productLine : undefined,
    customer_group: customerGroup !== "all" ? customerGroup : undefined,
    warehouse_id: warehouseId !== "all" ? warehouseId : undefined,
    reason_code: reasonCode !== "all" ? reasonCode : undefined,
  });
  const { data, isLoading, refetch } = useBackorders({
    q: q || undefined,
    date_from: dateFrom,
    date_to: dateTo,
    product_line: productLine !== "all" ? productLine : undefined,
    customer_group: customerGroup !== "all" ? customerGroup : undefined,
    warehouse_id: warehouseId !== "all" ? warehouseId : undefined,
    reason_code: reasonCode !== "all" ? reasonCode : undefined,
    page,
    per_page: perPage,
  });
  const sync = useSyncBackorders();
  const syncStocks = useSyncInventoryStocks();
  const updateBackorderReason = useUpdateBackorderReason();
  const canEditReasons = session?.role === "Administrator"
    || session?.role === "Customer Service Manager"
    || session?.role === "Sales Operations";

  function applyDatePreset(preset: DatePresetId) {
    setDatePreset(preset);
    if (preset !== "custom") {
      const range = resolveDatePreset(preset);
      setDateFrom(range.from);
      setDateTo(range.to);
      setPage(1);
    }
  }

  function handleUpdate() {
    if (!dateFrom || !dateTo) {
      toast.error("Select dates before updating backorders.");
      return;
    }
    if (dateFrom > dateTo) {
      toast.error("Start date must be before end date.");
      return;
    }

    sync.mutate({ date_from: dateFrom, date_to: dateTo }, {
      onSuccess: (res) => {
        if (res.sync_run.status === "completed") {
          toast.success(formatOpsSyncToast("Backorders", res.sync_run));
        } else if (res.sync_run.status === "stopped") {
          toast.warning(formatOpsSyncToast("Backorders", res.sync_run));
        } else if (res.sync_run.status === "running") {
          toast.info(formatOpsSyncToast("Backorders", res.sync_run));
        } else {
          toast.error(formatOpsSyncToast("Backorders", res.sync_run));
        }
        refetch();
        summary.refetch();
        accounts.refetch();
        analytics.refetch();
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  function handleSyncStocks() {
    syncStocks.mutate(undefined, {
      onSuccess: (res) => {
        const msg = formatOpsSyncToast("Stocks", res.sync_run);
        if (res.sync_run.status === "completed") {
          if (res.sync_run.filters?.warning) {
            toast.warning(msg);
          } else {
            toast.success(msg);
          }
        } else if (res.sync_run.status === "stopped") {
          toast.warning(msg);
        } else if (res.sync_run.status === "running") {
          toast.info(msg);
        } else {
          toast.error(msg);
        }
        refetch();
        analytics.refetch();
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  function openReasonEditor(line: BackorderLine) {
    setEditingLine(line);
    setReasonDraftCode(line.reason_code ?? "none");
    setReasonDraftNotes(line.reason_notes ?? "");
  }

  function saveReason() {
    if (!editingLine) return;

    updateBackorderReason.mutate({
      id: editingLine.id,
      reason_code: reasonDraftCode === "none" ? null : reasonDraftCode,
      reason_notes: reasonDraftNotes.trim() || null,
    }, {
      onSuccess: () => {
        toast.success("Backorder reason saved.");
        setEditingLine(null);
      },
      onError: (error: Error) => toast.error(error.message),
    });
  }

  async function handleDownload() {
    if (!dateFrom || !dateTo) {
      toast.error("Select dates before downloading.");
      return;
    }
    if (dateFrom > dateTo) {
      toast.error("Start date must be before end date.");
      return;
    }

    const qs = new URLSearchParams();
    if (q) qs.set("q", q);
    if (dateFrom) qs.set("date_from", dateFrom);
    if (dateTo) qs.set("date_to", dateTo);
    if (productLine !== "all") qs.set("product_line", productLine);
    if (customerGroup !== "all") qs.set("customer_group", customerGroup);
    if (warehouseId !== "all") qs.set("warehouse_id", warehouseId);
    if (reasonCode !== "all") qs.set("reason_code", reasonCode);

    setIsDownloading(true);
    try {
      await downloadApiFile(`operations/backorders/export?${qs}`, `backorders-export-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`, { timeoutMs: 180_000 });
      toast.success("Backorders Excel download started.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to download backorders.");
    } finally {
      setIsDownloading(false);
    }
  }

  const anySyncPending = sync.isPending || syncStocks.isPending;
  const filteredSummary = analytics.data?.summary;
  const trendData = useMemo(
    () => analytics.data?.charts.trend.map((point) => ({
      ...point,
      label: formatChartDate(point.bucket_date),
    })) ?? [],
    [analytics.data],
  );
  const categoryData = analytics.data?.charts.category_distribution ?? [];
  const categoryRows = topCategoriesWithOther(categoryData, 6);
  const leadTimeData = analytics.data?.charts.lead_time_correlation ?? [];
  const reasonDistribution = analytics.data?.charts.reason_distribution ?? [];

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight">Backorders</h1>
          <p className="text-sm text-muted-foreground">
            Interactive backorder monitoring with root-cause tracking, lead-time analysis, and current open-line exposure
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <Button variant="outline" onClick={handleSyncStocks} disabled={anySyncPending}>
            <Boxes className={`mr-2 h-4 w-4 ${syncStocks.isPending ? "animate-spin" : ""}`} />
            {syncStocks.isPending ? "Syncing stocks…" : "Sync stocks only"}
          </Button>
          <Button onClick={handleUpdate} disabled={anySyncPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
            {sync.isPending ? "Updating…" : "Update backorders"}
          </Button>
        </div>
      </div>

      <OperationsSyncStatus />

      <div className="rounded-lg border bg-card p-4 shadow-sm">
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-5">
          <div className="xl:col-span-2">
            <Label htmlFor="bo-search">Search lines</Label>
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                id="bo-search"
                className="pl-8"
                placeholder="Order, item, customer…"
                value={q}
                onChange={(e) => { setQ(e.target.value); setPage(1); }}
              />
            </div>
          </div>
          <div>
            <Label>Date preset</Label>
            <Select value={datePreset} onValueChange={(value) => applyDatePreset(value as DatePresetId)}>
              <SelectTrigger>
                <SelectValue placeholder="Date preset" />
              </SelectTrigger>
              <SelectContent>
                {DATE_PRESETS.filter((preset) => preset.id !== "last_30_days").map((preset) => (
                  <SelectItem key={preset.id} value={preset.id}>{preset.id === "custom" ? "Date range" : preset.label}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div className="flex flex-wrap items-end gap-2 xl:col-span-2">
            <div className="min-w-[130px] flex-1">
              <Label htmlFor="bo-from">From</Label>
              <Input
                id="bo-from"
                type="date"
                value={dateFrom}
                onChange={(e) => { setDatePreset("custom"); setDateFrom(e.target.value); setPage(1); }}
              />
            </div>
            <div className="min-w-[130px] flex-1">
              <Label htmlFor="bo-to">To</Label>
              <Input
                id="bo-to"
                type="date"
                value={dateTo}
                onChange={(e) => { setDatePreset("custom"); setDateTo(e.target.value); setPage(1); }}
              />
            </div>
            <Button variant="outline" onClick={handleDownload} disabled={isDownloading}>
              <FileDown className={`mr-2 h-4 w-4 ${isDownloading ? "animate-pulse" : ""}`} />
              {isDownloading ? "Preparing…" : "Download Excel"}
            </Button>
          </div>
          <div>
            <Label>Product line</Label>
            <Select value={productLine} onValueChange={(value) => { setProductLine(value); setPage(1); }}>
              <SelectTrigger>
                <SelectValue placeholder="All product lines" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All product lines</SelectItem>
                {(analytics.data?.filters.product_lines ?? []).map((line) => (
                  <SelectItem key={line} value={line}>{line}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label>Customer group</Label>
            <Select value={customerGroup} onValueChange={(value) => { setCustomerGroup(value); setPage(1); }}>
              <SelectTrigger>
                <SelectValue placeholder="All customer groups" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All customer groups</SelectItem>
                {(analytics.data?.filters.customer_groups ?? []).map((group) => (
                  <SelectItem key={group} value={group}>{group}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label>Warehouse</Label>
            <Select value={warehouseId} onValueChange={(value) => { setWarehouseId(value); setPage(1); }}>
              <SelectTrigger>
                <SelectValue placeholder="All warehouses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All warehouses</SelectItem>
                {(analytics.data?.filters.warehouse_ids ?? []).map((warehouse) => (
                  <SelectItem key={warehouse} value={warehouse}>{warehouse}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <div>
            <Label>Root cause</Label>
            <Select value={reasonCode} onValueChange={(value) => { setReasonCode(value); setPage(1); }}>
              <SelectTrigger>
                <SelectValue placeholder="All reasons" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All reasons</SelectItem>
                <SelectItem value="unassigned">Unassigned</SelectItem>
                {(analytics.data?.filters.reason_codes ?? []).map((code) => (
                  <SelectItem key={code} value={code}>{reasonLabel(code)}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Open lines" value={filteredSummary?.open_lines} loading={analytics.isLoading} icon={PackageX} />
        <Kpi label="Open orders" value={filteredSummary?.open_orders} loading={analytics.isLoading} icon={AlertTriangle} />
        <Kpi
          label="Revenue at risk"
          value={filteredSummary ? formatKes(filteredSummary.revenue_at_risk) : undefined}
          loading={analytics.isLoading}
          warn={(filteredSummary?.revenue_at_risk ?? 0) > 500_000}
          text
        />
        <Kpi
          label="Last synced"
          value={summary.data?.last_synced_at ? new Date(summary.data.last_synced_at).toLocaleString() : "—"}
          loading={summary.isLoading}
          text
        />
      </div>

      {analytics.data?.excel_summary && (
        <BackordersExcelSummaryPanel summary={analytics.data.excel_summary} />
      )}

      <div className="grid gap-4 xl:grid-cols-3">
        <ChartPanel title="Historical Backorder Volume Trends" icon={TrendingUp} loading={analytics.isLoading}>
          <div className="space-y-4">
            <div>
              <p className="mb-1 text-xs font-medium text-muted-foreground">Open lines</p>
              <ResponsiveContainer width="100%" height={110}>
                <LineChart data={trendData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="label" tick={AXIS_STYLE} minTickGap={18} />
                  <YAxis tick={AXIS_STYLE} width={32} />
                  <Tooltip formatter={(value: number) => formatNumber(value)} labelFormatter={(value) => `Date: ${value}`} />
                  <Line type="monotone" dataKey="line_count" stroke={LINES_COLOR} strokeWidth={2} dot={false} name="Open lines" />
                </LineChart>
              </ResponsiveContainer>
            </div>
            <div>
              <p className="mb-1 text-xs font-medium text-muted-foreground">Revenue at risk</p>
              <ResponsiveContainer width="100%" height={110}>
                <LineChart data={trendData}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="label" tick={AXIS_STYLE} minTickGap={18} />
                  <YAxis tick={AXIS_STYLE} width={32} tickFormatter={(value) => formatKES(Number(value), { compact: true })} />
                  <Tooltip formatter={(value: number) => formatKES(value)} labelFormatter={(value) => `Date: ${value}`} />
                  <Line type="monotone" dataKey="revenue_at_risk" stroke={REVENUE_COLOR} strokeWidth={2} dot={false} name="Revenue at risk" />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </div>
        </ChartPanel>

        <ChartPanel title="Inventory Lead Time Correlations" icon={BarChart3} loading={analytics.isLoading}>
          <ResponsiveContainer width="100%" height={260}>
            <BarChart data={leadTimeData}>
              <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
              <XAxis dataKey="lead_time_bucket" tick={AXIS_STYLE} />
              <YAxis tick={AXIS_STYLE} />
              <Tooltip
                formatter={(value: number, name: string) => (
                  name === "revenue_at_risk" ? formatKES(value) : formatNumber(value)
                )}
              />
              <Legend />
              <Bar dataKey="line_count" name="Open lines" fill="var(--color-chart-2)" radius={[4, 4, 0, 0]} />
              <Bar dataKey="revenue_at_risk" name="Revenue at risk" fill="var(--color-chart-4)" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </ChartPanel>

        <ChartPanel title="Product Category Backorder Distribution" icon={BarChart3} loading={analytics.isLoading}>
          {categoryRows.length === 0 ? (
            <p className="py-10 text-center text-xs text-muted-foreground">No category data for the current filters</p>
          ) : (
            <ResponsiveContainer width="100%" height={Math.max(categoryRows.length * 34, 100)}>
              <BarChart data={categoryRows} layout="vertical" margin={{ left: 8, right: 24, top: 4, bottom: 4 }}>
                <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-muted" />
                <XAxis type="number" tick={AXIS_STYLE} tickFormatter={(value) => formatKES(Number(value), { compact: true })} axisLine={false} tickLine={false} />
                <YAxis type="category" dataKey="name" width={110} tick={AXIS_STYLE} axisLine={false} tickLine={false} />
                <Tooltip formatter={(value: number) => formatKES(value)} cursor={{ fill: "var(--color-muted)" }} />
                <Bar dataKey="value" fill={CATEGORY_RANK_COLOR} radius={[0, 4, 4, 0]} maxBarSize={20} />
              </BarChart>
            </ResponsiveContainer>
          )}
        </ChartPanel>
      </div>

      <div className="rounded-lg border">
        <div className="flex items-center gap-2 border-b px-4 py-3">
          <Users className="h-4 w-4 text-muted-foreground" />
          <h2 className="font-medium">Most affected accounts</h2>
        </div>
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-2 font-medium">Account</th>
              <th className="px-4 py-2 font-medium text-right">Orders</th>
              <th className="px-4 py-2 font-medium text-right">Open lines</th>
              <th className="px-4 py-2 font-medium text-right">Open qty</th>
              <th className="px-4 py-2 font-medium text-right">Rev at risk</th>
            </tr>
          </thead>
          <tbody>
            {accounts.isLoading && <tr><td colSpan={5} className="px-4 py-4"><Skeleton className="h-5 w-full" /></td></tr>}
            {(accounts.data?.accounts ?? []).map((a) => (
              <tr key={a.customer_acumatica_id} className="border-b">
                <td className="px-4 py-2">
                  <CustomerLink
                    customerId={a.customer_acumatica_id}
                    customerName={a.customer_name}
                    className="block"
                  >
                    <div className="font-medium">{a.customer_name ?? a.customer_acumatica_id}</div>
                    <div className="text-xs text-muted-foreground">{a.customer_acumatica_id}</div>
                  </CustomerLink>
                </td>
                <td className="px-4 py-2 text-right">{a.order_count}</td>
                <td className="px-4 py-2 text-right">{a.open_lines}</td>
                <td className="px-4 py-2 text-right font-mono">{Number(a.total_open_qty).toLocaleString()}</td>
                <td className="px-4 py-2 text-right font-medium">{formatKes(a.revenue_at_risk)}</td>
              </tr>
            ))}
            {!accounts.isLoading && (accounts.data?.accounts ?? []).length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">No backorder data yet</td></tr>
            )}
          </tbody>
        </table>
      </div>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <div className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="mb-3 flex items-center gap-2">
            <AlertTriangle className="h-4 w-4 text-muted-foreground" />
            <h2 className="font-medium">Root-cause distribution</h2>
          </div>
          <div className="space-y-2">
            {reasonDistribution.length === 0 && (
              <p className="text-sm text-muted-foreground">No backorder reasons recorded for the current filters.</p>
            )}
            {reasonDistribution.map((item) => (
              <div key={item.reason_code} className="flex items-center justify-between rounded-md border px-3 py-2 text-sm">
                <div>
                  <div className="font-medium">{reasonLabel(item.reason_code)}</div>
                  <div className="text-xs text-muted-foreground">{formatNumber(Number(item.line_count))} lines</div>
                </div>
                <div className="font-medium">{formatKES(Number(item.revenue_at_risk))}</div>
              </div>
            ))}
          </div>
        </div>

        <div className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="mb-3 flex items-center gap-2">
            <Users className="h-4 w-4 text-muted-foreground" />
            <h2 className="font-medium">Editing policy</h2>
          </div>
          <ul className="space-y-2 text-sm text-muted-foreground">
            <li>Reason codes support supplier, inventory, production, logistics, and related root causes.</li>
            <li>Additional notes capture context for procurement, warehouse, and audit follow-up.</li>
            <li>{canEditReasons ? "You can edit backorder reason fields for the selected lines." : "Reason fields are read-only for your current role."}</li>
          </ul>
        </div>
      </div>

      <div className="rounded-lg border overflow-x-auto">
        <table className="w-full text-sm min-w-[1480px]">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-3 font-medium">Order</th>
              <th className="px-4 py-3 font-medium">Item</th>
              <th className="px-4 py-3 font-medium">Product line</th>
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-4 py-3 font-medium">Warehouse</th>
              <th className="px-4 py-3 font-medium text-right">On hand</th>
              <th className="px-4 py-3 font-medium">UOM</th>
              <th className="px-4 py-3 font-medium">Status</th>
              <th className="px-4 py-3 font-medium text-right">Lead time</th>
              <th className="px-4 py-3 font-medium text-right">Ordered</th>
              <th className="px-4 py-3 font-medium text-right">Shipped</th>
              <th className="px-4 py-3 font-medium text-right">Open</th>
              <th className="px-4 py-3 font-medium text-right">Unit price</th>
              <th className="px-4 py-3 font-medium text-right">Rev at risk</th>
              <th className="px-4 py-3 font-medium">Reason</th>
              <th className="px-4 py-3 font-medium text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && Array.from({ length: 6 }).map((_, i) => (
              <tr key={i}><td colSpan={16} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
            ))}
            {!isLoading && (data?.data ?? []).map((row) => (
              <tr key={row.id} className="border-b hover:bg-muted/20">
                <td className="px-4 py-3 font-medium">
                  {row.customer_acumatica_id ? (
                    <OrderLink
                      customerId={row.customer_acumatica_id}
                      orderId={row.order_nbr}
                      className="font-mono"
                    >
                      {row.order_nbr}
                    </OrderLink>
                  ) : (
                    <span className="font-mono">{row.order_nbr}</span>
                  )}
                </td>
                <td className="px-4 py-3">
                  <InventoryLink inventoryId={row.inventory_id} description={row.product_name} className="block">
                    <div className="font-medium">{row.product_name ?? row.inventory_id}</div>
                  </InventoryLink>
                </td>
                <td className="px-4 py-3 text-xs">{row.product_line ?? "Unclassified"}</td>
                <td className="px-4 py-3">
                  {row.customer_acumatica_id ? (
                    <CustomerLink
                      customerId={row.customer_acumatica_id}
                      customerName={row.customer_name}
                      className="block"
                    >
                      <div>{row.customer_name ?? row.customer_acumatica_id ?? "—"}</div>
                      <div className="text-xs text-muted-foreground">{row.customer_acumatica_id}</div>
                    </CustomerLink>
                  ) : (
                    <div>
                      <div>{row.customer_name ?? "—"}</div>
                    </div>
                  )}
                </td>
                <td className="px-4 py-3 text-xs">{row.warehouse_id ?? "—"}</td>
                <td className="px-4 py-3 text-right">
                  {row.qty_on_hand != null ? (
                    <div className="flex flex-col items-end gap-0.5">
                      <span className={`font-mono ${row.stock_shortfall ? "text-red-600 font-medium" : ""}`}>
                        {Number(row.qty_on_hand).toLocaleString()}
                      </span>
                      {row.stock_shortfall && (
                        <Badge variant="destructive" className="text-[10px] px-1 py-0">Short</Badge>
                      )}
                    </div>
                  ) : (
                    <span className="text-muted-foreground text-xs">Sync stocks</span>
                  )}
                </td>
                <td className="px-4 py-3 text-xs">{row.uom ?? "—"}</td>
                <td className="px-4 py-3 text-xs">{row.fulfillment_status ?? "—"}</td>
                <td className="px-4 py-3 text-right font-mono">{row.lead_time_days != null ? `${row.lead_time_days}d` : "—"}</td>
                <td className="px-4 py-3 text-right font-mono">{qtyWithUom(row.order_qty, row.uom)}</td>
                <td className="px-4 py-3 text-right font-mono">{qtyWithUom(row.shipped_qty, row.uom)}</td>
                <td className="px-4 py-3 text-right font-mono">{qtyWithUom(row.open_qty, row.uom)}</td>
                <td className="px-4 py-3 text-right font-mono">{formatKes(row.unit_price)}</td>
                <td className="px-4 py-3 text-right font-medium">{formatKes(row.revenue_at_risk)}</td>
                <td className="px-4 py-3">
                  <div className="space-y-1">
                    <Badge variant={row.reason_code ? "default" : "secondary"}>{reasonLabel(row.reason_code)}</Badge>
                    <div className="max-w-[14rem] text-xs text-muted-foreground">
                      {row.reason_notes ? row.reason_notes : "No additional notes"}
                    </div>
                    {row.reason_updated_at && (
                      <div className="text-[10px] text-muted-foreground">Updated {formatDateTime(row.reason_updated_at)}</div>
                    )}
                  </div>
                </td>
                <td className="px-4 py-3 text-right">
                  {canEditReasons ? (
                    <Button variant="outline" size="sm" onClick={() => openReasonEditor(row)}>
                      <PencilLine className="mr-1 h-3.5 w-3.5" />
                      Edit
                    </Button>
                  ) : (
                    <span className="text-xs text-muted-foreground">View only</span>
                  )}
                </td>
              </tr>
            ))}
            {!isLoading && (data?.data ?? []).length === 0 && (
              <tr><td colSpan={16} className="px-4 py-8 text-center text-muted-foreground">No backorder lines — sync from Acumatica to populate</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {data && (
        <PaginationControls
          currentPage={page}
          perPage={perPage}
          total={data.total}
          lastPage={data.last_page}
          onPageChange={setPage}
          onPerPageChange={(n) => { setPerPage(n); setPage(1); }}
        />
      )}

      <Dialog open={!!editingLine} onOpenChange={(open) => !open && setEditingLine(null)}>
        <DialogContent className="sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>Edit Backorder Root Cause</DialogTitle>
            <DialogDescription>
              Capture the operational reason code and detailed notes for {editingLine?.order_nbr ?? "this backorder"}.
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-4">
            <div className="grid gap-2">
              <Label>Reason code</Label>
              <Select value={reasonDraftCode} onValueChange={setReasonDraftCode}>
                <SelectTrigger>
                  <SelectValue placeholder="Select a reason" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Unassigned</SelectItem>
                  {BACKORDER_REASON_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>{option.label}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="reason-notes">Detailed notes</Label>
              <Textarea
                id="reason-notes"
                placeholder="Document supplier, warehouse, or logistics context for this backorder..."
                value={reasonDraftNotes}
                onChange={(event) => setReasonDraftNotes(event.target.value)}
                rows={6}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditingLine(null)}>Cancel</Button>
            <Button onClick={saveReason} disabled={updateBackorderReason.isPending}>
              {updateBackorderReason.isPending ? "Saving…" : "Save reason"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

function ChartPanel({
  title,
  icon: Icon,
  children,
  loading,
}: {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
  loading?: boolean;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="mb-3 flex items-center gap-2">
        <Icon className="h-4 w-4 text-muted-foreground" />
        <h2 className="font-medium">{title}</h2>
      </div>
      {loading ? <Skeleton className="h-[260px] w-full" /> : children}
    </div>
  );
}

function BackordersExcelSummaryPanel({
  summary,
}: {
  summary: {
    totals: {
      back_order_qty: number;
      back_order_value: number;
      line_count: number;
      order_count: number;
    };
    by_reason: ContributionRow[];
    by_customer_group: ContributionRow[];
    top_products: ContributionRow[];
  };
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="mb-3 flex items-center gap-2">
        <BarChart3 className="h-4 w-4 text-cyan-600" />
        <h2 className="font-medium">Excel-style backorder summary</h2>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <SummaryTile label="Back Order Qty" value={formatNumber(summary.totals.back_order_qty)} tone="amber" />
        <SummaryTile label="Back Ordered Value" value={formatKES(summary.totals.back_order_value)} tone="red" />
        <SummaryTile label="Orders" value={formatNumber(summary.totals.order_count)} tone="blue" />
        <SummaryTile label="Lines" value={formatNumber(summary.totals.line_count)} tone="cyan" />
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <SummaryContributionList title="Reason contribution" rows={summary.by_reason} labelKey="reason" valueKey="back_order_value" tone="red" />
        <SummaryContributionList title="Customer group contribution" rows={summary.by_customer_group} labelKey="customer_group" valueKey="back_order_value" tone="cyan" />
        <SummaryContributionList title="Top product contribution" rows={summary.top_products} labelKey="product_name" valueKey="back_order_value" tone="blue" />
      </div>
    </div>
  );
}

function SummaryTile({
  label, value, tone,
}: {
  label: string;
  value: string;
  tone: "blue" | "amber" | "red" | "cyan";
}) {
  const color = {
    blue: "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300",
    amber: "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300",
    red: "border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300",
    cyan: "border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-900/50 dark:bg-cyan-950/30 dark:text-cyan-300",
  }[tone];

  return (
    <div className={`rounded-md border p-3 ${color}`}>
      <p className="text-xs font-medium opacity-80">{label}</p>
      <p className="mt-1 text-lg font-semibold">{value}</p>
    </div>
  );
}

function SummaryContributionList({
  title, rows, labelKey, valueKey, tone,
}: {
  title: string;
  rows: ContributionRow[];
  labelKey: string;
  valueKey: string;
  tone: "blue" | "red" | "cyan";
}) {
  const bar = tone === "red" ? "bg-red-500" : tone === "cyan" ? "bg-cyan-500" : "bg-blue-500";
  const topRows = rows.slice(0, 5);

  return (
    <div className="rounded-md border p-3">
      <h3 className="text-sm font-medium">{title}</h3>
      <div className="mt-3 space-y-3">
        {topRows.length === 0 && <p className="text-xs text-muted-foreground">No contribution data yet.</p>}
        {topRows.map((row, index) => (
          <div key={`${String(row[labelKey])}-${index}`} className="space-y-1">
            <div className="flex items-center justify-between gap-3 text-xs">
              <span className="truncate font-medium">{reasonLabel(String(row[labelKey] ?? "Unassigned"))}</span>
              <span className="shrink-0 font-mono">{formatKES(Number(row[valueKey] ?? 0))}</span>
            </div>
            <div className="h-1.5 rounded-full bg-muted">
              <div className={`h-1.5 rounded-full ${bar}`} style={{ width: `${Math.min(Number(row.contribution_pct ?? 0), 100)}%` }} />
            </div>
            <p className="text-[11px] text-muted-foreground">{Number(row.contribution_pct ?? 0).toFixed(1)}% contribution</p>
          </div>
        ))}
      </div>
    </div>
  );
}

function Kpi({
  label, value, loading, icon: Icon, warn, text,
}: {
  label: string;
  value?: number | string;
  loading?: boolean;
  icon?: React.ComponentType<{ className?: string }>;
  warn?: boolean;
  text?: boolean;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        {Icon && <Icon className="h-4 w-4" />}
        {label}
      </div>
      {loading ? <Skeleton className="mt-2 h-8 w-24" /> : (
        <p className={`mt-1 text-2xl font-semibold ${warn ? "text-red-600" : ""}`}>
          {text ? value : typeof value === "number" ? value.toLocaleString() : value ?? "—"}
        </p>
      )}
    </div>
  );
}
