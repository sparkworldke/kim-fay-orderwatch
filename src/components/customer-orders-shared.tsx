import type { ReactNode } from "react";
import { Link } from "@tanstack/react-router";
import { DateLink } from "@/components/entity-links";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import type { CommonProductsResponse, SuggestedOrdersResponse } from "@/hooks/useCustomers";
import { fillRateIssueReason } from "@/lib/order-reasons";
import { formatKES } from "@/lib/format";
import type { AcumaticaCustomer, AcumaticaSalesOrder, AcumaticaSalesOrderLine } from "@/types/admin";
import { Building2, ClipboardList } from "lucide-react";

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
          <TableHead className="text-right">Total</TableHead>
          <TableHead>Date</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {docs.map((doc) => {
          const fillRate = numeric(doc.lines_avg_fill_rate_pct);
          const backorderQty = numeric(doc.lines_sum_backorder_qty) ?? 0;

          return (
            <TableRow key={doc.id}>
              <TableCell>
                {branchId ? (
                  <Link
                    to="/app/customer-orders/$customerId/branch/$branchId/so/$orderId"
                    params={{ customerId, branchId, orderId: doc.acumatica_order_nbr }}
                    className="font-mono font-medium underline-offset-4 hover:underline"
                  >
                    {doc.acumatica_order_nbr}
                  </Link>
                ) : (
                  <Link
                    to="/app/customer-orders/$customerId/so/$orderId"
                    params={{ customerId, orderId: doc.acumatica_order_nbr }}
                    className="font-mono font-medium underline-offset-4 hover:underline"
                  >
                    {doc.acumatica_order_nbr}
                  </Link>
                )}
                {doc.customer_order && <div className="text-xs text-muted-foreground">PO {doc.customer_order}</div>}
              </TableCell>
              <TableCell><Badge variant="outline">{doc.order_type}</Badge></TableCell>
              <TableCell><Badge variant="secondary">{doc.status ?? "-"}</Badge></TableCell>
              <TableCell className="text-right">
                {fillRate === null ? (
                  "-"
                ) : (
                  <span className={backorderQty > 0 ? "font-medium text-destructive" : ""}>
                    {fillRate.toFixed(1)}%
                  </span>
                )}
              </TableCell>
              <TableCell className="text-right tabular-nums">{backorderQty.toLocaleString("en-KE")}</TableCell>
              <TableCell className="text-right tabular-nums">{formatKES(Number(doc.order_total ?? 0))}</TableCell>
              <TableCell>
                <DateLink value={doc.order_date} emptyText="-">{formatDate(doc.order_date)}</DateLink>
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
    avgFillRate: fillRates.length > 0 ? fillRates.reduce((sum, value) => sum + value, 0) / fillRates.length : null,
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
      <MetricCard label="Total value" value={formatKES(summary.totalValue)} loading={loading} text />
      <MetricCard label="Avg fill rate" value={summary.avgFillRate === null ? "-" : `${summary.avgFillRate.toFixed(1)}%`} loading={loading} text />
      <MetricCard label="Backorder qty" value={summary.backorderQty} loading={loading} />
    </div>
  );
}

export function MetricCard({ label, value, loading, text = false }: { label: string; value: string | number; loading: boolean; text?: boolean }) {
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

export function BranchesCard({ customerId, branches }: { customerId: string; branches: AcumaticaCustomer[] }) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="flex flex-row items-center justify-between gap-3 pb-3">
        <div>
          <CardTitle className="text-base">Branches ({branches.length})</CardTitle>
          <p className="text-sm text-muted-foreground">Orders are placed against each branch account — pick one to view its documents.</p>
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
              <div className="font-medium">{branch.name}</div>
              <div className="font-mono text-[11px] text-muted-foreground">{branch.acumatica_id}</div>
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
}: {
  data: CommonProductsResponse | undefined;
  isLoading: boolean;
  isError: boolean;
  error: unknown;
  onRetry: () => void;
}) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Common Products</CardTitle>
        <p className="text-sm text-muted-foreground">Items purchased most often across this account's sales orders.</p>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <SkeletonRows count={2} />
        ) : isError ? (
          <ErrorBlock message={error instanceof Error ? error.message : "Common products could not be loaded."} onRetry={onRetry} />
        ) : data && data.products.length === 0 ? (
          <EmptyBlock message="No product history found for this account." />
        ) : (
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
              {data?.products.map((item) => (
                <TableRow key={item.inventory_id}>
                  <TableCell>
                    <div className="font-medium">{item.description ?? item.inventory_id}</div>
                    <div className="font-mono text-xs text-muted-foreground">{item.inventory_id}</div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{item.order_count}</TableCell>
                  <TableCell className="text-right tabular-nums">{item.total_qty} {item.uom ?? ""}</TableCell>
                  <TableCell className="text-right tabular-nums">{item.last_order_qty} {item.uom ?? ""}</TableCell>
                  <TableCell>{formatDate(item.last_order_date)}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  );
}

// ---------------------------------------------------------------------------
// Whitspot — recurring items that look overdue for reorder.
// ---------------------------------------------------------------------------

export function SuggestedOrdersCard({
  data,
  isLoading,
  isError,
  error,
  onRetry,
}: {
  data: SuggestedOrdersResponse | undefined;
  isLoading: boolean;
  isError: boolean;
  error: unknown;
  onRetry: () => void;
}) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardHeader className="pb-3">
        <CardTitle className="text-base">Whitspot</CardTitle>
        <p className="text-sm text-muted-foreground">Suggested items for the next order based on this account's sales order history.</p>
      </CardHeader>
      <CardContent>
        {isLoading ? (
          <SkeletonRows count={2} />
        ) : isError ? (
          <ErrorBlock message={error instanceof Error ? error.message : "Whitspot could not be loaded."} onRetry={onRetry} />
        ) : data && data.suggestions.length === 0 ? (
          <EmptyBlock message="No Whitspot items found." />
        ) : (
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
              {data?.suggestions.map((item) => (
                <TableRow key={item.inventory_id}>
                  <TableCell>
                    <div className="font-medium">{item.description ?? item.inventory_id}</div>
                    <div className="font-mono text-xs text-muted-foreground">{item.inventory_id}</div>
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{item.avg_order_qty} {item.uom ?? ""}</TableCell>
                  <TableCell className="text-right tabular-nums">{item.last_order_qty} {item.uom ?? ""}</TableCell>
                  <TableCell>~{item.avg_interval_days}d</TableCell>
                  <TableCell>{formatDate(item.last_order_date)}</TableCell>
                  <TableCell>{formatDate(item.next_expected_date)}</TableCell>
                  <TableCell className="text-right">
                    <Badge variant={item.days_overdue > 14 ? "destructive" : "secondary"}>{item.days_overdue}d</Badge>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        )}
      </CardContent>
    </Card>
  );
}

/** Link out to the standalone Whitspot page instead of embedding the full table inline. */
export function SuggestedOrdersLinkCard({ children }: { children: ReactNode }) {
  return (
    <Card className="rounded-lg shadow-sm">
      <CardContent className="flex items-center justify-between gap-3 p-4">
        <div className="flex items-center gap-3">
          <ClipboardList className="h-5 w-5 text-muted-foreground" />
          <div>
            <p className="text-sm font-medium">Whitspot</p>
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
      {Array.from({ length: count }).map((_, index) => <Skeleton key={index} className="h-10 w-full" />)}
    </div>
  );
}

export function ErrorBlock({ message, onRetry }: { message: string; onRetry: () => void }) {
  return (
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-md border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
      <span>{message}</span>
      <Button type="button" variant="outline" size="sm" onClick={onRetry}>Retry</Button>
    </div>
  );
}

export function EmptyBlock({ message }: { message: string }) {
  return <div className="rounded border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">{message}</div>;
}

export function numeric(value: unknown): number | null {
  const number = Number(value);
  return Number.isFinite(number) ? number : null;
}

// ---------------------------------------------------------------------------
// SO detail body — shared between the flat and branch-scoped order detail pages.
// ---------------------------------------------------------------------------

export function summarizeLines(lines: AcumaticaSalesOrderLine[]) {
  const ordered = lines.reduce((sum, line) => sum + (numeric(line.order_qty) ?? 0), 0);
  const shipped = lines.reduce((sum, line) => sum + (numeric(line.shipped_qty) ?? 0), 0);
  const fillRates = lines.map((line) => numeric(line.fill_rate_pct)).filter((value): value is number => value !== null);

  return {
    fillRate: fillRates.length > 0 ? fillRates.reduce((sum, value) => sum + value, 0) / fillRates.length : ordered > 0 ? (shipped / ordered) * 100 : null,
    backorderQty: lines.reduce((sum, line) => sum + (numeric(line.backorder_qty) ?? 0), 0),
  };
}

export function OrderDetailBody({ order, lines }: { order: AcumaticaSalesOrder; lines: AcumaticaSalesOrderLine[] }) {
  const summary = summarizeLines(lines);

  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <MetricCard label="Document total" value={formatKES(Number(order.order_total ?? 0))} loading={false} text />
        <MetricCard label="Lines" value={lines.length} loading={false} />
        <MetricCard label="Fill rate" value={summary.fillRate === null ? "-" : `${summary.fillRate.toFixed(1)}%`} loading={false} text />
        <MetricCard label="Backorder qty" value={summary.backorderQty} loading={false} />
      </div>

      <Card className="rounded-lg shadow-sm">
        <CardHeader className="pb-3">
          <CardTitle className="text-base">Document Details</CardTitle>
        </CardHeader>
        <CardContent className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Info label="Status" value={order.status ?? "-"} />
          <Info label="PO" value={order.customer_order ?? "-"} mono />
          <Info label="Date" value={formatDate(order.order_date)} />
          <Info label="Consultant" value={order.sales_consultant_name ?? order.sales_consultant_rep_code ?? "-"} />
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
                  <TableHead className="text-right">Fill Rate</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Possible Reason</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((line) => {
                  const reason = fillRateIssueReason(line);
                  return (
                    <TableRow key={line.id}>
                      <TableCell>
                        <div className="font-medium">{line.description ?? line.inventory_id ?? "-"}</div>
                        <div className="font-mono text-xs text-muted-foreground">{line.inventory_id ?? "-"}</div>
                      </TableCell>
                      <QtyCell value={line.order_qty} />
                      <QtyCell value={line.shipped_qty} />
                      <QtyCell value={line.open_qty} />
                      <QtyCell value={line.backorder_qty} />
                      <TableCell className="text-right">{formatPercent(line.fill_rate_pct)}</TableCell>
                      <TableCell><Badge variant="secondary">{line.fulfillment_status ?? (line.completed ? "completed" : "-")}</Badge></TableCell>
                      <TableCell className="text-xs">
                        {reason ? <span className="text-destructive">{reason}</span> : <span className="text-muted-foreground">-</span>}
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
  return <TableCell className="text-right tabular-nums">{(numeric(value) ?? 0).toLocaleString("en-KE")}</TableCell>;
}

export function Info({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
  return (
    <div>
      <p className="text-xs text-muted-foreground">{label}</p>
      <p className={`mt-1 text-sm font-medium ${mono ? "font-mono" : ""}`}>{value}</p>
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
