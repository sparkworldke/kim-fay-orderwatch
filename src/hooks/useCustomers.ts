import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import type { AcumaticaCustomer, PaginatedResponse } from "@/types/admin";

interface CustomerFilters {
  q?: string;
  class?: string;
  /** Prefix filter — matches customer_class starting with this value (e.g. "KP", "CS") */
  class_prefix?: string;
  status?: string;
  shipping_zone_id?: string;
  page?: number;
  per_page?: number;
}

export function useCustomers(filters: CustomerFilters = {}) {
  const params = new URLSearchParams();
  if (filters.q)      params.set("q", filters.q);
  if (filters.class)  params.set("class", filters.class);
  if (filters.class_prefix) params.set("class_prefix", filters.class_prefix);
  if (filters.status) params.set("status", filters.status);
  if (filters.shipping_zone_id) params.set("shipping_zone_id", filters.shipping_zone_id);
  if (filters.page)     params.set("page",     String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));

  const qs = params.toString();

  return useQuery({
    queryKey: ["customers", filters],
    queryFn: () => apiFetch<PaginatedResponse<AcumaticaCustomer>>(`customers${qs ? `?${qs}` : ""}`),
  });
}

export interface SuggestedOrderItem {
  inventory_id: string;
  description: string | null;
  uom: string | null;
  order_count: number;
  avg_interval_days: number;
  last_order_date: string;
  last_order_qty: number;
  next_expected_date: string;
  days_overdue: number;
  avg_order_qty: number;
}

export interface SuggestedOrdersResponse {
  customer_id: string;
  customer_name: string | null;
  suggestions: SuggestedOrderItem[];
}

export function useSuggestedOrders(customerId: string | null) {
  return useQuery({
    queryKey: ["customers", customerId, "suggested-orders"],
    queryFn: () => apiFetch<SuggestedOrdersResponse>(`customers/${encodeURIComponent(customerId ?? "")}/suggested-orders`),
    enabled: customerId !== null,
  });
}

export interface CommonProductItem {
  inventory_id: string;
  description: string | null;
  uom: string | null;
  order_count: number;
  total_qty: number;
  last_order_date: string;
  last_order_qty: number;
}

export interface CommonProductsResponse {
  customer_id: string;
  customer_name: string | null;
  products: CommonProductItem[];
}

export function useCommonProducts(customerId: string | null) {
  return useQuery({
    queryKey: ["customers", customerId, "common-products"],
    queryFn: () => apiFetch<CommonProductsResponse>(`customers/${encodeURIComponent(customerId ?? "")}/common-products`),
    enabled: customerId !== null,
  });
}
