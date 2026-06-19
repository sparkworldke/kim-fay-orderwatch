import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Loader2 } from "lucide-react";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Badge } from "@/components/ui/badge";
import { Separator } from "@/components/ui/separator";

export const Route = createFileRoute("/app/profile")({
  head: () => ({ meta: [{ title: "Profile — Kim-Fay OrderWatch" }] }),
  component: ProfilePage,
});

// ── Types ────────────────────────────────────────────────────────────────────

interface ProfileData {
  id: number;
  name: string;
  email: string;
  role: string;
  phone_number: string | null;
  updated_at: string;
}

interface SignInLogEntry {
  id: number;
  created_at: string;
  ip_address: string;
  user_agent: string;
  login_mode: string;
  status: "success" | "failure";
}

interface SignInLogsResponse {
  data: SignInLogEntry[];
  current_page: number;
  last_page: number;
  total: number;
}

interface ProfileErrors {
  name?: string[];
  phone_number?: string[];
}

// ── Helper ───────────────────────────────────────────────────────────────────

function formatLoginMode(mode: string): string {
  if (mode === "otp-only") return "OTP only";
  if (mode === "otp-and-password") return "OTP + Password";
  return mode;
}

function truncateUserAgent(ua: string): string {
  return ua.length > 40 ? ua.slice(0, 40) + "…" : ua;
}

// ── Loading skeleton for sign-in logs ───────────────────────────────────────

function LogSkeleton() {
  return (
    <div className="space-y-2">
      {[0, 1, 2].map((i) => (
        <div key={i} className="h-8 w-full animate-pulse rounded bg-muted" />
      ))}
    </div>
  );
}

// ── Main page ────────────────────────────────────────────────────────────────

