import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type CustomerFeedBranch = {
  acumatica_id: string;
  name: string;
  order_count: number;
  email_count: number;
  matched_orders: number;
  avg_completion_hours: number | null;
  avg_fill_rate_pct: number | null;
};

export type CustomerFeedGroup = {
  group_key: string;
  display_name: string;
  is_grouped: boolean;
  branch_count: number;
  acumatica_ids: string[];
  order_count: number;
  email_count: number;
  matched_orders: number;
  avg_completion_hours: number | null;
  avg_fill_rate_pct: number | null;
  branches: CustomerFeedBranch[];
};

export type CustomerFeedResponse = {
  date_from: string;
  date_to: string;
  summary: {
    group_count: number;
    order_count: number;
    email_count: number;
    matched_orders: number;
  };
  groups: CustomerFeedGroup[];
};

export type CustomerFeedIssue = {
  type: string;
  label: string;
  count: number;
  examples: Array<Record<string, unknown>>;
};

export type CustomerFeedInsights = {
  group_key: string;
  display_name: string;
  date_from: string;
  date_to: string;
  issues: CustomerFeedIssue[];
  issue_total: number;
};

export function useCustomerFeed(params: {
  date_from: string;
  date_to: string;
  q?: string;
}) {
  const qs = new URLSearchParams({
    date_from: params.date_from,
    date_to: params.date_to,
  });
  if (params.q) qs.set("q", params.q);

  return useQuery({
    queryKey: ["customer-feed", params],
    queryFn: () => apiFetch<CustomerFeedResponse>(`customer-feed?${qs}`),
  });
}

export function useCustomerFeedInsights(
  groupKey: string | null,
  params: { date_from: string; date_to: string },
) {
  const qs = new URLSearchParams({
    date_from: params.date_from,
    date_to: params.date_to,
  });

  return useQuery({
    queryKey: ["customer-feed-insights", groupKey, params],
    queryFn: () =>
      apiFetch<CustomerFeedInsights>(
        `customer-feed/${encodeURIComponent(groupKey!)}/insights?${qs}`,
      ),
    enabled: groupKey !== null,
  });
}

export function formatCompletionTime(hours: number | null | undefined): string {
  if (hours == null) return "—";
  if (hours < 24) return `${hours.toFixed(1)}h`;
  const days = hours / 24;
  return `${days.toFixed(1)}d`;
}

export function fillRateTone(pct: number | null | undefined): "good" | "warn" | "bad" | "muted" {
  if (pct == null) return "muted";
  if (pct >= 95) return "good";
  if (pct >= 80) return "warn";
  return "bad";
}