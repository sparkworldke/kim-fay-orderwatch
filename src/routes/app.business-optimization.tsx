import { createFileRoute, Link } from "@tanstack/react-router";
import { useState } from "react";
import {
  AlertTriangle, BarChart3, Factory, LineChart, Target, TrendingDown, Users, Wallet,
} from "lucide-react";
import {
  Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Skeleton } from "@/components/ui/skeleton";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import {
  fillRateStatusColor,
  type ExecutiveAlert,
  useBusinessOptimization,
} from "@/hooks/useOperations";
import { formatKES, formatNumber } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/business-optimization")({
  head: () => ({ meta: [{ title: "Business Optimization — Kim-Fay OrderWatch" }] }),
  component: BusinessOptimizationPage,
});

const FILL_STATUS_COLORS: Record<string, string> = {
  healthy: "hsl(var(--chart-2))",
  at_risk: "hsl(var(--chart-4))",
  critical: "hsl(var(--destructive))",
  na: "hsl(var(--muted-foreground))",
};

const axisStyle = { fill: "var(--color-muted-foreground)", fontSize: 11 } as const;

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

function BusinessOptimizationPage() {
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const { data, isLoading, refetch } = useBusinessOptimization(dateFrom, dateTo);

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
            <Target className="h-6 w-6 text-primary" />
            Business Optimization
          </h1>
          <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
            Executive view across backorders, fill rate, inventory run-rate, and revenue exposure —
            what to prioritize for customers, products, and production.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <div>
            <Label htmlFor="bo-from" className="text-xs">From</Label>
            <Input id="bo-from" type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} className="h-9" />
          </div>
          <div>
            <Label htmlFor="bo-to" className="text-xs">To</Label>
            <Input id="bo-to" type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} className="h-9" />
          </div>
          <Button variant="outline" size="sm" className="h-9" onClick={() => refetch()}>Refresh</Button>
        </div>
      </div>

      <OperationsSyncStatus />

      {isLoading && <PageSkeleton />}

      {!isLoading && data && (
        <>
          <ExecutiveAlerts alerts={data.executive_alerts} />

          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Kpi
              label="Combined revenue exposure"
              value={formatKES(data.revenue_bleeding.combined_exposure)}
              icon={Wallet}
              warn={data.revenue_bleeding.combined_exposure > 1_000_000}
            />
            <Kpi
              label="Backorder revenue at risk"
              value={formatKES(data.revenue_bleeding.backorder_revenue_at_risk)}
              icon={TrendingDown}
              sub={`${formatNumber(data.revenue_bleeding.open_backorder_lines)} open lines`}
            />
            <Kpi
              label="Fill rate not shipped"
              value={formatKES(data.revenue_bleeding.fill_rate_not_shipped)}
              icon={LineChart}
              sub={`${data.revenue_bleeding.orders_below_80_pct} orders below 80%`}
            />
            <Kpi
              label="SKUs with stock shortfall"
              value={data.product_focus.shortfall_count}
              icon={Factory}
              warn={data.product_focus.shortfall_count > 0}
            />
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <ChartCard title="Customer focus — backorder revenue at risk" icon={Users}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart
                  data={data.charts.backorders_by_customer.slice(0, 8).map((c) => ({
                    name: (c.customer_name ?? c.customer_acumatica_id).slice(0, 18),
                    value: c.revenue_at_risk,
                  }))}
                  layout="vertical"
                  margin={{ left: 8, right: 16 }}
                >
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis type="number" tick={axisStyle} tickFormatter={(v) => formatKES(v, { compact: true })} />
                  <YAxis type="category" dataKey="name" width={100} tick={axisStyle} />
                  <Tooltip formatter={(v: number) => formatKES(v)} />
                  <Bar dataKey="value" fill="hsl(var(--primary))" radius={[0, 4, 4, 0]} />
                </BarChart>
              </ResponsiveContainer>
              {data.customer_focus.top_customer_concentration_pct != null && (
                <p className="mt-2 text-xs text-muted-foreground">
                  Top account concentration: {data.customer_focus.top_customer_concentration_pct}% of backorder risk
                </p>
              )}
            </ChartCard>

            <ChartCard title="Fill rate distribution" icon={BarChart3}>
              <ResponsiveContainer width="100%" height={260}>
                <PieChart>
                  <Pie
                    data={data.charts.fill_rate_by_status.map((s) => ({
                      name: s.status.replace("_", " "),
                      value: s.count,
                      status: s.status,
                    }))}
                    dataKey="value"
                    nameKey="name"
                    cx="50%"
                    cy="50%"
                    innerRadius={50}
                    outerRadius={90}
                    paddingAngle={2}
                  >
                    {data.charts.fill_rate_by_status.map((s) => (
                      <Cell key={s.status} fill={FILL_STATUS_COLORS[s.status] ?? "hsl(var(--muted))"} />
                    ))}
                  </Pie>
                  <Tooltip />
                  <Legend />
                </PieChart>
              </ResponsiveContainer>
            </ChartCard>

            <ChartCard title="Product focus — revenue at risk by SKU" icon={Target}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={data.product_focus.top_by_revenue.slice(0, 8).map((p) => ({
                  name: (p.product_name ?? p.inventory_id).slice(0, 14),
                  value: p.revenue_at_risk,
                  shortfall: p.stock_shortfall,
                }))}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="name" tick={axisStyle} interval={0} angle={-25} textAnchor="end" height={60} />
                  <YAxis tick={axisStyle} tickFormatter={(v) => formatKES(v, { compact: true })} />
                  <Tooltip formatter={(v: number) => formatKES(v)} />
                  <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                    {data.product_focus.top_by_revenue.slice(0, 8).map((p, i) => (
                      <Cell key={i} fill={p.stock_shortfall ? "hsl(var(--destructive))" : "hsl(var(--chart-1))"} />
                    ))}
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
              <p className="mt-2 text-xs text-muted-foreground">Red bars = on-hand stock below open backorder qty</p>
            </ChartCard>

            <ChartCard title="Revenue bleeding split" icon={Wallet}>
              <ResponsiveContainer width="100%" height={260}>
                <BarChart data={data.charts.revenue_bleeding_split}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis dataKey="label" tick={axisStyle} interval={0} />
                  <YAxis tick={axisStyle} tickFormatter={(v) => formatKES(v, { compact: true })} />
                  <Tooltip formatter={(v: number) => formatKES(v)} />
                  <Bar dataKey="value" fill="hsl(var(--chart-5))" radius={[4, 4, 0, 0]} />
                </BarChart>
              </ResponsiveContainer>
            </ChartCard>
          </div>

          <Section title="Production forecasting — stockout risk" icon={Factory}>
            <div className="overflow-x-auto rounded-lg border">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b bg-muted/40 text-left">
                    <th className="px-4 py-2 font-medium">Product</th>
                    <th className="px-4 py-2 font-medium text-right">On hand</th>
                    <th className="px-4 py-2 font-medium text-right">Run rate / day</th>
                    <th className="px-4 py-2 font-medium text-right">Days left</th>
                    <th className="px-4 py-2 font-medium">Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data.production_forecast.at_risk_items.length === 0 && (
                    <tr><td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">No at-risk forecasts — sync inventory for run-rate history</td></tr>
                  )}
                  {data.production_forecast.at_risk_items.map((item) => (
                    <tr key={item.inventory_id} className="border-b">
                      <td className="px-4 py-2">
                        <div className="font-medium">{item.product_name ?? item.inventory_id}</div>
                        <div className="text-xs font-mono text-muted-foreground">{item.inventory_id}</div>
                      </td>
                      <td className="px-4 py-2 text-right font-mono">{formatNumber(item.qty_on_hand)}</td>
                      <td className="px-4 py-2 text-right font-mono">{item.daily_run_rate?.toFixed(2) ?? "—"}</td>
                      <td className="px-4 py-2 text-right font-mono">{item.days_until_stockout ?? "—"}</td>
                      <td className="px-4 py-2">
                        <Badge variant={fillRateStatusColor(item.prediction_status === "healthy" ? "healthy" : item.prediction_status)}>
                          {item.prediction_status}
                        </Badge>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Section>

          <div className="grid gap-6 lg:grid-cols-2">
            <InsightList
              title="Customer focus — fill rate risk"
              empty="No critical fill-rate customers in range"
              rows={data.customer_focus.top_by_fill_rate_risk.map((c) => ({
                key: c.customer_acumatica_id,
                primary: c.customer_name ?? c.customer_acumatica_id,
                secondary: `${c.critical_orders} critical · ${c.at_risk_orders} at risk orders`,
                value: formatKES(c.revenue_not_shipped),
              }))}
            />
            <InsightList
              title="Products — stock shortfall on backorders"
              empty="No stock shortfalls detected"
              rows={data.product_focus.stock_shortfall_skus.map((p) => ({
                key: p.inventory_id,
                primary: p.product_name ?? p.inventory_id,
                secondary: `Open ${formatNumber(p.total_open_qty)} · On hand ${p.qty_on_hand != null ? formatNumber(p.qty_on_hand) : "?"}`,
                value: formatKES(p.revenue_at_risk),
              }))}
            />
          </div>

          <div className="rounded-lg border bg-muted/20 p-4 text-sm text-muted-foreground">
            <p className="font-medium text-foreground">Quick actions</p>
            <ul className="mt-2 flex flex-wrap gap-3">
              <li><Link to="/app/backorders" className="text-primary hover:underline">Review backorders</Link></li>
              <li><Link to="/app/fill-rate" className="text-primary hover:underline">Review fill rate</Link></li>
              <li><Link to="/app/inventory" className="text-primary hover:underline">Sync stocks / inventory</Link></li>
            </ul>
          </div>
        </>
      )}
    </div>
  );
}

