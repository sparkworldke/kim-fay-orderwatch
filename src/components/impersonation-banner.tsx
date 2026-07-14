import { UserCog, LogOut } from "lucide-react";
import { Button } from "@/components/ui/button";
import { useAuth } from "@/lib/auth";
import { useStopImpersonation } from "@/hooks/admin/useImpersonation";

/** Sticky bar shown while an admin is acting as another user. */
export function ImpersonationBanner() {
  const { session, isImpersonating } = useAuth();
  const stop = useStopImpersonation();

  if (!isImpersonating || !session) return null;

  const adminName = session.impersonator?.name ?? "Admin";

  return (
    <div
      role="status"
      className="sticky top-0 z-40 flex flex-wrap items-center justify-between gap-2 border-b border-amber-500/40 bg-amber-500/15 px-3 py-2 text-sm text-amber-950 dark:text-amber-50"
    >
      <div className="flex min-w-0 items-center gap-2">
        <UserCog className="h-4 w-4 shrink-0" />
        <span className="truncate">
          Viewing as <strong>{session.name}</strong>
          <span className="text-muted-foreground"> ({session.role})</span>
          <span className="hidden sm:inline"> · switched by {adminName}</span>
        </span>
      </div>
      <Button
        size="sm"
        variant="outline"
        className="shrink-0 border-amber-600/50 bg-background/80 hover:bg-background"
        disabled={stop.isPending}
        onClick={() => stop.mutate()}
      >
        <LogOut className="mr-1.5 h-3.5 w-3.5" />
        {stop.isPending ? "Returning…" : "Return to admin"}
      </Button>
    </div>
  );
}
