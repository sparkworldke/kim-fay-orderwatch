import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

type Paginated<T> = {
  data: T[];
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
};

export type ContributionRow = {
  contribution_pct: number;
  line_count?: number;
  count?: number;
  order_count?: number;
  [key: string]: string | number | null | undefined;
};

/** Metrics for a single KP/CS segment bucket. */
export type FillRateSegmentBucket = {
  fill_rate_pct: number | null;
  status: string;
  order_count: number;
  total_ordered_qty: number;
  total_shipped_qty: number;
  revenue_not_shipped: number;
  healthy_count: number;
  at_risk_count: number;
  critical_count: number;
};

/** KP / CS segment split as returned by fillRateSummary(). */
export type FillRateSegmentSplit = {
  KP: FillRateSegmentBucket;
  CS: FillRateSegmentBucket;
};

/** Per-segment summary row used in the Excel export (by_segment). */
export type FillRateSegmentRow = {
  segment: string;
  label: string;
  order_count: number;
  total_ordered_qty: number;
  total_shipped_qty: number;
  fill_rate_pct: number | null;
  status: string;
  revenue_not_shipped: number;
  healthy_count: number;
  at_risk_count: number;
  critical_count: number;
};

/** Per-segment root-cause row used in the Excel export (by_segment_reason). */
export type FillRateSegmentReasonRow = {
  segment: string;
  reason: string;
  undershipped_value: number;
  contribution_pct: number;
};

export type FillRateBusinessCategoryRow = {
  business_category: string;
  label: string;
  line_count: number;
  order_count: number;
  ordered_qty: number;
  shipped_qty: number;
  undershipped_value: number;
  fill_rate_pct: number | null;
};

export type FillRateReasonCaptureSummary = {
  total_shortfall_lines: number;
  total_shortfall_orders: number;
  valid_reason_lines: number;
  missing_reason_lines: number;
  unclassified_reason_lines: number;
  capture_rate_pct: number | null;
};

export type FillRateReasonBreakdownRow = {
  business_category: string;
  parent_reason: string;
  parent_reason_code: string | null;
  sub_reason: string;
  sub_reason_label: string;
  line_count: number;
  order_count: number;
  undershipped_value: number;
};

export type FillRateFlaggedRecord = {
  order_nbr: string;
  customer_acumatica_id?: string | null;
  inventory_id: string;
  reason_code: string | null;
  issue: "missing" | "unclassified";
  business_category: string;
  undershipped_value: number;
};

export type FillRateReasonCaptureReport = {
  summary: FillRateReasonCaptureSummary;
  by_business_category: Record<string, {
    business_category: string;
    label: string;
    line_count: number;
    order_count: number;
    undershipped_value: number;
    valid_reason_lines: number;
    missing_reason_lines: number;
    unclassified_reason_lines: number;
  }>;
  breakdown: FillRateReasonBreakdownRow[];
  flagged_records: FillRateFlaggedRecord[];
};

export type FillRateExcelSummary = {
  totals: {
    actual_qty: number;
    ordered_qty: number;
    undershipped_qty: number;
    undershipped_value: number;
    fill_rate_pct: number | null;
    order_count: number;
  };
  by_status: ContributionRow[];
  by_reason: ContributionRow[];
  by_department: ContributionRow[];
  by_customer_group: ContributionRow[];
  top_customers: ContributionRow[];
  top_products: ContributionRow[];
  by_segment: FillRateSegmentRow[];
  by_segment_reason: FillRateSegmentReasonRow[];
  by_business_category: FillRateBusinessCategoryRow[];
  reason_capture_report: FillRateReasonCaptureReport;
};

export type BackorderBusinessCategoryRow = {
  business_category: string;
  label: string;
  line_count: number;
  order_count: number;
  open_qty: number;
  back_order_value: number;
};

export type BackordersExcelSummary = {
  totals: {
    back_order_qty: number;
    back_order_value: number;
    line_count: number;
    order_count: number;
  };
  by_reason: ContributionRow[];
  by_department: ContributionRow[];
  by_customer_group: ContributionRow[];
  top_customers: ContributionRow[];
  top_products: ContributionRow[];
  by_business_category?: BackorderBusinessCategoryRow[];
};

