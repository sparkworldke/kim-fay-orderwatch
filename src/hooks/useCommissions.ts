import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type CommissionStatement = {
  id: number;
  target_amount: string;
  eligible_sales: string;
  attainment_pct: string;
  gross_commission: string;
  adjustment_amount: string;
  net_commission: string;
  user: { id: number; name: string; rep_code: string | null };
  period: { id: number; period_month: string; version: number; status: string; shadow_mode: boolean };
};

type Page = { data: CommissionStatement[]; current_page: number; last_page: number; total: number };

export function useCommissionStatements(page = 1) {
  return useQuery({
    queryKey: ["commission-statements", page],
    queryFn: () => apiFetch<Page>(`kp/commissions?page=${page}&per_page=25`),
  });
}

export function useCreateCommissionPeriod() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: { period_month: string; shadow_mode: boolean }) =>
      apiFetch("kp/commissions/periods", { method: "POST", body: JSON.stringify(input) }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["commission-statements"] }),
  });
}

export function useCommissionTransition() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: ({ periodId, action, reason }: { periodId: number; action: string; reason?: string }) =>
      apiFetch(`kp/commissions/periods/${periodId}/transition`, {
        method: "POST",
        body: JSON.stringify({ action, reason }),
      }),
    onSuccess: () => client.invalidateQueries({ queryKey: ["commission-statements"] }),
  });
}
