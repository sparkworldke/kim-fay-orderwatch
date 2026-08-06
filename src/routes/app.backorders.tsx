import { createFileRoute } from "@tanstack/react-router";
import { CustomerLink, OrderLink } from "@/components/entity-links";
import { ProductListingCell } from "@/components/inventory/ProductListingCell";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { useEffect, useMemo, useState } from "react";
import { FileDown, PackageX, PencilLine, RefreshCw, Search, Trash2, X } from "lucide-react";
import { toast } from "sonner";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { BrandFilterCascade, type BrandFilterValue } from "@/components/filters/BrandFilterCascade";
import { InfoTip } from "@/components/info-tip";
import { MaskedCurrency, useMaskedKESFormatter } from "@/components/MaskedCurrency";
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
import { Tabs, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Textarea } from "@/components/ui/textarea";
import {
  type BackorderLine,
  type BackorderResolutionLine,
  type ValueSummaryTotals,
  formatOpsSyncToast,
  useBackorders,
  useBackordersAnalytics,
  useBackordersSummary,
  useResolvedBackorders,
  useSyncBackorders,
  useTruncateBackorders,
  useUpdateBackorderReason,
} from "@/hooks/useOperations";
import { formatDate, formatNumber } from "@/lib/format";
import { useAuth } from "@/lib/auth";
import { downloadApiFile } from "@/lib/api";
import { useQueueExportDownload } from "@/hooks/useExportDownloads";
import { DATE_PRESETS, type DatePresetId, resolveDatePreset } from "@/lib/date-presets";
import {
  APPROVED_SUB_REASONS,
  REJECTION_REASON_OPTIONS,
  subReasonLabel,
} from "@/lib/order-reasons";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/backorders")({
  head: () => ({ meta: [{ title: "Backorders — Kim-Fay Sight" }] }),
  component: BackordersPage,
});

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function today() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

function reasonLabel(code: string | null | undefined) {
  if (!code) return "Unassigned";
  return subReasonLabel(code) ?? APPROVED_SUB_REASONS[code] ?? code.replaceAll("_", " ");
}

/** Split multi-value SO reason codes into display labels. */
function lineReasonLabels(line: Pick<BackorderLine, "reason_code" | "reason_notes">): string[] {
  if (!line.reason_code?.trim()) return [];
  const code = line.reason_code.trim();
  if (code.toLowerCase() === "unassigned") return [];
  return code
    .split(/[,;|]+/)
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => reasonLabel(part));
}

function isUnassignedReason(line: Pick<BackorderLine, "reason_code">): boolean {
  const code = line.reason_code?.trim();
  return !code || code.toLowerCase() === "unassigned";
}

function orderStatusLabel(status: string | null | undefined): string {
  if (!status?.trim()) return "—";
  return status.trim();
}

function orderStatusBadgeClass(status: string | null | undefined): string {
  const normalized = (status ?? "").toLowerCase().trim();
  if (!normalized) return "bg-muted text-muted-foreground";
  if (normalized === "completed" || normalized === "closed" || normalized === "invoiced") {
    return "border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200";
  }
  if (normalized.includes("ship") || normalized === "shipping" || normalized === "partially shipped") {
    return "border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-200";
  }
  if (normalized.includes("back") || normalized === "open" || normalized === "on hold" || normalized === "credit hold") {
    return "border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200";
  }
  if (normalized.includes("cancel") || normalized === "rejected") {
    return "border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950/40 dark:text-red-200";
  }
  return "border-border bg-muted/50 text-foreground";
}

// ---------------------------------------------------------------------------
// Group backorder lines by Inventory ID
// ---------------------------------------------------------------------------

type InventoryBackorderGroup = {
  inventoryId: string;
  productName: string | null;
  brand: string | null;
  subTradingGroup: string | null;
  postingClass: string | null;
  supplier: string | null;
  productLine: string | null;
  uom: string | null;
  orderCount: number;
  customerCount: number;
  totalOpenQty: number;
  totalOrderQty: number;
  totalShippedQty: number;
  totalRevenueAtRisk: number;
  lines: BackorderLine[];
};

function groupBackordersByInventory(lines: BackorderLine[]): InventoryBackorderGroup[] {
  const groups = new Map<string, InventoryBackorderGroup>();

  for (const line of lines) {
    const key = line.inventory_id || "UNKNOWN";
    let group = groups.get(key);
    if (!group) {
      group = {
        inventoryId: key,
        productName: line.product_name,
        brand: line.brand ?? null,
        subTradingGroup: line.sub_trading_group ?? null,
        postingClass: line.posting_class ?? null,
        supplier: line.supplier ?? null,
        productLine: line.product_line,
        uom: line.uom,
        orderCount: 0,
        customerCount: 0,
        totalOpenQty: 0,
        totalOrderQty: 0,
        totalShippedQty: 0,
        totalRevenueAtRisk: 0,
        lines: [],
      };
      groups.set(key, group);
    }
    group.totalOpenQty += Number(line.backorder_qty) || 0;
    group.totalOrderQty += Number(line.order_qty) || 0;
    group.totalShippedQty += Number(line.shipped_qty) || 0;
    group.totalRevenueAtRisk += Number(line.revenue_at_risk) || 0;
    group.lines.push(line);
  }

  for (const group of groups.values()) {
    group.orderCount = new Set(group.lines.map((line) => line.order_nbr)).size;
    group.customerCount = new Set(
      group.lines
        .map((line) => line.customer_acumatica_id ?? line.customer_name ?? "")
        .filter(Boolean),
    ).size;
    // Sort lines: highest revenue first, then SO number
    group.lines.sort((a, b) => {
      const rev = (Number(b.revenue_at_risk) || 0) - (Number(a.revenue_at_risk) || 0);
      if (rev !== 0) return rev;
      return (a.order_nbr ?? "").localeCompare(b.order_nbr ?? "");
    });
  }

  return Array.from(groups.values()).sort((a, b) => b.totalRevenueAtRisk - a.totalRevenueAtRisk);
}

const GROUPS_PAGE_SIZE_OPTIONS = [25, 50, 100];

