import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { API_BASE_URL, apiFetch, ApiError } from "@/lib/api";
import { getToken } from "@/lib/auth";
import type {
  AcumaticaCustomerSummary,
  AcumaticaLookupResult,
  AcumaticaLookupType,
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
  MailSettings,
  MailSettingsInput,
  NotificationRule,
  PaginatedResponse,
  Permission,
  ReconciliationResult,
  Role,
  TeamMember,
  CreateTeamMemberInput,
  UpdateTeamMemberInput,
  RepCodeHistoryEntry,
  UserSessionEntry,
  DeliverySlaConfigRule,
  StaffImportGap,
  StaffImportResult,
  CustomerBackfillResult,
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

export function useMailSettings() {
  return useQuery({
    queryKey: [...adminKey, "mail-settings"],
    queryFn: () => apiFetch<MailSettings>("admin/mail-settings"),
  });
}

export function useUpdateMailSettings() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: MailSettingsInput) =>
      apiFetch<MailSettings>("admin/mail-settings", { method: "PATCH", body: payload }),
    onSuccess: () => {
      toast.success("Mail settings saved");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "mail-settings"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "health"] });
    },
    onError: showError,
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
    refetchInterval: 5_000,
  });
}

export interface SyncDiagnosis {
  summary: string;
  likely_causes: string[];
  next_steps: string[];
  ai_status: "success" | "unavailable" | "failed";
  ai_error?: string;
  logs_considered: number;
}

export function useDiagnoseSyncHealth() {
  return useMutation({
    mutationFn: () => apiFetch<SyncDiagnosis>("admin/acumatica/sync/diagnose", { method: "POST" }),
    onError: showError,
  });
}

export function useStopSyncLog() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<{ message: string; sync_run: AcumaticaSyncLog }>(`admin/acumatica/sync/logs/${id}/stop`, {
        method: "POST",
      }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "sync-logs"] });
      queryClient.invalidateQueries({ queryKey: ["operations-status"] });
      queryClient.invalidateQueries({ queryKey: ["operations-inventory"] });
      queryClient.invalidateQueries({ queryKey: ["operations-inventory-summary"] });
      queryClient.invalidateQueries({ queryKey: ["operations-backorders"] });
      queryClient.invalidateQueries({ queryKey: ["operations-backorders-summary"] });
      queryClient.invalidateQueries({ queryKey: ["operations-fill-rate"] });
      queryClient.invalidateQueries({ queryKey: ["operations-fill-rate-summary"] });
      queryClient.invalidateQueries({ queryKey: ["orders"] });
    },
    onError: showError,
  });
}

