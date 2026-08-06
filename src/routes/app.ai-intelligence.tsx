import { createFileRoute } from "@tanstack/react-router";
import { useEffect, useMemo, useState } from "react";
import type { DateRange } from "react-day-picker";
import {
  Sparkles,
  RefreshCw,
  CalendarDays,
  Package,
  Users,
  LineChart,
  TrendingUp,
  TrendingDown,
  AlertTriangle,
  ChevronRight,
  Wand2,
  Brain,
  Lock,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart as ReLineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Skeleton } from "@/components/ui/skeleton";
import { Calendar } from "@/components/ui/calendar";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { Input } from "@/components/ui/input";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  useAiIntelligence,
  useGenerateAiIntelligence,
  useGeniusConsultants,
  useGeniusConsultant,
  useGenerateGenius,
  type IntelligenceSection,
} from "@/hooks/useAiIntelligence";
import {
  DATE_PRESETS,
  formatRangeLabel,
  resolveDatePreset,
  type DatePresetId,
} from "@/lib/date-presets";
import { useMaskedKESFormatter } from "@/components/MaskedCurrency";
import { formatNumber } from "@/lib/format";
import { cn } from "@/lib/utils";
import { ApiError } from "@/lib/api";

export const Route = createFileRoute("/app/ai-intelligence")({
  head: () => ({ meta: [{ title: "AI Intelligence — Kim-Fay Sight" }] }),
  validateSearch: (search: Record<string, unknown>): { tab: "company" | "genius" } => ({
    tab: search.tab === "genius" ? "genius" : "company",
  }),
  component: AiIntelligencePage,
});

const axisStyle = { stroke: "var(--color-muted-foreground)", fontSize: 11 } as const;

function AiIntelligencePage() {
  const navigate = Route.useNavigate();
  const { tab: mainTab } = Route.useSearch();

  function setMainTab(next: "company" | "genius") {
    void navigate({
      search: (prev) => ({ ...prev, tab: next }),
      replace: true,
    });
  }

  return (
    <div className="space-y-5">
      <div>
        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
          <Sparkles className="h-5 w-5 text-primary" /> AI Intelligence
        </h1>
        <p className="text-sm text-muted-foreground">
          Company-wide briefings and Kimfay Genius coaching for each sales consultant.
        </p>
      </div>

      <Tabs value={mainTab} onValueChange={(v) => setMainTab(v as "company" | "genius")}>
        <TabsList>
          <TabsTrigger value="company">Executive / Company</TabsTrigger>
          <TabsTrigger value="genius">
            <Brain className="mr-1.5 h-3.5 w-3.5" />
            Kimfay Genius
          </TabsTrigger>
        </TabsList>
        <TabsContent value="company" className="mt-4">
          <CompanyIntelligenceTab />
        </TabsContent>
        <TabsContent value="genius" className="mt-4">
          <KimfayGeniusTab />
        </TabsContent>
      </Tabs>
    </div>
  );
}

