import { type ReactNode, useState } from "react";
import { Link } from "@tanstack/react-router";
import { AlertTriangle, ChevronDown, ChevronRight, Package } from "lucide-react";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { MaskedKES } from "@/components/MaskedCurrency";
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from "@/components/ui/popover";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { cn } from "@/lib/utils";

export interface BrandMetrics {
  so_count: number;
  ordered_qty: number;
  ordered_value: number;
  sold_qty: number;
  sold_value: number;
  undelivered_qty: number;
  undelivered_value: number;
  fill_rate_pct: number | null;
  avg_daily_sold_qty: number;
  avg_daily_sold_value: number;
  projected_month_end_qty: number;
  projected_month_end_value: number;
}

export interface UndeliveredSkuRow {
  inventory_id: string;
  description: string;
  unit_price: number;
  ordered_qty: number;
  sold_qty: number;
  undelivered_qty: number;
  undelivered_value: number;
  so_count: number;
  /** Why this SKU is at risk (unique reason labels). */
  reasons?: string[];
  /** Sales order numbers contributing to open qty. */
  order_nbrs?: string[];
}

export interface BrandRow extends BrandMetrics {
  brand: string;
  undelivered_skus?: UndeliveredSkuRow[];
  undelivered_sku_count?: number;
}
export interface ReasonRow {
  reason: string;
  quantity: number;
  value: number;
  so_count: number;
}
export interface SalesOrderRow extends BrandMetrics {
  id: number;
  order_nbr: string;
  order_date: string | null;
  status: string | null;
  brands: BrandRow[];
  reasons: string[];
}
export interface CustomerBrandRow {
  customer: { id: string; name: string; customer_class?: string | null };
  totals: BrandMetrics | null;
  brands: BrandRow[];
  undelivered_reasons: ReasonRow[];
  sales_orders: SalesOrderRow[];
}

const qty = (value: number) => value.toLocaleString("en-KE", { maximumFractionDigits: 2 });
const rate = (value: number | null) => (value == null ? "—" : `${value.toFixed(1)}%`);

export function MetricStrip({ metrics }: { metrics: BrandMetrics }) {
  const items = [
    ["SO", metrics.so_count.toLocaleString("en-KE")],
    ["Ordered", qty(metrics.ordered_qty)],
    ["Sold", qty(metrics.sold_qty)],
    ["Undelivered", qty(metrics.undelivered_qty)],
    ["Fill rate", rate(metrics.fill_rate_pct)],
  ];
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
      {items.map(([label, value]) => (
        <div key={label} className="rounded border bg-card p-2">
          <div className="text-xs text-muted-foreground">{label}</div>
          <div className="font-semibold tabular-nums">{value}</div>
        </div>
      ))}
    </div>
  );
}

export interface BrandRollupRow extends BrandMetrics {
  brand: string;
  customer_count?: number;
  undelivered_skus?: UndeliveredSkuRow[];
  undelivered_sku_count?: number;
}

export interface PortfolioSummary extends BrandMetrics {
  customer_count: number;
  brand_count: number;
}

export function BrandTable({
  rows,
  showCustomers = false,
}: {
  rows: Array<BrandRow | BrandRollupRow>;
  showCustomers?: boolean;
}) {
  const [expanded, setExpanded] = useState<string | null>(null);
  const colCount =
    8 +
    (showCustomers ? 1 : 0) +
    ("avg_daily_sold_qty" in (rows[0] ?? {}) ? 1 : 0) +
    ("projected_month_end_value" in (rows[0] ?? {}) ? 1 : 0);

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead className="w-8" />
          <TableHead>Brand</TableHead>
          {showCustomers && <TableHead className="text-right">Customers</TableHead>}
          <TableHead className="text-right">SO</TableHead>
          <TableHead className="text-right">Ordered</TableHead>
          <TableHead className="text-right">Sold</TableHead>
          <TableHead className="text-right">Sold value</TableHead>
          <TableHead className="text-right">Undelivered</TableHead>
          <TableHead className="text-right">At risk</TableHead>
          <TableHead className="text-right">Fill</TableHead>
          {"avg_daily_sold_qty" in (rows[0] ?? {}) && (
            <TableHead className="text-right">Run rate / day</TableHead>
          )}
          {"projected_month_end_value" in (rows[0] ?? {}) && (
            <TableHead className="text-right">Projected</TableHead>
          )}
        </TableRow>
      </TableHeader>
      <TableBody>
        {rows.map((row) => {
          const skus = row.undelivered_skus ?? [];
          const skuCount = row.undelivered_sku_count ?? skus.length;
          const isOpen = expanded === row.brand;
          const canExpand = skuCount > 0 || row.undelivered_qty > 0;

          return (
            <FragmentBrandRows
              key={row.brand}
              row={row}
              showCustomers={showCustomers}
              skus={skus}
              skuCount={skuCount}
              isOpen={isOpen}
              canExpand={canExpand}
              colCount={colCount}
              onToggle={() => setExpanded(isOpen ? null : row.brand)}
            />
          );
        })}
      </TableBody>
    </Table>
  );
}