export function useSyncCustomers() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { date_from: string; date_to: string }) =>
      apiFetch<{ sync_run: AcumaticaSyncLog }>("admin/acumatica/sync/customers", { method: "POST", body: payload }),
    onSuccess: (result) => {
      const run = result.sync_run;
      if (run.status === "completed") {
        toast.success(`Customer sync complete — ${run.success_count} synced, ${run.failed_count} failed`);
      } else if (run.status === "stopped") {
        toast.warning(run.error_message ?? "Customer sync stopped.");
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
    mutationFn: (payload: {
      date_from: string;
      date_to: string;
      /** full = import all SO in range; updates_only = recheck existing local SOs only */
      mode?: "full" | "updates_only";
      updates_only?: boolean;
      max_orders?: number;
    }) =>
      apiFetch<{ sync_run: AcumaticaSyncLog; mode?: string }>("admin/acumatica/sync/orders", {
        method: "POST",
        body: payload,
        timeoutMs: 300_000,
      }),
    onSuccess: (result) => {
      const run = result.sync_run;
      const updatesOnly = result.mode === "updates_only" || run.sync_type === "sales_order_status_updates";
      if (run.status === "completed") {
        if (updatesOnly) {
          const checked = Number(run.filters?.status_comparison_count ?? run.record_count ?? 0);
          const updated = Number(run.filters?.status_updates ?? 0);
          const deleted = Number(run.filters?.orders_deleted_missing_from_acumatica ?? 0);
          toast.success(
            `Order updates check complete — ${updated} updated, ${deleted} removed, ${checked} checked`,
          );
        } else {
          toast.success(`Order sync complete — ${run.success_count} synced, ${run.failed_count} failed`);
        }
      } else if (run.status === "stopped") {
        toast.warning(run.error_message ?? "Order sync stopped.");
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
    mutationFn: (payload: { customer_ids: string[]; date_from: string; date_to: string }) =>
      apiFetch<{ sync_run: AcumaticaSyncLog }>("admin/acumatica/sync/customer-orders", { method: "POST", body: payload }),
    onSuccess: (result) => {
      const run = result.sync_run;
      if (run.status === "completed") {
        toast.success(`Customer order sync complete — ${run.success_count} orders synced, ${run.failed_count} failed`);
      } else if (run.status === "stopped") {
        toast.warning(run.error_message ?? "Customer order sync stopped.");
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

export function useAcumaticaLookup() {
  return useMutation({
    mutationFn: ({ type, id }: { type: AcumaticaLookupType; id: string }) =>
      apiFetch<AcumaticaLookupResult>(
        `admin/acumatica/lookup?type=${encodeURIComponent(type)}&id=${encodeURIComponent(id)}`,
      ),
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

export function useDepartments() {
  return useQuery({
    queryKey: [...adminKey, "departments"],
    queryFn: () => apiFetch<import("@/types/admin").Department[]>("admin/departments"),
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

export function useResendWelcomeEmail() {
  return useMutation({
    mutationFn: (userId: number) =>
      apiFetch<{ message: string }>(`admin/users/${userId}/resend-welcome`, { method: "POST" }),
    onSuccess: (result) => {
      toast.success(result.message);
    },
    onError: showError,
  });
}

export type UpdateUserPasswordInput = {
  userId: number;
  auto_generate: boolean;
  password?: string;
  email_user?: boolean;
};

export type UpdateUserPasswordResult = {
  message: string;
  auto_generate: boolean;
  emailed: boolean;
  password: string | null;
};

export function useUpdateUserPassword() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ userId, ...body }: UpdateUserPasswordInput) =>
      apiFetch<UpdateUserPasswordResult>(`admin/users/${userId}/password`, {
        method: "POST",
        body,
      }),
    onSuccess: (result) => {
      toast.success(result.message);
      if (result.password) {
        toast.message("Generated password", {
          description: result.password,
          duration: 20_000,
        });
      }
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useToggleUserStatus() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) =>
      apiFetch<{ message: string; is_active: boolean }>(`admin/users/${userId}/toggle-status`, { method: "PATCH" }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useBulkActivateUsers() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ user_ids, set_verified_date }: { user_ids: number[]; set_verified_date: boolean }) =>
      apiFetch<{ message: string; activated_count: number }>(`admin/users/bulk-activate`, {
        method: "POST",
        body: { user_ids, set_verified_date },
      }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useDeleteUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) =>
      apiFetch<{ message: string }>(`admin/users/${userId}`, { method: "DELETE" }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useUpdateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ userId, ...payload }: UpdateTeamMemberInput & { userId: number }) =>
      apiFetch<TeamMember>(`admin/users/${userId}`, { method: "PATCH", body: payload }),
    onSuccess: () => {
      toast.success("Team member updated");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useRepCodeHistory(userId: number | null) {
  return useQuery({
    queryKey: [...adminKey, "rep-code-history", userId],
    queryFn: () => apiFetch<RepCodeHistoryEntry[]>(`admin/users/${userId}/rep-code-history`),
    enabled: userId !== null,
  });
}

export function useUserSessions(userId: number | null, page = 1) {
  return useQuery({
    queryKey: [...adminKey, "user-sessions", userId, page],
    queryFn: () =>
      apiFetch<PaginatedResponse<UserSessionEntry>>(`admin/users/${userId}/sessions?page=${page}`),
    enabled: userId !== null,
  });
}

export function useBrandOptions() {
  return useQuery({
    queryKey: [...adminKey, "brands"],
    queryFn: () =>
      apiFetch<{ data: Array<{ id: number; name: string; ownership: string | null; is_active: boolean; partner_group?: { id: number; name: string } | null }> }>("admin/brands"),
  });
}

export function useSyncBrandAssignments() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ userId, brand_ids }: { userId: number; brand_ids: number[] }) =>
      apiFetch<{ message: string; brands: string[] }>(`admin/users/${userId}/brand-assignments`, {
        method: "PUT",
        body: { brand_ids },
      }),
    onSuccess: () => {
      toast.success("Brand assignments saved");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
    },
    onError: showError,
  });
}

export interface CustomerAssignmentRow {
  id: number;
  customer_acumatica_id: string;
  assignment_type: string;
  notes: string | null;
  source?: string | null;
  source_batch_id?: string | null;
}

export interface CustomerSearchRow {
  id: number;
  acumatica_id: string;
  name: string;
  customer_class: string | null;
  status: string | null;
}

export function useCustomerSearch(q: string) {
  return useQuery({
    queryKey: [...adminKey, "customer-search", q],
    queryFn: () =>
      apiFetch<CustomerSearchRow[]>(`admin/customers/search?q=${encodeURIComponent(q)}`),
    enabled: q.trim().length >= 2,
  });
}

export function useCustomerAssignments(userId: number | null) {
  return useQuery({
    queryKey: [...adminKey, "customer-assignments", userId],
    queryFn: () => apiFetch<CustomerAssignmentRow[]>(`admin/users/${userId}/customer-assignments`),
    enabled: userId !== null,
  });
}

export function useSyncCustomerAssignments() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      userId,
      customer_acumatica_ids,
      assignment_type,
    }: {
      userId: number;
      customer_acumatica_ids: string[];
      assignment_type: "owner" | "servicing";
    }) =>
      apiFetch<{ message: string; assignments: CustomerAssignmentRow[] }>(
        `admin/users/${userId}/customer-assignments`,
        { method: "PUT", body: { customer_acumatica_ids, assignment_type } },
      ),
    onSuccess: (_, vars) => {
      toast.success("Customer assignments saved");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "customer-assignments", vars.userId] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
    },
    onError: showError,
  });
}