export type BusinessCategorySkuRow = {
  inventory_id: string;
  product_name: string | null;
  brand: string | null;
  posting_class: string | null;
  sub_trading_group: string | null;
  supplier: string | null;
  business_category: string;
  business_category_label: string;
  line_count: number;
  order_count: number;
  ordered_qty?: number;
  shipped_qty?: number;
  undershipped_qty?: number;
  undershipped_value?: number;
  fill_rate_pct?: number | null;
  open_qty?: number;
  back_order_value?: number;
};

export type BusinessCategorySkuBreakdown = {
  business_category: string;
  label: string;
  date_from?: string;
  date_to?: string;
  sku_count: number;
  line_count: number;
  order_count: number;
  undershipped_value?: number;
  fill_rate_pct?: number | null;
  open_qty?: number;
  back_order_value?: number;
  skus: BusinessCategorySkuRow[];
};

export type InventoryPrediction = {
  daily_run_rate: number | null;
  days_until_stockout: number | null;
  prediction_status: string;
  qty_delta: number | null;
  logged_at: string;
};

export type InventoryItem = {
  id: number;
  inventory_id: string;
  description: string | null;
  item_class: string | null;
  posting_class: string | null;
  brand: string | null;
  product_type: "manufactured" | "trading";
  item_group: string | null;
  sub_item_group: string | null;
  trading_group: string | null;
  sub_trading_group: string | null;
  conversion_factor: number | null;
  profit_margin_target: string | null;
  supplier: string | null;
  default_uom: string | null;
  default_warehouse_id: string | null;
  qty_on_hand: string;
  qty_available: string | null;
  sales_price: string;
  synced_at: string | null;
  prediction: InventoryPrediction | null;
};

export type BackorderLine = {
  id: number;
  order_nbr: string;
  inventory_id: string;
  product_name: string | null;
  brand?: string | null;
  posting_class?: string | null;
  sub_trading_group?: string | null;
  supplier?: string | null;
  product_line: string | null;
  uom: string | null;
  qty_on_hand: string | null;
  qty_available: string | null;
  stock_shortfall: boolean;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  order_qty: string;
  shipped_qty: string;
  open_qty: string;
  backorder_qty: string;
  cancelled_qty: string;
  qty_at_approval: string | null;
  fulfillment_status: string | null;
  unit_price: string;
  revenue_at_risk: string;
  warehouse_id: string | null;
  lead_time_days: number | null;
  reason_code: string | null;
  reason_notes: string | null;
  reason_updated_at: string | null;
  currency_id: string | null;
  synced_at: string | null;
};

export type BackordersAnalytics = {
  summary: {
    open_lines: number;
    open_orders: number;
    revenue_at_risk: number;
    total_open_qty: number;
  };
  excel_summary: BackordersExcelSummary;
  filters: {
    product_lines: string[];
    customer_groups: string[];
    departments: string[];
    warehouse_ids: string[];
    reason_codes: string[];
  };
  charts: {
    trend: Array<{
      bucket_date: string;
      line_count: number;
      order_count: number;
      open_qty: number;
      revenue_at_risk: number;
    }>;
    lead_time_correlation: Array<{
      lead_time_bucket: string;
      line_count: number;
      avg_lead_time_days: number | null;
      revenue_at_risk: number;
      open_qty: number;
    }>;
    category_distribution: Array<{
      product_line: string;
      line_count: number;
      revenue_at_risk: number;
    }>;
    reason_distribution: Array<{
      reason_code: string;
      line_count: number;
      revenue_at_risk: number;
    }>;
    customer_group_distribution: ContributionRow[];
    department_distribution: ContributionRow[];
    customer_distribution: ContributionRow[];
    product_distribution: ContributionRow[];
  };
};

export type FillRateProduct = {
  inventory_id: string;
  product_name: string | null;
  brand?: string | null;
  posting_class?: string | null;
  sub_trading_group?: string | null;
  supplier?: string | null;
  order_qty: string;
  shipped_qty: string;
  qty_on_shipments: string;
  open_qty: string;
  uom: string | null;
  unit_price: string;
  line_fill_rate_pct: string | null;
  unfilled_reason_code: string | null;
  not_shipped_value: string;
};

