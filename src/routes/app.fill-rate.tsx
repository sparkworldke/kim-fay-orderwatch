import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { Gauge, RefreshCw, Search } from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import {
  fillRateStatusColor,
  formatOpsSyncToast,
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

function FillRatePage() {
  const [q, setQ] = useState("");
  const [status, setStatus] = useState("all");
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);

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
                    <ul className="space-y-0.5">
                      {(row.products ?? []).slice(0, 3).map((p) => (
                        <li key={p.inventory_id}>
                          <div className="truncate max-w-[200px]">{p.product_name ?? p.inventory_id}</div>
                          <div className="text-xs text-muted-foreground font-mono">{p.inventory_id}</div>
                        </li>
                      ))}
                      {(row.products ?? []).length > 3 && (
                        <li className="text-xs text-muted-foreground">+{(row.products ?? []).length - 3} more</li>
                      )}
                    </ul>
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
    </div>
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