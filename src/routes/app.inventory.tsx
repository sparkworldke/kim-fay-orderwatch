import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { Boxes, ChevronDown, FileDown, RefreshCw, Search, TrendingDown } from "lucide-react";
import { useInventoryByWarehouse } from "@/hooks/useInventoryByWarehouse";
import { InventoryWarehouseView } from "@/components/inventory/InventoryWarehouseView";
import { SkuDetailPanel } from "@/components/inventory/SkuDetailPanel";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import { useStopSyncLog, useSyncLogs } from "@/hooks/admin/useAdminSettings";
import {
  formatOpsSyncToast,
  useInventory,
  useInventorySummary,
  useSyncInventory,
  useSyncInventoryStocks,
} from "@/hooks/useOperations";
import type { AcumaticaSyncLog } from "@/types/admin";
import { downloadApiFile } from "@/lib/api";

export const Route = createFileRoute("/app/inventory")({
  head: () => ({ meta: [{ title: "Inventory — Kim-Fay OrderWatch" }] }),
  component: InventoryPage,
});

const ACTIVE_SYNC_WINDOW_MS = 2 * 60 * 1000;
const INVENTORY_IMPORT_WAREHOUSES = ["DTC", "FGS", "PRMS", "RMS1", "TRMS"] as const;

function isActiveSyncLog(log: AcumaticaSyncLog) {
  if (log.status !== "running" || log.ended_at) {
    return false;
  }

  const pulseAt = log.heartbeat_at ?? log.started_at;
  const pulseMs = pulseAt ? new Date(pulseAt).getTime() : Number.NaN;

  return Number.isFinite(pulseMs) && Date.now() - pulseMs <= ACTIVE_SYNC_WINDOW_MS;
}

function findActiveSyncRun(logs: AcumaticaSyncLog[] | undefined, syncTypes: string[]) {
  return logs?.find((log) => syncTypes.includes(log.sync_type) && isActiveSyncLog(log)) ?? null;
}

