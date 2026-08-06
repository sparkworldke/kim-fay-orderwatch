import { createFileRoute } from "@tanstack/react-router";
import {
  AlertTriangle,
  Building2,
  ChevronDown,
  Download,
  PackageCheck,
  PackageMinus,
  ShoppingBag,
  Tags,
} from "lucide-react";
import { useEffect, useMemo, useState } from "react";
import { toast } from "sonner";
import { MultiSelect } from "@/components/ui/multi-select";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PaginationControls } from "@/components/ui/pagination-controls";
import {
  itemsNotDeliveredParams,
  useItemsNotDelivered,
  type NotDeliveredOrder,
  type NotDeliveredTotals,
} from "@/hooks/useItemsNotDelivered";
import { useOrderFilterOptions } from "@/hooks/useOrders";
import { OrderLink } from "@/components/entity-links";
import { downloadApiFile } from "@/lib/api";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/products-not-delivered")({
  head: () => ({ meta: [{ title: "Product In Stock (Not Delivered) — Kim-Fay Sight" }] }),
  component: ProductsNotDeliveredPage,
});

const number = (value: number | null) =>
  value == null ? "—" : value.toLocaleString(undefined, { maximumFractionDigits: 2 });
const money = (value: number | null) =>
  value == null
    ? "—"
    : new Intl.NumberFormat("en-KE", {
        style: "currency",
        currency: "KES",
        maximumFractionDigits: 0,
      }).format(value);
const dateValue = (date: Date) => {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
};
const monthStart = () => {
  const now = new Date();
  return dateValue(new Date(now.getFullYear(), now.getMonth(), 1));
};

function Totals({ totals }: { totals: NotDeliveredTotals }) {
  return (
    <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-[10px] sm:grid-cols-5">
      <span>
        Ordered <b className="tabular-nums">{number(totals.ordered_qty)}</b>
      </span>
      <span>
        Shipped <b className="tabular-nums">{number(totals.shipped_qty)}</b>
      </span>
      <span>
        Not delivered{" "}
        <b className="text-destructive tabular-nums">{number(totals.not_delivered_qty)}</b>
      </span>
      <span>
        Amount <b className="tabular-nums">{money(totals.not_delivered_amount)}</b>
      </span>
      <span>
        SOs <b>{totals.orders}</b>
      </span>
    </div>
  );
}

function StockBadge({ status, label }: { status: string; label: string }) {
  return (
    <Badge
      variant={
        status === "out_of_stock" ? "destructive" : status === "in_stock" ? "default" : "secondary"
      }
      className="whitespace-normal text-left text-[9px] leading-tight"
    >
      {label}
    </Badge>
  );
}

function orderStatusBadgeClass(status: string | null | undefined) {
  const s = (status ?? "").toLowerCase();
  if (s.includes("complete")) return "border-emerald-500/40 bg-emerald-600/15 text-emerald-700";
  if (s.includes("ship")) return "border-sky-500/40 bg-sky-600/15 text-sky-700";
  if (s.includes("open")) return "border-amber-500/40 bg-amber-500/15 text-amber-800";
  if (s.includes("hold") || s.includes("pending")) return "border-orange-500/40 bg-orange-500/15 text-orange-800";
  if (s.includes("reject") || s.includes("cancel")) return "border-red-500/40 bg-red-600/15 text-red-700";
  return "border-border bg-muted text-foreground";
}

function OrderStatusBadge({ status }: { status: string | null }) {
  if (!status?.trim()) {
    return <span className="text-muted-foreground">—</span>;
  }
  return (
    <span
      className={cn(
        "inline-flex rounded-md border px-1.5 py-0.5 text-[9px] font-bold whitespace-nowrap",
        orderStatusBadgeClass(status),
      )}
    >
      {status}
    </span>
  );
}

