import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  Boxes,
  FileDown,
  PackageX,
  RefreshCw,
  TrendingDown,
  Warehouse,
} from "lucide-react";
import { InventoryWarehouseView } from "@/components/inventory/InventoryWarehouseView";
import { SkuDetailPanel } from "@/components/inventory/SkuDetailPanel";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import { BrandFilterCascade, type BrandFilterValue } from "@/components/filters/BrandFilterCascade";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import { useStopSyncLog, useSyncLogs } from "@/hooks/admin/useAdminSettings";
import {
  formatOpsSyncToast,
  type InventoryStockoutFilter,
  type InventoryWarehouseOption,
  useInventory,
  useInventorySummary,
  useSyncInventory,
  useSyncInventoryStocks,
} from "@/hooks/useOperations";
import type { InventoryItemExtended } from "@/hooks/useInventoryByWarehouse";
import type { AcumaticaSyncLog } from "@/types/admin";
import { downloadApiFile } from "@/lib/api";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/inventory")({
  head: () => ({ meta: [{ title: "Inventory — Kim-Fay OrderWatch" }] }),
  validateSearch: (search: Record<string, unknown>) => ({
    sku: typeof search.sku === "string" ? search.sku : undefined,
    tab: search.tab === "stockout" ? "stockout" as const : "all" as const,
  }),
  component: InventoryPage,
});

const ACTIVE_SYNC_WINDOW_MS = 2 * 60 * 1000;

/** Fallback Acumatica WarehouseIDs when summary has not loaded yet (config inventory.warehouses). */
const ACUMATICA_WAREHOUSES = [
  "DTC",
  "FGS",
  "FGS2",
  "FGS2 RETURNS",
  "MSA",
  "EXPORT",
  "PRMS",
  "RMS1",
  "TRMS",
] as const;

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

function warehouseLabel(w: InventoryWarehouseOption | string): string {
  if (typeof w === "string") return w;
  return w.label?.trim() || w.warehouse_id;
}

