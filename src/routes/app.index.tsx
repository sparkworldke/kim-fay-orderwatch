import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useState } from "react";
import {
  CheckCircle2, Clock, Package, RefreshCw,
  ShoppingCart, TrendingUp, XCircle, ChevronDown, ChevronRight, BarChart2,
} from "lucide-react";
import {
  Area, AreaChart, CartesianGrid, Legend,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { Switch } from "@/components/ui/switch";
import { apiFetch } from "@/lib/api";

export const Route = createFileRoute("/app/")({
  head: () => ({ meta: [{ title: "Dashboard — Kim-Fay OrderWatch" }] }),
  component: DashboardPage,
});

// -------------------------------------------------------------------------
// Types
// -------------------------------------------------------------------------

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
}

interface TrendData {
  current: TrendDay[];
  previous: TrendDay[] | null;
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

function useTrend(dateFrom: string, dateTo: string, compare: boolean) {
  return useQuery({
    queryKey: ["dashboard-trend", dateFrom, dateTo, compare],
    queryFn: () =>
      apiFetch<TrendData>(
        `dashboard/trend?date_from=${dateFrom}&date_to=${dateTo}&compare=${compare ? 1 : 0}`
      ),
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
  borderRadius: 6,
  fontSize: 12,
} as const;

const axisStyle = { stroke: "var(--color-muted-foreground, #9ca3af)", fontSize: 11 } as const;

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
      className={`inline-flex items-center rounded border px-1.5 py-0.5 font-semibold tabular-nums ${row ? "cursor-help" : ""} ${color}`}
    >
      {pct}%
    </span>
  );
}

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

function DailyOrderTable({ trendData }: { trendData: TrendDay[] }) {
  const [collapsed, setCollapsed] = useState(false);

  const rows = [...trendData].sort((a, b) => b.day.localeCompare(a.day));

  return (
    <div className="rounded-lg border bg-card shadow-[var(--shadow-panel)]">
      {/* Table header row */}
      <button
        type="button"
        onClick={() => setCollapsed((v) => !v)}
        className="flex w-full items-center justify-between px-4 py-3 text-left"
      >
        <div>
          <h3 className="text-sm font-semibold">Open Orders by Date</h3>
          <p className="text-xs text-muted-foreground">Order status breakdown — grouped by day</p>
        </div>
        {collapsed ? (
          <ChevronRight className="h-4 w-4 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
        )}
      </button>

      {!collapsed && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-t bg-muted/40">
                <th className="px-4 py-2 text-left font-semibold text-muted-foreground whitespace-nowrap">Date</th>
                <th className="px-3 py-2 text-right font-semibold text-muted-foreground">Total</th>
                {STATUS_COLS.map((col) => (
                  <th key={col.key} className="px-3 py-2 text-right font-semibold text-muted-foreground whitespace-nowrap">
                    {col.label}
                  </th>
                ))}
                <th className="px-3 py-2 text-right font-semibold text-muted-foreground whitespace-nowrap">
                  <span className="inline-flex items-center gap-1">
                    Completion Rate
                    <span
                      title={COMPLETION_TOOLTIP}
                      className="inline-flex h-3.5 w-3.5 cursor-help items-center justify-center rounded-full bg-muted text-[9px] font-bold text-muted-foreground ring-1 ring-muted-foreground/30 select-none"
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
                  key={row.day}
                  className={`border-t transition-colors hover:bg-muted/30 ${i % 2 === 0 ? "" : "bg-muted/10"}`}
                >
                  <td className="px-4 py-2 font-medium whitespace-nowrap">{fmtFullDate(row.day)}</td>
                  <td className="px-3 py-2 text-right tabular-nums font-semibold">{row.total.toLocaleString()}</td>
                  {STATUS_COLS.map((col) => {
                    const val = (row as any)[col.key] as number;
                    return (
                      <td key={col.key} className="px-3 py-2 text-right tabular-nums">
                        {val > 0 ? (
                          <StatusBadge statusKey={col.key} value={val} />
                        ) : (
                          <span className="text-muted-foreground/50">—</span>
                        )}
                      </td>
                    );
                  })}
                  <td className="px-3 py-2 text-right tabular-nums">
                    <CompletionRateBadge rate={calcCompletionRate(row)} row={row} />
                  </td>
                </tr>
              ))}

