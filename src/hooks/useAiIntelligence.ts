import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export interface IntelligenceSection {
  summary: string;
  highlights: string[];
}

export interface AiIntelligenceInsights {
  executive_summary: string;
  orders: IntelligenceSection;
  customer_behaviour: IntelligenceSection;
  predictions: IntelligenceSection;
  actions: string[];
}

export interface AiIntelligenceBriefing {
  period: { from: string; to: string; label: string; days: number };
  comparison_period: { from: string; to: string; label: string };
  metrics: {
    orders: {
      orders_received: number;
      total_value: number;
      orders_captured: number;
      outstanding: number;
      completion_rate: number;
      revenue_at_risk: number;
      avg_order_value: number;
    };
    orders_comparison: Record<string, { current: number; prior: number; change: number; change_pct: number }>;
    customers: {
      top_customers: Array<{ customer_name: string; orders: number; value: number }>;
      fastest_growth: Array<{ customer_name: string; value_change_pct: number; value: number }>;
      fastest_decline: Array<{ customer_name: string; value_change_pct: number; value: number }>;
      unique_customers: number;
      prior_unique_customers: number;
      went_quiet: string[];
      new_or_returning: string[];
    };
    daily_trend: Array<{ day: string; orders: number; value: number; captured: number }>;
    historical_weekly: Array<{ week_start: string; orders: number; value: number }>;
    projections: {
      projected_next_7_days_orders: number;
      projected_next_7_days_value: number;
      volume_momentum_pct: number;
      avg_daily_orders: number;
      avg_daily_value: number;
      method: string;
    };
  };
  insights: AiIntelligenceInsights | null;
  insights_cached: boolean;
  insights_generated_at: string | null;
  ai_status: string | null;
  provider: string | null;
  generated_at: string;
}

function intelligenceUrl(dateFrom: string, dateTo: string) {
  return `ai/intelligence?date_from=${encodeURIComponent(dateFrom)}&date_to=${encodeURIComponent(dateTo)}`;
}

/** Loads metrics and any previously saved insights — never triggers AI. */
export function useAiIntelligence(dateFrom: string, dateTo: string) {
  return useQuery({
    queryKey: ["ai-intelligence", dateFrom, dateTo],
    queryFn: () => apiFetch<AiIntelligenceBriefing>(intelligenceUrl(dateFrom, dateTo)),
    enabled: !!dateFrom && !!dateTo,
    staleTime: 60_000,
  });
}

/** On-demand AI generation — caches result server-side for the date range. */
export function useGenerateAiIntelligence(dateFrom: string, dateTo: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (regenerate?: boolean) =>
      apiFetch<AiIntelligenceBriefing>("ai/intelligence/generate", {
        method: "POST",
        body: JSON.stringify({ date_from: dateFrom, date_to: dateTo, regenerate: regenerate ?? false }),
      }),
    onSuccess: (data) => {
      qc.setQueryData(["ai-intelligence", dateFrom, dateTo], data);
    },
  });
}