export type DeliverySlaStatus = "ok" | "warning" | "breach" | "unknown";

export type FillRateSnapshot = {
  id: number;
  order_nbr: string;
  order_description?: string | null;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  status: string | null;
  total_ordered_qty: string;
  total_shipped_qty: string;
  fill_rate_pct: string | null;
  fill_rate_status: string;
  revenue_not_shipped: string;
  currency_id: string | null;
  computed_at: string | null;
  delivery_hours?: number | null;
  sla_hours?: number | null;
  sla_warning_hours?: number | null;
  delivery_sla_status?: DeliverySlaStatus;
  delivery_sla_label?: string;
  shipping_zone_id?: string | null;
  shipping_zone_description?: string | null;
  is_metro_zone?: boolean;
  products?: FillRateProduct[];
  order?: {
    id: number;
    acumatica_order_nbr: string;
    customer_acumatica_id?: string | null;
    customer_name: string | null;
    order_date: string | null;
  };
};

export type InventoryWarehouseOption = {
  warehouse_id: string;
  label?: string;
  sku_count: number;
  configured?: boolean;
};

/** Stockout prediction tab filter values (backend stockout_filter). */
export type InventoryStockoutFilter =
  | "critical_or_oos"
  | "critical"
  | "out_of_stock"
  | "at_risk";

export function useInventorySummary() {
  return useQuery({
    queryKey: ["operations-inventory-summary"],
    queryFn: () => apiFetch<{
      total_items: number;
      low_stock_count: number;
      at_risk_count: number;
      out_of_stock_count?: number;
      critical_stockout_count?: number;
      last_synced_at: string | null;
      warehouse_ids: string[];
      warehouse_counts: InventoryWarehouseOption[];
      warehouses?: InventoryWarehouseOption[];
      brands: string[];
      manufactured_count: number;
      trading_count: number;
    }>("operations/inventory/summary"),
  });
}

export function useInventory(params: {
  q?: string;
  low_stock?: boolean;
  warehouse_id?: string[];
  prediction_status?: string;
  /** Stockout risk tab: critical prediction and/or zero stock. */
  stockout_filter?: InventoryStockoutFilter;
  product_type?: string;
  partner_brand?: string;
  brand?: string;
  category?: string;
  page?: number;
  per_page?: number;
}) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.low_stock) qs.set("low_stock", "1");
  for (const warehouse of params.warehouse_id ?? []) qs.append("warehouse_id[]", warehouse);
  if (params.prediction_status) qs.set("prediction_status", params.prediction_status);
  if (params.stockout_filter) qs.set("stockout_filter", params.stockout_filter);
  if (params.product_type) qs.set("product_type", params.product_type);
  if (params.partner_brand) qs.set("partner_brand", params.partner_brand);
  if (params.brand) qs.set("brand", params.brand);
  if (params.category) qs.set("category", params.category);
  qs.set("page", String(params.page ?? 1));
  qs.set("per_page", String(params.per_page ?? 50));

  return useQuery({
    queryKey: ["operations-inventory", params],
    queryFn: () => apiFetch<Paginated<InventoryItem>>(`operations/inventory?${qs}`),
  });
}

export function useBackordersSummary() {
  return useQuery({
    queryKey: ["operations-backorders-summary"],
    queryFn: () => apiFetch<{
      open_lines: number;
      open_orders: number;
      revenue_at_risk: number;
      total_open_qty: number;
      last_synced_at: string | null;
    }>("operations/backorders/summary"),
  });
}

export function useBackorders(params: {
  q?: string;
  customer_id?: string;
  customer_group?: string;
  date_from?: string;
  date_to?: string;
  product_line?: string;
  warehouse_id?: string;
  reason_code?: string;
  partner_brand?: string;
  brand?: string;
  category?: string;
  page?: number;
  per_page?: number;
}) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.customer_id) qs.set("customer_id", params.customer_id);
  if (params.customer_group) qs.set("customer_group", params.customer_group);
  if (params.date_from) qs.set("date_from", params.date_from);
  if (params.date_to) qs.set("date_to", params.date_to);
  if (params.product_line) qs.set("product_line", params.product_line);
  if (params.warehouse_id) qs.set("warehouse_id", params.warehouse_id);
  if (params.reason_code) qs.set("reason_code", params.reason_code);
  if (params.partner_brand) qs.set("partner_brand", params.partner_brand);
  if (params.brand) qs.set("brand", params.brand);
  if (params.category) qs.set("category", params.category);
  qs.set("page", String(params.page ?? 1));
  qs.set("per_page", String(params.per_page ?? 50));

  return useQuery({
    queryKey: ["operations-backorders", params],
    queryFn: () => apiFetch<Paginated<BackorderLine>>(`operations/backorders?${qs}`),
  });
}

