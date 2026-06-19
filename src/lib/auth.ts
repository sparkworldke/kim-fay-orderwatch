import { useEffect, useState } from "react";

export type Role =
  | "Administrator"
  | "Customer Service Manager"
  | "Customer Service Agent"
  | "Sales Operations"
  | "Executive";

export interface Session {
  id: number;          // user ID from the API
  email: string;
  name: string;
  role: Role;
  loggedInAt: string;
  token: string;       // Sanctum Bearer token
}

const KEY = "kf_session";
const TOKEN_KEY = "kf_token";

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
}

export function clearToken() {
  window.localStorage.removeItem(TOKEN_KEY);
}

export function inferRole(email: string): Role {
  const prefix = email.split("@")[0]?.toLowerCase() ?? "";
  if (prefix.startsWith("admin")) return "Administrator";
  if (prefix.startsWith("csm") || prefix.startsWith("manager")) return "Customer Service Manager";
  if (prefix.startsWith("agent") || prefix.startsWith("cs")) return "Customer Service Agent";
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
    logout: () => { clearSession(); clearToken(); },
  };
}
