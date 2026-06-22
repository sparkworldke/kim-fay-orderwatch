import { Link, useRouterState } from "@tanstack/react-router";
import {
  LayoutDashboard,
  PackageSearch,
  PackageCheck,
  AlertTriangle,
  Users,
  Sparkles,
  FileBarChart,
  Bell,
  Settings,
  UserCircle,
  Inbox,
} from "lucide-react";
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from "@/components/ui/sidebar";
import logoAsset from "@/assets/kim-fay-logo.png.asset.json";

const NAV = [
  { group: "Operations", items: [
    { title: "Dashboard", url: "/app", icon: LayoutDashboard, exact: true },
    { title: "Orders", url: "/app/orders", icon: PackageSearch },
    { title: "Discrepancies", url: "/app/discrepancies", icon: AlertTriangle },
    { title: "Customers", url: "/app/customers", icon: Users },
  ]},
  { group: "Intelligence", items: [
    { title: "AI Insights", url: "/app/ai-insights", icon: Sparkles },
    { title: "Reports", url: "/app/reports", icon: FileBarChart },
    { title: "Notifications", url: "/app/notifications", icon: Bell },
    { title: "Mailbox", url: "/app/mailbox", icon: Inbox },
  ]},
  { group: "System", items: [
    { title: "Administration", url: "/app/administration", icon: Settings },
    { title: "Sales Order Imports", url: "/app/so-imports", icon: PackageCheck },
    { title: "Profile", url: "/app/profile", icon: UserCircle },
  ]},
] as const;

export function AppSidebar() {
  const { state } = useSidebar();
  const collapsed = state === "collapsed";
  const pathname = useRouterState({ select: (r) => r.location.pathname });

  const isActive = (url: string, exact?: boolean) =>
    exact ? pathname === url : pathname === url || pathname.startsWith(url + "/");

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="border-b">
        <div className="flex items-center gap-2 px-2 py-1.5">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded bg-white">
            <img src={logoAsset.url} alt="Kim-Fay" className="h-7 w-7 object-contain" />
          </div>
          {!collapsed && (
            <div className="min-w-0">
              <div className="truncate text-sm font-semibold leading-tight">Kim-Fay</div>
              <div className="truncate text-[10px] text-muted-foreground">OrderWatch</div>
            </div>
          )}
        </div>
      </SidebarHeader>
      <SidebarContent>
        {NAV.map((g) => (
          <SidebarGroup key={g.group}>
            {!collapsed && <SidebarGroupLabel>{g.group}</SidebarGroupLabel>}
            <SidebarGroupContent>
              <SidebarMenu>
                {g.items.map((item) => (
                  <SidebarMenuItem key={item.url}>
                    <SidebarMenuButton asChild isActive={isActive(item.url, (item as { exact?: boolean }).exact)} tooltip={item.title}>
                      <Link to={item.url} className="flex items-center gap-2">
                        <item.icon className="h-4 w-4" />
                        {!collapsed && <span>{item.title}</span>}
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                ))}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
        ))}
      </SidebarContent>
    </Sidebar>
  );
}
