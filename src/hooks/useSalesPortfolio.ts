import { useQuery } from "@tanstack/react-query";
import { apiFetch, ApiError } from "@/lib/api";

export type PortfolioMode = "auto" | "self" | "team";

export type PortfolioSummary = {
  scope: {
    mode: string;
    rep_code: string | null;
    customer_count: number;
    can_toggle_team: boolean;
    has_sales_book: boolean;
    empty_portfolio: boolean;
    empty_hint: string | null;
  };
  windows: {
    timezone: string;
    month: string;
    month_start: string;
    month_label: string;
    is_current_month: boolean;
    active_from: string;
    as_of: string;
    dormant_tooltip: string;
  };
  compare: {
    mode: "mom" | "yoy";
    label: string;
    prior_month_label: string;
  };
  kpis: {
    customers_total: number;
    customers_active: number;
    customers_dormant: number;
    revenue_mtd: number;
    revenue_prior_comparable: number;
    revenue_change_pct: number | null;
    target: number | null;
    time_gone_pct: number;
    working_days_elapsed: number;
    working_days_total: number;
    expected_revenue_to_date: number | null;
    variance_to_pace: number | null;
    orders_count: number;
    orders_open: number;
    orders_completed: number;
    backorder_lines: number;
    backorder_revenue_at_risk: number;
    fill_rate_pct: number | null;
    predicted_remaining_orders: number;
    predicted_remaining_value: number;
  };
  top_movers: {
    top_customers: Array<{
      customer_id: string;
      customer_name: string;
      revenue: number;
      orders: number;
      change_pct: number | null;
      trend: "up" | "down" | "flat";
    }>;
    declining: Array<{
      customer_id: string;
      customer_name: string;
      last_order_date: string;
      label: string;
    }>;
    concentration_top3_pct: number | null;
  };
  plain_language: string;
};

/** Map legacy kp/accounts/summary shape → portfolio KPI cards. */
function mapLegacySummary(raw: Record<string, unknown>): PortfolioSummary {
  const period = (raw.period ?? {}) as Record<string, string>;
  const customers = (raw.customers ?? {}) as Record<string, number>;
  const sales = (raw.sales ?? {}) as Record<string, number | null>;
  const target = (raw.target ?? {}) as Record<string, number | null>;
  const backorders = (raw.backorders ?? {}) as Record<string, number>;
  const fill = (raw.fill_rate ?? {}) as Record<string, number | null>;
  const top = (raw.top_customers ?? []) as Array<Record<string, unknown>>;

  const total = Number(customers.total ?? 0);
  const active = Number(customers.active ?? 0);
  const dormant = Number(customers.dormant ?? Math.max(0, total - active));
  const revenue = Number(sales.revenue ?? 0);
  const prior = Number(sales.prior_revenue ?? 0);
  const targetAmt = target.amount != null ? Number(target.amount) : null;
  const variance = target.variance != null ? Number(target.variance) : null;
  const timeGone = Number(target.time_gone_pct ?? 0);

  return {
    scope: {
      mode: String(raw.scope ?? "scoped"),
      rep_code: null,
      customer_count: total,
      can_toggle_team: false,
      has_sales_book: total > 0,
      empty_portfolio: total === 0,
      empty_hint:
        total === 0
          ? "No customers in your portfolio yet. Ask Sales Ops to set your rep code or assign customers."
          : null,
    },
    windows: {
      timezone: period.timezone ?? "Africa/Nairobi",
      month: period.month ?? (period.from ?? "").slice(0, 7),
      month_start: period.from ?? "",
      month_label: period.label ?? "This month",
      is_current_month: true,
      active_from: period.active_cutoff ?? "",
      as_of: new Date().toISOString(),
      dormant_tooltip: `No sales order since ${period.active_cutoff ?? "the active cut-off"}.`,
    },
    compare: {
      mode: "mom",
      label: "vs previous month",
      prior_month_label: "",
    },
    kpis: {
      customers_total: total,
      customers_active: active,
      customers_dormant: dormant,
      revenue_mtd: revenue,
      revenue_prior_comparable: prior,
      revenue_change_pct: sales.change_pct != null ? Number(sales.change_pct) : null,
      target: targetAmt,
      time_gone_pct: timeGone,
      working_days_elapsed: 0,
      working_days_total: 0,
      expected_revenue_to_date:
        target.expected_to_date != null ? Number(target.expected_to_date) : null,
      variance_to_pace: variance,
      orders_count: Number(sales.orders ?? 0),
      orders_open: 0,
      orders_completed: 0,
      backorder_lines: Number(backorders.lines ?? 0),
      backorder_revenue_at_risk: Number(backorders.value_at_risk ?? 0),
      fill_rate_pct: fill.percentage != null ? Number(fill.percentage) : null,
      predicted_remaining_orders: 0,
      predicted_remaining_value: 0,
    },
    top_movers: {
      top_customers: top.map((c) => ({
        customer_id: String(c.customer_id ?? ""),
        customer_name: String(c.customer_name ?? c.customer_id ?? ""),
        revenue: Number(c.revenue ?? 0),
        orders: Number(c.orders ?? 0),
        change_pct: null,
        trend: "flat" as const,
      })),
      declining: [],
      concentration_top3_pct: null,
    },
    plain_language: `You have ${active} customers actively buying and ${dormant} dormant. ${(period.label ?? "This month")} revenue so far: KES ${revenue.toLocaleString()}.`,
  };
}

