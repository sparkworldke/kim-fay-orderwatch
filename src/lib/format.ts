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

/** Parse ISO / date-only into a valid Date, or null. */
function asDate(value: Date | string | null | undefined): Date | null {
  if (!value) return null;
  if (value instanceof Date) {
    return Number.isNaN(value.getTime()) ? null : value;
  }
  const raw = String(value).trim();
  if (!raw) return null;
  // Date-only Y-m-d → local-safe parse
  const dateOnly = /^(\d{4})-(\d{2})-(\d{2})$/.exec(raw);
  if (dateOnly) {
    const d = new Date(
      Number(dateOnly[1]),
      Number(dateOnly[2]) - 1,
      Number(dateOnly[3]),
      12,
      0,
      0,
    );
    return Number.isNaN(d.getTime()) ? null : d;
  }
  const d = new Date(raw);
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Compact day-month-year for sync labels, e.g. "1-07-2026", "22-07-2026".
 */
export function formatSyncDay(value: Date | string | null | undefined): string | null {
  const d = asDate(value);
  if (!d) return null;
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: EAT,
    day: "numeric",
    month: "2-digit",
    year: "numeric",
  }).formatToParts(d);
  const day = parts.find((p) => p.type === "day")?.value;
  const month = parts.find((p) => p.type === "month")?.value;
  const year = parts.find((p) => p.type === "year")?.value;
  if (!day || !month || !year) return null;
  return `${Number(day)}-${month}-${year}`;
}

/**
 * Compact day + time for run windows, e.g. "22-07-2026 8am", "22-07-2026 2:30pm".
 */
export function formatSyncDayTime(value: Date | string | null | undefined): string | null {
  const d = asDate(value);
  if (!d) return null;
  const day = formatSyncDay(d);
  if (!day) return null;

  const hourParts = new Intl.DateTimeFormat("en-US", {
    timeZone: EAT,
    hour: "numeric",
    minute: "2-digit",
    hour12: true,
  }).formatToParts(d);

  const hour = hourParts.find((p) => p.type === "hour")?.value;
  const minute = hourParts.find((p) => p.type === "minute")?.value;
  const dayPeriod = hourParts.find((p) => p.type === "dayPeriod")?.value?.toLowerCase() ?? "";
  if (!hour) return day;

  const mins = minute && minute !== "00" ? `:${minute}` : "";
  return `${day} ${hour}${mins}${dayPeriod}`;
}

export type SyncLogLabelInput = {
  sync_type: string;
  started_at?: string | null;
  ended_at?: string | null;
  status?: string | null;
  filters?: Record<string, unknown> | null;
};

/**
 * Sync log display name with date/time range, e.g.:
 * - "sales_orders — 1-07-2026 to 6-07-2026"
 * - "inventory_stocks (EXPORT) — 22-07-2026 8am to 22-07-2026 11am"
 */
export function formatSyncLogName(log: SyncLogLabelInput): string {
  const filters = log.filters ?? {};
  const extras: string[] = [];

  const warehouse = filters.warehouse_id;
  if (typeof warehouse === "string" && warehouse.trim() !== "") {
    extras.push(warehouse.trim());
  }

  const mode = filters.mode;
  if (typeof mode === "string" && mode.trim() !== "" && mode !== "stocks_only") {
    extras.push(mode.trim());
  }

  const base =
    extras.length > 0 ? `${log.sync_type} (${extras.join(", ")})` : log.sync_type;

  const range = formatSyncLogRange(log);
  return range ? `${base} — ${range}` : base;
}

function formatSyncLogRange(log: SyncLogLabelInput): string | null {
  const filters = log.filters ?? {};

  const dateFrom = filters.date_from;
  const dateTo = filters.date_to;
  if (
    (typeof dateFrom === "string" && dateFrom) ||
    (typeof dateTo === "string" && dateTo)
  ) {
    const fromLabel = formatSyncDay(typeof dateFrom === "string" ? dateFrom : null);
    const toLabel = formatSyncDay(typeof dateTo === "string" ? dateTo : null);
    if (fromLabel && toLabel) {
      return fromLabel === toLabel ? fromLabel : `${fromLabel} to ${toLabel}`;
    }
    if (fromLabel) return `from ${fromLabel}`;
    if (toLabel) return `to ${toLabel}`;
  }

  // lookback_days (status updates / prune): derive range ending at run start
  const lookback = Number(filters.lookback_days);
  if (Number.isFinite(lookback) && lookback > 0 && log.started_at) {
    const end = asDate(log.started_at);
    if (end) {
      const start = new Date(end.getTime());
      start.setDate(start.getDate() - Math.floor(lookback));
      const fromLabel = formatSyncDay(start);
      const toLabel = formatSyncDay(end);
      if (fromLabel && toLabel) {
        return `${fromLabel} to ${toLabel}`;
      }
    }
  }

  // order_nbrs resync: list a few ids
  const orderNbrs = filters.order_nbrs;
  if (Array.isArray(orderNbrs) && orderNbrs.length > 0) {
    const sample = orderNbrs
      .filter((n): n is string => typeof n === "string" && n.trim() !== "")
      .slice(0, 3);
    if (sample.length > 0) {
      const more = orderNbrs.length > sample.length ? ` +${orderNbrs.length - sample.length}` : "";
      return `${sample.join(", ")}${more}`;
    }
  }

  // Fall back to actual run window (started → ended) with times
  if (log.started_at) {
    const fromLabel = formatSyncDayTime(log.started_at);
    if (!fromLabel) return null;

    if (log.ended_at) {
      const toLabel = formatSyncDayTime(log.ended_at);
      if (toLabel) {
        return fromLabel === toLabel ? fromLabel : `${fromLabel} to ${toLabel}`;
      }
    }

    if (log.status === "running") {
      return `${fromLabel} to now`;
    }

    return fromLabel;
  }

  return null;
}
