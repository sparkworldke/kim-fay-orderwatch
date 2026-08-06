import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type WarehouseStock = {
  warehouse_id: string;
  warehouse_name: string;
  qty_available: number | null;
  qty_on_hand: number | null;
};

export type NotDeliveredTotals = {
  ordered_qty: number;
  shipped_qty: number;
  not_delivered_qty: number;
  not_delivered_amount: number;
  orders: number;
};

export type NotDeliveredOrder = {
  order_nbr: string;
  order_date: string;
  order_status: string | null;
  ordered_qty: number;
  shipped_qty: number;
  not_delivered_qty: number;
  unit_price: number | null;
  not_delivered_amount: number;
  reason_key: string;
  reason_label: string;
  stock_status: "in_stock" | "partial_stock" | "out_of_stock";
  stock_status_label: string;
  stock_basis: "available" | "on_hand_fallback";
  total_available: number | null;
  total_on_hand: number | null;
  age_days: number;
  warehouse_stocks: WarehouseStock[];
};

export type NotDeliveredOutlet = {
  customer_id: string;
  customer_name: string;
  parent_customer_id: string;
  parent_customer_name: string;
  totals: NotDeliveredTotals;
  orders: NotDeliveredOrder[];
};

export type NotDeliveredSku = {
  inventory_id: string;
  product_name: string;
  brand: string | null;
  totals: NotDeliveredTotals;
  outlets: NotDeliveredOutlet[];
};

export type ItemsNotDeliveredFilters = {
  brands: string[];
  customerIds: string[];
  productSegments: string[];
  reasons: string[];
  stockStatuses: string[];
  orderStatuses: string[];
  dateFrom: string;
  dateTo: string;
  page?: number;
  perPage?: number;
};

export type ItemsNotDeliveredResponse = {
  data: NotDeliveredSku[];
  meta: { current_page: number; per_page: number; total: number; last_page: number };
  summary: {
    affected_skus: number;
    affected_outlets: number;
    affected_orders: number;
    not_delivered_units: number;
    not_delivered_amount: number;
    in_stock_amount: number;
    reason_totals: Array<{ key: string; label: string; count: number }>;
  };
  filter_options: {
    reasons: Array<{ key: string; label: string }>;
    stock_statuses: Array<{ key: string; label: string }>;
    order_statuses: Array<{ key: string; label: string }>;
    product_segments: Array<{ key: string; label: string }>;
  };
  period: { from: string; to: string };
};

export function itemsNotDeliveredParams(filters: ItemsNotDeliveredFilters) {
  const params = new URLSearchParams();
  filters.brands.forEach((value) => params.append("brands[]", value));
  filters.customerIds.forEach((value) => params.append("customer_ids[]", value));
  filters.productSegments.forEach((value) => params.append("product_segments[]", value));
  filters.reasons.forEach((value) => params.append("reasons[]", value));
  filters.stockStatuses.forEach((value) => params.append("stock_statuses[]", value));
  filters.orderStatuses.forEach((value) => params.append("order_statuses[]", value));
  if (filters.dateFrom) params.set("date_from", filters.dateFrom);
  if (filters.dateTo) params.set("date_to", filters.dateTo);
  params.set("page", String(filters.page ?? 1));
  params.set("per_page", String(filters.perPage ?? 20));
  return params;
}

export function useItemsNotDelivered(filters: ItemsNotDeliveredFilters) {
  const params = itemsNotDeliveredParams(filters);
  return useQuery({
    queryKey: ["items-not-delivered", filters],
    queryFn: () => apiFetch<ItemsNotDeliveredResponse>(`operations/items-not-delivered?${params}`),
    enabled: Boolean(filters.dateFrom && filters.dateTo),
    staleTime: 5 * 60_000,
  });
}
