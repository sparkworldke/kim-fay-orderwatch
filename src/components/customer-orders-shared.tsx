import { useMemo, useState, type ReactNode } from "react";
import { usePagination } from "@/hooks/usePagination";
import { Link } from "@tanstack/react-router";
import {
  ConsultantLink,
  CustomerLink,
  DateWithActions,
  OrderLink,
} from "@/components/entity-links";
import { ProductListingCell } from "@/components/inventory/ProductListingCell";
import { InfoTip } from "@/components/info-tip";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { PaginationControls } from "@/components/ui/pagination-controls";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { CommonProductsResponse, SuggestedOrdersResponse } from "@/hooks/useCustomers";
import { useBackorders, type BackorderLine } from "@/hooks/useOperations";
import { fillRateIssueReason } from "@/lib/order-reasons";
import { MaskedKES } from "@/components/MaskedCurrency";
import type {
  AcumaticaCustomer,
  AcumaticaSalesOrder,
  AcumaticaSalesOrderLine,
} from "@/types/admin";
import { Building2, ClipboardList, Search, X } from "lucide-react";

// ---------------------------------------------------------------------------
// Documents table — shared between the flat customer page and the branch page.
// ---------------------------------------------------------------------------

export function DocumentsTable({
  customerId,
  branchId,
  docs,
}: {
  customerId: string;
  branchId?: string;
  docs: AcumaticaSalesOrder[];
}) {
  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Document</TableHead>
          <TableHead>Type</TableHead>
          <TableHead>Status</TableHead>
          <TableHead className="text-right">Fill Rate</TableHead>
          <TableHead className="text-right">Backorder</TableHead>
          <TableHead className="text-right">Rev. Lost</TableHead>
          <TableHead className="text-right">Total</TableHead>
          <TableHead>Date</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {docs.map((doc) => {
          const fillRate = numeric(doc.lines_avg_fill_rate_pct);
          const backorderQty = numeric(doc.lines_sum_backorder_qty) ?? 0;
          const revenueLost = numeric(doc.revenue_lost) ?? 0;

          return (
            <TableRow key={doc.id}>
              <TableCell>
                <OrderLink
                  customerId={customerId}
                  branchId={branchId}
                  orderId={doc.acumatica_order_nbr}
                />
                {doc.description && doc.description.trim() !== "" && (
                  <div className="text-xs text-muted-foreground">{doc.description}</div>
                )}
                {doc.customer_order && (
                  <div className="text-xs text-muted-foreground">PO {doc.customer_order}</div>
                )}
              </TableCell>
              <TableCell>
                <Badge variant="outline">{doc.order_type}</Badge>
              </TableCell>
              <TableCell>
                <Badge variant="secondary">{doc.status ?? "-"}</Badge>
              </TableCell>
              <TableCell className="text-right">
                {fillRate === null ? (
                  "-"
                ) : (
                  <span className={backorderQty > 0 ? "font-medium text-destructive" : ""}>
                    {fillRate.toFixed(1)}%
                  </span>
                )}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                {backorderQty.toLocaleString("en-KE")}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                {revenueLost > 0 && fillRate !== null && fillRate < 100 ? (
                  <MaskedKES value={revenueLost} />
                ) : (
                  "—"
                )}
              </TableCell>
              <TableCell className="text-right tabular-nums">
                <MaskedKES value={Number(doc.order_total ?? 0)} />
              </TableCell>
              <TableCell>
                <DateWithActions value={doc.order_date} emptyText="-" />
              </TableCell>
            </TableRow>
          );
        })}
      </TableBody>
    </Table>
  );
}

export function summarizeDocuments(docs: AcumaticaSalesOrder[]) {
  const fillRates = docs
    .map((doc) => numeric(doc.lines_avg_fill_rate_pct))
    .filter((value): value is number => value !== null);

  return {
    documentCount: docs.length,
    totalValue: docs.reduce((sum, doc) => sum + Number(doc.order_total ?? 0), 0),
    avgFillRate:
      fillRates.length > 0
        ? fillRates.reduce((sum, value) => sum + value, 0) / fillRates.length
        : null,
    backorderQty: docs.reduce((sum, doc) => sum + (numeric(doc.lines_sum_backorder_qty) ?? 0), 0),
  };
}

export function MetricsGrid({
  summary,
  loading,
}: {
  summary: ReturnType<typeof summarizeDocuments>;
  loading: boolean;
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <MetricCard label="Documents" value={summary.documentCount} loading={loading} />
      <MetricCard
        label="Total value"
        value={<MaskedKES value={summary.totalValue} />}
        loading={loading}
        text
      />
      <MetricCard
        label="Avg fill rate"
        value={summary.avgFillRate === null ? "-" : `${summary.avgFillRate.toFixed(1)}%`}
        loading={loading}
        text
      />
      <MetricCard label="Backorder qty" value={summary.backorderQty} loading={loading} />
    </div>
  );
}