              {/* Totals footer */}
              {rows.length > 0 && (() => {
                const totals = rows.reduce(
                  (acc, row) => {
                    acc.total += row.total;
                    for (const col of STATUS_COLS) {
                      acc[col.key] = (acc[col.key] ?? 0) + ((row as any)[col.key] as number);
                    }
                    return acc;
                  },
                  { total: 0 } as Record<string, number>
                );
                const totalsRow = {
                  total:     totals.total,
                  completed: totals.completed ?? 0,
                  open:      totals.open      ?? 0,
                  rejected:  totals.rejected  ?? 0,
                  on_hold:   totals.on_hold   ?? 0,
                };
                return (
                  <tr className="border-t bg-muted/40 font-semibold">
                    <td className="px-4 py-2 text-muted-foreground">Totals</td>
                    <td className="px-3 py-2 text-right tabular-nums">{totals.total.toLocaleString()}</td>
                    {STATUS_COLS.map((col) => (
                      <td key={col.key} className="px-3 py-2 text-right tabular-nums">
                        {(totals[col.key] ?? 0) > 0
                          ? (totals[col.key] ?? 0).toLocaleString()
                          : <span className="text-muted-foreground/50">—</span>}
                      </td>
                    ))}
                    <td className="px-3 py-2 text-right tabular-nums">
                      <CompletionRateBadge rate={calcCompletionRate(totalsRow)} row={totalsRow} />
                    </td>
                  </tr>
                );
              })()}
            </tbody>
          </table>

          {rows.length === 0 && (
            <p className="py-8 text-center text-xs text-muted-foreground">No data for selected period.</p>
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
    <span className={`inline-flex items-center rounded border px-1.5 py-0.5 font-medium tabular-nums ${styles[statusKey]}`}>
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
    <div className="rounded-lg border bg-card shadow-[var(--shadow-panel)]">
      <button
        type="button"
        onClick={() => setCollapsed((v) => !v)}
        className="flex w-full items-center justify-between px-4 py-3 text-left"
      >
        <div>
          <h3 className="text-sm font-semibold">Cumulative Orders by Month</h3>
          <p className="text-xs text-muted-foreground">Monthly totals with completion rate</p>
        </div>
        {collapsed ? (
          <ChevronRight className="h-4 w-4 text-muted-foreground" />
        ) : (
          <ChevronDown className="h-4 w-4 text-muted-foreground" />
        )}
      </button>

      {!collapsed && (
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="border-t bg-muted/40">
                <th className="px-4 py-2 text-left font-semibold text-muted-foreground whitespace-nowrap">Month</th>
                <th className="px-3 py-2 text-right font-semibold text-muted-foreground">Total</th>
                {STATUS_COLS.map((col) => (
                  <th key={col.key} className="px-3 py-2 text-right font-semibold text-muted-foreground whitespace-nowrap">
                    {col.label}
                  </th>
                ))}
                <th className="px-3 py-2 text-right font-semibold text-muted-foreground whitespace-nowrap">
                  <span className="inline-flex items-center gap-1">
                    Completion Rate
                    <span
                      title={COMPLETION_TOOLTIP}
                      className="inline-flex h-3.5 w-3.5 cursor-help items-center justify-center rounded-full bg-muted text-[9px] font-bold text-muted-foreground ring-1 ring-muted-foreground/30 select-none"
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
                  <td className="px-4 py-2 font-medium whitespace-nowrap">{row.label}</td>
                  <td className="px-3 py-2 text-right tabular-nums font-semibold">{row.total.toLocaleString()}</td>
                  {STATUS_COLS.map((col) => {
                    const val = row[col.key as keyof typeof row] as number;
                    return (
                      <td key={col.key} className="px-3 py-2 text-right tabular-nums">
                        {val > 0 ? (
                          <StatusBadge statusKey={col.key} value={val} />
                        ) : (
                          <span className="text-muted-foreground/50">—</span>
                        )}
                      </td>
                    );
                  })}
                  <td className="px-3 py-2 text-right tabular-nums">
                    <CompletionRateBadge rate={calcCompletionRate(row)} row={row} />
                  </td>
                </tr>
              ))}

              {rows.length > 0 && (
                <tr className="border-t bg-muted/40 font-semibold">
                  <td className="px-4 py-2 text-muted-foreground">Grand Total</td>
                  <td className="px-3 py-2 text-right tabular-nums">{grand.total.toLocaleString()}</td>
                  {STATUS_COLS.map((col) => {
                    const val = grand[col.key as keyof typeof grand] as number;
                    return (
                      <td key={col.key} className="px-3 py-2 text-right tabular-nums">
                        {val > 0 ? val.toLocaleString() : <span className="text-muted-foreground/50">—</span>}
                      </td>
                    );
                  })}
                  <td className="px-3 py-2 text-right tabular-nums">
                    <CompletionRateBadge rate={calcCompletionRate(grand)} row={grand} />
                  </td>
                </tr>
              )}
            </tbody>
          </table>

          {rows.length === 0 && (
            <p className="py-8 text-center text-xs text-muted-foreground">No data for selected period.</p>
          )}
        </div>
      )}
    </div>
  );
}

