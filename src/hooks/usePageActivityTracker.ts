import { useEffect, useRef } from "react";
import { useRouterState } from "@tanstack/react-router";
import { apiFetch } from "@/lib/api";

/**
 * Records authenticated page navigations for admin Login + Activity export.
 * Debounced / deduped server-side; client also skips identical path spam.
 */
export function usePageActivityTracker(enabled = true) {
  const pathname = useRouterState({ select: (r) => r.location.pathname });
  const search = useRouterState({ select: (r) => r.location.searchStr ?? "" });
  const lastSent = useRef<string>("");

  useEffect(() => {
    if (!enabled || typeof window === "undefined") return;
    if (!pathname.startsWith("/app")) return;

    const path = `${pathname}${search || ""}`;
    if (path === lastSent.current) return;
    lastSent.current = path;

    const title =
      (typeof document !== "undefined" && document.title
        ? document.title.replace(/\s*[—|-]\s*Kim-Fay Sight.*/i, "").trim()
        : "") || pathname;

    // Fire-and-forget; never block navigation.
    void apiFetch("activity/page-view", {
      method: "POST",
      body: {
        activity_type: "page_view",
        path,
        page_title: title.slice(0, 255),
      },
    }).catch(() => {
      // Silent: tracking must not surface errors to the user.
    });
  }, [enabled, pathname, search]);
}
