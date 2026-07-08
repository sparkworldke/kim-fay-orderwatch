import { useQuery } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";
import { InventoryItem } from "@/hooks/useOperations";

export type SkuDetailResponse = {
  item: InventoryItem & {
    item_status: string;
    valuation_method: string;
    last_cost: number;
    average_cost: number;
    last_modified_at: string | null;
  };
  monthly_sales: Array<{
    month: string;       // "YYYY-MM"
    month_label: string; // "Jan 2024"
    shipped_qty: number;
    predicted_qty: number;
    is_future: boolean;
  }>;
  prediction_period: { from: string; to: string };
};

export function useSkuDetail(
  inventoryId: string | null,
  dateFrom: string,
  dateTo: string,
) {
  return useQuery({
    queryKey: ["inventory-sku-detail", inventoryId, dateFrom, dateTo],
    enabled: inventoryId !== null,
    queryFn: () => {
      const qs = new URLSearchParams({ date_from: dateFrom, date_to: dateTo });
      return apiFetch<SkuDetailResponse>(
        `operations/inventory/${inventoryId}/sku-detail?${qs}`,
        { timeoutMs: 10_000 },
      );
    },
  });
}