function CompanyIntelligenceTab() {
  const kes = useMaskedKESFormatter();
  const [preset, setPreset] = useState<DatePresetId>("last_week");
  const initial = resolveDatePreset("last_week");
  const [dateFrom, setDateFrom] = useState(initial.from);
  const [dateTo, setDateTo] = useState(initial.to);
  const [calendarOpen, setCalendarOpen] = useState(false);

  const briefing = useAiIntelligence(dateFrom, dateTo);
  const generate = useGenerateAiIntelligence(dateFrom, dateTo);

  const calendarRange: DateRange | undefined = useMemo(
    () => ({
      from: dateFrom ? new Date(dateFrom + "T00:00:00") : undefined,
      to: dateTo ? new Date(dateTo + "T00:00:00") : undefined,
    }),
    [dateFrom, dateTo],
  );

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
  const isGenerating =
    generate.isPending || data?.ai_status === "queued" || data?.ai_status === "running";

  const generateError =
    generate.error instanceof Error
      ? generate.error.message
      : generate.error instanceof ApiError
        ? ((generate.error.data as { message?: string })?.message ?? generate.error.message)
        : data?.ai_status === "failed"
          ? data.error_message
          : null;

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <p className="text-sm text-muted-foreground">
          Metrics load automatically. AI insights generate on demand (queued) to save tokens.
        </p>
        {hasInsights && (
          <Button
            size="sm"
            variant="outline"
            disabled={isGenerating}
            onClick={() => generate.mutate(true)}
          >
            <RefreshCw className={cn("mr-1.5 h-3.5 w-3.5", isGenerating && "animate-spin")} />
            {isGenerating ? "Regenerating…" : "Regenerate insights"}
          </Button>
        )}
      </div>

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
            {hasInsights && data.provider && (
              <>
                {" "}
                · AI: {data.provider}
                {data.model ? ` / ${data.model}` : ""}
              </>
            )}
            {hasInsights && data.insights_generated_at && (
              <>
                {" "}
                · Saved{" "}
                {new Date(data.insights_generated_at).toLocaleString("en-KE", {
                  timeZone: "Africa/Nairobi",
                })}
              </>
            )}
            {data.ai_status === "queued" || data.ai_status === "running" ? (
              <>
                {" "}
                · <span className="text-amber-600">Generation {data.ai_status}…</span>
              </>
            ) : null}
            {data.ai_status === "failed" && data.error_message ? (
              <>
                {" "}
                · <span className="text-destructive">Failed: {data.error_message}</span>
              </>
            ) : null}
          </p>
        )}
      </div>

      {briefing.isLoading && <MetricsSkeleton />}

      {briefing.isError && (
        <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          Could not load intelligence data.{" "}
          {briefing.error instanceof Error ? briefing.error.message : "Try again."}
        </div>
      )}

      {data && metrics && (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              label="Orders"
              value={formatNumber(metrics.orders.orders_received)}
              change={metrics.orders_comparison.orders_received}
            />
            <KpiCard
              label="Order value"
              value={kes(metrics.orders.total_value, { compact: true })}
              change={metrics.orders_comparison.total_value}
            />
            <KpiCard
              label="Completion rate"
              value={`${metrics.orders.completion_rate}%`}
              change={metrics.orders_comparison.completion_rate}
              suffix="%"
            />
            <KpiCard
              label="Revenue at risk"
              value={kes(metrics.orders.revenue_at_risk, { compact: true })}
              change={metrics.orders_comparison.revenue_at_risk}
              invert
            />
          </div>

          <div className="grid gap-3 lg:grid-cols-2">
            <ChartPanel title="Daily orders" subtitle={data.period.label}>
              <ResponsiveContainer width="100%" height={200}>
                <ReLineChart data={metrics.daily_trend}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-border)" />
                  <XAxis dataKey="day" tickFormatter={(v) => v.slice(5)} {...axisStyle} />
                  <YAxis {...axisStyle} />
                  <Tooltip />
                  <Line
                    type="monotone"
                    dataKey="orders"
                    stroke="var(--color-chart-1)"
                    strokeWidth={2}
                    dot={false}
                  />
                </ReLineChart>
              </ResponsiveContainer>
            </ChartPanel>
            <ChartPanel title="12-week history" subtitle="Order volume by week">
              <ResponsiveContainer width="100%" height={200}>
                <BarChart data={metrics.historical_weekly}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--color-border)" />
                  <XAxis dataKey="week_start" tickFormatter={(v) => String(v).slice(-5)} {...axisStyle} />
                  <YAxis {...axisStyle} />
                  <Tooltip />
                  <Bar dataKey="orders" fill="var(--color-chart-2)" radius={[3, 3, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartPanel>
          </div>

          {!hasInsights ? (
            <div className="flex flex-col items-center justify-center rounded-lg border border-dashed bg-muted/20 px-6 py-14 text-center shadow-[var(--shadow-panel)]">
              <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-primary/10">
                <Wand2 className="h-7 w-7 text-primary" />
              </div>
              <h2 className="text-lg font-semibold">Generate AI insights</h2>
              <p className="mt-2 max-w-md text-sm text-muted-foreground">
                Metrics above are live from your database. Click below to produce an executive briefing
                for {data.period.label}. Requires a configured AI key (OpenAI, xAI, or Anthropic).
              </p>
              <Button
                className="mt-6"
                size="lg"
                disabled={isGenerating}
                onClick={() => generate.mutate(false)}
              >
                <Sparkles className={cn("mr-2 h-4 w-4", isGenerating && "animate-pulse")} />
                {isGenerating ? "Generating insights…" : "Generate insights"}
              </Button>
              {(generateError || generate.isError) && (
                <div className="mt-4 max-w-lg rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-left text-sm text-destructive">
                  {generateError || "Generation failed. Try again."}
                  <p className="mt-1 text-xs text-muted-foreground">
                    Administration → AI Connector · ensure{" "}
                    <code className="text-[10px]">queue:work</code> is running on the server.
                  </p>
                </div>
              )}
            </div>
          ) : (
            <>
              <section className="rounded-lg border bg-gradient-to-br from-primary/5 via-card to-card p-5 shadow-[var(--shadow-panel)]">
                <div className="mb-2 flex items-center gap-2">
                  <Badge variant="secondary" className="text-[10px]">
                    Executive Summary
                  </Badge>
                  <span className="text-[11px] text-muted-foreground">{data.period.label}</span>
                  {data.insights_cached && (
                    <Badge variant="outline" className="text-[10px]">
                      Cached
                    </Badge>
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
                  extra={metrics.customers.top_customers
                    .slice(0, 3)
                    .map((c) => `${c.customer_name}: ${kes(c.value, { compact: true })}`)}
                />
                <InsightPanel
                  icon={LineChart}
                  title="Predictions"
                  section={insights.predictions}
                  extra={[
                    `Next 7 days: ~${metrics.projections.projected_next_7_days_orders} orders`,
                    `Projected value: ${kes(metrics.projections.projected_next_7_days_value, { compact: true })}`,
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

function KimfayGeniusTab() {
  const list = useGeniusConsultants();
  const [q, setQ] = useState("");
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const detail = useGeniusConsultant(selectedId);
  const generate = useGenerateGenius(selectedId);

  const consultants = (list.data?.data ?? []).filter((c) => {
    if (!q.trim()) return true;
    const s = q.toLowerCase();
    return (
      c.name.toLowerCase().includes(s) ||
      (c.rep_code ?? "").toLowerCase().includes(s) ||
      c.email.toLowerCase().includes(s)
    );
  });

  useEffect(() => {
    if (selectedId === null && consultants.length > 0) {
      setSelectedId(consultants[0].id);
    }
  }, [selectedId, consultants]);

  const briefing = detail.data?.briefing;
  const insights = briefing?.insights;
  const lockActive = detail.data?.lock_active ?? false;
  const canGenerate = detail.data?.can_generate ?? false;
  const isBusy =
    generate.isPending ||
    briefing?.ai_status === "queued" ||
    briefing?.ai_status === "running";

  return (
    <div className="space-y-3">
      <div className="rounded-lg border bg-card p-4 text-sm text-muted-foreground shadow-[var(--shadow-panel)]">
        <p className="font-medium text-foreground">Kimfay Genius</p>
        <p className="mt-1">
          Available to every signed-in user. Browse anyone with a sales book (rep code / consultant
          flag / portfolio assignments). Each brief is still scoped to that person&apos;s portfolio —
          not company-wide numbers.{" "}
          <strong>1 successful generation per person per week</strong>
          {list.data?.week_start ? ` · week of ${list.data.week_start}` : ""}.
          Failed runs do not consume the weekly slot.
        </p>
      </div>

      <div className="grid gap-4 lg:grid-cols-[280px_1fr]">
        <div className="rounded-lg border bg-card shadow-[var(--shadow-panel)]">
          <div className="border-b p-3">
            <Input
              placeholder="Search consultants…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              className="h-9"
            />
          </div>
          <div className="max-h-[480px] overflow-y-auto">
            {list.isLoading && (
              <div className="space-y-2 p-3">
                <Skeleton className="h-10" />
                <Skeleton className="h-10" />
              </div>
            )}
            {list.isError && (
              <p className="p-3 text-sm text-destructive">Could not load consultants.</p>
            )}
            {!list.isLoading && consultants.length === 0 && (
              <p className="p-3 text-sm text-muted-foreground">
                No active sales books found yet (need rep code, consultant flag, or customer assignments).
              </p>
            )}
            {consultants.map((c) => (
              <button
                key={c.id}
                type="button"
                onClick={() => setSelectedId(c.id)}
                className={cn(
                  "flex w-full items-center justify-between border-b px-3 py-2.5 text-left text-sm hover:bg-muted/50",
                  selectedId === c.id && "bg-primary/5",
                )}
              >
                <span>
                  <span className="font-medium">{c.name}</span>
                  {c.rep_code && (
                    <span className="ml-1 text-xs text-muted-foreground">{c.rep_code}</span>
                  )}
                </span>
                <StatusDot status={c.week_status} />
              </button>
            ))}
          </div>
        </div>

        <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
          {!selectedId ? (
            <p className="py-12 text-center text-sm text-muted-foreground">
              Select a consultant to view or generate their Kimfay Genius brief.
            </p>
          ) : detail.isLoading ? (
            <Skeleton className="h-48" />
          ) : detail.isError ? (
            <p className="text-sm text-destructive">Unable to load consultant brief.</p>
          ) : (
            <>
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h2 className="text-lg font-semibold">{detail.data?.consultant.name}</h2>
                  <p className="text-xs text-muted-foreground">
                    {detail.data?.consultant.rep_code
                      ? `Rep ${detail.data.consultant.rep_code} · `
                      : ""}
                    Week of {detail.data?.week_start}
                    {briefing?.ai_status ? ` · ${briefing.ai_status}` : ""}
                    {briefing?.provider ? ` · ${briefing.provider}` : ""}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button
                    size="sm"
                    disabled={!canGenerate || isBusy || lockActive}
                    onClick={() => generate.mutate(false)}
                  >
                    <Sparkles className={cn("mr-1.5 h-3.5 w-3.5", isBusy && "animate-pulse")} />
                    {isBusy ? "Generating…" : "Generate this week"}
                  </Button>
                  {lockActive && (
                    <Badge variant="secondary" className="gap-1">
                      <Lock className="h-3 w-3" /> Locked until{" "}
                      {detail.data?.unlock_at
                        ? new Date(detail.data.unlock_at).toLocaleDateString("en-KE")
                        : "next week"}
                    </Badge>
                  )}
                </div>
              </div>

              {generate.isError && (
                <div className="mt-3 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                  {generate.error instanceof Error
                    ? generate.error.message
                    : generate.error instanceof ApiError
                      ? ((generate.error.data as { message?: string })?.message ??
                        generate.error.message)
                      : "Generation failed"}
                </div>
              )}

              {briefing?.ai_status === "failed" && briefing.error_message && (
                <div className="mt-3 rounded-md border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
                  {briefing.error_message}
                </div>
              )}

              {insights?.executive_summary ? (
                <div className="mt-4 space-y-4">
                  <section className="rounded-lg border bg-gradient-to-br from-violet-500/5 via-card to-card p-4">
                    <Badge variant="secondary" className="mb-2 text-[10px]">
                      Kimfay Genius
                    </Badge>
                    <p className="text-sm leading-relaxed">{insights.executive_summary}</p>
                  </section>
                  <div className="grid gap-3 lg:grid-cols-3">
                    <InsightPanel icon={Users} title="Portfolio" section={insights.portfolio} />
                    <InsightPanel icon={AlertTriangle} title="Risks" section={insights.risks} />
                    <InsightPanel icon={LineChart} title="Predictions" section={insights.predictions} />
                  </div>
                  {insights.actions?.length > 0 && (
                    <section className="rounded-lg border p-4">
                      <h3 className="mb-2 text-sm font-semibold">Actions this week</h3>
                      <ul className="space-y-2">
                        {insights.actions.map((a, i) => (
                          <li key={i} className="flex gap-2 text-sm">
                            <ChevronRight className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                            {a}
                          </li>
                        ))}
                      </ul>
                    </section>
                  )}
                </div>
              ) : (
                <p className="mt-8 text-center text-sm text-muted-foreground">
                  No brief yet for this week. Click <strong>Generate this week</strong> when ready.
                </p>
              )}
            </>
          )}
        </div>
      </div>
    </div>
  );
}

function StatusDot({ status }: { status: string | null }) {
  if (status === "success") {
    return <span className="h-2 w-2 rounded-full bg-emerald-500" title="Ready" />;
  }
  if (status === "queued" || status === "running") {
    return <span className="h-2 w-2 animate-pulse rounded-full bg-amber-500" title={status} />;
  }
  if (status === "failed") {
    return <span className="h-2 w-2 rounded-full bg-red-500" title="Failed" />;
  }
  return <span className="h-2 w-2 rounded-full bg-muted-foreground/30" title="None" />;
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
    label === "Orders"
      ? "border-blue-200 bg-blue-50/70 dark:border-blue-900/50 dark:bg-blue-950/20"
      : label === "Order value"
        ? "border-emerald-200 bg-emerald-50/70 dark:border-emerald-900/50 dark:bg-emerald-950/20"
        : label === "Completion rate"
          ? "border-cyan-200 bg-cyan-50/70 dark:border-cyan-900/50 dark:bg-cyan-950/20"
          : "border-red-200 bg-red-50/70 dark:border-red-900/50 dark:bg-red-950/20";
  const valueClass =
    label === "Orders"
      ? "text-blue-700 dark:text-blue-300"
      : label === "Order value"
        ? "text-emerald-700 dark:text-emerald-300"
        : label === "Completion rate"
          ? "text-cyan-700 dark:text-cyan-300"
          : "text-red-700 dark:text-red-300";

  return (
    <div className={cn("rounded-lg border p-4 shadow-[var(--shadow-panel)]", toneClass)}>
      <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={cn("mt-1 text-xl font-bold", valueClass)}>{value}</p>
      {change && (
        <div
          className={cn(
            "mt-1 flex items-center gap-1 text-xs font-medium",
            positive && "text-green-600 dark:text-green-400",
            negative && "text-destructive",
            !positive && !negative && "text-muted-foreground",
          )}
        >
          <Icon className="h-3 w-3" />
          {pct > 0 ? "+" : ""}
          {pct}
          {suffix} vs prior period
        </div>
      )}
    </div>
  );
}

function ChartPanel({
  title,
  subtitle,
  children,
}: {
  title: string;
  subtitle: string;
  children: React.ReactNode;
}) {
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
  section: IntelligenceSection;
  extra?: string[];
}) {
  return (
    <section className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold">
        <Icon className="h-4 w-4 text-primary" /> {title}
      </h3>
      <p className="text-sm leading-relaxed text-muted-foreground">{section?.summary}</p>
      <ul className="mt-3 space-y-1.5">
        {(section?.highlights ?? []).map((item, i) => (
          <li key={i} className="flex gap-2 text-xs leading-relaxed">
            <span className="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-primary" />
            <span>{item}</span>
          </li>
        ))}
      </ul>
      {extra && extra.length > 0 && (
        <div className="mt-3 flex flex-wrap gap-1">
          {extra.map((tag) => (
            <Badge key={tag} variant="outline" className="text-[10px]">
              {tag}
            </Badge>
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
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-24 rounded-lg" />
        ))}
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <Skeleton className="h-56 rounded-lg" />
        <Skeleton className="h-56 rounded-lg" />
      </div>
    </div>
  );
}
