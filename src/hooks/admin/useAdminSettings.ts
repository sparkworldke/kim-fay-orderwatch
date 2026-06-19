import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import type {
  AcumaticaResponse,
  AdminHealth,
  AiProviderStatus,
  AuditLogEntry,
  NotificationRule,
  PaginatedResponse,
  Permission,
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

export function useAuditLogs() {
  return useQuery({
    queryKey: [...adminKey, "audit-logs"],
    queryFn: () => apiFetch<PaginatedResponse<AuditLogEntry>>("admin/audit-logs"),
  });
}
