import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import type {
  CreateEmailFilterPayload,
  EmailFilter,
  EmailMessage,
  MailboxAccount,
  PaginatedEmails,
  SyncLog,
  UpdateEmailFilterPayload,
} from "@/types/mailbox";

const MAILBOXES_KEY = ["mailboxes"] as const;
const EMAILS_KEY = ["emails"] as const;
const FILTERS_KEY = ["email-filters"] as const;

function showError(error: unknown) {
  toast.error(error instanceof ApiError ? error.message : "Request failed");
}

// --- Mailboxes ---

export function useMailboxAccounts() {
  return useQuery({
    queryKey: MAILBOXES_KEY,
    queryFn: () => apiFetch<MailboxAccount[]>("admin/mailboxes"),
  });
}

export function useStartOAuth() {
  return useMutation({
    mutationFn: () => apiFetch<{ auth_url: string }>("admin/mailboxes/oauth/start", { method: "POST" }),
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
    mutationFn: () => apiFetch<OAuthCheckResult>("admin/mailboxes/oauth/check"),
    onError: showError,
  });
}

export function useSyncMailbox() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<{ message: string }>(`admin/mailboxes/${id}/sync`, { method: "POST" }),
    onSuccess: () => {
      toast.success("Sync queued — emails will update shortly.");
      queryClient.invalidateQueries({ queryKey: MAILBOXES_KEY });
    },
    onError: showError,
  });
}

export function useDisconnectMailbox() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<{ message: string }>(`admin/mailboxes/${id}`, { method: "DELETE" }),
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

export function useDeleteEmailFilter() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<{ message: string }>(`email-filters/${id}`, { method: "DELETE" }),
    onSuccess: () => {
      toast.success("Filter deleted.");
      queryClient.invalidateQueries({ queryKey: FILTERS_KEY });
    },
    onError: showError,
  });
}
