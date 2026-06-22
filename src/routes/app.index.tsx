import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useMutation, useQuery } from "@tanstack/react-query";
import { useState } from "react";
import {
  CheckCircle2, Clock, Package, RefreshCw,
  ShoppingCart, TrendingUp, XCircle,
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

type StatKey = "total" | "completed" | "shipping" | "pending_approval" | "rejected" | "on_hold";

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
    icon: TrendingUp,
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
// Page
// -------------------------------------------------------------------------

function DashboardPage() {
  const navigate = useNavigate();
  const [dateFrom, setDateFrom] = useState(startOfMonth);
  const [dateTo, setDateTo]     = useState(today);
  const [compare, setCompare]   = useState(false);
  const [activeStats, setActiveStats] = useState<StatKey[]>(
    ["total", "completed", "pending_approval", "shipping", "rejected", "on_hold"]
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
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
          {Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-24" />)}
        </div>
      ) : (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
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
    </div>
  );
}
