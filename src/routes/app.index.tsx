import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery } from "@tanstack/react-query";
import { Fragment, useState } from "react";
import {
  CheckCircle2, Clock, Package, PackageX, RefreshCw,
  ShoppingCart, TrendingUp, XCircle, ChevronDown, ChevronRight, BarChart2,
} from "lucide-react";
import {
  Area, AreaChart, CartesianGrid, Legend,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Badge } from "@/components/ui/badge";
import { apiFetch } from "@/lib/api";
import { MaskedKES } from "@/components/MaskedCurrency";
import { useRefreshOrderStatuses } from "@/hooks/useOrders";
import { CustomerLink, DateLink, OrderLink } from "@/components/entity-links";

export const Route = createFileRoute("/app/")({
  head: () => ({ meta: [{ title: "Dashboard — Kim-Fay OrderWatch" }] }),
  component: DashboardPage,
});

// -------------------------------------------------------------------------
// Types
// -------------------------------------------------------------------------

interface SoTotals {
  all_so: number;
  dashboard_so: number;
  goods_lost_in_transit_so: number;
  formula: string;
  calculation: string;
  excluded_customer_ids?: string[];
}

interface KpiData {
  total: number;
  completed: number;
  shipping: number;
  pending_approval: number;
  rejected: number;
  on_hold: number;
  open: number;
  back_order: number;
  avg_per_day: number;
  active_days: number;
  date_from: string;
  date_to: string;
  open_so: number;
  open_qt: number;
  open_rc: number;
  open_other: number;
  so_totals?: SoTotals;
  goods_lost_in_transit?: {
    customer_id: string;
    label: string;
    total: number;
  };
}

interface GoodsLostInTransitResponse {
  customer_id: string;
  customer_name: string;
  label: string;
  date_from: string;
  date_to: string;
  total: number;
  order_total_sum: number;
  completed: number;
  shipping: number;
  pending_approval: number;
  rejected: number;
  on_hold: number;
  open: number;
  so_totals: SoTotals;
  orders: DashboardStatusOrder[];
}

interface TrendDay {
  day: string;
  total: number;
  completed: number;
  shipping: number;
  pending_approval: number;
  rejected: number;
  on_hold: number;
  open: number;
  fill_shipped_qty?: number;
  fill_ordered_qty?: number;
  fill_rate_pct?: number | null;
}

interface TrendData {
  current: TrendDay[];
  previous: TrendDay[] | null;
}

interface DashboardStatusOrder {
  id: number;
  order_nbr: string;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  amount: number;
  currency_id: string | null;
  quantity: number;
  order_date: string | null;
  status: string | null;
}

interface DashboardStatusOrdersResponse {
  status: string;
  count: number;
  orders: DashboardStatusOrder[];
}

interface ZoneRouteData {
  route_code: string;
  route_name: string | null;
  customer_zone: string | null;
  total: number;
  open: number;
  pending_approval: number;
  shipping: number;
  completed: number;
  rejected: number;
  on_hold: number;
  back_order: number;
}

interface ZoneRoutesZone {
  shipping_zone_id: string;
  name: string;
  description: string | null;
  region: string | null;
  total: number;
  routes: ZoneRouteData[];
}

interface ZoneRoutesResponse {
  date_from: string;
  date_to: string;
  total: number;
  zones: ZoneRoutesZone[];
}

// -------------------------------------------------------------------------
// Hooks
// -------------------------------------------------------------------------

function useKpis(dateFrom: string, dateTo: string) {
  return useQuery({
    queryKey: ["dashboard-kpis", dateFrom, dateTo],
    queryFn: () =>
      apiFetch<KpiData>(`dashboard/kpis?date_from=${dateFrom}&date_to=${dateTo}`),
  });
}

function useStatusOrders(statusKey: string, day: string, enabled: boolean) {
  return useQuery({
    queryKey: ["dashboard-orders-by-status", statusKey, day],
    queryFn: () =>
      apiFetch<DashboardStatusOrdersResponse>(
        `dashboard/orders-by-status?status=${statusKey}&date_from=${day}&date_to=${day}`,
      ),
    enabled,
  });
}

function useTrend(dateFrom: string, dateTo: string, compare: boolean) {
  return useQuery({
    queryKey: ["dashboard-trend", dateFrom, dateTo, compare],
    queryFn: () =>
      apiFetch<TrendData>(
        `dashboard/trend?date_from=${dateFrom}&date_to=${dateTo}&compare=${compare ? 1 : 0}`
      ),
  });
}

function useGoodsLostInTransit(dateFrom: string, dateTo: string, enabled: boolean) {
  return useQuery({
    queryKey: ["dashboard-goods-lost-in-transit", dateFrom, dateTo],
    queryFn: () =>
      apiFetch<GoodsLostInTransitResponse>(
        `dashboard/goods-lost-in-transit?date_from=${dateFrom}&date_to=${dateTo}`,
      ),
    enabled,
  });
}

function useZoneRoutes(dateFrom: string, dateTo: string, enabled: boolean) {
  const params = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });

  return useQuery({
    queryKey: ["dashboard-zone-routes", dateFrom, dateTo],
    queryFn: () => apiFetch<ZoneRoutesResponse>(`dashboard/zone-routes?${params.toString()}`),
    enabled,
  });
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function today() {
  return new Date().toISOString().slice(0, 10);
}

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function fmtDay(iso: string) {
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString("en-KE", { day: "numeric", month: "short" });
}

// -------------------------------------------------------------------------
// Stat card config
// -------------------------------------------------------------------------

type StatKey = "total" | "completed" | "shipping" | "pending_approval" | "rejected" | "on_hold" | "open";

const STATS: {
  key: StatKey;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  color: string;
  bg: string;
  border: string;
  statusFilter?: string;
}[] = [
  {
    key: "total",
    label: "Total Orders",
    icon: Package,
    color: "text-blue-700 dark:text-blue-300",
    bg: "bg-blue-50 dark:bg-blue-950/40",
    border: "border-blue-200 dark:border-blue-800",
  },
  {
    key: "open",
    label: "Open Orders",
    icon: TrendingUp,
    color: "text-sky-700 dark:text-sky-300",
    bg: "bg-sky-50 dark:bg-sky-950/40",
    border: "border-sky-200 dark:border-sky-800",
    statusFilter: "Open",
  },
  {
    key: "completed",
    label: "Completed",
    icon: CheckCircle2,
    color: "text-green-700 dark:text-green-300",
    bg: "bg-green-50 dark:bg-green-950/40",
    border: "border-green-200 dark:border-green-800",
    statusFilter: "Completed",
  },
  {
    key: "pending_approval",
    label: "Pending Approval",
    icon: Clock,
    color: "text-amber-700 dark:text-amber-300",
    bg: "bg-amber-50 dark:bg-amber-950/40",
    border: "border-amber-200 dark:border-amber-800",
    statusFilter: "Pending Approval",
  },
  {
    key: "shipping",
    label: "Shipping",
    icon: Package,
    color: "text-purple-700 dark:text-purple-300",
    bg: "bg-purple-50 dark:bg-purple-950/40",
    border: "border-purple-200 dark:border-purple-800",
    statusFilter: "Shipping",
  },
  {
    key: "rejected",
    label: "Rejected",
    icon: XCircle,
    color: "text-red-700 dark:text-red-300",
    bg: "bg-red-50 dark:bg-red-950/40",
    border: "border-red-200 dark:border-red-800",
    statusFilter: "Rejected",
  },
  {
    key: "on_hold",
    label: "On Hold",
    icon: ShoppingCart,
    color: "text-orange-700 dark:text-orange-300",
    bg: "bg-orange-50 dark:bg-orange-950/40",
    border: "border-orange-200 dark:border-orange-800",
    statusFilter: "On Hold",
  },
];

