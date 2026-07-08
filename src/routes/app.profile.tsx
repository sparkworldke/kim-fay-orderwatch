import { createFileRoute } from "@tanstack/react-router";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useEffect, useState } from "react";
import { Eye, EyeOff, KeyRound, Loader2 } from "lucide-react";
import { toast } from "sonner";
import { apiFetch, ApiError } from "@/lib/api";
import { setSession, setToken } from "@/lib/auth";
import type { Role } from "@/lib/auth";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
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

interface PasswordUpdateResponse {
  message: string;
  token: string;
  user: { id: number; name: string; email: string; role: string; rep_code?: string | null };
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
  const [passwordPanelOpen, setPasswordPanelOpen] = useState(false);
  const [passwordOtp, setPasswordOtp] = useState("");
  const [passwordOtpVerified, setPasswordOtpVerified] = useState(false);
  const [newPassword, setNewPassword] = useState("");
  const [newPasswordConfirmation, setNewPasswordConfirmation] = useState("");
  const [showNewPassword, setShowNewPassword] = useState(false);
  const [showNewPasswordConfirmation, setShowNewPasswordConfirmation] = useState(false);
  const [passwordOtpSecondsLeft, setPasswordOtpSecondsLeft] = useState(0);

  // Sync query data into form state
  useEffect(() => {
    if (profile) {
      setName(profile.name);
      setPhoneNumber(profile.phone_number ?? "");
    }
  }, [profile]);

