const DEFAULT_API_BASE = "https://kim-fay-orderwatch.tools/backend/public/api";

/** API base for server-side (SSR) requests — may use HTTP to avoid TLS issues in local dev. */
export function getServerApiBaseUrl(): string {
  const ssr =
    (import.meta.env.VITE_API_BASE_URL_SSR as string | undefined) ??
    (process.env.VITE_API_BASE_URL_SSR as string | undefined);
  const primary =
    (import.meta.env.VITE_API_BASE_URL as string | undefined) ??
    (process.env.VITE_API_BASE_URL as string | undefined);

  return (ssr || primary || DEFAULT_API_BASE).replace(/\/+$/, "");
}

function allowInsecureTls(): boolean {
  if (import.meta.env.PROD) return false;
  const flag = import.meta.env.VITE_ALLOW_SELF_SIGNED_CERT as string | undefined;
  return flag !== "false" && flag !== "0";
}

let insecureDispatcher: import("undici").Dispatcher | undefined;

async function insecureTlsFetch(url: string, init?: RequestInit): Promise<Response> {
  const undici = await import("undici");
  if (!insecureDispatcher) {
    insecureDispatcher = new undici.Agent({
      connect: { rejectUnauthorized: false },
    });
  }
  return undici.fetch(
    url,
    {
      ...init,
      dispatcher: insecureDispatcher,
    } as import("undici").RequestInit,
  ) as unknown as Response;
}

/**
 * Server-only fetch for calling the Laravel API during SSR.
 * Uses VITE_API_BASE_URL_SSR when set (recommended: http:// for Laragon local).
 * In dev, HTTPS self-signed certs are accepted when VITE_ALLOW_SELF_SIGNED_CERT is not false.
 */
export async function serverFetch(url: string, init?: RequestInit): Promise<Response> {
  const parsed = new URL(url);
  const useInsecureTls = import.meta.env.DEV && parsed.protocol === "https:" && allowInsecureTls();

  if (useInsecureTls) {
    return insecureTlsFetch(url, init);
  }

  return fetch(url, init);
}