export function useBackordersAnalytics(params: {
  date_from?: string;
  date_to?: string;
  product_line?: string;
  customer_group?: string;
  warehouse_id?: string;
  reason_code?: string;
  partner_brand?: string;
  brand?: string;
  category?: string;
}) {
  const qs = new URLSearchParams();
  if (params.date_from) qs.set("date_from", params.date_from);
  if (params.date_to) qs.set("date_to", params.date_to);
  if (params.product_line) qs.set("product_line", params.product_line);
  if (params.customer_group) qs.set("customer_group", params.customer_group);
  if (params.warehouse_id) qs.set("warehouse_id", params.warehouse_id);
  if (params.reason_code) qs.set("reason_code", params.reason_code);
  if (params.partner_brand) qs.set("partner_brand", params.partner_brand);
  if (params.brand) qs.set("brand", params.brand);
  if (params.category) qs.set("category", params.category);

  return useQuery({
    queryKey: ["operations-backorders-analytics", params],
    queryFn: () => apiFetch<BackordersAnalytics>(`operations/backorders/analytics?${qs}`),
  });
}

export function useBackordersByAccount(top = 10) {
  return useQuery({
    queryKey: ["operations-backorders-accounts", top],
    queryFn: () => apiFetch<{ accounts: Array<{
      customer_acumatica_id: string;
      customer_name: string | null;
      order_count: number;
      open_lines: number;
      revenue_at_risk: string;
      total_open_qty: string;
    }> }>(`operations/backorders/by-account?top=${top}`),
  });
}

export function useBusinessCategorySkuBreakdown(
  module: "fill-rate" | "backorders",
  businessCategory: "manufactured" | "trading",
  filters: Record<string, string | undefined> = {},
  enabled = true,
) {
  const qs = new URLSearchParams();
  qs.set("business_category", businessCategory);
  Object.entries(filters).forEach(([key, value]) => {
    if (value != null && value !== "") qs.set(key, value);
  });

  return useQuery({
    queryKey: ["operations-business-category-skus", module, businessCategory, filters],
    queryFn: () =>
      apiFetch<BusinessCategorySkuBreakdown>(
        `operations/${module}/sku-breakdown?${qs}`,
      ),
    enabled,
  });
}

export function useFillRateOutOfStockReport(
  params: {
    date_from?: string;
    date_to?: string;
    brand?: string;
    business_category?: string;
    partner_brand?: string;
    customer_group?: string;
    segment?: string;
    shipping_zone_id?: string;
  },
  enabled = true,
) {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value != null && value !== "") qs.set(key, value);
  });

  return useQuery({
    queryKey: ["operations-fill-rate-oos", params],
    queryFn: () =>
      apiFetch<FillRateOutOfStockReport>(`operations/fill-rate/out-of-stock?${qs}`),
    enabled,
  });
}

export type ReasonTaxonomySubReason = {
  code: string;
  label: string;
  hierarchical_label: string;
};

export type ReasonTaxonomyParent = {
  code: string;
  label: string;
  sub_reasons: ReasonTaxonomySubReason[];
};

export type ReasonTaxonomy = {
  parents: ReasonTaxonomyParent[];
  sub_reasons: { code: string; label: string }[];
  source: "database" | "catalog_constants" | string;
};

export function useReasonTaxonomy() {
  return useQuery({
    queryKey: ["operations-reason-taxonomy"],
    queryFn: () => apiFetch<ReasonTaxonomy>("operations/reason-taxonomy"),
    staleTime: 1000 * 60 * 60,
  });
}