function FragmentBrandRows({
  row,
  showCustomers,
  skus,
  skuCount,
  isOpen,
  canExpand,
  colCount,
  onToggle,
}: {
  row: BrandRow | BrandRollupRow;
  showCustomers: boolean;
  skus: UndeliveredSkuRow[];
  skuCount: number;
  isOpen: boolean;
  canExpand: boolean;
  colCount: number;
  onToggle: () => void;
}) {
  return (
    <>
      <TableRow
        className={cn(canExpand && "cursor-pointer hover:bg-muted/40", isOpen && "bg-muted/20")}
        onClick={() => canExpand && onToggle()}
      >
        <TableCell className="w-8 px-2 text-muted-foreground">
          {canExpand ? (
            isOpen ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />
          ) : null}
        </TableCell>
        <TableCell className="font-medium">
          <div className="flex flex-wrap items-center gap-1.5">
            <span>{row.brand}</span>
            {skuCount > 0 && (
              <Badge variant="outline" className="text-[10px] text-amber-700">
                {skuCount} SKU{skuCount === 1 ? "" : "s"} open
              </Badge>
            )}
          </div>
        </TableCell>
        {showCustomers && (
          <TableCell className="text-right tabular-nums">
            {"customer_count" in row ? (row.customer_count ?? 0) : "—"}
          </TableCell>
        )}
        <TableCell className="text-right">{row.so_count}</TableCell>
        <TableCell className="text-right">{qty(row.ordered_qty)}</TableCell>
        <TableCell className="text-right">{qty(row.sold_qty)}</TableCell>
        <TableCell className="text-right">
          <MaskedKES value={row.sold_value} />
        </TableCell>
        <TableCell className="text-right text-amber-700">{qty(row.undelivered_qty)}</TableCell>
        <TableCell className="text-right text-amber-700">
          <MaskedKES value={row.undelivered_value} />
        </TableCell>
        <TableCell className="text-right">{rate(row.fill_rate_pct)}</TableCell>
        {"avg_daily_sold_qty" in row && row.avg_daily_sold_qty != null && (
          <TableCell className="text-right">{qty(row.avg_daily_sold_qty as number)}</TableCell>
        )}
        {"projected_month_end_value" in row && row.projected_month_end_value != null && (
          <TableCell className="text-right">
            <MaskedKES value={row.projected_month_end_value as number} />
          </TableCell>
        )}
      </TableRow>

      {isOpen && (
        <TableRow className="hover:bg-transparent">
          <TableCell colSpan={colCount} className="bg-muted/10 p-0">
            <div className="border-t px-3 py-3">
              <div className="mb-2 flex items-center gap-2 text-xs font-semibold text-muted-foreground">
                <Package className="h-3.5 w-3.5" />
                Undelivered SKU split — {row.brand}
                <span className="font-normal">
                  (qty not delivered · unit price · inventory ID · why at risk · SOs)
                </span>
              </div>
              {skus.length === 0 ? (
                <p className="text-xs text-muted-foreground">No undelivered SKUs for this brand.</p>
              ) : (
                <div className="overflow-x-auto rounded border bg-card">
                  <table className="w-full text-xs">
                    <thead className="bg-muted/40">
                      <tr>
                        <th className="px-3 py-2 text-left font-semibold">Inventory ID</th>
                        <th className="px-3 py-2 text-left font-semibold">Product</th>
                        <th className="px-3 py-2 text-right font-semibold">Unit price</th>
                        <th className="px-3 py-2 text-right font-semibold">Ordered</th>
                        <th className="px-3 py-2 text-right font-semibold">Sold</th>
                        <th className="px-3 py-2 text-right font-semibold text-amber-800">Not delivered</th>
                        <th className="px-3 py-2 text-right font-semibold text-amber-800">At risk</th>
                        <th className="px-3 py-2 text-left font-semibold text-amber-800">Why at risk</th>
                        <th className="px-3 py-2 text-right font-semibold">SOs</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y">
                      {skus.map((sku) => (
                        <tr key={sku.inventory_id} className="hover:bg-muted/20">
                          <td className="px-3 py-1.5 font-mono text-[11px] font-semibold">
                            {sku.inventory_id}
                          </td>
                          <td className="max-w-[220px] truncate px-3 py-1.5">
                            {sku.description || "—"}
                          </td>
                          <td className="px-3 py-1.5 text-right tabular-nums">
                            <MaskedKES value={sku.unit_price} />
                          </td>
                          <td className="px-3 py-1.5 text-right tabular-nums">{qty(sku.ordered_qty)}</td>
                          <td className="px-3 py-1.5 text-right tabular-nums">{qty(sku.sold_qty)}</td>
                          <td className="px-3 py-1.5 text-right tabular-nums font-medium text-amber-700">
                            {qty(sku.undelivered_qty)}
                          </td>
                          <td className="px-3 py-1.5 text-right tabular-nums text-amber-700">
                            <MaskedKES value={sku.undelivered_value} />
                          </td>
                          <td className="px-3 py-1.5" onClick={(e) => e.stopPropagation()}>
                            <SkuReasonsCell reasons={sku.reasons ?? []} />
                          </td>
                          <td className="px-3 py-1.5 text-right" onClick={(e) => e.stopPropagation()}>
                            <SkuOrdersCell
                              soCount={sku.so_count}
                              orderNbrs={sku.order_nbrs ?? []}
                              inventoryId={sku.inventory_id}
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          </TableCell>
        </TableRow>
      )}
    </>
  );
}

function SkuReasonsCell({ reasons }: { reasons: string[] }) {
  if (reasons.length === 0) {
    return <span className="text-muted-foreground">—</span>;
  }
  if (reasons.length === 1) {
    return (
      <span className="inline-flex max-w-[180px] items-center gap-1 text-amber-800" title={reasons[0]}>
        <AlertTriangle className="h-3 w-3 shrink-0" />
        <span className="truncate">{reasons[0]}</span>
      </span>
    );
  }
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="sm"
          className="h-auto max-w-[200px] gap-1 px-1.5 py-0.5 text-xs font-normal text-amber-800 hover:bg-amber-50 hover:text-amber-900"
          onClick={(e) => e.stopPropagation()}
        >
          <AlertTriangle className="h-3 w-3 shrink-0" />
          <span className="truncate">{reasons[0]}</span>
          <Badge variant="outline" className="shrink-0 border-amber-300 text-[10px] text-amber-800">
            +{reasons.length - 1}
          </Badge>
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-72 p-3" align="start" onClick={(e) => e.stopPropagation()}>
        <div className="mb-1.5 text-xs font-semibold text-muted-foreground">Why at risk</div>
        <ul className="space-y-1.5">
          {reasons.map((reason) => (
            <li
              key={reason}
              className="flex items-start gap-1.5 rounded border bg-muted/30 px-2 py-1.5 text-xs"
            >
              <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0 text-amber-600" />
              <span>{reason}</span>
            </li>
          ))}
        </ul>
      </PopoverContent>
    </Popover>
  );
}

function SkuOrdersCell({
  soCount,
  orderNbrs,
  inventoryId,
}: {
  soCount: number;
  orderNbrs: string[];
  inventoryId: string;
}) {
  const count = orderNbrs.length > 0 ? orderNbrs.length : soCount;
  if (count <= 0) {
    return <span className="tabular-nums text-muted-foreground">0</span>;
  }
  if (orderNbrs.length === 0) {
    return <span className="tabular-nums">{count}</span>;
  }
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="link"
          size="sm"
          className="h-auto px-0 py-0 text-xs font-semibold tabular-nums text-primary"
          onClick={(e) => e.stopPropagation()}
        >
          {count} · view
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-64 p-3" align="end" onClick={(e) => e.stopPropagation()}>
        <div className="mb-1.5 text-xs font-semibold text-muted-foreground">
          Open SOs for {inventoryId}
          <span className="ml-1 font-normal">({orderNbrs.length})</span>
        </div>
        <ul className="max-h-48 space-y-1 overflow-y-auto">
          {orderNbrs.map((nbr) => (
            <li
              key={nbr}
              className="rounded border bg-card px-2 py-1 font-mono text-[11px] font-semibold"
            >
              {nbr}
            </li>
          ))}
        </ul>
        <p className="mt-2 text-[10px] text-muted-foreground">
          Search these SO numbers on Orders or Backorders for full line detail.
        </p>
      </PopoverContent>
    </Popover>
  );
}

export function PortfolioSummaryStrip({ summary }: { summary: PortfolioSummary }) {
  const items: Array<[string, ReactNode]> = [
    ["Brands", summary.brand_count.toLocaleString("en-KE")],
    ["Customers", summary.customer_count.toLocaleString("en-KE")],
    ["SO", summary.so_count.toLocaleString("en-KE")],
    ["Sold value", <MaskedKES key="sv" value={summary.sold_value} />],
    ["At risk", <MaskedKES key="ar" value={summary.undelivered_value} className="text-amber-700" />],
    ["Fill rate", rate(summary.fill_rate_pct)],
  ];
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
      {items.map(([label, value]) => (
        <div key={label} className="rounded border bg-card p-2">
          <div className="text-xs text-muted-foreground">{label}</div>
          <div className="font-semibold tabular-nums">{value}</div>
        </div>
      ))}
    </div>
  );
}

export function ReasonList({ rows }: { rows: ReasonRow[] }) {
  if (!rows.length)
    return (
      <p className="text-sm text-muted-foreground">No undelivered quantities in this period.</p>
    );
  return (
    <div className="grid gap-2 sm:grid-cols-2">
      {rows.map((row) => (
        <div
          key={row.reason}
          className="flex items-center justify-between rounded border p-2 text-sm"
        >
          <div>
            <div className="font-medium">{row.reason}</div>
            <div className="text-xs text-muted-foreground">
              {row.so_count} SO · {qty(row.quantity)} units
            </div>
          </div>
          <MaskedKES value={row.value} className="font-medium text-amber-700" />
        </div>
      ))}
    </div>
  );
}

export function CustomerBrandAccordion({ rows }: { rows: CustomerBrandRow[] }) {
  return (
    <Accordion type="multiple" className="space-y-2">
      {rows.map(
        (row) =>
          row.totals && (
            <AccordionItem
              key={row.customer.id}
              value={row.customer.id}
              className="rounded border px-3"
            >
              <AccordionTrigger className="gap-3 hover:no-underline">
                <div className="grid flex-1 grid-cols-2 items-center gap-2 text-left md:grid-cols-5">
                  <div>
                    <div className="font-semibold">{row.customer.name}</div>
                    <div className="font-mono text-xs text-muted-foreground">{row.customer.id}</div>
                  </div>
                  <div>
                    <span className="text-xs text-muted-foreground">SO</span>
                    <div className="font-semibold">{row.totals.so_count}</div>
                  </div>
                  <div>
                    <span className="text-xs text-muted-foreground">Sold</span>
                    <div>
                      <MaskedKES value={row.totals.sold_value} />
                    </div>
                  </div>
                  <div>
                    <span className="text-xs text-muted-foreground">Undelivered</span>
                    <div className="text-amber-700">
                      <MaskedKES value={row.totals.undelivered_value} />
                    </div>
                  </div>
                  <div>
                    <Badge variant="outline">Fill {rate(row.totals.fill_rate_pct)}</Badge>
                  </div>
                </div>
              </AccordionTrigger>
              <AccordionContent className="space-y-4">
                <div className="flex justify-end">
                  <Link
                    to="/app/customer-brands/$customerId"
                    params={{ customerId: row.customer.id }}
                    className="text-sm font-medium text-primary hover:underline"
                  >
                    View customer brand detail →
                  </Link>
                </div>
                {row.brands.length > 0 && (
                  <div>
                    <h4 className="mb-2 font-semibold">Brands — expand for SKU split</h4>
                    <BrandTable rows={row.brands} />
                  </div>
                )}
                <div>
                  <h4 className="mb-2 font-semibold">Sales orders</h4>
                  <Accordion type="multiple" className="space-y-1">
                    {row.sales_orders.map((order) => (
                      <AccordionItem
                        key={order.id}
                        value={String(order.id)}
                        className="rounded border px-2"
                      >
                        <AccordionTrigger className="hover:no-underline">
                          <div className="grid flex-1 grid-cols-2 gap-2 text-left md:grid-cols-5">
                            <span className="font-mono font-semibold">{order.order_nbr}</span>
                            <span>{order.order_date ?? "—"}</span>
                            <Badge variant="outline" className="w-fit">
                              {order.status ?? "Unknown"}
                            </Badge>
                            <span>Sold {qty(order.sold_qty)}</span>
                            <span className="text-amber-700">
                              Open {qty(order.undelivered_qty)}
                            </span>
                          </div>
                        </AccordionTrigger>
                        <AccordionContent>
                          <BrandTable rows={order.brands} />
                        </AccordionContent>
                      </AccordionItem>
                    ))}
                  </Accordion>
                </div>
                <div>
                  <h4 className="mb-2 font-semibold">Why items were not delivered</h4>
                  <ReasonList rows={row.undelivered_reasons} />
                </div>
              </AccordionContent>
            </AccordionItem>
          ),
      )}
    </Accordion>
  );
}
