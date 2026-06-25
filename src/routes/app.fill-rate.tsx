import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { Gauge, List, RefreshCw, Search } from "lucide-react";
import { toast } from "sonner";
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
  type FillRateSnapshot,
  useFillRate,
  useFillRateSummary,
  useSyncFillRate,
} from "@/hooks/useOperations";

export const Route = createFileRoute("/app/fill-rate")({
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
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("all");
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [selectedOrder, setSelectedOrder] = useState<FillRateSnapshot | null>(null);

  const summary = useFillRateSummary(dateFrom, dateTo);
  const { data, isLoading, refetch } = useFillRate({
    q: q || undefined,
    status: status !== "all" ? status : undefined,
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

  const overall = summary.data?.overall_fill_rate;

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Fill Rate</h1>
          <p className="text-sm text-muted-foreground">
            Unique-item rollup for the date range — use Update to refresh existing snapshots and add new orders
          </p>
        </div>
        <Button onClick={handleUpdate} disabled={sync.isPending}>
          <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
          {sync.isPending ? "Updating…" : "Update fill rate"}
        </Button>
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div>
          <Label htmlFor="fr-from">From</Label>
          <Input id="fr-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
        </div>
        <div>
          <Label htmlFor="fr-to">To</Label>
          <Input id="fr-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
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
      </div>

      {summary.data && (
        <p className="text-sm text-muted-foreground">
          Revenue not yet shipped: <span className="font-medium text-foreground">KES {summary.data.revenue_not_shipped.toLocaleString()}</span>
          {" · "}N/A orders: {summary.data.na_count}
        </p>
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
      </div>

      <div className="rounded-lg border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-3 font-medium">Order</th>
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-4 py-3 font-medium">Products</th>
              <th className="px-4 py-3 font-medium">Status</th>
              <th className="px-4 py-3 font-medium text-right">Ordered</th>
              <th className="px-4 py-3 font-medium text-right">Shipped</th>
              <th className="px-4 py-3 font-medium text-right">Fill rate</th>
              <th className="px-4 py-3 font-medium text-right">Not shipped (KES)</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && Array.from({ length: 6 }).map((_, i) => (
              <tr key={i}><td colSpan={8} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
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
              <tr><td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">No fill rate data — sync for the selected date range</td></tr>
            )}
          </tbody>
        </table>
      </div>

      {data && (
        <PaginationControls
          page={page}
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

      <div className="mt-6 space-y-3">
        {products.length === 0 ? (
          <p className="text-sm text-muted-foreground">No line items — re-sync fill rate for this order.</p>
        ) : (
          products.map((p) => (
            <div key={p.inventory_id} className="rounded-lg border p-3">
              <div className="font-medium">{p.product_name ?? p.inventory_id}</div>
              <div className="text-xs text-muted-foreground font-mono">{p.inventory_id}</div>
              <dl className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                <div>
                  <dt className="text-muted-foreground">Ordered</dt>
                  <dd className="font-mono">{qtyWithUom(p.order_qty, p.uom)}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Shipped</dt>
                  <dd className="font-mono">{qtyWithUom(p.shipped_qty, p.uom)}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Open</dt>
                  <dd className="font-mono">{qtyWithUom(p.open_qty, p.uom)}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Unit price</dt>
                  <dd className="font-mono">{formatKes(p.unit_price)}</dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Line fill rate</dt>
                  <dd>
                    {p.line_fill_rate_pct != null
                      ? `${Number(p.line_fill_rate_pct).toFixed(1)}%`
                      : "—"}
                  </dd>
                </div>
                <div>
                  <dt className="text-muted-foreground">Not shipped</dt>
                  <dd className="font-mono font-medium">{formatKes(p.not_shipped_value)}</dd>
                </div>
              </dl>
            </div>
          ))
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
  label, value, loading, icon: Icon, status,
}: {
  label: string;
  value?: number | string;
  loading?: boolean;
  icon?: React.ComponentType<{ className?: string }>;
  status?: string;
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
    </div>
  );
}