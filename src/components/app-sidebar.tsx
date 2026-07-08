import { Link, useRouterState } from "@tanstack/react-router";
import {
  LayoutDashboard,
  PackageSearch,
  PackageCheck,
  Users,
  Settings,
  UserCircle,
  Inbox,
  Sparkles,
  Boxes,
  PackageX,
  Gauge,
  GitMerge,
  FileText,
  Radio,
  Target,
  MapPin,
  ShieldCheck,
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
import { LogoImage } from "@/components/logo-image";
import { useAuth } from "@/lib/auth";
import { canAccessNavItem } from "@/lib/nav-permissions";

const NAV = [
  { group: "Overview", items: [
    { title: "Dashboard", url: "/app", icon: LayoutDashboard, exact: true },
    { title: "Orders", url: "/app/orders", icon: PackageSearch },
    { title: "Business Optimization", url: "/app/business-optimization", icon: Target },
    { title: "AI Intelligence", url: "/app/ai-intelligence", icon: Sparkles },
    { title: "Customer Feed", url: "/app/customer-feed", icon: Radio },
  ]},
  { group: "Operations", items: [
    { title: "Credit Notes & More", url: "/app/credit-notes-more", icon: FileText },
    { title: "Inventory", url: "/app/inventory", icon: Boxes },
    { title: "Backorders", url: "/app/backorders", icon: PackageX },
    { title: "Fill Rate", url: "/app/fill-rate", icon: Gauge },
    { title: "Zones", url: "/app/zones", icon: MapPin },
    { title: "Customers", url: "/app/customers", icon: Users },
    { title: "Sales Consultants", url: "/app/sales-consultants", icon: Users },
  ]},
  { group: "Workflow", items: [
    { title: "Order Match", url: "/app/order-match", icon: GitMerge },
    { title: "Mailbox", url: "/app/mailbox", icon: Inbox },
  ]},
  { group: "System", items: [
    { title: "Administration", url: "/app/administration", icon: Settings },
    { title: "Team Members", url: "/app/team", icon: Users },
    { title: "Roles & Permissions", url: "/app/roles", icon: ShieldCheck },
    { title: "Sales Order Imports", url: "/app/so-imports", icon: PackageCheck },
    { title: "Profile", url: "/app/profile", icon: UserCircle },
  ]},
] as const;

export function AppSidebar() {
  const { session } = useAuth();
  const { state, isMobile, setOpenMobile } = useSidebar();
  const collapsed = state === "collapsed";
  const pathname = useRouterState({ select: (r) => r.location.pathname });
  const role = session?.role;

  const isActive = (url: string, exact?: boolean) =>
    exact ? pathname === url : pathname === url || pathname.startsWith(url + "/");

  function handleNavClick() {
    if (isMobile) setOpenMobile(false);
  }

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="border-b">
        <div className="flex items-center gap-2 px-2 py-1.5">
          {collapsed ? (
            /* Icon-only: small square with KF initials / logo mark */
            <div className="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded bg-white shadow-sm">
              <LogoImage iconOnly className="h-8 w-8 object-contain" />
            </div>
          ) : (
            /* Expanded: full landscape logo */
            <div className="flex h-10 items-center justify-start overflow-hidden rounded bg-white px-2 shadow-sm w-full">
              <LogoImage className="h-8 w-auto max-w-[140px] object-contain" />
            </div>
          )}
        </div>
      </SidebarHeader>
      <SidebarContent>
        {NAV.map((g) => {
          const visibleItems = g.items.filter((item) => canAccessNavItem(role, item.url));
          if (visibleItems.length === 0) return null;
          return (
          <SidebarGroup key={g.group}>
            {!collapsed && <SidebarGroupLabel>{g.group}</SidebarGroupLabel>}
            <SidebarGroupContent>
              <SidebarMenu>
                {visibleItems.map((item) => (
                  <SidebarMenuItem key={item.url}>
                    <SidebarMenuButton asChild isActive={isActive(item.url, (item as { exact?: boolean }).exact)} tooltip={item.title}>
                      <Link to={item.url} className="flex items-center gap-2" onClick={handleNavClick}>
                        <item.icon className="h-4 w-4" />
                        {!collapsed && <span>{item.title}</span>}
                      </Link>
                    </SidebarMenuButton>
                  </SidebarMenuItem>
                ))}
              </SidebarMenu>
            </SidebarGroupContent>
          </SidebarGroup>
          );
        })}
      </SidebarContent>
    </Sidebar>
  );
}
