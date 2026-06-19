import { createFileRoute } from "@tanstack/react-router";
import {
  PackageCheck,
  Inbox,
  Percent,
  AlertOctagon,
  Wallet,
  AlertTriangle,
  Flame,
  Receipt,
  Sparkles,
} from "lucide-react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { KpiCard } from "@/components/kpi-card";
import { StatusBadge, SlaBadge, PriorityBadge } from "@/components/status-badge";
import { ORDERS, TREND, getKpis, ACTIVITY, ESCALATIONS, AI_RECOMMENDATIONS } from "@/lib/demo-data";
import { formatKES, formatNumber } from "@/lib/format";

export const Route = createFileRoute("/app/")({
  head: () => ({ meta: [{ title: "Dashboard — Kim-Fay OrderWatch" }] }),
  component: DashboardPage,
});

const chartAxis = { stroke: "var(--color-muted-foreground)", fontSize: 11 } as const;
const tooltipStyle = {
  background: "var(--color-popover)",
  border: "1px solid var(--color-border)",
  borderRadius: 6,
  fontSize: 12,
  color: "var(--color-popover-foreground)",
} as const;

function ChartCard({ title, hint, children }: { title: string; hint?: string; children: React.ReactNode }) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
      <div className="mb-3 flex items-center justify-between">
        <h3 className="text-sm font-semibold">{title}</h3>
        {hint && <span className="text-[11px] text-muted-foreground">{hint}</span>}
      </div>
      <div className="h-44">
        <ResponsiveContainer width="100%" height="100%">
          {children as React.ReactElement}
        </ResponsiveContainer>
      </div>
    </div>
  );
}

