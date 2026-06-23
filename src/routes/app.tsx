import { Outlet, createFileRoute, redirect } from "@tanstack/react-router";
import { AppSidebar } from "@/components/app-sidebar";
import { AppHeader } from "@/components/app-header";
import { AiAssistant } from "@/components/ai-assistant";
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar";
import { apiFetch } from "@/lib/api";
import { clearSession } from "@/lib/auth";

export const Route = createFileRoute("/app")({
  beforeLoad: async () => {
    if (typeof window === "undefined") return;

    if (!window.localStorage.getItem("kf_token")) {
      throw redirect({ to: "/auth" });
    }

    try {
      // A stored token is only a hint. Validate it before mounting the shell so
      // revoked and expired sessions cannot expose protected page content.
      await apiFetch("auth/me");
    } catch {
      clearSession();
      throw redirect({ to: "/auth" });
    }
  },
  component: AppLayout,
});

function AppLayout() {
  return (
    <SidebarProvider>
      <div className="flex min-h-screen w-full bg-background">
        <AppSidebar />
        <SidebarInset className="flex min-w-0 flex-1 flex-col">
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
