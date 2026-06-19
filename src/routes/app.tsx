import { Outlet, createFileRoute, redirect } from "@tanstack/react-router";
import { AppSidebar } from "@/components/app-sidebar";
import { AppHeader } from "@/components/app-header";
import { SidebarInset, SidebarProvider } from "@/components/ui/sidebar";

export const Route = createFileRoute("/app")({
  beforeLoad: () => {
    if (typeof window !== "undefined" && !window.localStorage.getItem("kf_token")) {
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
      </div>
    </SidebarProvider>
  );
}
