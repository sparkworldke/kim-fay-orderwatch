export function formatKES(n: number, opts: { compact?: boolean } = {}) {
  if (opts.compact) {
    return new Intl.NumberFormat("en-KE", {
      style: "currency",
      currency: "KES",
      notation: "compact",
      maximumFractionDigits: 1,
    }).format(n);
  }
  return new Intl.NumberFormat("en-KE", {
    style: "currency",
    currency: "KES",
    maximumFractionDigits: 0,
  }).format(n);
}

export function formatNumber(n: number) {
  return new Intl.NumberFormat("en-KE").format(n);
}

export function formatPercent(n: number, digits = 1) {
  return `${n.toFixed(digits)}%`;
}

export const EAT = "Africa/Nairobi";

export function formatDateTime(d: Date | string) {
  const date = typeof d === "string" ? new Date(d) : d;
  return date.toLocaleString("en-KE", {
    timeZone: EAT,
    month: "short",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
}

/**
 * Formats a date string as "MMM dd, yyyy" (e.g. "Jul 08, 2026").
 * Returns null for null/undefined/empty input.
 */
export function formatDate(d: Date | string | null | undefined): string | null {
  if (!d) return null;
  const date = typeof d === "string" ? new Date(d) : d;
  if (isNaN(date.getTime())) return null;
  return date.toLocaleDateString("en-KE", {
    timeZone: EAT,
    year: "numeric",
    month: "short",
    day: "2-digit",
  });
}

/**
 * Extracts the ISO date portion (yyyy-mm-dd) from a datetime string.
 * Returns null for null/undefined/empty input.
 */
export function toIsoDate(d: Date | string | null | undefined): string | null {
  if (!d) return null;
  const date = typeof d === "string" ? new Date(d) : d;
  if (isNaN(date.getTime())) return null;
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function formatRelative(d: Date | string) {
  const date = typeof d === "string" ? new Date(d) : d;
  const diff = Date.now() - date.getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1) return "just now";
  if (mins < 60) return `${mins}m ago`;
  const hrs = Math.floor(mins / 60);
  if (hrs < 24) return `${hrs}h ago`;
  const days = Math.floor(hrs / 24);
  return `${days}d ago`;
}

export function formatDuration(
  from: Date | string | null | undefined,
  to: Date | string | null | undefined,
): string {
  if (!from || !to) return "—";
  const a = typeof from === "string" ? new Date(from) : from;
  const b = typeof to === "string" ? new Date(to) : to;
  let mins = Math.max(0, Math.round((b.getTime() - a.getTime()) / 60000));
  if (mins < 1) return "< 1 min";
  const days = Math.floor(mins / 1440);
  mins -= days * 1440;
  const hrs = Math.floor(mins / 60);
  mins -= hrs * 60;
  const parts: string[] = [];
  if (days) parts.push(`${days}d`);
  if (hrs) parts.push(`${hrs}h`);
  if (mins && !days) parts.push(`${mins}m`);
  return parts.join(" ") || "0m";
}
