import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type SalesPromptStatus = "open" | "snoozed" | "resolved" | "dismissed";
export type SalesPromptType =
  | "order_cycle_follow_up"
  | "not_billed_month"
  | "debt_collection"
  | "volume_delta"
  | "whitespot_customer"
  | "whitespot_product"
  | "incentive_review";

export interface SalesManagementPrompt {
  id: number;
  prompt_type: SalesPromptType;
  status: SalesPromptStatus;
  severity: "info" | "due" | "overdue";
  period_key: string | null;
  customer_acumatica_id: string;
  customer_name: string | null;
  consultant_user_id: number | null;
  consultant_rep_code: string | null;
  consultant_name: string | null;
  source_from: string | null;
  source_to: string | null;
  last_order_date: string | null;
  expected_cycle_days: number | null;
  days_since_last_order: number | null;
  due_date: string | null;
  snoozed_until: string | null;
  value_snapshot: string | number;
  order_count_snapshot: number;
  reason: string;
  created_at: string;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface SalesManagementDashboard {
  total_open: number;
  due: number;
  overdue: number;
  month_gaps: number;
  resolved_30d: number;
  sales_order_sync: {
    last_success_at: string | null;
    threshold_hours: number;
    is_stale: boolean;
    message: string | null;
  };
}

export function useSalesManagementPrompts(params: {
  view?: string;
  type?: string;
  status?: string;
  consultant_user_id?: number;
  q?: string;
  page?: number;
  per_page?: number;
} = {}) {
  const qs = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== "" && value !== null) qs.set(key, String(value));
  });

  return useQuery({
    queryKey: ["sales-management", "prompts", params],
    queryFn: () => apiFetch<Paginated<SalesManagementPrompt>>(`operations/sales-management/prompts?${qs}`),
  });
}

export function useSalesManagementDashboard() {
  return useQuery({
    queryKey: ["sales-management", "dashboard"],
    queryFn: () => apiFetch<SalesManagementDashboard>("operations/sales-management/prompts/dashboard"),
  });
}

export function useGenerateSalesManagementPrompts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { period?: string; force?: boolean }) =>
      apiFetch<{ created: number; updated: number; skipped: number; stale_blocked: boolean; stale_message: string | null }>(
        "admin/sales-management/prompts/generate",
        { method: "POST", body },
      ),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["sales-management"] }),
  });
}

function promptAction(id: number | string, action: "resolve" | "snooze" | "dismiss", body: Record<string, unknown>) {
  return apiFetch<SalesManagementPrompt>(`operations/sales-management/prompts/${id}/${action}`, {
    method: "POST",
    body,
  });
}

export function useSalesPromptActions() {
  const qc = useQueryClient();
  const invalidate = () => qc.invalidateQueries({ queryKey: ["sales-management"] });

  return {
    resolve: useMutation({
      mutationFn: ({ id, note }: { id: number; note: string }) => promptAction(id, "resolve", { note }),
      onSuccess: invalidate,
    }),
    snooze: useMutation({
      mutationFn: ({ id, snoozed_until, note }: { id: number; snoozed_until: string; note?: string }) =>
        promptAction(id, "snooze", { snoozed_until, note }),
      onSuccess: invalidate,
    }),
    dismiss: useMutation({
      mutationFn: ({ id, reason }: { id: number; reason: string }) => promptAction(id, "dismiss", { reason }),
      onSuccess: invalidate,
    }),
  };
}
