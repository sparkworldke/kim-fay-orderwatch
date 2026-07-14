import { useState } from "react";
import { Clock } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Badge } from "@/components/ui/badge";
import { useUserSessions } from "@/hooks/admin/useAdminSettings";
import type { TeamMember } from "@/types/admin";

function formatDuration(seconds: number | null): string {
  if (seconds == null) return "—";
  if (seconds < 60) return `${seconds}s`;
  const mins = Math.floor(seconds / 60);
  const rem = seconds % 60;
  if (mins < 60) return rem > 0 ? `${mins}m ${rem}s` : `${mins}m`;
  const hours = Math.floor(mins / 60);
  const remMins = mins % 60;
  return remMins > 0 ? `${hours}h ${remMins}m` : `${hours}h`;
}

function formatLogoutReason(reason: string | null): string {
  if (!reason) return "—";
  if (reason === "idle") return "Idle timeout";
  if (reason === "manual") return "Manual logout";
  return reason;
}

function formatLoginMode(mode: string): string {
  if (mode === "otp-only") return "OTP only";
  if (mode === "otp-and-password") return "OTP + Password";
  return mode;
}

type Props = {
  member: TeamMember;
  onClose: () => void;
};

export function UserSessionsSheet({ member, onClose }: Props) {
  const [page, setPage] = useState(1);
  const sessions = useUserSessions(member.id, page);

  return (
    <Sheet open onOpenChange={(open) => !open && onClose()}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <Clock className="h-4 w-4" />
            Session History
          </SheetTitle>
          <SheetDescription>{member.name} — login activity and session duration</SheetDescription>
        </SheetHeader>

        <div className="mt-4 space-y-2">
          {sessions.isLoading && (
            <div className="space-y-2 pt-2">
              {[1, 2, 3].map((i) => <Skeleton key={i} className="h-16 w-full" />)}
            </div>
          )}

          {sessions.isError && (
            <p className="text-sm text-destructive">Failed to load session history.</p>
          )}

          {sessions.data && sessions.data.data.length === 0 && (
            <p className="py-6 text-center text-sm text-muted-foreground">No sessions recorded yet.</p>
          )}

          {sessions.data?.data.map((entry) => (
            <div key={entry.id} className="rounded-md border p-3 text-sm">
              <div className="flex items-center justify-between gap-2">
                <span className="text-xs font-medium">
                  {new Date(entry.login_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
                </span>
                {!entry.logout_at && (
                  <Badge variant="outline" className="text-[10px]">Active</Badge>
                )}
              </div>
              <div className="mt-1 grid gap-0.5 text-xs text-muted-foreground">
                {entry.logout_at && (
                  <div>
                    Logout:{" "}
                    {new Date(entry.logout_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
                    {" · "}{formatLogoutReason(entry.logout_reason)}
                  </div>
                )}
                <div>Duration: {formatDuration(entry.duration_seconds)}</div>
                <div>IP: {entry.ip_address ?? "—"} · {formatLoginMode(entry.login_mode)}</div>
              </div>
            </div>
          ))}

          {sessions.data && sessions.data.last_page > 1 && (
            <div className="flex items-center justify-between gap-2 pt-2">
              <span className="text-xs text-muted-foreground">
                Page {sessions.data.current_page} of {sessions.data.last_page}
              </span>
              <div className="flex gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={sessions.data.current_page <= 1}
                >
                  Previous
                </Button>
                <Button
                  size="sm"
                  variant="outline"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={sessions.data.current_page >= sessions.data.last_page}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </div>
      </SheetContent>
    </Sheet>
  );
}