function DashboardPage() {
  const k = getKpis();
  const outstanding = ORDERS.filter((o) => o.status !== "Matched").slice(0, 6);
  const critical = ORDERS.filter((o) => o.priority === "Critical" && o.status !== "Matched").slice(0, 5);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-2">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">Operations Dashboard</h1>
          <p className="text-sm text-muted-foreground">
            Every Order. Accounted For. — Live across Outlook, Acumatica & AI insights.
          </p>
        </div>
        <div className="text-[11px] text-muted-foreground">
          Last refresh: {new Date().toLocaleTimeString("en-KE", { hour: "2-digit", minute: "2-digit" })}
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-4">
        <KpiCard label="Orders Received Today" value={formatNumber(k.received)} delta={k.receivedDelta} icon={Inbox} />
        <KpiCard label="Orders Captured" value={formatNumber(k.captured)} delta={k.capturedDelta} icon={PackageCheck} />
        <KpiCard label="Capture Rate" value={`${k.captureRate}%`} delta={k.captureRateDelta} deltaSuffix="pts" icon={Percent} />
        <KpiCard label="Revenue At Risk" value={formatKES(k.revenueAtRisk, { compact: true })} icon={AlertOctagon} invertDelta />
        <KpiCard label="Revenue Captured" value={formatKES(k.revenueCaptured, { compact: true })} icon={Wallet} />
        <KpiCard label="Outstanding Orders" value={formatNumber(k.outstanding)} icon={AlertTriangle} />
        <KpiCard label="Critical Orders" value={formatNumber(k.critical)} icon={Flame} />
        <KpiCard label="Avg Order Value" value={formatKES(k.aov, { compact: true })} icon={Receipt} />
      </div>

      {/* Charts */}
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <ChartCard title="Order Volume Trend" hint="Last 14 days">
          <AreaChart data={TREND}>
            <defs>
              <linearGradient id="g1" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--color-chart-1)" stopOpacity={0.5} />
                <stop offset="100%" stopColor="var(--color-chart-1)" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="date" {...chartAxis} />
            <YAxis {...chartAxis} />
            <Tooltip contentStyle={tooltipStyle} />
            <Area type="monotone" dataKey="received" stroke="var(--color-chart-1)" fill="url(#g1)" />
            <Area type="monotone" dataKey="captured" stroke="var(--color-chart-2)" fill="transparent" />
          </AreaChart>
        </ChartCard>

        <ChartCard title="Revenue Trend" hint="KES, captured">
          <BarChart data={TREND}>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="date" {...chartAxis} />
            <YAxis {...chartAxis} tickFormatter={(v) => `${(v / 1_000_000).toFixed(1)}M`} />
            <Tooltip contentStyle={tooltipStyle} formatter={(v: number) => formatKES(v, { compact: true })} />
            <Bar dataKey="revenue" fill="var(--color-chart-1)" radius={[3, 3, 0, 0]} />
          </BarChart>
        </ChartCard>

        <ChartCard title="Capture Rate Trend" hint="%">
          <LineChart data={TREND}>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="date" {...chartAxis} />
            <YAxis {...chartAxis} domain={[80, 100]} />
            <Tooltip contentStyle={tooltipStyle} />
            <Line type="monotone" dataKey="captureRate" stroke="var(--color-chart-2)" strokeWidth={2} dot={false} />
          </LineChart>
        </ChartCard>

        <ChartCard title="SLA Compliance Trend" hint="%">
          <LineChart data={TREND}>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="date" {...chartAxis} />
            <YAxis {...chartAxis} domain={[80, 100]} />
            <Tooltip contentStyle={tooltipStyle} />
            <Line type="monotone" dataKey="sla" stroke="var(--color-chart-3)" strokeWidth={2} dot={false} />
          </LineChart>
        </ChartCard>

        <ChartCard title="Revenue At Risk" hint="KES, daily">
          <AreaChart data={TREND}>
            <defs>
              <linearGradient id="g2" x1="0" y1="0" x2="0" y2="1">
                <stop offset="0%" stopColor="var(--color-chart-4)" stopOpacity={0.6} />
                <stop offset="100%" stopColor="var(--color-chart-4)" stopOpacity={0} />
              </linearGradient>
            </defs>
            <CartesianGrid stroke="var(--color-border)" strokeDasharray="3 3" vertical={false} />
            <XAxis dataKey="date" {...chartAxis} />
            <YAxis {...chartAxis} tickFormatter={(v) => `${(v / 1_000_000).toFixed(1)}M`} />
            <Tooltip contentStyle={tooltipStyle} formatter={(v: number) => formatKES(v, { compact: true })} />
            <Area type="monotone" dataKey="revenueAtRisk" stroke="var(--color-chart-4)" fill="url(#g2)" />
          </AreaChart>
        </ChartCard>

        <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
          <div className="mb-3 flex items-center justify-between">
            <h3 className="text-sm font-semibold flex items-center gap-1.5"><Sparkles className="h-4 w-4 text-primary" /> AI Recommendations</h3>
            <span className="text-[11px] text-muted-foreground">12:00 cycle</span>
          </div>
          <ul className="space-y-3">
            {AI_RECOMMENDATIONS.map((r) => (
              <li key={r.title} className="rounded-md border bg-muted/30 p-3">
                <div className="text-sm font-medium">{r.title}</div>
                <div className="mt-0.5 text-xs text-muted-foreground">{r.rationale}</div>
                <div className="mt-1.5 inline-flex rounded border border-primary/30 bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">
                  {r.impact}
                </div>
              </li>
            ))}
          </ul>
        </div>
      </div>

      {/* Widgets */}
      <div className="grid gap-3 lg:grid-cols-3">
        <div className="rounded-lg border bg-card shadow-[var(--shadow-panel)] lg:col-span-2">
          <div className="flex items-center justify-between border-b px-4 py-2.5">
            <h3 className="text-sm font-semibold">Outstanding Orders</h3>
            <span className="text-[11px] text-muted-foreground">Top {outstanding.length} of {ORDERS.filter(o => o.status !== "Matched").length}</span>
          </div>
          <table className="w-full text-sm">
            <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="px-4 py-2 text-left font-semibold">PO</th>
                <th className="px-4 py-2 text-left font-semibold">Customer</th>
                <th className="px-4 py-2 text-right font-semibold">Value</th>
                <th className="px-4 py-2 text-left font-semibold">Status</th>
                <th className="px-4 py-2 text-left font-semibold">SLA</th>
              </tr>
            </thead>
            <tbody>
              {outstanding.map((o) => (
                <tr key={o.id} className="border-t">
                  <td className="px-4 py-2 font-mono text-xs">{o.poNumber}</td>
                  <td className="px-4 py-2">{o.customer}</td>
                  <td className="px-4 py-2 text-right font-mono tabular-nums">{formatKES(o.orderValue, { compact: true })}</td>
                  <td className="px-4 py-2"><StatusBadge status={o.status} /></td>
                  <td className="px-4 py-2"><SlaBadge status={o.slaStatus} /></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
          <h3 className="mb-3 text-sm font-semibold">Critical Orders</h3>
          <ul className="space-y-2.5">
            {critical.map((o) => (
              <li key={o.id} className="flex items-start justify-between gap-2 border-b pb-2 last:border-0">
                <div className="min-w-0">
                  <div className="truncate text-sm font-medium">{o.customer}</div>
                  <div className="truncate font-mono text-[11px] text-muted-foreground">{o.poNumber}</div>
                </div>
                <div className="text-right">
                  <div className="font-mono text-sm tabular-nums">{formatKES(o.orderValue, { compact: true })}</div>
                  <PriorityBadge priority={o.priority} />
                </div>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <div className="grid gap-3 lg:grid-cols-2">
        <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
          <h3 className="mb-3 text-sm font-semibold">Recent Activity</h3>
          <ul className="space-y-2.5">
            {ACTIVITY.map((a, i) => (
              <li key={i} className="flex gap-3 text-sm">
                <span className="w-12 shrink-0 font-mono text-xs text-muted-foreground">{a.time}</span>
                <span className="text-foreground/90">{a.text}</span>
              </li>
            ))}
          </ul>
        </div>

        <div className="rounded-lg border bg-card p-4 shadow-[var(--shadow-panel)]">
          <h3 className="mb-3 text-sm font-semibold">Recent Escalations</h3>
          <ul className="space-y-2.5">
            {ESCALATIONS.map((e) => (
              <li key={e.id} className="flex items-start justify-between gap-3 border-b pb-2 last:border-0">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 text-sm">
                    <span className="font-mono text-xs text-muted-foreground">{e.id}</span>
                    <span className="font-medium">{e.customer}</span>
                  </div>
                  <div className="text-xs text-muted-foreground">{e.reason} · {e.owner}</div>
                </div>
                <div className="text-right">
                  <div className="font-mono text-sm tabular-nums">{formatKES(e.value, { compact: true })}</div>
                  <div className="font-mono text-[11px] text-muted-foreground">{e.po}</div>
                </div>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  );
}
