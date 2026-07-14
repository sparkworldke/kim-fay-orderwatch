import { createFileRoute } from "@tanstack/react-router";
import { useState } from "react";
import { BarChart3, FileDown, Gauge, List, PackageX, RefreshCw, Search } from "lucide-react";
import { toast } from "sonner";
import {
  CustomerLink,
  DateWithActions,
  OrderLink,
} from "@/components/entity-links";
import { ProductListingCell } from "@/components/inventory/ProductListingCell";
import { BrandFilterCascade, type BrandFilterValue } from "@/components/filters/BrandFilterCascade";
import { MaskedCurrency, useMaskedKESFormatter } from "@/components/MaskedCurrency";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
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
  type FillRateExcelSummary,
  type FillRateBusinessCategoryRow,
  type FillRateOutOfStockReport,
  type FillRateReasonCaptureReport,
  type FillRateSegmentBucket,
  type FillRateSegmentSplit,
  type FillRateSnapshot,
  useFillRate,
  useFillRateOutOfStockReport,
  useFillRateSummary,
  useSyncFillRate,
  type FillRateSort,
  type ContributionRow,
} from "@/hooks/useOperations";
import { shippingZoneLabel } from "@/hooks/useShippingZones";
import {
  BusinessCategorySkuSheet,
  type BusinessCategoryKey,
} from "@/components/operations/BusinessCategorySkuSheet";
import { downloadApiFile } from "@/lib/api";
import { formatNumber } from "@/lib/format";

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

function qtyWithUom(qty: string | number, uom: string | null | undefined) {
  const n = Number(qty).toLocaleString();
  return uom ? `${n} ${uom}` : n;
}

