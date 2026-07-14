import { Outlet, createFileRoute, redirect } from "@tanstack/react-router";
import { AppSidebar } from "@/components/app-sidebar";
import { AppHeader } from "@/components/app-header";
import { ImpersonationBanner } from "@/components/impersonation-banner";
import { AiAssistant } from "@/components/ai-assistant";
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar";
import { apiFetch } from "@/lib/api";
import { clearSession, syncSessionFromMe, type ImpersonationPayload } from "@/lib/auth";
import { useIdleLogout } from "@/hooks/useIdleLogout";

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
    } catch {
      clearSession();
      throw redirect({ to: "/auth" });
    }
  },
  component: AppLayout,
});

function AppLayout() {
  useIdleLogout();

  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full bg-background">
        <AppSidebar />
        <SidebarInset className="flex min-w-0 flex-1 flex-col">
          <ImpersonationBanner />
          <AppHeader />
          <main className="flex-1 overflow-x-hidden p-4 md:p-6">
            <Outlet />
          </main>
        </SidebarInset>
        <AiAssistant />
      </div>
    </SidebarProvider>
  );
}
