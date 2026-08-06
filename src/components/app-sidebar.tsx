import { Link, useRouterState } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import {
  LayoutDashboard,
  PackageSearch,
  Users,
  Sparkles,
  Boxes,
  PackageX,
  Gauge,
  FileText,
  Radio,
  Target,
  MapPin,
  ClipboardList,
  BadgeDollarSign,
  TrendingUp,
  Building2,
  ChevronRight,
  ShoppingCart,
  HandCoins,
  Brain,
  Download,
  Factory,
  PackageMinus,
  PackageCheck,
  Settings,
  UserCircle,
  Inbox,
  GitMerge,
  ShieldCheck,
  UserCheck,
  Tags,
  BriefcaseBusiness,
} from "lucide-react";
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarHeader,
  SidebarFooter,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  useSidebar,
} from "@/components/ui/sidebar";
import { LogoImage } from "@/components/logo-image";
import { useAuth } from "@/lib/auth";
import { useCapabilities } from "@/hooks/useCapabilities";
import { canAccessNavItem } from "@/lib/nav-permissions";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";

const NAV = [
  {
    group: "OrderWatch",
    icon: LayoutDashboard,
    items: [
      { title: "Dashboard", url: "/app", icon: LayoutDashboard, exact: true },
      { title: "Orders", url: "/app/orders", icon: PackageSearch },
      {
        title: "Product In Stock (Not Delivered)",
        url: "/app/products-not-delivered",
        icon: PackageMinus,
      },
      { title: "Customer Feed", url: "/app/customer-feed", icon: Radio },
      { title: "Credit Notes & More", url: "/app/credit-notes-more", icon: FileText },
      { title: "Customers", url: "/app/customers", icon: Users },
      {
        title: "AI Intelligence",
        url: "/app/ai-intelligence",
        icon: Sparkles,
        search: { tab: "company" as const },
      },
    ],
  },
  {
    group: "Sales Intelligence",
    icon: TrendingUp,
    items: [
      {
        title: "My Portfolio",
        url: "/app/sales-intelligence",
        icon: BriefcaseBusiness,
        capability: "portfolio",
        search: { channel: "PORTFOLIO" as const },
      },
      { title: "My Team", url: "/app/sales-consultants", icon: Users, capability: "team" },
      {
        title: "Modern Trade",
        url: "/app/sales-intelligence",
        icon: Building2,
        capability: "mt",
        children: [
          { title: "MT1", url: "/app/sales-intelligence", search: { channel: "MT1" as const } },
          { title: "MT2", url: "/app/sales-intelligence", search: { channel: "MT2" as const } },
        ],
      },
      {
        title: "General Trade",
        url: "/app/sales-intelligence",
        icon: ShoppingCart,
        capability: "gt",
        search: { channel: "GT" as const },
      },
      {
        title: "DTC / DTB",
        url: "/app/kp/dtc-calltronix",
        icon: ShoppingCart,
        capability: "dtc_dtb",
        children: [
          { title: "Quotes", url: "/app/kp/dtc-calltronix/quotes" },
          { title: "Sales Orders", url: "/app/kp/dtc-calltronix/sales-orders" },
          { title: "Price List", url: "/app/kp/dtc-calltronix/price-list" },
          { title: "Customers", url: "/app/kp/dtc-calltronix/customers" },
        ],
      },
      {
        title: "E-commerce",
        url: "/app/sales-intelligence",
        icon: ShoppingCart,
        capability: "ecommerce",
        search: { channel: "ECOMMERCE" as const },
      },
      {
        title: "KP Cumulative Sales",
        url: "/app/sales-intelligence",
        search: { channel: "KP" as const },
        icon: TrendingUp,
        capability: "kp",
      },
      { title: "Sales Follow-ups", url: "/app/sales-management", icon: Target, capability: "team" },
    ],
  },
  {
    group: "Production",
    icon: Factory,
    items: [
      { title: "Production & Stock", url: "/app/production", icon: Factory },
      { title: "Inventory", url: "/app/inventory", icon: Boxes },
      { title: "Backorders", url: "/app/backorders", icon: PackageX },
      { title: "Fill Rate", url: "/app/fill-rate", icon: Gauge },
      { title: "Business Optimization", url: "/app/business-optimization", icon: Target },
      { title: "Zones", url: "/app/zones", icon: MapPin },
    ],
  },
  {
    group: "KP Operations",
    icon: ClipboardList,
    items: [
      { title: "My Portfolio", url: "/app/accounts", icon: Building2, capability: "portfolio" },
      {
        title: "KP CRM",
        url: "/app/accounts",
        icon: Building2,
        capability: "kp",
        children: [
          { title: "Accounts", url: "/app/accounts" },
          { title: "Contract Cleaners", url: "/app/kp/contract-cleaners" },
          { title: "Dormant Customers", url: "/app/kp/dormant" },
          { title: "Items Not Ordered", url: "/app/kp/items-not-ordered" },
          { title: "Meetings", url: "/app/kp/meetings" },
          { title: "Calendar", url: "/app/kp/calendar" },
        ],
      },
      {
        title: "KP FOL",
        url: "/app/kp/fol",
        icon: ClipboardList,
        children: [
          { title: "FOL Requests", url: "/app/kp/fol" },
          { title: "FOL Calendar", url: "/app/kp/fol/calendar" },
          { title: "FOL Settings", url: "/app/kp/fol/settings" },
        ],
      },
      {
        title: "Price Changes Request",
        url: "/app/price-change-requests",
        icon: BadgeDollarSign,
      },
      { title: "Commissions", url: "/app/kp/commissions", icon: HandCoins },
    ],
  },
  {
    group: "Administration",
    icon: Settings,
    items: [
      { title: "Administration", url: "/app/administration", icon: Settings },
      { title: "Training Adoption", url: "/app/adoption", icon: UserCheck },
      { title: "Order Match", url: "/app/order-match", icon: GitMerge },
      { title: "Mailbox", url: "/app/mailbox", icon: Inbox },
      { title: "Team Members", url: "/app/team", icon: Users },
      { title: "Channel Classification", url: "/app/channel-classification", icon: Tags },
      { title: "Roles & Permissions", url: "/app/roles", icon: ShieldCheck },
      { title: "Sales Order Imports", url: "/app/so-imports", icon: PackageCheck },
      { title: "Profile", url: "/app/profile", icon: UserCircle },
    ],
  },
] as const;