export function MetricCard({
  label,
  value,
  loading,
  text = false,
}: {
  label: string;
  value: string | number | ReactNode;
  loading: boolean;
  text?: boolean;
}) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardContent className="p-4">
        <p className="text-sm text-muted-foreground">{label}</p>
        <p className="mt-1 text-2xl font-semibold tabular-nums">
          {loading ? "..." : text ? value : Number(value).toLocaleString("en-KE")}
        </p>
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Branches — shown on a parent account's page instead of its own documents.
// ---------------------------------------------------------------------------

export function BranchesCard({
  customerId,
  branches,
}: {
  customerId: string;
  branches: AcumaticaCustomer[];
}) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
        <div>
          <CardTitle className="text-base">Branches ({branches.length})</CardTitle>
          <p className="text-sm text-muted-foreground">
            Orders are placed against each branch account — pick one to view its documents.
          </p>
        </div>
        <Building2 className="h-5 w-5 text-muted-foreground" />
      </CardHeader>
      <CardContent className="space-y-1.5">
        {branches.map((branch) => (
          <Link
            key={branch.acumatica_id}
            to="/app/customer-orders/$customerId/branch/$branchId"
            params={{ customerId, branchId: branch.acumatica_id }}
            className="flex items-center justify-between rounded-md border bg-muted/20 px-3 py-2.5 text-sm hover:bg-muted/40"
          >
            <div>
              <CustomerLink
                customerId={branch.acumatica_id}
                customerName={branch.name}
                className="font-medium"
              />
              <div className="font-mono text-[11px] text-muted-foreground">
                {branch.acumatica_id}
              </div>
            </div>
            <div className="flex items-center gap-2">
              {branch.status && <Badge variant="secondary">{branch.status}</Badge>}
              <span className="text-xs text-muted-foreground">View documents →</span>
            </div>
          </Link>
        ))}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Common products — most frequently purchased items across the account's SO history.
// ---------------------------------------------------------------------------