function ProfilePage() {
  const queryClient = useQueryClient();

  // ── Personal information query ──────────────────────────────────────────
  const { data: profile, isLoading: profileLoading } = useQuery({
    queryKey: ["profile"],
    queryFn: () => apiFetch<ProfileData>("profile"),
  });

  // Local form state
  const [name, setName] = useState("");
  const [phoneNumber, setPhoneNumber] = useState("");
  const [fieldErrors, setFieldErrors] = useState<ProfileErrors>({});

  // Sync query data into form state
  useEffect(() => {
    if (profile) {
      setName(profile.name);
      setPhoneNumber(profile.phone_number ?? "");
    }
  }, [profile]);

  const updateMutation = useMutation({
    mutationFn: () =>
      apiFetch("profile", {
        method: "PATCH",
        body: { name, phone_number: phoneNumber || null },
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["profile"] });
      setFieldErrors({});
      toast.success("Profile updated");
    },
    onError: (err: unknown) => {
      if (
        err instanceof ApiError &&
        err.status === 422 &&
        err.data &&
        typeof err.data === "object" &&
        "errors" in (err.data as object)
      ) {
        setFieldErrors((err.data as { errors: ProfileErrors }).errors);
      } else {
        toast.error(err instanceof Error ? err.message : "Something went wrong");
      }
    },
  });

  // ── Sign-in logs query ──────────────────────────────────────────────────
  const [page, setPage] = useState(1);

  const { data: logsData, isLoading: logsLoading } = useQuery({
    queryKey: ["sign-in-logs", page],
    queryFn: () => apiFetch<SignInLogsResponse>(`profile/sign-in-logs?page=${page}`),
  });

  // ── Render ──────────────────────────────────────────────────────────────
  return (
    <div className="space-y-6 max-w-2xl">
      <h1 className="text-xl font-semibold tracking-tight">Profile</h1>

      {/* ── Personal Information ── */}
      <Card>
        <CardHeader>
          <CardTitle>Personal Information</CardTitle>
        </CardHeader>
        <CardContent>
          {profileLoading ? (
            <div className="flex items-center justify-center py-8">
              <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <div className="space-y-4">
              {/* Name */}
              <div className="space-y-1.5">
                <Label htmlFor="profile-name">Name</Label>
                <Input
                  id="profile-name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="Your full name"
                />
                {fieldErrors.name && (
                  <p className="text-xs text-destructive">{fieldErrors.name[0]}</p>
                )}
              </div>

              {/* Email — read-only */}
              <div className="space-y-1.5">
                <Label htmlFor="profile-email">Email</Label>
                <Input
                  id="profile-email"
                  value={profile?.email ?? ""}
                  readOnly
                  disabled
                  className="text-muted-foreground"
                />
              </div>

              {/* Phone number */}
              <div className="space-y-1.5">
                <Label htmlFor="profile-phone">Phone number</Label>
                <Input
                  id="profile-phone"
                  value={phoneNumber}
                  onChange={(e) => setPhoneNumber(e.target.value)}
                  placeholder="+254712345678"
                />
                {fieldErrors.phone_number && (
                  <p className="text-xs text-destructive">{fieldErrors.phone_number[0]}</p>
                )}
              </div>

              {/* Role — read-only badge */}
              <div className="space-y-1.5">
                <Label>Role</Label>
                <div>
                  <Badge variant="secondary">{profile?.role ?? "—"}</Badge>
                </div>
              </div>

              <Separator />

              <Button
                onClick={() => updateMutation.mutate()}
                disabled={updateMutation.isPending}
              >
                {updateMutation.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                Save changes
              </Button>
            </div>
          )}
        </CardContent>
      </Card>

      {/* ── Sign-in History ── */}
      <Card>
        <CardHeader>
          <CardTitle>Sign-in History</CardTitle>
        </CardHeader>
        <CardContent>
          {logsLoading ? (
            <LogSkeleton />
          ) : !logsData || logsData.data.length === 0 ? (
            <p className="text-sm text-muted-foreground">No sign-in history yet.</p>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full text-sm">
                  <thead className="bg-muted/30 text-[11px] uppercase tracking-wide text-muted-foreground">
                    <tr>
                      <th className="px-3 py-2 text-left font-semibold">Date/Time</th>
                      <th className="px-3 py-2 text-left font-semibold">IP Address</th>
                      <th className="px-3 py-2 text-left font-semibold">Device</th>
                      <th className="px-3 py-2 text-left font-semibold">Mode</th>
                      <th className="px-3 py-2 text-left font-semibold">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {logsData.data.map((entry) => (
                      <tr key={entry.id} className="border-t">
                        <td className="px-3 py-2 text-xs tabular-nums whitespace-nowrap">
                          {new Date(entry.created_at).toLocaleString("en-KE")}
                        </td>
                        <td className="px-3 py-2 font-mono text-xs">{entry.ip_address}</td>
                        <td className="px-3 py-2 text-xs text-muted-foreground">
                          {truncateUserAgent(entry.user_agent)}
                        </td>
                        <td className="px-3 py-2 text-xs">
                          {formatLoginMode(entry.login_mode)}
                        </td>
                        <td className="px-3 py-2">
                          {entry.status === "success" ? (
                            <span className="inline-flex items-center rounded border border-success/30 bg-success/15 px-1.5 py-0.5 text-[11px] font-medium text-success">
                              Success
                            </span>
                          ) : (
                            <span className="inline-flex items-center rounded border border-destructive/30 bg-destructive/15 px-1.5 py-0.5 text-[11px] font-medium text-destructive">
                              Failure
                            </span>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {logsData.last_page > 1 && (
                <div className="mt-4 flex items-center justify-between gap-2">
                  <span className="text-xs text-muted-foreground">
                    Page {logsData.current_page} of {logsData.last_page}
                  </span>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setPage((p) => Math.max(1, p - 1))}
                      disabled={logsData.current_page <= 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setPage((p) => p + 1)}
                      disabled={logsData.current_page >= logsData.last_page}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