const CHART_COLORS: Record<StatKey, string> = {
  total:            "var(--color-chart-1, #3b82f6)",
  open:             "#0ea5e9",
  completed:        "#22c55e",
  pending_approval: "#f59e0b",
  shipping:         "#a855f7",
  rejected:         "#ef4444",
  on_hold:          "#f97316",
};

const tooltipStyle = {
  background: "var(--color-popover, #fff)",
  border: "1px solid var(--color-border, #e5e7eb)",
  borderRadius: 4,
  fontSize: 8,
  padding: "4px 6px",
} as const;

const axisStyle = { stroke: "var(--color-muted-foreground, #9ca3af)", fontSize: 8 } as const;

// -------------------------------------------------------------------------
// Open Orders per day table
// -------------------------------------------------------------------------

const STATUS_COLS = [
  { key: "open",             label: "Open" },
  { key: "pending_approval", label: "Pending Approval" },
  { key: "shipping",         label: "Shipping" },
  { key: "completed",        label: "Completed" },
  { key: "rejected",         label: "Rejected" },
  { key: "on_hold",          label: "On Hold" },
] as const;

type StatusCol = (typeof STATUS_COLS)[number]["key"];

function trendDayStatusCount(row: TrendDay, key: StatusCol): number {
  return row[key] ?? 0;
}

type ChartDayPoint = TrendDay & {
  label: string;
  prev_total?: number;
  prev_completed?: number;
  prev_pending_approval?: number;
  prev_shipping?: number;
  prev_rejected?: number;
  prev_on_hold?: number;
  prev_open?: number;
};

/** Statuses shown when expanding a day row in the daily table. */
const DAY_EXPAND_STATUSES: StatusCol[] = ["pending_approval", "shipping"];

const DAY_EXPAND_LABELS: Record<StatusCol, string> = Object.fromEntries(
  STATUS_COLS.map((c) => [c.key, c.label]),
) as Record<StatusCol, string>;

function fmtFullDate(iso: string) {
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString("en-KE", { day: "numeric", month: "long", year: "numeric" });
}

/**
 * Completion Rate = Completed / (Total - Open - Rejected - On Hold) × 100
 *
 * Denominator = "actionable" orders — those that could have been completed.
 * Open (not yet processed), Rejected, and On Hold are excluded because they
 * are either still in-flight or definitively not completable in the period.
 * Returns null when the denominator is 0 (nothing to complete).
 */
function calcCompletionRate(row: {
  total: number;
  completed: number;
  open: number;
  rejected: number;
  on_hold: number;
}): number | null {
  const denominator = row.total - row.open - row.rejected - row.on_hold;
  if (denominator <= 0) return null;
  return Math.min(100, (row.completed / denominator) * 100);
}

type RateRow = { total: number; completed: number; open: number; rejected: number; on_hold: number };

function rateTooltip(row: RateRow, pct: string): string {
  const denom = row.total - row.open - row.rejected - row.on_hold;
  return (
    "How this rate is calculated:\n" +
    "\n" +
    `  Completed        ${row.completed.toLocaleString()}\n` +
    `  ÷ Denominator    ${row.total.toLocaleString()} − ${row.open.toLocaleString()} − ${row.rejected.toLocaleString()} − ${row.on_hold.toLocaleString()} = ${denom.toLocaleString()}\n` +
    `  × 100            = ${pct}%\n` +
    "\n" +
    "Denominator = Total − Open − Rejected − On Hold\n" +
    "(excludes orders that are not yet actionable or completable)"
  );
}

function CompletionRateBadge({ rate, row }: { rate: number | null; row?: RateRow }) {
  if (rate === null) return <span className="text-muted-foreground/50">—</span>;

  const pct = rate.toFixed(1);
  const color =
    rate >= 90 ? "text-green-700 bg-green-50 border-green-200 dark:text-green-300 dark:bg-green-950/40 dark:border-green-800"
    : rate >= 70 ? "text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-800"
    : "text-red-700 bg-red-50 border-red-200 dark:text-red-300 dark:bg-red-950/40 dark:border-red-800";

  return (
    <span
      title={row ? rateTooltip(row, pct) : undefined}
      className={`inline-flex items-center rounded border px-1 py-px text-[8px] font-semibold tabular-nums ${row ? "cursor-help" : ""} ${color}`}
    >
      {pct}%
    </span>
  );
}

const FILL_RATE_TOOLTIP =
  "Fill Rate Formula (Completed orders only):\n" +
  "Shipped Qty ÷ Order Qty × 100\n\n" +
  "Only Completed sales orders are included.\n" +
  "Aggregated per day from synced fill-rate snapshots.\n" +
  "Shows — when no completed orders exist for that day.";

const COMPLETION_TOOLTIP =
  "Completion Rate Formula:\n" +
  "Completed ÷ (Total − Open − Rejected − On Hold) × 100\n\n" +
  "Why exclude certain statuses from the denominator?\n" +
  "  • Open     — still being processed; outcome not yet known\n" +
  "  • Rejected — declined orders; not a fulfilment failure\n" +
  "  • On Hold  — paused orders; outcome is still pending\n\n" +
  "Only orders that could realistically be fulfilled are counted,\n" +
  "giving a true measure of the team's fulfilment performance.\n\n" +
  "Example: 80 Completed, 10 Open, 5 Rejected, 5 On Hold, 100 Total\n" +
  "  Denominator = 100 − 10 − 5 − 5 = 80\n" +
  "  Rate = 80 ÷ 80 × 100 = 100 %";

type FillRateRow = { fill_shipped_qty?: number; fill_ordered_qty?: number; fill_rate_pct?: number | null };

function fillRateTooltip(row: FillRateRow, pct: string): string {
  const shipped = row.fill_shipped_qty ?? 0;
  const ordered = row.fill_ordered_qty ?? 0;
  return (
    "How this rate is calculated:\n" +
    "\n" +
    `  Shipped Qty      ${shipped.toLocaleString()}\n` +
    `  ÷ Approved Qty   ${ordered.toLocaleString()}\n` +
    `  × 100            = ${pct}%\n` +
    "\n" +
    "Rolled up from fill-rate snapshots for orders on this day."
  );
}