type NavigationGroup = (typeof NAV)[number]["group"];

function menuGroupForPath(pathname: string, searchStr = ""): NavigationGroup | null {
  if (pathname === "/app/downloads") return null;
  if (
    pathname === "/app/ai-intelligence" &&
    (searchStr.includes("tab=genius") || searchStr.includes("tab%3Dgenius"))
  ) {
    return null;
  }
  if (
    pathname === "/app/administration" ||
    pathname === "/app/adoption" ||
    pathname === "/app/order-match" ||
    pathname === "/app/mailbox" ||
    pathname === "/app/team" ||
    pathname === "/app/roles" ||
    pathname === "/app/so-imports" ||
    pathname === "/app/profile"
  ) {
    return "Administration";
  }

  if (
    pathname === "/app/production" ||
    pathname.startsWith("/app/production/") ||
    pathname === "/app/inventory" ||
    pathname.startsWith("/app/inventory/") ||
    pathname === "/app/backorders" ||
    pathname === "/app/fill-rate" ||
    pathname === "/app/business-optimization" ||
    pathname === "/app/zones"
  ) {
    return "Production";
  }

  if (
    pathname.startsWith("/app/sales-consultants") ||
    pathname === "/app/sales-management" ||
    pathname.startsWith("/app/kp/dtc-calltronix") ||
    pathname === "/app/sales-intelligence" ||
    (pathname === "/app/customers" && searchStr.includes("channel="))
  ) {
    return "Sales Intelligence";
  }

  if (
    pathname === "/app/accounts" ||
    pathname.startsWith("/app/kp/contract-cleaners") ||
    pathname.startsWith("/app/kp/dormant") ||
    pathname.startsWith("/app/kp/items-not-ordered") ||
    pathname.startsWith("/app/kp/meetings") ||
    pathname.startsWith("/app/kp/calendar") ||
    pathname.startsWith("/app/kp/fol") ||
    pathname.startsWith("/app/kp/commissions") ||
    pathname.startsWith("/app/price-change-requests")
  ) {
    return "KP Operations";
  }

  return "OrderWatch";
}