export function useUpdateBackorderReason() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: ({
      id,
      reason_code,
      reason_notes,
    }: {
      id: number;
      reason_code: string | null;
      reason_notes: string | null;
    }) =>
      apiFetch<BackorderLine>(`operations/backorders/${id}`, {
        method: "PATCH",
        body: { reason_code, reason_notes },
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["operations-backorders"] });
      qc.invalidateQueries({ queryKey: ["operations-backorders-analytics"] });
      qc.invalidateQueries({ queryKey: ["operations-backorders-summary"] });
      qc.invalidateQueries({ queryKey: ["operations-backorders-accounts"] });
    },
  });
}

export type OperationsStatus = {
  last_inventory_sync_at: string | null;
  last_inventory_sync_type: string | null;
  last_backorder_sync_at: string | null;
  last_fill_rate_sync_at: string | null;
  last_fill_rate_computed_at: string | null;
  inventory_stale: boolean;
  backorders_stale: boolean;
  fill_rate_stale: boolean;
};

export type ExecutiveAlert = {
  severity: "critical" | "warning" | "info";
  category: string;
  message: string;
};

export type DeliverySlaZoneImpact = {
  acumatica_id: string | null;
  name: string;
  region: string | null;
  total_orders: number;
  delayed_orders: number;
  warning_orders: number;
  delayed_pct: number;
  delayed_value: number;
  avg_delay_hours: number | null;
  primary_reason: string | null;
  meets_min_sample: boolean;
  alert_triggered: boolean;
};

export type DeliverySlaDelayedOrder = {
  order_nbr: string;
  customer_name: string | null;
  customer_acumatica_id: string | null;
  shipping_zone_id: string | null;
  shipping_zone_name: string | null;
  shipping_zone_region: string | null;
  region_key: string | null;
  order_value: number;
  delivery_hours: number | null;
  delivery_sla_status: string;
  delivery_sla_label: string;
  sla_hours: number | null;
  primary_reason: string | null;
  order_date: string | null;
};

