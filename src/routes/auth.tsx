import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useEffect, useRef, useState } from "react";
import { ArrowRight, CheckCircle2, Eye, EyeOff, Loader2, ShieldCheck } from "lucide-react";
import { toast } from "sonner";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { InputOTP, InputOTPGroup, InputOTPSlot } from "@/components/ui/input-otp";
import { setSession, setToken } from "@/lib/auth";
import type { Role } from "@/lib/auth";
import { apiFetch, getErrorMessage } from "@/lib/api";
import { LogoImage } from "@/components/logo-image";

export const Route = createFileRoute("/auth")({
  head: () => ({
    meta: [
      { title: "Sign in — Kim-Fay OrderWatch" },
      { name: "robots", content: "noindex,nofollow" },
    ],
  }),
  component: AuthPage,
});

type LoginMode = "otp-only" | "otp-and-password";

type EmailValidation =
  | { status: "idle" }
  | { status: "checking" }
  | { status: "valid" }
  | { status: "invalid-format"; message: string }
  | { status: "not-registered"; message: string }
  | { status: "inactive"; message: string }
  | { status: "error"; message: string };

type EmailCheckResponse = {
  exists: boolean;
  eligible: boolean;
  status: "registered" | "not_registered" | "inactive";
  message?: string;
};

/** Loose format check — catches obvious non-emails before hitting the API. */
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Masks an email like "contact@kimfay.com" → "co**********ct@kim***.c**" */
function maskEmail(email: string): string {
  const [local, domain] = email.split("@");
  if (!local || !domain) return email;

  const maskPart = (s: string) => {
    if (s.length <= 2) return s;
    const keep = Math.max(2, Math.ceil(s.length * 0.25));
    const half = Math.ceil(keep / 2);
    const start = s.slice(0, half);
    const end = s.slice(-(keep - half));
    return start + "*".repeat(s.length - keep) + end;
  };

  const dotIdx = domain.lastIndexOf(".");
  const domainName = dotIdx > 0 ? domain.slice(0, dotIdx) : domain;
  const tld = dotIdx > 0 ? domain.slice(dotIdx) : "";

  return `${maskPart(local)}@${maskPart(domainName)}${tld.slice(0, 1)}${"*".repeat(Math.max(0, tld.length - 1))}`;
}

