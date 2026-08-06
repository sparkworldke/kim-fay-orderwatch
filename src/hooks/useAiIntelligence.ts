import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch, ApiError } from "@/lib/api";

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
    orders_comparison: Record<
      string,
      { current: number; prior: number; change: number; change_pct: number }
    >;
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
  model?: string | null;
  error_message?: string | null;
  queue_uuid?: string | null;
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
    refetchInterval: (q) => {
      const s = q.state.data?.ai_status;
      return s === "queued" || s === "running" ? 2500 : false;
    },
  });
}

/** On-demand AI generation — dispatches job (sync queue runs immediately). */
export function useGenerateAiIntelligence(dateFrom: string, dateTo: string) {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: async (regenerate?: boolean) => {
      let data = await apiFetch<AiIntelligenceBriefing>("ai/intelligence/generate", {
        method: "POST",
        body: { date_from: dateFrom, date_to: dateTo, regenerate: regenerate ?? false },
        timeoutMs: 180_000,
      });

      // Async worker path: poll until terminal state
      if (data.ai_status === "queued" || data.ai_status === "running") {
        for (let i = 0; i < 90; i++) {
          await new Promise((r) => setTimeout(r, 2000));
          if (data.queue_uuid) {
            data = await apiFetch<AiIntelligenceBriefing>(
              `ai/intelligence/jobs/${data.queue_uuid}`,
            );
          } else {
            data = await apiFetch<AiIntelligenceBriefing>(intelligenceUrl(dateFrom, dateTo));
          }
          if (data.ai_status === "success" || data.ai_status === "failed") break;
        }
      }

      if (data.ai_status === "failed") {
        throw new Error(
          data.error_message ||
            "AI generation failed. Check Administration → AI Connector for a valid API key.",
        );
      }
      if (data.ai_status === "queued" || data.ai_status === "running") {
        throw new Error(
          "AI generation is still queued. Ensure `php artisan queue:work` is running on the server.",
        );
      }
      return data;
    },
    onSuccess: (data) => {
      qc.setQueryData(["ai-intelligence", dateFrom, dateTo], data);
    },
  });
}

// ── Kimfay Genius ───────────────────────────────────────────────────────────

export type GeniusConsultant = {
  id: number;
  name: string;
  email: string;
  rep_code: string | null;
  role: string;
  week_status: string | null;
  has_brief: boolean;
  generated_at: string | null;
};

export type GeniusInsights = {
  executive_summary: string;
  portfolio: IntelligenceSection;
  risks: IntelligenceSection;
  predictions: IntelligenceSection;
  actions: string[];
};

export type GeniusBriefing = {
  id: number;
  week_start: string | null;
  ai_status: string;
  provider: string | null;
  model: string | null;
  error_message: string | null;
  queue_uuid: string | null;
  generated_at: string | null;
  insights: GeniusInsights | null;
};

export function useGeniusConsultants() {
  return useQuery({
    queryKey: ["ai-genius-consultants"],
    queryFn: () =>
      apiFetch<{ week_start: string; unlock_at: string; data: GeniusConsultant[] }>(
        "ai/genius/consultants",
      ),
    staleTime: 30_000,
  });
}

export function useGeniusConsultant(userId: number | null) {
  return useQuery({
    queryKey: ["ai-genius-consultant", userId],
    queryFn: () =>
      apiFetch<{
        consultant: { id: number; name: string; email: string; rep_code: string | null; role: string };
        week_start: string;
        unlock_at: string;
        lock_active: boolean;
        can_generate: boolean;
        briefing: GeniusBriefing | null;
      }>(`ai/genius/consultants/${userId}`),
    enabled: !!userId,
    refetchInterval: (q) => {
      const s = q.state.data?.briefing?.ai_status;
      return s === "queued" || s === "running" ? 2500 : false;
    },
  });
}

export function useGenerateGenius(userId: number | null) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (force?: boolean) => {
      if (!userId) throw new Error("No consultant selected");
      let data = await apiFetch<{
        briefing: GeniusBriefing;
        week_start: string;
        unlock_at: string;
      }>(`ai/genius/consultants/${userId}/generate`, {
        method: "POST",
        body: { force: force ?? false },
        timeoutMs: 180_000,
      });

      let briefing = data.briefing;
      if (briefing.ai_status === "queued" || briefing.ai_status === "running") {
        for (let i = 0; i < 90; i++) {
          await new Promise((r) => setTimeout(r, 2000));
          if (briefing.queue_uuid) {
            const poll = await apiFetch<{ briefing: GeniusBriefing }>(
              `ai/genius/jobs/${briefing.queue_uuid}`,
            );
            briefing = poll.briefing;
          } else {
            const show = await apiFetch<{ briefing: GeniusBriefing | null }>(
              `ai/genius/consultants/${userId}`,
            );
            if (show.briefing) briefing = show.briefing;
          }
          if (briefing.ai_status === "success" || briefing.ai_status === "failed") break;
        }
      }

      if (briefing.ai_status === "failed") {
        throw new Error(briefing.error_message || "Kimfay Genius generation failed.");
      }
      if (briefing.ai_status === "queued" || briefing.ai_status === "running") {
        throw new Error(
          "Generation still queued. Ensure `php artisan queue:work` is running on the server.",
        );
      }
      return { ...data, briefing };
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["ai-genius-consultants"] });
      qc.invalidateQueries({ queryKey: ["ai-genius-consultant", userId] });
    },
  });
}
