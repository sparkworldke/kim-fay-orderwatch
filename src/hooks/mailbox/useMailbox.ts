import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import type {
  CreateEmailFilterPayload,
  EmailFilter,
  EmailMessage,
  InboxEmailGroupsResponse,
  MailboxAccount,
  MailboxFolder,
  PaginatedEmails,
  SyncLog,
  UpdateEmailFilterPayload,
} from "@/types/mailbox";

const MAILBOXES_KEY = ["mailboxes"] as const;
const EMAILS_KEY = ["emails"] as const;
const FILTERS_KEY = ["email-filters"] as const;
const FOLDERS_KEY = ["mailbox-folders"] as const;

function showError(error: unknown) {
  toast.error(
    error instanceof ApiError
      ? error.message
      : error instanceof Error
        ? error.message
        : "The request could not be completed. Please try again.",
  );
}

async function pollMailboxFolderSync(syncId: number): Promise<MailboxFolderSyncResult> {
  const deadline = Date.now() + 600_000;

  while (Date.now() < deadline) {
    const run = await apiFetch<MailboxFolderSyncResult & { error_message?: string | null }>(
      `admin/mailbox-folder-sync-runs/${syncId}`,
      { timeoutMs: 30_000 },
    );

    if (run.status === "completed") {
      return run;
    }

    if (run.status === "failed") {
      throw new ApiError(run.error_message ?? "Folder sync failed.", 422, run);
    }

    await new Promise((resolve) => window.setTimeout(resolve, 3000));
  }

  throw new Error("Sync is still running in the background. Open sync results again in a minute.");
}

// --- Mailboxes ---

export function useMailboxAccounts(enabled = true) {
  return useQuery({
    queryKey: MAILBOXES_KEY,
    queryFn: () => apiFetch<MailboxAccount[]>("admin/mailboxes"),
    enabled,
  });
}

export function useStartOAuth() {
  return useMutation({
    mutationFn: () =>
      apiFetch<{ auth_url: string }>("admin/mailboxes/oauth/start", { method: "POST" }),
    onSuccess: ({ auth_url }) => {
      window.location.href = auth_url;
    },
    onError: showError,
  });
}

export interface OAuthCheckResult {
  overall_ok: boolean;
  checks: Record<string, { ok: boolean; label: string; detail: string }>;
  mailbox_tokens: { email: string; ok: boolean; detail: string }[];
  checked_at: string;
}

export function useCheckOAuth() {
  return useMutation({
    mutationFn: () => apiFetch<OAuthCheckResult>("admin/mailboxes/oauth/check", { method: "POST" }),
    onError: showError,
  });
}

export function useUpdateMailbox() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...payload }: { id: number; sync_from_date?: string | null }) =>
      apiFetch<MailboxAccount>(`admin/mailboxes/${id}`, { method: "PUT", body: payload }),
    onSuccess: () => {
      toast.success("Import settings saved — next sync will use the new date.");
      queryClient.invalidateQueries({ queryKey: MAILBOXES_KEY });
    },
    onError: showError,
  });
}

export function useSyncAllMailboxes() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      apiFetch<{ message: string }>("admin/mailboxes/sync-all", { method: "POST" }),
    onSuccess: (data) => {
      toast.success(data.message);
      queryClient.invalidateQueries({ queryKey: MAILBOXES_KEY });
    },
    onError: showError,
  });
}

export function useSyncMailbox() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<{ message: string }>(`admin/mailboxes/${id}/sync`, { method: "POST" }),
    onSuccess: (_data, id) => {
      toast.success("Sync started — emails will be imported in the background.");
      queryClient.invalidateQueries({ queryKey: MAILBOXES_KEY });
      // Refresh logs shortly so the "running" entry appears
      queryClient.invalidateQueries({ queryKey: [...MAILBOXES_KEY, id, "sync-logs"] });
    },
    onError: showError,
  });
}