export function useBackfillCustomers() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) =>
      apiFetch<CustomerBackfillResult>(`admin/users/${userId}/backfill-customers`, { method: "POST" }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
    },
    onError: showError,
  });
}

export interface CustomerAssignmentSourceStatus {
  sales_orders: { available: boolean; message: string };
  customer_endpoint: { available: boolean; field: string | null; message: string };
  upload: { available: boolean; message: string };
}

export interface CustomerAssignmentBatchRow {
  id: number;
  row_no: number;
  rep_code: string | null;
  customer_acumatica_id: string | null;
  customer_name: string | null;
  resolved_user_id: number | null;
  action: "create" | "update" | "error";
  status: "valid" | "error";
  source: string;
  message: string | null;
}

export interface CustomerAssignmentBatch {
  id: number;
  uuid: string;
  source: string;
  mode: string;
  status: "dry_run" | "applied" | "failed";
  target_user_id: number | null;
  filename: string | null;
  stats_json: {
    rows?: number;
    valid?: number;
    errors?: number;
    create?: number;
    update?: number;
    created?: number;
    updated?: number;
    applied?: number;
  } | null;
  rows: CustomerAssignmentBatchRow[];
}

export function useCustomerAssignmentSources() {
  return useQuery({
    queryKey: [...adminKey, "customer-assignment-sources"],
    queryFn: () => apiFetch<CustomerAssignmentSourceStatus>("admin/customer-assignments/sources"),
    staleTime: 60_000,
  });
}

