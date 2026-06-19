import { Link, useNavigate } from "@tanstack/react-router";
import { Bell, LogOut, RefreshCw, Search } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { SidebarTrigger } from "@/components/ui/sidebar";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ThemeToggle } from "@/components/theme-toggle";
import { useAuth } from "@/lib/auth";
import { NOTIFICATIONS } from "@/lib/demo-data";

export function AppHeader() {
  const { session, logout } = useAuth();
  const navigate = useNavigate();
  const unread = NOTIFICATIONS.filter((n) => !n.read).length;

  const initials = (session?.name ?? "U")
    .split(" ")
    .map((p) => p[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  return (
    <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b bg-background/95 px-3 backdrop-blur supports-[backdrop-filter]:bg-background/80">
      <SidebarTrigger />
      <div className="relative hidden md:block w-80">
        <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
        <Input placeholder="Search orders, POs, customers…" className="h-9 pl-8 text-sm" />
      </div>
      <div className="ml-auto flex items-center gap-1">
        <div className="hidden lg:flex items-center gap-2 rounded-md border bg-muted/40 px-2 py-1 text-[11px] text-muted-foreground">
          <span className="inline-flex h-1.5 w-1.5 animate-pulse rounded-full bg-success" />
          <span>Outlook synced 12:04</span>
          <span className="mx-1 opacity-50">·</span>
          <span>Acumatica synced 12:00</span>
          <span className="mx-1 opacity-50">·</span>
          <span>Next AI 15:00</span>
        </div>
        <Button
          variant="ghost"
          size="icon"
          onClick={() => toast.success("Sync triggered")}
          aria-label="Refresh"
        >
          <RefreshCw className="h-4 w-4" />
        </Button>
        <Link to="/app/notifications" className="relative inline-flex">
          <Button variant="ghost" size="icon" aria-label="Notifications">
            <Bell className="h-4 w-4" />
          </Button>
          {unread > 0 && (
            <span className="pointer-events-none absolute right-1 top-1 inline-flex h-4 min-w-[16px] items-center justify-center rounded-full bg-destructive px-1 text-[10px] font-semibold text-destructive-foreground">
              {unread}
            </span>
          )}
        </Link>
        <ThemeToggle />
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" className="h-9 gap-2 px-2">
              <div className="flex h-7 w-7 items-center justify-center rounded-full bg-primary text-[11px] font-semibold text-primary-foreground">
                {initials}
              </div>
              <div className="hidden text-left md:block">
                <div className="text-xs font-medium leading-tight">{session?.name}</div>
                <div className="text-[10px] leading-tight text-muted-foreground">{session?.role}</div>
              </div>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuLabel>
              <div className="text-sm font-medium">{session?.name}</div>
              <div className="text-xs text-muted-foreground">{session?.email}</div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem onClick={() => navigate({ to: "/app/profile" })}>Profile</DropdownMenuItem>
            <DropdownMenuItem onClick={() => navigate({ to: "/app/administration" })}>Administration</DropdownMenuItem>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onClick={() => {
                logout();
                navigate({ to: "/auth" });
              }}
            >
              <LogOut className="mr-2 h-4 w-4" /> Sign out
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  );
}