export function AppSidebar() {
  const { session } = useAuth();
  const {
    hidden_menus: hiddenMenus,
    permissions,
    sales_intelligence_channels: salesIntelligenceChannels,
  } = useCapabilities();
  const { state, isMobile, setOpenMobile } = useSidebar();
  const collapsed = state === "collapsed";
  const { pathname, searchStr } = useRouterState({
    select: (r) => ({
      pathname: r.location.pathname,
      searchStr: r.location.searchStr ?? "",
    }),
  });
  const role = session?.role;
  const [openGroup, setOpenGroup] = useState<NavigationGroup | null>(() =>
    menuGroupForPath(pathname, searchStr),
  );

  useEffect(() => {
    setOpenGroup(menuGroupForPath(pathname, searchStr));
  }, [pathname, searchStr]);

  const onGeniusTab =
    pathname === "/app/ai-intelligence" &&
    (searchStr.includes("tab=genius") || searchStr.includes("tab%3Dgenius"));

  const isActive = (
    url: string,
    exact?: boolean,
    search?: Record<string, string | undefined>,
  ) => {
    if (url === "/app/ai-intelligence") {
      if (search?.tab === "genius") return onGeniusTab;
      // Company tab (default / tab=company)
      return pathname === "/app/ai-intelligence" && !onGeniusTab;
    }
    if (search && Object.keys(search).length > 0) {
      return (
        pathname === url &&
        Object.entries(search).every(
          ([key, value]) => value == null || searchStr.includes(`${key}=${encodeURIComponent(value)}`),
        )
      );
    }
    return exact ? pathname === url : pathname === url || pathname.startsWith(url + "/");
  };

  function handleNavClick() {
    if (isMobile) setOpenMobile(false);
  }

  return (
    <Sidebar collapsible="icon" className="app-navigation-sidebar">
      <SidebarHeader className="border-b">
        <div className="flex items-center gap-1.5 px-1.5 py-1">
          {collapsed ? (
            /* Icon-only: small square with KF initials / logo mark */
            <div className="flex h-7 w-7 shrink-0 items-center justify-center overflow-hidden rounded bg-white shadow-sm">
              <LogoImage iconOnly className="h-6 w-6 object-contain" />
            </div>
          ) : (
            /* Expanded: full landscape logo */
            <div className="flex h-8 w-full items-center justify-start overflow-hidden rounded bg-white px-1.5 shadow-sm">
              <LogoImage className="h-6 w-auto max-w-[120px] object-contain" />
            </div>
          )}
        </div>
      </SidebarHeader>
      <SidebarContent>
        {NAV.map((g) => {
          // KP is a separately authorized operating area. Do not render even
          // the group heading for MT/GT portfolio users without KP access.
          if (g.group === "KP Operations" && !salesIntelligenceChannels.includes("kp")) {
            return null;
          }
          const visibleItems = g.items.filter((item) => {
            const itemUrl = item.url as string;
            if ("capability" in item) {
              const capability = item.capability;
              const allowed = capability === "mt"
                ? salesIntelligenceChannels.includes("mt1") ||
                  salesIntelligenceChannels.includes("mt2")
                : salesIntelligenceChannels.includes(capability);
              if (!allowed) return false;

              if (item.url !== "/app/kp/dtc-calltronix" && item.url !== "/app/sales-management") {
                return true;
              }
            }
            // Kimfay Genius + AI Intelligence: every authenticated role
            if (item.url === "/app/ai-intelligence") {
              return Boolean(role);
            }
            if (itemUrl === "/app/accounts") {
              // Shared across teams — anyone with either portfolio-view permission sees it,
              // not just KP FOL users.
              return (
                (permissions.includes("kp.fol.view") ||
                  permissions.includes("kp.accounts.view") ||
                  role === "Administrator") &&
                canAccessNavItem(role, itemUrl, hiddenMenus)
              );
            }
            if (
              itemUrl === "/app/kp/contract-cleaners" ||
              itemUrl === "/app/kp/dormant" ||
              itemUrl === "/app/kp/items-not-ordered" ||
              itemUrl === "/app/kp/meetings" ||
              itemUrl === "/app/kp/calendar"
            ) {
              return (
                (permissions.includes("kp.fol.view") || role === "Administrator") &&
                canAccessNavItem(role, itemUrl, hiddenMenus)
              );
            }
            if (item.url === "/app/kp/dtc-calltronix") {
              return (
                permissions.includes("dtc.view") &&
                canAccessNavItem(role, "/app/kp/dtc-calltronix/quotes", hiddenMenus)
              );
            }
            if (item.url === "/app/kp/fol") {
              // Parent "KP FOL" shows when the user can open FOL requests, calendar, or settings.
              return (
                (permissions.includes("kp.fol.view") &&
                  canAccessNavItem(role, item.url, hiddenMenus)) ||
                role === "Administrator"
              );
            }
            if (item.url === "/app/price-change-requests") {
              return (
                permissions.includes("pricing.pcr.view") &&
                canAccessNavItem(role, item.url, hiddenMenus)
              );
            }
            if (item.url === "/app/sales-management") {
              return (
                permissions.includes("sales.management.view") &&
                canAccessNavItem(role, item.url, hiddenMenus)
              );
            }
            if (item.url === "/app/kp/commissions") {
              return (
                (permissions.includes("commissions.view_own") || permissions.includes("commissions.review")) &&
                canAccessNavItem(role, item.url, hiddenMenus)
              );
            }
            return canAccessNavItem(role, item.url, hiddenMenus);
          });
          if (visibleItems.length === 0) return null;
          return (
            <Collapsible
              key={g.group}
              open={openGroup === g.group}
              onOpenChange={(open) => setOpenGroup(open ? g.group : openGroup)}
              className="group/main-menu"
            >
              <SidebarGroup className="py-1">
                <CollapsibleTrigger asChild>
                  <SidebarMenuButton
                    size="sm"
                    className="font-semibold"
                    tooltip={g.group}
                  >
                    <g.icon className="h-4 w-4 shrink-0" />
                    {!collapsed && (
                      <>
                        <span className="flex-1 text-left text-xs">{g.group}</span>
                        <ChevronRight className="h-3.5 w-3.5 transition-transform group-data-[state=open]/main-menu:rotate-90" />
                      </>
                    )}
                  </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                  {!collapsed && <SidebarGroupLabel className="sr-only">{g.group}</SidebarGroupLabel>}
                  <SidebarGroupContent className={collapsed ? "" : "ml-2 border-l pl-2"}>
                <SidebarMenu>
                  {visibleItems.map((item) => {
                    const childItems =
                      "children" in item
                        ? item.children.filter((child) => {
                            if (child.url === "/app/kp/fol") {
                              return (
                                permissions.includes("kp.fol.view") &&
                                canAccessNavItem(role, child.url, hiddenMenus)
                              );
                            }
                            if (child.url === "/app/kp/fol/calendar") {
                              const canInstall =
                                permissions.includes("kp.fol.install.execute") ||
                                permissions.includes("kp.fol.install.manage");
                              return (
                                permissions.includes("kp.fol.view") &&
                                canInstall &&
                                canAccessNavItem(role, "/app/kp/fol", hiddenMenus)
                              );
                            }
                            if (child.url === "/app/kp/fol/settings") {
                              return (
                                role === "Administrator" &&
                                canAccessNavItem(role, child.url, hiddenMenus)
                              );
                            }
                            if (
                              child.url === "/app/kp/contract-cleaners" ||
                              child.url === "/app/kp/dormant" ||
                              child.url === "/app/kp/items-not-ordered" ||
                              child.url === "/app/kp/meetings" ||
                              child.url === "/app/kp/calendar"
                            ) {
                              return (
                                (permissions.includes("kp.fol.view") ||
                                  role === "Administrator") &&
                                canAccessNavItem(role, child.url, hiddenMenus)
                              );
                            }
                            if (child.url === "/app/accounts") {
                              return (
                                (permissions.includes("kp.fol.view") ||
                                  permissions.includes("kp.accounts.view") ||
                                  role === "Administrator") &&
                                canAccessNavItem(role, child.url, hiddenMenus)
                              );
                            }
                            return canAccessNavItem(role, child.url, hiddenMenus);
                          })
                        : [];

                    return "children" in item ? (
                      <Collapsible
                        key={`${item.title}-${item.url}`}
                        defaultOpen={childItems.some((child) =>
                          isActive(
                            child.url,
                            false,
                            "search" in child
                              ? (child.search as Record<string, string>)
                              : undefined,
                          ),
                        )}
                        asChild
                      >
                        <SidebarMenuItem>
                          <CollapsibleTrigger asChild>
                            <SidebarMenuButton
                              size="sm"
                              isActive={
                                item.url === "/app/kp/fol"
                                  ? pathname.startsWith("/app/kp/fol")
                                  : childItems.some((child) =>
                                      isActive(
                                        child.url,
                                        false,
                                        "search" in child
                                          ? (child.search as Record<string, string>)
                                          : undefined,
                                      ),
                                    ) || pathname.startsWith(item.url)
                              }
                              tooltip={item.title}
                            >
                              <item.icon className="h-3.5 w-3.5 shrink-0" />
                              {!collapsed && (
                                <>
                                  <span className="flex-1 truncate text-[11px] leading-tight">
                                    {item.title}
                                  </span>
                                  <ChevronRight className="h-3 w-3 transition-transform data-[state=open]:rotate-90" />
                                </>
                              )}
                            </SidebarMenuButton>
                          </CollapsibleTrigger>
                          {!collapsed && (
                            <CollapsibleContent className="ml-5 border-l pl-2">
                              {childItems.map((child) => (
                                <SidebarMenuButton
                                  key={
                                    "search" in child
                                      ? `${child.url}?${new URLSearchParams(child.search as Record<string, string>).toString()}`
                                      : `${item.title}-${child.url}-${child.title}`
                                  }
                                  asChild
                                  size="sm"
                                  isActive={isActive(
                                    child.url,
                                    child.url === "/app/kp/fol",
                                    "search" in child
                                      ? (child.search as Record<string, string>)
                                      : undefined,
                                  )}
                                >
                                  <Link
                                    to={child.url}
                                    search={"search" in child ? child.search : undefined}
                                    className="text-[11px]"
                                    onClick={handleNavClick}
                                  >
                                    {child.title}
                                  </Link>
                                </SidebarMenuButton>
                              ))}
                            </CollapsibleContent>
                          )}
                        </SidebarMenuItem>
                      </Collapsible>
                    ) : (
                      <SidebarMenuItem
                        key={
                          "search" in item && item.search
                            ? `${item.url}?${new URLSearchParams(item.search as Record<string, string>).toString()}`
                            : `${item.title}-${item.url}`
                        }
                      >
                        <SidebarMenuButton
                          asChild
                          size="sm"
                          isActive={isActive(
                            item.url,
                            (item as { exact?: boolean }).exact,
                            "search" in item
                              ? (item.search as Record<string, string>)
                              : undefined,
                          )}
                          tooltip={item.title}
                        >
                          <Link
                            to={item.url}
                            search={"search" in item ? item.search : undefined}
                            className="flex items-center gap-1.5"
                            onClick={handleNavClick}
                          >
                            <item.icon className="h-3.5 w-3.5 shrink-0" />
                            {!collapsed && (
                              <span className="truncate text-[11px] leading-tight">
                                {item.title}
                              </span>
                            )}
                          </Link>
                        </SidebarMenuButton>
                      </SidebarMenuItem>
                    );
                  })}
                </SidebarMenu>
                  </SidebarGroupContent>
                </CollapsibleContent>
              </SidebarGroup>
            </Collapsible>
          );
        })}
      </SidebarContent>
      <SidebarFooter className="border-t border-sidebar-border p-2">
        <SidebarMenu>
          {role && (
            <SidebarMenuItem>
              <SidebarMenuButton
                asChild
                size="sm"
                isActive={onGeniusTab}
                tooltip="Kimfay Genius"
              >
                <Link
                  to="/app/ai-intelligence"
                  search={{ tab: "genius" }}
                  className="flex items-center gap-1.5"
                  onClick={handleNavClick}
                >
                  <Brain className="h-3.5 w-3.5 shrink-0" />
                  {!collapsed && <span className="truncate font-semibold">Kimfay Genius</span>}
                </Link>
              </SidebarMenuButton>
            </SidebarMenuItem>
          )}
          <SidebarMenuItem>
            <SidebarMenuButton
              asChild
              size="sm"
              isActive={pathname === "/app/downloads"}
              tooltip="Downloads"
            >
              <Link
                to="/app/downloads"
                className="flex items-center gap-1.5"
                onClick={handleNavClick}
              >
                <Download className="h-3.5 w-3.5 shrink-0" />
                {!collapsed && <span className="truncate font-semibold">Downloads</span>}
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
      </SidebarFooter>
    </Sidebar>
  );
}