  useEffect(() => {
    if (!passwordPanelOpen || passwordOtpSecondsLeft <= 0) return;

    const timer = window.setInterval(() => {
      setPasswordOtpSecondsLeft((seconds) => Math.max(0, seconds - 1));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [passwordPanelOpen, passwordOtpSecondsLeft]);

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

  const requestPasswordOtpMutation = useMutation({
    mutationFn: () =>
      apiFetch<{ message: string }>("profile/password/otp", {
        method: "POST",
      }),
    onSuccess: (data) => {
      setPasswordPanelOpen(true);
      setPasswordOtp("");
      setPasswordOtpVerified(false);
      setNewPassword("");
      setNewPasswordConfirmation("");
      setPasswordOtpSecondsLeft(900);
      toast.success(data.message || "Verification code sent to your email");
    },
    onError: (err: unknown) => {
      toast.error(err instanceof Error ? err.message : "Failed to send verification code");
    },
  });

  const verifyPasswordOtpMutation = useMutation({
    mutationFn: () =>
      apiFetch<{ message: string }>("profile/password/otp/verify", {
        method: "POST",
        body: { otp: passwordOtp },
      }),
    onSuccess: (data) => {
      setPasswordOtpVerified(true);
      toast.success(data.message || "Verification code confirmed");
    },
    onError: (err: unknown) => {
      setPasswordOtpVerified(false);
      toast.error(err instanceof Error ? err.message : "Verification failed");
    },
  });

  const updatePasswordMutation = useMutation({
    mutationFn: () =>
      apiFetch<PasswordUpdateResponse>("profile/password", {
        method: "PATCH",
        body: {
          otp: passwordOtp,
          new_password: newPassword,
          new_password_confirmation: newPasswordConfirmation,
        },
      }),
    onSuccess: (data) => {
      setToken(data.token);
      setSession({
        id: data.user.id,
        email: data.user.email,
        name: data.user.name,
        role: data.user.role as Role,
        rep_code: data.user.rep_code ?? null,
        loggedInAt: new Date().toISOString(),
        token: data.token,
      });
      setPasswordPanelOpen(false);
      setPasswordOtp("");
      setPasswordOtpVerified(false);
      setNewPassword("");
      setNewPasswordConfirmation("");
      setPasswordOtpSecondsLeft(0);
      toast.success(data.message || "Password updated successfully");
    },
    onError: (err: unknown) => {
      toast.error(err instanceof Error ? err.message : "Password update failed");
    },
  });

  // ── Sign-in logs query ──────────────────────────────────────────────────
  const [page, setPage] = useState(1);

  const { data: logsData, isLoading: logsLoading } = useQuery({
    queryKey: ["sign-in-logs", page],
    queryFn: () => apiFetch<SignInLogsResponse>(`profile/sign-in-logs?page=${page}`),
  });

  const passwordMatches =
    newPassword.length > 0 && newPassword === newPasswordConfirmation;
  const canSetPassword =
    passwordOtpVerified &&
    passwordOtp.length === 6 &&
    newPassword.length >= 8 &&
    passwordMatches &&
    !updatePasswordMutation.isPending;

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

      {/* ── Password Update ── */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <KeyRound className="h-4 w-4" />
            Update Password
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <p className="text-sm text-muted-foreground">
            Start a password update by verifying a code sent to your registered email.
          </p>

          {!passwordPanelOpen ? (
            <Button
              variant="outline"
              onClick={() => requestPasswordOtpMutation.mutate()}
              disabled={requestPasswordOtpMutation.isPending}
            >
              {requestPasswordOtpMutation.isPending && (
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
              )}
              Send verification code
            </Button>
          ) : (
            <div className="space-y-4">
              <div className="space-y-2">
                <Label>Verification code</Label>
                <InputOTP
                  maxLength={6}
                  value={passwordOtp}
                  onChange={(value) => {
                    setPasswordOtp(value);
                    setPasswordOtpVerified(false);
                  }}
                  containerClassName="w-full"
                >
                  <InputOTPGroup className="w-full">
                    {[0, 1, 2, 3, 4, 5].map((index) => (
                      <InputOTPSlot
                        key={index}
                        index={index}
                        className="h-12 flex-1 rounded-none border-y border-r bg-muted/40 text-lg first:rounded-l-md first:border-l last:rounded-r-md"
                      />
                    ))}
                  </InputOTPGroup>
                </InputOTP>
                <div className="flex flex-col gap-2 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
                  <span>
                    {passwordOtpSecondsLeft > 0
                      ? `Code expires in ${passwordOtpSecondsLeft}s`
                      : "Request a new code if this one expired."}
                  </span>
                  <button
                    type="button"
                    className="self-start font-medium text-primary hover:underline disabled:opacity-50 sm:self-auto"
                    onClick={() => requestPasswordOtpMutation.mutate()}
                    disabled={requestPasswordOtpMutation.isPending}
                  >
                    {requestPasswordOtpMutation.isPending ? "Sending..." : "Resend OTP"}
                  </button>
                </div>
              </div>

              <Button
                type="button"
                variant={passwordOtpVerified ? "secondary" : "default"}
                onClick={() => verifyPasswordOtpMutation.mutate()}
                disabled={
                  passwordOtp.length !== 6 ||
                  verifyPasswordOtpMutation.isPending ||
                  passwordOtpVerified
                }
              >
                {verifyPasswordOtpMutation.isPending && (
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                )}
                {passwordOtpVerified ? "OTP verified" : "Verify OTP"}
              </Button>

              <Separator />

              <div className="space-y-4">
                <div className="space-y-1.5">
                  <Label htmlFor="new-password">New password</Label>
                  <div className="relative">
                    <Input
                      id="new-password"
                      type={showNewPassword ? "text" : "password"}
                      value={newPassword}
                      onChange={(event) => setNewPassword(event.target.value)}
                      disabled={!passwordOtpVerified}
                      placeholder="At least 8 characters"
                      className="pr-10"
                    />
                    <button
                      type="button"
                      onClick={() => setShowNewPassword((value) => !value)}
                      className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground hover:text-foreground disabled:opacity-50"
                      disabled={!passwordOtpVerified}
                      aria-label={showNewPassword ? "Hide password" : "Show password"}
                    >
                      {showNewPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                  </div>
                  {newPassword.length > 0 && newPassword.length < 8 && (
                    <p className="text-xs text-destructive">
                      Password must be at least 8 characters.
                    </p>
                  )}
                </div>

                <div className="space-y-1.5">
                  <Label htmlFor="new-password-confirmation">Confirm new password</Label>
                  <div className="relative">
                    <Input
                      id="new-password-confirmation"
                      type={showNewPasswordConfirmation ? "text" : "password"}
                      value={newPasswordConfirmation}
                      onChange={(event) => setNewPasswordConfirmation(event.target.value)}
                      disabled={!passwordOtpVerified}
                      placeholder="Re-enter new password"
                      className="pr-10"
                    />
                    <button
                      type="button"
                      onClick={() => setShowNewPasswordConfirmation((value) => !value)}
                      className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground hover:text-foreground disabled:opacity-50"
                      disabled={!passwordOtpVerified}
                      aria-label={showNewPasswordConfirmation ? "Hide password" : "Show password"}
                    >
                      {showNewPasswordConfirmation ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                  </div>
                  {newPasswordConfirmation.length > 0 && !passwordMatches && (
                    <p className="text-xs text-destructive">Passwords do not match.</p>
                  )}
                </div>
              </div>

              <div className="flex flex-col gap-2 sm:flex-row">
                <Button
                  type="button"
                  onClick={() => updatePasswordMutation.mutate()}
                  disabled={!canSetPassword}
                >
                  {updatePasswordMutation.isPending && (
                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  )}
                  Set new password
                </Button>
                <Button
                  type="button"
                  variant="ghost"
                  onClick={() => {
                    setPasswordPanelOpen(false);
                    setPasswordOtp("");
                    setPasswordOtpVerified(false);
                    setNewPassword("");
                    setNewPasswordConfirmation("");
                    setPasswordOtpSecondsLeft(0);
                  }}
                  disabled={updatePasswordMutation.isPending}
                >
                  Cancel
                </Button>
              </div>
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
                          {new Date(entry.created_at).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" })}
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
