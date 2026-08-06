import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type KpAccount = {
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  status: string | null;
  email: string | null;
  phone: string | null;
  payment_terms: string | null;
  parent_acumatica_id: string | null;
  is_main_account: boolean;
  contact_count: number;
  last_order_date: string | null;
};

export type KpAccountsPage = {
  data: KpAccount[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
  scope: "all_kp" | "my_portfolio" | "scoped" | "none" | string;
  customer_class?: string;
  exclude_class?: string | null;
};

export function useKpAccounts(params: {
  q?: string;
  page?: number;
  per_page?: number;
  status?: string;
  /** Exact Acumatica customer class, e.g. KPCCLNRS */
  customer_class?: string;
  /** Exclude a class from the default KP% portfolio */
  exclude_class?: string;
  /** When true, list every class in the portfolio (not only KP%). */
  all_classes?: boolean;
  /** "self" restricts to the caller's own book even if they'd otherwise see their subtree. */
  mode?: "auto" | "self" | "team";
  /** Scope to one team member's book (My Team accordion expand) — authorized server-side. */
  rep_code?: string;
}) {
  const qs = new URLSearchParams();
  if (params.q?.trim()) qs.set("q", params.q.trim());
  if (params.page) qs.set("page", String(params.page));
  if (params.per_page) qs.set("per_page", String(params.per_page));
  if (params.status && params.status !== "all") qs.set("status", params.status);
  if (params.customer_class?.trim()) qs.set("customer_class", params.customer_class.trim());
  if (params.exclude_class?.trim()) qs.set("exclude_class", params.exclude_class.trim());
  if (params.all_classes) qs.set("all_classes", "1");
  if (params.mode) qs.set("mode", params.mode);
  if (params.rep_code?.trim()) qs.set("rep_code", params.rep_code.trim());

  return useQuery({
    queryKey: ["kp-accounts", params],
    queryFn: () => apiFetch<KpAccountsPage>(`kp/accounts?${qs}`),
  });
}

export type KpAccountsByRepGroup = {
  user_id: number;
  rep_code: string | null;
  rep_name: string;
  employee_number: string | null;
  account_count: number;
  active_count: number;
  dormant_count: number;
  on_hold_count: number;
  revenue_mtd: number;
  target: number | null;
  achieved_pct: number | null;
  time_gone_pct: number;
  variance_to_pace: number | null;
  pace_status: "ahead" | "on_track" | "at_risk" | "off_track" | "no_target";
  needs_attention: boolean;
  attention_reason: string;
  attention_reasons: string[];
  dormant_share_pct: number;
  backorder_lines: number;
  backorder_revenue_at_risk: number;
  last_activity_at: string | null;
  top_at_risk_accounts: Array<{ customer_id: string; last_order_date: string | null }>;
};

export type KpAccountsByRepResponse = {
  period: { month_label: string; time_gone_pct: number; active_from: string };
  groups: KpAccountsByRepGroup[];
  summary: { revenue_mtd: number; target: number; targeted_reportees: number; reportees_needing_attention: number; backorder_revenue_at_risk: number };
};

export function useKpAccountsByRep(
  params: { customer_class?: string; exclude_class?: string; all_classes?: boolean; month?: string },
  enabled = true,
) {
  const qs = new URLSearchParams();
  if (params.customer_class?.trim()) qs.set("customer_class", params.customer_class.trim());
  if (params.exclude_class?.trim()) qs.set("exclude_class", params.exclude_class.trim());
  if (params.all_classes) qs.set("all_classes", "1");
  if (params.month) qs.set("month", params.month);

  return useQuery({
    queryKey: ["kp-accounts-by-rep", params],
    queryFn: () => apiFetch<KpAccountsByRepResponse>(`kp/accounts/by-rep?${qs}`),
    enabled,
  });
}
