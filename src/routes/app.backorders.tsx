import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { AlertTriangle, PackageX, RefreshCw, Search, Users } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  formatOpsSyncToast,
  useBackorders,
  useBackordersByAccount,
  useBackordersSummary,
  useSyncBackorders,
} from "@/hooks/useOperations";

export const Route = createFileRoute("/app/backorders")({
  head: () => ({ meta: [{ title: "Backorders — Kim-Fay OrderWatch" }] }),
  component: BackordersPage,
});

function formatKes(n: number | string) {
  return `KES ${Number(n).toLocaleString(undefined, { maximumFractionDigits: 0 })}`;
}

function BackordersPage() {
  const [q, setQ] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);

  const summary = useBackordersSummary();
  const accounts = useBackordersByAccount(10);
  const { data, isLoading, refetch } = useBackorders({ q: q || undefined, page, per_page: perPage });
  const sync = useSyncBackorders();

  function handleUpdate() {
    sync.mutate(undefined, {
      onSuccess: (res) => {
        if (res.sync_run.status === "completed") {
          toast.success(formatOpsSyncToast("Backorders", res.sync_run));
        } else {
          toast.error(formatOpsSyncToast("Backorders", res.sync_run));
        }
        refetch();
        summary.refetch();
        accounts.refetch();
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Backorders</h1>
          <p className="text-sm text-muted-foreground">
            Open backorder lines from Acumatica — use Update to refresh existing lines and add new ones
          </p>
        </div>
        <Button onClick={handleUpdate} disabled={sync.isPending}>
          <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
          {sync.isPending ? "Updating…" : "Update backorders"}
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <Kpi label="Open lines" value={summary.data?.open_lines} loading={summary.isLoading} icon={PackageX} />
        <Kpi label="Open orders" value={summary.data?.open_orders} loading={summary.isLoading} icon={AlertTriangle} />
        <Kpi
          label="Revenue at risk"
          value={summary.data ? formatKes(summary.data.revenue_at_risk) : undefined}
          loading={summary.isLoading}
          warn={(summary.data?.revenue_at_risk ?? 0) > 500_000}
          text
        />
        <Kpi
          label="Last synced"
          value={summary.data?.last_synced_at ? new Date(summary.data.last_synced_at).toLocaleString() : "—"}
          loading={summary.isLoading}
          text
        />
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
                  <div className="font-medium">{a.customer_name ?? a.customer_acumatica_id}</div>
                  <div className="text-xs text-muted-foreground">{a.customer_acumatica_id}</div>
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

      <div className="max-w-md">
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

      <div className="rounded-lg border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-3 font-medium">Order</th>
              <th className="px-4 py-3 font-medium">Item</th>
              <th className="px-4 py-3 font-medium">Customer</th>
              <th className="px-4 py-3 font-medium">Status</th>
              <th className="px-4 py-3 font-medium text-right">Ordered</th>
              <th className="px-4 py-3 font-medium text-right">Shipped</th>
              <th className="px-4 py-3 font-medium text-right">Open</th>
              <th className="px-4 py-3 font-medium text-right">Unit price</th>
              <th className="px-4 py-3 font-medium text-right">Rev at risk</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && Array.from({ length: 6 }).map((_, i) => (
              <tr key={i}><td colSpan={9} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
            ))}
            {!isLoading && (data?.data ?? []).map((row) => (
              <tr key={row.id} className="border-b hover:bg-muted/20">
                <td className="px-4 py-3 font-medium">{row.order_nbr}</td>
                <td className="px-4 py-3 font-mono">{row.inventory_id}</td>
                <td className="px-4 py-3">
                  <div>{row.customer_name ?? "—"}</div>
                  <div className="text-xs text-muted-foreground">{row.customer_acumatica_id}</div>
                </td>
                <td className="px-4 py-3 text-xs">{row.fulfillment_status ?? "—"}</td>
                <td className="px-4 py-3 text-right font-mono">{Number(row.order_qty).toLocaleString()}</td>
                <td className="px-4 py-3 text-right font-mono">{Number(row.shipped_qty).toLocaleString()}</td>
                <td className="px-4 py-3 text-right font-mono">{Number(row.open_qty).toLocaleString()}</td>
                <td className="px-4 py-3 text-right font-mono">{formatKes(row.unit_price)}</td>
                <td className="px-4 py-3 text-right font-medium">{formatKes(row.revenue_at_risk)}</td>
              </tr>
            ))}
            {!isLoading && (data?.data ?? []).length === 0 && (
              <tr><td colSpan={9} className="px-4 py-8 text-center text-muted-foreground">No backorder lines — sync from Acumatica to populate</td></tr>
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