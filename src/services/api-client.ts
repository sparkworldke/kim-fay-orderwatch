/**
 * Thin transport layer. Today it resolves in-memory dummy data; when the
 * Laravel API is ready only this file changes (swap `resolve` for `fetch`).
 */
export const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? "/api";

export const LATENCY_MS = 120;

export function resolve<T>(data: T, latency = LATENCY_MS): Promise<T> {
  return new Promise((res) => setTimeout(() => res(data), latency));
}

/** Future implementation used once the Laravel API is connected. */
export async function request<T>(path: string, init?: RequestInit): Promise<T> {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    headers: { Accept: "application/json", "Content-Type": "application/json" },
    ...init,
  });
  if (!response.ok) throw new Error(`Request failed: ${response.status}`);
  return (await response.json()) as T;
}