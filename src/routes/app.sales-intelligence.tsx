import { createFileRoute, Link } from "@tanstack/react-router";
import { useEffect, useMemo, useState } from "react";
import {
  BadgeDollarSign,
  Building2,
  FileMinus2,
  PackageCheck,
  Store,
  RefreshCw,
  Search,
  ShoppingCart,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { MultiSelect } from "@/components/ui/multi-select";
import { Skeleton } from "@/components/ui/skeleton";
import { Badge } from "@/components/ui/badge";
import { CustomerLink } from "@/components/entity-links";
import {
  type SalesIntelligenceChannel,
  type SalesIntelligenceCustomer,
  useSalesIntelligenceMetrics,
} from "@/hooks/useSalesIntelligence";
import { useCapabilities } from "@/hooks/useCapabilities";
import { cn } from "@/lib/utils";

type SearchParams = { channel?: SalesIntelligenceChannel };
const CHANNELS: SalesIntelligenceChannel[] = ["PORTFOLIO", "MT", "MT1", "MT2", "GT", "DTC_DTB", "ECOMMERCE", "KP"];
const BUSINESS_CHANNELS: SalesIntelligenceChannel[] = ["MT", "MT1", "MT2", "GT", "DTC_DTB", "ECOMMERCE", "KP"];

function currentNairobiPeriod() {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone: "Africa/Nairobi",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));

  return {
    from: `${values.year}-${values.month}-01`,
    to: `${values.year}-${values.month}-${values.day}`,
  };
}

export const Route = createFileRoute("/app/sales-intelligence")({
  validateSearch: (search: Record<string, unknown>): SearchParams => {
    if (typeof search.channel !== "string") return { channel: undefined };
    const value = search.channel.toUpperCase();
    return {
      channel: CHANNELS.includes(value as SalesIntelligenceChannel)
        ? (value as SalesIntelligenceChannel)
        : undefined,
    };
  },
  head: () => ({ meta: [{ title: "Sales Intelligence - Kim-Fay Sight" }] }),
  component: SalesIntelligencePage,
});

type SortKey =
  | "customer_name"
  | "so_count"
  | "open_so_count"
  | "gross_revenue"
  | "credit_revenue"
  | "net_revenue"
  | "net_volume"
  | "last_order_date";

function SalesIntelligencePage() {
  const { channel } = Route.useSearch();
  const caps = useCapabilities();
  const effectiveChannel = channel ?? (caps.default_sales_channel as SalesIntelligenceChannel | null) ?? "MT";
  return <SalesIntelligenceView channel={effectiveChannel} />;
}