export function usePreviewCustomerAssignmentMatch() {
  return useMutation({
    mutationFn: ({ userId, source }: { userId: number; source: "so_match" | "customer_endpoint" }) => {
      const endpoint = source === "so_match" ? "match-so" : "match-customer-endpoint";
      return apiFetch<CustomerAssignmentBatch>(`admin/users/${userId}/customer-assignments/${endpoint}`, {
        method: "POST",
        body: { dry_run: true },
        timeoutMs: 300_000,
      });
    },
    onError: showError,
  });
}

export function useApplyCustomerAssignmentBatch() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ batchId, assignment_type }: { batchId: number; assignment_type: "owner" | "servicing" }) =>
      apiFetch<CustomerAssignmentBatch>(`admin/customer-assignments/batches/${batchId}/apply`, {
        method: "POST",
        body: { assignment_type },
        timeoutMs: 300_000,
      }),
    onSuccess: (batch) => {
      toast.success(`Applied ${batch.stats_json?.applied ?? 0} customer assignment(s).`);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "customer-assignments"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
    },
    onError: showError,
  });
}

export function useUploadCustomerAssignments() {
  return useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append("file", file);
      const token = getToken();
      const res = await fetch(`${API_BASE_URL}/admin/customer-assignments/upload`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: form,
      });
      const data = await res.json();
      if (!res.ok) {
        throw new ApiError(data?.message ?? "Customer upload failed.", res.status, data);
      }
      return data as CustomerAssignmentBatch;
    },
    onError: showError,
  });
}

export function useImportStaff() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: { dry_run?: boolean; preserve_manual?: boolean; min_confidence?: string }) =>
      apiFetch<StaffImportResult>("admin/team/import-staff", { method: "POST", body: payload }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "staff-import-gaps"] });
    },
    onError: showError,
  });
}

export function useStaffImportGaps() {
  return useQuery({
    queryKey: [...adminKey, "staff-import-gaps"],
    queryFn: () => apiFetch<StaffImportGap[]>("admin/team/import-gaps"),
  });
}

export function useResolveStaffGap() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      gapId,
      resolution_status,
      resolved_user_id,
    }: {
      gapId: number;
      resolution_status: "linked" | "ignored";
      resolved_user_id?: number;
    }) =>
      apiFetch<StaffImportGap>(`admin/team/import-gaps/${gapId}`, {
        method: "PATCH",
        body: { resolution_status, resolved_user_id },
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [...adminKey, "staff-import-gaps"] });
    },
    onError: showError,
  });
}

export function useCreateUserFromGap() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (gapId: number) =>
      apiFetch<{ message: string }>(`admin/team/import-gaps/${gapId}/create-user`, { method: "POST" }),
    onSuccess: (result) => {
      toast.success(result.message);
      queryClient.invalidateQueries({ queryKey: [...adminKey, "staff-import-gaps"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
    },
    onError: showError,
  });
}

export function useSeedOrgTree() {
  return useMutation({
    mutationFn: (payload: { dry_run?: boolean }) =>
      apiFetch<{ message: string; result: { linked: number; missing: string[] } }>(
        "admin/team/seed-org-tree",
        { method: "POST", body: payload },
      ),
    onSuccess: (result) => {
      toast.success(`${result.message} (${result.result.linked} linked)`);
    },
    onError: showError,
  });
}

export function useRestoreRepCode() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ userId, historyEntryId }: { userId: number; historyEntryId: number }) =>
      apiFetch<TeamMember>(`admin/users/${userId}/rep-code-history/${historyEntryId}/restore`, { method: "POST" }),
    onSuccess: () => {
      toast.success("Rep code restored");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "team-members"] });
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

