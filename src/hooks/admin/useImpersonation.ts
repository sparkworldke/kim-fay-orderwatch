import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import {
  applyAuthResponse,
  type AuthUserPayload,
  type ImpersonationPayload,
} from "@/lib/auth";

export type ImpersonationCandidate = {
  id: number;
  name: string;
  email: string;
  role: string;
  rep_code: string | null;
  employee_number: string | null;
  is_consultant: boolean;
};

type ImpersonationAuthResponse = {
  token: string;
  user: AuthUserPayload;
  capabilities?: unknown;
  impersonation: ImpersonationPayload;
};

function showError(error: unknown) {
  toast.error(error instanceof ApiError ? error.message : "Request failed");
}

/** After switching identity, drop all cached user-scoped queries. */
function resetClientCaches(queryClient: ReturnType<typeof useQueryClient>) {
  queryClient.clear();
}

export function useImpersonationCandidates(q: string, enabled = true) {
  return useQuery({
    queryKey: ["admin-settings", "impersonate-candidates", q],
    queryFn: () =>
      apiFetch<{ items: ImpersonationCandidate[] }>(
        `admin/impersonate/candidates${q.trim() ? `?q=${encodeURIComponent(q.trim())}` : ""}`,
      ),
    enabled,
    staleTime: 30_000,
  });
}

export function useStartImpersonation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (userId: number) =>
      apiFetch<ImpersonationAuthResponse>("admin/impersonate", {
        method: "POST",
        body: { user_id: userId },
      }),
    onSuccess: (data) => {
      applyAuthResponse({
        token: data.token,
        user: data.user,
        impersonation: data.impersonation,
      });
      resetClientCaches(queryClient);
      toast.success(`Now viewing as ${data.user.name} (${data.user.role})`);
      // Full reload so nav, capabilities, and scoped data match the target user
      window.location.assign("/app");
    },
    onError: showError,
  });
}

export function useStopImpersonation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      apiFetch<ImpersonationAuthResponse>("auth/impersonate/stop", {
        method: "POST",
      }),
    onSuccess: (data) => {
      applyAuthResponse({
        token: data.token,
        user: data.user,
        impersonation: data.impersonation,
      });
      resetClientCaches(queryClient);
      toast.success(`Returned to ${data.user.name}`);
      window.location.assign("/app/administration");
    },
    onError: showError,
  });
}
