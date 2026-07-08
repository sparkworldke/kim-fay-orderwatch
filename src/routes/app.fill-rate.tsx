import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { BarChart3, FileDown, Gauge, List, RefreshCw, Search } from "lucide-react";
import { toast } from "sonner";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle,
} from "@/components/ui/sheet";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import {
  fillRateStatusColor,
  formatOpsSyncToast,
  type DeliverySlaStatus,
  type FillRateSnapshot,
  useFillRate,
  useFillRateSummary,
  useSyncFillRate,
  type FillRateSort,
  type ContributionRow,
} from "@/hooks/useOperations";
import { shippingZoneLabel } from "@/hooks/useShippingZones";
import { downloadApiFile } from "@/lib/api";

type FillRateSearch = {
  shipping_zone_id?: string;
  date_from?: string;
  date_to?: string;
  delivery_sla?: "breach" | "warning";
};

export const Route = createFileRoute("/app/fill-rate")({
  validateSearch: (search: Record<string, unknown>): FillRateSearch => ({
    shipping_zone_id:
      typeof search.shipping_zone_id === "string" && search.shipping_zone_id !== ""
        ? search.shipping_zone_id
        : undefined,
    date_from:
      typeof search.date_from === "string" && search.date_from !== ""
        ? search.date_from
        : undefined,
    date_to:
      typeof search.date_to === "string" && search.date_to !== ""
        ? search.date_to
        : undefined,
    delivery_sla:
      search.delivery_sla === "breach" || search.delivery_sla === "warning"
        ? search.delivery_sla
        : undefined,
  }),
  head: () => ({ meta: [{ title: "Fill Rate — Kim-Fay OrderWatch" }] }),
  component: FillRatePage,
});