function ExecutiveAlerts({ alerts }: { alerts: ExecutiveAlert[] }) {
  const severityIcon = (s: ExecutiveAlert["severity"]) =>
    s === "critical" ? "destructive" as const : s === "warning" ? "secondary" as const : "outline" as const;

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <h2 className="flex items-center gap-2 font-semibold">
        <AlertTriangle className="h-4 w-4 text-amber-500" />
        Executive view — what you may have missed
      </h2>
      <ul className="mt-3 space-y-2">
        {alerts.map((a, i) => (
          <li key={i} className="flex items-start gap-2 text-sm">
            <Badge variant={severityIcon(a.severity)} className="mt-0.5 shrink-0 capitalize">{a.severity}</Badge>
            <span>{a.message}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ChartCard({
  title, icon: Icon, children,
}: {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
}) {
  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <h3 className="mb-3 flex items-center gap-2 text-sm font-semibold">
        <Icon className="h-4 w-4 text-muted-foreground" />
        {title}
      </h3>
      {children}
    </div>
  );
}

function Section({
  title, icon: Icon, children,
}: {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
}) {
  return (
    <div>
      <h2 className="mb-3 flex items-center gap-2 text-lg font-semibold">
        <Icon className="h-5 w-5 text-muted-foreground" />
        {title}
      </h2>
      {children}
    </div>
  );
}

function Kpi({
  label, value, icon: Icon, sub, warn,
}: {
  label: string;
  value: string | number;
  icon: React.ComponentType<{ className?: string }>;
  sub?: string;
  warn?: boolean;
}) {
  return (
    <div className={cn("rounded-lg border bg-card p-4 shadow-sm", warn && "border-red-300/50")}>
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Icon className="h-3.5 w-3.5" />
        {label}
      </div>
      <p className={cn("mt-1 text-xl font-semibold", warn && "text-red-600")}>{value}</p>
      {sub && <p className="text-xs text-muted-foreground">{sub}</p>}
    </div>
  );
}

function InsightList({
  title, empty, rows,
}: {
  title: string;
  empty: string;
  rows: Array<{ key: string; primary: string; secondary: string; value: string }>;
}) {
  return (
    <div className="rounded-lg border">
      <div className="border-b px-4 py-3 font-medium">{title}</div>
      {rows.length === 0 ? (
        <p className="px-4 py-6 text-center text-sm text-muted-foreground">{empty}</p>
      ) : (
        <ul className="divide-y">
          {rows.map((r) => (
            <li key={r.key} className="flex items-center justify-between gap-4 px-4 py-3 text-sm">
              <div className="min-w-0">
                <div className="truncate font-medium">{r.primary}</div>
                <div className="text-xs text-muted-foreground">{r.secondary}</div>
              </div>
              <span className="shrink-0 font-mono text-xs">{r.value}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function PageSkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-24 w-full" />
      <div className="grid gap-4 sm:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-20" />)}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-72" />)}
      </div>
    </div>
  );
}