function AuthPage() {
  const navigate = useNavigate();

  const [step, setStep] = useState<"email" | "otp">("email");
  const [email, setEmail] = useState("");
  const [emailValidation, setEmailValidation] = useState<EmailValidation>({ status: "idle" });
  const [otp, setOtp] = useState("");
  const [password, setPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [loginMode, setLoginMode] = useState<LoginMode>("otp-and-password");
  const [secondsLeft, setSecondsLeft] = useState(900);
  const [loading, setLoading] = useState(false);
  const [resending, setResending] = useState(false);
  /** Inline form error (mirrors toast so login failures stay visible). */
  const [formError, setFormError] = useState<string | null>(null);

  const timerRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const validationDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  // Ref holds the latest email so async callbacks can detect stale responses.
  const latestEmailRef = useRef("");

  function startCountdown() {
    if (timerRef.current) clearInterval(timerRef.current);
    setSecondsLeft(900);
    timerRef.current = setInterval(
      () => setSecondsLeft((s) => Math.max(0, s - 1)),
      1000,
    );
  }

  useEffect(() => {
    return () => {
      if (timerRef.current) clearInterval(timerRef.current);
      if (validationDebounceRef.current) clearTimeout(validationDebounceRef.current);
    };
  }, []);

  async function checkEmailRegistration(value: string): Promise<EmailValidation> {
    const data = await apiFetch<EmailCheckResponse>("auth/email/check", {
      method: "POST",
      body: { email: value },
    });

    if (data.eligible) return { status: "valid" };

    if (data.status === "inactive") {
      return {
        status: "inactive",
        message: data.message ?? "This account is not active. Contact your administrator.",
      };
    }

    return {
      status: "not-registered",
      message: data.message ?? "This email is not registered in OrderWatch",
    };
  }

  function showAuthError(message: string) {
    setFormError(message);
    toast.error(message, { duration: 7000 });
  }

  function handleEmailChange(value: string) {
    setEmail(value);
    setFormError(null);
    const normalizedEmail = value.trim().toLowerCase();
    latestEmailRef.current = normalizedEmail;

    if (validationDebounceRef.current) clearTimeout(validationDebounceRef.current);

    if (!normalizedEmail) {
      setEmailValidation({ status: "idle" });
      return;
    }

    // Immediate client-side format check — no network call needed.
    if (!EMAIL_RE.test(normalizedEmail)) {
      setEmailValidation({
        status: "invalid-format",
        message: "Please enter a valid email address",
      });
      return;
    }

    // Format is fine — debounce the registration check.
    setEmailValidation({ status: "checking" });

    const captured = normalizedEmail;
    validationDebounceRef.current = setTimeout(async () => {
      try {
        const result = await checkEmailRegistration(captured);
        // Discard if the user has already changed the email input.
        if (latestEmailRef.current !== captured) return;
        setEmailValidation(result);
      } catch {
        if (latestEmailRef.current !== captured) return;
        setEmailValidation({
          status: "error",
          message: "Unable to verify this email right now. Check your connection and try again.",
        });
      }
    }, 600);
  }

  async function handleEmailStepSubmit(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    const normalizedEmail = email.trim().toLowerCase();

    if (!EMAIL_RE.test(normalizedEmail)) {
      const result: EmailValidation = {
        status: "invalid-format",
        message: "Please enter a valid email address",
      };
      setEmailValidation(result);
      showAuthError(result.message);
      return;
    }

    // Surface inactive / not-registered from the live email check before password POST.
    if (
      emailValidation.status === "inactive" ||
      emailValidation.status === "not-registered" ||
      emailValidation.status === "error"
    ) {
      const message =
        "message" in emailValidation && emailValidation.message
          ? emailValidation.message
          : "Unable to sign in with this email.";
      showAuthError(message);
      return;
    }

    // Password mode: sign in directly, no OTP needed
    if (loginMode === "otp-and-password") {
      if (!password.trim()) {
        showAuthError("Enter your password");
        return;
      }
      setLoading(true);
      try {
        const data = await apiFetch<{
          token: string;
          user: { id: number; name: string; email: string; role: string; rep_code?: string | null };
        }>("auth/login", {
          method: "POST",
          body: { email: normalizedEmail, password },
        });
        setFormError(null);
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
        toast.success("Welcome to OrderWatch");
        navigate({ to: "/app" });
      } catch (err: unknown) {
        showAuthError(
          getErrorMessage(err, "Invalid credentials. Please check your email and password."),
        );
      } finally {
        setLoading(false);
      }
      return;
    }

    // OTP-only mode: validate email then request OTP
    if (emailValidation.status !== "valid" || latestEmailRef.current !== normalizedEmail) {
      try {
        setEmailValidation({ status: "checking" });
        const result = await checkEmailRegistration(normalizedEmail);
        setEmailValidation(result);

        if (result.status !== "valid") {
          showAuthError("message" in result ? result.message : "Email validation failed");
          return;
        }
      } catch {
        const result: EmailValidation = {
          status: "error",
          message: "Unable to verify this email right now. Check your connection and try again.",
        };
        setEmailValidation(result);
        showAuthError(result.message);
        return;
      }
    }

    setLoading(true);
    try {
      await apiFetch("auth/otp/request", {
        method: "POST",
        body: { email: normalizedEmail },
      });
      setEmail(normalizedEmail);
      latestEmailRef.current = normalizedEmail;
      toast.success(`Verification code sent to ${normalizedEmail}`);
      setOtp("");
      setStep("otp");
      startCountdown();
    } catch (err: unknown) {
      showAuthError(getErrorMessage(err, "Failed to send verification code"));
    } finally {
      setLoading(false);
    }
  }

  async function resendOtp() {
    setResending(true);
    setFormError(null);
    try {
      await apiFetch("auth/otp/request", {
        method: "POST",
        body: { email: email.trim().toLowerCase() },
      });
      toast.success("New code sent");
      startCountdown();
    } catch (err: unknown) {
      showAuthError(getErrorMessage(err, "Failed to resend code"));
    } finally {
      setResending(false);
    }
  }

  async function verifyOtp(e: React.FormEvent) {
    e.preventDefault();
    setFormError(null);
    if (otp.length !== 6) {
      showAuthError("Enter the 6-digit code");
      return;
    }
    setLoading(true);
    try {
      const data = await apiFetch<{
        token: string;
        user: { id: number; name: string; email: string; role: string; rep_code?: string | null };
      }>("auth/otp/verify", {
        method: "POST",
        body: { email: email.trim().toLowerCase(), otp, login_mode: "otp-only" },
      });
      setFormError(null);
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
      if (timerRef.current) clearInterval(timerRef.current);
      toast.success("Welcome to OrderWatch");
      navigate({ to: "/app" });
    } catch (err: unknown) {
      showAuthError(getErrorMessage(err, "Verification failed"));
    } finally {
      setLoading(false);
    }
  }

  function goBackToEmail() {
    if (timerRef.current) clearInterval(timerRef.current);
    setStep("email");
    setOtp("");
    setPassword("");
    setLoginMode("otp-and-password");
  }

  const emailHasKnownError =
    emailValidation.status === "invalid-format" ||
    emailValidation.status === "not-registered" ||
    emailValidation.status === "inactive" ||
    emailValidation.status === "error";

  // Allow password attempt whenever email format looks valid + password present.
  // Do not hard-block on email check so wrong-password / inactive responses always reach the API toast.
  const emailFormatOk = EMAIL_RE.test(email.trim().toLowerCase());
  const canRequestOtp = emailValidation.status === "valid" && !loading;
  const canSignInWithPassword =
    emailFormatOk &&
    password.trim().length > 0 &&
    emailValidation.status !== "checking" &&
    !loading;

  return (
    <div className="relative grid min-h-screen lg:grid-cols-2">
      {/* Brand panel */}
      <div
        className="relative hidden flex-col justify-between overflow-hidden p-10 text-white lg:flex"
        style={{ background: "var(--gradient-brand)" }}
      >
        <div className="flex items-center gap-3">
          <div className="flex h-10 items-center overflow-hidden rounded-lg bg-white/95 px-3 shadow">
            <LogoImage className="h-7 w-auto max-w-[130px] object-contain" />
          </div>
        </div>

        <div className="relative z-10 max-w-md">
          <h1 className="font-mono text-4xl font-semibold leading-tight">
            Every Order.<br />Accounted For.
          </h1>
        </div>

        <div className="flex items-center gap-2 text-xs text-white/60">
          <ShieldCheck className="h-3.5 w-3.5" />
          Restricted access · Kim-Fay employees only
        </div>

        <div className="pointer-events-none absolute -right-32 -top-32 h-96 w-96 rounded-full bg-white/10 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-40 -left-20 h-96 w-96 rounded-full bg-white/5 blur-3xl" />
      </div>

      {/* Auth form — items-start on mobile so the page scrolls from the top */}
      <div className="flex min-h-screen items-start justify-center overflow-y-auto bg-background px-6 py-8 sm:py-12 lg:items-center">
        <div className="w-full max-w-sm">
          {/* Mobile logo — shown on small screens where the brand panel is hidden */}
          <div className="mb-8 flex items-center lg:hidden">
            <div className="flex h-9 items-center overflow-hidden rounded-lg bg-white px-2 shadow-sm border">
              <LogoImage className="h-7 w-auto max-w-[130px] object-contain" />
            </div>
          </div>

          {step === "email" ? (
            <form onSubmit={handleEmailStepSubmit} className="space-y-5">
              <div>
                <h2 className="text-xl font-semibold">Sign in</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                  Sign in with your email and password to access OrderWatch.
                </p>
              </div>

              {/* Mode tabs — password is the primary option */}
              <div className="grid grid-cols-2 gap-2 rounded-lg border bg-muted/40 p-1">
                <button
                  type="button"
                  onClick={() => {
                    setLoginMode("otp-and-password");
                    setFormError(null);
                  }}
                  className={`rounded-md px-3 py-2 text-sm font-medium transition-all ${
                    loginMode === "otp-and-password"
                      ? "bg-background text-foreground shadow-sm"
                      : "text-muted-foreground hover:text-foreground"
                  }`}
                >
                  Password
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setLoginMode("otp-only");
                    setFormError(null);
                  }}
                  className={`rounded-md px-3 py-2 text-sm font-medium transition-all ${
                    loginMode === "otp-only"
                      ? "bg-background text-foreground shadow-sm"
                      : "text-muted-foreground hover:text-foreground"
                  }`}
                >
                  Login via OTP
                </button>
              </div>

              {formError && (
                <div
                  role="alert"
                  className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300"
                >
                  {formError}
                </div>
              )}

              {/* Email field with real-time validation */}
              <div className="space-y-1.5">
                <Label htmlFor="email">Email (Username)</Label>
                <div className="relative">
                  <Input
                    id="email"
                    type="email"
                    autoFocus
                    required
                    value={email}
                    onChange={(e) => handleEmailChange(e.target.value)}
                    placeholder="johndoe@kimfay.com"
                    className={
                      emailValidation.status === "valid"
                        ? "border-green-500 pr-9"
                        : emailHasKnownError
                          ? "border-red-500 pr-9"
                          : emailValidation.status === "checking"
                            ? "pr-9"
                            : ""
                    }
                  />
                  {emailValidation.status === "checking" && (
                    <Loader2 className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 animate-spin text-muted-foreground" />
                  )}
                  {emailValidation.status === "valid" && (
                    <CheckCircle2 className="pointer-events-none absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-green-500" />
                  )}
                </div>
                {emailHasKnownError && (
                  <p className="text-xs text-red-500">
                    {(emailValidation as { message: string }).message}
                  </p>
                )}
              </div>

              {loginMode === "otp-and-password" && (
                <div className="space-y-1.5">
                  <Label htmlFor="password">Password</Label>
                  <div className="relative">
                    <Input
                      id="password"
                      type={showPassword ? "text" : "password"}
                      required
                      value={password}
                      onChange={(e) => {
                        setPassword(e.target.value);
                        if (formError) setFormError(null);
                      }}
                      placeholder="Your account password"
                      className="pr-10"
                      aria-invalid={!!formError}
                    />
                    <button
                      type="button"
                      onClick={() => setShowPassword((v) => !v)}
                      className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground hover:text-foreground"
                      aria-label={showPassword ? "Hide password" : "Show password"}
                    >
                      {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                    </button>
                  </div>
                </div>
              )}

              <Button
                type="submit"
                className="w-full"
                disabled={loginMode === "otp-and-password" ? !canSignInWithPassword : !canRequestOtp}
              >
                {loading
                  ? loginMode === "otp-and-password" ? "Signing in…" : "Sending…"
                  : emailValidation.status === "checking"
                    ? "Checking email…"
                    : loginMode === "otp-and-password"
                      ? <>Sign in <ArrowRight className="ml-1 h-4 w-4" /></>
                      : <>Send verification code <ArrowRight className="ml-1 h-4 w-4" /></>
                }
              </Button>
              <p className="text-center text-[11px] text-muted-foreground">
                {loginMode === "otp-and-password"
                  ? "Use your OrderWatch account password"
                  : "Passwordless · OTP expires in 15 minutes"}
              </p>
            </form>
          ) : (
            <form onSubmit={verifyOtp} className="space-y-6">
              <div>
                <h2 className="text-2xl font-bold tracking-tight">
                  Verify using an available option
                </h2>
                <p className="mt-3 text-sm text-muted-foreground leading-relaxed">
                  Verify using an OTP sent to{" "}
                  <span className="font-medium text-foreground">{maskEmail(email)}</span>{" "}
                  or any other available option to continue.
                </p>
              </div>

              {formError && (
                <div
                  role="alert"
                  className="rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300"
                >
                  {formError}
                </div>
              )}

              {/* OTP input — full-width single row */}
              <InputOTP
                maxLength={6}
                value={otp}
                onChange={(value) => {
                  setOtp(value);
                  if (formError) setFormError(null);
                }}
                autoFocus
                containerClassName="w-full"
              >
                <InputOTPGroup className="w-full">
                  {[0, 1, 2, 3, 4, 5].map((i) => (
                    <InputOTPSlot
                      key={i}
                      index={i}
                      className="flex-1 h-14 text-xl rounded-none first:rounded-l-md last:rounded-r-md border-y border-r first:border-l bg-muted/40"
                    />
                  ))}
                </InputOTPGroup>
              </InputOTP>

              {/* Actions row */}
              <div className="flex items-center justify-between text-sm">
                {secondsLeft > 0 ? (
                  <span className="text-muted-foreground">
                    Resend in{" "}
                    <span className="font-medium text-foreground">{secondsLeft}s</span>
                  </span>
                ) : (
                  <button
                    type="button"
                    className="font-medium text-primary hover:underline disabled:opacity-50"
                    onClick={resendOtp}
                    disabled={resending}
                  >
                    {resending ? "Sending…" : "Resend"}
                  </button>
                )}
              </div>

              <Button
                type="submit"
                className="w-full h-12 text-base font-semibold"
                disabled={loading}
              >
                {loading ? "Verifying…" : "Sign in"}
              </Button>

              <button
                type="button"
                className="block w-full text-center text-xs text-muted-foreground hover:text-foreground"
                onClick={goBackToEmail}
              >
                Use a different email
              </button>
            </form>
          )}

          <div className="mt-10 border-t pt-4 text-[11px] text-muted-foreground">
            By signing in you agree to Kim-Fay's internal systems policy. Activity is logged.
          </div>
        </div>
      </div>
    </div>
  );
}