function FillRateBadge({ rate, row }: { rate: number | null | undefined; row?: FillRateRow }) {
  if (rate == null) return <span className="text-muted-foreground/50">—</span>;

  const pct = rate.toFixed(1);
  const color =
    rate >= 95 ? "text-green-700 bg-green-50 border-green-200 dark:text-green-300 dark:bg-green-950/40 dark:border-green-800"
    : rate >= 80 ? "text-amber-700 bg-amber-50 border-amber-200 dark:text-amber-300 dark:bg-amber-950/40 dark:border-amber-800"
    : "text-red-700 bg-red-50 border-red-200 dark:text-red-300 dark:bg-red-950/40 dark:border-red-800";

  return (
    <span
      title={row ? fillRateTooltip(row, pct) : undefined}
      className={`inline-flex items-center rounded border px-1 py-px text-[8px] font-semibold tabular-nums ${row ? "cursor-help" : ""} ${color}`}
    >
      {pct}%
    </span>
  );
}

function aggregateFillRate(rows: FillRateRow[]): number | null {
  const shipped = rows.reduce((sum, row) => sum + (row.fill_shipped_qty ?? 0), 0);
  const ordered = rows.reduce((sum, row) => sum + (row.fill_ordered_qty ?? 0), 0);
  if (ordered <= 0) return null;
  return Math.min(100, (shipped / ordered) * 100);
}