function InventoryPage() {
  const { sku: skuFromUrl, tab: tabFromUrl } = Route.useSearch();
  const navigate = Route.useNavigate();
  const [activeTab, setActiveTab] = useState<"all" | "stockout">(tabFromUrl ?? "all");
  const [q, setQ] = useState("");
  const [lowStock, setLowStock] = useState(false);
  const [selectedWarehouse, setSelectedWarehouse] = useState<string | null>(null);
  const [predictionStatus, setPredictionStatus] = useState("all");
  const [stockoutFilter, setStockoutFilter] = useState<InventoryStockoutFilter>("critical_or_oos");
  const [productType, setProductType] = useState("all");
  const [importWarehouseId, setImportWarehouseId] = useState("FGS");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [isDownloading, setIsDownloading] = useState(false);
  const [selectedInventoryId, setSelectedInventoryId] = useState<string | null>(skuFromUrl ?? null);
  const [brandFilter, setBrandFilter] = useState<BrandFilterValue>({
    partner_brand: "",
    brand: "",
    category: "",
  });

  useEffect(() => {
    if (skuFromUrl) {
      setSelectedInventoryId(skuFromUrl);
      setQ(skuFromUrl);
    }
  }, [skuFromUrl]);

  useEffect(() => {
    setActiveTab(tabFromUrl ?? "all");
  }, [tabFromUrl]);

  const isStockoutTab = activeTab === "stockout";

  const summary = useInventorySummary();
  const warehouses: InventoryWarehouseOption[] = useMemo(() => {
    const fromApi = summary.data?.warehouses ?? summary.data?.warehouse_counts;
    if (fromApi && fromApi.length > 0) return fromApi;
    return ACUMATICA_WAREHOUSES.map((id) => ({
      warehouse_id: id,
      label: id === "FGS2 RETURNS" ? "FGS2 Returns" : id === "EXPORT" ? "Export" : id,
      sku_count: 0,
      configured: true,
    }));
  }, [summary.data?.warehouses, summary.data?.warehouse_counts]);

  const { data, isLoading, refetch } = useInventory({
    q: q || undefined,
    low_stock: !isStockoutTab && lowStock ? true : undefined,
    warehouse_id: selectedWarehouse ? [selectedWarehouse] : undefined,
    prediction_status:
      !isStockoutTab && predictionStatus !== "all" ? predictionStatus : undefined,
    stockout_filter: isStockoutTab ? stockoutFilter : undefined,
    product_type: productType !== "all" ? productType : undefined,
    partner_brand: brandFilter.partner_brand || undefined,
    brand: brandFilter.brand || undefined,
    category: brandFilter.category || undefined,
    page,
    per_page: perPage,
  });

  const sync = useSyncInventory();
  const syncStocks = useSyncInventoryStocks();
  const syncLogs = useSyncLogs();
  const stopSync = useStopSyncLog();
  const activeInventorySync = findActiveSyncRun(syncLogs.data, ["inventory"]);
  const activeStocksSync = findActiveSyncRun(syncLogs.data, ["inventory_stocks"]);
  const anySyncActive = !!activeInventorySync || !!activeStocksSync;

  function handleTabChange(tab: string) {
    const next = tab === "stockout" ? "stockout" : "all";
    setActiveTab(next);
    setPage(1);
    // Reset filters that only apply on the all-inventory tab
    if (next === "stockout") {
      setLowStock(false);
      setPredictionStatus("all");
      setStockoutFilter("critical_or_oos");
    }
    void navigate({
      search: (prev) => ({ ...prev, tab: next }),
      replace: true,
    });
  }

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
    if (!isStockoutTab && lowStock) qs.set("low_stock", "1");
    if (selectedWarehouse) qs.append("warehouse_id[]", selectedWarehouse);
    if (!isStockoutTab && predictionStatus !== "all") qs.set("prediction_status", predictionStatus);
    if (isStockoutTab) qs.set("stockout_filter", stockoutFilter);
    if (productType !== "all") qs.set("product_type", productType);
    if (brandFilter.partner_brand) qs.set("partner_brand", brandFilter.partner_brand);
    if (brandFilter.brand) qs.set("brand", brandFilter.brand);
    if (brandFilter.category) qs.set("category", brandFilter.category);

    setIsDownloading(true);
    try {
      const suffix = isStockoutTab ? "stockout" : "inventory";
      await downloadApiFile(
        `operations/inventory/export?${qs}`,
        `${suffix}-export-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`,
        { timeoutMs: 180_000 },
      );
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
                {warehouses.map((warehouse) => (
                  <SelectItem key={warehouse.warehouse_id} value={warehouse.warehouse_id}>
                    {warehouseLabel(warehouse)}
                  </SelectItem>
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

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <StatCard label="Total items" value={summary.data?.total_items} loading={summary.isLoading} icon={Boxes} />
        <StatCard label="Low stock (≤10)" value={summary.data?.low_stock_count} loading={summary.isLoading} icon={TrendingDown} />
        <StatCard label="At risk / critical" value={summary.data?.at_risk_count} loading={summary.isLoading} icon={TrendingDown} variant="warn" />
        <StatCard
          label="Out of stock (0)"
          value={summary.data?.out_of_stock_count}
          loading={summary.isLoading}
          icon={PackageX}
          variant="danger"
        />
        <StatCard
          label="Last synced"
          value={summary.data?.last_synced_at ?? "—"}
          loading={summary.isLoading}
          text
          isDate
        />
      </div>

      <Tabs value={activeTab} onValueChange={handleTabChange}>
        <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1 sm:w-auto">
          <TabsTrigger value="all" className="gap-1.5">
            <Boxes className="h-4 w-4" />
            All inventory
          </TabsTrigger>
          <TabsTrigger value="stockout" className="gap-1.5">
            <AlertTriangle className="h-4 w-4" />
            Out of stock
            {(summary.data?.critical_stockout_count ?? 0) > 0 && (
              <Badge variant="destructive" className="ml-1 h-5 min-w-5 px-1.5 text-[10px]">
                {(summary.data?.critical_stockout_count ?? 0).toLocaleString()}
              </Badge>
            )}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="all" className="mt-4 space-y-4">
          <BrandFilterCascade
            value={brandFilter}
            onChange={(next) => {
              setBrandFilter(next);
              setPage(1);
            }}
            className="grid gap-3 sm:grid-cols-3 lg:grid-cols-3"
          />

          <div className="flex flex-wrap items-end gap-3">
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
            items={(data?.data ?? []) as InventoryItemExtended[]}
            onSkuClick={(item) => setSelectedInventoryId(item.inventory_id)}
            isLoading={isLoading}
            warehouseCounts={warehouses}
            selectedWarehouse={selectedWarehouse}
            onWarehouseSelect={(w) => { setSelectedWarehouse(w); setPage(1); }}
            searchInput={q}
            onSearchChange={(v) => { setQ(v); setPage(1); }}
          />
        </TabsContent>

        <TabsContent value="stockout" className="mt-4 space-y-4">
          <div className="rounded-lg border bg-card p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <h2 className="text-sm font-semibold">Stockout prediction</h2>
                <p className="mt-0.5 text-xs text-muted-foreground">
                  Critical run-rate risk (stockout ≤ 7 days) or completely out of stock (qty = 0).
                  Switch warehouse to focus on an Acumatica site.
                </p>
              </div>
              <div className="flex flex-wrap gap-2 text-xs">
                <Badge variant="outline" className="bg-red-50 text-red-700 border-red-200">
                  Out of stock: {(summary.data?.out_of_stock_count ?? 0).toLocaleString()}
                </Badge>
                <Badge variant="outline" className="bg-amber-50 text-amber-700 border-amber-200">
                  Critical + OOS: {(summary.data?.critical_stockout_count ?? 0).toLocaleString()}
                </Badge>
              </div>
            </div>

            <div className="mt-4 flex flex-wrap items-end gap-3">
              <div className="min-w-[180px]">
                <Label className="flex items-center gap-1.5">
                  <Warehouse className="h-3.5 w-3.5" />
                  Warehouse
                </Label>
                <Select
                  value={selectedWarehouse ?? "all"}
                  onValueChange={(v) => {
                    setSelectedWarehouse(v === "all" ? null : v);
                    setPage(1);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue placeholder="All warehouses" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All warehouses</SelectItem>
                    {warehouses.map((w) => (
                      <SelectItem key={w.warehouse_id} value={w.warehouse_id}>
                        {warehouseLabel(w)}
                        {w.sku_count > 0 ? ` (${w.sku_count.toLocaleString()} SKUs)` : ""}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <div className="min-w-[200px]">
                <Label>Risk focus</Label>
                <Select
                  value={stockoutFilter}
                  onValueChange={(v) => {
                    setStockoutFilter(v as InventoryStockoutFilter);
                    setPage(1);
                  }}
                >
                  <SelectTrigger>
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="critical_or_oos">Critical or out of stock</SelectItem>
                    <SelectItem value="critical">Critical only (≤ 7 days)</SelectItem>
                    <SelectItem value="out_of_stock">No stock completely (0)</SelectItem>
                    <SelectItem value="at_risk">At risk only (≤ 14 days)</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="w-48">
                <Label>Product type</Label>
                <Select value={productType} onValueChange={(v) => { setProductType(v); setPage(1); }}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All products</SelectItem>
                    <SelectItem value="manufactured">Manufactured</SelectItem>
                    <SelectItem value="trading">Trading</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </div>

            {/* Quick warehouse chips from Acumatica config + synced data */}
            <div className="mt-3 flex flex-wrap gap-1.5">
              <button
                type="button"
                onClick={() => { setSelectedWarehouse(null); setPage(1); }}
                className={cn(
                  "rounded-full border px-2.5 py-1 text-xs font-medium transition-colors",
                  selectedWarehouse === null
                    ? "border-primary bg-primary text-primary-foreground"
                    : "border-border bg-background hover:bg-muted",
                )}
              >
                All
              </button>
              {warehouses.map((w) => (
                <button
                  key={w.warehouse_id}
                  type="button"
                  onClick={() => {
                    setSelectedWarehouse(w.warehouse_id);
                    setPage(1);
                  }}
                  className={cn(
                    "rounded-full border px-2.5 py-1 text-xs font-medium transition-colors",
                    selectedWarehouse === w.warehouse_id
                      ? "border-primary bg-primary text-primary-foreground"
                      : "border-border bg-background hover:bg-muted",
                  )}
                  title={`${w.sku_count.toLocaleString()} SKUs`}
                >
                  {warehouseLabel(w)}
                </button>
              ))}
            </div>
          </div>

          <BrandFilterCascade
            value={brandFilter}
            onChange={(next) => {
              setBrandFilter(next);
              setPage(1);
            }}
            className="grid gap-3 sm:grid-cols-3 lg:grid-cols-3"
          />

          <InventoryWarehouseView
            items={(data?.data ?? []) as InventoryItemExtended[]}
            onSkuClick={(item) => setSelectedInventoryId(item.inventory_id)}
            isLoading={isLoading}
            warehouseCounts={warehouses}
            selectedWarehouse={selectedWarehouse}
            onWarehouseSelect={(w) => { setSelectedWarehouse(w); setPage(1); }}
            searchInput={q}
            onSearchChange={(v) => { setQ(v); setPage(1); }}
            stockoutMode
          />
        </TabsContent>
      </Tabs>

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
  label, value, loading, icon: Icon, variant, text, isDate,
}: {
  label: string;
  value?: number | string;
  loading?: boolean;
  icon?: React.ComponentType<{ className?: string }>;
  variant?: "warn" | "danger";
  text?: boolean;
  isDate?: boolean;
}) {
  const displayValue = isDate && typeof value === "string" && value !== "—"
    ? new Date(value).toLocaleString()
    : value;

  const valueClass =
    typeof value === "number" && value > 0
      ? variant === "danger"
        ? "text-red-600"
        : variant === "warn"
          ? "text-amber-600"
          : ""
      : "";

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-sm text-muted-foreground">
        {Icon && <Icon className="h-4 w-4" />}
        {label}
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-8 w-20" />
      ) : (
        <p className={`mt-1 text-2xl font-semibold ${valueClass}`}>
          {text ? displayValue : typeof value === "number" ? value.toLocaleString() : value ?? "—"}
        </p>
      )}
    </div>
  );
}