export function useUpdateNotificationRuleRecipients() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, recipient_emails, recipient_roles }: { id: number; recipient_emails: string[]; recipient_roles: string[] }) =>
      apiFetch<NotificationRule>(`admin/notification-rules/${id}`, {
        method: "PUT",
        body: { recipient_emails, recipient_roles },
      }),
    onSuccess: () => {
      toast.success("Notification recipients saved");
      queryClient.invalidateQueries({ queryKey: [...adminKey, "notification-rules"] });
      queryClient.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

export function useSendNotificationRulesConfig() {
  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string; recipient: string; rule_count: number }>("admin/notification-rules/send-config", { method: "POST" }),
    onSuccess: (data) => {
      toast.success(`Configuration sent to ${data.recipient}`);
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
  match_mode: "exact" | "wildcard" | "regex";
  is_wildcard: boolean;
  display_name: string;
  customer_id: number | null;
  branch_name: string | null;
  branch_tag_pattern: string | null;
  customer_class: string | null;
  po_patterns: string[] | null;
  po_extraction_source: "subject" | "body" | "pdf" | "all";
  ai_fallback_enabled: boolean;
  is_active: boolean;
  approval_status: "pending" | "approved" | "rejected";
  created_by: number | null;
  approved_by: number | null;
  approved_at: string | null;
  last_matched_at: string | null;
  last_imported_at: string | null;
  auto_deactivated_at: string | null;
  notes: string | null;
  customer?: { id: number; acumatica_id: string; name: string } | null;
}

export interface EmailImportMetrics {
  imported_orders_last_24h: number;
  unrecognized_emails_last_24h: number;
  success_rate: number;
  pending_approvals: number;
  auto_deactivated_configs: number;
  reason_counts: Array<{ reason: string | null; count: number }>;
}

export function useEmailImportConfigs() {
  return useQuery({
    queryKey: [...adminKey, "email-import-configs"],
    queryFn: () => apiFetch<EmailImportConfig[]>("admin/email-import-configs"),
  });
}

export function useEmailImportMetrics() {
  return useQuery({
    queryKey: [...adminKey, "email-import-configs-metrics"],
    queryFn: () => apiFetch<EmailImportMetrics>("admin/email-import-configs/metrics"),
    refetchInterval: 30_000,
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

export function useApproveEmailImportConfig() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch<{ message: string; config: EmailImportConfig }>(`admin/email-import-configs/${id}/approve`, { method: "POST" }),
    onSuccess: (result) => {
      toast.success(result.message);
      qc.invalidateQueries({ queryKey: [...adminKey, "email-import-configs"] });
      qc.invalidateQueries({ queryKey: [...adminKey, "email-import-configs-metrics"] });
    },
    onError: showError,
  });
}

export function useTestSender() {
  return useMutation({
    mutationFn: (email: string) =>
      apiFetch<{ email: string; matched: boolean; branch_tag: string | null; config: EmailImportConfig | null }>(
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
  attachments: Array<{
    id: number;
    name: string | null;
    content_type?: string | null;
    size?: number | null;
    extraction_status: string;
    extraction_confidence: number | null;
    extraction_error: string | null;
    extracted_text?: string | null;
  }>;
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

export function useAuditLogs(params?: {
  page?: number;
  q?: string;
  start_date?: string;
  end_date?: string;
}) {
  const page = params?.page ?? 1;
  const q = params?.q?.trim() ?? "";
  const start = params?.start_date ?? "";
  const end = params?.end_date ?? "";
  const qs = new URLSearchParams();
  qs.set("page", String(page));
  if (q) qs.set("q", q);
  if (start) qs.set("start_date", start);
  if (end) qs.set("end_date", end);

  return useQuery({
    queryKey: [...adminKey, "audit-logs", page, q, start, end],
    queryFn: () => apiFetch<PaginatedResponse<AuditLogEntry>>(`admin/audit-logs?${qs.toString()}`),
  });
}

export async function exportAuditActivityExcel(params?: {
  start_date?: string;
  end_date?: string;
  actor_user_id?: number;
}): Promise<void> {
  const { downloadApiFile } = await import("@/lib/api");
  const qs = new URLSearchParams();
  if (params?.start_date) qs.set("start_date", params.start_date);
  if (params?.end_date) qs.set("end_date", params.end_date);
  if (params?.actor_user_id) qs.set("actor_user_id", String(params.actor_user_id));
  const suffix = qs.toString() ? `?${qs.toString()}` : "";
  await downloadApiFile(
    `admin/audit-logs/export${suffix}`,
    `user-login-activity-${new Date().toISOString().slice(0, 10)}.xlsx`,
  );
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
    mutationFn: (body: Partial<DailyReportConfig> & { recipients?: string[]; reply_to?: string[]; send_to?: string[]; cc?: string[] }) =>
      apiFetch<DailyReportConfig>("admin/daily-reports/config", { method: "PUT", body }),
    onSuccess: () => {
      toast.success("Daily report settings saved.");
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
      qc.invalidateQueries({ queryKey: [...adminKey, "audit-logs"] });
    },
    onError: showError,
  });
}

type DailyReportSendResult = {
  ok?: boolean;
  message: string;
  run: DailyReportRun | null;
};

function toastDailyReportResult(result: DailyReportSendResult) {
  const detail = result.run?.error_summary?.trim();
  if (result.ok === false || result.run?.status === "failed" || result.run?.status === "skipped") {
    toast.error(detail ? `${result.message}` : result.message);
    return;
  }
  if (result.run?.status === "partial") {
    toast.warning(result.message);
    return;
  }
  toast.success(result.message);
}

export function useTestDailyReport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (routing?: {
      send_to?: string[];
      cc?: string[];
      recipients?: string[];
      report_date?: string;
    }) =>
      apiFetch<DailyReportSendResult>("admin/daily-reports/test-send", {
        method: "POST",
        body: routing ? routing : {},
        timeoutMs: 180_000,
      }),
    onSuccess: (result) => {
      toastDailyReportResult(result);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
    onError: (error) => {
      showError(error);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
  });
}

/** Send (or resend) the daily report for a selected report date, e.g. 2026-07-16. */
export function useSendDailyReport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (body: {
      report_date: string;
      force?: boolean;
      send_to?: string[];
      cc?: string[];
      recipients?: string[];
    }) =>
      apiFetch<DailyReportSendResult>("admin/daily-reports/send", {
        method: "POST",
        body,
        timeoutMs: 180_000,
      }),
    onSuccess: (result) => {
      toastDailyReportResult(result);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
    onError: (error) => {
      showError(error);
      // Always refresh history — a failed HTTP response may still have created a run.
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
  });
}

export function useResendDailyReport() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string; run: DailyReportRun }>("admin/daily-reports/resend-last", {
        method: "POST",
        timeoutMs: 180_000,
      }),
    onSuccess: (result) => {
      toast.success(result.message);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
    onError: (error) => {
      showError(error);
      qc.invalidateQueries({ queryKey: [...adminKey, "daily-reports"] });
    },
  });
}

