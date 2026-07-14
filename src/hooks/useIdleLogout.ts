import { useEffect, useRef } from "react";
import { apiFetch } from "@/lib/api";
import { clearSession, getToken } from "@/lib/auth";
import { useCapabilities } from "@/hooks/useCapabilities";

const ACTIVITY_EVENTS = ["mousedown", "keydown", "scroll", "touchstart"] as const;

export function useIdleLogout() {
  const { idleTimeoutMinutes } = useCapabilities();
  const timerRef = useRef<number | null>(null);

  useEffect(() => {
    const token = getToken();
    if (!token || idleTimeoutMinutes <= 0) {
      return;
    }

    const timeoutMs = idleTimeoutMinutes * 60 * 1000;
    const warnMs = Math.max(timeoutMs - 5 * 60 * 1000, timeoutMs * 0.8);

    const logout = async (reason: "idle" | "manual") => {
      try {
        if (reason === "idle") {
          await apiFetch("auth/logout", { method: "POST" });
        }
      } catch {
        // ignore network errors during idle logout
      } finally {
        clearSession();
        window.location.href = "/auth";
      }
    };

    const reset = () => {
      if (timerRef.current) {
        window.clearTimeout(timerRef.current);
      }
      timerRef.current = window.setTimeout(() => logout("idle"), timeoutMs);
    };

    const onActivity = () => reset();
    ACTIVITY_EVENTS.forEach((event) => window.addEventListener(event, onActivity, { passive: true }));
    reset();

    return () => {
      if (timerRef.current) {
        window.clearTimeout(timerRef.current);
      }
      ACTIVITY_EVENTS.forEach((event) => window.removeEventListener(event, onActivity));
    };
  }, [idleTimeoutMinutes]);
}