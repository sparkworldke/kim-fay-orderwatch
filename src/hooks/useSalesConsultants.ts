import { useQuery } from "@tanstack/react-query";
import { apiFetch, ApiError } from "@/lib/api";

export interface SalesConsultantOption {
  rep_code: string;
  name: string;
  employee_number: string | null;
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
  fill_rate_pct: number | null;
  revenue_lost: number | null;
  first_order_date: string | null;
  last_order_date: string | null;
  orders_per_month: number | null;
}

export interface ConsultantCustomersPagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from: number;
  to: number;
}

export interface ConsultantCustomersResponse {
  rep_code: string | null;
  summary: ConsultantCustomerSummary;
  customers: ConsultantCustomerRow[];
  pagination?: ConsultantCustomersPagination;
}

export interface SalesConsultantProfile {
  id: number;
  name: string;
  email: string;
  role: string;
  rep_code: string | null;
  employee_number: string | null;
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

export type ConsultantCustomerFilters = ConsultantDateFilters & {
  q?: string;
  page?: number;
  per_page?: number;
  sort?: string;
  sort_dir?: "asc" | "desc";
};

function buildDateQuery(filters: ConsultantDateFilters) {
  const params = new URLSearchParams();
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

function buildCustomerQuery(filters: ConsultantCustomerFilters) {
  const params = new URLSearchParams();
  if (filters.date_from) params.set("date_from", filters.date_from);
  if (filters.date_to) params.set("date_to", filters.date_to);
  if (filters.q) params.set("q", filters.q);
  if (filters.page) params.set("page", String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));
  if (filters.sort) params.set("sort", filters.sort);
  if (filters.sort_dir) params.set("sort_dir", filters.sort_dir);
  const qs = params.toString();
  return qs ? `?${qs}` : "";
}

function shouldFallbackToRepCode(error: unknown) {
  return error instanceof ApiError && [404, 405].includes(error.status);
}

function normalizeConsultantIdentifier(identifier: string | number) {
  return String(identifier).trim();
}

function isNumericIdentifier(identifier: string) {
  return /^\d+$/.test(identifier);
}

async function fetchConsultantFromList(identifier: string | number) {
  const normalized = normalizeConsultantIdentifier(identifier);
  const list = await apiFetch<{ items: SalesConsultantProfile[] }>("operations/sales-consultants");
  const consultant = list.items.find((item) => {
    const repCode = item.rep_code?.trim().toUpperCase();

    return String(item.id) === normalized || (repCode !== undefined && repCode === normalized.toUpperCase());
  });

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

async function fetchCustomersWithFilters(repCode: string, filters: ConsultantCustomerFilters) {
  return apiFetch<ConsultantCustomersResponse>(
    `operations/sales-consultants/${encodeURIComponent(repCode)}/customers${buildCustomerQuery(filters)}`,
  );
}

export function useConsultantDetail(
  consultantIdentifier: string | number,
  filters: ConsultantDateFilters = {},
) {
  const identifier = normalizeConsultantIdentifier(consultantIdentifier);

  return useQuery({
    queryKey: ["operations", "sales-consultants", identifier, "detail", filters],
    queryFn: async () => {
      try {
        return await apiFetch<ConsultantDetailResponse>(
          `operations/sales-consultants/${encodeURIComponent(identifier)}${buildDateQuery(filters)}`,
        );
      } catch (error) {
        if (!shouldFallbackToRepCode(error)) throw error;

        const consultant = await fetchConsultantFromList(identifier);
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
    enabled: identifier !== "",
  });
}

export function useConsultantCustomers(
  consultantIdentifier: string | number,
  filters: ConsultantCustomerFilters = {},
) {
  const identifier = normalizeConsultantIdentifier(consultantIdentifier);

  return useQuery({
    queryKey: ["operations", "sales-consultants", identifier, "customers", filters],
    queryFn: async () => {
      try {
        return await apiFetch<ConsultantCustomersResponse>(
          `operations/sales-consultants/${encodeURIComponent(identifier)}/customers${buildCustomerQuery(filters)}`,
        );
      } catch (error) {
        if (!shouldFallbackToRepCode(error)) throw error;

        if (!isNumericIdentifier(identifier)) throw error;

        const consultant = await fetchConsultantFromList(identifier);
        return fetchCustomersWithFilters(consultant.rep_code, filters);
      }
    },
    enabled: identifier !== "",
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
