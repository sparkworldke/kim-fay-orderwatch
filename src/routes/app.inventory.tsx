import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { Boxes, RefreshCw, Search, TrendingDown } from "lucide-react";
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
  predictionStatusLabel,
  useInventory,
  useInventorySummary,
  useSyncInventory,
  useSyncInventoryStocks,
} from "@/hooks/useOperations";

export const Route = createFileRoute("/app/inventory")({
  head: () => ({ meta: [{ title: "Inventory — Kim-Fay OrderWatch" }] }),
  component: InventoryPage,
});

function InventoryPage() {
  const [q, setQ] = useState("");
  const [lowStock, setLowStock] = useState(false);
  const [predictionStatus, setPredictionStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);

  const summary = useInventorySummary();
  const { data, isLoading, refetch } = useInventory({
    q: q || undefined,
    low_stock: lowStock || undefined,
    prediction_status: predictionStatus !== "all" ? predictionStatus : undefined,
    page,
    per_page: perPage,
  });
  const sync = useSyncInventory();
  const syncStocks = useSyncInventoryStocks();

  function handleUpdate() {
    sync.mutate(undefined, {
      onSuccess: (res) => {
        if (res.sync_run.status === "completed") {
          toast.success(formatOpsSyncToast("Inventory", res.sync_run));
        } else {
          toast.error(formatOpsSyncToast("Inventory", res.sync_run));
        }
        refetch();
        summary.refetch();
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
        } else {
          toast.error(msg);
        }
        refetch();
        summary.refetch();
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  const anySyncPending = sync.isPending || syncStocks.isPending;

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Inventory</h1>
          <p className="text-sm text-muted-foreground">
            Stock levels from Acumatica — use Update to refresh existing items and add new ones
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" onClick={handleSyncStocks} disabled={anySyncPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${syncStocks.isPending ? "animate-spin" : ""}`} />
            {syncStocks.isPending ? "Syncing stocks…" : "Sync stocks only"}
          </Button>
          <Button onClick={handleUpdate} disabled={anySyncPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
            {sync.isPending ? "Updating…" : "Update inventory"}
          </Button>
        </div>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label="Total items" value={summary.data?.total_items} loading={summary.isLoading} icon={Boxes} />
        <StatCard label="Low stock (≤10)" value={summary.data?.low_stock_count} loading={summary.isLoading} icon={TrendingDown} />
        <StatCard label="At risk / critical" value={summary.data?.at_risk_count} loading={summary.isLoading} icon={TrendingDown} variant="warn" />
        <StatCard
          label="Last synced"
          value={summary.data?.last_synced_at ? new Date(summary.data.last_synced_at).toLocaleString() : "—"}
          loading={summary.isLoading}
          text
        />
      </div>

      <div className="flex flex-wrap items-end gap-3">
        <div className="flex-1 min-w-[200px]">
          <Label htmlFor="inv-search">Search</Label>
          <div className="relative">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              id="inv-search"
              className="pl-8"
              placeholder="Item ID or description…"
              value={q}
              onChange={(e) => { setQ(e.target.value); setPage(1); }}
            />
          </div>
        </div>
        <div className="w-44">
          <Label>Prediction</Label>
          <Select value={predictionStatus} onValueChange={(v) => { setPredictionStatus(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All</SelectItem>
              <SelectItem value="critical">Critical</SelectItem>
              <SelectItem value="at_risk">At risk</SelectItem>
              <SelectItem value="healthy">Healthy</SelectItem>
              <SelectItem value="insufficient_history">Needs history</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Button variant={lowStock ? "default" : "outline"} onClick={() => { setLowStock(!lowStock); setPage(1); }}>
          Low stock only
        </Button>
      </div>

      <div className="rounded-lg border">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-4 py-3 font-medium">Item</th>
              <th className="px-4 py-3 font-medium">Warehouse</th>
              <th className="px-4 py-3 font-medium">UOM</th>
              <th className="px-4 py-3 font-medium text-right">On hand</th>
              <th className="px-4 py-3 font-medium text-right">Run rate / day</th>
              <th className="px-4 py-3 font-medium text-right">Days left</th>
              <th className="px-4 py-3 font-medium">Prediction</th>
              <th className="px-4 py-3 font-medium">Synced</th>
            </tr>
          </thead>
          <tbody>
            {isLoading && Array.from({ length: 8 }).map((_, i) => (
              <tr key={i} className="border-b"><td colSpan={8} className="px-4 py-3"><Skeleton className="h-5 w-full" /></td></tr>
            ))}
            {!isLoading && (data?.data ?? []).map((item) => (
              <tr key={item.id} className="border-b hover:bg-muted/20">
                <td className="px-4 py-3">
                  <div className="font-medium">{item.inventory_id}</div>
                  <div className="text-xs text-muted-foreground truncate max-w-[240px]">{item.description ?? "—"}</div>
                </td>
                <td className="px-4 py-3">{item.default_warehouse_id ?? "—"}</td>
                <td className="px-4 py-3 text-xs">{item.default_uom ?? "—"}</td>
                <td className="px-4 py-3 text-right font-mono">{Number(item.qty_on_hand).toLocaleString()}</td>
                <td className="px-4 py-3 text-right font-mono">
                  {item.prediction?.daily_run_rate != null ? Number(item.prediction.daily_run_rate).toFixed(2) : "—"}
                </td>
                <td className="px-4 py-3 text-right font-mono">
                  {item.prediction?.days_until_stockout ?? "—"}
                </td>
                <td className="px-4 py-3">
                  {item.prediction?.prediction_status ? (
                    <Badge variant={fillRateStatusColor(item.prediction.prediction_status === "healthy" ? "healthy" : item.prediction.prediction_status === "at_risk" ? "at_risk" : item.prediction.prediction_status === "critical" ? "critical" : "na")}>
                      {predictionStatusLabel(item.prediction.prediction_status)}
                    </Badge>
                  ) : "—"}
                </td>
                <td className="px-4 py-3 text-xs text-muted-foreground">
                  {item.synced_at ? new Date(item.synced_at).toLocaleString() : "—"}
                </td>
              </tr>
            ))}
            {!isLoading && (data?.data ?? []).length === 0 && (
              <tr><td colSpan={8} className="px-4 py-8 text-center text-muted-foreground">No inventory items — run a sync to pull from Acumatica</td></tr>
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

function StatCard({
  label, value, loading, icon: Icon, variant, text,
}: {
  label: string;
  value?: number | string;
  loading?: boolean;
  icon?: React.ComponentType<{ className?: string }>;
  variant?: "warn";
  text?: boolean;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        {Icon && <Icon className="h-4 w-4" />}
        {label}
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-8 w-20" />
      ) : (
        <p className={`mt-1 text-2xl font-semibold ${variant === "warn" && typeof value === "number" && value > 0 ? "text-amber-600" : ""}`}>
          {text ? value : typeof value === "number" ? value.toLocaleString() : value ?? "—"}
        </p>
      )}
    </div>
  );
}