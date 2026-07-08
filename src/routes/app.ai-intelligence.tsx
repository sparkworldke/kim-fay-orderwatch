import { createFileRoute } from "@tanstack/react-router";
import { useMemo, useState } from "react";
import type { DateRange } from "react-day-picker";
import {
  Sparkles, RefreshCw, CalendarDays, Package, Users, LineChart,
  TrendingUp, TrendingDown, AlertTriangle, ChevronRight, Wand2,
} from "lucide-react";
import {
  Bar, BarChart, CartesianGrid, Line, LineChart as ReLineChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Calendar } from "@/components/ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { useAiIntelligence, useGenerateAiIntelligence } from "@/hooks/useAiIntelligence";
import {
  DATE_PRESETS,
  formatRangeLabel,
  resolveDatePreset,
  type DatePresetId,
} from "@/lib/date-presets";
import { formatKES, formatNumber } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/ai-intelligence")({
  head: () => ({ meta: [{ title: "AI Intelligence — Kim-Fay OrderWatch" }] }),
  component: AiIntelligencePage,
});

const axisStyle = { stroke: "var(--color-muted-foreground)", fontSize: 11 } as const;

function AiIntelligencePage() {
  const [preset, setPreset] = useState<DatePresetId>("last_7_days");
  const initial = resolveDatePreset("last_7_days");
  const [dateFrom, setDateFrom] = useState(initial.from);
  const [dateTo, setDateTo] = useState(initial.to);
  const [calendarOpen, setCalendarOpen] = useState(false);

  const briefing = useAiIntelligence(dateFrom, dateTo);
  const generate = useGenerateAiIntelligence(dateFrom, dateTo);

  const calendarRange: DateRange | undefined = useMemo(() => ({
    from: dateFrom ? new Date(dateFrom + "T00:00:00") : undefined,
    to: dateTo ? new Date(dateTo + "T00:00:00") : undefined,
  }), [dateFrom, dateTo]);

  function applyPreset(id: DatePresetId) {
    setPreset(id);
    if (id !== "custom") {
      const range = resolveDatePreset(id);
      setDateFrom(range.from);
      setDateTo(range.to);
    }
  }

  function applyCustomRange(range: DateRange | undefined) {
    if (!range?.from) return;
    const from = range.from.toISOString().slice(0, 10);
    const to = (range.to ?? range.from).toISOString().slice(0, 10);
    setPreset("custom");
    setDateFrom(from);
    setDateTo(to);
    if (range.to) setCalendarOpen(false);
  }

  const data = briefing.data;
  const metrics = data?.metrics;
  const insights = data?.insights;
  const hasInsights = !!insights?.executive_summary;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
            <Sparkles className="h-5 w-5 text-primary" /> AI Intelligence
          </h1>
          <p className="text-sm text-muted-foreground">
            Order metrics load automatically. AI insights generate on demand to save tokens.
          </p>
        </div>
        {hasInsights && (
          <Button
            size="sm"
            variant="outline"
            disabled={generate.isPending}
            onClick={() => generate.mutate(true)}
          >
            <RefreshCw className={cn("mr-1.5 h-3.5 w-3.5", generate.isPending && "animate-spin")} />
            {generate.isPending ? "Regenerating…" : "Regenerate insights"}
          </Button>
        )}
      </div>

      {/* Date controls */}
      <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
        <div className="flex flex-wrap items-center gap-2">
          {DATE_PRESETS.map((item) => (
            <Button
              key={item.id}
              size="sm"
              variant={preset === item.id ? "default" : "outline"}
              onClick={() => applyPreset(item.id)}
            >
              {item.label}
            </Button>
          ))}
          <Popover open={calendarOpen} onOpenChange={setCalendarOpen}>
            <PopoverTrigger asChild>
              <Button size="sm" variant={preset === "custom" ? "default" : "outline"}>
                <CalendarDays className="mr-1.5 h-3.5 w-3.5" />
                {formatRangeLabel(dateFrom, dateTo)}
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-auto p-0" align="start">
              <Calendar
                mode="range"
                selected={calendarRange}
                onSelect={applyCustomRange}
                numberOfMonths={2}
                defaultMonth={calendarRange?.from}
              />
            </PopoverContent>
          </Popover>
        </div>
        {data && (
          <p className="mt-2 text-xs text-muted-foreground">
            Comparing with prior period: {data.comparison_period.label}
            {hasInsights && data.provider && <> · AI: {data.provider}</>}
            {hasInsights && data.insights_generated_at && (
              <> · Saved {new Date(data.insights_generated_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}</>
            )}
            {hasInsights && data.ai_status && data.ai_status !== "success" && (
              <> · <span className="text-amber-600">Rule-based fallback ({data.ai_status})</span></>
            )}
          </p>
        )}
      </div>

      {briefing.isLoading && <MetricsSkeleton />}

      {briefing.isError && (
        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          Could not load intelligence data. {briefing.error instanceof Error ? briefing.error.message : "Try again."}
        </div>
      )}

      {data && metrics && (
        <>
          {/* KPI strip */}
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard label="Orders" value={formatNumber(metrics.orders.orders_received)} change={metrics.orders_comparison.orders_received} />
            <KpiCard label="Order value" value={formatKES(metrics.orders.total_value, { compact: true })} change={metrics.orders_comparison.total_value} />
            <KpiCard label="Completion rate" value={`${metrics.orders.completion_rate}%`} change={metrics.orders_comparison.completion_rate} suffix="%" />
            <KpiCard label="Revenue at risk" value={formatKES(metrics.orders.revenue_at_risk, { compact: true })} change={metrics.orders_comparison.revenue_at_risk} invert />
          </div>

          {/* Charts */}
          <div className="grid gap-3 lg:grid-cols-2">
            <ChartPanel title="Daily orders" subtitle={data.period.label}>
              <ResponsiveContainer width="100%" height={200}>
                <ReLineChart data={metrics.daily_trend}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-border)" />
                  <XAxis dataKey="day" tickFormatter={(v) => v.slice(5)} {...axisStyle} />
                  <YAxis {...axisStyle} />
                  <Tooltip />
                  <Line type="monotone" dataKey="orders" stroke="var(--color-chart-1)" strokeWidth={2} dot={false} />
                </ReLineChart>
              </ResponsiveContainer>
            </ChartPanel>
            <ChartPanel title="12-week history" subtitle="Order volume by week">
              <ResponsiveContainer width="100%" height={200}>
                <BarChart data={metrics.historical_weekly}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-border)" />
                  <XAxis dataKey="week_start" tickFormatter={(v) => String(v).slice(-3)} {...axisStyle} />
                  <YAxis {...axisStyle} />
                  <Tooltip />
                  <Bar dataKey="orders" fill="var(--color-chart-2)" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartPanel>
          </div>

          {/* Generate CTA or cached insights */}
          {!hasInsights ? (
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed bg-muted/20 px-6 py-14 text-center shadow-[var(--shadow-panel)]">
              <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                <Wand2 className="h-7 w-7 text-primary" />
              </div>
              <h2 className="text-lg font-semibold">Generate AI insights</h2>
              <p className="mt-2 max-w-md text-sm text-muted-foreground">
                Metrics above are live from your database. Click below to produce an executive briefing
                for {data.period.label}. The result is saved for this date range until you regenerate.
              </p>
              <Button
                className="mt-6"
                size="lg"
                disabled={generate.isPending}
                onClick={() => generate.mutate(false)}
              >
                <Sparkles className={cn("mr-2 h-4 w-4", generate.isPending && "animate-pulse")} />
                {generate.isPending ? "Generating insights…" : "Generate insights"}
              </Button>
              {generate.isError && (
                <p className="mt-3 text-sm text-destructive">
                  {generate.error instanceof Error ? generate.error.message : "Generation failed. Try again."}
                </p>
              )}
            </div>
          ) : (
            <>
              <section className="rounded-lg border bg-gradient-to-br from-primary/5 via-card to-card p-5 shadow-[var(--shadow-panel)]">
                <div className="mb-2 flex items-center gap-2">
                  <Badge variant="secondary" className="text-[10px]">Executive Summary</Badge>
                  <span className="text-[11px] text-muted-foreground">{data.period.label}</span>
                  {data.insights_cached && (
                    <Badge variant="outline" className="text-[10px]">Cached</Badge>
                  )}
                </div>
                <p className="text-sm leading-relaxed text-foreground">{insights.executive_summary}</p>
              </section>

              <div className="grid gap-4 lg:grid-cols-3">
                <InsightPanel icon={Package} title="Orders" section={insights.orders} />
                <InsightPanel
                  icon={Users}
                  title="Customer behaviour"
                  section={insights.customer_behaviour}
                  extra={metrics.customers.top_customers.slice(0, 3).map((c) => `${c.customer_name}: ${formatKES(c.value, { compact: true })}`)}
                />
                <InsightPanel
                  icon={LineChart}
                  title="Predictions"
                  section={insights.predictions}
                  extra={[
                    `Next 7 days: ~${metrics.projections.projected_next_7_days_orders} orders`,
                    `Projected value: ${formatKES(metrics.projections.projected_next_7_days_value, { compact: true })}`,
                    `Momentum: ${metrics.projections.volume_momentum_pct}%`,
                  ]}
                />
              </div>

              {insights.actions.length > 0 && (
                <section className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
                  <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
                    <AlertTriangle className="h-4 w-4 text-amber-500" /> Recommended actions
                  </h3>
                  <ul className="space-y-2">
                    {insights.actions.map((action, i) => (
                      <li key={i} className="flex gap-2 text-sm">
                        <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                        <span>{action}</span>
                      </li>
                    ))}
                  </ul>
                </section>
              )}
            </>
          )}
        </>
      )}
    </div>
  );
}

