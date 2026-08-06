import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type SalesIntelligenceChannel = "PORTFOLIO" | "MT" | "MT1" | "MT2" | "GT" | "DTC_DTB" | "ECOMMERCE" | "KP";

export type SalesIntelligenceCustomer = {
  customer_id: string;
  customer_name: string;
  sales_channel: string | null;
  customer_class: string | null;
  route_code: string | null;
  region: string | null;
  representatives: Array<{ id: number; name: string; rep_code: string | null }>;
  so_count: number;
  credit_note_count: number;
  open_so_count: number;
  gross_revenue: number;
  credit_revenue: number;
  net_revenue: number;
  ordered_volume: number;
  credited_volume: number;
  net_volume: number;
  last_order_date: string | null;
};

export type SalesIntelligenceMetrics = {
  scope: {
    channel: SalesIntelligenceChannel;
    customer_count: number;
    customer_ids: string[];
    portfolio_scoped: boolean;
    portfolio_scope: "own" | "team" | null;
    not_ordered_count: number;
    region: string | null;
    available_regions: string[];
  };
  period: { from: string; to: string; timezone: string };
  metrics: {
    so_count: number;
    credit_note_count: number;
    gross_revenue: number;
    credit_revenue: number;
    net_revenue: number;
    ordered_volume: number;
    credited_volume: number;
    net_volume: number;
  };
  customers: SalesIntelligenceCustomer[];
  not_ordered_customers: SalesIntelligenceCustomer[];
  comparison: null | {
    preset: "previous_month" | "past_3_months" | "past_6_months";
    from: string;
    to: string;
    declined_outlet_count: number;
    outlets: Array<{
      customer_id: string;
      customer_name: string;
      sales_channel: string | null;
      region: string | null;
      representatives: SalesIntelligenceCustomer["representatives"];
      current_revenue: number;
      comparison_revenue: number;
      revenue_change: number;
      current_orders: number;
      comparison_orders: number;
      order_change: number;
      missing_sku_count: number;
      missing_skus: string[];
      declined: boolean;
    }>;
  };
};

export function useSalesIntelligenceMetrics(
  channel: SalesIntelligenceChannel,
  from?: string,
  to?: string,
  region?: string,
  comparison?: "previous_month" | "past_3_months" | "past_6_months",
) {
  const params = new URLSearchParams({ channel });
  if (from) params.set("from", from);
  if (to) params.set("to", to);
  if (region) params.set("region", region);
  if (comparison) params.set("comparison", comparison);

  return useQuery({
    queryKey: ["sales-intelligence", channel, from ?? "month", to ?? "today", region ?? "all-regions", comparison ?? "none"],
    queryFn: () => apiFetch<SalesIntelligenceMetrics>(`sales/intelligence/metrics?${params}`),
    staleTime: 60_000,
  });
}