function StatusOrderList({
  statusKey,
  day,
  enabled,
}: {
  statusKey: StatusCol;
  day: string;
  enabled: boolean;
}) {
  const { data, isLoading, isError } = useStatusOrders(statusKey, day, enabled);

  if (!enabled) return null;

  if (isLoading) {
    return (
      <div className="space-y-1 py-1">
        {Array.from({ length: 2 }).map((_, i) => (
          <Skeleton key={i} className="h-5 w-full" />
        ))}
      </div>
    );
  }

  if (isError) {
    return <p className="py-1 text-[8px] text-destructive">Could not load orders.</p>;
  }

  const orders = data?.orders ?? [];
  if (orders.length === 0) {
    return <p className="py-1 text-[8px] text-muted-foreground">No orders.</p>;
  }

  return (
    <div className="overflow-x-auto rounded border bg-background">
      <table className="w-full text-[8px]">
        <thead>
          <tr className="border-b bg-muted/40 text-left">
            <th className="px-1.5 py-0.5 font-semibold text-muted-foreground">SO Number</th>
            <th className="px-1.5 py-0.5 font-semibold text-muted-foreground">Customer</th>
            <th className="px-1.5 py-0.5 text-right font-semibold text-muted-foreground">Amount</th>
            <th className="px-1.5 py-0.5 text-right font-semibold text-muted-foreground">Quantity</th>
          </tr>
        </thead>
        <tbody>
          {orders.map((order) => (
            <tr key={order.id} className="border-b last:border-b-0 hover:bg-muted/20">
              <td className="px-1.5 py-0.5 font-mono font-medium">
                <OrderLink
                  customerId={order.customer_acumatica_id}
                  orderId={order.order_nbr}
                />
              </td>
              <td className="px-1.5 py-0.5">
                <CustomerLink
                  customerId={order.customer_acumatica_id}
                  customerName={order.customer_name}
                >
                  {order.customer_name ?? "—"}
                </CustomerLink>
              </td>
              <td className="px-1.5 py-0.5 text-right font-mono tabular-nums"><MaskedKES value={order.amount} /></td>
              <td className="px-1.5 py-0.5 text-right font-mono tabular-nums">
                {order.quantity.toLocaleString(undefined, { maximumFractionDigits: 2 })}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DayRowOrdersPanel({ day, row }: { day: string; row: TrendDay }) {
  const [openStatus, setOpenStatus] = useState<string | undefined>();
  const visible = DAY_EXPAND_STATUSES.filter((key) => trendDayStatusCount(row, key) > 0);

  if (visible.length === 0) return null;

  return (
    <div className="py-1">
      <Accordion
        type="single"
        collapsible
        value={openStatus}
        onValueChange={setOpenStatus}
        className="rounded border bg-background"
      >
        {visible.map((statusKey) => {
          const count = trendDayStatusCount(row, statusKey);
          const isOpen = openStatus === statusKey;

          return (
            <AccordionItem key={statusKey} value={statusKey} className="border-b px-1.5 last:border-b-0">
              <AccordionTrigger className="py-1 text-[8px] hover:no-underline">
                <span className="flex items-center gap-1">
                  <StatusBadge statusKey={statusKey} value={count} />
                  <span className="font-medium">{DAY_EXPAND_LABELS[statusKey]}</span>
                </span>
              </AccordionTrigger>
              <AccordionContent className="pb-1">
                <StatusOrderList statusKey={statusKey} day={day} enabled={isOpen} />
              </AccordionContent>
            </AccordionItem>
          );
        })}
      </Accordion>
    </div>
  );
}

function DailyOrderTable({ trendData }: { trendData: TrendDay[] }) {
  const [collapsed, setCollapsed] = useState(false);
  const [expandedDay, setExpandedDay] = useState<string | null>(null);

  const rows = [...trendData].sort((a, b) => b.day.localeCompare(a.day));
  const colSpan = STATUS_COLS.length + 4;

  return (
    <div className="rounded border bg-card shadow-[var(--shadow-panel)]">
      {/* Table header row */}
      <button
        type="button"
        onClick={() => setCollapsed((v) => !v)}
        className="flex w-full items-center justify-between px-2 py-1.5 text-left"
      >
        <div>
          <h3 className="text-[10px] font-semibold">Open Orders by Date</h3>
          <p className="text-[8px] text-muted-foreground">
            Status by day — click a date to expand Pending Approval / Shipping
          </p>
        </div>
        {collapsed ? (
          <ChevronRight className="h-3 w-3 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-3 w-3 text-muted-foreground" />
        )}
      </button>

      {!collapsed && (
        <div className="overflow-x-auto">
          <table className="w-full text-[8px]">
            <thead>
              <tr className="border-t bg-muted/40">
                <th className="px-2 py-1 text-left font-semibold text-muted-foreground whitespace-nowrap">Date</th>
                <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground">Total</th>
                {STATUS_COLS.map((col) => (
                  <th key={col.key} className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                    {col.label}
                  </th>
                ))}
                <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                  <span className="inline-flex items-center gap-0.5">
                    Fill Rate
                    <span
                      title={FILL_RATE_TOOLTIP}
                      className="inline-flex h-2.5 w-2.5 cursor-help items-center justify-center rounded-full bg-muted text-[7px] font-bold text-muted-foreground ring-1 ring-muted-foreground/30 select-none"
                    >
                      ?
                    </span>
                  </span>
                </th>
                <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                  <span className="inline-flex items-center gap-0.5">
                    Completion Rate
                    <span
                      title={COMPLETION_TOOLTIP}
                      className="inline-flex h-2.5 w-2.5 cursor-help items-center justify-center rounded-full bg-muted text-[7px] font-bold text-muted-foreground ring-1 ring-muted-foreground/30 select-none"
                    >
                      ?
                    </span>
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => {
                const canExpand = DAY_EXPAND_STATUSES.some(
                  (key) => trendDayStatusCount(row, key) > 0,
                );
                const isExpanded = expandedDay === row.day;

                return (
                  <Fragment key={row.day}>
                    <tr
                      className={`border-t transition-colors ${canExpand ? "cursor-pointer hover:bg-muted/40" : "hover:bg-muted/30"} ${i % 2 === 0 ? "" : "bg-muted/10"} ${isExpanded ? "bg-muted/30" : ""}`}
                      onClick={() => {
                        if (!canExpand) return;
                        setExpandedDay((current) => (current === row.day ? null : row.day));
                      }}
                    >
                      <td className="px-2 py-0.5 font-medium whitespace-nowrap">
                        <span className="inline-flex items-center gap-1">
                          {canExpand ? (
                            isExpanded ? (
                              <ChevronDown className="h-2.5 w-2.5 shrink-0 text-muted-foreground" />
                            ) : (
                              <ChevronRight className="h-2.5 w-2.5 shrink-0 text-muted-foreground" />
                            )
                          ) : (
                            <span className="inline-block w-2.5" />
                          )}
                          <DateLink value={row.day}>{fmtFullDate(row.day)}</DateLink>
                        </span>
                      </td>
                      <td className="px-1.5 py-0.5 text-right tabular-nums font-semibold">{row.total.toLocaleString()}</td>
                      {STATUS_COLS.map((col) => {
                        const val = trendDayStatusCount(row, col.key);
                        return (
                          <td key={col.key} className="px-1.5 py-0.5 text-right tabular-nums">
                            {val > 0 ? (
                              <StatusBadge statusKey={col.key} value={val} />
                            ) : (
                              <span className="text-muted-foreground/50">—</span>
                            )}
                          </td>
                        );
                      })}
                      <td className="px-1.5 py-0.5 text-right tabular-nums">
                        <FillRateBadge rate={row.fill_rate_pct} row={row} />
                      </td>
                      <td className="px-1.5 py-0.5 text-right tabular-nums">
                        <CompletionRateBadge rate={calcCompletionRate(row)} row={row} />
                      </td>
                    </tr>
                    {isExpanded && (
                      <tr className="border-t bg-muted/15">
                        <td colSpan={colSpan} className="px-2 py-1">
                          <DayRowOrdersPanel day={row.day} row={row} />
                        </td>
                      </tr>
                    )}
                  </Fragment>
                );
              })}

              {/* Totals footer */}
              {rows.length > 0 && (() => {
                const totals = rows.reduce(
                  (acc, row) => {
                    acc.total += row.total;
                    for (const col of STATUS_COLS) {
                      acc[col.key] = (acc[col.key] ?? 0) + trendDayStatusCount(row, col.key);
                    }
                    return acc;
                  },
                  { total: 0 } as Record<string, number>,
                );
                const totalsRow = {
                  total:     totals.total,
                  completed: totals.completed ?? 0,
                  open:      totals.open      ?? 0,
                  rejected:  totals.rejected  ?? 0,
                  on_hold:   totals.on_hold   ?? 0,
                };
                const fillTotalsRow = {
                  fill_shipped_qty: rows.reduce((sum, row) => sum + (row.fill_shipped_qty ?? 0), 0),
                  fill_ordered_qty: rows.reduce((sum, row) => sum + (row.fill_ordered_qty ?? 0), 0),
                  fill_rate_pct:    aggregateFillRate(rows),
                };
                return (
                  <tr className="border-t bg-muted/40 font-semibold">
                    <td className="px-2 py-0.5 text-muted-foreground">Totals</td>
                    <td className="px-1.5 py-0.5 text-right tabular-nums">{totals.total.toLocaleString()}</td>
                    {STATUS_COLS.map((col) => (
                      <td key={col.key} className="px-1.5 py-0.5 text-right tabular-nums">
                        {(totals[col.key] ?? 0) > 0
                          ? (totals[col.key] ?? 0).toLocaleString()
                          : <span className="text-muted-foreground/50">—</span>}
                      </td>
                    ))}
                    <td className="px-1.5 py-0.5 text-right tabular-nums">
                      <FillRateBadge rate={fillTotalsRow.fill_rate_pct} row={fillTotalsRow} />
                    </td>
                    <td className="px-1.5 py-0.5 text-right tabular-nums">
                      <CompletionRateBadge rate={calcCompletionRate(totalsRow)} row={totalsRow} />
                    </td>
                  </tr>
                );
              })()}
            </tbody>
          </table>

          {rows.length === 0 && (
            <p className="py-4 text-center text-[8px] text-muted-foreground">No data for selected period.</p>
          )}
        </div>
      )}
    </div>
  );
}

function StatusBadge({ statusKey, value }: { statusKey: StatusCol; value: number }) {
  const styles: Record<StatusCol, string> = {
    open:             "bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800",
    pending_approval: "bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800",
    shipping:         "bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800",
    completed:        "bg-green-50 text-green-700 border-green-200 dark:bg-green-950/40 dark:text-green-300 dark:border-green-800",
    rejected:         "bg-red-50 text-red-700 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-800",
    on_hold:          "bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:border-orange-800",
  };
  return (
    <span className={`inline-flex items-center rounded border px-1 py-px text-[8px] font-medium tabular-nums ${styles[statusKey]}`}>
      {value.toLocaleString()}
    </span>
  );
}

// -------------------------------------------------------------------------
// Monthly cumulative table
// -------------------------------------------------------------------------

function MonthlyOrderTable({ trendData }: { trendData: TrendDay[] }) {
  const [collapsed, setCollapsed] = useState(false);

  // Aggregate daily rows into months
  const byMonth: Record<string, {
    month: string; label: string; total: number;
    open: number; pending_approval: number; shipping: number;
    completed: number; rejected: number; on_hold: number;
    fill_shipped_qty: number; fill_ordered_qty: number;
    days: TrendDay[];
  }> = {};

  for (const row of trendData) {
    const month = row.day.slice(0, 7); // "YYYY-MM"
    if (!byMonth[month]) {
      const d = new Date(row.day + "T00:00:00");
      byMonth[month] = {
        month,
        label: d.toLocaleDateString("en-KE", { month: "long", year: "numeric" }),
        total: 0, open: 0, pending_approval: 0,
        shipping: 0, completed: 0, rejected: 0, on_hold: 0,
        fill_shipped_qty: 0, fill_ordered_qty: 0, days: [],
      };
    }
    const m = byMonth[month];
    m.total            += row.total;
    m.open             += row.open;
    m.pending_approval += row.pending_approval;
    m.shipping         += row.shipping;
    m.completed        += row.completed;
    m.rejected         += row.rejected;
    m.on_hold          += row.on_hold;
    m.fill_shipped_qty += row.fill_shipped_qty ?? 0;
    m.fill_ordered_qty += row.fill_ordered_qty ?? 0;
    m.days.push(row);
  }

  const rows = Object.values(byMonth).sort((a, b) => b.month.localeCompare(a.month));

  // Grand-total row across all months
  const grand = rows.reduce(
    (acc, r) => {
      acc.total            += r.total;
      acc.open             += r.open;
      acc.pending_approval += r.pending_approval;
      acc.shipping         += r.shipping;
      acc.completed        += r.completed;
      acc.rejected         += r.rejected;
      acc.on_hold          += r.on_hold;
      return acc;
    },
    { total: 0, open: 0, pending_approval: 0, shipping: 0, completed: 0, rejected: 0, on_hold: 0 }
  );

  return (
    <div className="rounded border bg-card shadow-[var(--shadow-panel)]">
      <button
        type="button"
        onClick={() => setCollapsed((v) => !v)}
        className="flex w-full items-center justify-between px-2 py-1.5 text-left"
      >
        <div>
          <h3 className="text-[10px] font-semibold">Cumulative Orders by Month</h3>
          <p className="text-[8px] text-muted-foreground">Monthly totals with fill rate and completion rate</p>
        </div>
        {collapsed ? (
          <ChevronRight className="h-3 w-3 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-3 w-3 text-muted-foreground" />
        )}
      </button>

      {!collapsed && (
        <div className="overflow-x-auto">
          <table className="w-full text-[8px]">
            <thead>
              <tr className="border-t bg-muted/40">
                <th className="px-2 py-1 text-left font-semibold text-muted-foreground whitespace-nowrap">Month</th>
                <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground">Total</th>
                {STATUS_COLS.map((col) => (
                  <th key={col.key} className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                    {col.label}
                  </th>
                ))}
                <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                  <span className="inline-flex items-center gap-0.5">
                    Fill Rate
                    <span
                      title={FILL_RATE_TOOLTIP}
                      className="inline-flex h-2.5 w-2.5 cursor-help items-center justify-center rounded-full bg-muted text-[7px] font-bold text-muted-foreground ring-1 ring-muted-foreground/30 select-none"
                    >
                      ?
                    </span>
                  </span>
                </th>
                <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                  <span className="inline-flex items-center gap-0.5">
                    Completion Rate
                    <span
                      title={COMPLETION_TOOLTIP}
                      className="inline-flex h-2.5 w-2.5 cursor-help items-center justify-center rounded-full bg-muted text-[7px] font-bold text-muted-foreground ring-1 ring-muted-foreground/30 select-none"
                    >
                      ?
                    </span>
                  </span>
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row, i) => (
                <tr
                  key={row.month}
                  className={`border-t transition-colors hover:bg-muted/30 ${i % 2 === 0 ? "" : "bg-muted/10"}`}
                >
                  <td className="px-2 py-0.5 font-medium whitespace-nowrap">{row.label}</td>
                  <td className="px-1.5 py-0.5 text-right tabular-nums font-semibold">{row.total.toLocaleString()}</td>
                  {STATUS_COLS.map((col) => {
                    const val = row[col.key as keyof typeof row] as number;
                    return (
                      <td key={col.key} className="px-1.5 py-0.5 text-right tabular-nums">
                        {val > 0 ? (
                          <StatusBadge statusKey={col.key} value={val} />
                        ) : (
                          <span className="text-muted-foreground/50">—</span>
                        )}
                      </td>
                    );
                  })}
                  <td className="px-1.5 py-0.5 text-right tabular-nums">
                    <FillRateBadge
                      rate={aggregateFillRate(row.days)}
                      row={{ fill_shipped_qty: row.fill_shipped_qty, fill_ordered_qty: row.fill_ordered_qty }}
                    />
                  </td>
                  <td className="px-1.5 py-0.5 text-right tabular-nums">
                    <CompletionRateBadge rate={calcCompletionRate(row)} row={row} />
                  </td>
                </tr>
              ))}

              {rows.length > 0 && (() => {
                const allDays = rows.flatMap((row) => row.days);
                const grandFillRow = {
                  fill_shipped_qty: rows.reduce((sum, row) => sum + row.fill_shipped_qty, 0),
                  fill_ordered_qty: rows.reduce((sum, row) => sum + row.fill_ordered_qty, 0),
                  fill_rate_pct: aggregateFillRate(allDays),
                };
                return (
                <tr className="border-t bg-muted/40 font-semibold">
                  <td className="px-2 py-0.5 text-muted-foreground">Grand Total</td>
                  <td className="px-1.5 py-0.5 text-right tabular-nums">{grand.total.toLocaleString()}</td>
                  {STATUS_COLS.map((col) => {
                    const val = grand[col.key as keyof typeof grand] as number;
                    return (
                      <td key={col.key} className="px-1.5 py-0.5 text-right tabular-nums">
                        {val > 0 ? val.toLocaleString() : <span className="text-muted-foreground/50">—</span>}
                      </td>
                    );
                  })}
                  <td className="px-1.5 py-0.5 text-right tabular-nums">
                    <FillRateBadge rate={grandFillRow.fill_rate_pct} row={grandFillRow} />
                  </td>
                  <td className="px-1.5 py-0.5 text-right tabular-nums">
                    <CompletionRateBadge rate={calcCompletionRate(grand)} row={grand} />
                  </td>
                </tr>
                );
              })()}
            </tbody>
          </table>

          {rows.length === 0 && (
            <p className="py-4 text-center text-[8px] text-muted-foreground">No data for selected period.</p>
          )}
        </div>
      )}
    </div>
  );
}

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