// -------------------------------------------------------------------------
// Page
// -------------------------------------------------------------------------

function DashboardPage() {
  const navigate = useNavigate();
  const [dateFrom, setDateFrom] = useState(startOfMonth);
  const [dateTo, setDateTo]     = useState(today);
  const [compare, setCompare]   = useState(false);
  const [activeStats, setActiveStats] = useState<StatKey[]>(
    ["total", "open", "completed", "pending_approval", "shipping", "rejected", "on_hold"]
  );

  const kpis  = useKpis(dateFrom, dateTo);
  const trend = useTrend(dateFrom, dateTo, compare);

  function goToOrders(statusFilter?: string) {
    const params: Record<string, string> = {
      date_from: dateFrom,
      date_to:   dateTo,
    };
    if (statusFilter) params.status = statusFilter;
    navigate({ to: "/app/orders", search: params as any });
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
    const map: Record<string, Record<string, number | string>> = {};
    for (const d of trend.data.current) {
      map[d.day] = { day: d.day, label: fmtDay(d.day), ...d };
    }
    if (trend.data.previous) {
      for (let i = 0; i < trend.data.previous.length; i++) {
        const d = trend.data.previous[i];
        const currentDay = trend.data.current[i]?.day ?? d.day;
        if (map[currentDay]) {
          map[currentDay].prev_total            = d.total;
          map[currentDay].prev_completed        = d.completed;
          map[currentDay].prev_pending_approval = d.pending_approval;
          map[currentDay].prev_shipping         = d.shipping;
          map[currentDay].prev_rejected         = d.rejected;
          map[currentDay].prev_on_hold          = d.on_hold;
          map[currentDay].prev_open             = d.open;
        }
      }
    }
    return Object.values(map).sort((a, b) =>
      String(a.day).localeCompare(String(b.day))
    );
  })();

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Operations Dashboard</h1>
          <p className="text-sm text-muted-foreground">Live order status — Acumatica</p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div className="grid gap-1">
            <Label className="text-xs">From</Label>
            <Input type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-8 w-36 text-xs" />
          </div>
          <div className="grid gap-1">
            <Label className="text-xs">To</Label>
            <Input type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-8 w-36 text-xs" />
          </div>
          <Button variant="outline" size="sm" className="h-8" onClick={() => { kpis.refetch(); trend.refetch(); }}>
            <RefreshCw className="h-3.5 w-3.5" />
          </Button>
          <div className="flex items-center gap-2 rounded-md border px-3 h-8">
            <Switch id="compare-toggle" checked={compare} onCheckedChange={setCompare} />
            <Label htmlFor="compare-toggle" className="cursor-pointer text-xs select-none whitespace-nowrap">
              Compare prev. period
            </Label>
          </div>
        </div>
      </div>

      {/* Stat cards */}
      {kpis.isLoading ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
          {Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-24" />)}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-8">
          {STATS.map((s) => (
            <button
              key={s.key}
              type="button"
              onClick={() => goToOrders(s.statusFilter)}
              className={`group rounded-lg border p-4 text-left transition-all hover:shadow-md active:scale-[0.98] ${s.bg} ${s.border}`}
            >
              <div className="flex items-center justify-between mb-2">
                <span className={`text-[11px] font-semibold uppercase tracking-wide ${s.color} opacity-80`}>
                  {s.label}
                </span>
                <s.icon className={`h-4 w-4 ${s.color} opacity-60`} />
              </div>
              <div className={`text-2xl font-bold tabular-nums ${s.color}`}>
                {kpi ? (kpi[s.key] ?? 0).toLocaleString() : "—"}
              </div>
              <div className="mt-1 text-[10px] text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity">
                View orders →
              </div>
            </button>
          ))}

          {/* Avg orders / day card */}
          <div className="rounded-lg border p-4 bg-teal-50 border-teal-200 dark:bg-teal-950/40 dark:border-teal-800">
            <div className="flex items-center justify-between mb-2">
              <span className="text-[11px] font-semibold uppercase tracking-wide text-teal-700 dark:text-teal-300 opacity-80">
                Avg / Day
              </span>
              <BarChart2 className="h-4 w-4 text-teal-700 dark:text-teal-300 opacity-60" />
            </div>
            <div className="text-2xl font-bold tabular-nums text-teal-700 dark:text-teal-300">
              {kpi ? kpi.avg_per_day.toLocaleString("en-KE", { minimumFractionDigits: 1, maximumFractionDigits: 1 }) : "—"}
            </div>
            <div className="mt-1 text-[10px] text-teal-600/70 dark:text-teal-400/70">
              {kpi ? `${kpi.active_days} active day${kpi.active_days !== 1 ? "s" : ""} · MTD` : "orders per day"}
            </div>
          </div>
        </div>
      )}

      {/* Trend chart */}
      <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
        <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-semibold">Order Volume Trend</h3>
            <p className="text-xs text-muted-foreground">
              {fmtDay(dateFrom)} — {fmtDay(dateTo)}
              {compare && <span className="ml-2 text-muted-foreground/70">(dashed = previous period)</span>}
            </p>
          </div>
          {/* Series toggles */}
          <div className="flex flex-wrap gap-2">
            {STATS.map((s) => (
              <button
                key={s.key}
                type="button"
                onClick={() => toggleStat(s.key)}
                className={`flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-medium transition-all ${
                  activeStats.includes(s.key)
                    ? `${s.bg} ${s.border} ${s.color}`
                    : "border-muted bg-muted/30 text-muted-foreground line-through"
                }`}
              >
                <span
                  className="inline-block h-2 w-2 rounded-full"
                  style={{ background: CHART_COLORS[s.key] }}
                />
                {s.label}
              </button>
            ))}
          </div>
        </div>

        {trend.isLoading ? (
          <Skeleton className="h-64 w-full" />
        ) : (
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={chartData} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                <defs>
                  {STATS.map((s) => (
                    <linearGradient key={s.key} id={`grad-${s.key}`} x1="0" y1="0" x2="0" y2="1">
                      <stop offset="5%" stopColor={CHART_COLORS[s.key]} stopOpacity={0.25} />
                      <stop offset="95%" stopColor={CHART_COLORS[s.key]} stopOpacity={0} />
                    </linearGradient>
                  ))}
                </defs>
                <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="label" {...axisStyle} tick={{ fontSize: 10 }} />
                <YAxis {...axisStyle} allowDecimals={false} width={32} />
                <Tooltip contentStyle={tooltipStyle} />
                {STATS.filter((s) => activeStats.includes(s.key)).map((s) => (
                  <Area
                    key={s.key}
                    type="monotone"
                    dataKey={s.key}
                    name={s.label}
                    stroke={CHART_COLORS[s.key]}
                    fill={`url(#grad-${s.key})`}
                    strokeWidth={s.key === "total" ? 2 : 1.5}
                    dot={false}
                    activeDot={{ r: 3 }}
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
        <Skeleton className="h-32 w-full" />
      ) : trend.data?.current && trend.data.current.length > 0 ? (
        <MonthlyOrderTable trendData={trend.data.current} />
      ) : null}

      {/* Daily breakdown table */}
      {trend.isLoading ? (
        <Skeleton className="h-48 w-full" />
      ) : trend.data?.current && trend.data.current.length > 0 ? (
        <DailyOrderTable trendData={trend.data.current} />
      ) : null}
    </div>
  );
}