function ProductsNotDeliveredPage() {
  const options = useOrderFilterOptions();
  const [brands, setBrands] = useState<string[]>([]);
  const [outletIds, setOutletIds] = useState<string[]>([]);
  const [reasons, setReasons] = useState<string[]>([]);
  // Page is scoped to in-stock only (not delivered but stock available).
  const stockStatuses = useMemo(() => ["in_stock"], []);
  const [orderStatuses, setOrderStatuses] = useState<string[]>([]);
  const [productSegments, setProductSegments] = useState<string[]>([]);
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [exporting, setExporting] = useState(false);

  useEffect(() => {
    setDateFrom(monthStart());
    setDateTo(dateValue(new Date()));
  }, []);

  const outlets = useMemo(
    () =>
      (options.data?.parents ?? [])
        .flatMap((parent) =>
          parent.outlets.map((outlet) => ({
            ...outlet,
            label: `${parent.name} / ${outlet.name} · ${outlet.id}`,
          })),
        )
        .filter(
          (outlet, index, all) => all.findIndex((candidate) => candidate.id === outlet.id) === index,
        ),
    [options.data?.parents],
  );
  const outletLabels = outlets.map((outlet) => outlet.label);
  const selectedOutletLabels = outletIds.map(
    (id) => outlets.find((outlet) => outlet.id === id)?.label ?? id,
  );

  const filters = {
    brands,
    customerIds: outletIds,
    productSegments,
    reasons,
    stockStatuses,
    orderStatuses,
    dateFrom,
    dateTo,
    page,
    perPage,
  };
  const report = useItemsNotDelivered(filters);
  const summary = report.data?.summary;
  const reasonOptions = report.data?.filter_options.reasons ?? [];
  const orderStatusOptions = report.data?.filter_options.order_statuses ?? [
    { key: "Open", label: "Open" },
    { key: "Shipping", label: "Shipping" },
    { key: "Completed", label: "Completed" },
    { key: "Pending Approval", label: "Pending Approval" },
    { key: "On Hold", label: "On Hold" },
  ];
  const segmentOptions = report.data?.filter_options.product_segments ?? [];
  const selectedReasonLabels = reasons.map(
    (key) => reasonOptions.find((reason) => reason.key === key)?.label ?? key,
  );
  const selectedOrderStatusLabels = orderStatuses.map(
    (key) => orderStatusOptions.find((status) => status.key === key)?.label ?? key,
  );
  const selectedSegmentLabels = productSegments.map(
    (key) => segmentOptions.find((segment) => segment.key === key)?.label ?? key,
  );

  const exportReport = async () => {
    setExporting(true);
    try {
      const params = itemsNotDeliveredParams(filters);
      params.delete("page");
      params.delete("per_page");
      await downloadApiFile(
        `operations/items-not-delivered/export?${params}`,
        `products-not-delivered-${dateFrom}-${dateTo}.xlsx`,
      );
      toast.success("Products not delivered workbook downloaded.");
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to export report.");
    } finally {
      setExporting(false);
    }
  };

  const resetPage = () => setPage(1);

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <h1 className="font-semibold tracking-tight">Product In Stock (Not Delivered)</h1>
          <p className="text-muted-foreground">
            Only products that were <b>not fully delivered</b> but are currently{" "}
            <b>in stock</b> (non-RMS). Filter by SO date and SO status (Shipping, Completed, Open,
            …).
          </p>
        </div>
        <Button
          variant="outline"
          onClick={exportReport}
          disabled={exporting || !report.data?.meta.total}
        >
          <Download className="mr-1 size-4" />
          {exporting ? "Exporting…" : "Export workbook"}
        </Button>
      </div>

      <Card>
        <CardContent className="grid gap-2 p-3 sm:grid-cols-2 xl:grid-cols-4">
          <label className="grid gap-1 text-[10px] font-semibold">
            SO date from
            <Input
              type="date"
              value={dateFrom}
              max={dateTo}
              onChange={(event) => {
                setDateFrom(event.target.value);
                resetPage();
              }}
            />
          </label>
          <label className="grid gap-1 text-[10px] font-semibold">
            SO date to
            <Input
              type="date"
              value={dateTo}
              min={dateFrom}
              onChange={(event) => {
                setDateTo(event.target.value);
                resetPage();
              }}
            />
          </label>
          <MultiSelect
            label="SO Status"
            compact
            options={orderStatusOptions.map((status) => status.label)}
            selected={selectedOrderStatusLabels}
            onChange={(labels) => {
              setOrderStatuses(
                orderStatusOptions
                  .filter((status) => labels.includes(status.label))
                  .map((status) => status.key),
              );
              resetPage();
            }}
            emptyLabel="All SO statuses"
            allLabel="All SO statuses"
            searchPlaceholder="Search status (Shipping, Completed…)"
          />
          <MultiSelect
            label="Product Segment"
            compact
            options={segmentOptions.map((segment) => segment.label)}
            selected={selectedSegmentLabels}
            onChange={(labels) => {
              setProductSegments(
                segmentOptions
                  .filter((segment) => labels.includes(segment.label))
                  .map((segment) => segment.key),
              );
              resetPage();
            }}
            allLabel="All Segments"
          />
          <MultiSelect
            label="Brand"
            compact
            options={options.data?.brands ?? []}
            selected={brands}
            onChange={(values) => {
              setBrands(values);
              resetPage();
            }}
            allLabel="All Brands"
          />
          <MultiSelect
            label="Outlet"
            compact
            options={outletLabels}
            selected={selectedOutletLabels}
            onChange={(labels) => {
              setOutletIds(
                outlets.filter((outlet) => labels.includes(outlet.label)).map((outlet) => outlet.id),
              );
              resetPage();
            }}
            allLabel="All Outlets"
          />
          <MultiSelect
            label="Reason"
            compact
            options={reasonOptions.map((reason) => reason.label)}
            selected={selectedReasonLabels}
            onChange={(labels) => {
              setReasons(
                reasonOptions
                  .filter((reason) => labels.includes(reason.label))
                  .map((reason) => reason.key),
              );
              resetPage();
            }}
            allLabel="All Reasons"
          />
        </CardContent>
      </Card>

      <div className="flex flex-wrap items-center gap-2 rounded-md border border-emerald-500/30 bg-emerald-500/10 px-3 py-2 text-[10px] text-emerald-900 dark:text-emerald-100">
        <PackageCheck className="size-3.5 shrink-0" />
        <span>
          Scope is fixed to <b>in stock + not delivered</b>. Out-of-stock shortfalls are excluded.
        </span>
        {orderStatuses.length > 0 ? (
          <Button
            type="button"
            size="sm"
            variant="ghost"
            className="h-6 text-[10px]"
            onClick={() => {
              setOrderStatuses([]);
              resetPage();
            }}
          >
            Clear SO status
          </Button>
        ) : null}
      </div>

      <div className="grid grid-cols-2 gap-2 xl:grid-cols-6">
        {[
          { label: "Affected SKUs", value: summary?.affected_skus ?? 0, icon: Tags },
          { label: "Affected Outlets", value: summary?.affected_outlets ?? 0, icon: Building2 },
          { label: "Affected SOs", value: summary?.affected_orders ?? 0, icon: ShoppingBag },
          {
            label: "Not Delivered Units",
            value: number(summary?.not_delivered_units ?? 0),
            icon: PackageMinus,
          },
          {
            label: "Not Delivered Amount",
            value: money(summary?.not_delivered_amount ?? 0),
            icon: AlertTriangle,
          },
          {
            label: "In-stock shortfall amount",
            value: money(summary?.in_stock_amount ?? 0),
            icon: PackageCheck,
          },
        ].map((item) => (
          <Card key={item.label}>
            <CardContent className="flex items-center gap-2 p-3">
              <div className="rounded-lg bg-primary/10 p-2 text-primary">
                <item.icon className="size-4" />
              </div>
              <div className="min-w-0">
                <p className="truncate text-muted-foreground">{item.label}</p>
                <p className="font-bold">{item.value}</p>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="space-y-2">
        {report.data?.data.map((sku) => (
          <details
            key={sku.inventory_id}
            className="group overflow-hidden rounded-lg border bg-card"
          >
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-3 hover:bg-muted/30">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <b className="text-primary">{sku.inventory_id}</b>
                  <Badge variant="outline">{sku.brand ?? "—"}</Badge>
                  <span className="font-semibold">{sku.product_name}</span>
                </div>
                <Totals totals={sku.totals} />
              </div>
              <ChevronDown className="size-4 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="space-y-2 border-t bg-muted/10 p-2">
              {sku.outlets.map((outlet) => (
                <details
                  key={outlet.customer_id}
                  className="group/outlet overflow-hidden rounded-md border bg-background"
                >
                  <summary className="flex cursor-pointer list-none items-center justify-between gap-3 p-3 hover:bg-muted/30">
                    <div className="min-w-0">
                      <b>{outlet.customer_name}</b>
                      <span className="ml-2 text-muted-foreground">{outlet.customer_id}</span>
                      {outlet.parent_customer_name !== outlet.customer_name ? (
                        <p className="text-muted-foreground">
                          Parent: {outlet.parent_customer_name}
                        </p>
                      ) : null}
                      <Totals totals={outlet.totals} />
                    </div>
                    <ChevronDown className="size-4 shrink-0 transition-transform group-open/outlet:rotate-180" />
                  </summary>
                  <div className="overflow-x-auto border-t">
                    <table className="w-full min-w-[1280px] text-[10px]">
                      <thead className="bg-muted/40 text-left">
                        <tr>
                          {[
                            "SO number",
                            "SO date",
                            "SO status",
                            "Ordered",
                            "Shipped",
                            "Not delivered",
                            "Unit price",
                            "Amount",
                            "Primary reason",
                            "In stock",
                            "Available / On hand",
                            "Warehouses",
                            "Age",
                          ].map((heading) => (
                            <th key={heading} className="px-2 py-2 font-semibold">
                              {heading}
                            </th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {outlet.orders.map((order: NotDeliveredOrder) => (
                          <tr key={order.order_nbr} className="border-t align-top">
                            <td className="px-2 py-2">
                              <OrderLink
                                customerId={outlet.customer_id}
                                orderId={order.order_nbr}
                                className="text-primary"
                              >
                                {order.order_nbr}
                              </OrderLink>
                            </td>
                            <td className="px-2 py-2 whitespace-nowrap tabular-nums">
                              {order.order_date ?? "—"}
                            </td>
                            <td className="px-2 py-2">
                              <OrderStatusBadge status={order.order_status} />
                            </td>
                            <td className="px-2 py-2 text-right tabular-nums">
                              {number(order.ordered_qty)}
                            </td>
                            <td className="px-2 py-2 text-right tabular-nums">
                              {number(order.shipped_qty)}
                            </td>
                            <td className="px-2 py-2 text-right font-bold text-destructive tabular-nums">
                              {number(order.not_delivered_qty)}
                            </td>
                            <td className="px-2 py-2 text-right tabular-nums">
                              {money(order.unit_price)}
                            </td>
                            <td className="px-2 py-2 text-right font-semibold tabular-nums">
                              {money(order.not_delivered_amount)}
                            </td>
                            <td className="px-2 py-2">
                              <Badge variant="outline">{order.reason_label}</Badge>
                            </td>
                            <td className="px-2 py-2">
                              <StockBadge
                                status={order.stock_status}
                                label={order.stock_status_label}
                              />
                              {order.stock_basis === "on_hand_fallback" ? (
                                <p className="mt-1 text-muted-foreground">On-hand fallback</p>
                              ) : null}
                            </td>
                            <td className="px-2 py-2 text-right tabular-nums">
                              {number(order.total_available)} / {number(order.total_on_hand)}
                            </td>
                            <td className="px-2 py-2">
                              <details>
                                <summary className="cursor-pointer text-primary">
                                  View {order.warehouse_stocks.length}
                                </summary>
                                <div className="mt-1 min-w-56 space-y-1">
                                  {order.warehouse_stocks.map((stock) => (
                                    <div
                                      key={stock.warehouse_id}
                                      className="flex justify-between gap-3"
                                    >
                                      <span>{stock.warehouse_name}</span>
                                      <span className="tabular-nums">
                                        A {number(stock.qty_available)} · OH{" "}
                                        {number(stock.qty_on_hand)}
                                      </span>
                                    </div>
                                  ))}
                                  {!order.warehouse_stocks.length ? (
                                    <span className="text-muted-foreground">
                                      No non-RMS stock record
                                    </span>
                                  ) : null}
                                </div>
                              </details>
                            </td>
                            <td className="px-2 py-2">{order.age_days} days</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </details>
              ))}
            </div>
          </details>
        ))}
        {!report.isLoading && !report.data?.data.length ? (
          <Card>
            <CardContent className="py-10 text-center text-muted-foreground">
              No in-stock products with undelivered quantity match the selected filters.
            </CardContent>
          </Card>
        ) : null}
      </div>

      {report.data ? (
        <PaginationControls
          currentPage={report.data.meta.current_page}
          lastPage={report.data.meta.last_page}
          total={report.data.meta.total}
          onPageChange={setPage}
          perPage={perPage}
          onPerPageChange={(value) => {
            setPerPage(value);
            setPage(1);
          }}
        />
      ) : null}
    </div>
  );
}