export function useDisconnectMailbox() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<{ message: string }>(`admin/mailboxes/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Mailbox disconnected.");
      queryClient.invalidateQueries({ queryKey: MAILBOXES_KEY });
      queryClient.invalidateQueries({ queryKey: EMAILS_KEY });
    },
    onError: showError,
  });
}

export function useMailboxSyncLogs(mailboxId: number | null) {
  return useQuery({
    queryKey: [...MAILBOXES_KEY, mailboxId, "sync-logs"],
    queryFn: () => apiFetch<SyncLog[]>(`admin/mailboxes/${mailboxId}/sync-logs`),
    enabled: mailboxId !== null,
    // Poll every 5 s so running syncs update live; stops automatically when disabled
    refetchInterval: 5000,
  });
}

export function useMailboxFolders(mailboxId: number | null) {
  return useQuery({
    queryKey: [...FOLDERS_KEY, mailboxId],
    queryFn: () => apiFetch<MailboxFolder[]>(`admin/mailboxes/${mailboxId}/folders`),
    enabled: mailboxId !== null,
  });
}

export function useDiscoverMailboxFolders() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (mailboxId: number) => apiFetch<MailboxFolder[]>(`admin/mailboxes/${mailboxId}/folders/discover`, { method: "POST" }),
    onSuccess: (_data, mailboxId) => {
      toast.success("Outlook folders refreshed.");
      queryClient.invalidateQueries({ queryKey: [...FOLDERS_KEY, mailboxId] });
    },
    onError: showError,
  });
}

export function useUpdateMailboxFolder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ id, ...body }: Partial<MailboxFolder> & { id: number }) =>
      apiFetch<MailboxFolder>(`admin/mailbox-folders/${id}`, { method: "PATCH", body }),
    onSuccess: (folder) => queryClient.invalidateQueries({ queryKey: [...FOLDERS_KEY, folder.mailbox_account_id] }),
    onError: showError,
  });
}

export function useSaveFolderRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (payload: { id?: number; mailbox_folder_id: number; existing_rule_name: string; is_enabled: boolean; is_trusted: boolean }) => {
      const { id, ...body } = payload;
      return apiFetch(id ? `admin/mailbox-rule-mappings/${id}` : "admin/mailbox-rule-mappings", {
        method: id ? "PATCH" : "POST", body,
      });
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: FOLDERS_KEY }),
    onError: showError,
  });
}

export function useDeleteFolderRule() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch(`admin/mailbox-rule-mappings/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Rule mapping removed.");
      queryClient.invalidateQueries({ queryKey: FOLDERS_KEY });
    },
    onError: showError,
  });
}

export function useTestMailboxFolder() {
  return useMutation({
    mutationFn: (id: number) => apiFetch<{ ok: boolean; message: string; recent_message_count: number }>(`admin/mailbox-folders/${id}/test`, { method: "POST" }),
    onSuccess: (result) => toast.success(`${result.message} ${result.recent_message_count} recent message(s) sampled.`),
    onError: showError,
  });
}

export interface MailboxFolderSyncResult {
  sync_id: number;
  folder_name: string;
  emails_found: number;
  emails_stored: number;
  emails_created: number;
  emails_updated: number;
  status: string;
}

export interface SyncRunEmailsResponse {
  sync_run: {
    id: number;
    folder_name: string | null;
    sync_from: string;
    sync_to: string;
    emails_stored: number;
    emails_created: number;
    emails_updated: number;
    started_at: string | null;
  };
  emails: Array<{
    id: number;
    subject: string | null;
    from_email: string | null;
    from_name: string | null;
    received_at: string | null;
    folder: string;
    ingestion_classification: string | null;
    extracted_po_number: string | null;
    canonical_po: string | null;
    outcome: string;
  }>;
}

export function useSyncRunEmails(syncRunId: number | null) {
  return useQuery({
    queryKey: [...EMAILS_KEY, "sync-run", syncRunId],
    queryFn: () => apiFetch<SyncRunEmailsResponse>(`admin/mailbox-folder-sync-runs/${syncRunId}/emails`),
    enabled: syncRunId !== null,
  });
}

export function useSyncMailboxFolder() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: async ({
      folderId,
      from,
      to,
      mailboxId,
    }: {
      folderId: number;
      from: string;
      to: string;
      mailboxId: number;
    }) => {
      const started = await apiFetch<MailboxFolderSyncResult & { message?: string }>(
        `admin/mailbox-folders/${folderId}/sync`,
        {
          method: "POST",
          body: { from, to },
          timeoutMs: 30_000,
        },
      );

      if (started.status === "processing") {
        return pollMailboxFolderSync(started.sync_id);
      }

      return started;
    },
    onSuccess: (result, { mailboxId }) => {
      const stored = result.emails_stored ?? result.emails_found;
      toast.success(
        `Imported ${stored} email(s) from ${result.folder_name} (${result.emails_created} new, ${result.emails_updated} updated/re-imported).`,
      );
      queryClient.invalidateQueries({ queryKey: [...FOLDERS_KEY, mailboxId] });
      queryClient.invalidateQueries({ queryKey: EMAILS_KEY });
    },
    onError: showError,
  });
}

