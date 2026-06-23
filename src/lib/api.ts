// Centralized API client for the Laravel backend.
// Base URL is configured via VITE_API_BASE_URL in .env

export const API_BASE_URL: string =
  (import.meta.env.VITE_API_BASE_URL as string | undefined)?.replace(/\/+$/, "") ??
  "https://kim-fay-orderwatch.tools/backend/public/api";

type RequestOptions = Omit<RequestInit, "body"> & {
  body?: unknown;
  token?: string | null;
};

export class ApiError extends Error {
  status: number;
  data: unknown;
  constructor(message: string, status: number, data: unknown) {
    super(message);
    this.status = status;
    this.data = data;
  }
}

// ─── Structured error logger ──────────────────────────────────────────────────

const ERROR_LOG_KEY = "kf_api_errors";
const MAX_LOG_ENTRIES = 200;

interface ApiErrorEntry {
  ts: string;          // ISO timestamp
  url: string;
  method: string;
  status: number | null; // null = network failure (no response)
  message: string;
  userAgent: string;
}

function persistErrorLog(entry: ApiErrorEntry): void {
  if (typeof window === "undefined") return;
  try {
    const raw = localStorage.getItem(ERROR_LOG_KEY);
    const log: ApiErrorEntry[] = raw ? (JSON.parse(raw) as ApiErrorEntry[]) : [];
    log.push(entry);
    // Keep only the most recent entries to avoid unbounded storage growth
    if (log.length > MAX_LOG_ENTRIES) log.splice(0, log.length - MAX_LOG_ENTRIES);
    localStorage.setItem(ERROR_LOG_KEY, JSON.stringify(log));
  } catch {
    // localStorage unavailable or full — silently skip
  }
}

/** Returns all stored API error entries for debugging. */
export function getApiErrorLog(): ApiErrorEntry[] {
  if (typeof window === "undefined") return [];
  try {
    const raw = localStorage.getItem(ERROR_LOG_KEY);
    return raw ? (JSON.parse(raw) as ApiErrorEntry[]) : [];
  } catch {
    return [];
  }
}

/** Returns a summary of errors grouped by endpoint + status code. */
export function getApiErrorSummary(): Record<string, number> {
  return getApiErrorLog().reduce<Record<string, number>>((acc, e) => {
    const key = `${e.method} ${new URL(e.url).pathname} → HTTP ${e.status ?? "network"}`;
    acc[key] = (acc[key] ?? 0) + 1;
    return acc;
  }, {});
}

// ─────────────────────────────────────────────────────────────────────────────

export async function apiFetch<T = unknown>(
  path: string,
  { body, token, headers, ...rest }: RequestOptions = {},
): Promise<T> {
  const url = `${API_BASE_URL}/${path.replace(/^\/+/, "")}`;
  const method = (rest.method ?? "GET").toUpperCase();

  // Auto-attach stored token when caller does not supply one explicitly
  const resolvedToken =
    token !== undefined
      ? token
      : typeof window !== "undefined"
        ? window.localStorage.getItem("kf_token")
        : null;

  const finalHeaders: Record<string, string> = {
    Accept: "application/json",
    ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
    ...(resolvedToken ? { Authorization: `Bearer ${resolvedToken}` } : {}),
    ...(headers as Record<string, string> | undefined),
  };

  let res: Response;
  try {
    res = await fetch(url, {
      ...rest,
      headers: finalHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch (networkErr) {
    // Network failure — no HTTP response at all (DNS failure, timeout, CORS preflight blocked, etc.)
    const entry: ApiErrorEntry = {
      ts: new Date().toISOString(),
      url,
      method,
      status: null,
      message: networkErr instanceof Error ? networkErr.message : String(networkErr),
      userAgent: typeof navigator !== "undefined" ? navigator.userAgent : "",
    };
    persistErrorLog(entry);
    console.error("[api] Network error", entry);
    throw networkErr;
  }

  // Global 401 handler — clear stale session + token, notify listeners, and bail
  if (res.status === 401 && typeof window !== "undefined") {
    const { clearSession } = await import("./auth");
    clearSession();
    throw new ApiError("Unauthorized", 401, null);
  }

  const text = await res.text();
  const data = text ? safeJson(text) : null;

  if (!res.ok) {
    const message = extractErrorMessage(data, res.status, res.statusText);

    const entry: ApiErrorEntry = {
      ts: new Date().toISOString(),
      url,
      method,
      status: res.status,
      message,
      userAgent: typeof navigator !== "undefined" ? navigator.userAgent : "",
    };
    persistErrorLog(entry);

    if (res.status >= 500) {
      console.error("[api] Server error", entry);
    }

    throw new ApiError(message, res.status, data);
  }

  return data as T;
}

function extractErrorMessage(data: unknown, status: number, statusText: string): string {
  if (data && typeof data === "object") {
    if ("message" in data && (data as { message?: unknown }).message) {
      return String((data as { message: unknown }).message);
    }

    if ("error" in data && (data as { error?: unknown }).error) {
      return String((data as { error: unknown }).error);
    }
  }

  if (typeof data === "string" && data.trim() && !data.trim().startsWith("<")) {
    return data.trim();
  }

  return statusText || `The request failed with HTTP ${status}.`;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
