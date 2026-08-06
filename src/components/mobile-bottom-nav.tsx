import { Link, useRouterState } from "@tanstack/react-router";
import { Brain, Factory, LayoutDashboard, Menu, SprayCan } from "lucide-react";
import { useSidebar } from "@/components/ui/sidebar";
import { cn } from "@/lib/utils";

export function MobileBottomNav() {
  const { toggleSidebar } = useSidebar();
  const { pathname, searchStr } = useRouterState({
    select: (state) => ({
      pathname: state.location.pathname,
      searchStr: state.location.searchStr ?? "",
    }),
  });

  const geniusActive =
    pathname === "/app/ai-intelligence" &&
    (searchStr.includes("tab=genius") || searchStr.includes("tab%3Dgenius"));
  const moreActive =
    pathname === "/app/downloads" ||
    pathname === "/app/administration" ||
    pathname === "/app/adoption" ||
    pathname === "/app/order-match" ||
    pathname === "/app/mailbox" ||
    pathname === "/app/team" ||
    pathname === "/app/roles" ||
    pathname === "/app/so-imports" ||
    pathname === "/app/profile";

  const items = [
    {
      label: "OrderWatch",
      icon: LayoutDashboard,
      to: "/app" as const,
      active:
        !geniusActive &&
        !pathname.startsWith("/app/production") &&
        !pathname.startsWith("/app/kp/") &&
        !pathname.startsWith("/app/price-change-requests") &&
        pathname !== "/app/sales-management" &&
        !moreActive,
    },
    {
      label: "Production",
      icon: Factory,
      to: "/app/production" as const,
      active:
        pathname.startsWith("/app/production") ||
        pathname === "/app/inventory" ||
        pathname === "/app/backorders" ||
        pathname === "/app/fill-rate" ||
        pathname === "/app/business-optimization" ||
        pathname === "/app/zones",
    },
    {
      label: "KP",
      icon: SprayCan,
      to: "/app/kp/contract-cleaners" as const,
      active:
        pathname.startsWith("/app/kp/") ||
        pathname.startsWith("/app/price-change-requests") ||
        pathname === "/app/sales-management",
    },
  ];

  return (
    <nav className="mobile-bottom-nav md:hidden" aria-label="Primary mobile navigation">
      {items.map((item) => (
        <Link
          key={item.label}
          to={item.to}
          className={cn("mobile-bottom-nav__item", item.active && "mobile-bottom-nav__item--active")}
        >
          <item.icon className="h-4 w-4" />
          <span>{item.label}</span>
        </Link>
      ))}
      <Link
        to="/app/ai-intelligence"
        search={{ tab: "genius" }}
        className={cn("mobile-bottom-nav__item", geniusActive && "mobile-bottom-nav__item--active")}
      >
        <Brain className="h-4 w-4" />
        <span>Genius</span>
      </Link>
      <button
        type="button"
        className={cn("mobile-bottom-nav__item", moreActive && "mobile-bottom-nav__item--active")}
        onClick={toggleSidebar}
      >
        <Menu className="h-4 w-4" />
        <span>More</span>
      </button>
    </nav>
  );
}
