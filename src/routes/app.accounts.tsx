import { createFileRoute, Link } from "@tanstack/react-router";
import { useState } from "react";
import {
  AlertTriangle,
  Building2,
  Moon,
  Package,
  RefreshCw,
  ShoppingCart,
  Target,
  TrendingDown,
  TrendingUp,
  Users,
  Wallet,
} from "lucide-react";
import { KpAccountsTable } from "@/components/kp-accounts-table";
import { useKpAccountsByRep } from "@/hooks/useKpAccounts";
import { CustomerLink, OrderLink } from "@/components/entity-links";
import { InfoTip } from "@/components/info-tip";
import { MaskedKES } from "@/components/MaskedCurrency";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  usePortfolioBackorders,
  usePortfolioItemsNotOrdered,
  usePortfolioOrders,
  usePortfolioSummary,
  type PortfolioCompareMode,
  type PortfolioMode,
} from "@/hooks/useSalesPortfolio";
import { cn } from "@/lib/utils";

const MONTH_NAMES = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];

export const Route = createFileRoute("/app/accounts")({
  head: () => ({ meta: [{ title: "My Portfolio - Kim-Fay Sight" }] }),
  component: PortfolioDashboardPage,
});

function PortfolioDashboardPage() {
  const [mode, setMode] = useState<PortfolioMode>("auto");
  const [tab, setTab] = useState("overview");
  const [ordersPage, setOrdersPage] = useState(1);
  const [boPage, setBoPage] = useState(1);

  const today = new Date();
  const currentYear = today.getFullYear();
  const currentMonth = today.getMonth() + 1;
  const [year, setYear] = useState(currentYear);
  const [month, setMonth] = useState(currentMonth);
  const [compare, setCompare] = useState<PortfolioCompareMode>("mom");

  const isCurrentPeriod = year === currentYear && month === currentMonth;
  const periodParam = isCurrentPeriod ? undefined : `${year}-${String(month).padStart(2, "0")}`;
  const yearOptions = [currentYear - 1, currentYear, currentYear + 1];

  const summary = usePortfolioSummary(mode, periodParam, compare);
  const orders = usePortfolioOrders(mode, ordersPage);
  const backorders = usePortfolioBackorders(mode, boPage);
  const itemsNo = usePortfolioItemsNotOrdered(mode);

  const s = summary.data;
  const k = s?.kpis;
  const loading = summary.isLoading;

  // The Accounts tab mirrors the KPI section's resolved mode, not the raw toggle state —
  // "auto" needs to fall back to whatever the backend actually picked (self vs team).
  const effectiveMode = mode === "auto" ? s?.scope.mode : mode;
  const showTeamAccordion = effectiveMode === "team" && Boolean(s?.scope.can_toggle_team);
  // Only force "self" scoping when the viewer actually has a personal sales book — otherwise
  // (e.g. a sector-scoped non-sales viewer) leave the table on its normal DataScope behavior.
  const accountsTableMode =
    effectiveMode === "self" && s?.scope.has_sales_book ? ("self" as const) : undefined;

  return (
    <div className="space-y-5">
      {/* Header */}
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Sales portfolio
          </p>
          <h1 className="text-xl font-semibold tracking-tight">My Portfolio</h1>
          <p className="mt-0.5 max-w-2xl text-sm text-muted-foreground">
            Who is buying, who is quiet, how you track target, and what needs a call — in plain
            language.
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {s?.scope.can_toggle_team && (
            <div className="flex rounded-md border p-0.5">
              <ModeChip
                active={mode === "self" || (mode === "auto" && s.scope.mode === "self")}
                onClick={() => setMode("self")}
                label="My book"
              />
              <ModeChip
                active={mode === "team" || (mode === "auto" && s.scope.mode === "team")}
                onClick={() => setMode("team")}
                label="My team"
              />
            </div>
          )}
          <Button
            size="sm"
            variant="outline"
            onClick={() => summary.refetch()}
            disabled={summary.isFetching}
          >
            <RefreshCw className={cn("mr-1 h-3.5 w-3.5", summary.isFetching && "animate-spin")} />
            Refresh
          </Button>
        </div>
      </div>

      {/* Period picker — month, year, and comparison basis for the KPI + top-movers section */}
      <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-card p-2.5">
        <span className="pl-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
          Period
        </span>
        <Select value={String(month)} onValueChange={(v) => setMonth(Number(v))}>
          <SelectTrigger className="h-8 w-[140px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {MONTH_NAMES.map((name, i) => {
              const m = i + 1;
              const disabled = year === currentYear && m > currentMonth;
              return (
                <SelectItem key={m} value={String(m)} disabled={disabled}>
                  {name}
                </SelectItem>
              );
            })}
          </SelectContent>
        </Select>
        <Select
          value={String(year)}
          onValueChange={(v) => {
            const y = Number(v);
            setYear(y);
            // Clamp the month so switching into the current year never leaves a future month selected.
            if (y === currentYear && month > currentMonth) setMonth(currentMonth);
          }}
        >
          <SelectTrigger className="h-8 w-[100px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {yearOptions.map((y) => (
              <SelectItem key={y} value={String(y)} disabled={y > currentYear}>
                {y}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        {!isCurrentPeriod && (
          <Button
            size="sm"
            variant="ghost"
            className="h-8 px-2 text-xs"
            onClick={() => {
              setYear(currentYear);
              setMonth(currentMonth);
            }}
          >
            This month
          </Button>
        )}

        <div className="ml-auto flex items-center gap-2">
          <span className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
            Compare
          </span>
          <Select value={compare} onValueChange={(v) => setCompare(v as PortfolioCompareMode)}>
            <SelectTrigger className="h-8 w-[180px]">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="mom">Previous month</SelectItem>
              <SelectItem value="yoy">Same month, last year</SelectItem>
            </SelectContent>
          </Select>
        </div>
      </div>

      {/* Errors — never fail silently (cards disappear) */}
      {summary.isError && (
        <div className="rounded-lg border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive">
          <p className="font-medium">Could not load portfolio stats</p>
          <p className="mt-1 text-muted-foreground">
            {summary.error instanceof Error
              ? summary.error.message
              : "Check that the backend is deployed and includes sales/portfolio APIs."}
          </p>
          <Button size="sm" variant="outline" className="mt-2" onClick={() => summary.refetch()}>
            Retry
          </Button>
        </div>
      )}

      {/* Plain-language story */}
      {loading ? (
        <Skeleton className="h-16 w-full rounded-lg" />
      ) : s?.scope.empty_portfolio ? (
        <div className="rounded-lg border border-amber-200 bg-amber-50/80 p-4 text-sm text-amber-950 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100">
          <p className="font-medium">No customers in this view yet</p>
          <p className="mt-1 text-muted-foreground dark:text-amber-100/80">{s.scope.empty_hint}</p>
        </div>
      ) : s ? (
        <div className="rounded-lg border bg-gradient-to-br from-emerald-500/5 via-card to-card p-4 shadow-sm">
          <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
            <Badge variant="secondary">{s.windows.month_label}</Badge>
            {s.scope.rep_code && <Badge variant="outline">Rep {s.scope.rep_code}</Badge>}
            <Badge variant="outline" className="capitalize">
              {s.scope.mode === "team" ? "Team portfolio" : "Personal book"}
            </Badge>
          </div>
          <p className="mt-2 text-sm leading-relaxed text-foreground">{s.plain_language}</p>
        </div>
      ) : null}

      {/* KPI strip — always show when we have kpis (including zeros) */}
      {loading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-28 rounded-lg" />
          ))}
        </div>
      ) : k ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <KpiCard
            icon={Users}
            label="Customers"
            value={String(k.customers_total)}
            sub={`${k.customers_active} active · ${k.customers_dormant} dormant`}
            tip="Everyone attached via your rep code and assignments. Team mode includes your reports’ books."
            tone="blue"
          />
          <KpiCard
            icon={TrendingUp}
            label="Actively buying"
            value={String(k.customers_active)}
            sub={`Ordered since ${s?.windows.active_from ?? "—"}`}
            tip={`Purchased within the last 3 months from month start. Cut-off: ${s?.windows.active_from}.`}
            tone="emerald"
          />
          <KpiCard
            icon={Moon}
            label="Dormant"
            value={String(k.customers_dormant)}
            sub="No order in 3+ months"
            tip={s?.windows.dormant_tooltip ?? "Quiet accounts needing a visit or call."}
            tone="amber"
            href="/app/kp/dormant"
          />
          <KpiCard
            icon={Wallet}
            label={s?.windows.is_current_month === false ? "Revenue" : "Revenue MTD"}
            value={<MaskedKES value={k.revenue_mtd} />}
            sub={
              k.revenue_change_pct != null
                ? `${k.revenue_change_pct > 0 ? "▲" : k.revenue_change_pct < 0 ? "▼" : "—"} ${Math.abs(k.revenue_change_pct)}% ${s?.compare.label ?? "vs prior period"}`
                : "Month to date (order total)"
            }
            tip={`Sum of sales order totals for ${s?.windows.month_label ?? "this month"} in your portfolio (${s?.compare.label ?? "vs prior period"}: ${s?.compare.prior_month_label ?? "—"}). Ordered value — not cash collected.`}
            tone="emerald"
            trend={
              k.revenue_change_pct == null
                ? undefined
                : k.revenue_change_pct > 0
                  ? "up"
                  : k.revenue_change_pct < 0
                    ? "down"
                    : "flat"
            }
          />
          <KpiCard
            icon={Target}
            label="Target vs pace"
            value={
              k.target != null ? (
                <MaskedKES value={k.target} />
              ) : (
                <span className="text-base font-medium text-muted-foreground">Not set</span>
              )
            }
            sub={
              k.target != null && k.variance_to_pace != null
                ? `${k.time_gone_pct}% of working days gone · ${k.variance_to_pace >= 0 ? "ahead" : "behind"} by KES ${Math.abs(k.variance_to_pace).toLocaleString()}`
                : "Set target in Commissions"
            }
            tip={`Working days ${k.working_days_elapsed}/${k.working_days_total} this month. Expected so far: ${k.expected_revenue_to_date != null ? `KES ${k.expected_revenue_to_date.toLocaleString()}` : "—"}.`}
            tone="violet"
            progress={
              k.target && k.target > 0
                ? Math.min(100, Math.round((k.revenue_mtd / k.target) * 100))
                : undefined
            }
          />
          <KpiCard
            icon={ShoppingCart}
            label="My orders"
            value={String(k.orders_count)}
            sub={`${k.orders_open} open · ${k.orders_completed} completed/shipping`}
            tip="Sales orders this month from portfolio customers (excludes cancelled/rejected)."
            tone="sky"
            onClick={() => setTab("orders")}
          />
          <KpiCard
            icon={AlertTriangle}
            label="Backorders"
            value={String(k.backorder_lines)}
            sub={
              <>
                At risk <MaskedKES value={k.backorder_revenue_at_risk} />
              </>
            }
            tip="Open shortfall lines (open qty × unit price). This is value at risk — not confirmed lost sales."
            tone="red"
            onClick={() => setTab("backorders")}
          />
          <KpiCard
            icon={Package}
            label="Fill rate / predicted"
            value={k.fill_rate_pct != null ? `${k.fill_rate_pct}%` : "—"}
            sub={`~${k.predicted_remaining_orders} more orders · ~KES ${Math.round(k.predicted_remaining_value).toLocaleString()} est.`}
            tip="Fill rate ≈ shipped ÷ ordered this month. Prediction uses last 3 months daily run-rate for the rest of the month — estimate only."
            tone="cyan"
          />
        </div>
      ) : null}

      {/* Top movers */}
      {s && !s.scope.empty_portfolio && (
        <div className="grid gap-3 lg:grid-cols-2">
          <section className="rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="mb-1 text-sm font-semibold">Top customers this month</h2>
            <p className="mb-3 text-xs text-muted-foreground">
              Biggest revenue in your book
              {s.top_movers.concentration_top3_pct != null && (
                <> · top 3 = {s.top_movers.concentration_top3_pct}% of MTD</>
              )}
            </p>
            {s.top_movers.top_customers.length === 0 ? (
              <p className="text-sm text-muted-foreground">No orders yet this month.</p>
            ) : (
              <ul className="space-y-2">
                {s.top_movers.top_customers.map((c, i) => (
                  <li
                    key={c.customer_id}
                    className="flex items-center justify-between gap-2 text-sm"
                  >
                    <span className="flex min-w-0 items-center gap-2">
                      <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                        {i + 1}
                      </span>
                      <CustomerLink
                        customerId={c.customer_id}
                        customerName={c.customer_name}
                        className="truncate font-medium"
                      />
                    </span>
                    <span className="flex shrink-0 items-center gap-2 tabular-nums">
                      <TrendArrow trend={c.trend} pct={c.change_pct} />
                      <MaskedKES value={c.revenue} />
                    </span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="rounded-lg border bg-card p-4 shadow-sm">
            <h2 className="mb-1 flex items-center gap-1.5 text-sm font-semibold">
              <TrendingDown className="h-4 w-4 text-amber-600" />
              Early warning — slowing down
            </h2>
            <p className="mb-3 text-xs text-muted-foreground">
              Still “active” overall, but no order in 45+ days — call before they go dormant.
            </p>
            {s.top_movers.declining.length === 0 ? (
              <p className="text-sm text-muted-foreground">
                No at-risk accounts right now. Nice work.
              </p>
            ) : (
              <ul className="space-y-2">
                {s.top_movers.declining.map((c) => (
                  <li
                    key={c.customer_id}
                    className="flex items-center justify-between gap-2 text-sm"
                  >
                    <CustomerLink
                      customerId={c.customer_id}
                      customerName={c.customer_name}
                      className="font-medium"
                    />
                    <span className="text-xs text-muted-foreground">
                      Last order {c.last_order_date}
                    </span>
                  </li>
                ))}
              </ul>
            )}
            <Button asChild variant="link" className="mt-2 h-auto px-0 text-xs">
              <Link to="/app/kp/dormant">Open full dormant list →</Link>
            </Button>
          </section>
        </div>
      )}

      {/* Tabs */}
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList className="flex h-auto flex-wrap justify-start">
          <TabsTrigger value="overview">Accounts</TabsTrigger>
          <TabsTrigger value="orders">
            Orders
            {k ? (
              <Badge variant="secondary" className="ml-1.5 text-[10px]">
                {k.orders_count}
              </Badge>
            ) : null}
          </TabsTrigger>
          <TabsTrigger value="backorders">
            Backorders
            {k ? (
              <Badge variant="secondary" className="ml-1.5 text-[10px]">
                {k.backorder_lines}
              </Badge>
            ) : null}
          </TabsTrigger>
          <TabsTrigger value="items">
            Items not ordered
            {itemsNo.data?.summary.item_count != null ? (
              <Badge variant="secondary" className="ml-1.5 text-[10px]">
                {itemsNo.data.summary.item_count}
              </Badge>
            ) : null}
          </TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="mt-4">
          {showTeamAccordion ? (
            <TeamAccountsAccordion allClasses />
          ) : (
            <KpAccountsTable
              title="Accounts in your portfolio"
              description="All customers in your book (every customer class). Search and open any account for orders and contacts."
              searchPlaceholder="Search account name, ID, email, class…"
              emptyTitle="No accounts in this scope"
              allClasses
              badgeLabel="accounts"
              mode={accountsTableMode}
            />
          )}
        </TabsContent>

        <TabsContent value="orders" className="mt-4 space-y-3">
          <p className="text-sm text-muted-foreground">
            {orders.data?.hint ??
              "Grouped by customer (e.g. Titus Enterprise · 4 orders). Expand a row to see SOs."}
          </p>
          {orders.isLoading ? (
            <Skeleton className="h-48" />
          ) : orders.isError ? (
            <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
              Could not load orders.{" "}
              {orders.error instanceof Error ? orders.error.message : ""}
            </div>
          ) : (orders.data?.grouped?.length ?? 0) === 0 ? (
            <p className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
              No orders this month.
            </p>
          ) : (
            <>
              <Accordion type="multiple" className="rounded-lg border bg-card">
                {(orders.data?.grouped ?? []).map((g) => (
                  <AccordionItem
                    key={g.customer_id}
                    value={g.customer_id}
                    className="border-b px-3 last:border-b-0"
                  >
                    <AccordionTrigger className="hover:no-underline">
                      <div className="flex w-full flex-wrap items-center justify-between gap-2 pr-2 text-left">
                        <span className="text-sm font-semibold">{g.customer_name}</span>
                        <span className="flex items-center gap-2">
                          <Badge variant="secondary" className="tabular-nums">
                            {g.order_count} {g.order_count === 1 ? "order" : "orders"}
                          </Badge>
                          <span className="text-xs tabular-nums text-muted-foreground">
                            <MaskedKES value={g.total_value} />
                          </span>
                        </span>
                      </div>
                    </AccordionTrigger>
                    <AccordionContent>
                      <div className="mb-2">
                        <CustomerLink
                          customerId={g.customer_id}
                          customerName={g.customer_name}
                          className="text-xs"
                        />
                      </div>
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="border-b bg-muted/30 text-left text-xs text-muted-foreground">
                              <th className="p-2 font-medium">Order</th>
                              <th className="p-2 font-medium">Date</th>
                              <th className="p-2 font-medium">Status</th>
                              <th className="p-2 text-right font-medium">Total</th>
                            </tr>
                          </thead>
                          <tbody>
                            {g.orders.map((o) => (
                              <tr key={o.id} className="border-b last:border-0">
                                <td className="p-2">
                                  <OrderLink
                                    customerId={o.customer_id}
                                    orderId={o.order_nbr}
                                    className="font-medium"
                                  />
                                </td>
                                <td className="p-2 text-muted-foreground">
                                  {String(o.order_date).slice(0, 10)}
                                </td>
                                <td className="p-2">
                                  <StatusBadge status={o.status} />
                                </td>
                                <td className="p-2 text-right tabular-nums">
                                  <MaskedKES value={o.order_total} />
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </AccordionContent>
                  </AccordionItem>
                ))}
              </Accordion>
              <Pager
                page={orders.data?.current_page ?? 1}
                last={orders.data?.last_page ?? 1}
                onPage={setOrdersPage}
              />
            </>
          )}
        </TabsContent>

        <TabsContent value="backorders" className="mt-4 space-y-3">
          <p className="text-sm text-muted-foreground">
            {backorders.data?.hint ??
              "Grouped by customer. Expand to see lines still short on stock."}{" "}
            <strong>Value at risk</strong> = open qty × unit price
            {backorders.data ? (
              <>
                {" "}
                · Total at risk: <MaskedKES value={backorders.data.revenue_at_risk} />
              </>
            ) : null}
          </p>
          {backorders.isLoading ? (
            <Skeleton className="h-48" />
          ) : backorders.isError ? (
            <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
              Could not load backorders.{" "}
              {backorders.error instanceof Error ? backorders.error.message : ""}
            </div>
          ) : (backorders.data?.grouped?.length ?? 0) === 0 ? (
            <p className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
              No active backorder lines.
            </p>
          ) : (
            <>
              <Accordion type="multiple" className="rounded-lg border bg-card">
                {(backorders.data?.grouped ?? []).map((g) => (
                  <AccordionItem
                    key={g.customer_id}
                    value={g.customer_id}
                    className="border-b px-3 last:border-b-0"
                  >
                    <AccordionTrigger className="hover:no-underline">
                      <div className="flex w-full flex-wrap items-center justify-between gap-2 pr-2 text-left">
                        <span className="text-sm font-semibold">{g.customer_name}</span>
                        <span className="flex items-center gap-2">
                          <Badge variant="secondary" className="tabular-nums">
                            {g.line_count} {g.line_count === 1 ? "line" : "lines"}
                          </Badge>
                          <span className="text-xs tabular-nums text-muted-foreground">
                            <MaskedKES value={g.revenue_at_risk} />
                          </span>
                        </span>
                      </div>
                    </AccordionTrigger>
                    <AccordionContent>
                      <div className="mb-2">
                        <CustomerLink
                          customerId={g.customer_id}
                          customerName={g.customer_name}
                          className="text-xs"
                        />
                      </div>
                      <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="border-b bg-muted/30 text-left text-xs text-muted-foreground">
                              <th className="p-2 font-medium">SO</th>
                              <th className="p-2 font-medium">SKU</th>
                              <th className="p-2 text-right font-medium">Open qty</th>
                              <th className="p-2 text-right font-medium">At risk</th>
                              <th className="p-2 font-medium">Status</th>
                            </tr>
                          </thead>
                          <tbody>
                            {g.lines.map((b) => (
                              <tr key={b.id} className="border-b last:border-0">
                                <td className="p-2">
                                  <OrderLink
                                    customerId={b.customer_id}
                                    orderId={b.order_nbr}
                                    className="font-medium"
                                  />
                                </td>
                                <td className="p-2 font-mono text-xs">{b.inventory_id}</td>
                                <td className="p-2 text-right tabular-nums">{b.open_qty}</td>
                                <td className="p-2 text-right tabular-nums">
                                  <MaskedKES value={b.revenue_at_risk} />
                                </td>
                                <td className="p-2 text-xs text-muted-foreground">
                                  {b.fulfillment_status ?? "—"}
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </AccordionContent>
                  </AccordionItem>
                ))}
              </Accordion>
              <Pager
                page={backorders.data?.current_page ?? 1}
                last={backorders.data?.last_page ?? 1}
                onPage={setBoPage}
              />
            </>
          )}
        </TabsContent>

        <TabsContent value="items" className="mt-4 space-y-3">
          <p className="text-sm text-muted-foreground">
            {itemsNo.data?.hint ??
              "Grouped by customer (e.g. Titus Enterprise · 10 items). Expand a row to see SKUs."}
          </p>
          {itemsNo.isLoading ? (
            <Skeleton className="h-48" />
          ) : itemsNo.isError ? (
            <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
              Could not load items not ordered.{" "}
              {itemsNo.error instanceof Error ? itemsNo.error.message : ""}
            </div>
          ) : (itemsNo.data?.data.length ?? 0) === 0 ? (
            <p className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
              No overdue recurring items for this portfolio.
            </p>
          ) : (
            <Accordion type="multiple" className="rounded-lg border bg-card">
              {(itemsNo.data?.grouped ?? itemsNo.data?.data ?? []).map((group) => {
                const n = group.item_count;
                const title =
                  group.label ||
                  `${group.customer_name} · ${n} ${n === 1 ? "item" : "items"}`;
                return (
                  <AccordionItem
                    key={group.customer_id}
                    value={group.customer_id}
                    className="border-b px-3 last:border-b-0"
                  >
                    <AccordionTrigger className="hover:no-underline">
                      <div className="flex w-full flex-wrap items-center justify-between gap-2 pr-2 text-left">
                        <span className="text-sm font-semibold">{group.customer_name}</span>
                        <Badge variant="secondary" className="tabular-nums">
                          {n} {n === 1 ? "item" : "items"}
                        </Badge>
                      </div>
                    </AccordionTrigger>
                    <AccordionContent>
                      <p className="mb-2 text-xs text-muted-foreground">{title}</p>
                      <div className="mb-2">
                        <CustomerLink
                          customerId={group.customer_id}
                          customerName={group.customer_name}
                          className="text-xs"
                        />
                      </div>
                      <table className="w-full text-xs">
                        <thead>
                          <tr className="text-left text-muted-foreground">
                            <th className="py-1 pr-2">SKU</th>
                            <th className="py-1 pr-2">Last ordered</th>
                            <th className="py-1 pr-2">Days quiet</th>
                            <th className="py-1 text-right">Avg value*</th>
                          </tr>
                        </thead>
                        <tbody>
                          {group.items.map((it) => (
                            <tr key={it.inventory_id} className="border-t">
                              <td className="py-1.5 pr-2">
                                <span className="font-mono">{it.inventory_id}</span>
                                {it.description && (
                                  <span className="block text-muted-foreground">
                                    {it.description}
                                  </span>
                                )}
                              </td>
                              <td className="py-1.5 pr-2">{it.last_order_date ?? "—"}</td>
                              <td className="py-1.5 pr-2">
                                {it.days_since_order ?? it.days_overdue ?? "—"}
                              </td>
                              <td className="py-1.5 text-right tabular-nums">
                                {it.avg_value != null || it.opportunity_value != null ? (
                                  <MaskedKES
                                    value={it.avg_value ?? it.opportunity_value ?? 0}
                                  />
                                ) : (
                                  <span className="text-muted-foreground">—</span>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                      <p className="mt-2 text-[10px] text-muted-foreground">
                        * Value only when average sales for that item exist in recent history.
                      </p>
                    </AccordionContent>
                  </AccordionItem>
                );
              })}
            </Accordion>
          )}
        </TabsContent>
      </Tabs>
    </div>
  );
}

/**
 * "My Team" accounts view — one accordion row per rep, with their own book's stats
 * (accounts, active/dormant/on-hold, revenue MTD, target pace). Expanding a row lazily
 * loads that rep's account list via the same table used for "My book".
 */
function TeamAccountsAccordion({
  excludeClass,
  allClasses = false,
}: {
  excludeClass?: string;
  allClasses?: boolean;
}) {
  const byRep = useKpAccountsByRep({
    exclude_class: excludeClass,
    all_classes: allClasses,
  });
  const groups = byRep.data?.groups ?? [];

  if (byRep.isLoading) {
    return (
      <div className="space-y-2">
        {Array.from({ length: 4 }).map((_, i) => (
          <Skeleton key={i} className="h-16 w-full rounded-lg" />
        ))}
      </div>
    );
  }

  if (byRep.isError) {
    return (
      <div className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
        Could not load team accounts.{" "}
        {byRep.error instanceof Error ? byRep.error.message : ""}
      </div>
    );
  }

  if (groups.length === 0) {
    return (
      <p className="rounded-lg border border-dashed p-8 text-center text-muted-foreground">
        No team accounts in this scope yet.
      </p>
    );
  }

  return (
    <Accordion type="multiple" className="rounded-lg border bg-card">
      {groups.map((g) => (
        <AccordionItem
          key={g.user_id}
          value={String(g.user_id)}
          className="border-b px-3 last:border-b-0"
        >
          <AccordionTrigger className="hover:no-underline">
            <div className="flex w-full flex-wrap items-center justify-between gap-3 pr-2 text-left">
              <div className="min-w-0">
                <span className="font-semibold">{g.rep_name}</span>
                <span className="ml-2 font-mono text-xs text-muted-foreground">
                  {g.rep_code ?? "—"}
                  {g.employee_number ? ` / ${g.employee_number}` : ""}
                </span>
              </div>
              <div className="flex flex-wrap items-center gap-1.5 text-xs">
                <Badge variant="secondary" className="tabular-nums">
                  {g.account_count} accounts
                </Badge>
                <Badge
                  variant="outline"
                  className="border-emerald-200 bg-emerald-50 tabular-nums text-emerald-800"
                >
                  {g.active_count} active
                </Badge>
                <Badge
                  variant="outline"
                  className="border-amber-200 bg-amber-50 tabular-nums text-amber-900"
                >
                  {g.dormant_count} dormant
                </Badge>
                {g.on_hold_count > 0 && (
                  <Badge
                    variant="outline"
                    className="border-red-200 bg-red-50 tabular-nums text-red-800"
                  >
                    {g.on_hold_count} on hold
                  </Badge>
                )}
                <span className="ml-1 font-medium tabular-nums">
                  <MaskedKES value={g.revenue_mtd} />
                </span>
                {g.target != null ? (
                  <span className="text-muted-foreground">
                    of <MaskedKES value={g.target} />
                    {g.achieved_pct != null ? ` · ${g.achieved_pct}%` : ""} ·{" "}
                    {g.time_gone_pct}% time gone
                  </span>
                ) : (
                  <span className="text-muted-foreground">No target set · {g.time_gone_pct}% time gone</span>
                )}
              </div>
            </div>
          </AccordionTrigger>
          <AccordionContent>
            <KpAccountsTable
              title=""
              description=""
              hideHeader
              mode="team"
              repCode={g.rep_code ?? undefined}
              excludeClass={excludeClass}
              allClasses={allClasses}
              badgeLabel="accounts"
              emptyTitle="No accounts for this rep in this scope"
            />
          </AccordionContent>
        </AccordionItem>
      ))}
    </Accordion>
  );
}

function ModeChip({
  active,
  onClick,
  label,
}: {
  active: boolean;
  onClick: () => void;
  label: string;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        "rounded px-2.5 py-1 text-xs font-medium transition-colors",
        active ? "bg-primary text-primary-foreground" : "text-muted-foreground hover:bg-muted",
      )}
    >
      {label}
    </button>
  );
}

function KpiCard({
  icon: Icon,
  label,
  value,
  sub,
  tip,
  tone,
  href,
  onClick,
  progress,
  trend,
}: {
  icon: React.ComponentType<{ className?: string }>;
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
  tip: string;
  tone: "blue" | "emerald" | "amber" | "violet" | "sky" | "red" | "cyan";
  href?: string;
  onClick?: () => void;
  progress?: number;
  trend?: "up" | "down" | "flat";
}) {
  const tones: Record<string, string> = {
    blue: "border-blue-200/80 bg-blue-50/50 dark:border-blue-900/40 dark:bg-blue-950/20",
    emerald:
      "border-emerald-200/80 bg-emerald-50/50 dark:border-emerald-900/40 dark:bg-emerald-950/20",
    amber: "border-amber-200/80 bg-amber-50/50 dark:border-amber-900/40 dark:bg-amber-950/20",
    violet: "border-violet-200/80 bg-violet-50/50 dark:border-violet-900/40 dark:bg-violet-950/20",
    sky: "border-sky-200/80 bg-sky-50/50 dark:border-sky-900/40 dark:bg-sky-950/20",
    red: "border-red-200/80 bg-red-50/50 dark:border-red-900/40 dark:bg-red-950/20",
    cyan: "border-cyan-200/80 bg-cyan-50/50 dark:border-cyan-900/40 dark:bg-cyan-950/20",
  };

  const body = (
    <div
      className={cn(
        "rounded-lg border p-4 shadow-sm transition-shadow",
        tones[tone],
        (href || onClick) && "cursor-pointer hover:shadow-md",
      )}
      onClick={onClick}
      role={onClick ? "button" : undefined}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="flex items-center gap-1.5 text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
          <Icon className="h-3.5 w-3.5" />
          {label}
          <InfoTip text={tip} />
        </div>
        {trend === "up" && <TrendingUp className="h-4 w-4 text-emerald-600" />}
        {trend === "down" && <TrendingDown className="h-4 w-4 text-red-600" />}
      </div>
      <div className="mt-1 text-2xl font-bold tracking-tight">{value}</div>
      {sub != null && <div className="mt-1 text-xs text-muted-foreground">{sub}</div>}
      {progress != null && (
        <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-black/10 dark:bg-white/10">
          <div
            className="h-full rounded-full bg-primary transition-all"
            style={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
          />
        </div>
      )}
    </div>
  );

  if (href) {
    return (
      <Link to={href} className="block no-underline">
        {body}
      </Link>
    );
  }
  return body;
}

function TrendArrow({ trend, pct }: { trend: string; pct: number | null }) {
  if (pct == null || trend === "flat") {
    return <span className="text-xs text-muted-foreground">—</span>;
  }
  return (
    <span
      className={cn("text-xs font-medium", trend === "up" ? "text-emerald-600" : "text-red-600")}
    >
      {trend === "up" ? "▲" : "▼"}
      {Math.abs(pct)}%
    </span>
  );
}

function StatusBadge({ status }: { status: string | null }) {
  const s = (status ?? "—").toLowerCase();
  let cls = "border-border text-muted-foreground";
  if (s.includes("complete") || s === "shipping")
    cls = "border-emerald-200 bg-emerald-50 text-emerald-800";
  else if (s.includes("open") || s.includes("pending"))
    cls = "border-sky-200 bg-sky-50 text-sky-800";
  else if (s.includes("hold") || s.includes("back"))
    cls = "border-amber-200 bg-amber-50 text-amber-900";
  else if (s.includes("cancel") || s.includes("reject"))
    cls = "border-red-200 bg-red-50 text-red-800";
  return (
    <Badge variant="outline" className={cn("capitalize", cls)}>
      {status ?? "—"}
    </Badge>
  );
}

function Pager({
  page,
  last,
  onPage,
}: {
  page: number;
  last: number;
  onPage: (p: number) => void;
}) {
  if (last <= 1) return null;
  return (
    <div className="flex items-center justify-end gap-2 text-sm">
      <Button size="sm" variant="outline" disabled={page <= 1} onClick={() => onPage(page - 1)}>
        Previous
      </Button>
      <span className="text-muted-foreground">
        Page {page} of {last}
      </span>
      <Button size="sm" variant="outline" disabled={page >= last} onClick={() => onPage(page + 1)}>
        Next
      </Button>
    </div>
  );
}
