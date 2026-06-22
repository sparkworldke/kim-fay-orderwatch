import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import type { AcumaticaSalesOrder, PaginatedResponse } from "@/types/admin";

interface OrderFilters {
  q?: string;
  date_from?: string;
  date_to?: string;
  customer_id?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

export function useOrders(filters: OrderFilters = {}) {
  const params = new URLSearchParams();
  if (filters.q)           params.set("q", filters.q);
  if (filters.date_from)   params.set("date_from", filters.date_from);
  if (filters.date_to)     params.set("date_to", filters.date_to);
  if (filters.customer_id) params.set("customer_id", filters.customer_id);
  if (filters.status)      params.set("status", filters.status);
  if (filters.page)        params.set("page",     String(filters.page));
  if (filters.per_page)   params.set("per_page", String(filters.per_page));

  const qs = params.toString();

  return useQuery({
    queryKey: ["orders", filters],
    queryFn: () => apiFetch<PaginatedResponse<AcumaticaSalesOrder>>(`orders${qs ? `?${qs}` : ""}`),
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
}

export function useOrderStats(filters: OrderFilters = {}) {
  const params = new URLSearchParams();
  if (filters.q)           params.set("q", filters.q);
  if (filters.date_from)   params.set("date_from", filters.date_from);
  if (filters.date_to)     params.set("date_to", filters.date_to);
  if (filters.customer_id) params.set("customer_id", filters.customer_id);
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
      "match_status" | "flag_source" | "rejection_reason" | "on_hold_reason" | "email_subject" | "email_received_at"
    >>) =>
      apiFetch<AcumaticaSalesOrder>(`orders/${id}`, { method: "PATCH", body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["orders"] }),
  });
}