export function SalesIntelligenceView({ channel }: { channel: SalesIntelligenceChannel }) {
  const caps = useCapabilities();
  const initialPeriod = useMemo(currentNairobiPeriod, []);
  const [from, setFrom] = useState(initialPeriod.from);
  const [to, setTo] = useState(initialPeriod.to);
  const [q, setQ] = useState("");
  const [region, setRegion] = useState("");
  const [comparison, setComparison] = useState<"" | "previous_month" | "past_3_months" | "past_6_months">("");
  const [selectedChannels, setSelectedChannels] = useState<SalesIntelligenceChannel[]>([channel]);
  const [selectedReps, setSelectedReps] = useState<string[]>([]);
  const [selectedCustomers, setSelectedCustomers] = useState<string[]>([]);

  // Keep the in-page channel selection in sync when the URL channel changes.
  useEffect(() => {
    setSelectedChannels([channel]);
  }, [channel]);

  // Reset the rep/customer filters whenever the channel scope changes so stale
  // selections never hide every row.
  useEffect(() => {
    setSelectedReps([]);
    setSelectedCustomers([]);
  }, [selectedChannels]);

  // The channel selector only offers the business channels the viewer is
  // authorized for. PORTFOLIO is a personal scope, so it is intentionally left
  // out of the multi-select.
  const channelOptions = useMemo<SalesIntelligenceChannel[]>(() => {
    const allowed = new Set(caps.sales_intelligence_channels.map((code) => code.toLowerCase()));
    const options = BUSINESS_CHANNELS.filter((code) => {
      const slug = code.toLowerCase();
      if (code === "MT") return allowed.has("mt1") || allowed.has("mt2") || allowed.has("mt");
      return allowed.has(slug);
    });
    return options.length ? options : [channel];
  }, [caps.sales_intelligence_channels, channel]);

  const onChannelsChange = (next: string[]) => {
    // Clearing the selection is treated as "any / all channels".
    if (next.length === 0) {
      setSelectedChannels(channelOptions);
      return;
    }
    setSelectedChannels(
      next.filter((code): code is SalesIntelligenceChannel =>
        BUSINESS_CHANNELS.includes(code as SalesIntelligenceChannel),
      ),
    );
  };

  const monthOptions = useMemo(() => {
    const now = new Date();
    return Array.from({ length: now.getMonth() + 1 }, (_, index) => ({
      value: `${now.getFullYear()}-${String(index + 1).padStart(2, "0")}`,
      label: new Date(now.getFullYear(), index, 1).toLocaleString("en-KE", { month: "long", year: "numeric" }),
    }));
  }, []);
  const [sortKey, setSortKey] = useState<SortKey>("net_revenue");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");

  const query = useSalesIntelligenceMetrics(
    selectedChannels,
    from || undefined,
    to || undefined,
    region || undefined,
    comparison || undefined,
  );
  const data = query.data;

  // Representative options are derived from the customers returned for the
  // current scope, so the filter always reflects the visible data.
  const repOptions = useMemo(() => {
    const rows = data?.customers ?? [];
    const byId = new Map<string, { id: number; name: string; rep_code: string | null }>();
    rows.forEach((row) =>
      row.representatives.forEach((rep) => {
        if (!byId.has(String(rep.id))) byId.set(String(rep.id), rep);
      }),
    );
    return Array.from(byId, ([value, rep]) => ({
      value,
      label: rep.name + (rep.rep_code ? ` (${rep.rep_code})` : ""),
    }));
  }, [data?.customers]);

  const customerOptions = useMemo(
    () => (data?.customers ?? []).map((row) => ({ value: row.customer_id, label: row.customer_name })),
    [data?.customers],
  );

  const matchesRepCustomer = (row: {
    representatives: SalesIntelligenceCustomer["representatives"];
    customer_id: string;
  }) => {
    const repOk = selectedReps.length === 0 || row.representatives.some((rep) => selectedReps.includes(String(rep.id)));
    const custOk = selectedCustomers.length === 0 || selectedCustomers.includes(row.customer_id);
    return repOk && custOk;
  };

  const scopedCustomers = useMemo(
    () => (data?.customers ?? []).filter(matchesRepCustomer),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data?.customers, selectedReps, selectedCustomers],
  );

  const scopedNotOrdered = useMemo(
    () => (data?.not_ordered_customers ?? []).filter(matchesRepCustomer),
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [data?.not_ordered_customers, selectedReps, selectedCustomers],
  );

  const filtersActive = selectedReps.length > 0 || selectedCustomers.length > 0;

  const metricsView = useMemo(() => {
    if (!data) return null;
    if (!filtersActive) {
      return {
        customerCount: data.scope.customer_count,
        notOrderedCount: data.scope.not_ordered_count,
        soCount: data.metrics.so_count,
        gross: data.metrics.gross_revenue,
        credit: data.metrics.credit_revenue,
        net: data.metrics.net_revenue,
        orderedVolume: data.metrics.ordered_volume,
        creditedVolume: data.metrics.credited_volume,
        netVolume: data.metrics.net_volume,
      };
    }
    return {
      customerCount: scopedCustomers.length + scopedNotOrdered.length,
      notOrderedCount: scopedNotOrdered.length,
      soCount: scopedCustomers.reduce((total, row) => total + row.so_count, 0),
      gross: scopedCustomers.reduce((total, row) => total + row.gross_revenue, 0),
      credit: scopedCustomers.reduce((total, row) => total + row.credit_revenue, 0),
      net: scopedCustomers.reduce((total, row) => total + row.net_revenue, 0),
      orderedVolume: scopedCustomers.reduce((total, row) => total + row.ordered_volume, 0),
      creditedVolume: scopedCustomers.reduce((total, row) => total + row.credited_volume, 0),
      netVolume: scopedCustomers.reduce((total, row) => total + row.net_volume, 0),
    };
  }, [data, filtersActive, scopedCustomers, scopedNotOrdered]);

  const customers = useMemo(() => {
    const needle = q.trim().toLowerCase();
    let filtered = scopedCustomers;
    if (needle) {
      filtered = scopedCustomers.filter(
        (row) =>
          row.customer_name.toLowerCase().includes(needle) ||
          row.customer_id.toLowerCase().includes(needle) ||
          row.representatives.some((representative) =>
            `${representative.name} ${representative.rep_code ?? ""}`.toLowerCase().includes(needle),
          ),
      );
    }
    const dir = sortDir === "asc" ? 1 : -1;
    return [...filtered].sort((a, b) => {
      const av = a[sortKey];
      const bv = b[sortKey];
      if (av == null && bv == null) return 0;
      if (av == null) return 1;
      if (bv == null) return -1;
      if (typeof av === "string" && typeof bv === "string") {
        return av.localeCompare(bv, undefined, { sensitivity: "base" }) * dir;
      }
      return (Number(av) - Number(bv)) * dir;
    });
  }, [scopedCustomers, q, sortKey, sortDir]);

  const toggleSort = (key: SortKey) => {
    if (sortKey === key) {
      setSortDir((d) => (d === "asc" ? "desc" : "asc"));
    } else {
      setSortKey(key);
      setSortDir(key === "customer_name" || key === "last_order_date" ? "asc" : "desc");
    }
  };

  const channelLabel = useMemo(
    () =>
      selectedChannels.map((code) => code.replace(/_/g, " / ")).join(", ") ||
      channel.replace(/_/g, " / "),
    [selectedChannels, channel],
  );

  const comparisonOutlets = useMemo(() => {
    const rows = data?.comparison?.outlets ?? [];
    return filtersActive ? rows.filter(matchesRepCustomer) : rows;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data?.comparison?.outlets, filtersActive, selectedReps, selectedCustomers]);

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-end justify-between gap-3 border-b pb-4">
        <div>
          <h1 className="text-xl font-semibold">
            Sales Intelligence: {channel.replace("_", " / ")}
          </h1>
          <p className="text-sm text-muted-foreground">
            Authorized outlets with order counts, revenue, volume, and last order date.
          </p>
        </div>
        <div className="flex flex-wrap items-end gap-2">
          <label className="text-xs text-muted-foreground">
            Month
            <select className="mt-1 block h-9 rounded-md border bg-background px-2 text-xs" defaultValue={initialPeriod.from.slice(0, 7)} onChange={(event) => {
              const value = event.target.value;
              if (!value) return;
              const [year, month] = value.split("-").map(Number);
              const start = `${value}-01`;
              const now = new Date();
              const isCurrent = year === now.getFullYear() && month === now.getMonth() + 1;
              const end = isCurrent
                ? `${year}-${String(month).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`
                : `${year}-${String(month).padStart(2, "0")}-${String(new Date(year, month, 0).getDate()).padStart(2, "0")}`;
              setFrom(start); setTo(end);
            }}>
              <option value="">Custom dates</option>
              {monthOptions.map((value) => <option key={value.value} value={value.value}>{value.label}</option>)}
            </select>
          </label>
          <label className="text-xs text-muted-foreground">
            Region
            <select className="mt-1 block h-9 rounded-md border bg-background px-2 text-xs" value={region} onChange={(event) => setRegion(event.target.value)}>
              <option value="">All regions</option>
              {(data?.scope.available_regions ?? []).map((value) => <option key={value} value={value}>{value}</option>)}
            </select>
          </label>
          <label className="text-xs text-muted-foreground">
            Compare with
            <select className="mt-1 block h-9 rounded-md border bg-background px-2 text-xs" value={comparison} onChange={(event) => setComparison(event.target.value as typeof comparison)}>
              <option value="">No comparison</option>
              <option value="previous_month">Previous month</option>
              <option value="past_3_months">Past 3 months</option>
              <option value="past_6_months">Past 6 months</option>
            </select>
          </label>
          <label className="text-xs text-muted-foreground">
            From
            <Input
              type="date"
              className="mt-1 h-9"
              value={from}
              onChange={(event) => setFrom(event.target.value)}
            />
          </label>
          <label className="text-xs text-muted-foreground">
            To
            <Input
              type="date"
              className="mt-1 h-9"
              value={to}
              onChange={(event) => setTo(event.target.value)}
            />
          </label>
          <Button size="icon" variant="outline" onClick={() => query.refetch()} title="Refresh">
            <RefreshCw className="h-4 w-4" />
          </Button>
        </div>
      </header>

      <section className="flex flex-wrap items-end gap-3 rounded-md border bg-card p-3">
        <MultiSelect
          label="Channel"
          options={channelOptions}
          selected={selectedChannels}
          onChange={onChannelsChange}
          allLabel="All channels"
          emptyLabel="All channels"
          className="min-w-[12rem] flex-1"
        />
        <MultiSelect
          label="Representative"
          options={repOptions}
          selected={selectedReps}
          onChange={setSelectedReps}
          allLabel="All reps"
          emptyLabel="All reps"
          className="min-w-[12rem] flex-1"
          disabled={!data}
        />
        <MultiSelect
          label="Customer"
          options={customerOptions}
          selected={selectedCustomers}
          onChange={setSelectedCustomers}
          allLabel="All customers"
          emptyLabel="All customers"
          className="min-w-[14rem] flex-1"
          searchPlaceholder="Search customers..."
          disabled={!data}
        />
        {filtersActive ? (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              setSelectedReps([]);
              setSelectedCustomers([]);
            }}
          >
            Clear rep / customer
          </Button>
        ) : null}
      </section>

      {query.isLoading ? (
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          {Array.from({ length: 8 }).map((_, index) => (
            <Skeleton key={index} className="h-24" />
          ))}
        </div>
      ) : query.isError || !data || !metricsView ? (
        <div className="border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          <div className="font-medium">Unable to load this authorized channel scope.</div>
          <div className="mt-1 text-xs">
            {query.error instanceof Error ? query.error.message : "The server did not return Sales Intelligence data."}
          </div>
        </div>
      ) : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Metric
              icon={Building2}
              label="Customers"
              value={metricsView.customerCount.toLocaleString()}
            />
            <Metric
              icon={ShoppingCart}
              label="Sales orders"
              value={metricsView.soCount.toLocaleString()}
            />
            <Metric
              icon={BadgeDollarSign}
              label="Gross sales"
              value={money(metricsView.gross)}
            />
            <Metric icon={FileMinus2} label="Credits" value={money(metricsView.credit)} />
            <Metric
              icon={BadgeDollarSign}
              label={data.scope.portfolio_scope === "team" ? "Team portfolio revenue" : data.scope.portfolio_scoped ? "Your accounts revenue" : "Net revenue"}
              value={money(metricsView.net)}
            />
            <Metric
              icon={PackageCheck}
              label="Ordered volume"
              value={metricsView.orderedVolume.toLocaleString()}
            />
            <Metric
              icon={FileMinus2}
              label="Credited volume"
              value={metricsView.creditedVolume.toLocaleString()}
            />
            <Metric
              icon={PackageCheck}
              label="Net volume"
              value={metricsView.netVolume.toLocaleString()}
            />
            <Metric
              icon={Store}
              label="Outlets not ordered"
              value={metricsView.notOrderedCount.toLocaleString()}
            />
          </div>
          <p className="text-xs text-muted-foreground">
            {data.period.from} to {data.period.to} ({data.period.timezone}). Credit notes are shown
            separately and deducted from net results.
            {filtersActive ? " Metric cards reflect the selected representative / customer filters." : ""}
          </p>

          <section className="space-y-2">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="text-sm font-semibold">Customers in {channelLabel}</h2>
                <p className="text-[11px] text-muted-foreground">
                  {customers.length} shown
                  {q.trim() ? ` of ${scopedCustomers.length}` : ""}. Click a customer to open
                  their orders.
                </p>
              </div>
              <div className="relative w-full max-w-xs">
                <Search className="pointer-events-none absolute top-1/2 left-2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="h-8 pl-7 text-xs"
                  placeholder="Search customer name or ID…"
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                />
              </div>
            </div>

            <div className="overflow-x-auto rounded-md border bg-card">
              <table className="w-full min-w-[960px] text-[11px]">
                <thead className="bg-muted/40 text-left">
                  <tr>
                    {(
                      [
                        ["customer_name", "Customer"],
                        ["so_count", "SOs"],
                        ["open_so_count", "Open SOs"],
                        ["gross_revenue", "Gross"],
                        ["credit_revenue", "Credits"],
                        ["net_revenue", "Net revenue"],
                        ["net_volume", "Net volume"],
                        ["last_order_date", "Last order"],
                      ] as const
                    ).map(([key, label]) => (
                      <th key={key} className="px-2 py-2 font-semibold">
                        <button
                          type="button"
                          className={cn(
                            "inline-flex items-center gap-1 hover:text-primary",
                            sortKey === key && "text-primary",
                          )}
                          onClick={() => toggleSort(key)}
                        >
                          {label}
                          {sortKey === key ? (sortDir === "asc" ? " ↑" : " ↓") : null}
                        </button>
                      </th>
                    ))}
                    <th className="px-2 py-2 font-semibold">Representative</th>
                    <th className="px-2 py-2 font-semibold">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {customers.map((row) => (
                    <CustomerRow key={row.customer_id} row={row} />
                  ))}
                  {customers.length === 0 ? (
                    <tr>
                      <td colSpan={10} className="px-3 py-8 text-center text-muted-foreground">
                        No customers in this segment for the selected period and filters.
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </section>

          <section className="space-y-2">
            <div>
              <h2 className="text-sm font-semibold">Outlets that have not ordered</h2>
              <p className="text-[11px] text-muted-foreground">
                {scopedNotOrdered.length} outlet(s) in {channelLabel} with no sales order from{" "}
                {data.period.from} to {data.period.to}.
              </p>
            </div>
            <div className="overflow-x-auto rounded-md border bg-card">
              <table className="w-full min-w-[680px] text-[11px]">
                <thead className="bg-muted/40 text-left">
                  <tr>
                    <th className="px-2 py-2 font-semibold">Outlet</th>
                    <th className="px-2 py-2 font-semibold">Representative</th>
                    <th className="px-2 py-2 font-semibold">Channel</th>
                    <th className="px-2 py-2 font-semibold">Class</th>
                    <th className="px-2 py-2 font-semibold">Route</th>
                    <th className="px-2 py-2 font-semibold">Last order</th>
                    <th className="px-2 py-2 font-semibold">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {scopedNotOrdered.map((row) => (
                    <tr key={row.customer_id} className="border-t hover:bg-muted/20">
                      <td className="px-2 py-2">
                        <CustomerLink customerId={row.customer_id} customerName={row.customer_name} className="font-semibold">
                          {row.customer_name}
                        </CustomerLink>
                        <div className="font-mono text-[10px] text-muted-foreground">{row.customer_id}</div>
                      </td>
                      <td className="px-2 py-2"><RepresentativeNames representatives={row.representatives} /></td>
                      <td className="px-2 py-2"><Badge variant="outline">{row.sales_channel ?? channel}</Badge></td>
                      <td className="px-2 py-2">{row.customer_class ?? "—"}</td>
                      <td className="px-2 py-2">{row.route_code ?? "—"}</td>
                      <td className="px-2 py-2">{row.last_order_date ?? "No order in period"}</td>
                      <td className="px-2 py-2">
                        <Button asChild size="sm" variant="outline" className="h-7 text-[10px]">
                          <Link to="/app/customer-orders/$customerId" params={{ customerId: row.customer_id }}>Orders</Link>
                        </Button>
                      </td>
                    </tr>
                  ))}
                  {scopedNotOrdered.length === 0 ? (
                    <tr><td colSpan={7} className="px-3 py-8 text-center text-muted-foreground">All outlets in this segment ordered during the selected period.</td></tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </section>

          {data.comparison ? (
            <section className="space-y-2">
              <div>
                <h2 className="text-sm font-semibold">Outlets below the comparison period</h2>
                <p className="text-[11px] text-muted-foreground">
                  {comparisonOutlets.length} outlet(s) with lower revenue or fewer orders versus {data.comparison.from} to {data.comparison.to}.
                </p>
              </div>
              <div className="overflow-x-auto rounded-md border bg-card">
                <table className="w-full min-w-[820px] text-[11px]">
                  <thead className="bg-muted/40 text-left"><tr>
                    <th className="px-2 py-2">Outlet</th><th className="px-2 py-2">Representative</th><th className="px-2 py-2">Channel</th><th className="px-2 py-2">Region</th>
                    <th className="px-2 py-2">Current revenue</th><th className="px-2 py-2">Previous revenue</th>
                    <th className="px-2 py-2">Revenue change</th><th className="px-2 py-2">Order change</th><th className="px-2 py-2">Missing items</th>
                  </tr></thead>
                  <tbody>
                    {comparisonOutlets.map((row) => <tr key={row.customer_id} className="border-t">
                      <td className="px-2 py-2"><CustomerLink customerId={row.customer_id} customerName={row.customer_name}>{row.customer_name}</CustomerLink></td>
                      <td className="px-2 py-2"><RepresentativeNames representatives={row.representatives} /></td>
                      <td className="px-2 py-2">{row.sales_channel ?? channel}</td><td className="px-2 py-2">{row.region ?? "—"}</td>
                      <td className="px-2 py-2">{money(row.current_revenue)}</td><td className="px-2 py-2">{money(row.comparison_revenue)}</td>
                      <td className={cn("px-2 py-2 font-semibold", row.revenue_change < 0 && "text-destructive")}>{money(row.revenue_change)}</td>
                      <td className={cn("px-2 py-2 font-semibold", row.order_change < 0 && "text-destructive")}>{row.order_change.toLocaleString()}</td>
                      <td className="max-w-[280px] px-2 py-2">
                        {row.missing_sku_count ? <><Badge variant="destructive">{row.missing_sku_count}</Badge><div className="mt-1 break-words text-[10px] text-muted-foreground">{row.missing_skus.join(", ")}</div></> : "—"}
                      </td>
                    </tr>)}
                  </tbody>
                </table>
              </div>
            </section>
          ) : null}
        </>
      )}
    </div>
  );
}

function CustomerRow({ row }: { row: SalesIntelligenceCustomer }) {
  return (
    <tr className="border-t hover:bg-muted/20">
      <td className="px-2 py-2">
        <CustomerLink customerId={row.customer_id} customerName={row.customer_name} className="font-semibold">
          {row.customer_name}
        </CustomerLink>
        <div className="font-mono text-[10px] text-muted-foreground">{row.customer_id}</div>
      </td>
      <td className="px-2 py-2 tabular-nums">{row.so_count.toLocaleString()}</td>
      <td className="px-2 py-2">
        {row.open_so_count > 0 ? (
          <Badge variant="secondary" className="tabular-nums">
            {row.open_so_count}
          </Badge>
        ) : (
          <span className="tabular-nums text-muted-foreground">0</span>
        )}
      </td>
      <td className="px-2 py-2 tabular-nums">{money(row.gross_revenue)}</td>
      <td className="px-2 py-2 tabular-nums text-muted-foreground">{money(row.credit_revenue)}</td>
      <td className="px-2 py-2 font-semibold tabular-nums">{money(row.net_revenue)}</td>
      <td className="px-2 py-2 tabular-nums">{row.net_volume.toLocaleString()}</td>
      <td className="px-2 py-2 whitespace-nowrap tabular-nums">
        {row.last_order_date ?? "—"}
      </td>
      <td className="px-2 py-2"><RepresentativeNames representatives={row.representatives} /></td>
      <td className="px-2 py-2">
        <Button asChild size="sm" variant="outline" className="h-7 text-[10px]">
          <Link to="/app/customer-orders/$customerId" params={{ customerId: row.customer_id }}>
            Orders
          </Link>
        </Button>
      </td>
    </tr>
  );
}

function RepresentativeNames({ representatives }: { representatives: SalesIntelligenceCustomer["representatives"] }) {
  if (!representatives.length) return <span className="text-muted-foreground">Unassigned</span>;

  return (
    <div className="space-y-0.5">
      {representatives.map((representative) => (
        <div key={representative.id} className="whitespace-nowrap">
          {representative.name}
          {representative.rep_code ? <span className="ml-1 font-mono text-[10px] text-muted-foreground">({representative.rep_code})</span> : null}
        </div>
      ))}
    </div>
  );
}

function Metric({
  icon: Icon,
  label,
  value,
}: {
  icon: typeof Building2;
  label: string;
  value: string;
}) {
  return (
    <div className="min-h-24 border bg-card p-3">
      <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
        <Icon className="h-4 w-4" />
        {label}
      </div>
      <p className="mt-3 text-lg font-semibold tabular-nums">{value}</p>
    </div>
  );
}

function money(value: number) {
  return `KES ${Number(value || 0).toLocaleString()}`;
}