function FillRatePage() {
  const kes = useMaskedKESFormatter();
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
  const [segment, setSegment] = useState<"all" | "KP" | "CS">("all");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(50);
  const [selectedOrder, setSelectedOrder] = useState<FillRateSnapshot | null>(null);
  const [isDownloading, setIsDownloading] = useState(false);
  /** Default off: fill rate excludes out-of-stock shortfall lines. Toggle on to include them. */
  const [includeOutOfStock, setIncludeOutOfStock] = useState(false);
  const [oosBrand, setOosBrand] = useState<string>("all");
  const [oosCategory, setOosCategory] = useState<"all" | "manufactured" | "trading">("all");
  const [isDownloadingOos, setIsDownloadingOos] = useState(false);
  const [brandFilter, setBrandFilter] = useState<BrandFilterValue>({
    partner_brand: "",
    brand: "",
    category: "",
  });

  const listFilters = {
    customer_group: customerGroup !== "all" ? customerGroup : undefined,
    product_line: productLine !== "all" ? productLine : undefined,
    reason_code: reasonCode !== "all" ? reasonCode : undefined,
    shipping_zone_id: shippingZoneId !== "all" ? shippingZoneId : undefined,
    status: status !== "all" ? status : undefined,
    segment: segment !== "all" ? segment : undefined,
    partner_brand: brandFilter.partner_brand || undefined,
    brand: brandFilter.brand || undefined,
    category: brandFilter.category || undefined,
    include_out_of_stock: includeOutOfStock,
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
    segment: listFilters.segment,
    partner_brand: listFilters.partner_brand,
    brand: listFilters.brand,
    category: listFilters.category,
    include_out_of_stock: includeOutOfStock,
    sort,
    page,
    per_page: perPage,
  });
  const oosReport = useFillRateOutOfStockReport({
    date_from: dateFrom,
    date_to: dateTo,
    brand: oosBrand !== "all" ? oosBrand : undefined,
    business_category: oosCategory !== "all" ? oosCategory : undefined,
    partner_brand: brandFilter.partner_brand || undefined,
    customer_group: listFilters.customer_group,
    segment: listFilters.segment,
    shipping_zone_id: listFilters.shipping_zone_id,
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
    if (segment !== "all") qs.set("segment", segment);
    if (brandFilter.partner_brand) qs.set("partner_brand", brandFilter.partner_brand);
    if (brandFilter.brand) qs.set("brand", brandFilter.brand);
    if (brandFilter.category) qs.set("category", brandFilter.category);
    qs.set("include_out_of_stock", includeOutOfStock ? "1" : "0");
    qs.set("sort", sort);

    setIsDownloading(true);
    try {
      await downloadApiFile(
        `operations/fill-rate/export?${qs}`,
        `fill-rate-export-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`,
        { timeoutMs: 300_000 },
      );
      toast.success("Fill rate Excel download started.");
    } catch (error) {
      const message = error instanceof Error ? error.message : "Unable to download fill rate.";
      if (message.includes("504") || /gateway time|timed out|timeout/i.test(message)) {
        toast.error(
          "Export timed out. Narrow the date range or filters (under ~8,000 orders) and try again.",
          { duration: 8000 },
        );
      } else {
        toast.error(message);
      }
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
            Completed orders only: Shipped Qty ÷ Order Qty × 100. Use Update to refresh snapshots from Acumatica.
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
        <div className="w-40">
          <Label>Segment</Label>
          <Select value={segment} onValueChange={(v) => { setSegment(v as "all" | "KP" | "CS"); setPage(1); }}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All (combined)</SelectItem>
              <SelectItem value="KP">KP (Kimfay Professional)</SelectItem>
              <SelectItem value="CS">CS (Consumer Sales)</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="flex items-center gap-2 rounded-md border px-3 py-2">
          <Switch
            id="include-oos"
            checked={includeOutOfStock}
            onCheckedChange={(checked) => {
              setIncludeOutOfStock(checked);
              setPage(1);
            }}
          />
          <div className="min-w-0">
            <Label htmlFor="include-oos" className="cursor-pointer text-sm font-medium">
              Include out of stock
            </Label>
            <p className="text-[11px] text-muted-foreground">
              {includeOutOfStock
                ? "Fill rate counts OOS shortfalls"
                : "Fill rate excludes OOS lines (default)"}
            </p>
          </div>
        </div>
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

      {summary.data?.segment_split && (
        <SegmentSplitSection split={summary.data.segment_split} loading={summary.isLoading} />
      )}

      {summary.data && (
        <p className="text-sm text-muted-foreground">
          Revenue not yet shipped:{" "}
          <MaskedCurrency value={summary.data.revenue_not_shipped} className="font-medium text-foreground" />
          {" · "}N/A orders: {summary.data.na_count}
          {!includeOutOfStock && (
            <span className="ml-1 text-amber-700 dark:text-amber-400">
              · OOS excluded from fill rate
            </span>
          )}
        </p>
      )}

      <OutOfStockReportPanel
        report={oosReport.data}
        loading={oosReport.isLoading}
        brand={oosBrand}
        category={oosCategory}
        brands={oosReport.data?.brands ?? []}
        onBrandChange={setOosBrand}
        onCategoryChange={setOosCategory}
        isDownloading={isDownloadingOos}
        onDownload={async () => {
          const qs = new URLSearchParams();
          qs.set("date_from", dateFrom);
          qs.set("date_to", dateTo);
          if (oosBrand !== "all") qs.set("brand", oosBrand);
          if (oosCategory !== "all") qs.set("business_category", oosCategory);
          if (brandFilter.partner_brand) qs.set("partner_brand", brandFilter.partner_brand);
          if (listFilters.customer_group) qs.set("customer_group", listFilters.customer_group);
          if (listFilters.segment) qs.set("segment", listFilters.segment);
          if (listFilters.shipping_zone_id) qs.set("shipping_zone_id", listFilters.shipping_zone_id);
          setIsDownloadingOos(true);
          try {
            await downloadApiFile(
              `operations/fill-rate/out-of-stock/export?${qs}`,
              `fill-rate-out-of-stock-${new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "")}.xlsx`,
              { timeoutMs: 180_000 },
            );
            toast.success("Out of stock Excel download started.");
          } catch (error) {
            toast.error(error instanceof Error ? error.message : "Unable to download OOS report.");
          } finally {
            setIsDownloadingOos(false);
          }
        }}
      />

      {summary.data?.excel_summary && (
        <FillRateExcelSummaryPanel
          summary={summary.data.excel_summary}
          filters={{
            date_from: dateFrom,
            date_to: dateTo,
            customer_group: listFilters.customer_group,
            product_line: listFilters.product_line,
            reason_code: listFilters.reason_code,
            shipping_zone_id: listFilters.shipping_zone_id,
            segment: listFilters.segment,
            partner_brand: listFilters.partner_brand,
            brand: listFilters.brand,
            category: listFilters.category,
            status: status !== "all" ? status : undefined,
            q: q || undefined,
            include_out_of_stock: includeOutOfStock ? "1" : "0",
          }}
        />
      )}

      <BrandFilterCascade
        value={brandFilter}
        onChange={(next) => {
          setBrandFilter(next);
          setPage(1);
        }}
      />

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
                <td className="px-4 py-3 font-medium">
                  <OrderLink
                    customerId={row.customer_acumatica_id ?? row.order?.customer_acumatica_id}
                    orderId={row.order_nbr}
                  />
                  {(row.order_description ?? "").trim() !== "" && (
                    <div className="text-xs text-muted-foreground">{row.order_description}</div>
                  )}
                </td>
                <td className="px-4 py-3">
                  <CustomerLink
                    customerId={row.customer_acumatica_id ?? row.order?.customer_acumatica_id}
                    customerName={row.customer_name ?? row.order?.customer_name}
                    className="block"
                  >
                    <div>{row.customer_name ?? row.order?.customer_name ?? row.customer_acumatica_id ?? "—"}</div>
                  </CustomerLink>
                  {row.order?.order_date && (
                    <div className="mt-1">
                      <DateWithActions value={row.order.order_date} />
                    </div>
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
        <SheetContent className="flex h-full w-full flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
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
  filters,
}: {
  summary: FillRateExcelSummary;
  filters?: {
    date_from?: string;
    date_to?: string;
    customer_group?: string;
    product_line?: string;
    reason_code?: string;
    shipping_zone_id?: string;
    segment?: string;
    partner_brand?: string;
    brand?: string;
    category?: string;
    status?: string;
    q?: string;
    include_out_of_stock?: string;
  };
}) {
  const kes = useMaskedKESFormatter();
  const [skuCategory, setSkuCategory] = useState<BusinessCategoryKey | null>(null);
  const segmentReasons = summary.by_segment_reason ?? [];
  const kpReasons = segmentReasons.filter((r) => r.segment === "KP").slice(0, 4);
  const csReasons = segmentReasons.filter((r) => r.segment === "CS").slice(0, 4);

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
        <MetricTile label="Undershipped Value" value={kes(summary.totals.undershipped_value)} tone="red" />
        <MetricTile label="Fill Rate" value={summary.totals.fill_rate_pct != null ? `${summary.totals.fill_rate_pct}%` : "N/A"} tone="cyan" />
      </div>

      {(summary.by_segment ?? []).length > 0 && (
        <div className="mt-4 rounded-md border p-3">
          <h3 className="text-sm font-medium">KP (Kimfay Professional) vs CS segment breakdown</h3>
          <div className="mt-3 overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b text-left text-muted-foreground">
                  <th className="py-2 pr-4 font-medium">Segment</th>
                  <th className="py-2 pr-4 font-medium text-right">Fill rate</th>
                  <th className="py-2 pr-4 font-medium text-right">Orders</th>
                  <th className="py-2 pr-4 font-medium text-right">Ordered qty</th>
                  <th className="py-2 pr-4 font-medium text-right">Shipped qty</th>
                  <th className="py-2 pr-4 font-medium text-right">Not shipped (KES)</th>
                  <th className="py-2 pr-4 font-medium text-right">Healthy</th>
                  <th className="py-2 pr-4 font-medium text-right">At risk</th>
                  <th className="py-2 pr-4 font-medium text-right">Critical</th>
                </tr>
              </thead>
              <tbody>
                {summary.by_segment.map((row) => (
                  <tr key={row.segment} className="border-b last:border-0">
                    <td className="py-2 pr-4 font-medium">{row.label}</td>
                    <td className="py-2 pr-4 text-right">
                      <Badge variant={fillRateStatusColor(row.status)}>
                        {row.fill_rate_pct != null ? `${row.fill_rate_pct}%` : "N/A"}
                      </Badge>
                    </td>
                    <td className="py-2 pr-4 text-right font-mono">{row.order_count}</td>
                    <td className="py-2 pr-4 text-right font-mono">{Number(row.total_ordered_qty).toLocaleString()}</td>
                    <td className="py-2 pr-4 text-right font-mono">{Number(row.total_shipped_qty).toLocaleString()}</td>
                    <td className="py-2 pr-4 text-right font-mono">{Number(row.revenue_not_shipped).toLocaleString()}</td>
                    <td className="py-2 pr-4 text-right text-emerald-600 font-mono">{row.healthy_count}</td>
                    <td className="py-2 pr-4 text-right text-amber-600 font-mono">{row.at_risk_count}</td>
                    <td className="py-2 pr-4 text-right text-red-600 font-mono">{row.critical_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="mt-4 grid gap-4 lg:grid-cols-3">
        <ContributionList title="Status contribution" rows={summary.by_status} labelKey="status" valueKey="undershipped_value" tone="blue" />
        <ContributionList title="Reason contribution" rows={summary.by_reason} labelKey="reason" valueKey="undershipped_value" tone="red" />
        <ContributionList title="Customer group contribution" rows={summary.by_customer_group} labelKey="customer_group" valueKey="undershipped_value" tone="cyan" />
      </div>

      {(kpReasons.length > 0 || csReasons.length > 0) && (
        <div className="mt-4 grid gap-4 lg:grid-cols-2">
          <SegmentReasonList title="KP (Kimfay Professional) root causes" rows={kpReasons} />
          <SegmentReasonList title="CS (Consumer Sales) root causes" rows={csReasons} />
        </div>
      )}

      {(summary.by_business_category ?? []).length > 0 && (
        <BusinessCategorySection
          rows={summary.by_business_category}
          onSelectCategory={(category) => setSkuCategory(category)}
        />
      )}

      {summary.reason_capture_report && (
        <ReasonCaptureReportPanel report={summary.reason_capture_report} />
      )}

      <BusinessCategorySkuSheet
        open={skuCategory != null}
        onOpenChange={(open) => {
          if (!open) setSkuCategory(null);
        }}
        module="fill-rate"
        businessCategory={skuCategory}
        filters={filters}
      />
    </div>
  );
}

function OutOfStockReportPanel({
  report,
  loading,
  brand,
  category,
  brands,
  onBrandChange,
  onCategoryChange,
  isDownloading,
  onDownload,
}: {
  report?: FillRateOutOfStockReport;
  loading: boolean;
  brand: string;
  category: "all" | "manufactured" | "trading";
  brands: string[];
  onBrandChange: (brand: string) => void;
  onCategoryChange: (category: "all" | "manufactured" | "trading") => void;
  isDownloading: boolean;
  onDownload: () => void;
}) {
  const kes = useMaskedKESFormatter();
  const categoryRows = report?.by_business_category ?? [];

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="mb-3 flex flex-wrap items-start justify-between gap-3">
        <div className="flex items-center gap-2">
          <PackageX className="h-4 w-4 text-amber-600" />
          <div>
            <h2 className="font-medium">Out of stock report</h2>
            <p className="text-xs text-muted-foreground">
              Shortfall lines with out-of-stock reasons — Manufactured vs Trading (Partners), filterable by brand.
            </p>
          </div>
        </div>
        <Button size="sm" variant="outline" onClick={onDownload} disabled={isDownloading || loading}>
          <FileDown className={`mr-2 h-4 w-4 ${isDownloading ? "animate-pulse" : ""}`} />
          {isDownloading ? "Preparing…" : "Download OOS Excel"}
        </Button>
      </div>

      <div className="mb-3 flex flex-wrap items-end gap-3">
        <div className="w-44">
          <Label>Category</Label>
          <Select value={category} onValueChange={(v) => onCategoryChange(v as "all" | "manufactured" | "trading")}>
            <SelectTrigger><SelectValue /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All categories</SelectItem>
              <SelectItem value="manufactured">Manufactured</SelectItem>
              <SelectItem value="trading">Trading (Partners)</SelectItem>
            </SelectContent>
          </Select>
        </div>
        <div className="w-48">
          <Label>Brand</Label>
          <Select value={brand} onValueChange={onBrandChange}>
            <SelectTrigger><SelectValue placeholder="All brands" /></SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All brands</SelectItem>
              {brands.map((b) => (
                <SelectItem key={b} value={b}>{b}</SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>

      {loading && (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-16" />)}
        </div>
      )}

      {report && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
            <MetricTile label="OOS lines" value={String(report.totals.line_count)} tone="amber" />
            <MetricTile label="Orders" value={String(report.totals.order_count)} tone="blue" />
            <MetricTile label="SKUs" value={String(report.totals.sku_count)} tone="cyan" />
            <MetricTile label="Short qty" value={formatNumber(report.totals.undershipped_qty)} tone="red" />
            <MetricTile label="Value (KES)" value={kes(report.totals.undershipped_value)} tone="red" />
          </div>

          <div className="mt-4 overflow-x-auto rounded-md border">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b bg-muted/40 text-left text-muted-foreground">
                  <th className="px-3 py-2 font-medium">Category</th>
                  <th className="px-3 py-2 font-medium text-right">Lines</th>
                  <th className="px-3 py-2 font-medium text-right">Orders</th>
                  <th className="px-3 py-2 font-medium text-right">SKUs</th>
                  <th className="px-3 py-2 font-medium text-right">Short qty</th>
                  <th className="px-3 py-2 font-medium text-right">Value</th>
                </tr>
              </thead>
              <tbody>
                {categoryRows.map((row) => (
                  <tr key={row.business_category} className="border-b last:border-0">
                    <td className="px-3 py-2 font-medium">{row.label}</td>
                    <td className="px-3 py-2 text-right font-mono">{row.line_count}</td>
                    <td className="px-3 py-2 text-right font-mono">{row.order_count}</td>
                    <td className="px-3 py-2 text-right font-mono">{row.sku_count}</td>
                    <td className="px-3 py-2 text-right font-mono">{formatNumber(row.undershipped_qty)}</td>
                    <td className="px-3 py-2 text-right font-mono">{Number(row.undershipped_value).toLocaleString()}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <div className="mt-4 overflow-x-auto rounded-md border">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b bg-muted/40 text-left text-muted-foreground">
                  <th className="px-3 py-2 font-medium">SKU</th>
                  <th className="px-3 py-2 font-medium">Category</th>
                  <th className="px-3 py-2 font-medium">Reason</th>
                  <th className="px-3 py-2 font-medium text-right">Lines</th>
                  <th className="px-3 py-2 font-medium text-right">Orders</th>
                  <th className="px-3 py-2 font-medium text-right">Short qty</th>
                  <th className="px-3 py-2 font-medium text-right">Value</th>
                </tr>
              </thead>
              <tbody>
                {report.skus.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-3 py-6 text-center text-muted-foreground">
                      No out-of-stock shortfall lines for the current filters.
                    </td>
                  </tr>
                )}
                {report.skus.slice(0, 100).map((sku) => (
                  <tr key={sku.inventory_id} className="border-b last:border-0">
                    <td className="px-3 py-2">
                      <ProductListingCell product={sku} />
                    </td>
                    <td className="px-3 py-2">{sku.business_category_label}</td>
                    <td className="px-3 py-2">{sku.reason_label}</td>
                    <td className="px-3 py-2 text-right font-mono">{sku.line_count}</td>
                    <td className="px-3 py-2 text-right font-mono">{sku.order_count}</td>
                    <td className="px-3 py-2 text-right font-mono">{formatNumber(sku.undershipped_qty)}</td>
                    <td className="px-3 py-2 text-right font-mono">
                      <MaskedCurrency value={sku.undershipped_value} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {report.skus.length > 100 && (
              <p className="px-3 py-2 text-[11px] text-muted-foreground">
                Showing top 100 SKUs by value. Download Excel for the full list.
              </p>
            )}
          </div>
        </>
      )}
    </div>
  );
}

function BusinessCategorySection({
  rows,
  onSelectCategory,
}: {
  rows: FillRateBusinessCategoryRow[];
  onSelectCategory: (category: BusinessCategoryKey) => void;
}) {
  return (
    <div className="mt-4 rounded-md border p-3">
      <h3 className="text-sm font-medium">Manufactured vs Trading (Partners) — business category comparison</h3>
      <p className="mt-1 text-xs text-muted-foreground">
        Click a category to open the SKU breakdown and download Excel.
      </p>
      <div className="mt-3 overflow-x-auto">
        <table className="w-full text-xs">
          <thead>
            <tr className="border-b text-left text-muted-foreground">
              <th className="py-2 pr-4 font-medium">Category</th>
              <th className="py-2 pr-4 font-medium text-right">Fill rate</th>
              <th className="py-2 pr-4 font-medium text-right">Lines</th>
              <th className="py-2 pr-4 font-medium text-right">Orders</th>
              <th className="py-2 pr-4 font-medium text-right">Not shipped (KES)</th>
              <th className="py-2 pr-4 font-medium text-right">SKUs</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => {
              const category = row.business_category === "manufactured" || row.business_category === "trading"
                ? row.business_category
                : null;
              return (
                <tr
                  key={row.business_category}
                  className={`border-b last:border-0 ${category ? "cursor-pointer hover:bg-muted/50" : ""}`}
                  onClick={() => category && onSelectCategory(category)}
                  onKeyDown={(e) => {
                    if (category && (e.key === "Enter" || e.key === " ")) {
                      e.preventDefault();
                      onSelectCategory(category);
                    }
                  }}
                  tabIndex={category ? 0 : undefined}
                  role={category ? "button" : undefined}
                >
                  <td className="py-2 pr-4 font-medium text-primary underline-offset-2 hover:underline">
                    {row.label}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono">
                    {row.fill_rate_pct != null ? `${row.fill_rate_pct}%` : "N/A"}
                  </td>
                  <td className="py-2 pr-4 text-right font-mono">{row.line_count}</td>
                  <td className="py-2 pr-4 text-right font-mono">{row.order_count}</td>
                  <td className="py-2 pr-4 text-right font-mono">{Number(row.undershipped_value).toLocaleString()}</td>
                  <td className="py-2 pr-4 text-right">
                    {category ? (
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-7 text-[11px]"
                        onClick={(e) => {
                          e.stopPropagation();
                          onSelectCategory(category);
                        }}
                      >
                        View SKUs
                      </Button>
                    ) : (
                      "—"
                    )}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ReasonCaptureReportPanel({ report }: { report: FillRateReasonCaptureReport }) {
  const { summary, breakdown, flagged_records: flagged } = report;
  const hasGaps = summary.missing_reason_lines > 0 || summary.unclassified_reason_lines > 0;

  return (
    <div className="mt-4 rounded-md border p-3">
      <h3 className="text-sm font-medium">Root cause capture report</h3>
      <div className="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
        <MetricTile label="Shortfall lines" value={String(summary.total_shortfall_lines)} tone="blue" />
        <MetricTile label="Valid reasons" value={String(summary.valid_reason_lines)} tone="green" />
        <MetricTile label="Missing" value={String(summary.missing_reason_lines)} tone="amber" />
        <MetricTile label="Unclassified" value={String(summary.unclassified_reason_lines)} tone="red" />
        <MetricTile
          label="Capture rate"
          value={summary.capture_rate_pct != null ? `${summary.capture_rate_pct}%` : "N/A"}
          tone="cyan"
        />
        <MetricTile label="Flagged records" value={String(flagged.length)} tone={hasGaps ? "red" : "green"} />
      </div>

      {breakdown.length > 0 && (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-b text-left text-muted-foreground">
                <th className="py-2 pr-3 font-medium">Category</th>
                <th className="py-2 pr-3 font-medium">Parent reason</th>
                <th className="py-2 pr-3 font-medium">Sub-reason</th>
                <th className="py-2 pr-3 font-medium text-right">Lines</th>
                <th className="py-2 pr-3 font-medium text-right">Value (KES)</th>
              </tr>
            </thead>
            <tbody>
              {breakdown.slice(0, 12).map((row, index) => (
                <tr key={`${row.business_category}-${row.sub_reason}-${index}`} className="border-b last:border-0">
                  <td className="py-2 pr-3">{row.business_category}</td>
                  <td className="py-2 pr-3">{row.parent_reason}</td>
                  <td className="py-2 pr-3">{row.sub_reason_label}</td>
                  <td className="py-2 pr-3 text-right font-mono">{row.line_count}</td>
                  <td className="py-2 pr-3 text-right font-mono">{Number(row.undershipped_value).toLocaleString()}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {hasGaps && flagged.length > 0 && (
        <div className="mt-4">
          <p className="text-xs font-medium text-amber-700">Flagged records (missing or unclassified reasons)</p>
          <div className="mt-2 max-h-40 overflow-y-auto text-xs">
            {flagged.slice(0, 10).map((row, index) => (
              <div key={`${row.order_nbr}-${row.inventory_id}-${index}`} className="flex justify-between gap-2 border-b py-1">
                <span className="inline-flex flex-wrap items-center gap-1">
                  <OrderLink
                    customerId={row.customer_acumatica_id}
                    orderId={row.order_nbr}
                  />
                  <span className="text-muted-foreground">·</span>
                  <ProductListingCell product={row} showDescription={false} className="inline" />
                </span>
                <span className="text-muted-foreground">{row.issue} · {row.business_category}</span>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}

function SegmentReasonList({
  title,
  rows,
}: {
  title: string;
  rows: Array<{ segment: string; reason: string; undershipped_value: number; contribution_pct: number }>;
}) {
  const kes = useMaskedKESFormatter();

  return (
    <div className="rounded-md border p-3">
      <h3 className="text-sm font-medium">{title}</h3>
      <div className="mt-3 space-y-3">
        {rows.length === 0 && <p className="text-xs text-muted-foreground">No contribution data yet.</p>}
        {rows.map((row, index) => (
          <div key={`${row.segment}-${row.reason}-${index}`} className="space-y-1">
            <div className="flex items-center justify-between gap-3 text-xs">
              <span className="truncate font-medium">{reasonLabel(row.reason)}</span>
              <span className="shrink-0 font-mono">{kes(row.undershipped_value)}</span>
            </div>
            <div className="h-1.5 rounded-full bg-muted">
              <div className="h-1.5 rounded-full bg-purple-500" style={{ width: `${Math.min(row.contribution_pct, 100)}%` }} />
            </div>
            <p className="text-[11px] text-muted-foreground">{row.contribution_pct.toFixed(1)}% contribution</p>
          </div>
        ))}
      </div>
    </div>
  );
}

function SegmentSplitSection({
  split,
  loading,
}: {
  split: FillRateSegmentSplit;
  loading?: boolean;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="mb-3 flex items-center gap-2">
        <Gauge className="h-4 w-4 text-purple-600" />
        <h2 className="font-medium">KP (Kimfay Professional) vs CS (Consumer Sales) — sector split</h2>
      </div>
      <div className="grid gap-4 sm:grid-cols-2">
        <SegmentCard title="KP (Kimfay Professional)" bucket={split.KP} loading={loading} />
        <SegmentCard title="CS (Consumer Sales)" bucket={split.CS} loading={loading} />
      </div>
    </div>
  );
}

function SegmentCard({
  title,
  bucket,
  loading,
}: {
  title: string;
  bucket: FillRateSegmentBucket;
  loading?: boolean;
}) {
  const kes = useMaskedKESFormatter();
  const pct = bucket.fill_rate_pct;
  const color =
    bucket.status === "critical" ? "text-red-600" :
    bucket.status === "at_risk" ? "text-amber-600" :
    bucket.status === "healthy" ? "text-emerald-600" : "";

  return (
    <div className="rounded-md border p-4">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold">{title}</h3>
        <Badge variant={fillRateStatusColor(bucket.status)}>{bucket.status}</Badge>
      </div>
      {loading ? (
        <Skeleton className="mt-2 h-8 w-20" />
      ) : (
        <p className={`mt-2 text-3xl font-bold ${color}`}>
          {pct != null ? `${pct.toFixed(1)}%` : "N/A"}
        </p>
      )}
      <div className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground">
        <div>Orders: <span className="font-mono text-foreground">{bucket.order_count}</span></div>
        <div>Not shipped: <span className="font-mono text-foreground">{kes(bucket.revenue_not_shipped)}</span></div>
        <div className="text-emerald-600">Healthy: <span className="font-mono">{bucket.healthy_count}</span></div>
        <div className="text-amber-600">At risk: <span className="font-mono">{bucket.at_risk_count}</span></div>
        <div className="text-red-600">Critical: <span className="font-mono">{bucket.critical_count}</span></div>
        <div>Ordered qty: <span className="font-mono text-foreground">{Number(bucket.total_ordered_qty).toLocaleString()}</span></div>
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
  const kes = useMaskedKESFormatter();
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
              <span className="shrink-0 font-mono">{kes(Number(row[valueKey] ?? 0))}</span>
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
  const kes = useMaskedKESFormatter();
  const products = order.products ?? [];
  const lineTotal = products.reduce((sum, p) => sum + Number(p.not_shipped_value), 0);

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="shrink-0 space-y-4 border-b p-6 pr-12">
      <SheetHeader className="p-0">
        <SheetTitle>
          <OrderLink
            customerId={order.customer_acumatica_id ?? order.order?.customer_acumatica_id}
            orderId={order.order_nbr}
          />
        </SheetTitle>
        <SheetDescription className="flex flex-wrap items-center gap-2">
          <CustomerLink
            customerId={order.customer_acumatica_id ?? order.order?.customer_acumatica_id}
            customerName={order.customer_name}
          >
            {order.customer_name ?? order.customer_acumatica_id ?? "Unknown customer"}
          </CustomerLink>
          {order.order?.order_date && (
            <>
              <span className="text-muted-foreground">·</span>
              <DateWithActions value={order.order.order_date} />
            </>
          )}
        </SheetDescription>
      </SheetHeader>

      <div className="flex flex-wrap gap-2">
        {order.fill_rate_pct != null && (
          <Badge variant={fillRateStatusColor(order.fill_rate_status)}>
            {Number(order.fill_rate_pct).toFixed(1)}% fill rate
          </Badge>
        )}
        <Badge variant="outline">{products.length} line{products.length !== 1 ? "s" : ""}</Badge>
      </div>
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-6">
      <div className="rounded-lg border">
        {products.length === 0 ? (
          <p className="p-3 text-sm text-muted-foreground">No line items — re-sync fill rate for this order.</p>
        ) : (
          <div className="divide-y">
            {products.map((p) => (
              <div key={p.inventory_id} className="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                <div className="min-w-0 flex-1">
                  <ProductListingCell product={p} />
                  <div className="mt-1 truncate text-xs text-muted-foreground">
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
                  <div className="mt-0.5 font-mono text-xs font-medium">{kes(p.not_shipped_value)}</div>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      {products.length > 0 && (
        <div className="mt-4 flex justify-between border-t pt-4 text-sm">
          <span className="text-muted-foreground">Sum of line not-shipped values</span>
          <span className="font-mono font-medium">{kes(lineTotal)}</span>
        </div>
      )}
      {products.length > 0 && Math.abs(lineTotal - Number(order.revenue_not_shipped)) > 1 && (
        <p className="mt-2 text-xs text-amber-600">
          Order-level not shipped ({kes(order.revenue_not_shipped)}) may differ due to discounts or rounding.
        </p>
      )}
      </div>
    </div>
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
