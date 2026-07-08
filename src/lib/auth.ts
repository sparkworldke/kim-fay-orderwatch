import { useEffect, useState } from "react";

export type Role =
  | "Administrator"
  | "Customer Service Manager"
  | "Customer Service Agent"
  | "Sales Operations"
  | "Sales Consultant"
  | "Executive";

export interface Session {
  id: number; // user ID from the API
  email: string;
  name: string;
  role: Role;
  rep_code?: string | null;
  loggedInAt: string;
  token: string; // Sanctum Bearer token
}

const KEY = "kf_session";
const TOKEN_KEY = "kf_token";
const TOKEN_COOKIE = "kf_token";
const TOKEN_MAX_AGE_SECONDS = 60 * 60 * 24 * 7;

function setTokenCookie(token: string) {
  if (typeof document === "undefined") return;
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${TOKEN_COOKIE}=${encodeURIComponent(token)}; Path=/; Max-Age=${TOKEN_MAX_AGE_SECONDS}; SameSite=Lax${secure}`;
}

function clearTokenCookie() {
  if (typeof document === "undefined") return;
  const secure = window.location.protocol === "https:" ? "; Secure" : "";
  document.cookie = `${TOKEN_COOKIE}=; Path=/; Max-Age=0; SameSite=Lax${secure}`;
}

export function getSession(): Session | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = window.localStorage.getItem(KEY);
    return raw ? (JSON.parse(raw) as Session) : null;
  } catch {
    return null;
  }
}

export function setSession(s: Session) {
  window.localStorage.setItem(KEY, JSON.stringify(s));
  window.dispatchEvent(new Event("kf_session_change"));
}

export function clearSession() {
  window.localStorage.removeItem(KEY);
  clearToken();
  window.dispatchEvent(new Event("kf_session_change"));
}

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return window.localStorage.getItem(TOKEN_KEY);
}

export function setToken(t: string) {
  window.localStorage.setItem(TOKEN_KEY, t);
  // TanStack Start's request middleware cannot read localStorage. Mirroring the
  // bearer token into a same-site cookie lets it reject protected document
  // requests before any application HTML is rendered.
  setTokenCookie(t);
}

export function clearToken() {
  window.localStorage.removeItem(TOKEN_KEY);
  clearTokenCookie();
}

export function inferRole(email: string): Role {
  const prefix = email.split("@")[0]?.toLowerCase() ?? "";
  if (prefix.startsWith("admin")) return "Administrator";
  if (prefix.startsWith("csm") || prefix.startsWith("manager")) return "Customer Service Manager";
  if (prefix.startsWith("agent") || prefix.startsWith("cs")) return "Customer Service Agent";
  if (prefix.startsWith("consultant") || prefix.startsWith("rep")) return "Sales Consultant";
  if (prefix.startsWith("ops") || prefix.startsWith("sales")) return "Sales Operations";
  if (prefix.startsWith("exec") || prefix.startsWith("ceo")) return "Executive";
  return "Administrator";
}

export function nameFromEmail(email: string) {
  const local = email.split("@")[0] ?? "user";
  return local
    .split(/[._-]/)
    .filter(Boolean)
    .map((p) => p.charAt(0).toUpperCase() + p.slice(1))
    .join(" ");
}

export function useAuth() {
  const [session, setSessionState] = useState<Session | null>(() => getSession());
  useEffect(() => {
    const update = () => setSessionState(getSession());
    window.addEventListener("kf_session_change", update);
    window.addEventListener("storage", update);
    return () => {
      window.removeEventListener("kf_session_change", update);
      window.removeEventListener("storage", update);
    };
  }, []);
  return {
    session,
    isAuthenticated: !!session,
    token: getToken(),
    logout: () => {
      clearSession();
      clearToken();
    },
  };
}
