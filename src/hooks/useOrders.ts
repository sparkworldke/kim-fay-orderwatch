import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch } from "@/lib/api";
import type { AcumaticaSalesOrder, AcumaticaSyncLog, PaginatedResponse } from "@/types/admin";

export interface OrderFilters {
  q?: string;
  date_from?: string;
  date_to?: string;
  customer_id?: string;
  rep_code?: string;
  status?: string;
  match_status?: string;
  has_email?: boolean;
  flag_source?: string;
  order_type?: string;
  document_type?: string;
  sort?: "latest" | "oldest" | "amount_desc" | "amount_asc";
  with_fulfillment?: boolean;
  page?: number;
  per_page?: number;
}

export function useOrders(filters: OrderFilters = {}) {
  const params = new URLSearchParams();
  if (filters.q)           params.set("q", filters.q);
  if (filters.date_from)   params.set("date_from", filters.date_from);
  if (filters.date_to)     params.set("date_to", filters.date_to);
  if (filters.customer_id) params.set("customer_id", filters.customer_id);
  if (filters.rep_code)    params.set("rep_code", filters.rep_code);
  if (filters.status)       params.set("status", filters.status);
  if (filters.match_status) params.set("match_status", filters.match_status);
  if (filters.has_email === true) params.set("has_email", "1");
  if (filters.has_email === false) params.set("has_email", "0");
  if (filters.flag_source)  params.set("flag_source", filters.flag_source);
  if (filters.order_type)   params.set("order_type", filters.order_type);
  if (filters.document_type) params.set("document_type", filters.document_type);
  if (filters.sort)        params.set("sort", filters.sort);
  if (filters.with_fulfillment) params.set("with_fulfillment", "1");
  if (filters.page)        params.set("page",     String(filters.page));
  if (filters.per_page)   params.set("per_page", String(filters.per_page));

  const qs = params.toString();

  return useQuery({
    queryKey: ["orders", filters],
    queryFn: () => apiFetch<PaginatedResponse<AcumaticaSalesOrder>>(`orders${qs ? `?${qs}` : ""}`),
  });
}

export function useOrder(id: string | number | null) {
  return useQuery({
    queryKey: ["orders", id],
    queryFn: () => apiFetch<AcumaticaSalesOrder>(`orders/${encodeURIComponent(String(id ?? ""))}`),
    enabled: id !== null && String(id).trim() !== "",
  });
}

export interface OrderStats {
  total: number;
  completed: number;
  shipping: number;
  pending_approval: number;
  rejected: number;
  on_hold: number;
  open: number;
  email_in: number;
  matched: number;
  matched_discrepancies: number;
  needs_review: number;
  missing: number;
  pending: number;
  unmatched: number;
  by_type?: Record<string, number>;
}

export function useOrderStats(filters: OrderFilters = {}) {
  const params = new URLSearchParams();
  if (filters.q)           params.set("q", filters.q);
  if (filters.date_from)   params.set("date_from", filters.date_from);
  if (filters.date_to)     params.set("date_to", filters.date_to);
  if (filters.customer_id) params.set("customer_id", filters.customer_id);
  if (filters.status)      params.set("status", filters.status);
  if (filters.order_type)  params.set("order_type", filters.order_type);
  if (filters.document_type) params.set("document_type", filters.document_type);
  const qs = params.toString();

  return useQuery({
    queryKey: ["order-stats", filters],
    queryFn: () => apiFetch<OrderStats>(`orders/stats${qs ? `?${qs}` : ""}`),
  });
}

export function useUpdateOrder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: { id: number } & Partial<Pick<AcumaticaSalesOrder,
      "status" | "match_status" | "flag_source" | "rejection_reason_code" | "rejection_reason" | "on_hold_reason" | "email_subject" | "email_received_at"
    >>) =>
      apiFetch<AcumaticaSalesOrder>(`orders/${id}`, { method: "PATCH", body }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["orders"] });
      qc.invalidateQueries({ queryKey: ["order-stats"] });
    },
  });
}

export function useRefreshOrderStatuses() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: { date_from: string; date_to: string }) =>
      apiFetch<{
        message: string;
        today_import: AcumaticaSyncLog;
        status_sync: AcumaticaSyncLog;
        date_from: string;
        date_to: string;
        today: string;
      }>("orders-status-refresh", {
        method: "POST",
        body: payload,
        timeoutMs: 300_000,
      }),
    onSuccess: (result) => {
      const imported = result.today_import.success_count;
      const checked = Number(result.status_sync.filters?.status_comparison_count ?? result.status_sync.record_count ?? 0);
      const updated = Number(result.status_sync.filters?.status_updates ?? 0);

      toast.success(`Status refresh complete: ${imported} imported today, ${updated} status updates from ${checked} checked.`);
      qc.invalidateQueries({ queryKey: ["orders"] });
      qc.invalidateQueries({ queryKey: ["order-stats"] });
      qc.invalidateQueries({ queryKey: ["dashboard-kpis"] });
      qc.invalidateQueries({ queryKey: ["dashboard-trend"] });
      qc.invalidateQueries({ queryKey: ["dashboard-orders-by-status"] });
      qc.invalidateQueries({ queryKey: ["admin-settings", "sync-logs"] });
    },
    onError: (error: Error) => {
      toast.error(error.message || "Order status refresh failed.");
    },
  });
}