export type BusinessOptimizationData = {
  date_from: string;
  date_to: string;
  ops_status: OperationsStatus;
  delivery_sla?: {
    rules: {
      clock_start: string;
      clock_start_label: string;
      metro_sla_hours: number;
      regional_warning_hours: number;
      regional_breach_hours: number;
      regions: Array<{
        region_key: string;
        label: string;
        sla_hours: number;
        warning_hours: number | null;
        breach_hours: number;
        is_metro: boolean;
        alert_min_orders: number;
        alert_delayed_pct: number;
        clock_start: string;
      }>;
    };
    summary: {
      total_orders: number;
      on_time_count: number;
      on_time_pct: number | null;
      warning_count: number;
      delayed_count: number;
      delayed_pct: number | null;
      delayed_value: number;
      unknown_count: number;
      avg_delivery_hours: number | null;
    };
    by_region: Array<{
      region_key: string;
      label: string;
      total_orders: number;
      on_time_orders: number;
      warning_orders: number;
      delayed_orders: number;
      on_time_pct: number | null;
      delayed_pct: number | null;
      delayed_value: number;
      avg_delivery_hours: number | null;
      sla_hours: number;
    }>;
    most_affected_zones: DeliverySlaZoneImpact[];
    daily_trend: Array<{ day: string; total: number; delayed: number; on_time: number }>;
    delayed_orders: DeliverySlaDelayedOrder[];
  };
  zone_guardrails?: {
    unmapped_customer_count: number;
    unmapped_with_orders_in_period: number;
    alert_min_orders: number;
    alert_delayed_pct: number;
    date_from: string;
    date_to: string;
  };
  filters?: {
    shipping_zones: Array<{ acumatica_id: string; description: string | null; name: string | null; region: string | null }>;
    selected_shipping_zone_id: string | null;
    selected_region: string | null;
    region_options: Array<{ value: string; label: string }>;
  };
  customer_focus: {
    top_by_revenue: Array<{
      customer_acumatica_id: string;
      customer_name: string | null;
      open_lines: number;
      open_orders: number;
      total_open_qty: number;
      revenue_at_risk: number;
    }>;
    top_by_fill_rate_risk: Array<{
      customer_acumatica_id: string;
      customer_name: string | null;
      order_count: number;
      critical_orders: number;
      at_risk_orders: number;
      revenue_not_shipped: number;
      avg_fill_rate_pct: number;
    }>;
    total_customers_at_risk: number;
    top_customer_concentration_pct: number | null;
  };
  product_focus: {
    top_by_revenue: Array<{
      inventory_id: string;
      product_name: string | null;
      open_lines: number;
      total_open_qty: number;
      revenue_at_risk: number;
      qty_on_hand: number | null;
      stock_shortfall: boolean;
    }>;
    stock_shortfall_skus: Array<{
      inventory_id: string;
      product_name: string | null;
      open_lines: number;
      total_open_qty: number;
      revenue_at_risk: number;
      qty_on_hand: number | null;
      stock_shortfall: boolean;
    }>;
    shortfall_count: number;
  };
  production_forecast: {
    at_risk_items: Array<{
      inventory_id: string;
      product_name: string | null;
      qty_on_hand: number;
      daily_run_rate: number | null;
      days_until_stockout: number | null;
      prediction_status: string;
    }>;
    critical_count: number;
    at_risk_count: number;
    zero_stock_skus: number;
  };
  revenue_bleeding: {
    backorder_revenue_at_risk: number;
    fill_rate_not_shipped: number;
    fill_rate_critical_not_shipped: number;
    combined_exposure: number;
    open_backorder_lines: number;
    orders_below_80_pct: number;
    zero_qty_on_shipments_lines?: number;
    backorders_without_reason?: number;
  };
  executive_alerts: ExecutiveAlert[];
  charts: {
    backorders_by_customer: BusinessOptimizationData["customer_focus"]["top_by_revenue"];
    backorders_by_reason?: Array<{
      reason_code: string;
      line_count: number;
      revenue_at_risk: number;
      total_open_qty: number;
    }>;
    fill_rate_by_status: Array<{ status: string; count: number }>;
    fill_rate_unfilled_reasons?: Array<{
      reason_code: string;
      line_count: number;
      total_demand_qty: number;
      revenue_at_risk: number;
    }>;
    stockout_risk_products: BusinessOptimizationData["production_forecast"]["at_risk_items"];
    revenue_bleeding_split?: Array<{ label: string; value: number }>;
    backorders_by_customer_group?: ContributionRow[];
    backorders_by_department?: ContributionRow[];
    fill_rate_by_customer_group?: ContributionRow[];
  };
  excel_summary?: {
    fill_rate: FillRateExcelSummary;
    backorders: BackordersExcelSummary;
  };
};

export function useOperationsStatus() {
  return useQuery({
    queryKey: ["operations-status"],
    queryFn: () => apiFetch<OperationsStatus>("operations/status"),
    staleTime: 60_000,
  });
}

export function useBusinessOptimization(
  dateFrom: string,
  dateTo: string,
  shippingZoneId?: string,
  region?: string,
) {
  const qs = new URLSearchParams();
  qs.set("date_from", dateFrom);
  qs.set("date_to", dateTo);
  if (shippingZoneId) qs.set("shipping_zone_id", shippingZoneId);
  if (region && region !== "all") qs.set("region", region);

  return useQuery({
    queryKey: ["operations-business-optimization", dateFrom, dateTo, shippingZoneId, region],
    queryFn: () =>
      apiFetch<BusinessOptimizationData>(
        `operations/business-optimization?${qs}`,
      ),
  });
}

export type FillRateSummaryFilters = {
  shipping_zone_id?: string;
  customer_group?: string;
  product_line?: string;
  reason_code?: string;
  status?: string;
  partner_brand?: string;
  brand?: string;
  category?: string;
  /** KP/CS segment filter: "KP", "CS", or undefined for combined view. */
  segment?: "KP" | "CS";
  /** When false (default), fill rate excludes out-of-stock shortfall lines. */
  include_out_of_stock?: boolean;
};

export type FillRateOutOfStockCategoryRow = {
  business_category: string;
  label: string;
  line_count: number;
  order_count: number;
  sku_count: number;
  undershipped_qty: number;
  undershipped_value: number;
};