export function useIngestionReviews() {
  return useQuery({
    queryKey: ["ingestion-reviews"],
    queryFn: () => apiFetch<{ data: EmailMessage[] }>("admin/ingestion-reviews"),
  });
}

export function useReviewIngestion() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({ emailId, decision, reason }: { emailId: number; decision: "approved" | "rejected"; reason: string }) =>
      apiFetch(`admin/ingestion-reviews/${emailId}`, { method: "POST", body: { decision, reason } }),
    onSuccess: () => {
      toast.success("Ingestion review recorded.");
      queryClient.invalidateQueries({ queryKey: ["ingestion-reviews"] });
      queryClient.invalidateQueries({ queryKey: EMAILS_KEY });
    },
    onError: showError,
  });
}

// --- Emails ---

export interface EmailQueryParams {
  mailbox_id?: number;
  search?: string;
  is_read?: boolean;
}

export interface InboxGroupQueryParams extends EmailQueryParams {
  date_from?: string;
  date_to?: string;
}

export function useInboxEmailGroups(params: InboxGroupQueryParams = {}) {
  const query = new URLSearchParams();
  if (params.mailbox_id !== undefined) query.set("mailbox_id", String(params.mailbox_id));
  if (params.search) query.set("search", params.search);
  if (params.date_from) query.set("date_from", params.date_from);
  if (params.date_to) query.set("date_to", params.date_to);
  const qs = query.toString();

  return useQuery({
    queryKey: [...EMAILS_KEY, "inbox-groups", params],
    queryFn: () => apiFetch<InboxEmailGroupsResponse>(`emails/inbox-groups${qs ? "?" + qs : ""}`),
    refetchInterval: 30_000,
  });
}

export function useEmails(params: EmailQueryParams = {}) {
  const query = new URLSearchParams();
  if (params.mailbox_id !== undefined) query.set("mailbox_id", String(params.mailbox_id));
  if (params.search) query.set("search", params.search);
  if (params.is_read !== undefined) query.set("is_read", params.is_read ? "1" : "0");

  const qs = query.toString();

  return useQuery({
    queryKey: [...EMAILS_KEY, params],
    queryFn: () => apiFetch<PaginatedEmails>(`emails${qs ? "?" + qs : ""}`),
    refetchInterval: 30_000,
  });
}

// --- Email Filters ---

export function useEmailFilters() {
  return useQuery({
    queryKey: FILTERS_KEY,
    queryFn: () => apiFetch<EmailFilter[]>("email-filters"),
    refetchInterval: 30_000,
  });
}

export function useCreateEmailFilter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (payload: CreateEmailFilterPayload) =>
      apiFetch<EmailFilter>("email-filters", { method: "POST", body: payload }),
    onSuccess: () => {
      toast.success("Filter created.");
      queryClient.invalidateQueries({ queryKey: FILTERS_KEY });
    },
    onError: showError,
  });
}

export function useUpdateEmailFilter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...payload }: UpdateEmailFilterPayload & { id: number }) =>
      apiFetch<EmailFilter>(`email-filters/${id}`, { method: "PATCH", body: payload }),
    onSuccess: () => {
      toast.success("Filter updated.");
      queryClient.invalidateQueries({ queryKey: FILTERS_KEY });
    },
    onError: showError,
  });
}

export function useSyncRule() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (filterId: number) =>
      apiFetch<{ message: string }>(`email-filters/${filterId}/sync`, { method: "POST" }),
    onSuccess: (data) => {
      toast.success(data.message);
      queryClient.invalidateQueries({ queryKey: MAILBOXES_KEY });
    },
    onError: showError,
  });
}

export function useStopSync() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ mailboxId, logId }: { mailboxId: number; logId: number }) =>
      apiFetch<{ message: string }>(`admin/mailboxes/${mailboxId}/sync-logs/${logId}/stop`, { method: "POST" }),
    onSuccess: (data, { mailboxId }) => {
      toast.success(data.message);
      queryClient.invalidateQueries({ queryKey: [...MAILBOXES_KEY, mailboxId, "sync-logs"] });
    },
    onError: showError,
  });
}

export function useDeleteEmailFilter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<{ message: string }>(`email-filters/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Filter deleted.");
      queryClient.invalidateQueries({ queryKey: FILTERS_KEY });
    },
    onError: showError,
  });
}