function BackordersPage() {
  const kes = useMaskedKESFormatter();
  const { session } = useAuth();

  const [q, setQ] = useState("");
  /** Debounced search text sent to the API (avoids a request per keystroke). */
  const [qDebounced, setQDebounced] = useState("");
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [datePreset, setDatePreset] = useState<DatePresetId>("this_month");
  const [productLine, setProductLine] = useState("all");
  const [customerGroup, setCustomerGroup] = useState("all");
  const [warehouseId, setWarehouseId] = useState("all");
  const [reasonCode, setReasonCode] = useState("all");
  const [fulfillmentStatus, setFulfillmentStatus] = useState("all");
  const [shortfallKind, setShortfallKind] = useState<"all" | "active_backorder" | "completed_shortfall">("all");
  const [productSegment, setProductSegment] = useState<"all" | "manufactured" | "trading">("all");
  const [customerSegment, setCustomerSegment] = useState<"all" | "KP" | "CS">("all");
  const [brandFilter, setBrandFilter] = useState<BrandFilterValue>({
    partner_brand: "",
    brand: "",
    category: "",
  });
  const [clearDialogOpen, setClearDialogOpen] = useState(false);
  const [clearMode, setClearMode] = useState<"range" | "all">("range");
  const [view, setView] = useState<"active" | "resolved">("active");

  // Fetch a large page so client-side InventoryID grouping is complete for the filter set.
  const [groupPage, setGroupPage] = useState(1);
  const [groupsPerPage, setGroupsPerPage] = useState(25);
  /** Accordion values expanded (auto-open when search matches a few SKUs). */
  const [expandedGroups, setExpandedGroups] = useState<string[]>([]);

  const [editingLine, setEditingLine] = useState<BackorderLine | null>(null);
  const [reasonDraftCode, setReasonDraftCode] = useState("none");
  const [reasonDraftNotes, setReasonDraftNotes] = useState("");
  const [isDownloading, setIsDownloading] = useState(false);
  const [isQueuingDownload, setIsQueuingDownload] = useState(false);
  const queueExport = useQueueExportDownload();

  useEffect(() => {
    const handle = window.setTimeout(() => {
      setQDebounced(q.trim());
      setGroupPage(1);
    }, 300);
    return () => window.clearTimeout(handle);
  }, [q]);

  const searchActive = qDebounced.length > 0;
  // When searching SO / customer / product, ignore date range so June SOs still appear in July.
  const filterParams = {
    q: searchActive ? qDebounced : undefined,
    date_from: searchActive ? undefined : dateFrom || undefined,
    date_to: searchActive ? undefined : dateTo || undefined,
    product_line: productLine !== "all" ? productLine : undefined,
    customer_group: customerGroup !== "all" ? customerGroup : undefined,
    warehouse_id: warehouseId !== "all" ? warehouseId : undefined,
    reason_code: reasonCode !== "all" ? reasonCode : undefined,
    fulfillment_status: fulfillmentStatus !== "all" ? fulfillmentStatus : undefined,
    shortfall_kind: shortfallKind !== "all" ? shortfallKind : undefined,
    partner_brand: brandFilter.partner_brand || undefined,
    brand: brandFilter.brand || undefined,
    category: brandFilter.category || undefined,
    segment: customerSegment !== "all" ? customerSegment : undefined,
    product_segment: productSegment !== "all" ? productSegment : undefined,
  };

  // KPI cards use active shortfalls when the user has not picked a kind filter.
  const shortfallForKpis =
    shortfallKind === "all" ? "active_backorder" : shortfallKind;
  const summary = useBackordersSummary({
    ...filterParams,
    shortfall_kind: shortfallForKpis,
  });
  const analytics = useBackordersAnalytics({
    ...filterParams,
    shortfall_kind: shortfallForKpis,
  });
  // Cap page size to avoid gateway 504s on large months. KPI SKU count still
  // comes from the summary API (COUNT DISTINCT). Narrow filters (brand/dates)
  // when the table is truncated.
  const { data, isLoading, isFetching, refetch } = useBackorders({
    ...filterParams,
    shortfall_kind: shortfallForKpis,
    page: 1,
    per_page: 2500,
  });
  const sync = useSyncBackorders();
  const truncateBackorders = useTruncateBackorders();
  const updateBackorderReason = useUpdateBackorderReason();
  const isAdmin = session?.role === "Administrator";
  const canAssignReasons =
    isAdmin ||
    session?.role === "Customer Service Manager" ||
    session?.role === "Sales Operations";

  function openReasonEditor(line: BackorderLine) {
    setEditingLine(line);
    setReasonDraftCode(line.reason_code && !isUnassignedReason(line) ? line.reason_code : "none");
    setReasonDraftNotes(line.reason_notes ?? "");
  }

  function saveReason() {
    if (!editingLine) return;
    if (reasonDraftCode === "none") {
      toast.error("Select a root cause before saving.");
      return;
    }

    updateBackorderReason.mutate(
      {
        id: editingLine.id,
        reason_code: reasonDraftCode,
        reason_notes: reasonDraftNotes.trim() || null,
      },
      {
        onSuccess: () => {
          toast.success("Backorder reason saved.");
          setEditingLine(null);
          refetch();
          summary.refetch();
          analytics.refetch();
        },
        onError: (error: Error) => toast.error(error.message),
      },
    );
  }

  function handleClearData() {
    if (clearMode === "range" && (!dateFrom || !dateTo || dateFrom > dateTo)) {
      toast.error("Select a valid date range before clearing data.");
      return;
    }
    truncateBackorders.mutate(clearMode === "all" ? { clear_all: true } : { date_from: dateFrom, date_to: dateTo }, {
      onSuccess: (res) => {
        toast.success(res.message);
        setClearDialogOpen(false);
      },
      onError: (e: Error) => toast.error(e.message),
    });
  }

  function resetGroupPage() {
    setGroupPage(1);
  }

  function clearSearch() {
    setQ("");
    setQDebounced("");
    resetGroupPage();
    setExpandedGroups([]);
  }

  function applyDatePreset(preset: DatePresetId) {
    setDatePreset(preset);
    if (preset !== "custom") {
      const range = resolveDatePreset(preset);
      setDateFrom(range.from);
      setDateTo(range.to);
      resetGroupPage();
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

    sync.mutate(
      { date_from: dateFrom, date_to: dateTo },
      {
        onSuccess: (res) => {
          if (res.sync_run.status === "completed") {
            toast.success(formatOpsSyncToast("Backorders", res.sync_run));
          } else if (res.sync_run.status === "stopped") {
            toast.warning(formatOpsSyncToast("Backorders", res.sync_run));
          } else if (res.sync_run.status === "running") {
            toast.info(
              res.message ??
                "Backorder import started in the background. Figures refresh as Acumatica data arrives (large ranges can take several minutes).",
            );
          } else {
            toast.error(formatOpsSyncToast("Backorders", res.sync_run));
          }
          refetch();
          summary.refetch();
          analytics.refetch();
        },
        onError: (e: Error) => toast.error(e.message),
      },
    );
  }

  function exportFilters(): Record<string, string> {
    const filters: Record<string, string> = {};
    if (q) filters.q = q;
    if (dateFrom) filters.date_from = dateFrom;
    if (dateTo) filters.date_to = dateTo;
    if (productLine !== "all") filters.product_line = productLine;
    if (customerGroup !== "all") filters.customer_group = customerGroup;
    if (warehouseId !== "all") filters.warehouse_id = warehouseId;
    if (reasonCode !== "all") filters.reason_code = reasonCode;
    if (fulfillmentStatus !== "all") filters.fulfillment_status = fulfillmentStatus;
    if (brandFilter.partner_brand) filters.partner_brand = brandFilter.partner_brand;
    if (brandFilter.brand) filters.brand = brandFilter.brand;
    if (brandFilter.category) filters.category = brandFilter.category;
    if (customerSegment !== "all") filters.segment = customerSegment;
    if (productSegment !== "all") filters.product_segment = productSegment;
    return filters;
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

    const qs = new URLSearchParams(exportFilters());
    setIsDownloading(true);
    try {
      await downloadApiFile(
        `operations/backorders/export?${qs}`,
        `backorders-export-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`,
        { timeoutMs: 180_000 },
      );
      toast.success("Backorders Excel download started.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to download backorders.");
    } finally {
      setIsDownloading(false);
    }
  }

  async function handleQueueDownload() {
    if (!dateFrom || !dateTo) {
      toast.error("Select dates before downloading.");
      return;
    }
    if (dateFrom > dateTo) {
      toast.error("Start date must be before end date.");
      return;
    }
    setIsQueuingDownload(true);
    try {
      const res = await queueExport.mutateAsync({
        type: "backorders",
        filters: exportFilters(),
      });
      toast.success(res.message || "Export queued. Open Downloads when ready.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to queue export.");
    } finally {
      setIsQueuingDownload(false);
    }
  }

  const allGroups = useMemo(() => {
    const groups = groupBackordersByInventory(data?.data ?? []);
    if (!searchActive) return groups;

    // Extra client filter: keep groups that still match after API filter (safety net).
    const needle = qDebounced.toLowerCase();
    const digits = qDebounced.replace(/\D+/g, "");
    return groups
      .map((group) => {
        const lines = group.lines.filter((line) => {
          const hay = [
            line.order_nbr,
            line.customer_name,
            line.customer_acumatica_id,
            line.inventory_id,
            line.product_name,
            line.product_line,
          ]
            .filter(Boolean)
            .join(" ")
            .toLowerCase();
          if (hay.includes(needle)) return true;
          if (digits && (line.order_nbr ?? "").includes(digits)) return true;
          return false;
        });
        if (lines.length === 0) return null;
        const totalOpenQty = lines.reduce(
          (sum, line) =>
            sum +
            (Number(line.open_qty) > 0 ? Number(line.open_qty) : Number(line.backorder_qty) || 0),
          0,
        );
        const totalRevenueAtRisk = lines.reduce((sum, line) => {
          return sum + (Number(line.revenue_at_risk) || 0);
        }, 0);
        const totalOrderQty = lines.reduce((sum, line) => sum + (Number(line.order_qty) || 0), 0);
        const totalShippedQty = lines.reduce(
          (sum, line) => sum + (Number(line.shipped_qty) || 0),
          0,
        );
        return {
          ...group,
          lines,
          totalOrderQty,
          totalShippedQty,
          orderCount: new Set(lines.map((l) => l.order_nbr)).size,
          customerCount: new Set(
            lines.map((l) => l.customer_acumatica_id ?? l.customer_name ?? "").filter(Boolean),
          ).size,
          totalOpenQty,
          totalRevenueAtRisk,
        };
      })
      .filter((g): g is InventoryBackorderGroup => g !== null);
  }, [data, searchActive, qDebounced]);

  // Auto-expand when search hits a small number of SKUs so SO rows are visible.
  useEffect(() => {
    if (!searchActive) {
      setExpandedGroups([]);
      return;
    }
    if (allGroups.length > 0 && allGroups.length <= 15) {
      setExpandedGroups(allGroups.map((g) => g.inventoryId));
    } else {
      setExpandedGroups([]);
    }
  }, [searchActive, allGroups]);

  const totalGroups = allGroups.length;
  const lastGroupPage = Math.max(1, Math.ceil(totalGroups / groupsPerPage));
  const safeGroupPage = Math.min(groupPage, lastGroupPage);
  const pagedGroups = useMemo(() => {
    const start = (safeGroupPage - 1) * groupsPerPage;
    return allGroups.slice(start, start + groupsPerPage);
  }, [allGroups, safeGroupPage, groupsPerPage]);

  const filteredSummary = analytics.data?.summary;
  const valueSummary = summary.data?.value_summary;
  // Authoritative SKU count = SQL COUNT(DISTINCT inventory_id) on filtered open lines.
  // Do not use client-side group count of a paginated list (that under-counted ~291 vs ~518).
  const skuCount =
    summary.data?.open_skus ??
    filteredSummary?.open_skus ??
    totalGroups;
  const lineTotal = data?.total ?? 0;
  const truncated = lineTotal > (data?.data?.length ?? 0);

  return (
    <div className="flex flex-col gap-6">
      {/* Header */}
      <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <h1 className="text-2xl font-semibold tracking-tight">Backorders</h1>
          <p className="text-sm text-muted-foreground">
            Short products grouped by Inventory ID (SKU). Date filters use sales order date. Expand a
            product to see each sales order, status, and reason.
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          {isAdmin && (
            <Button
              variant="outline"
              className="text-destructive hover:bg-destructive/10 hover:text-destructive"
              onClick={() => setClearDialogOpen(true)}
              disabled={truncateBackorders.isPending}
            >
              <Trash2 className="mr-2 h-4 w-4" />
              {truncateBackorders.isPending ? "Clearing…" : "Clear data"}
            </Button>
          )}
          <Button variant="outline" onClick={handleDownload} disabled={isDownloading || isQueuingDownload}>
            <FileDown className={`mr-2 h-4 w-4 ${isDownloading ? "animate-pulse" : ""}`} />
            {isDownloading ? "Preparing…" : "Download Excel"}
          </Button>
          <Button
            variant="secondary"
            onClick={() => void handleQueueDownload()}
            disabled={isDownloading || isQueuingDownload}
          >
            <FileDown className={`mr-2 h-4 w-4 ${isQueuingDownload ? "animate-pulse" : ""}`} />
            {isQueuingDownload ? "Queuing…" : "Queue download"}
          </Button>
          <Button onClick={handleUpdate} disabled={sync.isPending}>
            <RefreshCw className={`mr-2 h-4 w-4 ${sync.isPending ? "animate-spin" : ""}`} />
            {sync.isPending ? "Updating…" : "Update backorders"}
          </Button>
        </div>
      </div>

      {isAdmin && (
        <AlertDialog open={clearDialogOpen} onOpenChange={setClearDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>Clear backorder data?</AlertDialogTitle>
              <AlertDialogDescription>
                This permanently deletes backorder lines from OrderWatch. Sales orders, inventory, and fill-rate data are not touched.
              </AlertDialogDescription>
              <div className="grid gap-2 pt-2">
                <Label>Clear scope</Label>
                <Select value={clearMode} onValueChange={(value) => setClearMode(value as "range" | "all")}>
                  <SelectTrigger><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="range">Selected range: {dateFrom} to {dateTo}</SelectItem>
                    <SelectItem value="all">All backorder data</SelectItem>
                  </SelectContent>
                </Select>
              </div>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>Cancel</AlertDialogCancel>
              <AlertDialogAction
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                onClick={handleClearData}
              >
                {clearMode === "all" ? "Clear all backorders" : "Clear selected range"}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      )}

      <OperationsSyncStatus />

      <Tabs value={view} onValueChange={(value) => setView(value as "active" | "resolved")}>
        <TabsList>
          <TabsTrigger value="active">Active</TabsTrigger>
          <TabsTrigger value="resolved">Resolved</TabsTrigger>
        </TabsList>
      </Tabs>

      {view === "resolved" && <ResolvedBackordersPanel />}

      {view === "active" && (
      <>
      {/* Filters */}
      <div className="rounded-lg border bg-card p-4 shadow-sm">
        <div className="mb-3 flex items-center justify-between gap-2">
          <h2 className="text-sm font-medium">Filters</h2>
          <p className="text-xs text-muted-foreground">
            {searchActive
              ? "Search is active — date range ignored so SO/customer/product can match any month"
              : "Defaults to current month to date"}
          </p>
        </div>

        <BrandFilterCascade
          value={brandFilter}
          onChange={(next) => {
            setBrandFilter(next);
            // Keep Manufactured/Trading segment cards in sync with brand group.
            if (next.partner_brand === "manufactured") {
              setProductSegment("manufactured");
            } else if (next.partner_brand === "trading") {
              setProductSegment("trading");
            } else if (!next.partner_brand && !next.brand) {
              setProductSegment("all");
            }
            resetGroupPage();
          }}
        />

        <div className="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <div className="xl:col-span-2">
            <Label htmlFor="bo-search">Search SO / Customer / Product</Label>
            <div className="relative">
              <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                id="bo-search"
                className="pl-8 pr-9"
                placeholder="SO359099, customer name, inventory ID, product name…"
                value={q}
                onChange={(e) => setQ(e.target.value)}
              />
              {q && (
                <button
                  type="button"
                  onClick={clearSearch}
                  className="absolute right-2 top-2 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                  aria-label="Clear search"
                >
                  <X className="h-4 w-4" />
                </button>
              )}
            </div>
            <p className="mt-1 text-[11px] text-muted-foreground">
              Finds by sales order number, customer ID/name, inventory ID, or product name.
              {searchActive && " Date filters are off while searching."}
            </p>
          </div>

          <div>
            <Label>Date preset</Label>
            <Select
              value={datePreset}
              onValueChange={(value) => applyDatePreset(value as DatePresetId)}
            >
              <SelectTrigger>
                <SelectValue placeholder="Date preset" />
              </SelectTrigger>
              <SelectContent>
                {DATE_PRESETS.map((preset) => (
                  <SelectItem key={preset.id} value={preset.id}>
                    {preset.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid grid-cols-2 gap-2">
            <div>
              <Label htmlFor="bo-from">From</Label>
              <Input
                id="bo-from"
                type="date"
                value={dateFrom}
                onChange={(e) => {
                  setDatePreset("custom");
                  setDateFrom(e.target.value);
                  resetGroupPage();
                }}
              />
            </div>
            <div>
              <Label htmlFor="bo-to">To</Label>
              <Input
                id="bo-to"
                type="date"
                value={dateTo}
                onChange={(e) => {
                  setDatePreset("custom");
                  setDateTo(e.target.value);
                  resetGroupPage();
                }}
              />
            </div>
          </div>

          <div>
            <Label>Product line</Label>
            <Select
              value={productLine}
              onValueChange={(value) => {
                setProductLine(value);
                resetGroupPage();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="All product lines" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All product lines</SelectItem>
                {(analytics.data?.filters.product_lines ?? []).map((line) => (
                  <SelectItem key={line} value={line}>
                    {line}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>Customer group</Label>
            <Select
              value={customerGroup}
              onValueChange={(value) => {
                setCustomerGroup(value);
                resetGroupPage();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="All customer groups" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All customer groups</SelectItem>
                {(analytics.data?.filters.customer_groups ?? []).map((group) => (
                  <SelectItem key={group} value={group}>
                    {group}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>Warehouse</Label>
            <Select
              value={warehouseId}
              onValueChange={(value) => {
                setWarehouseId(value);
                resetGroupPage();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="All warehouses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All warehouses</SelectItem>
                {(analytics.data?.filters.warehouse_ids ?? []).map((warehouse) => (
                  <SelectItem key={warehouse} value={warehouse}>
                    {warehouse}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>Order state</Label>
            <Select
              value={shortfallKind}
              onValueChange={(value) => {
                setShortfallKind(value as typeof shortfallKind);
                resetGroupPage();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="All shortfalls" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All shortfalls</SelectItem>
                <SelectItem value="active_backorder">Active backorders</SelectItem>
                <SelectItem value="completed_shortfall">Completed shortfalls</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>Root cause</Label>
            <Select
              value={reasonCode}
              onValueChange={(value) => {
                setReasonCode(value);
                resetGroupPage();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="All reasons" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All reasons (from SO)</SelectItem>
                <SelectItem value="unassigned">Unassigned</SelectItem>
                {(analytics.data?.filters.reason_codes ?? []).map((code) => (
                  <SelectItem key={code} value={code}>
                    {reasonLabel(code)}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div>
            <Label>Fulfillment status</Label>
            <Select
              value={fulfillmentStatus}
              onValueChange={(value) => {
                setFulfillmentStatus(value);
                resetGroupPage();
              }}
            >
              <SelectTrigger>
                <SelectValue placeholder="All fulfillment statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All fulfillment statuses</SelectItem>
                {(analytics.data?.filters.fulfillment_statuses ?? []).map((status) => (
                  <SelectItem key={status} value={status}>
                    {status}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>

      {/* Key guide — plain-English meanings for the value cards and terms */}
      <BackorderKeyGuide />

      {/* Revenue value cards: order vs invoiced vs backorder */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <ValueCard
          label="Backorder value"
          value={valueSummary?.backorder_value}
          loading={summary.isLoading}
          hint="Still not delivered · open qty × unit price"
          tone="destructive"
          tip="Money still outstanding: open quantity × unit price on the same filtered shortfall lines as the table and Excel."
        />
        <ValueCard
          label="Invoiced value"
          value={valueSummary?.invoiced_value}
          loading={summary.isLoading}
          hint="Already shipped · shipped qty × unit price"
          tone="positive"
          tip="Money already delivered on those same shortfall lines: shipped quantity × unit price."
        />
        <ValueCard
          label="Order value"
          value={valueSummary?.order_value}
          loading={summary.isLoading}
          hint="What was ordered · ordered qty × unit price"
          tip="Full ordered amount on those same shortfall lines: ordered quantity × unit price. Ideally close to Invoiced + Backorder."
        />
      </div>

      <div className="grid gap-3 sm:grid-cols-3">
        <ValueCard
          label="Revenue at risk · true FGS stockout"
          value={summary.data?.stock_diagnosis.rar_true_stockout}
          loading={summary.isLoading}
          hint="FGS on hand is zero · open qty × unit price"
          tone="destructive"
          tip="Revenue at risk (open qty × unit price) where the latest FGS stock snapshot has no on-hand stock."
        />
        <ValueCard
          label="Revenue at risk · partial FGS cover"
          value={summary.data?.stock_diagnosis.rar_partial_cover}
          loading={summary.isLoading}
          hint="Some stock, but below backorder qty · open qty × unit price"
          tip="Revenue at risk (open qty × unit price) where FGS stock covers only part of the current uncommitted shortage."
        />
        <ValueCard
          label="Revenue at risk · stock available"
          value={summary.data?.stock_diagnosis.rar_stock_available_not_shipped}
          loading={summary.isLoading}
          hint="Check pick, allocation, or logistics · open qty × unit price"
          tone="positive"
          tip="Revenue at risk (open qty × unit price) where FGS on-hand stock covers the current uncommitted shortage."
        />
      </div>

      {/* Segment cards — click a segment to filter every card, chart and the table */}
      <div>
        <div className="mb-2 flex items-center justify-between">
          <h2 className="text-sm font-medium">Backorder value by segment</h2>
          {(productSegment !== "all" || customerSegment !== "all") && (
            <button
              type="button"
              className="text-xs text-muted-foreground underline-offset-2 hover:underline"
              onClick={() => {
                setProductSegment("all");
                setCustomerSegment("all");
                setBrandFilter({ partner_brand: "", brand: "", category: "" });
                resetGroupPage();
              }}
            >
              Reset segments
            </button>
          )}
        </div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <SegmentValueCard
            title="Manufactured"
            subtitle="Kim-Fay products"
            totals={valueSummary?.by_product_segment.manufactured}
            loading={summary.isLoading}
            active={productSegment === "manufactured"}
            tip="Open exposure (open qty × unit price) for Kim-Fay manufactured SKUs on the filtered backorder lines — same basis as Excel Manufactured total. Click to filter the Inventory ID list and brand filters (Fay, Sifa, …); the card amount stays as the full Manufactured split."
            onClick={() => {
              setProductSegment((prev) => {
                const next = prev === "manufactured" ? "all" : "manufactured";
                setBrandFilter((bf) => ({
                  partner_brand: next === "manufactured" ? "manufactured" : "",
                  brand: next === "manufactured" && bf.partner_brand === "manufactured" ? bf.brand : "",
                  category: next === "manufactured" && bf.partner_brand === "manufactured" ? bf.category : "",
                }));
                return next;
              });
              resetGroupPage();
            }}
          />
          <SegmentValueCard
            title="Trading (Partners)"
            subtitle="Third-party brands"
            totals={valueSummary?.by_product_segment.trading}
            loading={summary.isLoading}
            active={productSegment === "trading"}
            tip="Open exposure for partner brands (Dove, Rexona, Cow & Gate, …) on the filtered backorder lines — same basis as Excel Trading total. Click to filter the Inventory ID list and brand filters; the card amount stays as the full Trading split."
            onClick={() => {
              setProductSegment((prev) => {
                const next = prev === "trading" ? "all" : "trading";
                setBrandFilter((bf) => ({
                  partner_brand: next === "trading" ? "trading" : "",
                  brand: next === "trading" && bf.partner_brand === "trading" ? bf.brand : "",
                  category: next === "trading" && bf.partner_brand === "trading" ? bf.category : "",
                }));
                return next;
              });
              resetGroupPage();
            }}
          />
          <SegmentValueCard
            title="KP"
            subtitle="Kimfay Professional customers"
            totals={valueSummary?.by_customer_segment.KP}
            loading={summary.isLoading}
            active={customerSegment === "KP"}
            tip="Value for customers in Kimfay Professional classes (customer class starting with KP). Click to filter the whole page to this customer segment; click again to clear. Combines with a product segment if one is selected."
            onClick={() => {
              setCustomerSegment((prev) => (prev === "KP" ? "all" : "KP"));
              resetGroupPage();
            }}
          />
          <SegmentValueCard
            title="CS"
            subtitle="Consumer Sales customers"
            totals={valueSummary?.by_customer_segment.CS}
            loading={summary.isLoading}
            active={customerSegment === "CS"}
            tip="Value for Consumer Sales customers (every customer class not starting with KP). Click to filter the whole page to this customer segment; click again to clear."
            onClick={() => {
              setCustomerSegment((prev) => (prev === "CS" ? "all" : "CS"));
              resetGroupPage();
            }}
          />
        </div>
      </div>

      {/* KPI strip */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Kpi
          label="Open lines"
          value={summary.data?.open_lines ?? filteredSummary?.open_lines ?? lineTotal}
          loading={summary.isLoading && analytics.isLoading && isLoading}
          tip="How many product rows are still short (one product on one sales order = one line). Not the same as SKUs — one product can appear on many orders."
        />
        <Kpi
          label="SKUs (Inventory IDs)"
          value={skuCount}
          loading={summary.isLoading && analytics.isLoading}
          tip="How many different products (Inventory IDs) are short under the current filters. One SKU can cover many open lines."
        />
        <Kpi
          label="Open orders"
          value={summary.data?.open_orders ?? filteredSummary?.open_orders}
          loading={summary.isLoading && analytics.isLoading}
          tip="How many sales orders still have at least one product that is short."
        />
        <div className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
            Completed shortfall
            <InfoTip text="Value that was never delivered on orders that completed short, captured from the first snapshot taken after each order completed. This is history — it does not change when backorders are cleared or resynced." />
          </div>
          {analytics.isLoading ? (
            <Skeleton className="mt-2 h-8 w-28" />
          ) : (
            <div className="mt-1">
              <MaskedCurrency
                value={summary.data?.completed.missed_value ?? 0}
                className={`text-2xl font-semibold ${(summary.data?.completed.missed_value ?? 0) > 500_000 ? "text-red-600" : ""}`}
              />
            </div>
          )}
        </div>
        <div className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
            Current outstanding
            <InfoTip text="Live Revenue at Risk (RaR): sum of open qty × unit price on active backorder lines matching the filters — same formula as the Backorder value card. Drops as goods ship or orders are cancelled." />
          </div>
          <div className="mt-1">
            <MaskedCurrency
              value={
                filteredSummary?.current_outstanding_amount ?? filteredSummary?.revenue_at_risk ?? 0
              }
              className="text-2xl font-semibold"
            />
          </div>
          <div className="mt-1 text-xs text-muted-foreground">Live open balance · Revenue at Risk</div>
        </div>
      </div>

      {/* Grouped table — always reflects active filters (segment, brand, category, dates, …) */}
      <div className="rounded-lg border bg-card shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
          <div className="min-w-0 space-y-1">
            <div className="flex flex-wrap items-center gap-2">
              <PackageX className="h-4 w-4 shrink-0 text-muted-foreground" />
              <h2 className="font-medium">Backorders by Inventory ID</h2>
              {isFetching && !isLoading && (
                <span className="text-xs text-muted-foreground">Refreshing…</span>
              )}
            </div>
            {(productSegment !== "all"
              || brandFilter.partner_brand
              || brandFilter.brand
              || brandFilter.category
              || customerSegment !== "all") && (
              <div className="flex flex-wrap items-center gap-1.5 pl-6">
                {productSegment === "manufactured" || brandFilter.partner_brand === "manufactured" ? (
                  <Badge variant="outline" className="text-[11px] font-normal">
                    Kimfay / Manufactured
                  </Badge>
                ) : null}
                {productSegment === "trading" || brandFilter.partner_brand === "trading" ? (
                  <Badge variant="outline" className="text-[11px] font-normal">
                    Partner / Trading
                  </Badge>
                ) : null}
                {brandFilter.brand ? (
                  <Badge variant="outline" className="text-[11px] font-normal">
                    Brand: {brandFilter.brand}
                  </Badge>
                ) : null}
                {brandFilter.category ? (
                  <Badge variant="outline" className="text-[11px] font-normal">
                    Category: {brandFilter.category}
                  </Badge>
                ) : null}
                {customerSegment !== "all" ? (
                  <Badge variant="outline" className="text-[11px] font-normal">
                    Segment: {customerSegment}
                  </Badge>
                ) : null}
              </div>
            )}
          </div>
          <div className="flex flex-wrap items-center gap-2 text-sm">
            {skuCount > 0 && (
              <Badge variant="secondary">
                {formatNumber(skuCount)} SKU{skuCount !== 1 ? "s" : ""}
                {totalGroups > 0 && totalGroups < skuCount
                  ? ` · ${formatNumber(totalGroups)} loaded in table`
                  : ""}
              </Badge>
            )}
            {lineTotal > 0 && (
              <span className="text-xs text-muted-foreground">
                {formatNumber(Math.min(lineTotal, data?.data?.length ?? 0))} of{" "}
                {formatNumber(lineTotal)} lines
              </span>
            )}
          </div>
        </div>

        {truncated && !isLoading && (
          <div className="border-b bg-amber-50 px-4 py-2 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            Showing the first {data?.data?.length ?? 0} lines for this filter set. Narrow filters or
            download Excel for the full list.
          </div>
        )}

        {isLoading ? (
          <div className="space-y-2 p-4">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : pagedGroups.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">
            No backorder lines for the current filters.
          </p>
        ) : (
          <>
            {/* Simple summary header table */}
            <div className="hidden border-b bg-muted/40 px-4 py-2 text-xs font-medium text-muted-foreground md:grid md:grid-cols-[minmax(0,1.6fr)_80px_90px_100px_110px] md:gap-3">
              <span>Inventory / Product</span>
              <span className="text-right">SOs</span>
              <span className="text-right">Customers</span>
              <span className="text-right">Open qty</span>
              <span className="text-right">Revenue at risk</span>
            </div>

            <Accordion
              type="multiple"
              className="w-full"
              value={expandedGroups}
              onValueChange={setExpandedGroups}
            >
              {pagedGroups.map((group) => (
                <AccordionItem
                  key={group.inventoryId}
                  value={group.inventoryId}
                  className="border-b px-4 last:border-b-0"
                >
                  <AccordionTrigger className="hover:no-underline">
                    <div className="grid w-full items-center gap-2 pr-2 text-left md:grid-cols-[minmax(0,1.6fr)_80px_90px_100px_110px] md:gap-3">
                      <div className="min-w-0">
                        <ProductListingCell
                          link={false}
                          product={{
                            inventory_id: group.inventoryId,
                            product_name: group.productName,
                            brand: group.brand,
                            sub_trading_group: group.subTradingGroup,
                            posting_class: group.postingClass,
                            supplier: group.supplier,
                          }}
                        />
                        {group.productLine && (
                          <p className="mt-0.5 text-[11px] text-muted-foreground">
                            {group.productLine}
                          </p>
                        )}
                      </div>
                      <div className="text-sm md:text-right">
                        <Badge variant="secondary">
                          {group.orderCount} SO{group.orderCount !== 1 ? "s" : ""}
                        </Badge>
                      </div>
                      <div className="hidden text-sm tabular-nums text-muted-foreground md:block md:text-right">
                        {group.customerCount}
                      </div>
                      <div className="hidden text-sm tabular-nums text-muted-foreground md:block md:text-right">
                        <div>
                          {formatNumber(group.totalOpenQty)}
                          {group.uom ? ` ${group.uom}` : ""}
                        </div>
                        {group.totalOrderQty > 0 && (
                          <div className="text-[11px]">
                            of {formatNumber(group.totalOrderQty)} ordered
                          </div>
                        )}
                      </div>
                      <div className="text-sm font-medium md:text-right">
                        {kes(group.totalRevenueAtRisk)}
                      </div>
                    </div>
                  </AccordionTrigger>

                  <AccordionContent>
                    <div className="overflow-x-auto rounded-md border">
                      <table className="w-full text-sm">
                        <thead>
                          <tr className="border-b bg-muted/30 text-left">
                            <th className="px-3 py-2 font-medium">SO</th>
                            <th className="px-3 py-2 font-medium">Status</th>
                            <th className="px-3 py-2 font-medium">Customer Name</th>
                            <th className="px-3 py-2 font-medium text-right">Open qty</th>
                            <th className="px-3 py-2 font-medium text-right">Revenue at risk</th>
                            <th className="px-3 py-2 font-medium">Reason</th>
                            {canAssignReasons && (
                              <th className="px-3 py-2 font-medium text-right">Action</th>
                            )}
                          </tr>
                        </thead>
                        <tbody>
                          {group.lines.map((line, lineIndex) => {
                            // Prefer open_qty (missing remainder). Value = open × unit_price, not invoice total.
                            const qty =
                              Number(line.open_qty) > 0
                                ? Number(line.open_qty)
                                : Number(line.backorder_qty) || 0;
                            const unitPrice = Number(line.unit_price) || 0;
                            const lineValue = qty * unitPrice;
                            const uom = line.uom?.trim() ? ` ${line.uom}` : "";
                            const reasons = lineReasonLabels(line);
                            const unassigned = isUnassignedReason(line);
                            const dateLabel = formatDate(line.order_date ?? null) ?? "—";
                            return (
                              <tr
                                key={line.id}
                                className={cn(
                                  "border-b last:border-0 hover:bg-muted/30",
                                  lineIndex % 2 === 0 ? "bg-white dark:bg-background" : "bg-zinc-100 dark:bg-muted/40",
                                )}
                              >
                                <td className="px-3 py-2 align-top">
                                  {line.customer_acumatica_id ? (
                                    <OrderLink
                                      customerId={line.customer_acumatica_id}
                                      orderId={line.order_nbr}
                                      className="font-mono"
                                    >
                                      {line.order_nbr}
                                    </OrderLink>
                                  ) : (
                                    <span className="font-mono">{line.order_nbr}</span>
                                  )}
                                  <div className="mt-0.5 whitespace-nowrap text-[11px] text-muted-foreground">
                                    {dateLabel}
                                  </div>
                                  {line.backorder_age_days !== null && (
                                    <div className="mt-0.5 whitespace-nowrap text-[11px] text-muted-foreground">
                                      Backordered {line.backorder_age_days}d
                                    </div>
                                  )}
                                </td>
                                <td className="px-3 py-2 align-top">
                                  <span
                                    className={cn(
                                      "inline-flex max-w-[9rem] items-center rounded-full border px-2 py-0.5 text-[11px] font-medium capitalize",
                                      orderStatusBadgeClass(line.order_status),
                                    )}
                                    title={orderStatusLabel(line.order_status)}
                                  >
                                    {orderStatusLabel(line.order_status)}
                                  </span>
                                </td>
                                <td className="px-3 py-2 align-top">
                                  {line.customer_acumatica_id ? (
                                    <CustomerLink
                                      customerId={line.customer_acumatica_id}
                                      customerName={line.customer_name}
                                      className="block"
                                    >
                                      <div className="font-medium">
                                        {line.customer_name ?? line.customer_acumatica_id}
                                      </div>
                                      <div className="font-mono text-[11px] text-muted-foreground">
                                        {line.customer_acumatica_id}
                                      </div>
                                    </CustomerLink>
                                  ) : (
                                    <span>{line.customer_name ?? "—"}</span>
                                  )}
                                </td>
                                <td className="px-3 py-2 text-right align-top tabular-nums">
                                  <div>
                                    {formatNumber(qty)}
                                    {uom}
                                  </div>
                                  <div className="mt-0.5 text-[11px] font-normal text-muted-foreground">
                                    {unitPrice > 0 ? (
                                      <MaskedCurrency value={unitPrice} className="text-[11px] text-muted-foreground" />
                                    ) : (
                                      "—"
                                    )}
                                    <span className="ml-0.5">/ unit</span>
                                  </div>
                                </td>
                                <td className="px-3 py-2 text-right align-top font-medium">
                                  <MaskedCurrency
                                    value={lineValue > 0 ? lineValue : line.revenue_at_risk}
                                  />
                                </td>
                                <td className="px-3 py-2 align-top">
                                  {unassigned || reasons.length === 0 ? (
                                    <Badge
                                      variant={line.missing_reason_exception ? "destructive" : "secondary"}
                                      title={
                                        line.missing_reason_exception
                                          ? `Backordered ${line.backorder_age_days}d without a reason`
                                          : undefined
                                      }
                                    >
                                      {line.missing_reason_exception
                                        ? `Unassigned (${line.backorder_age_days}d)`
                                        : "Unassigned"}
                                    </Badge>
                                  ) : (
                                    <div className="flex max-w-[16rem] flex-wrap gap-1">
                                      {reasons.map((label) => (
                                        <Badge
                                          key={`${line.id}-${label}`}
                                          variant="default"
                                          className="max-w-full whitespace-normal text-left font-normal"
                                          title={label}
                                        >
                                          {label}
                                        </Badge>
                                      ))}
                                    </div>
                                  )}
                                </td>
                                {canAssignReasons && (
                                  <td className="px-3 py-2 text-right align-top">
                                    {unassigned ? (
                                      <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => openReasonEditor(line)}
                                      >
                                        <PencilLine className="mr-1 h-3.5 w-3.5" />
                                        Assign
                                      </Button>
                                    ) : (
                                      <span className="text-[11px] text-muted-foreground">From SO</span>
                                    )}
                                  </td>
                                )}
                              </tr>
                            );
                          })}
                        </tbody>
                      </table>
                    </div>
                  </AccordionContent>
                </AccordionItem>
              ))}
            </Accordion>
          </>
        )}

        {totalGroups > 0 && (
          <div className="border-t px-4 py-3">
            <PaginationControls
              currentPage={safeGroupPage}
              perPage={groupsPerPage}
              total={totalGroups}
              lastPage={lastGroupPage}
              onPageChange={setGroupPage}
              onPerPageChange={(n) => {
                setGroupsPerPage(n);
                setGroupPage(1);
              }}
              pageSizes={GROUPS_PAGE_SIZE_OPTIONS}
            />
          </div>
        )}
      </div>
      </>
      )}

      {/* Manual reason assignment — only for Unassigned lines */}
      <Dialog open={!!editingLine} onOpenChange={(open) => !open && setEditingLine(null)}>
        <DialogContent className="sm:max-w-xl">
          <DialogHeader>
            <DialogTitle>Assign root cause</DialogTitle>
            <DialogDescription>
              Set the backorder reason for {editingLine?.order_nbr ?? "this line"}
              {editingLine?.inventory_id ? ` · ${editingLine.inventory_id}` : ""}.
              Imported SO reasons stay as-is; only Unassigned lines need manual assignment.
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
                  <SelectItem value="none">Select reason…</SelectItem>
                  {REJECTION_REASON_OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid gap-2">
              <Label htmlFor="reason-notes">Notes (optional)</Label>
              <Textarea
                id="reason-notes"
                placeholder="Supplier, warehouse, or logistics context…"
                value={reasonDraftNotes}
                onChange={(event) => setReasonDraftNotes(event.target.value)}
                rows={4}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditingLine(null)}>
              Cancel
            </Button>
            <Button
              onClick={saveReason}
              disabled={updateBackorderReason.isPending || reasonDraftCode === "none"}
            >
              {updateBackorderReason.isPending ? "Saving…" : "Save reason"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

/**
 * Lines that cleared and were archived out of the active list. `first_backordered_at`
 * (when it started) and `resolved_at` (when it cleared) are independent — no attempt is
 * made to assign a single "owning" month to a line that spans two.
 */
function resolvedAverageDays(rows: BackorderResolutionLine[]): number | null {
  const days = rows
    .map((row) => row.days_to_resolve)
    .filter((value): value is number => value != null && Number.isFinite(value));
  return days.length ? days.reduce((sum, value) => sum + value, 0) / days.length : null;
}

function groupResolvedBackorders(rows: BackorderResolutionLine[]) {
  const skus = new Map<string, BackorderResolutionLine[]>();
  rows.forEach((row) => skus.set(row.inventory_id, [...(skus.get(row.inventory_id) ?? []), row]));
  return Array.from(skus.entries()).map(([inventoryId, skuRows]) => {
    const customerMap = new Map<string, BackorderResolutionLine[]>();
    skuRows.forEach((row) => {
      const key = row.customer_acumatica_id || row.customer_name || "UNKNOWN";
      customerMap.set(key, [...(customerMap.get(key) ?? []), row]);
    });
    return {
      inventoryId,
      productName: skuRows[0]?.product_name ?? null,
      brand: skuRows[0]?.brand ?? null,
      amount: skuRows.reduce((sum, row) => sum + (Number(row.revenue_at_risk) || 0), 0),
      averageDays: resolvedAverageDays(skuRows),
      customers: Array.from(customerMap.entries()).map(([key, customerRows]) => ({
        key,
        customerId: customerRows[0]?.customer_acumatica_id ?? null,
        customerName: customerRows[0]?.customer_name ?? null,
        rows: customerRows,
        amount: customerRows.reduce((sum, row) => sum + (Number(row.revenue_at_risk) || 0), 0),
        averageDays: resolvedAverageDays(customerRows),
      })).sort((a, b) => b.amount - a.amount),
    };
  }).sort((a, b) => b.amount - a.amount);
}

function ResolvedBackordersPanel() {
  const [q, setQ] = useState("");
  const [qDebounced, setQDebounced] = useState("");
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [expandedSkus, setExpandedSkus] = useState<string[]>([]);

  useEffect(() => {
    const handle = window.setTimeout(() => {
      setQDebounced(q.trim());
      setPage(1);
    }, 300);
    return () => window.clearTimeout(handle);
  }, [q]);

  const searchActive = qDebounced.length > 0;
  const { data, isLoading, isFetching } = useResolvedBackorders({
    q: searchActive ? qDebounced : undefined,
    date_from: searchActive ? undefined : dateFrom || undefined,
    date_to: searchActive ? undefined : dateTo || undefined,
    page: 1,
    per_page: 1000,
  });

  const rows = data?.data ?? [];
  const total = data?.total ?? rows.length;
  const groups = useMemo(() => groupResolvedBackorders(rows), [rows]);
  const lastPage = Math.max(1, Math.ceil(groups.length / perPage));
  const safePage = Math.min(page, lastPage);
  const pagedGroups = groups.slice((safePage - 1) * perPage, safePage * perPage);

  return (
    <div className="flex flex-col gap-4">
      <div className="rounded-lg border bg-card p-4 shadow-sm">
        <div className="flex flex-wrap items-end gap-3">
          <div className="min-w-[220px] flex-1">
            <Label>Search</Label>
            <div className="relative">
              <Search className="pointer-events-none absolute left-2 top-2.5 h-4 w-4 text-muted-foreground" />
              <Input
                value={q}
                onChange={(event) => setQ(event.target.value)}
                placeholder="SO, SKU, customer…"
                className="pl-8"
              />
            </div>
          </div>
          <div>
            <Label>Resolved from</Label>
            <Input
              type="date"
              value={dateFrom}
              onChange={(event) => {
                setDateFrom(event.target.value);
                setPage(1);
              }}
              disabled={searchActive}
            />
          </div>
          <div>
            <Label>Resolved to</Label>
            <Input
              type="date"
              value={dateTo}
              onChange={(event) => {
                setDateTo(event.target.value);
                setPage(1);
              }}
              disabled={searchActive}
            />
          </div>
        </div>
        <p className="mt-2 text-xs text-muted-foreground">
          Lines that cleared (shipped, or their order completed) and were removed from the active
          list, filtered by when they resolved. "First backordered" is shown per line but doesn't
          affect this filter — a line that started last month and cleared this month appears here
          once, dated by when it actually resolved.
        </p>
      </div>

      <div className="rounded-lg border bg-card shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
          <div className="flex items-center gap-2">
            <h2 className="font-medium">Resolved backorders</h2>
            {isFetching && !isLoading && (
              <span className="text-xs text-muted-foreground">Refreshing…</span>
            )}
          </div>
          {total > 0 && (
            <div className="flex gap-2">
              <Badge variant="secondary">{formatNumber(groups.length)} SKUs</Badge>
              <Badge variant="outline">{formatNumber(total)} resolved SO lines</Badge>
            </div>
          )}
        </div>

        {isLoading ? (
          <div className="space-y-2 p-4">
            {Array.from({ length: 6 }).map((_, i) => (
              <Skeleton key={i} className="h-12 w-full" />
            ))}
          </div>
        ) : rows.length === 0 ? (
          <p className="px-4 py-10 text-center text-sm text-muted-foreground">
            No resolved backorders for the current filters.
          </p>
        ) : (
          <>
          <Accordion type="multiple" value={expandedSkus} onValueChange={setExpandedSkus}>
            {pagedGroups.map((sku) => (
              <AccordionItem key={sku.inventoryId} value={sku.inventoryId} className="px-4">
                <AccordionTrigger className="hover:no-underline">
                  <div className="grid flex-1 gap-2 pr-4 text-left sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-center">
                    <div className="min-w-0">
                      <div className="font-mono font-semibold">{sku.inventoryId}</div>
                      <div className="truncate text-xs text-muted-foreground">
                        {sku.productName ?? "Description unavailable"}{sku.brand ? ` · ${sku.brand}` : ""}
                      </div>
                    </div>
                    <Badge variant="secondary">{sku.customers.length} customers</Badge>
                    <div className="text-xs text-muted-foreground">
                      Avg resolve
                      <div className="font-semibold text-foreground">
                        {sku.averageDays == null ? "—" : `${sku.averageDays.toFixed(1)} days`}
                      </div>
                    </div>
                    <div className="text-xs text-muted-foreground sm:text-right">
                      Resolved amount
                      <div className="font-semibold text-foreground"><MaskedCurrency value={sku.amount} /></div>
                    </div>
                  </div>
                </AccordionTrigger>
                <AccordionContent>
                  <Accordion type="multiple" className="rounded-md border">
                    {sku.customers.map((customer) => (
                      <AccordionItem key={`${sku.inventoryId}-${customer.key}`} value={`${sku.inventoryId}-${customer.key}`} className="px-3 last:border-0">
                        <AccordionTrigger className="hover:no-underline">
                          <div className="grid flex-1 gap-2 pr-4 text-left sm:grid-cols-[minmax(0,1fr)_auto_auto_auto] sm:items-center">
                            <div className="min-w-0">
                              <div className="truncate font-medium">{customer.customerName ?? customer.customerId ?? "Unknown customer"}</div>
                              <div className="font-mono text-[11px] text-muted-foreground">{customer.customerId ?? "No customer ID"}</div>
                            </div>
                            <Badge variant="outline">{customer.rows.length} SOs</Badge>
                            <div className="text-xs text-muted-foreground">Avg resolve<div className="font-medium text-foreground">{customer.averageDays == null ? "—" : `${customer.averageDays.toFixed(1)} days`}</div></div>
                            <div className="text-xs text-muted-foreground sm:text-right">Amount<div className="font-medium text-foreground"><MaskedCurrency value={customer.amount} /></div></div>
                          </div>
                        </AccordionTrigger>
                        <AccordionContent>
                          <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                              <thead><tr className="border-b bg-muted/40 text-left"><th className="px-3 py-2 font-medium">SO</th><th className="px-3 py-2 font-medium">Reason</th><th className="px-3 py-2 font-medium">First backordered</th><th className="px-3 py-2 font-medium">Resolved</th><th className="px-3 py-2 text-right font-medium">Days</th><th className="px-3 py-2 text-right font-medium">Amount</th></tr></thead>
                              <tbody>
                                {customer.rows.map((row) => (
                                  <tr key={row.id} className="border-b last:border-0">
                                    <td className="px-3 py-2">{row.customer_acumatica_id ? <OrderLink customerId={row.customer_acumatica_id} orderId={row.order_nbr} className="font-mono">{row.order_nbr}</OrderLink> : <span className="font-mono">{row.order_nbr}</span>}</td>
                                    <td className="px-3 py-2"><Badge variant={row.reason_code ? "default" : "secondary"}>{reasonLabel(row.reason_code)}</Badge></td>
                                    <td className="px-3 py-2 text-xs text-muted-foreground">{formatDate(row.first_backordered_at) ?? "—"}{row.first_backordered_at_is_backfilled ? " (min.)" : ""}</td>
                                    <td className="px-3 py-2 text-xs text-muted-foreground">{formatDate(row.resolved_at) ?? "—"}</td>
                                    <td className="px-3 py-2 text-right tabular-nums">{row.days_to_resolve ?? "—"}</td>
                                    <td className="px-3 py-2 text-right font-medium"><MaskedCurrency value={Number(row.revenue_at_risk) || 0} /></td>
                                  </tr>
                                ))}
                              </tbody>
                            </table>
                          </div>
                        </AccordionContent>
                      </AccordionItem>
                    ))}
                  </Accordion>
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
          <div className="hidden overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b bg-muted/30 text-left">
                  <th className="px-3 py-2 font-medium">SO</th>
                  <th className="px-3 py-2 font-medium">Product</th>
                  <th className="px-3 py-2 font-medium">Customer</th>
                  <th className="px-3 py-2 font-medium">Reason</th>
                  <th className="px-3 py-2 font-medium">First backordered</th>
                  <th className="px-3 py-2 font-medium">Resolved</th>
                  <th className="px-3 py-2 font-medium text-right">Days to resolve</th>
                  <th className="px-3 py-2 font-medium text-right">Value</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row, i) => (
                  <tr
                    key={row.id}
                    className={cn(
                      "border-b last:border-0",
                      i % 2 === 0 ? "bg-white dark:bg-background" : "bg-zinc-100 dark:bg-muted/40",
                    )}
                  >
                    <td className="px-3 py-2 align-top">
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
                    <td className="px-3 py-2 align-top">
                      <div className="font-medium">{row.product_name ?? row.inventory_id}</div>
                      <div className="font-mono text-[11px] text-muted-foreground">{row.inventory_id}</div>
                      {row.brand && <div className="text-[11px] text-muted-foreground">{row.brand}</div>}
                    </td>
                    <td className="px-3 py-2 align-top">
                      {row.customer_acumatica_id ? (
                        <CustomerLink
                          customerId={row.customer_acumatica_id}
                          customerName={row.customer_name}
                          className="block"
                        >
                          <div className="font-medium">{row.customer_name ?? row.customer_acumatica_id}</div>
                          <div className="font-mono text-[11px] text-muted-foreground">
                            {row.customer_acumatica_id}
                          </div>
                        </CustomerLink>
                      ) : (
                        <span>{row.customer_name ?? "—"}</span>
                      )}
                    </td>
                    <td className="px-3 py-2 align-top">
                      {row.reason_code ? (
                        <Badge variant="default" className="max-w-full whitespace-normal text-left font-normal">
                          {reasonLabel(row.reason_code)}
                        </Badge>
                      ) : (
                        <Badge variant="secondary">Unassigned</Badge>
                      )}
                    </td>
                    <td className="px-3 py-2 align-top text-[11px] text-muted-foreground">
                      {formatDate(row.first_backordered_at) ?? "—"}
                      {row.first_backordered_at_is_backfilled && (
                        <span
                          className="ml-1"
                          title="Approximate — this line was already backordered when aging tracking started"
                        >
                          ~
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2 align-top text-[11px] text-muted-foreground">
                      {formatDate(row.resolved_at) ?? "—"}
                    </td>
                    <td className="px-3 py-2 text-right align-top tabular-nums">
                      {row.days_to_resolve ?? "—"}
                    </td>
                    <td className="px-3 py-2 text-right align-top font-medium">
                      <MaskedCurrency value={Number(row.revenue_at_risk) || 0} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          </>
        )}

        {groups.length > 0 && (
          <div className="border-t px-4 py-3">
            <PaginationControls
              currentPage={safePage}
              perPage={perPage}
              total={groups.length}
              lastPage={lastPage}
              onPageChange={setPage}
              onPerPageChange={(n) => {
                setPerPage(n);
                setPage(1);
              }}
              pageSizes={GROUPS_PAGE_SIZE_OPTIONS}
            />
          </div>
        )}
      </div>
    </div>
  );
}

function BackorderKeyGuide() {
  return (
    <div className="rounded-lg border bg-muted/20 shadow-sm">
      <Accordion type="single" collapsible defaultValue="key-guide">
        <AccordionItem value="key-guide" className="border-0">
          <AccordionTrigger className="px-4 py-3 hover:no-underline">
            <div className="text-left">
              <div className="text-sm font-medium">Key guide</div>
              <div className="text-xs font-normal text-muted-foreground">
                What the numbers mean · Line vs SKU · in simple English
              </div>
            </div>
          </AccordionTrigger>
          <AccordionContent className="px-4 pb-4">
            <div className="grid gap-4 text-sm sm:grid-cols-2">
              <div className="space-y-3">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Value cards
                </h3>
                <dl className="space-y-3">
                  <div>
                    <dt className="font-medium text-red-700 dark:text-red-400">Backorder value</dt>
                    <dd className="mt-0.5 text-muted-foreground">
                      How much money is still waiting to be delivered. Open quantity × unit price.
                      This is the main “still short” number.
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium text-emerald-700 dark:text-emerald-400">Invoiced value</dt>
                    <dd className="mt-0.5 text-muted-foreground">
                      How much of those same products has already been shipped. Shipped quantity ×
                      unit price.
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium">Order value</dt>
                    <dd className="mt-0.5 text-muted-foreground">
                      How much customers ordered on these shortfall products. Ordered quantity × unit
                      price. Ideally close to Invoiced + Backorder.
                    </dd>
                  </div>
                </dl>
              </div>

              <div className="space-y-3">
                <h3 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                  Words we use
                </h3>
                <dl className="space-y-3">
                  <div>
                    <dt className="font-medium">SKU / Inventory ID</dt>
                    <dd className="mt-0.5 text-muted-foreground">
                      One product code (e.g. FAYTP0015). The main table groups by this. “SKUs” =
                      how many different products are short.
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium">Line (order line)</dt>
                    <dd className="mt-0.5 text-muted-foreground">
                      One product on one sales order — not the same as a SKU. One order can have
                      several lines; one SKU can appear on many orders. “Open lines” counts those
                      rows; “SKUs” counts unique products.
                    </dd>
                  </div>
                  <div>
                    <dt className="font-medium">Filters</dt>
                    <dd className="mt-0.5 text-muted-foreground">
                      Cards, KPIs, and the Inventory ID table all use the same filters (dates, Kimfay
                      / Manufactured, brand, category, and so on). Change a filter and everything
                      updates together.
                    </dd>
                  </div>
                </dl>
              </div>
            </div>
          </AccordionContent>
        </AccordionItem>
      </Accordion>
    </div>
  );
}

function ValueCard({
  label,
  value,
  loading,
  hint,
  tone,
  tip,
}: {
  label: string;
  value?: number | null;
  loading?: boolean;
  hint?: string;
  tone?: "destructive" | "positive";
  tip?: string;
}) {
  const valueClass =
    tone === "destructive"
      ? "text-red-600 dark:text-red-400"
      : tone === "positive"
        ? "text-emerald-600 dark:text-emerald-400"
        : "";

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
        {label}
        {tip && <InfoTip text={tip} />}
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-8 w-32" />
      ) : (
        <div className="mt-1">
          <MaskedCurrency value={value ?? 0} className={`text-2xl font-semibold ${valueClass}`} />
        </div>
      )}
      {hint && <div className="mt-1 text-xs text-muted-foreground">{hint}</div>}
    </div>
  );
}

function SegmentValueCard({
  title,
  subtitle,
  totals,
  loading,
  active,
  onClick,
  tip,
}: {
  title: string;
  subtitle: string;
  totals?: ValueSummaryTotals;
  loading?: boolean;
  active: boolean;
  onClick: () => void;
  tip?: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={`rounded-lg border bg-card p-4 text-left shadow-sm transition-colors hover:bg-muted/40 ${
        active ? "border-primary ring-1 ring-primary" : ""
      }`}
    >
      <div className="flex items-center justify-between gap-2">
        <div className="flex items-center gap-1.5 text-sm font-medium">
          {title}
          {tip && <InfoTip text={tip} />}
        </div>
        {active && <Badge>Filtering</Badge>}
      </div>
      <div className="text-[11px] text-muted-foreground">{subtitle}</div>
      {loading ? (
        <Skeleton className="mt-2 h-7 w-28" />
      ) : (
        <div className="mt-1">
          <MaskedCurrency
            value={totals?.backorder_value ?? 0}
            className="text-xl font-semibold text-red-600 dark:text-red-400"
          />
        </div>
      )}
      {!loading && (
        <div className="mt-1 flex items-center gap-1 text-xs text-muted-foreground">
          <span>Invoiced</span>
          <MaskedCurrency value={totals?.invoiced_value ?? 0} />
        </div>
      )}
    </button>
  );
}

function Kpi({
  label,
  value,
  loading,
  tip,
}: {
  label: string;
  value?: number | string | null;
  loading?: boolean;
  tip?: string;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-1.5 text-sm text-muted-foreground">
        {label}
        {tip && <InfoTip text={tip} />}
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-8 w-20" />
      ) : (
        <p className="mt-1 text-2xl font-semibold">
          {typeof value === "number" ? value.toLocaleString() : (value ?? "—")}
        </p>
      )}
    </div>
  );
}
