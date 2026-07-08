/**
 * useSkuInsights
 *
 * Fetches AI-powered variance insights for a single SKU. The query is
 * non-cached (staleTime / gcTime = 0) so that changing the date range always
 * triggers a fresh LLM-backed request, and the previous in-flight request is
 * cancelled via TanStack Query's built-in AbortController integration.
 *
 * Requirements: 3.9, 3.10
 */

import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type InsightFindingType =
  | "promotion_impact"
  | "price_change_impact"
  | "unexplained_variance";

export type InsightFinding = {
  type: InsightFindingType;
  month: string;
  text: string;
  promotion_name?: string;
  variance_pct?: number;
  variance_abs?: number;
  price_direction?: "upward" | "downward";
  price_magnitude?: number;
};

export type SkuInsightResponse = {
  insights: InsightFinding[];
  data_gaps?: string[];
  ai_status: "success" | "failed" | "unavailable";
};

export function useSkuInsights(
  inventoryId: string | null,
  dateFrom: string,
  dateTo: string,
) {
  return useQuery({
    queryKey: ["inventory-sku-insights", inventoryId, dateFrom, dateTo],
    enabled: inventoryId !== null,
    staleTime: 0,
    gcTime: 0,
    // Never retry on failure — the UI shows a retry button instead.
    retry: false,
    queryFn: ({ signal }) => {
      const qs = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });
      return apiFetch<SkuInsightResponse>(
        `operations/inventory/${inventoryId}/insights?${qs}`,
        // The AbortSignal from TanStack Query is forwarded; apiFetch also
        // applies a 30s server-side timeout (mirrored client-side).
        { timeoutMs: 30_000, signal },
      );
    },
  });
}
