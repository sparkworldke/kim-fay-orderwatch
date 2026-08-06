import { Outlet, createFileRoute, redirect } from "@tanstack/react-router";
import { AppSidebar } from "@/components/app-sidebar";
import { AppHeader } from "@/components/app-header";
import { ImpersonationBanner } from "@/components/impersonation-banner";
import { AiAssistant } from "@/components/ai-assistant";
import { DownloadReadyNotifier } from "@/components/download-ready-notifier";
import { MobileBottomNav } from "@/components/mobile-bottom-nav";
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar";
import { apiFetch, ApiError } from "@/lib/api";
import { clearSession, syncSessionFromMe, type ImpersonationPayload } from "@/lib/auth";
import { useIdleLogout } from "@/hooks/useIdleLogout";
import { usePageActivityTracker } from "@/hooks/usePageActivityTracker";
import { FirstLoginOnboarding } from "@/components/first-login-onboarding";
import { WhatsAppPromptDialog } from "@/components/whatsapp-prompt-dialog";
import { useQueryClient } from "@tanstack/react-query";
import { useEffect } from "react";

export const Route = createFileRoute("/app")({
  beforeLoad: async () => {
    if (typeof window === "undefined") return;

    if (!window.localStorage.getItem("kf_token")) {
      throw redirect({ to: "/auth" });
    }

    try {
      // A stored token is only a hint. Validate it before mounting the shell so
      // revoked and expired sessions cannot expose protected page content.
      const me = await apiFetch<{
        id: number;
        name: string;
        email: string;
        role: string;
        rep_code?: string | null;
        impersonation?: ImpersonationPayload | null;
      }>("auth/me");
      syncSessionFromMe(me);
    } catch (err) {
      // Only log out on a genuine 401 (token revoked/expired). Network errors,
      // timeouts and 5xx responses are transient — killing the session for those
      // would log users out on every brief connectivity blip or server restart.
      if (err instanceof ApiError && err.status === 401) {
        clearSession();
        throw redirect({ to: "/auth" });
      }
      // For any other error (network failure, 5xx, etc.) let the app shell
      // mount with whatever session data is already in localStorage rather than
      // forcing a logout. The user can retry their action.
    }
  },
  component: AppLayout,
});

function AppLayout() {
  useIdleLogout();
  usePageActivityTracker(true);
  const queryClient = useQueryClient();

  // Warm the small shared dashboard reference payload once after authentication.
  // Dashboard navigation then renders filters immediately from React Query.
  useEffect(() => {
    void queryClient.prefetchQuery({
      queryKey: ["dashboard", "filter-options"],
      queryFn: () => apiFetch("dashboard/filter-options"),
      staleTime: 30 * 60 * 1000,
      gcTime: 6 * 60 * 60 * 1000,
    });
  }, [queryClient]);

  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full bg-background">
        <AppSidebar />
        <SidebarInset className="flex min-w-0 flex-1 flex-col">
          <ImpersonationBanner />
          <AppHeader />
          <main className="mobile-app-main flex-1 overflow-x-hidden p-3 pb-24 sm:p-4 sm:pb-24 md:p-6">
            <Outlet />
          </main>
        </SidebarInset>
        <MobileBottomNav />
        <DownloadReadyNotifier />
        <AiAssistant />
        <FirstLoginOnboarding />
        <WhatsAppPromptDialog />
      </div>
    </SidebarProvider>
  );
}