function KpiCard({
  label,
  value,
  change,
  suffix = "%",
  invert,
}: {
  label: string;
  value: string;
  change?: { change_pct: number };
  suffix?: string;
  invert?: boolean;
}) {
  const pct = change?.change_pct ?? 0;
  const positive = invert ? pct < 0 : pct > 0;
  const negative = invert ? pct > 0 : pct < 0;
  const Icon = positive ? TrendingUp : negative ? TrendingDown : LineChart;
  const toneClass =
    label === "Orders" ? "border-blue-200 bg-blue-50/70 dark:border-blue-900/50 dark:bg-blue-950/20" :
    label === "Order value" ? "border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/50 dark:bg-emerald-950/20" :
    label === "Completion rate" ? "border-cyan-200 bg-cyan-50/70 dark:border-cyan-900/50 dark:bg-cyan-950/20" :
    "border-red-200 bg-red-50/70 dark:border-red-900/50 dark:bg-red-950/20";
  const valueClass =
    label === "Orders" ? "text-blue-700 dark:text-blue-300" :
    label === "Order value" ? "text-emerald-700 dark:text-emerald-300" :
    label === "Completion rate" ? "text-cyan-700 dark:text-cyan-300" :
    "text-red-700 dark:text-red-300";

  return (
    <div className={cn("rounded-lg border p-4 shadow-[var(--shadow-panel)]", toneClass)}>
      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={cn("mt-1 text-xl font-bold", valueClass)}>{value}</p>
      {change && (
        <div className={cn(
          "mt-1 flex items-center gap-1 text-xs font-medium",
          positive && "text-green-600 dark:text-green-400",
          negative && "text-destructive",
          !positive && !negative && "text-muted-foreground",
        )}>
          <Icon className="h-3 w-3" />
          {pct > 0 ? "+" : ""}{pct}{suffix} vs prior period
        </div>
      )}
    </div>
  );
}

