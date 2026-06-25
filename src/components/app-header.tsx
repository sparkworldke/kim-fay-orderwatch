import { useNavigate } from "@tanstack/react-router";
import { LogOut, RefreshCw, Loader2 } from "lucide-react";
import { LogoImage } from "@/components/logo-image";
import { Button } from "@/components/ui/button";
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
import { useSyncAllMailboxes } from "@/hooks/mailbox/useMailbox";

export function AppHeader() {
  const { session, logout } = useAuth();
  const navigate  = useNavigate();
  const syncAll   = useSyncAllMailboxes();

  const initials = (session?.name ?? "U")
    .split(" ")
    .map((p) => p[0])
    .slice(0, 2)
    .join("")
    .toUpperCase();

  return (
    <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b bg-background/95 px-3 backdrop-blur supports-[backdrop-filter]:bg-background/80">
      <SidebarTrigger />

      {/* Logo — visible on mobile when sidebar is hidden as drawer */}
      <div className="flex h-8 items-center overflow-hidden rounded bg-white px-2 shadow-sm md:hidden">
        <LogoImage className="h-6 w-auto max-w-[110px] object-contain" />
      </div>

      <div className="ml-auto flex items-center gap-1">
        <Button
          variant="ghost"
          size="icon"
          disabled={syncAll.isPending}
          onClick={() => syncAll.mutate()}
          aria-label="Sync mailboxes"
          title="Sync all mailboxes now"
        >
          {syncAll.isPending
            ? <Loader2 className="h-4 w-4 animate-spin" />
            : <RefreshCw className="h-4 w-4" />}
        </Button>
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