export type PortfolioCompareMode = "mom" | "yoy";

async function fetchPortfolioSummary(
  mode: PortfolioMode,
  month?: string,
  compare: PortfolioCompareMode = "mom",
): Promise<PortfolioSummary> {
  const params = new URLSearchParams({ mode });
  if (month) params.set("month", month);
  if (compare) params.set("compare", compare);

  try {
    return await apiFetch<PortfolioSummary>(`sales/portfolio/summary?${params.toString()}`);
  } catch (err) {
    // Backend may not have sales/portfolio yet — fall back to kp/accounts/summary
    const status = err instanceof ApiError ? err.status : 0;
    if (status === 404 || status === 500 || status === 0) {
      try {
        const legacy = await apiFetch<Record<string, unknown>>("kp/accounts/summary");
        return mapLegacySummary(legacy);
      } catch {
        throw err;
      }
    }
    throw err;
  }
}

export function usePortfolioSummary(
  mode: PortfolioMode = "auto",
  month?: string,
  compare: PortfolioCompareMode = "mom",
) {
  return useQuery({
    queryKey: ["sales-portfolio-summary", mode, month ?? "current", compare],
    queryFn: () => fetchPortfolioSummary(mode, month, compare),
    staleTime: 60_000,
    retry: 1,
  });
}

export type PortfolioOrderRow = {
  id: number;
  order_nbr: string;
  order_date: string;
  customer_id: string;
  customer_name: string;
  status: string | null;
  order_total: number;
  rep_code: string | null;
};

export type PortfolioOrderGroup = {
  customer_id: string;
  customer_name: string;
  order_count: number;
  total_value: number;
  label: string;
  orders: PortfolioOrderRow[];
};

export type PortfolioBackorderRow = {
  id: number;
  order_nbr: string;
  inventory_id: string;
  customer_id: string;
  customer_name: string;
  open_qty: number;
  unit_price: number;
  revenue_at_risk: number;
  fulfillment_status: string | null;
  reason_code: string | null;
};

export type PortfolioBackorderGroup = {
  customer_id: string;
  customer_name: string;
  line_count: number;
  revenue_at_risk: number;
  label: string;
  lines: PortfolioBackorderRow[];
};

function groupOrdersClient(
  rows: PortfolioOrderRow[],
): PortfolioOrderGroup[] {
  const map = new Map<string, PortfolioOrderGroup>();
  for (const o of rows) {
    const cid = o.customer_id || "unknown";
    if (!map.has(cid)) {
      map.set(cid, {
        customer_id: cid,
        customer_name: o.customer_name || cid,
        order_count: 0,
        total_value: 0,
        label: "",
        orders: [],
      });
    }
    const g = map.get(cid)!;
    g.orders.push(o);
    g.order_count++;
    g.total_value += Number(o.order_total) || 0;
  }
  return [...map.values()]
    .map((g) => ({
      ...g,
      label: `${g.customer_name} · ${g.order_count} ${g.order_count === 1 ? "order" : "orders"}`,
    }))
    .sort((a, b) => b.total_value - a.total_value);
}

function groupBackordersClient(
  rows: PortfolioBackorderRow[],
): PortfolioBackorderGroup[] {
  const map = new Map<string, PortfolioBackorderGroup>();
  for (const b of rows) {
    const cid = b.customer_id || "unknown";
    if (!map.has(cid)) {
      map.set(cid, {
        customer_id: cid,
        customer_name: b.customer_name || cid,
        line_count: 0,
        revenue_at_risk: 0,
        label: "",
        lines: [],
      });
    }
    const g = map.get(cid)!;
    g.lines.push(b);
    g.line_count++;
    g.revenue_at_risk += Number(b.revenue_at_risk) || 0;
  }
  return [...map.values()]
    .map((g) => ({
      ...g,
      label: `${g.customer_name} · ${g.line_count} ${g.line_count === 1 ? "line" : "lines"}`,
    }))
    .sort((a, b) => b.revenue_at_risk - a.revenue_at_risk);
}

