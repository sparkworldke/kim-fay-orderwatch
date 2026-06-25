import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

type Paginated<T> = {
  data: T[];
  total: number;
  current_page: number;
  last_page: number;
  per_page: number;
};

export type InventoryPrediction = {
  daily_run_rate: number | null;
  days_until_stockout: number | null;
  prediction_status: string;
  qty_delta: number | null;
  logged_at: string;
};

export type InventoryItem = {
  id: number;
  inventory_id: string;
  description: string | null;
  item_class: string | null;
  default_uom: string | null;
  default_warehouse_id: string | null;
  qty_on_hand: string;
  qty_available: string | null;
  sales_price: string;
  synced_at: string | null;
  prediction: InventoryPrediction | null;
};

export type BackorderLine = {
  id: number;
  order_nbr: string;
  inventory_id: string;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  order_qty: string;
  shipped_qty: string;
  open_qty: string;
  backorder_qty: string;
  cancelled_qty: string;
  qty_at_approval: string | null;
  fulfillment_status: string | null;
  unit_price: string;
  revenue_at_risk: string;
  warehouse_id: string | null;
  currency_id: string | null;
  synced_at: string | null;
};

export type FillRateSnapshot = {
  id: number;
  order_nbr: string;
  customer_acumatica_id: string | null;
  status: string | null;
  total_ordered_qty: string;
  total_shipped_qty: string;
  fill_rate_pct: string | null;
  fill_rate_status: string;
  revenue_not_shipped: string;
  currency_id: string | null;
  computed_at: string | null;
  order?: { id: number; acumatica_order_nbr: string; customer_name: string | null; order_date: string | null };
};

export function useInventorySummary() {
  return useQuery({
    queryKey: ["operations-inventory-summary"],
    queryFn: () => apiFetch<{
      total_items: number;
      low_stock_count: number;
      at_risk_count: number;
      last_synced_at: string | null;
    }>("operations/inventory/summary"),
  });
}

export function useInventory(params: {
  q?: string;
  low_stock?: boolean;
  prediction_status?: string;
  page?: number;
  per_page?: number;
}) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.low_stock) qs.set("low_stock", "1");
  if (params.prediction_status) qs.set("prediction_status", params.prediction_status);
  qs.set("page", String(params.page ?? 1));
  qs.set("per_page", String(params.per_page ?? 50));

  return useQuery({
    queryKey: ["operations-inventory", params],
    queryFn: () => apiFetch<Paginated<InventoryItem>>(`operations/inventory?${qs}`),
  });
}

export function useBackordersSummary() {
  return useQuery({
    queryKey: ["operations-backorders-summary"],
    queryFn: () => apiFetch<{
      open_lines: number;
      open_orders: number;
      revenue_at_risk: number;
      total_open_qty: number;
      last_synced_at: string | null;
    }>("operations/backorders/summary"),
  });
}

export function useBackorders(params: { q?: string; customer_id?: string; page?: number; per_page?: number }) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.customer_id) qs.set("customer_id", params.customer_id);
  qs.set("page", String(params.page ?? 1));
  qs.set("per_page", String(params.per_page ?? 50));

  return useQuery({
    queryKey: ["operations-backorders", params],
    queryFn: () => apiFetch<Paginated<BackorderLine>>(`operations/backorders?${qs}`),
  });
}

export function useBackordersByAccount(top = 10) {
  return useQuery({
    queryKey: ["operations-backorders-accounts", top],
    queryFn: () => apiFetch<{ accounts: Array<{
      customer_acumatica_id: string;
      customer_name: string | null;
      order_count: number;
      open_lines: number;
      revenue_at_risk: string;
      total_open_qty: string;
    }> }>(`operations/backorders/by-account?top=${top}`),
  });
}

export function useFillRateSummary(dateFrom: string, dateTo: string) {
  return useQuery({
    queryKey: ["operations-fill-rate-summary", dateFrom, dateTo],
    queryFn: () => apiFetch<{
      date_from: string;
      date_to: string;
      overall_fill_rate: number | null;
      overall_status: string;
      revenue_not_shipped: number;
      order_count: number;
      healthy_count: number;
      at_risk_count: number;
      critical_count: number;
      na_count: number;
      last_computed_at: string | null;
    }>(`operations/fill-rate/summary?date_from=${dateFrom}&date_to=${dateTo}`),
  });
}

export function useFillRate(params: { q?: string; status?: string; page?: number; per_page?: number }) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.status) qs.set("status", params.status);
  qs.set("page", String(params.page ?? 1));
  qs.set("per_page", String(params.per_page ?? 50));

  return useQuery({
    queryKey: ["operations-fill-rate", params],
    queryFn: () => apiFetch<Paginated<FillRateSnapshot>>(`operations/fill-rate?${qs}`),
  });
}

export type OpsSyncRun = {
  id: number;
  status: string;
  record_count: number;
  success_count: number;
  failed_count: number;
  error_message?: string | null;
};

export function formatOpsSyncToast(label: string, run: OpsSyncRun): string {
  const base =
    run.status === "completed"
      ? `${label} updated: ${run.success_count} saved`
      : `${label} failed: ${run.error_message ?? "Unknown error"}`;

  const parts = [base];
  if (run.record_count > 0 && run.record_count !== run.success_count) {
    parts.push(`${run.record_count} processed from Acumatica`);
  }
  if (run.failed_count > 0) {
    parts.push(`${run.failed_count} errors`);
  }

  return parts.join(" · ");
}

function useOpsSync(endpoint: string, keys: string[]) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body?: Record<string, string>) =>
      apiFetch<{ sync_run: OpsSyncRun }>(
        `admin/acumatica/sync/${endpoint}`,
        { method: "POST", body: body ?? undefined },
      ),
    onSuccess: () => {
      keys.forEach((k) => qc.invalidateQueries({ queryKey: [k] }));
      qc.invalidateQueries({ queryKey: ["admin-settings", "sync-logs"] });
    },
  });
}

export function useSyncInventory() {
  return useOpsSync("inventory", ["operations-inventory", "operations-inventory-summary"]);
}

export function useSyncBackorders() {
  return useOpsSync("backorders", ["operations-backorders", "operations-backorders-summary", "operations-backorders-accounts"]);
}

export function useSyncFillRate() {
  return useOpsSync("fill-rate", ["operations-fill-rate", "operations-fill-rate-summary"]);
}

export function useSyncCreditNotesAndMore() {
  return useOpsSync("credit-notes-more", ["orders", "order-stats"]);
}

export function fillRateStatusColor(status: string): "default" | "secondary" | "destructive" | "outline" {
  switch (status) {
    case "healthy": return "default";
    case "at_risk": return "secondary";
    case "critical": return "destructive";
    default: return "outline";
  }
}

export function predictionStatusLabel(status: string | null | undefined): string {
  switch (status) {
    case "critical": return "Stockout ≤ 7 days";
    case "at_risk": return "Stockout ≤ 14 days";
    case "healthy": return "Stock healthy";
    case "stable_or_replenished": return "Stable / replenished";
    case "insufficient_history": return "Needs more sync history";
    default: return status ?? "Unknown";
  }
}