function InventoryPage() {
  const [q, setQ] = useState("");
  const [lowStock, setLowStock] = useState(false);
  const [warehouseIds, setWarehouseIds] = useState<string[]>([]);
  const [predictionStatus, setPredictionStatus] = useState("all");
  const [productType, setProductType] = useState("all");
  const [importWarehouseId, setImportWarehouseId] = useState("FGS");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [isDownloading, setIsDownloading] = useState(false);
  const [selectedInventoryId, setSelectedInventoryId] = useState<string | null>(null);

  const summary = useInventorySummary();
  const { data, isLoading, refetch } = useInventory({
    q: q || undefined,
    low_stock: lowStock || undefined,
    warehouse_id: warehouseIds.length > 0 ? warehouseIds : undefined,
    prediction_status: predictionStatus !== "all" ? predictionStatus : undefined,
    product_type: productType !== "all" ? productType : undefined,
    page,
    per_page: perPage,
  });

  // Warehouse + band grouped view of the current page of inventory items.
  // Shares the same query key as `useInventory` so no duplicate requests.
  const warehouseView = useInventoryByWarehouse({
    q: q || undefined,
    low_stock: lowStock || undefined,
    warehouse_id: warehouseIds.length > 0 ? warehouseIds : undefined,
    prediction_status: predictionStatus !== "all" ? predictionStatus : undefined,
    product_type: productType !== "all" ? productType : undefined,
    page,
    per_page: perPage,
  });

  function toggleWarehouse(warehouse: string) {
    setWarehouseIds((current) => (
      current.includes(warehouse) ? current.filter((w) => w !== warehouse) : [...current, warehouse]
    ));
    setPage(1);
  }
  const sync = useSyncInventory();
  const syncStocks = useSyncInventoryStocks();
  const syncLogs = useSyncLogs();
  const stopSync = useStopSyncLog();
  const activeInventorySync = findActiveSyncRun(syncLogs.data, ["inventory"]);
  const activeStocksSync = findActiveSyncRun(syncLogs.data, ["inventory_stocks"]);
  const anySyncActive = !!activeInventorySync || !!activeStocksSync;

  function handleUpdate() {
    const body = importWarehouseId !== "all" ? { warehouse_id: importWarehouseId } : undefined;
    sync.mutate(body, {
      onSuccess: (res) => {
        if (res.sync_run.status === "completed") {
          toast.success(formatOpsSyncToast("Inventory", res.sync_run));
        } else if (res.sync_run.status === "stopped") {
          toast.warning(formatOpsSyncToast("Inventory", res.sync_run));
        } else if (res.sync_run.status === "running") {
          toast.info(formatOpsSyncToast("Inventory", res.sync_run));
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
    const body = importWarehouseId !== "all" ? { warehouse_id: importWarehouseId } : undefined;
    syncStocks.mutate(body, {
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
        summary.refetch();
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  async function handleDownload() {
    const qs = new URLSearchParams();
    if (q) qs.set("q", q);
    if (lowStock) qs.set("low_stock", "1");
    for (const warehouse of warehouseIds) qs.append("warehouse_id[]", warehouse);
    if (predictionStatus !== "all") qs.set("prediction_status", predictionStatus);
    if (productType !== "all") qs.set("product_type", productType);

    setIsDownloading(true);
    try {
      await downloadApiFile(`operations/inventory/export?${qs}`, `inventory-export-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`, { timeoutMs: 180_000 });
      toast.success("Inventory Excel download started.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to download inventory.");
    } finally {
      setIsDownloading(false);
    }
  }

  const anySyncPending = sync.isPending || syncStocks.isPending || anySyncActive;

  return (
    <div className="flex flex-col gap-6 p-6">
      <div className="flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 className="text-2xl font-semibold tracking-tight">Inventory</h1>
          <p className="text-sm text-muted-foreground">
            Stock levels from Acumatica — use Update to refresh existing items and add new ones
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div className="min-w-[150px]">
            <Label className="text-xs">Import warehouse</Label>
            <Select value={importWarehouseId} onValueChange={setImportWarehouseId}>
              <SelectTrigger className="h-9">
                <SelectValue placeholder="Warehouse" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All/default</SelectItem>
                {INVENTORY_IMPORT_WAREHOUSES.map((warehouse) => (
                  <SelectItem key={warehouse} value={warehouse}>{warehouse}</SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
          <Button variant="outline" onClick={handleSyncStocks} disabled={anySyncPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${syncStocks.isPending ? "animate-spin" : ""}`} />
            {syncStocks.isPending || activeStocksSync ? "Syncing stocks…" : "Sync stocks only"}
          </Button>
          <Button variant="outline" onClick={handleDownload} disabled={isDownloading}>
            <FileDown className={`mr-2 h-4 w-4 ${isDownloading ? "animate-pulse" : ""}`} />
            {isDownloading ? "Preparing..." : "Download Excel"}
          </Button>
          <Button onClick={handleUpdate} disabled={anySyncPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
            {sync.isPending || activeInventorySync ? "Updating…" : "Update inventory"}
          </Button>
          {activeStocksSync && (
            <Button
              variant="outline"
              onClick={() => stopSync.mutate(activeStocksSync.id)}
              disabled={stopSync.isPending}
            >
              Stop stocks sync
            </Button>
          )}
          {activeInventorySync && (
            <Button
              variant="outline"
              onClick={() => stopSync.mutate(activeInventorySync.id)}
              disabled={stopSync.isPending}
            >
              Stop inventory sync
            </Button>
          )}
        </div>
      </div>

      <OperationsSyncStatus />

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
          <Label>Warehouse</Label>
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="outline" className="w-full justify-between font-normal">
                <span className="truncate">
                  {warehouseIds.length === 0
                    ? "All warehouses"
                    : warehouseIds.length === 1
                      ? warehouseIds[0]
                      : `${warehouseIds.length} warehouses`}
                </span>
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-56">
              <DropdownMenuItem
                onSelect={(e) => { e.preventDefault(); setWarehouseIds([]); setPage(1); }}
                disabled={warehouseIds.length === 0}
              >
                All warehouses
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              {(summary.data?.warehouse_ids ?? []).map((warehouse) => (
                <DropdownMenuCheckboxItem
                  key={warehouse}
                  checked={warehouseIds.includes(warehouse)}
                  onSelect={(e) => e.preventDefault()}
                  onCheckedChange={() => toggleWarehouse(warehouse)}
                >
                  {warehouse}
                </DropdownMenuCheckboxItem>
              ))}
              {(summary.data?.warehouse_ids ?? []).length === 0 && (
                <DropdownMenuItem disabled>No warehouses yet</DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
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
        <div className="w-48">
          <Label>Product type</Label>
          <Select value={productType} onValueChange={(v) => { setProductType(v); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All products</SelectItem>
              <SelectItem value="manufactured">
                Manufactured{summary.data ? ` (${summary.data.manufactured_count})` : ""}
              </SelectItem>
              <SelectItem value="trading">
                Trading{summary.data ? ` (${summary.data.trading_count})` : ""}
              </SelectItem>
            </SelectContent>
          </Select>
        </div>
        <Button variant={lowStock ? "default" : "outline"} onClick={() => { setLowStock(!lowStock); setPage(1); }}>
          Low stock only
        </Button>
      </div>

      <InventoryWarehouseView
        items={warehouseView.items}
        onSkuClick={(item) => setSelectedInventoryId(item.inventory_id)}
        isLoading={warehouseView.isLoading}
        warehouseOptions={summary.data?.warehouse_ids}
      />

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

      <SkuDetailPanel
        inventoryId={selectedInventoryId}
        onClose={() => setSelectedInventoryId(null)}
      />
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
