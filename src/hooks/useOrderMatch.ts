import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api";

export type OrderMatchFolder = {
  id: number;
  folder_name: string;
  mailbox_email: string | null;
  sync_enabled: boolean;
  is_order_folder: boolean;
  auto_sync_cron: string | null;
  last_synced_at: string | null;
};

export type TopPrediction = {
  id: number;
  order_nbr: string | null;
  confidence: number;
  match_type: string;
  reasoning: string | null;
  label: "high" | "medium" | "low" | "no_match";
};

export type QueueEmail = {
  id: number;
  subject: string | null;
  from_email: string | null;
  from_name: string | null;
  body_preview: string;
  received_at: string | null;
  canonical_po: string | null;
  extraction_status: string;
  match_status: string;
  duplicate_flag: string | null;
  canonical_email_id: number | null;
  top_prediction: TopPrediction | null;
  customer: { id: string | null; name: string | null };
};

export type QueueGroup = {
  main_account_id: string;
  main_account_name: string;
  email_count: number;
  revenue_at_risk: number;
  emails: QueueEmail[];
};

export function useOrderMatchFolders() {
  return useQuery({
    queryKey: ["order-match-folders"],
    queryFn: () => apiFetch<{ folders: OrderMatchFolder[] }>("order-match/folders"),
  });
}

export function useOrderMatchQueue(status = "pending", page = 1) {
  return useQuery({
    queryKey: ["order-match-queue", status, page],
    queryFn: () =>
      apiFetch<{
        groups: QueueGroup[];
        total: number;
        current_page: number;
        last_page: number;
      }>(`order-match/queue?status=${status}&page=${page}&pageSize=50`),
  });
}

export function useSyncOrderMatchFolder() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ folderId, from, to }: { folderId: number; from: string; to: string }) =>
      apiFetch(`order-match/folders/${folderId}/sync`, {
        method: "POST",
        body: { from, to },
      }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["order-match-folders"] });
      qc.invalidateQueries({ queryKey: ["order-match-queue"] });
    },
  });
}

export function useRunOrderMatchPipeline() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () => apiFetch("order-match/run", { method: "POST", timeoutMs: 300_000 }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["order-match-queue"] }),
  });
}

export function useAcceptMatch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ emailId, confirmLow }: { emailId: number; confirmLow?: boolean }) =>
      apiFetch(`order-match/matches/${emailId}/accept`, {
        method: "POST",
        body: confirmLow ? { confirm_low_confidence: true } : {},
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["order-match-queue"] }),
  });
}

export function useRejectMatch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ emailId, reason }: { emailId: number; reason: string }) =>
      apiFetch(`order-match/matches/${emailId}/reject`, {
        method: "POST",
        body: { reason },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ["order-match-queue"] }),
  });
}

export function confidenceBadgeVariant(label: string | undefined) {
  switch (label) {
    case "high": return "default" as const;
    case "medium": return "secondary" as const;
    case "low": return "destructive" as const;
    default: return "outline" as const;
  }
}