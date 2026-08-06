import "./lib/error-capture";

import { API_UPSTREAM } from "./lib/api-upstream";
import { consumeLastCapturedError } from "./lib/error-capture";
import { renderErrorPage } from "./lib/error-page";

type ServerEntry = {
  fetch: (request: Request, env: unknown, ctx: unknown) => Promise<Response> | Response;
};

let serverEntryPromise: Promise<ServerEntry> | undefined;

async function getServerEntry(): Promise<ServerEntry> {
  if (!serverEntryPromise) {
    serverEntryPromise = import("@tanstack/react-start/server-entry").then(
      (m) => (m.default ?? m) as ServerEntry,
    );
  }
  return serverEntryPromise;
}

// h3 swallows in-handler throws into a normal 500 Response with body
// {"unhandled":true,"message":"HTTPError"} — try/catch alone never fires for those.
async function normalizeCatastrophicSsrResponse(response: Response): Promise<Response> {
  if (response.status < 500) return response;
  const contentType = response.headers.get("content-type") ?? "";
  if (!contentType.includes("application/json")) return response;

  const body = await response.clone().text();
  if (!body.includes('"unhandled":true') || !body.includes('"message":"HTTPError"')) {
    return response;
  }

  console.error(consumeLastCapturedError() ?? new Error(`h3 swallowed SSR error: ${body}`));
  return new Response(renderErrorPage(), {
    status: 500,
    headers: { "content-type": "text/html; charset=utf-8" },
  });
}

async function proxyApiRequest(request: Request): Promise<Response> {
  const url = new URL(request.url);
  const suffix = url.pathname.replace(/^\/api\/?/, "");
  const target = new URL(`${API_UPSTREAM.replace(/\/+$/, "")}/${suffix}`);
  target.search = url.search;

  const headers = new Headers(request.headers);
  headers.delete("host");
  // Cloudflare / origin may reject mismatched host; never forward CF client headers that break fetch
  headers.delete("cf-connecting-ip");
  headers.delete("cf-ipcountry");
  headers.delete("cf-ray");
  headers.delete("cf-visitor");
  headers.delete("cdn-loop");

  const init: RequestInit = {
    method: request.method,
    headers,
    // Manual: avoid redirect loops on misconfigured API hosts (CF error 1101).
    redirect: "manual",
  };
  if (request.method !== "GET" && request.method !== "HEAD") {
    init.body = request.body;
  }

  try {
    const upstream = await fetch(target.toString(), init);

    // Surface origin misconfig instead of opaque Worker 1101.
    if (upstream.status >= 300 && upstream.status < 400) {
      const location = upstream.headers.get("location") ?? "(none)";
      console.error("API proxy unexpected redirect", {
        target: target.toString(),
        status: upstream.status,
        location,
      });
      return new Response(
        JSON.stringify({
          message: "API origin returned a redirect instead of a response. Check API URL / SSL config.",
          upstream_status: upstream.status,
          upstream_location: location,
          upstream: target.toString(),
        }),
        {
          status: 502,
          headers: { "content-type": "application/json; charset=utf-8" },
        },
      );
    }

    return upstream;
  } catch (error) {
    console.error("API proxy failed", { target: target.toString(), error });
    return new Response(
      JSON.stringify({
        message: "API origin unreachable from Worker.",
        upstream: target.toString(),
      }),
      {
        status: 502,
        headers: { "content-type": "application/json; charset=utf-8" },
      },
    );
  }
}

/** Legacy hostname → permanent redirect to Kim-Fay Sight. */
const LEGACY_HOSTS = new Set(["orderwatch.fayshop.co.ke", "www.orderwatch.fayshop.co.ke"]);
const CANONICAL_ORIGIN = "https://sight.fayshop.co.ke";

function redirectLegacyHost(request: Request): Response | null {
  const url = new URL(request.url);
  const host = url.hostname.toLowerCase();
  if (!LEGACY_HOSTS.has(host)) return null;

  const target = new URL(url.pathname + url.search + url.hash, CANONICAL_ORIGIN);
  return Response.redirect(target.toString(), 301);
}

export default {
  async fetch(request: Request, env: unknown, ctx: unknown) {
    const legacyRedirect = redirectLegacyHost(request);
    if (legacyRedirect) return legacyRedirect;

    const { pathname } = new URL(request.url);
    if (pathname === "/api" || pathname.startsWith("/api/")) {
      return proxyApiRequest(request);
    }

    try {
      const handler = await getServerEntry();
      const response = await handler.fetch(request, env, ctx);
      return await normalizeCatastrophicSsrResponse(response);
    } catch (error) {
      console.error(error);
      return new Response(renderErrorPage(), {
        status: 500,
        headers: { "content-type": "text/html; charset=utf-8" },
      });
    }
  },
};
