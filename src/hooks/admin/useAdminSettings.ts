import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import type {
  AcumaticaCustomerSummary,
  AcumaticaResponse,
  AcumaticaSyncLog,
  AdminHealth,
  AiProviderStatus,
  AuditLogEntry,
  DeadLetter,
  NotificationRule,
  PaginatedResponse,
  Permission,
  ReconciliationResult,
  Role,
} from "@/types/admin";
import type { AcumaticaInput, AiKeyInput } from "@/lib/admin-schemas";

const adminKey = ["admin-settings"] as const;

function showError(error: unknown) {
  toast.error(error instanceof ApiError ? error.message : "Request failed");
}

export function useAdminHealth() {
  return useQuery({
    queryKey: [...adminKey, "health"],
    queryFn: () => apiFetch<AdminHealth>("admin/health"),
  });
}

export function useAiKeys() {
  return useQuery({
    queryKey: [...adminKey, "ai-keys"],
    queryFn: () => apiFetch<AiProviderStatus[]>("admin/ai-keys"),
  });
}

export function useSaveAiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AiKeyInput) => apiFetch<AiProviderStatus>("admin/ai-keys", { method: "POST", body: payload }),
    onSuccess: () => {
      toast.success("AI key saved");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "ai-keys"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "health"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useDeleteAiKey() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<AiProviderStatus>(`admin/ai-keys/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Stored AI key deleted");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "ai-keys"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "health"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useAcumatica() {
  return useQuery({
    queryKey: [...adminKey, "acumatica"],
    queryFn: () => apiFetch<AcumaticaResponse>("admin/acumatica"),
  });
}

export function useUpdateAcumatica() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: AcumaticaInput) => apiFetch<{ config: AcumaticaResponse["config"] }>("admin/acumatica", { method: "PUT", body: payload }),
    onSuccess: () => {
      toast.success("Acumatica settings saved");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "acumatica"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useValidateAcumatica() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiFetch<{ success: boolean; message: string; response_ms: number }>("admin/acumatica/validate", { method: "POST" }),
    onSuccess: (result) => {
      toast.success(`${result.message} (${result.response_ms}ms)`);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "acumatica"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "health"] });
    },
    onError: showError,
  });
}

// -------------------------------------------------------------------------
// Sync operations
// -------------------------------------------------------------------------

export function useSyncLogs() {
  return useQuery({
    queryKey: [...adminKey, "sync-logs"],
    queryFn: () => apiFetch<AcumaticaSyncLog[]>("admin/acumatica/sync/logs"),
    refetchInterval: 10_000,
  });
}

export function useSyncCustomers() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => apiFetch<{ sync_run: AcumaticaSyncLog }>("admin/acumatica/sync/customers", { method: "POST" }),
    onSuccess: (result) => {
      const run = result.sync_run;
      if (run.status === "completed") {
        toast.success(`Customer sync complete — ${run.success_count} synced, ${run.failed_count} failed`);
      } else {
        toast.error(`Customer sync failed: ${run.error_message ?? "Unknown error"}`);
      }
      queryClient.invalidateQueries({ queryKey: [...adminKey, "sync-logs"] });
      queryClient.invalidateQueries({ queryKey: ["customers"] });
    },
    onError: showError,
  });
}

export function useSyncOrders() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { date_from: string; date_to: string }) =>
      apiFetch<{ sync_run: AcumaticaSyncLog }>("admin/acumatica/sync/orders", { method: "POST", body: payload }),
    onSuccess: (result) => {
      const run = result.sync_run;
      if (run.status === "completed") {
        toast.success(`Order sync complete — ${run.success_count} synced, ${run.failed_count} failed`);
      } else {
        toast.error(`Order sync failed: ${run.error_message ?? "Unknown error"}`);
      }
      queryClient.invalidateQueries({ queryKey: [...adminKey, "sync-logs"] });
      queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
    onError: showError,
  });
}

export function useSyncCustomerOrders() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { customer_ids: string[] }) =>
      apiFetch<{ sync_run: AcumaticaSyncLog }>("admin/acumatica/sync/customer-orders", { method: "POST", body: payload }),
    onSuccess: (result) => {
      const run = result.sync_run;
      if (run.status === "completed") {
        toast.success(`Customer order sync complete — ${run.success_count} orders synced, ${run.failed_count} failed`);
      } else {
        toast.error(`Customer order sync failed: ${run.error_message ?? "Unknown error"}`);
      }
      queryClient.invalidateQueries({ queryKey: [...adminKey, "sync-logs"] });
      queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
    onError: showError,
  });
}

export function usePreviewCustomer() {
  return useMutation({
    mutationFn: (customerId: string) =>
      apiFetch<{ customer_id: string; raw: Record<string, unknown> }>(`admin/acumatica/customers/${encodeURIComponent(customerId)}`),
    onError: showError,
  });
}

export function usePreviewOrder() {
  return useMutation({
    mutationFn: (orderNbr: string) =>
      apiFetch<{
        order_nbr: string;
        customer_details: Record<string, unknown> | null;
        document_details: Record<string, unknown>[] | null;
        payment_details: Record<string, unknown> | null;
        raw: Record<string, unknown>;
      }>(`admin/acumatica/orders/${encodeURIComponent(orderNbr)}`),
    onError: showError,
  });
}

export function useAcumaticaCustomerSearch(q: string, enabled: boolean) {
  return useQuery({
    queryKey: [...adminKey, "customer-search", q],
    queryFn: () => apiFetch<AcumaticaCustomerSummary[]>(`admin/acumatica/customers/search?q=${encodeURIComponent(q)}`),
    enabled,
    staleTime: 30_000,
  });
}

// -------------------------------------------------------------------------
// Reconciliation & dead letters
// -------------------------------------------------------------------------

export function useReconciliation() {
  return useQuery({
    queryKey: [...adminKey, "reconciliation"],
    queryFn: () => apiFetch<PaginatedResponse<ReconciliationResult>>("admin/acumatica/reconciliation"),
  });
}

export function useUpdateReconciliationStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, remediation_status }: { id: number; remediation_status: string }) =>
      apiFetch<ReconciliationResult>(`admin/acumatica/reconciliation/${id}`, { method: "PATCH", body: { remediation_status } }),
    onSuccess: () => {
      toast.success("Status updated");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "reconciliation"] });
    },
    onError: showError,
  });
}

export function useDeadLetters() {
  return useQuery({
    queryKey: [...adminKey, "dead-letters"],
    queryFn: () => apiFetch<PaginatedResponse<DeadLetter>>("admin/acumatica/dead-letters"),
  });
}

// -------------------------------------------------------------------------
// Roles / permissions / notifications / audit
// -------------------------------------------------------------------------

export function useRoles() {
  return useQuery({
    queryKey: [...adminKey, "roles"],
    queryFn: () => apiFetch<Role[]>("admin/roles"),
  });
}

export function usePermissions() {
  return useQuery({
    queryKey: [...adminKey, "permissions"],
    queryFn: () => apiFetch<Permission[]>("admin/permissions"),
  });
}

export function useNotificationRules() {
  return useQuery({
    queryKey: [...adminKey, "notification-rules"],
    queryFn: () => apiFetch<NotificationRule[]>("admin/notification-rules"),
  });
}

export function useToggleNotificationRule() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, is_enabled }: { id: number; is_enabled: boolean }) =>
      apiFetch<NotificationRule>(`admin/notification-rules/${id}`, { method: "PUT", body: { is_enabled } }),
    onSuccess: () => {
      toast.success("Notification rule updated");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "notification-rules"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

// -------------------------------------------------------------------------
// Email import configs
// -------------------------------------------------------------------------

export interface EmailImportConfig {
  id: number;
  sender_pattern: string;
  is_wildcard: boolean;
  display_name: string;
  customer_class: string | null;
  po_patterns: string[] | null;
  po_extraction_source: "subject" | "body" | "pdf" | "all";
  ai_fallback_enabled: boolean;
  is_active: boolean;
  notes: string | null;
}

export function useEmailImportConfigs() {
  return useQuery({
    queryKey: [...adminKey, "email-import-configs"],
    queryFn: () => apiFetch<EmailImportConfig[]>("admin/email-import-configs"),
  });
}

export function useSaveEmailImportConfig() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: Partial<EmailImportConfig> & { id?: number }) =>
      payload.id
        ? apiFetch<EmailImportConfig>(`admin/email-import-configs/${payload.id}`, { method: "PUT", body: payload })
        : apiFetch<EmailImportConfig>("admin/email-import-configs", { method: "POST", body: payload }),
    onSuccess: () => {
      toast.success("Sender config saved");
      qc.invalidateQueries({ queryKey: [...adminKey, "email-import-configs"] });
    },
    onError: showError,
  });
}

export function useDeleteEmailImportConfig() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch(`admin/email-import-configs/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Sender config deleted");
      qc.invalidateQueries({ queryKey: [...adminKey, "email-import-configs"] });
    },
    onError: showError,
  });
}

