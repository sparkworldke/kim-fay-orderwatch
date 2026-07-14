import { createFileRoute, Link } from "@tanstack/react-router";
import { useState, type ReactNode } from "react";
import { CustomerLink, OrderLink } from "@/components/entity-links";
import { ProductListingCell } from "@/components/inventory/ProductListingCell";
import {
  AlertTriangle,
  ArrowRight,
  BarChart3,
  CheckCircle2,
  Clock,
  Factory,
  LineChart,
  MapPin,
  PackageX,
  RefreshCw,
  Target,
  Timer,
  TrendingDown,
  Users,
  Wallet,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Line,
  LineChart as ReLineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { OperationsSyncStatus } from "@/components/operations-sync-status";
import {
  fillRateStatusColor,
  type ContributionRow,
  type BusinessOptimizationData,
  type DeliverySlaDelayedOrder,
  type DeliverySlaZoneImpact,
  type ExecutiveAlert,
  useBusinessOptimization,
} from "@/hooks/useOperations";
import { shippingZoneLabel } from "@/hooks/useShippingZones";
import { useMaskedKESFormatter } from "@/components/MaskedCurrency";
import { formatNumber } from "@/lib/format";
import { cn } from "@/lib/utils";

export const Route = createFileRoute("/app/business-optimization")({
  head: () => ({ meta: [{ title: "Business Optimization - Kim-Fay OrderWatch" }] }),
  component: BusinessOptimizationPage,
});

const RANK_COLOR = "var(--color-chart-1)";
const SHORTFALL_COLOR = "var(--color-destructive)";
const BACKORDER_REASON_COLOR = "var(--color-destructive)";
const FILL_REASON_COLOR = "var(--color-chart-3)";

const REASON_LABELS: Record<string, string> = {
  unassigned: "Unassigned",
  inventory_shortage: "Out of stock",
  supplier_delay: "Supplier delay",
  production_issue: "Production issue",
  logistics_disruption: "Logistics disruption",
  quality_hold: "Quality hold",
  forecast_gap: "Forecast gap",
  customer_change: "Customer change",
  system_allocation: "System allocation",
};

function reasonLabel(code: string | null | undefined) {
  if (!code) return "Unassigned";
  return REASON_LABELS[code] ?? code.replace(/_/g, " ");
}

const axisStyle = { fill: "var(--color-muted-foreground)", fontSize: 11 } as const;

const STATUS_ORDER = ["healthy", "at_risk", "critical", "na"] as const;
const STATUS_META: Record<
  (typeof STATUS_ORDER)[number],
  { label: string; barClass: string; textClass: string }
> = {
  healthy: { label: "Healthy (>=95%)", barClass: "bg-emerald-500", textClass: "text-emerald-600" },
  at_risk: { label: "At risk (80-94%)", barClass: "bg-amber-500", textClass: "text-amber-600" },
  critical: { label: "Critical (<80%)", barClass: "bg-red-500", textClass: "text-red-600" },
  na: { label: "No data", barClass: "bg-muted-foreground/30", textClass: "text-muted-foreground" },
};

function startOfMonth() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-01`;
}

function today() {
  return new Date().toISOString().slice(0, 10);
}

type RankedRow = { key: string; name: string; value: number; color?: string };
type ReasonRow = {
  reason_code: string;
  line_count: number;
  revenue_at_risk: number;
  total_open_qty?: number;
  total_demand_qty?: number;
};
type DecisionRow = {
  priority: "Critical" | "High" | "Watch";
  driver: string;
  impact: number;
  affected: string;
  action: string;
  href: string;
};

function topNWithOther<T>(
  rows: T[],
  n: number,
  keyFn: (r: T) => string,
  nameFn: (r: T) => string,
  valueFn: (r: T) => number,
): RankedRow[] {
  const sorted = [...rows].sort((a, b) => valueFn(b) - valueFn(a));
  const top = sorted
    .slice(0, n)
    .map((r) => ({ key: keyFn(r), name: nameFn(r), value: valueFn(r) }));
  const rest = sorted.slice(n).reduce((sum, r) => sum + valueFn(r), 0);
  if (rest > 0) top.push({ key: "__other", name: "Other reasons", value: rest });
  return top;
}

function BusinessOptimizationPage() {
  const kes = useMaskedKESFormatter();
  const [dateFrom, setDateFrom] = useState(startOfMonth());
  const [dateTo, setDateTo] = useState(today());
  const [shippingZoneId, setShippingZoneId] = useState("all");
  const [regionFilter, setRegionFilter] = useState("all");
  const selectedZone = shippingZoneId !== "all" ? shippingZoneId : undefined;
  const { data, isLoading, isFetching, refetch } = useBusinessOptimization(
    dateFrom,
    dateTo,
    selectedZone,
    regionFilter,
  );

  const backordersByReason = data?.charts.backorders_by_reason ?? [];
  const fillRateUnfilledReasons = data?.charts.fill_rate_unfilled_reasons ?? [];
  const zeroQtyOnShipments = data?.revenue_bleeding.zero_qty_on_shipments_lines ?? 0;
  const backordersWithoutReason = data?.revenue_bleeding.backorders_without_reason ?? 0;
  const openBackorderLines = data?.revenue_bleeding.open_backorder_lines ?? 0;
  const reasonCoveragePct = openBackorderLines > 0
    ? Math.max(0, Math.round(((openBackorderLines - backordersWithoutReason) / openBackorderLines) * 1000) / 10)
    : 100;
  const topBackorderReason = backordersByReason[0];
  const topFillReason = fillRateUnfilledReasons[0];
  const selectedZoneLabel = selectedZone
    ? (() => {
        const zone = data?.filters?.shipping_zones.find((item) => item.acumatica_id === selectedZone);
        return zone ? shippingZoneLabel(zone) : selectedZone;
      })()
    : "All regions";

  const customerRows: RankedRow[] = (data?.charts.backorders_by_customer ?? [])
    .slice(0, 8)
    .map((c) => ({
      key: c.customer_acumatica_id,
      name: (c.customer_name ?? c.customer_acumatica_id).slice(0, 22),
      value: c.revenue_at_risk,
    }));

  const productRows: RankedRow[] = (data?.product_focus.top_by_revenue ?? [])
    .slice(0, 8)
    .map((p) => ({
      key: p.inventory_id,
      name: (p.product_name ?? p.inventory_id).slice(0, 26),
      value: p.revenue_at_risk,
      color: p.stock_shortfall ? SHORTFALL_COLOR : RANK_COLOR,
    }));

  const backorderReasonRows = topNWithOther(
    backordersByReason,
    6,
    (r) => r.reason_code,
    (r) => reasonLabel(r.reason_code),
    (r) => r.revenue_at_risk,
  );
  const fillReasonRows = topNWithOther(
    fillRateUnfilledReasons,
    6,
    (r) => r.reason_code,
    (r) => reasonLabel(r.reason_code),
    (r) => r.revenue_at_risk,
  );
  const decisionRows: DecisionRow[] = data ? [
    ...(data.revenue_bleeding.combined_exposure > 0 ? [{
      priority: "Critical" as const,
      driver: "Revenue exposure",
      impact: data.revenue_bleeding.combined_exposure,
      affected: `${formatNumber(data.revenue_bleeding.open_backorder_lines)} backorder lines, ${formatNumber(data.revenue_bleeding.orders_below_80_pct)} critical fill orders`,
      action: "Review exposure split",
      href: "/app/backorders",
    }] : []),
    ...(topBackorderReason ? [{
      priority: topBackorderReason.reason_code === "unassigned" ? "High" as const : "Critical" as const,
      driver: `Backorders: ${reasonLabel(topBackorderReason.reason_code)}`,
      impact: topBackorderReason.revenue_at_risk,
      affected: `${formatNumber(topBackorderReason.line_count)} lines, ${formatNumber(topBackorderReason.total_open_qty ?? 0)} open qty`,
      action: "Assign owner and unblock stock",
      href: "/app/backorders",
    }] : []),
    ...(topFillReason ? [{
      priority: "High" as const,
      driver: `Low fill rate: ${reasonLabel(topFillReason.reason_code)}`,
      impact: topFillReason.revenue_at_risk,
      affected: `${formatNumber(topFillReason.line_count)} zero-shipment lines`,
      action: "Inspect fill-rate orders",
      href: selectedZone ? `/app/fill-rate?shipping_zone_id=${encodeURIComponent(selectedZone)}` : "/app/fill-rate",
    }] : []),
    ...(data.product_focus.shortfall_count > 0 ? [{
      priority: "High" as const,
      driver: "Stock shortfall SKUs",
      impact: data.product_focus.stock_shortfall_skus.reduce((sum, sku) => sum + sku.revenue_at_risk, 0),
      affected: `${formatNumber(data.product_focus.shortfall_count)} SKUs below open demand`,
      action: "Sync stock and plan replenishment",
      href: "/app/inventory",
    }] : []),
    ...(backordersWithoutReason > 0 ? [{
      priority: "Watch" as const,
      driver: "Missing backorder reasons",
      impact: 0,
      affected: `${formatNumber(backordersWithoutReason)} lines need classification`,
      action: "Clean reason codes",
      href: "/app/backorders",
    }] : []),
  ].slice(0, 6) : [];

  return (
    <div className="flex flex-col gap-6 p-4 sm:p-6">
      <div className="rounded-lg border bg-card p-4 shadow-sm">
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="flex items-center gap-2 text-2xl font-semibold tracking-tight">
              <Target className="h-6 w-6 text-primary" />
              Business Optimization
            </h1>
            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">
              Executive command center for revenue exposure, backorder reasons, fill-rate loss,
              and the next operating decision.
            </p>
          </div>
          <div className="grid w-full gap-2 sm:w-auto sm:grid-cols-2 lg:grid-cols-5">
            <div>
              <Label htmlFor="bo-from" className="text-xs">From</Label>
              <Input
                id="bo-from"
                type="date"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                className="h-9"
              />
            </div>
            <div>
              <Label htmlFor="bo-to" className="text-xs">To</Label>
              <Input
                id="bo-to"
                type="date"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                className="h-9"
              />
            </div>
            <div>
              <Label className="text-xs">Region group</Label>
              <Select value={regionFilter} onValueChange={(v) => { setRegionFilter(v); setShippingZoneId("all"); }}>
                <SelectTrigger className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(data?.filters?.region_options ?? [
                    { value: "all", label: "All regions" },
                    { value: "nairobi", label: "Nairobi" },
                    { value: "coast", label: "Coast / MSA" },
                    { value: "other", label: "Other regions" },
                    { value: "unmapped", label: "Unmapped" },
                  ]).map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div>
              <Label className="text-xs">Shipping zone</Label>
              <Select value={shippingZoneId} onValueChange={setShippingZoneId}>
                <SelectTrigger className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All zones</SelectItem>
                  {(data?.filters?.shipping_zones ?? []).map((zone) => (
                    <SelectItem key={zone.acumatica_id} value={zone.acumatica_id}>
                      {shippingZoneLabel(zone)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-end">
              <Button variant="outline" size="sm" className="h-9 w-full" onClick={() => refetch()}>
                <RefreshCw className={cn("mr-2 h-4 w-4", isFetching && "animate-spin")} />
                Refresh
              </Button>
            </div>
          </div>
        </div>
        <div className="mt-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
          <Badge variant="outline" className="gap-1.5">
            <MapPin className="h-3 w-3" />
            {selectedZoneLabel}
          </Badge>
          <span>{dateFrom} to {dateTo}</span>
        </div>
      </div>

      <OperationsSyncStatus />

      {isLoading && <PageSkeleton />}

      {!isLoading && data && (
        <>
          <ExecutiveAlerts alerts={data.executive_alerts} />

          {data.zone_guardrails && data.zone_guardrails.unmapped_with_orders_in_period > 0 && (
            <div className="rounded-lg border border-amber-300/60 bg-amber-50/40 px-4 py-3 text-sm dark:bg-amber-950/20">
              <span className="font-medium">Zone mapping guardrail:</span>{" "}
              {formatNumber(data.zone_guardrails.unmapped_with_orders_in_period)} orders in this period belong to{" "}
              {formatNumber(data.zone_guardrails.unmapped_customer_count)} customers without a shipping zone.
              {" "}
              <Link to="/app/customers" className="font-medium text-primary hover:underline">
                Review customers
              </Link>
            </div>
          )}

          {data.delivery_sla && (
            <DeliverySlaSection
              deliverySla={data.delivery_sla}
              dateFrom={dateFrom}
              dateTo={dateTo}
              selectedZone={selectedZone}
            />
          )}

          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
            <ExposureHero
              combined={data.revenue_bleeding.combined_exposure}
              backorder={data.revenue_bleeding.backorder_revenue_at_risk}
              fillRate={data.revenue_bleeding.fill_rate_not_shipped}
            />
            <Kpi
              label="Backorder exposure"
              value={kes(data.revenue_bleeding.backorder_revenue_at_risk, { compact: true })}
              icon={PackageX}
              sub={`${formatNumber(data.revenue_bleeding.open_backorder_lines)} open lines`}
              warn={data.revenue_bleeding.backorder_revenue_at_risk > 0}
            />
            <Kpi
              label="Fill-rate gap"
              value={kes(data.revenue_bleeding.fill_rate_not_shipped, { compact: true })}
              icon={LineChart}
              sub={`${formatNumber(data.revenue_bleeding.orders_below_80_pct)} orders below 80%`}
              warn={data.revenue_bleeding.orders_below_80_pct > 0}
            />
            <Kpi
              label="SKUs with stock shortfall"
              value={data.product_focus.shortfall_count}
              icon={Factory}
              sub="on-hand below open backorder qty"
              warn={data.product_focus.shortfall_count > 0}
            />
            <Kpi
              label="Reason coverage"
              value={`${reasonCoveragePct}%`}
              icon={CheckCircle2}
              sub={`${formatNumber(backordersWithoutReason)} unassigned backorder lines`}
              warn={backordersWithoutReason > 0}
            />
          </div>

          <div className="grid gap-4 xl:grid-cols-[1fr_1fr_1.2fr]">
            <ReasonImpactCard
              title="Reasons for backorder"
              icon={PackageX}
              rows={backordersByReason}
              empty="No backorder reason data in this scope"
              colorClass="bg-red-500"
              footer={backordersWithoutReason > 0
                ? `${formatNumber(backordersWithoutReason)} lines have no reason code assigned`
                : "All visible backorder lines have a reason code"}
            />
            <ReasonImpactCard
              title="Reasons for low fill rate"
              icon={LineChart}
              rows={fillRateUnfilledReasons}
              empty="No low-fill-rate reason data in this scope"
              colorClass="bg-amber-500"
              footer={`${formatNumber(zeroQtyOnShipments)} lines have demand with QtyOnShipments = 0`}
            />
            <ExecutiveDecisionTable rows={decisionRows} />
          </div>

          <div className="grid gap-3 sm:grid-cols-3">
            <ActionCard
              href="/app/backorders"
              icon={PackageX}
              title="Review backorders"
              stat={`${formatNumber(data.revenue_bleeding.open_backorder_lines)} open lines - ${kes(data.revenue_bleeding.backorder_revenue_at_risk, { compact: true })} at risk`}
            />
            <ActionCard
              href={selectedZone ? `/app/fill-rate?shipping_zone_id=${encodeURIComponent(selectedZone)}` : "/app/fill-rate"}
              icon={LineChart}
              title="Review fill rate"
              stat={`${data.revenue_bleeding.orders_below_80_pct} orders below 80% - ${kes(data.revenue_bleeding.fill_rate_not_shipped, { compact: true })} unshipped`}
            />
            <ActionCard
              href="/app/inventory"
              icon={Factory}
              title="Sync stock & inventory"
              stat={`${data.product_focus.shortfall_count} SKUs below open backorder qty`}
            />
          </div>

          <Section title="Where the risk is" icon={Users}>
            <div className="grid gap-6 lg:grid-cols-2">
              <ChartCard title="Customer focus - backorder revenue at risk" icon={Users}>
                {customerRows.length === 0 ? (
                  <EmptyChart message="No backorder revenue data - sync backorders first" />
                ) : (
                  <RankedBar
                    rows={customerRows}
                    defaultColor={RANK_COLOR}
                    valueFormatter={(v) => kes(v, { compact: true })}
                  />
                )}
                {data.customer_focus.top_customer_concentration_pct != null && (
                  <p className="mt-2 text-xs text-muted-foreground">
                    Top account concentration: {data.customer_focus.top_customer_concentration_pct}%
                    of backorder risk
                  </p>
                )}
              </ChartCard>

              <ChartCard title="Product focus - revenue at risk by SKU" icon={Target}>
                {productRows.length === 0 ? (
                  <EmptyChart message="No product revenue data - sync backorders first" />
                ) : (
                  <>
                    <RankedBar
                      rows={productRows}
                      defaultColor={RANK_COLOR}
                      valueFormatter={(v) => kes(v, { compact: true })}
                    />
                    <ChartLegend
                      items={[
                        { label: "Stock shortfall", color: SHORTFALL_COLOR },
                        { label: "Sufficient stock", color: RANK_COLOR },
                      ]}
                    />
                  </>
                )}
              </ChartCard>
            </div>
          </Section>

          <Section title="Why it's happening" icon={TrendingDown}>
            <div className="grid gap-6 lg:grid-cols-2">
              <ChartCard title="Backorder value by reason" icon={PackageX}>
                {backorderReasonRows.length === 0 ? (
                  <EmptyChart message="No backorder reason data - sync backorders first" />
                ) : (
                  <RankedBar
                    rows={backorderReasonRows}
                    defaultColor={BACKORDER_REASON_COLOR}
                    valueFormatter={(v) => kes(v, { compact: true })}
                  />
                )}
              </ChartCard>

              <ChartCard title="Fill rate - zero on shipments by reason" icon={LineChart}>
                {fillReasonRows.length === 0 ? (
                  <EmptyChart message="No fill-rate shortfall data - sync fill rate first" />
                ) : (
                  <RankedBar
                    rows={fillReasonRows}
                    defaultColor={FILL_REASON_COLOR}
                    valueFormatter={(v) => kes(v, { compact: true })}
                  />
                )}
              </ChartCard>
            </div>

            <ChartCard title="Fill rate distribution" icon={BarChart3}>
              <FillRateMeter rows={data.charts.fill_rate_by_status} />
            </ChartCard>
          </Section>

          {data.excel_summary && (
            <ExecutiveSummaryGrid
              fillRate={data.excel_summary.fill_rate}
              backorders={data.excel_summary.backorders}
            />
          )}

          <Section title="Production forecasting - stockout risk" icon={Factory}>
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
                    <tr>
                      <td colSpan={5} className="px-4 py-6 text-center text-muted-foreground">
                        No at-risk forecasts - sync inventory for run-rate history
                      </td>
                    </tr>
                  )}
                  {data.production_forecast.at_risk_items.map((item) => (
                    <tr key={item.inventory_id} className="border-b">
                      <td className="px-4 py-2">
                        <ProductListingCell product={item} />
                      </td>
                      <td className="px-4 py-2 text-right font-mono">
                        {formatNumber(item.qty_on_hand)}
                      </td>
                      <td className="px-4 py-2 text-right font-mono">
                        {item.daily_run_rate?.toFixed(2) ?? "-"}
                      </td>
                      <td className="px-4 py-2 text-right font-mono">
                        {item.days_until_stockout ?? "-"}
                      </td>
                      <td className="px-4 py-2">
                        <Badge
                          variant={fillRateStatusColor(
                            item.prediction_status === "healthy"
                              ? "healthy"
                              : item.prediction_status,
                          )}
                        >
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
              title="Customer focus - fill rate risk"
              empty="No critical fill-rate customers in range"
              rows={data.customer_focus.top_by_fill_rate_risk.map((c) => ({
                key: c.customer_acumatica_id,
                primary: (
                  <CustomerLink customerId={c.customer_acumatica_id} customerName={c.customer_name}>
                    {c.customer_name ?? c.customer_acumatica_id}
                  </CustomerLink>
                ),
                secondary: `${c.critical_orders} critical - ${c.at_risk_orders} at risk orders`,
                value: kes(c.revenue_not_shipped),
              }))}
            />
            <InsightList
              title="Products - stock shortfall on backorders"
              empty="No stock shortfalls detected"
              rows={data.product_focus.stock_shortfall_skus.map((p) => ({
                key: p.inventory_id,
                primary: <ProductListingCell product={p} />,
                secondary: `Open ${formatNumber(p.total_open_qty)} - On hand ${p.qty_on_hand != null ? formatNumber(p.qty_on_hand) : "?"}`,
                value: kes(p.revenue_at_risk),
              }))}
            />
          </div>
        </>
      )}
    </div>
  );
}

function ReasonImpactCard({
  title,
  icon: Icon,
  rows,
  empty,
  colorClass,
  footer,
}: {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  rows: ReasonRow[];
  empty: string;
  colorClass: string;
  footer: string;
}) {
  const kes = useMaskedKESFormatter();
  const total = rows.reduce((sum, row) => sum + Number(row.revenue_at_risk ?? 0), 0);
  const topRows = rows.slice(0, 5);

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="flex items-center justify-between gap-3">
        <h3 className="flex items-center gap-2 text-sm font-semibold">
          <Icon className="h-4 w-4 text-muted-foreground" />
          {title}
        </h3>
        <Badge variant="outline">{kes(total, { compact: true })}</Badge>
      </div>
      <div className="mt-4 space-y-3">
        {topRows.length === 0 && <p className="py-8 text-center text-xs text-muted-foreground">{empty}</p>}
        {topRows.map((row) => {
          const pct = total > 0 ? Math.round((Number(row.revenue_at_risk) / total) * 1000) / 10 : 0;
          return (
            <div key={row.reason_code} className="space-y-1.5">
              <div className="flex items-center justify-between gap-3 text-xs">
                <span className="min-w-0 truncate font-medium capitalize">{reasonLabel(row.reason_code)}</span>
                <span className="shrink-0 font-mono">{kes(row.revenue_at_risk, { compact: true })}</span>
              </div>
              <div className="h-2 rounded-full bg-muted">
                <div className={cn("h-2 rounded-full", colorClass)} style={{ width: `${Math.min(pct, 100)}%` }} />
              </div>
              <div className="flex justify-between text-[11px] text-muted-foreground">
                <span>{formatNumber(row.line_count)} lines</span>
                <span>{pct}% contribution</span>
              </div>
            </div>
          );
        })}
      </div>
      <p className="mt-4 rounded-md bg-muted/60 px-3 py-2 text-xs text-muted-foreground">{footer}</p>
    </div>
  );
}

function ExecutiveDecisionTable({ rows }: { rows: DecisionRow[] }) {
  const kes = useMaskedKESFormatter();

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <div className="mb-3 flex items-center justify-between gap-3">
        <h3 className="flex items-center gap-2 text-sm font-semibold">
          <Target className="h-4 w-4 text-muted-foreground" />
          Executive decision queue
        </h3>
        <Badge variant="outline">{rows.length} actions</Badge>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full min-w-[620px] text-sm">
          <thead>
            <tr className="border-b bg-muted/40 text-left">
              <th className="px-3 py-2 font-medium">Priority</th>
              <th className="px-3 py-2 font-medium">Driver</th>
              <th className="px-3 py-2 font-medium text-right">Impact</th>
              <th className="px-3 py-2 font-medium">Affected</th>
              <th className="px-3 py-2 font-medium">Action</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td colSpan={5} className="px-3 py-8 text-center text-muted-foreground">
                  No executive actions for this scope.
                </td>
              </tr>
            )}
            {rows.map((row, index) => (
              <tr key={`${row.driver}-${index}`} className="border-b">
                <td className="px-3 py-2">
                  <Badge variant={row.priority === "Critical" ? "destructive" : row.priority === "High" ? "secondary" : "outline"}>
                    {row.priority}
                  </Badge>
                </td>
                <td className="px-3 py-2 font-medium">{row.driver}</td>
                <td className="px-3 py-2 text-right font-mono">
                  {row.impact > 0 ? kes(row.impact, { compact: true }) : "-"}
                </td>
                <td className="px-3 py-2 text-xs text-muted-foreground">{row.affected}</td>
                <td className="px-3 py-2">
                  <a className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline" href={row.href}>
                    {row.action}
                    <ArrowRight className="h-3 w-3" />
                  </a>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function ExposureHero({
  combined,
  backorder,
  fillRate,
}: {
  combined: number;
  backorder: number;
  fillRate: number;
}) {
  const kes = useMaskedKESFormatter();
  const total = backorder + fillRate || 1;
  const backorderPct = (backorder / total) * 100;
  const fillPct = 100 - backorderPct;

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm sm:col-span-2 2xl:col-span-2">
      <div className="flex items-center gap-2 text-xs text-muted-foreground">
        <Wallet className="h-3.5 w-3.5" />
        Combined revenue exposure
      </div>
      <p className="mt-1 text-3xl font-semibold text-red-600">{kes(combined)}</p>
      <div className="mt-4 flex h-3 w-full overflow-hidden rounded-full bg-muted">
        <div className="h-full bg-red-500" style={{ width: `${backorderPct}%` }} />
        <div className="h-full bg-amber-500" style={{ width: `${fillPct}%` }} />
      </div>
      <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
        <span className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-red-500" />
          Backorders {kes(backorder, { compact: true })}
        </span>
        <span className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full bg-amber-500" />
          Fill rate gap {kes(fillRate, { compact: true })}
        </span>
      </div>
    </div>
  );
}

function ActionCard({
  href,
  icon: Icon,
  title,
  stat,
}: {
  href: string;
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  stat: string;
}) {
  return (
    <a
      href={href}
      className="group flex items-center justify-between gap-3 rounded-lg border bg-card p-4 shadow-sm transition-colors hover:border-primary/50 hover:bg-accent/40"
    >
      <div className="flex min-w-0 items-center gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
          <Icon className="h-4 w-4" />
        </div>
        <div className="min-w-0">
          <p className="text-sm font-medium">{title}</p>
          <p className="truncate text-xs text-muted-foreground">{stat}</p>
        </div>
      </div>
      <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:text-primary" />
    </a>
  );
}

function RankedBar({
  rows,
  defaultColor,
  valueFormatter,
}: {
  rows: RankedRow[];
  defaultColor: string;
  valueFormatter: (v: number) => string;
}) {
  const height = Math.max(rows.length * 34, 100);
  return (
    <ResponsiveContainer width="100%" height={height}>
      <BarChart data={rows} layout="vertical" margin={{ left: 8, right: 24, top: 4, bottom: 4 }}>
        <CartesianGrid strokeDasharray="3 3" horizontal={false} className="stroke-muted" />
        <XAxis
          type="number"
          tick={axisStyle}
          tickFormatter={valueFormatter}
          axisLine={false}
          tickLine={false}
        />
        <YAxis
          type="category"
          dataKey="name"
          width={140}
          tick={axisStyle}
          axisLine={false}
          tickLine={false}
        />
        <Tooltip
          formatter={(v: number) => valueFormatter(v)}
          cursor={{ fill: "var(--color-muted)" }}
        />
        <Bar dataKey="value" radius={[0, 4, 4, 0]} maxBarSize={20}>
          {rows.map((r) => (
            <Cell key={r.key} fill={r.color ?? defaultColor} />
          ))}
        </Bar>
      </BarChart>
    </ResponsiveContainer>
  );
}

function ChartLegend({ items }: { items: Array<{ label: string; color: string }> }) {
  return (
    <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
      {items.map((item) => (
        <span key={item.label} className="flex items-center gap-1.5">
          <span className="h-2 w-2 rounded-full" style={{ backgroundColor: item.color }} />
          {item.label}
        </span>
      ))}
    </div>
  );
}

function EmptyChart({ message }: { message: string }) {
  return <p className="py-10 text-center text-xs text-muted-foreground">{message}</p>;
}

function FillRateMeter({ rows }: { rows: Array<{ status: string; count: number }> }) {
  const byStatus = new Map(rows.map((r) => [r.status, r.count]));
  const total = rows.reduce((sum, r) => sum + r.count, 0);

  if (total === 0) {
    return <EmptyChart message="No fill-rate data in range" />;
  }

  return (
    <div>
      <div className="flex h-4 w-full overflow-hidden rounded-full bg-muted">
        {STATUS_ORDER.map((s) => {
          const count = byStatus.get(s) ?? 0;
          if (count === 0) return null;
          const pct = (count / total) * 100;
          return (
            <div
              key={s}
              className={cn("h-full", STATUS_META[s].barClass)}
              style={{ width: `${pct}%` }}
              title={`${STATUS_META[s].label}: ${count}`}
            />
          );
        })}
      </div>
      <ul className="mt-3 grid gap-2 sm:grid-cols-2">
        {STATUS_ORDER.filter((s) => (byStatus.get(s) ?? 0) > 0).map((s) => (
          <li key={s} className="flex items-center justify-between text-sm">
            <span className="flex items-center gap-2">
              <span className={cn("h-2.5 w-2.5 rounded-full", STATUS_META[s].barClass)} />
              {STATUS_META[s].label}
            </span>
            <span className={cn("font-mono font-medium", STATUS_META[s].textClass)}>
              {formatNumber(byStatus.get(s) ?? 0)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ExecutiveSummaryGrid({
  fillRate,
  backorders,
}: {
  fillRate: {
    totals: {
      actual_qty: number;
      ordered_qty: number;
      undershipped_qty: number;
      undershipped_value: number;
      fill_rate_pct: number | null;
    };
    by_customer_group: ContributionRow[];
  };
  backorders: {
    totals: {
      back_order_qty: number;
      back_order_value: number;
    };
    by_customer_group: ContributionRow[];
  };
}) {
  const kes = useMaskedKESFormatter();

  return (
    <Section title="Detailed source numbers" icon={BarChart3}>
      <div className="grid gap-4 xl:grid-cols-2">
        <section className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="mb-3 flex items-center justify-between gap-3">
            <h3 className="text-sm font-semibold">Fill rate summary format</h3>
            <Badge variant="outline">API source</Badge>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <ToneMetric label="Actual Qty" value={formatNumber(fillRate.totals.actual_qty)} tone="green" />
            <ToneMetric label="Ordered Qty" value={formatNumber(fillRate.totals.ordered_qty)} tone="blue" />
            <ToneMetric label="Undershipped Value" value={kes(fillRate.totals.undershipped_value)} tone="red" />
            <ToneMetric
              label="Fill Rate"
              value={fillRate.totals.fill_rate_pct != null ? `${fillRate.totals.fill_rate_pct}%` : "N/A"}
              tone="cyan"
            />
          </div>
          <MiniContribution rows={fillRate.by_customer_group} labelKey="customer_group" valueKey="undershipped_value" />
        </section>

        <section className="rounded-lg border bg-card p-4 shadow-sm">
          <div className="mb-3 flex items-center justify-between gap-3">
            <h3 className="text-sm font-semibold">Backorder summary format</h3>
            <Badge variant="outline">API source</Badge>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <ToneMetric label="Back Order Qty" value={formatNumber(backorders.totals.back_order_qty)} tone="amber" />
            <ToneMetric label="Back Ordered Value" value={kes(backorders.totals.back_order_value)} tone="red" />
          </div>
          <MiniContribution rows={backorders.by_customer_group} labelKey="customer_group" valueKey="back_order_value" />
        </section>
      </div>
    </Section>
  );
}

function ToneMetric({
  label,
  value,
  tone,
}: {
  label: string;
  value: string;
  tone: "blue" | "green" | "amber" | "red" | "cyan";
}) {
  const toneClass = {
    blue: "border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300",
    green: "border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300",
    amber: "border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300",
    red: "border-red-200 bg-red-50 text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300",
    cyan: "border-cyan-200 bg-cyan-50 text-cyan-700 dark:border-cyan-900/50 dark:bg-cyan-950/30 dark:text-cyan-300",
  }[tone];

  return (
    <div className={`rounded-md border p-3 ${toneClass}`}>
      <p className="text-xs font-medium opacity-80">{label}</p>
      <p className="mt-1 text-lg font-semibold">{value}</p>
    </div>
  );
}

function MiniContribution({
  rows,
  labelKey,
  valueKey,
}: {
  rows: ContributionRow[];
  labelKey: string;
  valueKey: string;
}) {
  const kes = useMaskedKESFormatter();
  const topRows = rows.slice(0, 4);

  return (
    <div className="mt-4 space-y-2">
      <p className="text-xs font-medium text-muted-foreground">Customer group contribution</p>
      {topRows.length === 0 && (
        <p className="text-xs text-muted-foreground">No contribution data yet.</p>
      )}
      {topRows.map((row, index) => (
        <div key={`${String(row[labelKey])}-${index}`} className="space-y-1">
          <div className="flex items-center justify-between gap-3 text-xs">
            <span className="truncate font-medium">{String(row[labelKey] ?? "Unassigned")}</span>
            <span className="shrink-0 font-mono">{kes(Number(row[valueKey] ?? 0))}</span>
          </div>
          <div className="h-1.5 rounded-full bg-muted">
            <div
              className="h-1.5 rounded-full bg-cyan-500"
              style={{ width: `${Math.min(Number(row.contribution_pct ?? 0), 100)}%` }}
            />
          </div>
        </div>
      ))}
    </div>
  );
}

function ExecutiveAlerts({ alerts }: { alerts: ExecutiveAlert[] }) {
  if (alerts.length === 0) return null;

  const ordered = [...alerts].sort((a, b) => {
    const rank = { critical: 0, warning: 1, info: 2 } as const;
    return rank[a.severity] - rank[b.severity];
  });

  const styleFor = (s: ExecutiveAlert["severity"]) =>
    s === "critical"
      ? "border-red-500 bg-red-50 dark:bg-red-950/20"
      : s === "warning"
        ? "border-amber-500 bg-amber-50 dark:bg-amber-950/20"
        : "border-muted-foreground/30 bg-muted/20";

  const labelClassFor = (s: ExecutiveAlert["severity"]) =>
    s === "critical"
      ? "text-red-700 dark:text-red-400"
      : s === "warning"
        ? "text-amber-700 dark:text-amber-400"
        : "text-muted-foreground";

  return (
    <div className="rounded-lg border bg-card p-4 shadow-sm">
      <h2 className="flex items-center gap-2 font-semibold">
        <AlertTriangle className="h-4 w-4 text-amber-500" />
        Executive view - what you may have missed
      </h2>
      <ul className="mt-3 space-y-2">
        {ordered.map((a, i) => (
          <li
            key={i}
            className={cn(
              "flex items-start gap-3 rounded-md border-l-4 px-3 py-2 text-sm",
              styleFor(a.severity),
            )}
          >
            <span
              className={cn(
                "mt-0.5 shrink-0 text-[10px] font-semibold uppercase tracking-wide",
                labelClassFor(a.severity),
              )}
            >
              {a.severity}
            </span>
            <span>{a.message}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

function ChartCard({
  title,
  icon: Icon,
  children,
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
  title,
  icon: Icon,
  children,
}: {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  children: React.ReactNode;
}) {
  return (
    <div className="flex flex-col gap-4">
      <h2 className="flex items-center gap-2 text-lg font-semibold">
        <Icon className="h-5 w-5 text-muted-foreground" />
        {title}
      </h2>
      {children}
    </div>
  );
}

function Kpi({
  label,
  value,
  icon: Icon,
  sub,
  warn,
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
  title,
  empty,
  rows,
}: {
  title: string;
  empty: string;
  rows: Array<{ key: string; primary: ReactNode; secondary: string; value: string }>;
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

function DeliverySlaSection({
  deliverySla,
  dateFrom,
  dateTo,
  selectedZone,
}: {
  deliverySla: NonNullable<BusinessOptimizationData["delivery_sla"]>;
  dateFrom: string;
  dateTo: string;
  selectedZone?: string;
}) {
  const kes = useMaskedKESFormatter();
  const { summary, rules, by_region, most_affected_zones, daily_trend, delayed_orders } = deliverySla;
  const fillRateHref = {
    to: "/app/fill-rate" as const,
    search: {
      date_from: dateFrom,
      date_to: dateTo,
      ...(selectedZone ? { shipping_zone_id: selectedZone } : {}),
      delivery_sla: "breach" as const,
    },
  };

  return (
    <Section title="Time to deliver & delivery SLA" icon={Timer}>
      <div className="mb-4 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
        <Badge variant="outline">
          SLA clock starts at {rules.clock_start_label}
        </Badge>
        <Badge variant="secondary">
          Nairobi & Coast: {rules.metro_sla_hours}h · Other: {rules.regional_warning_hours}–{rules.regional_breach_hours}h
        </Badge>
        <Button variant="outline" size="sm" className="ml-auto h-8" asChild>
          <Link {...fillRateHref}>
            Export delayed orders
          </Link>
        </Button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <Kpi label="Orders tracked" value={formatNumber(summary.total_orders)} icon={Timer} />
        <Kpi
          label="On-time"
          value={summary.on_time_pct != null ? `${summary.on_time_pct}%` : "—"}
          icon={CheckCircle2}
          sub={`${formatNumber(summary.on_time_count)} orders`}
        />
        <Kpi
          label="Delayed"
          value={formatNumber(summary.delayed_count)}
          icon={AlertTriangle}
          sub={summary.delayed_pct != null ? `${summary.delayed_pct}% · ${kes(summary.delayed_value, { compact: true })}` : undefined}
          warn={summary.delayed_count > 0}
        />
        <Kpi
          label="Regional warnings"
          value={formatNumber(summary.warning_count)}
          icon={Clock}
          sub="Between 48–72h for other regions"
          warn={summary.warning_count > 0}
        />
        <Kpi
          label="Avg delivery time"
          value={summary.avg_delivery_hours != null ? `${summary.avg_delivery_hours}h` : "—"}
          icon={LineChart}
          sub={summary.unknown_count > 0 ? `${formatNumber(summary.unknown_count)} missing dates` : "All orders dated"}
        />
      </div>

      <div className="mt-6 grid gap-4 xl:grid-cols-3">
        {by_region.map((region) => (
          <div key={region.region_key} className="rounded-lg border bg-card p-4 shadow-sm">
            <div className="flex items-center justify-between gap-2">
              <h3 className="text-sm font-semibold">{region.label}</h3>
              <Badge variant="outline">{region.sla_hours}h target</Badge>
            </div>
            <div className="mt-3 grid grid-cols-2 gap-3 text-sm">
              <div>
                <p className="text-xs text-muted-foreground">On-time</p>
                <p className="font-semibold">{region.on_time_pct != null ? `${region.on_time_pct}%` : "—"}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Delayed</p>
                <p className={cn("font-semibold", region.delayed_orders > 0 && "text-red-600")}>
                  {region.delayed_pct != null ? `${region.delayed_pct}%` : "—"}
                </p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Delayed value</p>
                <p className="font-medium">{kes(region.delayed_value, { compact: true })}</p>
              </div>
              <div>
                <p className="text-xs text-muted-foreground">Avg hours</p>
                <p className="font-medium">{region.avg_delivery_hours != null ? `${region.avg_delivery_hours}h` : "—"}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-[1.2fr_1fr]">
        <ChartCard title="Most affected zones" icon={MapPin}>
          <MostAffectedZonesTable zones={most_affected_zones} />
        </ChartCard>
        <ChartCard title="Delayed orders trend" icon={LineChart}>
          {daily_trend.length === 0 ? (
            <EmptyChart message="No delivery trend data in this period" />
          ) : (
            <ResponsiveContainer width="100%" height={260}>
              <ReLineChart data={daily_trend}>
                <CartesianGrid strokeDasharray="3 3" stroke="var(--color-border)" />
                <XAxis dataKey="day" tick={axisStyle} />
                <YAxis tick={axisStyle} allowDecimals={false} />
                <Tooltip />
                <Line type="monotone" dataKey="delayed" stroke="var(--color-destructive)" strokeWidth={2} name="Delayed" />
                <Line type="monotone" dataKey="on_time" stroke="var(--color-chart-2)" strokeWidth={2} name="On-time" />
              </ReLineChart>
            </ResponsiveContainer>
          )}
        </ChartCard>
      </div>

      <ChartCard title="Top delayed orders" icon={AlertTriangle}>
        <DelayedOrdersTable orders={delayed_orders} />
      </ChartCard>
    </Section>
  );
}

function MostAffectedZonesTable({ zones }: { zones: DeliverySlaZoneImpact[] }) {
  const kes = useMaskedKESFormatter();

  if (zones.length === 0) {
    return <EmptyChart message="No zone-level SLA data in this scope" />;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs text-muted-foreground">
            <th className="px-2 py-2 font-medium">Zone</th>
            <th className="px-2 py-2 font-medium text-right">Orders</th>
            <th className="px-2 py-2 font-medium text-right">Delayed</th>
            <th className="px-2 py-2 font-medium text-right">%</th>
            <th className="px-2 py-2 font-medium text-right">Value</th>
            <th className="px-2 py-2 font-medium text-right">Avg hrs</th>
            <th className="px-2 py-2 font-medium">Reason</th>
          </tr>
        </thead>
        <tbody>
          {zones.map((zone) => (
            <tr
              key={zone.acumatica_id ?? zone.name}
              className={cn("border-b", zone.alert_triggered && "bg-red-50/50 dark:bg-red-950/10")}
            >
              <td className="px-2 py-2">
                <div className="font-medium">{zone.name}</div>
                <div className="text-xs text-muted-foreground">
                  {zone.acumatica_id ?? "Unmapped"}{zone.region ? ` · ${zone.region}` : ""}
                </div>
              </td>
              <td className="px-2 py-2 text-right tabular-nums">{zone.total_orders}</td>
              <td className="px-2 py-2 text-right tabular-nums">{zone.delayed_orders}</td>
              <td className="px-2 py-2 text-right tabular-nums">{zone.delayed_pct}%</td>
              <td className="px-2 py-2 text-right tabular-nums">{kes(zone.delayed_value, { compact: true })}</td>
              <td className="px-2 py-2 text-right tabular-nums">{zone.avg_delay_hours ?? "—"}</td>
              <td className="px-2 py-2 capitalize text-xs">{reasonLabel(zone.primary_reason)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function DelayedOrdersTable({ orders }: { orders: DeliverySlaDelayedOrder[] }) {
  const kes = useMaskedKESFormatter();

  if (orders.length === 0) {
    return <EmptyChart message="No delayed orders in this period" />;
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead>
          <tr className="border-b text-left text-xs text-muted-foreground">
            <th className="px-2 py-2 font-medium">Order</th>
            <th className="px-2 py-2 font-medium">Customer</th>
            <th className="px-2 py-2 font-medium">Zone</th>
            <th className="px-2 py-2 font-medium text-right">Value</th>
            <th className="px-2 py-2 font-medium text-right">Hours</th>
            <th className="px-2 py-2 font-medium">SLA</th>
          </tr>
        </thead>
        <tbody>
          {orders.map((order) => (
            <tr key={order.order_nbr} className="border-b">
              <td className="px-2 py-2 font-mono text-xs">
                <OrderLink
                  customerId={order.customer_acumatica_id}
                  orderId={order.order_nbr}
                />
              </td>
              <td className="px-2 py-2">
                <CustomerLink
                  customerId={order.customer_acumatica_id}
                  customerName={order.customer_name}
                >
                  {order.customer_name ?? order.customer_acumatica_id ?? "—"}
                </CustomerLink>
              </td>
              <td className="px-2 py-2 text-xs">
                {order.shipping_zone_name ?? order.shipping_zone_id ?? "Unmapped"}
                {order.shipping_zone_region ? ` (${order.shipping_zone_region})` : ""}
              </td>
              <td className="px-2 py-2 text-right tabular-nums">{kes(order.order_value, { compact: true })}</td>
              <td className="px-2 py-2 text-right tabular-nums">{order.delivery_hours ?? "—"}</td>
              <td className="px-2 py-2 text-xs text-muted-foreground">{order.delivery_sla_label}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

function PageSkeleton() {
  return (
    <div className="space-y-4">
      <Skeleton className="h-24 w-full" />
      <div className="grid gap-4 sm:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-20" />
        ))}
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-72" />
        ))}
      </div>
    </div>
  );
}
