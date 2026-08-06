/**
 * Laravel API origin used by the Cloudflare Worker /api proxy.
 *
 * Set at build time via Vite env (see .env.production / .env.staging):
 *   VITE_API_UPSTREAM  — preferred (full URL ending in /api)
 *   VITE_API_BASE_URL_SSR — fallback (same shape; used for SSR auth too)
 */
function resolveApiUpstream(): string {
  const fromEnv =
    (import.meta.env.VITE_API_UPSTREAM as string | undefined) ||
    (import.meta.env.VITE_API_BASE_URL_SSR as string | undefined) ||
    (typeof process !== "undefined"
      ? (process.env.VITE_API_UPSTREAM as string | undefined) ||
        (process.env.VITE_API_BASE_URL_SSR as string | undefined)
      : undefined);

  // Default production Laravel API host (intended).
  const raw = (fromEnv || "https://datacontroller.fayshop.co.ke/backend/public/api").trim();
  return raw.replace(/\/+$/, "");
}

export const API_UPSTREAM = resolveApiUpstream();
