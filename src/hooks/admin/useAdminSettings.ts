import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import type {
  AcumaticaCustomerSummary,
  AcumaticaSalesOrder,
  AcumaticaResponse,
  AcumaticaSyncLog,
  AdminHealth,
  AiPromptLog,
  AiPromptLogStats,
  AiProviderStatus,
  AuditLogEntry,
  DeadLetter,
  CronJob,
  CronRunLog,
  DailyReportConfig,
  DailyReportRun,
  NotificationRule,
  PaginatedResponse,
  Permission,
  ReconciliationResult,
  Role,
  TeamMember,
  CreateTeamMemberInput,
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

export function useTeamMembers() {
  return useQuery({
    queryKey: [...adminKey, "team-members"],
    queryFn: () => apiFetch<TeamMember[]>("admin/users"),
  });
}

export function useCreateTeamMember() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateTeamMemberInput) =>
      apiFetch<TeamMember>("admin/users", { method: "POST", body: payload }),
    onSuccess: () => {
      toast.success("Team member created and welcome email sent");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "roles"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
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
        { method: "POST", timeoutMs: 300_000 },
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

export interface MatchEvidence {
  po_number: string;
  source: string;
  method: string;
  confidence: number;
  raw_match: string;
}

export interface MatchReviewEmail {
  id: number;
  subject: string | null;
  from_email: string | null;
  received_at: string | null;
  extracted_po_number: string | null;
  match_classification: "needs_review" | "matched_discrepancies" | "not_matched";
  match_sources: string[] | null;
  match_evidence: MatchEvidence[] | null;
  match_conflicts: Array<{ field: string; email_value: string; acumatica_value: string; reason: string }> | null;
  match_reason_codes: string[] | null;
  match_rule_version: string | null;
  matched_order: AcumaticaSalesOrder | null;
  attachments: Array<{ id: number; name: string | null; extraction_status: string; extraction_confidence: number | null; extraction_error: string | null }>;
}

export function usePendingMatchReviews() {
  return useQuery({
    queryKey: [...adminKey, "match-reviews"],
    queryFn: () => apiFetch<PaginatedResponse<MatchReviewEmail>>("admin/order-matching/pending-manual"),
  });
}

export function useReviewMatch() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ emailId, decision, reason }: { emailId: number; decision: "approved" | "rejected" | "acknowledged"; reason: string }) =>
      apiFetch<MatchReviewEmail>(`admin/order-matching/${emailId}/review`, { method: "POST", body: { decision, reason } }),
    onSuccess: () => {
      toast.success("Match review recorded.");
      qc.invalidateQueries({ queryKey: [...adminKey, "match-reviews"] });
      qc.invalidateQueries({ queryKey: ["orders"] });
    },
  });
}

export function useAuditLogs() {
  return useQuery({
    queryKey: [...adminKey, "audit-logs"],
    queryFn: () => apiFetch<PaginatedResponse<AuditLogEntry>>("admin/audit-logs"),
  });
}

export function useCronJobs() {
  return useQuery({
    queryKey: [...adminKey, "cron-jobs"],
    queryFn: () => apiFetch<CronJob[]>("admin/cron-jobs"),
    refetchInterval: 5000,
  });
}

export function useCronRuns(jobId: number | null, status: "all" | "failures" | "successes" = "all") {
  return useQuery({
    queryKey: [...adminKey, "cron-jobs", jobId, "runs", status],
    queryFn: () => apiFetch<PaginatedResponse<CronRunLog>>(`admin/cron-jobs/${jobId}/runs?status=${status}`),
    enabled: jobId !== null,
    refetchInterval: 5000,
  });
}

export function useUpdateCronJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: { id: number; is_enabled?: boolean; notes?: string | null; settings?: Partial<CronJob["settings"]> }) =>
      apiFetch<CronJob>(`admin/cron-jobs/${id}`, { method: "PATCH", body }),
    onSuccess: () => {
      toast.success("Cron settings saved.");
      qc.invalidateQueries({ queryKey: [...adminKey, "cron-jobs"] });
    },
    onError: showError,
  });
}

export function useRunCronJob() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch<{ message: string }>(`admin/cron-jobs/${id}/run`, { method: "POST" }),
    onSuccess: (result) => {
      toast.success(result.message);
      setTimeout(() => qc.invalidateQueries({ queryKey: [...adminKey, "cron-jobs"] }), 500);
    },
    onError: showError,
  });
}

export function useAiPromptLogs(params?: { intent?: string; status?: string; start_date?: string; end_date?: string }) {
  const search = new URLSearchParams();
  if (params?.intent)     search.set("intent",     params.intent);
  if (params?.status)     search.set("status",      params.status);
  if (params?.start_date) search.set("start_date",  params.start_date);
  if (params?.end_date)   search.set("end_date",    params.end_date);
  const qs = search.toString() ? `?${search.toString()}` : "";

  return useQuery({
    queryKey: [...adminKey, "ai-prompt-logs", params],
    queryFn: () => apiFetch<PaginatedResponse<AiPromptLog>>(`admin/ai-prompt-logs${qs}`),
  });
}

export function useAiPromptLogStats() {
  return useQuery({
    queryKey: [...adminKey, "ai-prompt-logs", "stats"],
    queryFn: () => apiFetch<AiPromptLogStats>("admin/ai-prompt-logs/stats"),
  });
}

export function useDailyReportConfig() {
  return useQuery({
    queryKey: [...adminKey, "daily-reports", "config"],
    queryFn: () => apiFetch<DailyReportConfig>("admin/daily-reports/config"),
  });
}

export function useDailyReportRuns() {
  return useQuery({
    queryKey: [...adminKey, "daily-reports", "runs"],
    queryFn: () => apiFetch<PaginatedResponse<DailyReportRun>>("admin/daily-reports/runs"),
    refetchInterval: 10000,
  });
}

export function useUpdateDailyReportConfig() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: Partial<DailyReportConfig> & { recipients?: string[]; reply_to?: string[] }) =>
      apiFetch<DailyReportConfig>("admin/daily-reports/config", { method: "PUT", body }),
    onSuccess: () => {
      toast.success("Daily report settings saved.");
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
      qc.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useTestDailyReport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (recipients?: string[]) =>
      apiFetch<{ message: string; run: DailyReportRun }>("admin/daily-reports/test-send", {
        method: "POST",
        body: recipients ? { recipients } : {},
      }),
    onSuccess: (result) => {
      toast.success(result.message);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
    onError: showError,
  });
}

export function useResendDailyReport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string; run: DailyReportRun }>("admin/daily-reports/resend-last", { method: "POST" }),
    onSuccess: (result) => {
      toast.success(result.message);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
    onError: showError,
  });
}
