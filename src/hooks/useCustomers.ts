import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import type { AcumaticaCustomer, PaginatedResponse } from "@/types/admin";

interface CustomerFilters {
  q?: string;
  class?: string;
  status?: string;
  page?: number;
  per_page?: number;
}

export function useCustomers(filters: CustomerFilters = {}) {
  const params = new URLSearchParams();
  if (filters.q)      params.set("q", filters.q);
  if (filters.class)  params.set("class", filters.class);
  if (filters.status) params.set("status", filters.status);
  if (filters.page)     params.set("page",     String(filters.page));
  if (filters.per_page) params.set("per_page", String(filters.per_page));

  const qs = params.toString();

  return useQuery({
    queryKey: ["customers", filters],
    queryFn: () => apiFetch<PaginatedResponse<AcumaticaCustomer>>(`customers${qs ? `?${qs}` : ""}`),
  });
}