export function useDeliverySlaConfig() {
  return useQuery({
    queryKey: [...adminKey, "delivery-sla-config"],
    queryFn: () => apiFetch<DeliverySlaConfigRule[]>("admin/delivery-sla-config"),
  });
}

export function useUpdateDeliverySlaConfig() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (rules: DeliverySlaConfigRule[]) =>
      apiFetch<DeliverySlaConfigRule[]>("admin/delivery-sla-config", {
        method: "PUT",
        body: { rules },
      }),
    onSuccess: () => {
      toast.success("Delivery SLA settings saved");
      qc.invalidateQueries({ queryKey: [...adminKey, "delivery-sla-config"] });
      qc.invalidateQueries({ queryKey: ["operations-business-optimization"] });
      qc.invalidateQueries({ queryKey: ["operations-fill-rate-summary"] });
    },
    onError: showError,
  });
}

// ── FOL settings (dynamic stages + mail + attachments) ───────────────────────

export type FolApprovalStageConfig = {
  id?: number;
  key: string;
  name: string;
  sort_order: number;
  is_active: boolean;
  assignee_mode: "role" | "user_list" | "manager_of_submitter";
  role_names: string[];
  user_ids: number[];
  require_comment: boolean;
  sla_hours: number | null;
};

export type FolSettings = {
  mail_from_address: string;
  mail_from_name: string;
  max_attachment_kb: number;
  attachment_mimes: string[];
  invoicing_roles: string[];
  cc_watcher_emails: string[];
  duplicate_policy: "block" | "warn" | "allow";
  consumables_months: number;
  require_attachment: boolean;
  allow_admin_on_all_stages: boolean;
  /** Env/config: when true, all FOL emails redirect to mail_testing_recipient only. */
  mail_testing_mode?: boolean;
  mail_testing_recipient?: string;
  stages: FolApprovalStageConfig[];
  available_roles: string[];
  users: Array<{ id: number; name: string; email: string; role: string }>;
  defaults?: Record<string, unknown>;
};