export function CommonProductsCard({
  data,
  isLoading,
  isError,
  error,
  onRetry,
  pageSize,
}: {
  data: CommonProductsResponse | undefined;
  isLoading: boolean;
  isError: boolean;
  error: unknown;
  onRetry: () => void;
  pageSize?: number;
}) {
  const { page, perPage, setPage, setPerPage } = usePagination(pageSize ?? 20);
  const products = data?.products ?? [];
  const total = products.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const currentPage = Math.min(page, lastPage);
  const start = (currentPage - 1) * perPage;
  const pagedProducts = products.slice(start, start + perPage);

  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Common Products</CardTitle>
        <p className="text-sm text-muted-foreground">
          Items purchased most often across this account's sales orders.
        </p>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <SkeletonRows count={2} />
        ) : isError ? (
          <ErrorBlock
            message={
              error instanceof Error ? error.message : "Common products could not be loaded."
            }
            onRetry={onRetry}
          />
        ) : total === 0 ? (
          <EmptyBlock message="No product history found for this account." />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead className="text-right">Orders</TableHead>
                  <TableHead className="text-right">Total qty</TableHead>
                  <TableHead className="text-right">Last qty</TableHead>
                  <TableHead>Last ordered</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {pagedProducts.map((item) => (
                  <TableRow key={item.inventory_id}>
                    <TableCell>
                      <ProductListingCell product={item} />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{item.order_count}</TableCell>
                    <TableCell className="text-right tabular-nums">
                      {item.total_qty} {item.uom ?? ""}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {item.last_order_qty} {item.uom ?? ""}
                    </TableCell>
                    <TableCell>
                      <DateWithActions value={item.last_order_date} emptyText="-" />
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            {lastPage > 1 && (
              <div className="mt-4">
                <PaginationControls
                  currentPage={currentPage}
                  lastPage={lastPage}
                  total={total}
                  perPage={perPage}
                  onPageChange={setPage}
                  onPerPageChange={setPerPage}
                />
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Whitespot — recurring items that look overdue for reorder.
// ---------------------------------------------------------------------------

export function SuggestedOrdersCard({
  data,
  isLoading,
  isError,
  error,
  onRetry,
  pageSize,
}: {
  data: SuggestedOrdersResponse | undefined;
  isLoading: boolean;
  isError: boolean;
  error: unknown;
  onRetry: () => void;
  pageSize?: number;
}) {
  const { page, perPage, setPage, setPerPage } = usePagination(pageSize ?? 20);
  const suggestions = data?.suggestions ?? [];
  const total = suggestions.length;
  const lastPage = Math.max(1, Math.ceil(total / perPage));
  const currentPage = Math.min(page, lastPage);
  const start = (currentPage - 1) * perPage;
  const pagedSuggestions = suggestions.slice(start, start + perPage);

  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Items not ordered</CardTitle>
        <p className="text-sm text-muted-foreground">
          Suggested items for the next order based on this account's sales order history.
        </p>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <SkeletonRows count={2} />
        ) : isError ? (
          <ErrorBlock
            message={error instanceof Error ? error.message : "Items not ordered could not be loaded."}
            onRetry={onRetry}
          />
        ) : total === 0 ? (
          <EmptyBlock message="No items not ordered found." />
        ) : (
          <>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead className="text-right">Usual qty</TableHead>
                  <TableHead className="text-right">Last qty</TableHead>
                  <TableHead>Reorders every</TableHead>
                  <TableHead>Last ordered</TableHead>
                  <TableHead>Predicted next order</TableHead>
                  <TableHead className="text-right">Overdue by</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {pagedSuggestions.map((item) => (
                  <TableRow key={item.inventory_id}>
                    <TableCell>
                      <ProductListingCell product={item} />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {item.avg_order_qty} {item.uom ?? ""}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      {item.last_order_qty} {item.uom ?? ""}
                    </TableCell>
                    <TableCell>~{item.avg_interval_days}d</TableCell>
                    <TableCell>
                      <DateWithActions value={item.last_order_date} emptyText="-" />
                    </TableCell>
                    <TableCell>
                      <DateWithActions value={item.next_expected_date} emptyText="-" />
                    </TableCell>
                    <TableCell className="text-right">
                      <Badge variant={item.days_overdue > 14 ? "destructive" : "secondary"}>
                        {item.days_overdue}d
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            {lastPage > 1 && (
              <div className="mt-4">
                <PaginationControls
                  currentPage={currentPage}
                  lastPage={lastPage}
                  total={total}
                  perPage={perPage}
                  onPageChange={setPage}
                  onPerPageChange={setPerPage}
                />
              </div>
            )}
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** Link out to the standalone Whitespot page instead of embedding the full table inline. */
export function SuggestedOrdersLinkCard({ children }: { children: ReactNode }) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardContent className="flex items-center justify-between gap-3 p-4">
        <div className="flex items-center gap-3">
          <ClipboardList className="h-5 w-5 text-muted-foreground" />
          <div>
            <p className="text-sm font-medium">Items not ordered</p>
            <p className="text-xs text-muted-foreground">Suggested items for the next order.</p>
          </div>
        </div>
        {children}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Shared primitives
// ---------------------------------------------------------------------------

export function SkeletonRows({ count = 3 }: { count?: number }) {
  return (
    <div className="space-y-2">
      {Array.from({ length: count }).map((_, index) => (
        <Skeleton key={index} className="h-10 w-full" />
      ))}
    </div>
  );
}

export function ErrorBlock({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
      <span>{message}</span>
      <Button type="button" variant="outline" size="sm" onClick={onRetry}>
        Retry
      </Button>
    </div>
  );
}

export function EmptyBlock({ message }: { message: string }) {
  return (
    <div className="rounded border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
      {message}
    </div>
  );
}

export function numeric(value: unknown): number | null {
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

// ---------------------------------------------------------------------------
// SO detail body — shared between the flat and branch-scoped order detail pages.
// ---------------------------------------------------------------------------

export function summarizeLines(lines: AcumaticaSalesOrderLine[], orderStatus?: string | null) {
  // Use only lines where Acumatica considers the quantity eligible for fill-rate
  // (same OOS-exclusion logic as the FillRateCalculator on the backend).
  // Sum ordered vs shipped to get a quantity-weighted fill rate, which is the
  // only mathematically correct figure for a multi-line order.
  // e.g. SO358552: 316 shipped / 460 ordered = 68.7% ≈ 69%, not the 80% you
  // get from averaging per-line percentages (100+100+0+100+100)/5.
  const eligibleLines = lines.filter((line) => {
    const ordered = numeric(line.order_qty) ?? 0;
    return ordered > 0;
  });

  const totalOrdered = eligibleLines.reduce((sum, l) => sum + (numeric(l.order_qty) ?? 0), 0);
  const finalized = isFinalizedOrderStatus(orderStatus);
  const completedUnavailable = finalized && eligibleLines.some(
    (line) => line.invoice_reconciliation_status !== "reconciled",
  );
  const totalShipped = eligibleLines.reduce((sum, l) => {
    if (finalized) {
      return sum + Math.min(numeric(l.invoiced_qty) ?? 0, numeric(l.order_qty) ?? 0);
    }
    const shipped = numeric(l.shipped_qty) ?? 0;
    const onShipments = numeric(l.qty_on_shipments) ?? 0;
    return sum + Math.max(shipped, onShipments);
  }, 0);

  return {
    fillRate: totalOrdered > 0 && !completedUnavailable ? (totalShipped / totalOrdered) * 100 : null,
    backorderQty: lines.reduce((sum, line) => sum + missingLineQty(line, orderStatus), 0),
  };
}

/** Documents where fulfillment is over — no remainder can still ship. */
const FINALIZED_ORDER_STATUSES = new Set(["completed", "cancelled", "closed", "invoiced"]);

export function isFinalizedOrderStatus(status?: string | null): boolean {
  return !!status && FINALIZED_ORDER_STATUSES.has(status.trim().toLowerCase());
}

/**
 * Missing/open qty for a line — prefer open_qty (Acumatica remainder), not full order qty.
 * Value must be unit_price × this qty (e.g. 460 × 24 = 11,040), never document total.
 *
 * On finalized documents (completed/cancelled/closed/invoiced) nothing can
 * still ship, so the current backorder is always 0 — line open_qty is often
 * stale (frozen at first sync, before shipments), and an Order-vs-Invoice
 * total gap on such documents is a price adjustment, not missing goods.
 * Undelivered history belongs to the fulfillment-history shortfall, not here.
 */
export function missingLineQty(
  line: AcumaticaSalesOrderLine,
  orderStatus?: string | null,
): number {
  if (isFinalizedOrderStatus(orderStatus)) {
    if (line.invoice_reconciliation_status !== "reconciled") return 0;
    const ordered = numeric(line.order_qty) ?? 0;
    const invoiced = Math.min(numeric(line.invoiced_qty) ?? 0, ordered);
    const cancelled = numeric(line.cancelled_qty) ?? 0;
    return Math.max(ordered - invoiced - cancelled, 0);
  }
  const open = numeric(line.open_qty) ?? 0;
  if (open > 0) return open;
  if (line.open_qty !== null && line.open_qty !== undefined && line.open_qty !== "") return 0;
  const bo = numeric(line.backorder_qty) ?? 0;
  if (bo > 0) return bo;
  const ordered = numeric(line.order_qty) ?? 0;
  const shipped = Math.max(numeric(line.shipped_qty) ?? 0, numeric(line.qty_on_shipments) ?? 0);
  const cancelled = numeric(line.cancelled_qty) ?? 0;
  return Math.max(ordered - shipped - cancelled, 0);
}

export function FulfillmentHistoryCard({ order }: { order: AcumaticaSalesOrder }) {
  const history = order.fulfillment_history;
  const invoiceReconciled = isFinalizedOrderStatus(order.status)
    && (order.lines ?? []).some((line) => line.invoice_reconciliation_status === "reconciled");
  if (!history)
    return (
      <Card>
        <CardHeader>
          <CardTitle className="text-base">Historical delivery shortfall</CardTitle>
        </CardHeader>
        <CardContent>
          <EmptyBlock message={invoiceReconciled
            ? "Completed delivery has been reconstructed from released invoice lines and is shown in Unfulfilled lines above; it is not presented as a first-completed snapshot."
            : "No recoverable first-completed snapshot is available for this SO."
          } />
        </CardContent>
      </Card>
    );
  return (
    <Card className={order.delivered_later ? "border-emerald-300" : ""}>
      <CardHeader>
        <div className="flex justify-between gap-2">
          <div>
            <CardTitle className="text-base">Historical delivery shortfall</CardTitle>
            <p className="text-xs text-muted-foreground">
              First completed observation · {new Date(history.observed_at).toLocaleString()} ·{" "}
              {history.source}
            </p>
          </div>
          {order.delivered_later && <Badge className="bg-emerald-600">Delivered later</Badge>}
        </div>
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Item</TableHead>
              <TableHead className="text-right">Ordered</TableHead>
              <TableHead className="text-right">Delivered then</TableHead>
              <TableHead className="text-right">Missing then</TableHead>
              <TableHead className="text-right">Shortfall</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {history.lines.map((line) => (
              <TableRow key={line.id}>
                <TableCell>
                  {line.description ?? line.inventory_id}
                  <div className="font-mono text-xs text-muted-foreground">{line.inventory_id}</div>
                </TableCell>
                <TableCell className="text-right">
                  {Number(line.order_qty).toLocaleString()}
                </TableCell>
                <TableCell className="text-right">
                  {Number(line.delivered_qty).toLocaleString()}
                </TableCell>
                <TableCell className="text-right">
                  {Number(line.open_qty).toLocaleString()}
                </TableCell>
                <TableCell className="text-right">
                  <MaskedKES value={Number(line.shortfall_amount)} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
          <TableFooter>
            <TableRow>
              <TableCell colSpan={4}>Historical total</TableCell>
              <TableCell className="text-right">
                <MaskedKES value={Number(history.historical_shortfall_amount)} />
              </TableCell>
            </TableRow>
          </TableFooter>
        </Table>
        <div className="mt-3 text-sm">
          Current outstanding:{" "}
          <b>
            <MaskedKES value={order.current_outstanding_amount ?? 0} />
          </b>
        </div>
      </CardContent>
    </Card>
  );
}

/** Backorder items on a single SO — unit price × open qty, shown at the top of the document view. */
export function BackorderCard({
  lines,
  orderStatus,
}: {
  lines: AcumaticaSalesOrderLine[];
  orderStatus?: string | null;
}) {
  const isFinalized = isFinalizedOrderStatus(orderStatus);

  // Active (non-finalized) orders: show lines with an open qty still to ship.
  const backorderLines = lines.filter((line) => missingLineQty(line, orderStatus) > 0);

  // Finalized orders (completed/closed/etc): open_qty is stale after sync,
  // so surface lines where shipped < ordered as genuinely undelivered goods.
  const reconciliationUnavailable = isFinalized && lines.some(
    (line) => (numeric(line.order_qty) ?? 0) > 0 && line.invoice_reconciliation_status !== "reconciled",
  );
  const unfulfilledLines = isFinalized
    ? lines.filter((line) => missingLineQty(line, orderStatus) > 0)
    : [];

  const totalQty = backorderLines.reduce(
    (sum, line) => sum + missingLineQty(line, orderStatus),
    0,
  );
  const totalValue = backorderLines.reduce((sum, line) => {
    const qty = missingLineQty(line, orderStatus);
    const unitPrice = numeric(line.unit_price) ?? 0;
    return sum + qty * unitPrice;
  }, 0);

  const unfulfilledValue = unfulfilledLines.reduce((sum, line) => {
    return sum + missingLineQty(line, orderStatus) * (numeric(line.unit_price) ?? 0);
  }, 0);

  const hasItems = backorderLines.length > 0 || unfulfilledLines.length > 0;
  const displayCount = isFinalized ? unfulfilledLines.length : backorderLines.length;
  const displayValue = isFinalized ? unfulfilledValue : totalValue;

  return (
    <Card
      className={`rounded-lg shadow-sm ${hasItems ? "border-destructive/40 bg-destructive/5" : ""}`}
    >
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <CardTitle className="flex items-center gap-1.5 text-base">
              {isFinalized ? "Unfulfilled lines" : "Backorder"}
              <InfoTip text={isFinalized
                ? "Lines where shipped qty < ordered qty on this completed document. These goods were not delivered — value = (ordered − shipped) × unit price per line."
                : "Only quantities still open on this document: unit price × open qty per line — never the document or invoice total. Completed/closed documents show unfulfilled lines instead."
              } />
            </CardTitle>
            <p className="text-sm text-muted-foreground">
              {isFinalized
                ? "Ordered but not shipped — unit price × undelivered qty"
                : "Missing / open qty only (unit price × open qty) — not invoice total."}
            </p>
          </div>
          {hasItems && (
            <div className="flex items-center gap-2 text-sm">
              <Badge variant="destructive">
                {displayCount} item{displayCount !== 1 ? "s" : ""}
              </Badge>
              <span className="font-medium">
                <MaskedKES value={displayValue} />
              </span>
            </div>
          )}
        </div>
      </CardHeader>
      <CardContent>
        {reconciliationUnavailable ? (
          <EmptyBlock message="Completed-order invoice details are unavailable. Sync this order again or review it in Acumatica; fulfillment is not assumed to be 100%." />
        ) : !hasItems ? (
          <EmptyBlock message={isFinalized ? "All eligible ordered quantities were invoiced." : "No backordered items on this document."} />
        ) : isFinalized ? (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead className="text-right">Ordered</TableHead>
                <TableHead className="text-right">Invoiced</TableHead>
                <TableHead className="text-right">Not delivered</TableHead>
                <TableHead className="text-right">Unit Price × Missed</TableHead>
                <TableHead className="text-right">Value</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {unfulfilledLines.map((line) => {
                const ordered = numeric(line.order_qty) ?? 0;
                const invoiced = Math.min(numeric(line.invoiced_qty) ?? 0, ordered);
                const missedQty = missingLineQty(line, orderStatus);
                const unitPrice = numeric(line.unit_price) ?? 0;
                const uom = line.uom?.trim() ? ` ${line.uom}` : "";
                const lineValue = missedQty * unitPrice;
                return (
                  <TableRow key={line.id}>
                    <TableCell><ProductListingCell product={line} /></TableCell>
                    <TableCell className="text-right tabular-nums">{ordered.toLocaleString("en-KE")}{uom}</TableCell>
                    <TableCell className="text-right tabular-nums">{invoiced.toLocaleString("en-KE")}{uom}</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-destructive">
                      {missedQty.toLocaleString("en-KE")}{uom}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      <span className="inline-flex flex-wrap items-center justify-end gap-1">
                        <MaskedKES value={unitPrice} />
                        <span className="text-muted-foreground">×</span>
                        <span>{missedQty.toLocaleString("en-KE")}{uom}</span>
                      </span>
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium text-destructive">
                      <MaskedKES value={lineValue} />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell colSpan={5}>Total missed sale</TableCell>
                <TableCell className="text-right tabular-nums"><MaskedKES value={unfulfilledValue} /></TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        ) : (
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead>
                <TableHead className="text-right">Unit Price × Open Qty</TableHead>
                <TableHead className="text-right">Value</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {backorderLines.map((line) => {
                const qty = missingLineQty(line, orderStatus);
                const unitPrice = numeric(line.unit_price) ?? 0;
                const uom = line.uom?.trim() ? ` ${line.uom}` : "";
                const lineValue = qty * unitPrice;
                return (
                  <TableRow key={line.id}>
                    <TableCell>
                      <ProductListingCell product={line} />
                    </TableCell>
                    <TableCell className="text-right tabular-nums">
                      <span className="inline-flex flex-wrap items-center justify-end gap-1">
                        <MaskedKES value={unitPrice} />
                        <span className="text-muted-foreground">×</span>
                        <span>
                          {qty.toLocaleString("en-KE")}
                          {uom}
                        </span>
                      </span>
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium">
                      <MaskedKES value={lineValue} />
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
            <TableFooter>
              <TableRow>
                <TableCell>Total</TableCell>
                <TableCell className="text-right tabular-nums text-muted-foreground">
                  {totalQty.toLocaleString("en-KE")} units
                </TableCell>
                <TableCell className="text-right tabular-nums">
                  <MaskedKES value={totalValue} />
                </TableCell>
              </TableRow>
            </TableFooter>
          </Table>
        )}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Customer-level backorder card — grouped by InventoryID, each with an
// accordion of the SO numbers carrying that item.
// ---------------------------------------------------------------------------

type InventoryBackorderGroup = {
  inventoryId: string;
  productName: string | null;
  brand: string | null;
  subTradingGroup: string | null;
  postingClass: string | null;
  supplier: string | null;
  uom: string | null;
  totalQty: number;
  totalValue: number;
  lines: BackorderLine[];
};

function groupBackordersByInventory(lines: BackorderLine[]): InventoryBackorderGroup[] {
  const groups = new Map<string, InventoryBackorderGroup>();

  for (const line of lines) {
    // Prefer open_qty (missing remainder); fall back to backorder_qty.
    const qty =
      (numeric(line.open_qty) ?? 0) > 0
        ? (numeric(line.open_qty) ?? 0)
        : (numeric(line.backorder_qty) ?? 0);
    const unitPrice = numeric(line.unit_price) ?? 0;
    let group = groups.get(line.inventory_id);
    if (!group) {
      group = {
        inventoryId: line.inventory_id,
        productName: line.product_name,
        brand: line.brand ?? null,
        subTradingGroup: line.sub_trading_group ?? null,
        postingClass: line.posting_class ?? null,
        supplier: line.supplier ?? null,
        uom: line.uom,
        totalQty: 0,
        totalValue: 0,
        lines: [],
      };
      groups.set(line.inventory_id, group);
    }
    group.totalQty += qty;
    group.totalValue += qty * unitPrice;
    group.lines.push(line);
  }

  return Array.from(groups.values()).sort((a, b) => b.totalValue - a.totalValue);
}

export function CustomerBackorderCard({
  customerId,
  dateFrom,
  dateTo,
  includeBranches = false,
}: {
  customerId: string;
  dateFrom?: string;
  dateTo?: string;
  /** When true, include backorders on child branch accounts of this customer. */
  includeBranches?: boolean;
}) {
  const backorders = useBackorders({
    customer_id: customerId,
    date_from: dateFrom,
    date_to: dateTo,
    per_page: 1000,
    include_branches: includeBranches || undefined,
  });

  const [q, setQ] = useState("");

  const lines = useMemo(() => backorders.data?.data ?? [], [backorders.data]);
  const filteredLines = useMemo(() => {
    const needle = q.trim().toLowerCase();
    if (!needle) return lines;
    const digits = needle.replace(/\D+/g, "");
    return lines.filter((line) => {
      const hay = [
        line.order_nbr,
        line.customer_name,
        line.customer_acumatica_id,
        line.inventory_id,
        line.product_name,
      ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();
      if (hay.includes(needle)) return true;
      // Match SO359099 when only 359099 is typed.
      return digits.length > 0 && (line.order_nbr ?? "").includes(digits);
    });
  }, [lines, q]);
  const groups = useMemo(() => groupBackordersByInventory(filteredLines), [filteredLines]);
  const totalQty = groups.reduce((sum, group) => sum + group.totalQty, 0);
  const totalValue = groups.reduce((sum, group) => sum + group.totalValue, 0);
  const searchActive = q.trim().length > 0;

  return (
    <Card
      className={`rounded-lg shadow-sm ${groups.length > 0 ? "border-destructive/40 bg-destructive/5" : ""}`}
    >
      <CardHeader className="pb-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <div>
            <CardTitle className="flex items-center gap-1.5 text-base">
              Backorder
              <InfoTip text="Goods still owed to this account: unshipped remainder × unit price per line, from open sales orders only. Completed documents never appear here — their undelivered value lives in the historical shortfall." />
            </CardTitle>
            <p className="text-sm text-muted-foreground">
              Grouped by Inventory ID — expand a SKU to see SO numbers (unit price × qty).
              {includeBranches ? " Includes branch accounts." : ""}
            </p>
          </div>
          {groups.length > 0 && (
            <div className="flex items-center gap-2 text-sm">
              <Badge variant="destructive">
                {groups.length} SKU{groups.length !== 1 ? "s" : ""}
              </Badge>
              <span className="tabular-nums text-muted-foreground">
                {totalQty.toLocaleString("en-KE")} units
              </span>
              <span className="font-medium">
                <MaskedKES value={totalValue} />
              </span>
            </div>
          )}
        </div>
        {(lines.length > 0 || searchActive) && (
          <div className="relative mt-2 max-w-sm">
            <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
            <Input
              className="pl-8 pr-9"
              placeholder="Search SO number, customer, product…"
              value={q}
              onChange={(event) => setQ(event.target.value)}
            />
            {q && (
              <button
                type="button"
                onClick={() => setQ("")}
                className="absolute right-2 top-2 rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label="Clear search"
              >
                <X className="h-4 w-4" />
              </button>
            )}
          </div>
        )}
      </CardHeader>
      <CardContent>
        {backorders.isLoading ? (
          <SkeletonRows count={2} />
        ) : backorders.isError ? (
          <ErrorBlock
            message={
              backorders.error instanceof Error
                ? backorders.error.message
                : "Backorders could not be loaded."
            }
            onRetry={() => backorders.refetch()}
          />
        ) : groups.length === 0 ? (
          <EmptyBlock
            message={
              searchActive
                ? "No backordered items match this search."
                : "No backordered items for this account in the selected period."
            }
          />
        ) : (
          <Accordion
            type="multiple"
            className="w-full"
            key={searchActive ? `search:${q}` : "browse"}
            defaultValue={
              searchActive && groups.length <= 10
                ? groups.map((group) => group.inventoryId)
                : undefined
            }
          >
            {groups.map((group) => (
              <AccordionItem key={group.inventoryId} value={group.inventoryId}>
                <AccordionTrigger>
                  <div className="flex w-full flex-wrap items-center justify-between gap-2 pr-2">
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
                    <div className="flex items-center gap-2 text-sm">
                      <Badge variant="secondary">
                        {group.lines.length} SO{group.lines.length !== 1 ? "s" : ""}
                      </Badge>
                      <span className="tabular-nums text-muted-foreground">
                        {group.totalQty.toLocaleString("en-KE")} {group.uom ?? ""}
                      </span>
                      <span className="font-medium">
                        <MaskedKES value={group.totalValue} />
                      </span>
                    </div>
                  </div>
                </AccordionTrigger>
                <AccordionContent>
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead>SO</TableHead>
                        <TableHead>Date</TableHead>
                        <TableHead className="text-right">Unit Price × Qty</TableHead>
                        <TableHead className="text-right">Value</TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {group.lines.map((line) => {
                        const qty =
                          (numeric(line.open_qty) ?? 0) > 0
                            ? (numeric(line.open_qty) ?? 0)
                            : (numeric(line.backorder_qty) ?? 0);
                        const unitPrice = numeric(line.unit_price) ?? 0;
                        const uom = line.uom?.trim() ? ` ${line.uom}` : "";
                        const lineCustomerId = line.customer_acumatica_id ?? customerId;
                        const isBranchLine = includeBranches && lineCustomerId !== customerId;
                        return (
                          <TableRow key={line.id}>
                            <TableCell>
                              <OrderLink
                                customerId={isBranchLine ? customerId : lineCustomerId}
                                branchId={isBranchLine ? lineCustomerId : undefined}
                                orderId={line.order_nbr}
                              />
                            </TableCell>
                            <TableCell className="whitespace-nowrap text-sm text-muted-foreground">
                              <DateWithActions value={line.order_date ?? null} emptyText="-" />
                            </TableCell>
                            <TableCell className="text-right tabular-nums">
                              <span className="inline-flex flex-wrap items-center justify-end gap-1">
                                <MaskedKES value={unitPrice} />
                                <span className="text-muted-foreground">×</span>
                                <span>
                                  {qty.toLocaleString("en-KE")}
                                  {uom}
                                </span>
                              </span>
                            </TableCell>
                            <TableCell className="text-right tabular-nums font-medium">
                              <MaskedKES value={qty * unitPrice} />
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </AccordionContent>
              </AccordionItem>
            ))}
          </Accordion>
        )}
      </CardContent>
    </Card>
  );
}

export function OrderDetailBody({
  order,
  lines,
  /** When false, parent page renders BackorderCard at the very top. Default true for safety. */
  includeBackorderCard = true,
}: {
  order: AcumaticaSalesOrder;
  lines: AcumaticaSalesOrderLine[];
  includeBackorderCard?: boolean;
}) {
  const summary = summarizeLines(lines, order.status);
  const isFinalized = isFinalizedOrderStatus(order.status);

  // For finalized orders compute total missed qty (ordered - shipped) for the KPI card.
  const missedQtyTotal = isFinalized
    ? lines.reduce((sum, line) => {
        const ordered = numeric(line.order_qty) ?? 0;
        const shipped = Math.max(numeric(line.shipped_qty) ?? 0, numeric(line.qty_on_shipments) ?? 0);
        return sum + Math.max(ordered - shipped, 0);
      }, 0)
    : summary.backorderQty;

  return (
    <>
      {includeBackorderCard && <BackorderCard lines={lines} orderStatus={order.status} />}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard
          label="Document total"
          value={<MaskedKES value={Number(order.order_total ?? 0)} />}
          loading={false}
          text
        />
        <MetricCard label="Lines" value={lines.length} loading={false} />
        <MetricCard
          label="Fill rate"
          value={summary.fillRate === null ? "-" : `${summary.fillRate.toFixed(1)}%`}
          loading={false}
          text
        />
        <MetricCard
          label={isFinalized ? "Missed qty" : "Backorder qty"}
          value={missedQtyTotal}
          loading={false}
        />
      </div>

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Document Details</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Info label="Status" value={order.status ?? "-"} />
          <Info label="PO" value={order.customer_order ?? "-"} mono />
          <Info
            label="Date"
            value={formatDate(order.order_date)}
            node={<DateWithActions value={order.order_date} emptyText="-" />}
          />
          <Info
            label="Consultant"
            value={order.sales_consultant_name ?? order.sales_consultant_rep_code ?? "-"}
            node={
              order.sales_consultant_rep_code || order.sales_consultant_name ? (
                <ConsultantLink
                  repCode={order.sales_consultant_rep_code}
                  consultantName={order.sales_consultant_name}
                />
              ) : undefined
            }
          />
          {order.description && order.description.trim() !== "" && (
            <div className="sm:col-span-2 lg:col-span-4">
              <Info label="Description" value={order.description} />
            </div>
          )}
        </CardContent>
      </Card>

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Line Fill Rate</CardTitle>
        </CardHeader>
        <CardContent>
          {lines.length === 0 ? (
            <EmptyBlock message="No line details found for this document." />
          ) : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Item</TableHead>
                  <TableHead className="text-right">Ordered</TableHead>
                  <TableHead className="text-right">Shipped</TableHead>
                  <TableHead className="text-right">Open</TableHead>
                  <TableHead className="text-right">Backorder</TableHead>
                  <TableHead className="text-right">Unit Price</TableHead>
                  <TableHead className="text-right">Missed Sale</TableHead>
                  <TableHead className="text-right">Fill Rate</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Possible Reason</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((line) => {
                  const reason = fillRateIssueReason(line);
                  const shipped = Math.max(numeric(line.shipped_qty) ?? 0, numeric(line.qty_on_shipments) ?? 0);
                  const ordered = numeric(line.order_qty) ?? 0;
                  // A line is unfilled if shipped quantity is less than ordered — regardless
                  // of order status. This makes OOS lines visible even on "Completed" orders.
                  const isUnfilled = ordered > 0 && shipped < ordered;
                  const openQty = numeric(line.open_qty) ?? 0;
                  const backorderQty = numeric(line.backorder_qty) ?? 0;
                  // Missed qty = explicit open_qty, or backorder_qty, or computed remainder.
                  const missedQty = openQty > 0 ? openQty
                    : backorderQty > 0 ? backorderQty
                    : Math.max(ordered - shipped, 0);
                  const unitPrice = numeric(line.unit_price) ?? 0;
                  const missedValue = missedQty * unitPrice;
                  const rowClass = isUnfilled ? "bg-destructive/5" : "";
                  return (
                    <TableRow key={line.id} className={rowClass}>
                      <TableCell>
                        <ProductListingCell product={line} />
                      </TableCell>
                      <QtyCell value={line.order_qty} />
                      <QtyCell value={line.shipped_qty} />
                      <TableCell className={`text-right tabular-nums ${isUnfilled && openQty > 0 ? "font-semibold text-destructive" : ""}`}>
                        {(openQty).toLocaleString("en-KE")}
                      </TableCell>
                      <TableCell className={`text-right tabular-nums ${isUnfilled && backorderQty > 0 ? "font-semibold text-destructive" : ""}`}>
                        {(backorderQty).toLocaleString("en-KE")}
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-muted-foreground">
                        {unitPrice > 0 ? <MaskedKES value={unitPrice} /> : <span className="text-muted-foreground">-</span>}
                      </TableCell>
                      <TableCell className={`text-right tabular-nums ${isUnfilled && missedValue > 0 ? "font-semibold text-destructive" : "text-muted-foreground"}`}>
                        {missedValue > 0 ? (
                          <span title={`${missedQty.toLocaleString("en-KE")} × ${unitPrice.toLocaleString("en-KE", { minimumFractionDigits: 2 })}`}>
                            <MaskedKES value={missedValue} />
                          </span>
                        ) : (
                          <span className="text-muted-foreground">-</span>
                        )}
                      </TableCell>
                      <TableCell className={`text-right ${isUnfilled ? "font-semibold text-destructive" : ""}`}>
                        {formatPercent(line.fill_rate_pct)}
                      </TableCell>
                      <TableCell>
                        <Badge variant={isUnfilled ? "destructive" : "secondary"}>
                          {line.fulfillment_status ?? (line.completed ? "completed" : "-")}
                        </Badge>
                      </TableCell>
                      <TableCell className="text-xs">
                        {reason ? (
                          <span className="text-destructive">{reason}</span>
                        ) : (
                          <span className="text-muted-foreground">-</span>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          )}
        </CardContent>
      </Card>
    </>
  );
}

export function QtyCell({ value }: { value: unknown }) {
  return (
    <TableCell className="text-right tabular-nums">
      {(numeric(value) ?? 0).toLocaleString("en-KE")}
    </TableCell>
  );
}

export function Info({
  label,
  value,
  mono = false,
  node,
}: {
  label: string;
  value: string;
  mono?: boolean;
  node?: ReactNode;
}) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <div className={`mt-1 text-sm font-medium ${mono ? "font-mono" : ""}`}>{node ?? value}</div>
    </div>
  );
}

export function formatPercent(value: unknown) {
  const number = numeric(value);
  return number === null ? "-" : `${number.toFixed(1)}%`;
}

export function formatDate(value: string | null) {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" });
}