function today() { return new Date().toISOString().slice(0, 10); }
function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function formatKes(n: number | string) {
  return `KES ${Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function qtyWithUom(qty: string | number, uom: string | null | undefined) {
  const n = Number(qty).toLocaleString();
  return uom ? `${n} ${uom}` : n;
}

function FillRatePage() {
  const {
    shipping_zone_id: initialZoneId,
    date_from: initialDateFrom,
    date_to: initialDateTo,
    delivery_sla: initialDeliverySla,
  } = Route.useSearch();
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("critical");
  const [sort, setSort] = useState<FillRateSort>("high_to_low");
  const [dateFrom, setDateFrom] = useState(initialDateFrom ?? startOfMonth());
  const [dateTo, setDateTo] = useState(initialDateTo ?? today());
  const [customerGroup, setCustomerGroup] = useState("all");
  const [productLine, setProductLine] = useState("all");
  const [reasonCode, setReasonCode] = useState("all");
  const [shippingZoneId, setShippingZoneId] = useState(initialZoneId ?? "all");
  const [deliverySla, setDeliverySla] = useState(initialDeliverySla ?? "all");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [selectedOrder, setSelectedOrder] = useState<FillRateSnapshot | null>(null);
  const [isDownloading, setIsDownloading] = useState(false);

  const listFilters = {
    customer_group: customerGroup !== "all" ? customerGroup : undefined,
    product_line: productLine !== "all" ? productLine : undefined,
    reason_code: reasonCode !== "all" ? reasonCode : undefined,
    shipping_zone_id: shippingZoneId !== "all" ? shippingZoneId : undefined,
    status: status !== "all" ? status : undefined,
  };

  const summary = useFillRateSummary(dateFrom, dateTo, listFilters);
  const { data, isLoading, refetch } = useFillRate({
    q: q || undefined,
    status: status !== "all" ? status : undefined,
    date_from: dateFrom,
    date_to: dateTo,
    customer_group: listFilters.customer_group,
    product_line: listFilters.product_line,
    reason_code: listFilters.reason_code,
    shipping_zone_id: listFilters.shipping_zone_id,
    delivery_sla: deliverySla === "breach" || deliverySla === "warning" ? deliverySla : undefined,
    sort,
    page,
    per_page: perPage,
  });
  const sync = useSyncFillRate();

  function handleUpdate() {
    if (!dateFrom || !dateTo) {
      toast.error("Set a date range first");
      return;
    }
    if (dateFrom > dateTo) {
      toast.error("Start date must be before end date");
      return;
    }
    sync.mutate(
      { date_from: dateFrom, date_to: dateTo },
      {
        onSuccess: (res) => {
          if (res.sync_run.status === "completed") {
            toast.success(formatOpsSyncToast("Fill rate", res.sync_run));
          } else if (res.sync_run.status === "stopped") {
            toast.warning(formatOpsSyncToast("Fill rate", res.sync_run));
          } else if (res.sync_run.status === "running") {
            toast.info(formatOpsSyncToast("Fill rate", res.sync_run));
          } else {
            toast.error(formatOpsSyncToast("Fill rate", res.sync_run));
          }
          refetch();
          summary.refetch();
        },
        onError: (e: Error) => toast.error(e.message),
      },
    );
  }

  async function handleDownload() {
    if (!dateFrom || !dateTo) {
      toast.error("Set a date range first");
      return;
    }
    if (dateFrom > dateTo) {
      toast.error("Start date must be before end date");
      return;
    }

    const qs = new URLSearchParams();
    if (q) qs.set("q", q);
    if (status !== "all") qs.set("status", status);
    if (dateFrom) qs.set("date_from", dateFrom);
    if (dateTo) qs.set("date_to", dateTo);
    if (customerGroup !== "all") qs.set("customer_group", customerGroup);
    if (productLine !== "all") qs.set("product_line", productLine);
    if (reasonCode !== "all") qs.set("reason_code", reasonCode);
    if (shippingZoneId !== "all") qs.set("shipping_zone_id", shippingZoneId);
    if (deliverySla === "breach" || deliverySla === "warning") qs.set("delivery_sla", deliverySla);
    qs.set("sort", sort);

    setIsDownloading(true);
    try {
      await downloadApiFile(`operations/fill-rate/export?${qs}`, `fill-rate-export-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`, { timeoutMs: 180_000 });
      toast.success("Fill rate Excel download started.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to download fill rate.");
    } finally {
      setIsDownloading(false);
    }
  }

  const overall = summary.data?.overall_fill_rate;

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight">Fill Rate</h1>
          <p className="text-sm text-muted-foreground">
            Unique-item rollup for the date range — use Update to refresh existing snapshots and add new orders
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <Button onClick={handleUpdate} disabled={sync.isPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
            {sync.isPending ? "Updating…" : "Update fill rate"}
          </Button>
        </div>
      </div>

      <OperationsSyncStatus />

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <Label htmlFor="fr-from">From</Label>
          <Input id="fr-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="fr-to">To</Label>
          <Input id="fr-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </div>
        <Button variant="outline" onClick={handleDownload} disabled={isDownloading}>
          <FileDown className={`mr-2 h-4 w-4 ${isDownloading ? "animate-pulse" : ""}`} />
          {isDownloading ? "Preparing…" : "Download Excel"}
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
        <Card
          label="Overall fill rate"
          value={overall != null ? `${overall}%` : "N/A"}
          loading={summary.isLoading}
          icon={Gauge}
          status={summary.data?.overall_status}
        />
        <Card label="Orders tracked" value={summary.data?.order_count} loading={summary.isLoading} />
        <Card label="Healthy (≥95%)" value={summary.data?.healthy_count} loading={summary.isLoading} status="healthy" />
        <Card label="At risk (80–94%)" value={summary.data?.at_risk_count} loading={summary.isLoading} status="at_risk" />
        <Card label="Critical (&lt;80%)" value={summary.data?.critical_count} loading={summary.isLoading} status="critical" />
        <Card
          label="Delivery &gt; SLA"
          value={summary.data?.delivery_sla_breach_count}
          loading={summary.isLoading}
          status="critical"
          hint="Nairobi/Mombasa &gt;24h · other regions &gt;72h"
        />
        <Card
          label="Delivery &gt;48h"
          value={summary.data?.delivery_sla_warning_count}
          loading={summary.isLoading}
          status="at_risk"
          hint="Regional zones between 48–72h"
        />
      </div>

      {summary.data && (
        <p className="text-sm text-muted-foreground">
          Revenue not yet shipped: <span className="font-medium text-foreground">KES {summary.data.revenue_not_shipped.toLocaleString()}</span>
          {" · "}N/A orders: {summary.data.na_count}
        </p>
      )}

      {summary.data?.excel_summary && (
        <FillRateExcelSummaryPanel summary={summary.data.excel_summary} />
      )}

      <div className="flex flex-wrap items-end gap-3">
        <div className="flex-1 min-w-[200px]">
          <Label htmlFor="fr-search">Search orders</Label>
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              id="fr-search"
              className="pl-8"
              placeholder="Order, customer, or product…"
              value={q}
              onChange={(e) => { setQ(e.target.value); setPage(1); }}
            />
          </div>
        </div>
        <div className="w-40">
          <Label>Status</Label>
          <Select value={status} onValueChange={(v) => { setStatus(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All</SelectItem>
              <SelectItem value="healthy">Healthy</SelectItem>
              <SelectItem value="at_risk">At risk</SelectItem>
              <SelectItem value="critical">Critical</SelectItem>
              <SelectItem value="na">N/A</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="w-48">
          <Label>Sort</Label>
          <Select value={sort} onValueChange={(v) => { setSort(v as FillRateSort); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="high_to_low">Fill rate: high to low</SelectItem>
              <SelectItem value="low_to_high">Fill rate: low to high</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="w-52">
          <Label>Shipping zone</Label>
          <Select value={shippingZoneId} onValueChange={(v) => { setShippingZoneId(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All zones</SelectItem>
              {(summary.data?.filters?.shipping_zones ?? []).map((zone) => (
                <SelectItem key={zone.acumatica_id} value={zone.acumatica_id}>
                  {shippingZoneLabel(zone)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="w-52">
          <Label>Customer group</Label>
          <Select value={customerGroup} onValueChange={(v) => { setCustomerGroup(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All customer groups</SelectItem>
              {(summary.data?.filters?.customer_groups ?? []).map((group) => (
                <SelectItem key={group} value={group}>{group}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="w-52">
          <Label>Product category</Label>
          <Select value={productLine} onValueChange={(v) => { setProductLine(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All product categories</SelectItem>
              {(summary.data?.filters?.product_lines ?? []).map((line) => (
                <SelectItem key={line} value={line}>{line}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="w-52">
          <Label>Reason</Label>
          <Select value={reasonCode} onValueChange={(v) => { setReasonCode(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All reasons</SelectItem>
              <SelectItem value="unassigned">Unassigned</SelectItem>
              {(summary.data?.filters?.reason_codes ?? []).map((reason) => (
                <SelectItem key={reason} value={reason}>{reasonLabel(reason)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
        <div className="w-52">
          <Label>Delivery SLA</Label>
          <Select value={deliverySla} onValueChange={(v) => { setDeliverySla(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All delivery times</SelectItem>
              <SelectItem value="breach">Over SLA</SelectItem>
              <SelectItem value="warning">Regional &gt;48h</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      <div className="rounded-lg border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-3 font-medium">Order</th>
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-4 py-3 font-medium">Products</th>
              <th className="px-4 py-3 font-medium">Status</th>
              <th className="px-4 py-3 font-medium">Delivery SLA</th>
              <th className="px-4 py-3 font-medium text-right">Ordered</th>
              <th className="px-4 py-3 font-medium text-right">Shipped</th>
              <th className="px-4 py-3 font-medium text-right">Fill rate</th>
              <th className="px-4 py-3 font-medium text-right">Not shipped (KES)</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && Array.from({ length: 6 }).map((_, i) => (
              <tr key={i}><td colSpan={9} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
            ))}
            {!isLoading && (data?.data ?? []).map((row) => (
              <tr key={row.id} className="border-b hover:bg-muted/20">
                <td className="px-4 py-3 font-medium">{row.order_nbr}</td>
                <td className="px-4 py-3">
                  <div>{row.customer_name ?? row.order?.customer_name ?? row.customer_acumatica_id ?? "—"}</div>
                  {row.order?.order_date && (
                    <div className="text-xs text-muted-foreground">{row.order.order_date.slice(0, 10)}</div>
                  )}
                </td>
                <td className="px-4 py-3">
                  {(row.products ?? []).length === 0 ? (
                    <span className="text-muted-foreground">—</span>
                  ) : (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 px-2 text-xs"
                      onClick={() => setSelectedOrder(row)}
                    >
                      <List className="mr-1.5 h-3.5 w-3.5" />
                      View products ({row.products!.length})
                    </Button>
                  )}
                </td>
                <td className="px-4 py-3 text-xs">{row.status ?? "—"}</td>
                <td className="px-4 py-3">
                  <DeliverySlaBadge row={row} />
                </td>
                <td className="px-4 py-3 text-right font-mono">{Number(row.total_ordered_qty).toLocaleString()}</td>
                <td className="px-4 py-3 text-right font-mono">{Number(row.total_shipped_qty).toLocaleString()}</td>
                <td className="px-4 py-3 text-right">
                  {row.fill_rate_pct != null ? (
                    <Badge variant={fillRateStatusColor(row.fill_rate_status)}>
                      {Number(row.fill_rate_pct).toFixed(1)}%
                    </Badge>
                  ) : (
                    <Badge variant="outline">N/A</Badge>
                  )}
                </td>
                <td className="px-4 py-3 text-right font-mono">
                  {Number(row.revenue_not_shipped).toLocaleString()}
                </td>
              </tr>
            ))}
            {!isLoading && (data?.data ?? []).length === 0 && (
              <tr><td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">No fill rate data — sync for the selected date range</td></tr>
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

      <Sheet open={!!selectedOrder} onOpenChange={(o) => !o && setSelectedOrder(null)}>
        <SheetContent className="w-full sm:max-w-lg overflow-y-auto">
          {selectedOrder && (
            <FillRateProductsSheet order={selectedOrder} />
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}

function reasonLabel(code: string | null | undefined) {
  if (!code) return "Unassigned";
  return code.replace(/_/g, " ").replace(/\b\w/g, (m) => m.toUpperCase());
}

function deliverySlaBadgeVariant(status: DeliverySlaStatus | undefined) {
  if (status === "breach") return "destructive" as const;
  if (status === "warning") return "secondary" as const;
  if (status === "ok") return "outline" as const;
  return "outline" as const;
}

function DeliverySlaBadge({ row }: { row: FillRateSnapshot }) {
  const status = row.delivery_sla_status ?? "unknown";
  const zone = row.shipping_zone_description ?? row.shipping_zone_id;

  return (
    <div className="space-y-1">
      <Badge variant={deliverySlaBadgeVariant(status)}>
        {status === "ok" ? "On time" : status === "warning" ? ">48h" : status === "breach" ? "Over SLA" : "—"}
      </Badge>
      {row.delivery_hours != null && (
        <div className="text-[11px] text-muted-foreground">{row.delivery_hours}h elapsed</div>
      )}
      {zone && <div className="text-[11px] text-muted-foreground">{zone}</div>}
      {row.delivery_sla_label && status !== "ok" && (
        <div className="text-[11px] text-muted-foreground">{row.delivery_sla_label}</div>
      )}
    </div>
  );
}

function FillRateExcelSummaryPanel({
  summary,
}: {
  summary: {
    totals: {
      actual_qty: number;
      ordered_qty: number;
      undershipped_qty: number;
      undershipped_value: number;
      fill_rate_pct: number | null;
      order_count: number;
    };
    by_status: ContributionRow[];
    by_reason: ContributionRow[];
    by_customer_group: ContributionRow[];
  };
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="mb-3 flex items-center gap-2">
        <BarChart3 className="h-4 w-4 text-cyan-600" />
        <h2 className="font-medium">Excel-style fill rate summary</h2>
      </div>
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <MetricTile label="Actual Qty" value={Number(summary.totals.actual_qty).toLocaleString()} tone="green" />
        <MetricTile label="Ordered Qty" value={Number(summary.totals.ordered_qty).toLocaleString()} tone="blue" />
        <MetricTile label="Undershipped Qty" value={Number(summary.totals.undershipped_qty).toLocaleString()} tone="amber" />
        <MetricTile label="Undershipped Value" value={formatKes(summary.totals.undershipped_value)} tone="red" />
        <MetricTile label="Fill Rate" value={summary.totals.fill_rate_pct != null ? `${summary.totals.fill_rate_pct}%` : "N/A"} tone="cyan" />
      </div>
      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <ContributionList title="Status contribution" rows={summary.by_status} labelKey="status" valueKey="undershipped_value" tone="blue" />
        <ContributionList title="Reason contribution" rows={summary.by_reason} labelKey="reason" valueKey="undershipped_value" tone="red" />
        <ContributionList title="Customer group contribution" rows={summary.by_customer_group} labelKey="customer_group" valueKey="undershipped_value" tone="cyan" />
      </div>
    </div>
  );
}

function MetricTile({
  label, value, tone,
}: {
  label: string;
  value: string;
  tone: "blue" | "green" | "amber" | "red" | "cyan";
}) {
  const color = {
    blue: "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300",
    green: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300",
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

function ContributionList({
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
              <span className="shrink-0 font-mono">{formatKes(Number(row[valueKey] ?? 0))}</span>
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

function FillRateProductsSheet({ order }: { order: FillRateSnapshot }) {
  const products = order.products ?? [];
  const lineTotal = products.reduce((sum, p) => sum + Number(p.not_shipped_value), 0);

  return (
    <>
      <SheetHeader>
        <SheetTitle>{order.order_nbr}</SheetTitle>
        <SheetDescription>
          {order.customer_name ?? order.customer_acumatica_id ?? "Unknown customer"}
          {order.order?.order_date && ` · ${order.order.order_date.slice(0, 10)}`}
        </SheetDescription>
      </SheetHeader>

      <div className="mt-4 flex flex-wrap gap-2">
        {order.fill_rate_pct != null && (
          <Badge variant={fillRateStatusColor(order.fill_rate_status)}>
            {Number(order.fill_rate_pct).toFixed(1)}% fill rate
          </Badge>
        )}
        <Badge variant="outline">{products.length} line{products.length !== 1 ? "s" : ""}</Badge>
      </div>

      <div className="mt-6 rounded-lg border">
        {products.length === 0 ? (
          <p className="p-3 text-sm text-muted-foreground">No line items — re-sync fill rate for this order.</p>
        ) : (
          <div className="divide-y">
            {products.map((p) => (
              <div key={p.inventory_id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <div className="min-w-0">
                  <div className="truncate font-medium">{p.product_name ?? p.inventory_id}</div>
                  <div className="truncate text-xs text-muted-foreground">
                    {qtyWithUom(p.order_qty, p.uom)} ordered · {qtyWithUom(p.qty_on_shipments, p.uom)} shipped
                    {p.unfilled_reason_code && ` · ${p.unfilled_reason_code.replace(/_/g, " ")}`}
                  </div>
                </div>
                <div className="shrink-0 text-right">
                  <div className="text-xs">
                    {p.line_fill_rate_pct != null ? (
                      <Badge variant="outline" className="px-1.5 py-0 text-[10px]">
                        {Number(p.line_fill_rate_pct).toFixed(0)}%
                      </Badge>
                    ) : (
                      <span className="text-muted-foreground">—</span>
                    )}
                  </div>
                  <div className="mt-0.5 font-mono text-xs font-medium">{formatKes(p.not_shipped_value)}</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {products.length > 0 && (
        <div className="mt-4 flex justify-between border-t pt-4 text-sm">
          <span className="text-muted-foreground">Sum of line not-shipped values</span>
          <span className="font-mono font-medium">{formatKes(lineTotal)}</span>
        </div>
      )}
      {products.length > 0 && Math.abs(lineTotal - Number(order.revenue_not_shipped)) > 1 && (
        <p className="mt-2 text-xs text-amber-600">
          Order-level not shipped ({formatKes(order.revenue_not_shipped)}) may differ due to discounts or rounding.
        </p>
      )}
    </>
  );
}

function Card({
  label, value, loading, icon: Icon, status, hint,
}: {
  label: string;
  value?: number | string;
  loading?: boolean;
  icon?: React.ComponentType<{ className?: string }>;
  status?: string;
  hint?: string;
}) {
  const color =
    status === "critical" ? "text-red-600" :
    status === "at_risk" ? "text-amber-600" :
    status === "healthy" ? "text-emerald-600" : "";

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        {Icon && <Icon className="h-4 w-4" />}
        {label}
      </div>
      {loading ? <Skeleton className="mt-2 h-8 w-16" /> : (
        <p className={`mt-1 text-2xl font-semibold ${color}`}>{value ?? "—"}</p>
      )}
      {hint && <p className="mt-1 text-[11px] text-muted-foreground">{hint}</p>}
    </div>
  );
}
