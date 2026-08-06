import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type ProductionSummary = {
  total_skus: number;
  critical_skus: number;
  at_risk_skus: number;
  healthy_skus: number;
  qty_available: number;
  avg_months_of_cover: number | null;
  skus_below_msi: number;
  requirement: number;
  freshness: Record<string, string | null>;
};

type SummaryFilters = {
  ownership: "manufactured" | "partner";
  warehouseIds?: string[];
  brands?: string[];
  categories?: string[];
  tradingGroups?: string[];
  sites?: string[];
  machines?: string[];
  businessLines?: string[];
  statuses?: string[];
  search?: string;
};

export function useProductionSummary(filters: SummaryFilters) {
  return useQuery({
    queryKey: ["production-kpi", "today", filters],
    queryFn: () => {
      const params = new URLSearchParams({ ownership: filters.ownership });
      const arrays: Array<[string, string[] | undefined]> = [
        ["warehouse_ids", filters.warehouseIds],
        ["brand", filters.brands],
        ["category", filters.categories],
        ["trading_group", filters.tradingGroups],
        ["site", filters.sites],
        ["machine", filters.machines],
        ["business_line", filters.businessLines],
        ["status", filters.statuses],
      ];
      arrays.forEach(([key, values]) => values?.forEach((value) => params.append(`${key}[]`, value)));
      if (filters.search?.trim()) params.set("search", filters.search.trim());
      return apiFetch<ProductionSummary>(`operations/production/summary?${params}`);
    },
    staleTime: 60_000,
    placeholderData: (previous) => previous,
    refetchOnWindowFocus: false,
  });
}