export type FillRateOutOfStockSkuRow = {
  inventory_id: string;
  product_name: string | null;
  brand: string | null;
  posting_class?: string | null;
  sub_trading_group?: string | null;
  supplier?: string | null;
  business_category: string;
  business_category_label: string;
  reason_code: string;
  reason_label: string;
  line_count: number;
  order_count: number;
  undershipped_qty: number;
  undershipped_value: number;
};

export type FillRateOutOfStockReport = {
  date_from: string;
  date_to: string;
  brand: string | null;
  business_category: string | null;
  totals: {
    line_count: number;
    order_count: number;
    sku_count: number;
    undershipped_qty: number;
    undershipped_value: number;
  };
  by_business_category: FillRateOutOfStockCategoryRow[];
  brands: string[];
  skus: FillRateOutOfStockSkuRow[];
};

export function useFillRateSummary(dateFrom: string, dateTo: string, filters: FillRateSummaryFilters = {}) {
  const qs = new URLSearchParams();
  qs.set("date_from", dateFrom);
  qs.set("date_to", dateTo);
  if (filters.shipping_zone_id) qs.set("shipping_zone_id", filters.shipping_zone_id);
  if (filters.customer_group) qs.set("customer_group", filters.customer_group);
  if (filters.product_line) qs.set("product_line", filters.product_line);
  if (filters.reason_code) qs.set("reason_code", filters.reason_code);
  if (filters.status) qs.set("status", filters.status);
  if (filters.segment) qs.set("segment", filters.segment);
  if (filters.partner_brand) qs.set("partner_brand", filters.partner_brand);
  if (filters.brand) qs.set("brand", filters.brand);
  if (filters.category) qs.set("category", filters.category);
  if (filters.include_out_of_stock != null) {
    qs.set("include_out_of_stock", filters.include_out_of_stock ? "1" : "0");
  }

  return useQuery({
    queryKey: ["operations-fill-rate-summary", dateFrom, dateTo, filters],
    queryFn: () => apiFetch<{
      date_from: string;
      date_to: string;
      include_out_of_stock?: boolean;
      overall_fill_rate: number | null;
      overall_status: string;
      segment_split: FillRateSegmentSplit;
      revenue_not_shipped: number;
      order_count: number;
      healthy_count: number;
      at_risk_count: number;
      critical_count: number;
      na_count: number;
      out_of_stock_line_count?: number;
      delivery_sla_breach_count: number;
      delivery_sla_warning_count: number;
      delivery_sla_rules: {
        metro_zones: string;
        metro_sla_hours: number;
        regional_warning_hours: number;
        regional_breach_hours: number;
      };
      last_computed_at: string | null;
      excel_summary: FillRateExcelSummary;
      filters: {
        customer_groups: string[];
        departments: string[];
        reason_codes: string[];
        product_lines: string[];
        shipping_zones: Array<{ acumatica_id: string; description: string | null; name: string | null; region: string | null }>;
      };
    }>(`operations/fill-rate/summary?${qs}`),
  });
}

export type FillRateSort = "high_to_low" | "low_to_high";

export function useFillRate(params: {
  q?: string;
  status?: string;
  date_from?: string;
  date_to?: string;
  customer_group?: string;
  product_line?: string;
  reason_code?: string;
  shipping_zone_id?: string;
  delivery_sla?: "breach" | "warning";
  partner_brand?: string;
  brand?: string;
  category?: string;
  /** KP/CS segment filter: "KP", "CS", or undefined for combined view. */
  segment?: "KP" | "CS";
  include_out_of_stock?: boolean;
  sort?: FillRateSort;
  page?: number;
  per_page?: number;
}) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.status) qs.set("status", params.status);
  if (params.delivery_sla) qs.set("delivery_sla", params.delivery_sla);
  if (params.date_from) qs.set("date_from", params.date_from);
  if (params.date_to) qs.set("date_to", params.date_to);
  if (params.customer_group) qs.set("customer_group", params.customer_group);
  if (params.product_line) qs.set("product_line", params.product_line);
  if (params.reason_code) qs.set("reason_code", params.reason_code);
  if (params.shipping_zone_id) qs.set("shipping_zone_id", params.shipping_zone_id);
  if (params.partner_brand) qs.set("partner_brand", params.partner_brand);
  if (params.brand) qs.set("brand", params.brand);
  if (params.category) qs.set("category", params.category);
  if (params.segment) qs.set("segment", params.segment);
  if (params.include_out_of_stock != null) {
    qs.set("include_out_of_stock", params.include_out_of_stock ? "1" : "0");
  }
  if (params.sort) qs.set("sort", params.sort);
  qs.set("page", String(params.page ?? 1));
  qs.set("per_page", String(params.per_page ?? 50));

  return useQuery({
    queryKey: ["operations-fill-rate", params],
    queryFn: () => apiFetch<Paginated<FillRateSnapshot>>(`operations/fill-rate?${qs}`),
  });
}