export function useTestSender() {
  return useMutation({
    mutationFn: (email: string) =>
      apiFetch<{ email: string; matched: boolean; config: EmailImportConfig | null }>(
        "admin/email-import-configs/test-sender",
        { method: "POST", body: { email } },
      ),
    onError: showError,
  });
}

// -------------------------------------------------------------------------
// Order matching
// -------------------------------------------------------------------------

export interface OrderMatchRun {
  id: number;
  status: "running" | "completed" | "failed";
  emails_processed: number;
  po_extracted: number;
  matched: number;
  unmatched: number;
  duplicate: number;
  missing_in_acumatica: number;
  started_at: string;
  ended_at: string | null;
  error_message: string | null;
  summary: Record<string, number> | null;
}

export function useMatchOrders() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string; extraction: { processed: number; extracted: number }; match_run: OrderMatchRun }>(
        "admin/order-matching/run-all",
        { method: "POST" },
      ),
    onSuccess: (result) => {
      toast.success(result.message);
      qc.invalidateQueries({ queryKey: ["orders"] });
      qc.invalidateQueries({ queryKey: [...adminKey, "match-history"] });
    },
    onError: showError,
  });
}

export function useMatchHistory() {
  return useQuery({
    queryKey: [...adminKey, "match-history"],
    queryFn: () => apiFetch<OrderMatchRun[]>("admin/order-matching/history"),
  });
}

export function useAuditLogs() {
  return useQuery({
    queryKey: [...adminKey, "audit-logs"],
    queryFn: () => apiFetch<PaginatedResponse<AuditLogEntry>>("admin/audit-logs"),
  });
}