export function useFolSettings() {
  return useQuery({
    queryKey: [...adminKey, "fol-settings"],
    queryFn: () => apiFetch<FolSettings>("admin/fol/settings"),
  });
}

export type FolProductRow = {
  id: number;
  inventory_id: string;
  description: string | null;
  is_fol_eligible: boolean;
  fol_category: string | null;
  default_uom: string | null;
  qty_on_hand: string | number | null;
};

export function useFolProducts(params: { q?: string; eligible_only?: boolean; page?: number }) {
  const qs = new URLSearchParams();
  if (params.q) qs.set("q", params.q);
  if (params.eligible_only) qs.set("eligible_only", "1");
  if (params.page) qs.set("page", String(params.page));
  qs.set("per_page", "50");
  return useQuery({
    queryKey: [...adminKey, "fol-products", params],
    queryFn: () =>
      apiFetch<{
        data: FolProductRow[];
        current_page: number;
        last_page: number;
        total: number;
      }>(`admin/fol/products?${qs}`),
  });
}

export function useUpdateFolProduct() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      inventoryId,
      is_fol_eligible,
      fol_category,
    }: {
      inventoryId: string;
      is_fol_eligible: boolean;
      fol_category?: string | null;
    }) =>
      apiFetch<FolProductRow>(`admin/fol/products/${encodeURIComponent(inventoryId)}`, {
        method: "PUT",
        body: { is_fol_eligible, fol_category },
      }),
    onSuccess: () => {
      toast.success("FOL product updated");
      qc.invalidateQueries({ queryKey: [...adminKey, "fol-products"] });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useBulkUploadFolProducts() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: async (file: File) => {
      const token = getToken();
      const form = new FormData();
      form.append("file", file);
      form.append("default_eligible", "1");
      const res = await fetch(`${API_BASE_URL}/admin/fol/products/bulk-upload`, {
        method: "POST",
        headers: {
          Accept: "application/json",
          ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
        body: form,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        throw new Error(
          (json as { message?: string }).message
            || (json as { error?: string }).error
            || `Upload failed (${res.status})`,
        );
      }
      return json as {
        message: string;
        updated: number;
        not_found_count: number;
        not_found_sample: string[];
        parse_errors: string[];
      };
    },
    onSuccess: (data) => {
      toast.success(data.message);
      if (data.not_found_count > 0) {
        toast.warning(`${data.not_found_count} SKU(s) not found in inventory (sync first).`);
      }
      qc.invalidateQueries({ queryKey: [...adminKey, "fol-products"] });
    },
    onError: (e: Error) => toast.error(e.message),
  });
}

export function useUpdateFolSettings() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (payload: Partial<FolSettings>) =>
      apiFetch<FolSettings>("admin/fol/settings", { method: "PUT", body: payload }),
    onSuccess: () => {
      toast.success("FOL settings saved");
      qc.invalidateQueries({ queryKey: [...adminKey, "fol-settings"] });
    },
    onError: showError,
  });
}

export function useUpdateFolStages() {
  const qc = useQueryClient();

  return useMutation({
    mutationFn: (stages: FolApprovalStageConfig[]) =>
      apiFetch<{ message: string; stages: FolApprovalStageConfig[] }>("admin/fol/stages", {
        method: "PUT",
        body: { stages },
      }),
    onSuccess: (result) => {
      toast.success(result.message);
      qc.invalidateQueries({ queryKey: [...adminKey, "fol-settings"] });
    },
    onError: showError,
  });
}