export type OpsSyncRun = {
  id: number;
  status: "running" | "completed" | "failed" | "stopped";
  record_count: number;
  success_count: number;
  failed_count: number;
  error_message?: string | null;
  filters?: {
    mode?: string;
    skipped_unknown?: number;
    zero_qty_count?: number;
    warning?: string;
  } | null;
};

export function formatOpsSyncToast(label: string, run: OpsSyncRun): string {
  const base =
    run.status === "completed"
      ? `${label} updated: ${run.success_count} saved`
      : run.status === "stopped"
        ? `${label} stopped: ${run.error_message ?? "Stopped by user"}`
        : run.status === "running"
          ? `${label} started and is running in the background`
        : `${label} failed: ${run.error_message ?? "Unknown error"}`;

  const parts = [base];
  if (run.record_count > 0 && run.record_count !== run.success_count) {
    parts.push(`${run.record_count} processed from Acumatica`);
  }
  if (run.failed_count > 0) {
    parts.push(`${run.failed_count} errors`);
  }
  const f = run.filters;
  if (f?.skipped_unknown && f.skipped_unknown > 0) {
    parts.push(`${f.skipped_unknown} skipped (not in catalog — run full inventory first)`);
  }
  if (f?.zero_qty_count && f.zero_qty_count > 0) {
    parts.push(`${f.zero_qty_count} still at zero qty`);
  }
  if (f?.warning) {
    parts.push(f.warning);
  }

  return parts.join(" · ");
}

function useOpsSync(endpoint: string, keys: string[]) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body?: Record<string, string>) =>
      apiFetch<{ sync_run: OpsSyncRun }>(
        `admin/acumatica/sync/${endpoint}`,
        { method: "POST", body: body ?? undefined },
      ),
    onSuccess: () => {
      keys.forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      qc.invalidateQueries({ queryKey: ["operations-status"] });
      qc.invalidateQueries({ queryKey: ["operations-business-optimization"] });
      qc.invalidateQueries({ queryKey: ["admin-settings", "sync-logs"] });
    },
  });
}

export function useSyncInventory() {
  return useOpsSync("inventory", ["operations-inventory", "operations-inventory-summary"]);
}

export function useSyncInventoryStocks() {
  return useOpsSync("inventory-stocks", [
    "operations-inventory",
    "operations-inventory-summary",
    "operations-backorders",
  ]);
}

export function useSyncBackorders() {
  return useOpsSync("backorders", ["operations-backorders", "operations-backorders-summary", "operations-backorders-accounts"]);
}

export function useSyncFillRate() {
  return useOpsSync("fill-rate", ["operations-fill-rate", "operations-fill-rate-summary"]);
}

export function useSyncCreditNotesAndMore() {
  return useOpsSync("credit-notes-more", ["orders", "order-stats"]);
}

export function fillRateStatusColor(status: string): "default" | "secondary" | "destructive" | "outline" {
  switch (status) {
    case "healthy": return "default";
    case "at_risk": return "secondary";
    case "critical": return "destructive";
    default: return "outline";
  }
}

export function predictionStatusLabel(status: string | null | undefined): string {
  switch (status) {
    case "critical": return "Stockout ≤ 7 days";
    case "at_risk": return "Stockout ≤ 14 days";
    case "healthy": return "Stock healthy";
    case "stable_or_replenished": return "Stable / replenished";
    case "insufficient_history": return "Needs more sync history";
    default: return status ?? "Unknown";
  }
}