function ChartPanel({ title, subtitle, children }: { title: string; subtitle: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <h3 className="text-sm font-semibold">{title}</h3>
      <p className="text-[11px] text-muted-foreground">{subtitle}</p>
      <div className="mt-3">{children}</div>
    </div>
  );
}

function InsightPanel({
  icon: Icon,
  title,
  section,
  extra,
}: {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  section: { summary: string; highlights: string[] };
  extra?: string[];
}) {
  return (
    <section className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold">
        <Icon className="h-4 w-4 text-primary" /> {title}
      </h3>
      <p className="text-sm leading-relaxed text-muted-foreground">{section.summary}</p>
      <ul className="mt-3 space-y-1.5">
        {section.highlights.map((item, i) => (
          <li key={i} className="flex gap-2 text-xs leading-relaxed">
            <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
            <span>{item}</span>
          </li>
        ))}
      </ul>
      {extra && extra.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-1">
          {extra.map((tag) => (
            <Badge key={tag} variant="outline" className="text-[10px]">{tag}</Badge>
          ))}
        </div>
      )}
    </section>
  );
}

function MetricsSkeleton() {
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-24 rounded-lg" />)}
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <Skeleton className="h-56 rounded-lg" />
        <Skeleton className="h-56 rounded-lg" />
      </div>
    </div>
  );
}
