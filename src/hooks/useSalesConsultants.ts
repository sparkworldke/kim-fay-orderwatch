import { useQuery } from "@tanstack/react-query";
import { apiFetch, ApiError } from "@/lib/api";

export interface SalesConsultantOption {
  rep_code: string;
  name: string;
}

/** Lightweight consultant picker list — for the full directory with stats, see app.sales-consultants.tsx. */
export function useConsultantOptions() {
  return useQuery({
    queryKey: ["operations", "sales-consultants"],
    queryFn: () => apiFetch<{ items: SalesConsultantOption[] }>("operations/sales-consultants"),
  });
}

export interface ConsultantCustomerSummary {
  total_order_value: number;
  customer_count: number;
  total_completed_orders: number;
  active_orders: number;
  total_orders: number;
  last_order_date: string | null;
}

export interface ConsultantCustomerRow {
  customer_id: string;
  customer_name: string | null;
  customer_class: string | null;
  customer_status: string | null;
  order_count: number;
  active_orders: number;
  completed_orders: number;
  total_order_value: number;
  first_order_date: string | null;
  last_order_date: string | null;
  orders_per_month: number | null;
}

export interface ConsultantCustomersResponse {
  rep_code: string;
  summary: ConsultantCustomerSummary;
  customers: ConsultantCustomerRow[];
}

export interface SalesConsultantProfile {
  id: number;
  name: string;
  email: string;
  role: string;
  rep_code: string;
  is_active: boolean;
  assigned_orders: number;
  active_orders: number;
  completed_orders: number;
  assigned_revenue: number;
  last_order_date: string | null;
}

export interface ConsultantDetailResponse {
  consultant: SalesConsultantProfile;
  summary: ConsultantCustomerSummary;
}

export type ConsultantDateFilters = {
  date_from?: string;
  date_to?: string;
};

function buildDateQuery(filters: ConsultantDateFilters) {
  const params = new URLSearchParams();
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

function shouldFallbackToRepCode(error: unknown) {
  return error instanceof ApiError && [404, 405].includes(error.status);
}

async function fetchConsultantFromList(consultantId: number) {
  const list = await apiFetch<{ items: SalesConsultantProfile[] }>("operations/sales-consultants");
  const consultant = list.items.find((item) => item.id === consultantId);
  if (!consultant?.rep_code) {
    throw new ApiError("Sales consultant not found.", 404, null);
  }
  return consultant;
}

async function fetchCustomersByRepCode(repCode: string, filters: ConsultantDateFilters) {
  return apiFetch<ConsultantCustomersResponse>(
    `operations/sales-consultants/${encodeURIComponent(repCode)}/customers${buildDateQuery(filters)}`,
  );
}

export function useConsultantDetail(
  consultantId: number,
  filters: ConsultantDateFilters = {},
) {
  return useQuery({
    queryKey: ["operations", "sales-consultants", consultantId, "detail", filters],
    queryFn: async () => {
      try {
        return await apiFetch<ConsultantDetailResponse>(
          `operations/sales-consultants/${consultantId}${buildDateQuery(filters)}`,
        );
      } catch (error) {
        if (!shouldFallbackToRepCode(error)) throw error;

        const consultant = await fetchConsultantFromList(consultantId);
        const customers = await fetchCustomersByRepCode(consultant.rep_code, filters);
        const summary = customers.summary ?? {
          total_order_value: consultant.assigned_revenue,
          customer_count: customers.customers.length,
          total_completed_orders: consultant.completed_orders,
          active_orders: consultant.active_orders,
          total_orders: consultant.assigned_orders,
          last_order_date: consultant.last_order_date,
        };

        return { consultant, summary };
      }
    },
    enabled: Number.isFinite(consultantId) && consultantId > 0,
  });
}

export function useConsultantCustomers(
  consultantId: number,
  filters: ConsultantDateFilters = {},
) {
  return useQuery({
    queryKey: ["operations", "sales-consultants", consultantId, "customers", filters],
    queryFn: async () => {
      try {
        return await apiFetch<ConsultantCustomersResponse>(
          `operations/sales-consultants/${consultantId}/customers${buildDateQuery(filters)}`,
        );
      } catch (error) {
        if (!shouldFallbackToRepCode(error)) throw error;

        const consultant = await fetchConsultantFromList(consultantId);
        return fetchCustomersByRepCode(consultant.rep_code, filters);
      }
    },
    enabled: Number.isFinite(consultantId) && consultantId > 0,
  });
}

/** @deprecated Use useConsultantCustomers(consultantId) instead. */
export function useConsultantCustomersByRepCode(
  repCode: string,
  filters: ConsultantDateFilters = {},
) {
  const params = new URLSearchParams();
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  const qs = params.toString();

  return useQuery({
    queryKey: ["operations", "sales-consultants", repCode, "customers", filters],
    queryFn: () =>
      apiFetch<ConsultantCustomersResponse>(
        `operations/sales-consultants/${encodeURIComponent(repCode)}/customers${qs ? `?${qs}` : ""}`,
      ),
    enabled: repCode.trim() !== "",
  });
}