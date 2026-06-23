import { createStart, createMiddleware } from "@tanstack/react-start";

import { renderErrorPage } from "./lib/error-page";

const errorMiddleware = createMiddleware().server(async ({ next }) => {
  try {
    return await next();
  } catch (error) {
    if (error != null && typeof error === "object" && "statusCode" in error) {
      throw error;
    }
    console.error(error);
    return new Response(renderErrorPage(), {
      status: 500,
      headers: { "content-type": "text/html; charset=utf-8" },
    });
  }
});

const authMiddleware = createMiddleware().server(async ({ request, next }) => {
  const url = new URL(request.url);
  if (request.method !== "GET" || !/^\/app(?:\/|$)/.test(url.pathname)) {
    return next();
  }

  const token = readCookie(request.headers.get("cookie"), "kf_token");
  if (!token) {
    return Response.redirect(new URL("/auth", url), 302);
  }

  const apiBase = (
    (import.meta.env.VITE_API_BASE_URL as string | undefined) ??
    "https://kim-fay-orderwatch.tools/backend/public/api"
  ).replace(/\/+$/, "");

  try {
    const response = await fetch(`${apiBase}/auth/me`, {
      headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      signal: AbortSignal.timeout(8_000),
    });

    if (response.status === 401 || response.status === 403) {
      return new Response(null, {
        status: 302,
        headers: {
          Location: new URL("/auth", url).toString(),
          "Set-Cookie": "kf_token=; Path=/; Max-Age=0; SameSite=Lax",
        },
      });
    }

    if (!response.ok) {
      return new Response("Authentication service is temporarily unavailable.", {
        status: 503,
        headers: { "content-type": "text/plain; charset=utf-8", "cache-control": "no-store" },
      });
    }
  } catch (error) {
    console.error("Session validation failed", error);
    return new Response("Authentication service is temporarily unavailable.", {
      status: 503,
      headers: { "content-type": "text/plain; charset=utf-8", "cache-control": "no-store" },
    });
  }

  return next();
});

function readCookie(header: string | null, name: string): string | null {
  if (!header) return null;
  const prefix = `${name}=`;
  const value = header
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));
  if (!value) return null;
  try {
    return decodeURIComponent(value.slice(prefix.length));
  } catch {
    return null;
  }
}

export const startInstance = createStart(() => ({
  requestMiddleware: [errorMiddleware, authMiddleware],
}));
