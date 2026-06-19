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

export async function apiFetch<T = unknown>(
  path: string,
  { body, token, headers, ...rest }: RequestOptions = {},
): Promise<T> {
  const url = `${API_BASE_URL}/${path.replace(/^\/+/, "")}`;

  // Auto-attach stored token when caller does not supply one explicitly
  const resolvedToken =
    token !== undefined
      ? token
      : (typeof window !== "undefined" ? window.localStorage.getItem("kf_token") : null);

  const finalHeaders: Record<string, string> = {
    Accept: "application/json",
    ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
    ...(resolvedToken ? { Authorization: `Bearer ${resolvedToken}` } : {}),
    ...(headers as Record<string, string> | undefined),
  };

  const res = await fetch(url, {
    ...rest,
    headers: finalHeaders,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  // Global 401 handler — clear stale session + token, notify listeners, and bail
  if (res.status === 401 && typeof window !== "undefined") {
    window.localStorage.removeItem("kf_session");
    window.localStorage.removeItem("kf_token");
    window.dispatchEvent(new Event("kf_session_change"));
    throw new ApiError("Unauthorized", 401, null);
  }

  const text = await res.text();
  const data = text ? safeJson(text) : null;

  if (!res.ok) {
    const message =
      (data && typeof data === "object" && "message" in data
        ? String((data as { message: unknown }).message)
        : res.statusText) || `Request failed (${res.status})`;
    throw new ApiError(message, res.status, data);
  }

  return data as T;
}

function safeJson(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