function SoTotalsStrip({ soTotals }: { soTotals?: SoTotals }) {
  if (!soTotals) return null;

  return (
    <div className="rounded border bg-muted/30 px-2 py-1.5 text-[8px]">
      <div className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
        <span className="font-medium">Total SO calculation</span>
        <Badge variant="outline" className="h-4 px-1 py-0 text-[8px] font-mono tabular-nums">
          All SO: {soTotals.all_so.toLocaleString()}
        </Badge>
        <Badge variant="secondary" className="h-4 px-1 py-0 text-[8px] font-mono tabular-nums">
          Dashboard SO: {soTotals.dashboard_so.toLocaleString()}
        </Badge>
        <Badge variant="outline" className="h-4 px-1 py-0 text-[8px] font-mono tabular-nums text-amber-800 border-amber-300 dark:text-amber-300">
          Goods Lost in Transit: {soTotals.goods_lost_in_transit_so.toLocaleString()}
        </Badge>
      </div>
      <p className="mt-0.5 text-[8px] leading-tight text-muted-foreground">
        {soTotals.formula}
        {" · "}
        <span className="font-mono text-foreground/80">{soTotals.calculation}</span>
        {" · "}
        GLT customer excluded from main SO dashboard.
      </p>
    </div>
  );
}

function GoodsLostInTransitPanel({
  dateFrom,
  dateTo,
  enabled,
}: {
  dateFrom: string;
  dateTo: string;
  enabled: boolean;
}) {
  const glt = useGoodsLostInTransit(dateFrom, dateTo, enabled);
  const data = glt.data;

  if (!enabled) return null;

  if (glt.isLoading) {
    return (
      <div className="space-y-2">
        <Skeleton className="h-12 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (glt.isError) {
    return <p className="text-[8px] text-destructive">Could not load Goods Lost in Transit orders.</p>;
  }

  if (!data) return null;

  const statusCards = [
    { label: "Total SO", value: data.total, tone: "text-amber-700" },
    { label: "Open", value: data.open, tone: "text-sky-700" },
    { label: "Pending Approval", value: data.pending_approval, tone: "text-amber-700" },
    { label: "Shipping", value: data.shipping, tone: "text-purple-700" },
    { label: "Completed", value: data.completed, tone: "text-green-700" },
    { label: "Rejected", value: data.rejected, tone: "text-red-700" },
    { label: "On Hold", value: data.on_hold, tone: "text-orange-700" },
  ];

  return (
    <div className="space-y-2">
      <SoTotalsStrip soTotals={data.so_totals} />

      <div className="rounded border bg-card p-2 shadow-sm">
        <div className="mb-1.5 flex flex-wrap items-start justify-between gap-1">
          <div>
            <h2 className="flex items-center gap-1 text-[10px] font-semibold">
              <PackageX className="h-3 w-3 text-amber-600" />
              {data.label}
            </h2>
            <p className="text-[8px] text-muted-foreground">
              Customer{" "}
              <CustomerLink customerId={data.customer_id} customerName={data.customer_name}>
                {data.customer_id}
              </CustomerLink>
              {" · "}
              {data.customer_name}
              {" · "}
              {data.total.toLocaleString()} SO in range
            </p>
          </div>
          <div className="text-right text-[8px] text-muted-foreground">
            Order value total
            <div className="text-[10px] font-semibold text-foreground">
              <MaskedKES value={data.order_total_sum} />
            </div>
          </div>
        </div>

        <div className="grid grid-cols-3 gap-1 sm:grid-cols-4 lg:grid-cols-7">
          {statusCards.map((card) => (
            <div key={card.label} className="rounded border bg-muted/20 px-1.5 py-1">
              <p className="text-[8px] leading-tight text-muted-foreground">{card.label}</p>
              <p className={`text-[11px] font-bold tabular-nums leading-tight ${card.tone}`}>{card.value.toLocaleString()}</p>
            </div>
          ))}
        </div>
      </div>

      <div className="overflow-x-auto rounded border bg-card shadow-sm">
        <table className="w-full text-[8px]">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-1.5 py-1 font-semibold text-muted-foreground">SO Number</th>
              <th className="px-1.5 py-1 font-semibold text-muted-foreground">Date</th>
              <th className="px-1.5 py-1 font-semibold text-muted-foreground">Status</th>
              <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground">Amount</th>
              <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground">Quantity</th>
            </tr>
          </thead>
          <tbody>
            {data.orders.length === 0 && (
              <tr>
                <td colSpan={5} className="px-1.5 py-4 text-center text-muted-foreground">
                  No Goods Lost in Transit sales orders in this date range.
                </td>
              </tr>
            )}
            {data.orders.map((order) => (
              <tr key={order.id} className="border-b last:border-0 hover:bg-muted/20">
                <td className="px-1.5 py-0.5 font-mono font-medium">
                  <OrderLink customerId={order.customer_acumatica_id} orderId={order.order_nbr} />
                </td>
                <td className="px-1.5 py-0.5">
                  {order.order_date ? <DateLink value={order.order_date} /> : "—"}
                </td>
                <td className="px-1.5 py-0.5">{order.status ?? "—"}</td>
                <td className="px-1.5 py-0.5 text-right font-mono tabular-nums">
                  <MaskedKES value={order.amount} />
                </td>
                <td className="px-1.5 py-0.5 text-right font-mono tabular-nums">
                  {order.quantity.toLocaleString(undefined, { maximumFractionDigits: 2 })}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

type ZoneRouteMetricKey =
  | "open"
  | "pending_approval"
  | "shipping"
  | "completed"
  | "rejected"
  | "on_hold"
  | "back_order";

const ZONE_ROUTE_COLS: { key: ZoneRouteMetricKey; label: string; tone: string }[] = [
  { key: "open", label: "Open", tone: "text-sky-700" },
  { key: "pending_approval", label: "Pending Approval", tone: "text-amber-700" },
  { key: "shipping", label: "In Shipment", tone: "text-purple-700" },
  { key: "completed", label: "Completed", tone: "text-green-700" },
  { key: "rejected", label: "Rejected", tone: "text-red-700" },
  { key: "on_hold", label: "On Hold", tone: "text-orange-700" },
  { key: "back_order", label: "Back Order", tone: "text-pink-700" },
];

function zoneRouteMetric(route: ZoneRouteData, key: ZoneRouteMetricKey): number {
  return route[key] ?? 0;
}

function ZoneRoutesPanel({
  dateFrom,
  dateTo,
  enabled,
}: {
  dateFrom: string;
  dateTo: string;
  enabled: boolean;
}) {
  const zr = useZoneRoutes(dateFrom, dateTo, enabled);
  const data = zr.data;

  if (!enabled) return null;

  if (zr.isLoading) {
    return (
      <div className="space-y-2">
        <Skeleton className="h-12 w-full" />
        <Skeleton className="h-32 w-full" />
      </div>
    );
  }

  if (zr.isError) {
    return <p className="text-[8px] text-destructive">Could not load zone / route data.</p>;
  }

  if (!data || data.zones.length === 0) {
    return (
      <p className="text-[8px] text-muted-foreground">
        No orders with assigned routes in this date range.
      </p>
    );
  }

  return (
    <div className="space-y-2">
      <div className="rounded border bg-card p-2 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-1">
          <h2 className="flex items-center gap-1 text-[10px] font-semibold">
            <BarChart2 className="h-3 w-3 text-indigo-600" />
            Zone Names & Routes
          </h2>
          <p className="text-[8px] text-muted-foreground">
            {data.zones.length} zone{data.zones.length !== 1 ? "s" : ""} /{" "}
            {data.total.toLocaleString()} orders / shipments by status
          </p>
        </div>
      </div>

      <Accordion type="multiple" className="rounded border bg-card shadow-sm">
        {data.zones.map((zone) => (
          <AccordionItem key={zone.shipping_zone_id} value={zone.shipping_zone_id} className="border-b px-2 last:border-b-0">
            <AccordionTrigger className="py-1.5 text-[9px] font-semibold hover:no-underline">
              <div className="flex flex-1 items-center justify-between gap-2 pr-1">
                <span className="flex items-center gap-1">
                  <span className="font-mono">{zone.shipping_zone_id}</span>
                  <span>{zone.name}</span>
                  {zone.description && (
                    <span className="text-[7px] font-normal text-muted-foreground">
                      ({zone.description})
                    </span>
                  )}
                </span>
                <Badge variant="secondary" className="h-3.5 px-1 text-[8px] tabular-nums">
                  {zone.routes.length} route{zone.routes.length !== 1 ? "s" : ""} /{" "}
                  {zone.total.toLocaleString()} orders
                </Badge>
              </div>
            </AccordionTrigger>
            <AccordionContent className="pb-1.5">
              <div className="overflow-x-auto rounded border bg-muted/10">
                <table className="w-full text-[8px]">
                  <thead>
                    <tr className="border-b bg-muted/40 text-left">
                      <th className="px-1.5 py-1 font-semibold text-muted-foreground">Route</th>
                      <th className="px-1.5 py-1 font-semibold text-muted-foreground">Name</th>
                      {ZONE_ROUTE_COLS.map((col) => (
                        <th key={col.key} className="px-1.5 py-1 text-right font-semibold text-muted-foreground whitespace-nowrap">
                          {col.label}
                        </th>
                      ))}
                      <th className="px-1.5 py-1 text-right font-semibold text-muted-foreground">Total</th>
                    </tr>
                  </thead>
                  <tbody>
                    {zone.routes.map((route) => (
                      <tr key={route.route_code} className="border-b last:border-0 hover:bg-muted/20">
                        <td className="px-1.5 py-0.5 font-mono font-medium">{route.route_code}</td>
                        <td className="px-1.5 py-0.5">{route.route_name ?? "-"}</td>
                        {ZONE_ROUTE_COLS.map((col) => {
                          const value = zoneRouteMetric(route, col.key);
                          return (
                            <td key={col.key} className="px-1.5 py-0.5 text-right tabular-nums">
                              {value > 0 ? (
                                <span className={col.tone}>{value.toLocaleString()}</span>
                              ) : (
                                <span className="text-muted-foreground">0</span>
                              )}
                            </td>
                          );
                        })}
                        <td className="px-1.5 py-0.5 text-right font-semibold tabular-nums">
                          {route.total.toLocaleString()}
                        </td>
                      </tr>
                    ))}
                    <tr className="border-t bg-muted/30 font-semibold">
                      <td className="px-1.5 py-0.5" colSpan={2}>
                        {zone.shipping_zone_id} Total
                      </td>
                      {ZONE_ROUTE_COLS.map((col) => {
                        const sum = zone.routes.reduce(
                          (acc, r) => acc + zoneRouteMetric(r, col.key),
                          0,
                        );
                        return (
                          <td key={col.key} className="px-1.5 py-0.5 text-right tabular-nums">
                            {sum > 0 ? (
                              <span className={col.tone}>{sum.toLocaleString()}</span>
                            ) : (
                              <span className="text-muted-foreground">0</span>
                            )}
                          </td>
                        );
                      })}
                      <td className="px-1.5 py-0.5 text-right tabular-nums">
                        {zone.total.toLocaleString()}
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </AccordionContent>
          </AccordionItem>
        ))}
      </Accordion>
    </div>
  );
}

function DashboardPage() {
  const navigate = useNavigate();
  const [dateFrom, setDateFrom] = useState(startOfMonth);
  const [dateTo, setDateTo]     = useState(today);
  const [compare, setCompare]   = useState(false);
  const [tab, setTab] = useState<"sales-orders" | "goods-lost" | "zone-routes">("sales-orders");
  const [activeStats, setActiveStats] = useState<StatKey[]>(
    ["total", "open", "completed", "pending_approval", "shipping", "rejected", "on_hold"]
  );

  const kpis  = useKpis(dateFrom, dateTo);
  const trend = useTrend(dateFrom, dateTo, compare);
  const orderStatusRefresh = useRefreshOrderStatuses();

  function goToOrders(statusFilter?: string) {
    navigate({
      to: "/app/orders",
      search: {
        status: statusFilter,
        order_type: undefined,
        date_from: dateFrom,
        date_to: dateTo,
      },
    });
  }

  function toggleStat(key: StatKey) {
    setActiveStats((prev) =>
      prev.includes(key) ? prev.filter((k) => k !== key) : [...prev, key]
    );
  }

  const kpi = kpis.data;

  // Merge current + previous into a single array for Recharts
  const chartData = (() => {
    if (!trend.data?.current) return [];
    const map: Record<string, ChartDayPoint> = {};
    for (const day of trend.data.current) {
      map[day.day] = { ...day, label: fmtDay(day.day) };
    }
    const previousDays = trend.data.previous;
    if (previousDays) {
      for (let i = 0; i < previousDays.length; i++) {
        const prevDay: TrendDay = previousDays[i];
        const currentDay = trend.data.current[i]?.day ?? prevDay.day;
        if (map[currentDay]) {
          map[currentDay].prev_total            = prevDay.total;
          map[currentDay].prev_completed        = prevDay.completed;
          map[currentDay].prev_pending_approval = prevDay.pending_approval;
          map[currentDay].prev_shipping         = prevDay.shipping;
          map[currentDay].prev_rejected         = prevDay.rejected;
          map[currentDay].prev_on_hold          = prevDay.on_hold;
          map[currentDay].prev_open             = prevDay.open;
        }
      }
    }
    return Object.values(map).sort((a, b) =>
      String(a.day).localeCompare(String(b.day))
    );
  })();

  return (
    <div className="space-y-2 text-[8px]">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-1.5">
        <div className="min-w-0">
          <h1 className="text-[12px] font-semibold tracking-tight">Operations Dashboard</h1>
          <p className="text-[8px] leading-tight text-muted-foreground">
            Sales orders (SO) only. Goods Lost in Transit ({kpi?.goods_lost_in_transit?.customer_id ?? "CUST102641"}) is on its own tab and excluded from SO totals.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-1">
          <div className="grid gap-0.5">
            <Label className="text-[8px]">From</Label>
            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-6 w-[7.5rem] px-1 text-[8px]" />
          </div>
          <div className="grid gap-0.5">
            <Label className="text-[8px]">To</Label>
            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-6 w-[7.5rem] px-1 text-[8px]" />
          </div>
          <Button
            variant="outline"
            size="sm"
            className="h-6 w-6 px-0"
            onClick={() => {
              kpis.refetch();
              trend.refetch();
            }}
            title="Refresh dashboard metrics"
          >
            <RefreshCw className="h-3 w-3" />
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-6 px-1.5 text-[8px]"
            onClick={() => orderStatusRefresh.mutate({ date_from: dateFrom, date_to: dateTo })}
            disabled={orderStatusRefresh.isPending}
            title="Import today's Acumatica orders and update statuses for the selected date range through today"
          >
            <RefreshCw className={`mr-0.5 h-3 w-3 ${orderStatusRefresh.isPending ? "animate-spin" : ""}`} />
            {orderStatusRefresh.isPending ? "Updating..." : "Update status"}
          </Button>
          <div className="flex h-6 items-center gap-1 rounded border px-1.5">
            <Switch id="compare-toggle" checked={compare} onCheckedChange={setCompare} className="scale-75 origin-left" />
            <Label htmlFor="compare-toggle" className="cursor-pointer text-[8px] select-none whitespace-nowrap">
              Compare prev.
            </Label>
          </div>
        </div>
      </div>

      <Tabs value={tab} onValueChange={(v) => setTab(v as "sales-orders" | "goods-lost" | "zone-routes")} className="space-y-2">
        <TabsList className="h-7 gap-0.5 p-0.5">
          <TabsTrigger value="sales-orders" className="h-6 gap-1 px-2 text-[8px]">
            <Package className="h-3 w-3" />
            Sales Orders
          </TabsTrigger>
          <TabsTrigger value="goods-lost" className="h-6 gap-1 px-2 text-[8px]">
            <PackageX className="h-3 w-3" />
            Goods Lost in Transit
            {kpi?.goods_lost_in_transit != null && (
              <Badge variant="secondary" className="ml-0.5 h-3.5 px-1 text-[8px] tabular-nums">
                {kpi.goods_lost_in_transit.total}
              </Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="zone-routes" className="h-6 gap-1 px-2 text-[8px]">
            <BarChart2 className="h-3 w-3" />
            Zone Names & Routes
          </TabsTrigger>
        </TabsList>

        <TabsContent value="sales-orders" className="mt-0 space-y-2">
          <SoTotalsStrip soTotals={kpi?.so_totals} />

      {/* Stat cards */}
      {kpis.isLoading ? (
        <div className="grid grid-cols-4 gap-1 sm:grid-cols-4 lg:grid-cols-8">
          {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-12" />)}
        </div>
      ) : (
        <div className="grid grid-cols-4 gap-1 sm:grid-cols-4 lg:grid-cols-8">
          {STATS.map((s) => (
            <button
              key={s.key}
              type="button"
              onClick={() => goToOrders(s.statusFilter)}
              className={`group rounded border p-1.5 text-left transition-all hover:shadow-sm active:scale-[0.98] ${s.bg} ${s.border}`}
            >
              <div className="mb-0.5 flex items-center justify-between gap-0.5">
                <span className={`truncate text-[8px] font-semibold uppercase leading-tight tracking-wide ${s.color} opacity-80`}>
                  {s.label}
                </span>
                <s.icon className={`h-2.5 w-2.5 shrink-0 ${s.color} opacity-60`} />
              </div>
              <div className={`text-[13px] font-bold leading-tight tabular-nums ${s.color}`}>
                {kpi ? (kpi[s.key] ?? 0).toLocaleString() : "—"}
              </div>
              <div className="mt-0.5 text-[7px] leading-none text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100">
                View →
              </div>
            </button>
          ))}

          {/* Avg orders / day card */}
          <div className="rounded border p-1.5 bg-teal-50 border-teal-200 dark:bg-teal-950/40 dark:border-teal-800">
            <div className="mb-0.5 flex items-center justify-between gap-0.5">
              <span className="truncate text-[8px] font-semibold uppercase leading-tight tracking-wide text-teal-700 dark:text-teal-300 opacity-80">
                Avg / Day
              </span>
              <BarChart2 className="h-2.5 w-2.5 shrink-0 text-teal-700 dark:text-teal-300 opacity-60" />
            </div>
            <div className="text-[13px] font-bold leading-tight tabular-nums text-teal-700 dark:text-teal-300">
              {kpi ? kpi.avg_per_day.toLocaleString("en-KE", { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : "—"}
            </div>
            <div className="mt-0.5 text-[7px] leading-none text-teal-600/70 dark:text-teal-400/70">
              {kpi ? `${kpi.active_days} active day${kpi.active_days !== 1 ? "s" : ""} · MTD` : "orders/day"}
            </div>
          </div>
        </div>
      )}

      {/* Trend chart */}
      <div className="rounded border bg-card p-2 shadow-[var(--shadow-panel)]">
        <div className="mb-1.5 flex flex-wrap items-center justify-between gap-1.5">
          <div>
            <h3 className="text-[10px] font-semibold">Order Volume Trend</h3>
            <p className="text-[8px] text-muted-foreground">
              {fmtDay(dateFrom)} — {fmtDay(dateTo)}
              {compare && <span className="ml-1 text-muted-foreground/70">(dashed = prev.)</span>}
            </p>
          </div>
          {/* Series toggles */}
          <div className="flex flex-wrap gap-0.5">
            {STATS.map((s) => (
              <button
                key={s.key}
                type="button"
                onClick={() => toggleStat(s.key)}
                className={`flex items-center gap-0.5 rounded-full border px-1.5 py-px text-[8px] font-medium transition-all ${
                  activeStats.includes(s.key)
                    ? `${s.bg} ${s.border} ${s.color}`
                    : "border-muted bg-muted/30 text-muted-foreground line-through"
                }`}
              >
                <span
                  className="inline-block h-1.5 w-1.5 rounded-full"
                  style={{ background: CHART_COLORS[s.key] }}
                />
                {s.label}
              </button>
            ))}
          </div>
        </div>

        {trend.isLoading ? (
          <Skeleton className="h-40 w-full" />
        ) : (
          <div className="h-40 sm:h-48">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData} margin={{ top: 2, right: 4, bottom: 0, left: 0 }}>
                <defs>
                  {STATS.map((s) => (
                    <linearGradient key={s.key} id={`grad-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor={CHART_COLORS[s.key]} stopOpacity={0.25} />
                      <stop offset="95%" stopColor={CHART_COLORS[s.key]} stopOpacity={0} />
                    </linearGradient>
                  ))}
                </defs>
                <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="label" {...axisStyle} tick={{ fontSize: 8 }} />
                <YAxis {...axisStyle} allowDecimals={false} width={22} tick={{ fontSize: 8 }} />
                <Tooltip contentStyle={tooltipStyle} />
                {STATS.filter((s) => activeStats.includes(s.key)).map((s) => (
                  <Area
                    key={s.key}
                    type="monotone"
                    dataKey={s.key}
                    name={s.label}
                    stroke={CHART_COLORS[s.key]}
                    fill={`url(#grad-${s.key})`}
                    strokeWidth={s.key === "total" ? 1.5 : 1}
                    dot={false}
                    activeDot={{ r: 2 }}
                  />
                ))}
                {compare &&
                  STATS.filter((s) => activeStats.includes(s.key)).map((s) => (
                    <Area
                      key={`prev-${s.key}`}
                      type="monotone"
                      dataKey={`prev_${s.key}`}
                      name={`${s.label} (prev)`}
                      stroke={CHART_COLORS[s.key]}
                      fill="transparent"
                      strokeWidth={1}
                      strokeDasharray="4 2"
                      dot={false}
                      activeDot={false}
                    />
                  ))}
              </AreaChart>
            </ResponsiveContainer>
          </div>
        )}
      </div>

      {/* Monthly cumulative table */}
      {trend.isLoading ? (
        <Skeleton className="h-20 w-full" />
      ) : trend.data?.current && trend.data.current.length > 0 ? (
        <MonthlyOrderTable trendData={trend.data.current} />
      ) : null}

      {/* Daily breakdown table */}
      {trend.isLoading ? (
        <Skeleton className="h-28 w-full" />
      ) : trend.data?.current && trend.data.current.length > 0 ? (
        <DailyOrderTable trendData={trend.data.current} />
      ) : null}
        </TabsContent>

        <TabsContent value="goods-lost" className="mt-0 space-y-2">
          <GoodsLostInTransitPanel
            dateFrom={dateFrom}
            dateTo={dateTo}
            enabled={tab === "goods-lost"}
          />
        </TabsContent>

        <TabsContent value="zone-routes" className="mt-0 space-y-2">
          <ZoneRoutesPanel
            dateFrom={dateFrom}
            dateTo={dateTo}
            enabled={tab === "zone-routes"}
          />
        </TabsContent>
      </Tabs>
    </div>
  );
}