export function usePortfolioOrders(mode: PortfolioMode, page = 1) {
  return useQuery({
    queryKey: ["sales-portfolio-orders", mode, page],
    queryFn: async () => {
      const res = await apiFetch<{
        data: PortfolioOrderRow[];
        grouped?: PortfolioOrderGroup[];
        total: number;
        order_count?: number;
        current_page: number;
        last_page: number;
        hint?: string;
      }>(`sales/portfolio/orders?mode=${mode}&page=${page}&per_page=40`);
      const grouped =
        res.grouped?.length ? res.grouped : groupOrdersClient(res.data ?? []);
      return { ...res, grouped };
    },
    retry: 1,
  });
}

export function usePortfolioBackorders(mode: PortfolioMode, page = 1) {
  return useQuery({
    queryKey: ["sales-portfolio-backorders", mode, page],
    queryFn: async () => {
      const res = await apiFetch<{
        data: PortfolioBackorderRow[];
        grouped?: PortfolioBackorderGroup[];
        total: number;
        line_count?: number;
        revenue_at_risk: number;
        current_page: number;
        last_page: number;
        hint?: string;
      }>(`sales/portfolio/backorders?mode=${mode}&page=${page}&per_page=40`);
      const grouped =
        res.grouped?.length ? res.grouped : groupBackordersClient(res.data ?? []);
      return { ...res, grouped };
    },
    retry: 1,
  });
}

export type PortfolioItemGroup = {
  customer_id: string;
  customer_name: string;
  item_count: number;
  label?: string;
  opportunity_value?: number;
  items: Array<{
    inventory_id: string;
    description: string | null;
    last_order_date?: string | null;
    days_since_order?: number | null;
    days_overdue?: number;
    order_times?: number;
    order_count?: number;
    avg_value?: number | null;
    opportunity_value?: number;
    avg_interval_days?: number;
    risk?: string;
  }>;
};

export function usePortfolioItemsNotOrdered(mode: PortfolioMode) {
  return useQuery({
    queryKey: ["sales-portfolio-ino", mode],
    queryFn: async () => {
      try {
        return await apiFetch<{
          data: PortfolioItemGroup[];
          grouped?: PortfolioItemGroup[];
          summary: { customer_count: number; item_count: number; days_overdue?: number };
          hint?: string;
        }>(`sales/portfolio/items-not-ordered?mode=${mode}&days=60`);
      } catch {
        // Fall back to KP items-not-ordered and group client-side
        const raw = await apiFetch<{
          data: Array<Record<string, unknown>>;
          grouped?: PortfolioItemGroup[];
          summary: { customer_count: number; item_count: number };
        }>("kp/items-not-ordered?bucket=60");
        if (raw.grouped?.length) {
          return {
            data: raw.grouped,
            grouped: raw.grouped,
            summary: raw.summary,
            hint: "Grouped by customer. Expand to see SKUs.",
          };
        }
        const map = new Map<string, PortfolioItemGroup>();
        for (const row of raw.data ?? []) {
          const cid = String(row.customer_id ?? "");
          const name = String(row.customer_name ?? cid);
          if (!map.has(cid)) {
            map.set(cid, {
              customer_id: cid,
              customer_name: name,
              item_count: 0,
              label: "",
              items: [],
            });
          }
          const g = map.get(cid)!;
          g.item_count++;
          g.items.push({
            inventory_id: String(row.inventory_id ?? ""),
            description: (row.description as string) ?? null,
            last_order_date: (row.last_order_date as string) ?? null,
            days_overdue: Number(row.days_overdue ?? 0),
            days_since_order: Number(row.days_overdue ?? 0),
            order_count: Number(row.order_count ?? 0),
            avg_value: row.opportunity_value != null ? Number(row.opportunity_value) : null,
            opportunity_value: Number(row.opportunity_value ?? 0),
            avg_interval_days: Number(row.avg_interval_days ?? 0),
            risk: String(row.risk ?? ""),
          });
        }
        const groups = [...map.values()].map((g) => ({
          ...g,
          label: `${g.customer_name} · ${g.item_count} ${g.item_count === 1 ? "item" : "items"}`,
        }));
        groups.sort((a, b) => b.item_count - a.item_count);
        return {
          data: groups,
          grouped: groups,
          summary: raw.summary,
          hint: "Grouped by customer. Expand a row to see SKUs not ordered recently.",
        };
      }
    },
    retry: 1,
  });
}
