import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type PcrStatus = "submitted" | "in_approval" | "rejected" | "pending_erp_apply" | "applied_erp";

export interface PcrEvent {
  id: number;
  event_type: string;
  comment: string | null;
  payload_json: Record<string, unknown> | null;
  created_at: string;
}

export interface PriceChangeRequest {
  id: number;
  public_ref: string;
  customer_acumatica_id: string;
  customer_name: string | null;
  customer_price_class: string | null;
  customer_payment_terms: string | null;
  inventory_id: string;
  product_description: string | null;
  current_selling_price: string | number | null;
  proposed_selling_price: string | number;
  base_price_snapshot?: string | number | null;
  margin_pct_snapshot?: string | number | null;
  margin_kes_snapshot?: string | number | null;
  currency_id: string;
  justification: string;
  status: PcrStatus;
  current_stage_key: string | null;
  duplicate_ack_required: boolean;
  duplicate_acked_at: string | null;
  submitted_at: string | null;
  created_at: string;
  can_actor_approve: boolean;
  can_actor_apply_erp: boolean;
  can_actor_ack_duplicate: boolean;
  current_stage?: { key: string; name: string } | null;
  events?: PcrEvent[];
  approval_actions?: Array<{ id: number; stage_key: string; decision: string; comment: string | null; decided_at: string | null }>;
}

export interface PcrCustomer {
  acumatica_id: string;
  name: string | null;
  customer_class: string | null;
  payment_terms: string | null;
  status: string | null;
}

export interface PcrInventory {
  inventory_id: string;
  description: string | null;
  sales_price: string | number | null;
  item_status: string | null;
}

export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  total: number;
  per_page: number;
}

export interface PcrInput {
  customer_acumatica_id: string;
  inventory_id: string;
  proposed_selling_price: number;
  justification: string;
  effective_date_requested?: string | null;
}

export function usePcrList(params: { view?: string; q?: string; page?: number }) {
  const qs = new URLSearchParams();
  if (params.view) qs.set("view", params.view);
  if (params.q) qs.set("q", params.q);
  if (params.page) qs.set("page", String(params.page));

  return useQuery({
    queryKey: ["price-change-requests", params],
    queryFn: () => apiFetch<Paginated<PriceChangeRequest>>(`operations/price-change-requests?${qs}`),
  });
}

export function usePcrDashboard() {
  return useQuery({
    queryKey: ["price-change-requests", "dashboard"],
    queryFn: () => apiFetch<Record<string, number>>("operations/price-change-requests/dashboard"),
  });
}

export function usePcrRequest(id: string | number | undefined) {
  return useQuery({
    queryKey: ["price-change-requests", id],
    queryFn: () => apiFetch<PriceChangeRequest>(`operations/price-change-requests/${id}`),
    enabled: id !== undefined && id !== "",
  });
}

export function usePcrCustomers(q: string) {
  return useQuery({
    queryKey: ["price-change-requests", "customers", q],
    queryFn: () => apiFetch<PcrCustomer[]>(`operations/price-change-requests/customers/search?q=${encodeURIComponent(q)}`),
    enabled: q.length >= 2,
  });
}

export function usePcrInventory(q: string) {
  return useQuery({
    queryKey: ["price-change-requests", "inventory", q],
    queryFn: () => apiFetch<PcrInventory[]>(`operations/price-change-requests/inventory/search?q=${encodeURIComponent(q)}`),
    enabled: q.length >= 1,
  });
}

export function useResolvePcrPrice(customerId: string, inventoryId: string, proposed?: number) {
  const qs = new URLSearchParams();
  if (customerId) qs.set("customer_acumatica_id", customerId);
  if (inventoryId) qs.set("inventory_id", inventoryId);
  if (proposed != null && Number.isFinite(proposed)) qs.set("proposed_selling_price", String(proposed));

  return useQuery({
    queryKey: ["price-change-requests", "resolve-price", customerId, inventoryId, proposed],
    queryFn: () => apiFetch<Record<string, unknown>>(`operations/price-change-requests/resolve-price?${qs}`),
    enabled: !!customerId && !!inventoryId,
  });
}

export function useCreatePcr() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: PcrInput) => apiFetch<PriceChangeRequest>("operations/price-change-requests", { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["price-change-requests"] });
      qc.setQueryData(["price-change-requests", data.id], data);
    },
  });
}

export function usePcrDecision(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: { decision: "approved" | "rejected"; comment: string }) =>
      apiFetch<PriceChangeRequest>(`operations/price-change-requests/${id}/decisions`, { method: "POST", body }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["price-change-requests"] });
      qc.setQueryData(["price-change-requests", id], data);
    },
  });
}

export function useAckPcrDuplicate(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<PriceChangeRequest>(`operations/price-change-requests/${id}/acknowledge-duplicate`, { method: "POST" }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["price-change-requests"] });
      qc.setQueryData(["price-change-requests", id], data);
    },
  });
}

export function useMarkPcrApplied(id: number | string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch<PriceChangeRequest>(`operations/price-change-requests/${id}/mark-applied-erp`, { method: "POST" }),
    onSuccess: (data) => {
      qc.invalidateQueries({ queryKey: ["price-change-requests"] });
      qc.setQueryData(["price-change-requests", id], data);
    },
  });
}
