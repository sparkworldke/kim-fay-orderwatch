import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import type {
  CreateEmailFilterPayload,
  EmailFilter,
  EmailMessage,
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
      : "The request could not be completed. Please try again.",
  );
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
