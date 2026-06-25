const TZ = "Africa/Nairobi";
const BUSINESS_START_HOUR = 8;
const BUSINESS_START_MINUTE = 15;
/** Kenya (Africa/Nairobi) is UTC+3 year-round. */
const NAIROBI_UTC_OFFSET_HOURS = 3;

function nairobiParts(value: string): { y: number; m: number; d: number; h: number; min: number } | null {
  const date = new Date(value);
  if (isNaN(date.getTime())) return null;

  const fmt = new Intl.DateTimeFormat("en-GB", {
    timeZone: TZ,
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  });
  const parts = Object.fromEntries(fmt.formatToParts(date).map((p) => [p.type, p.value]));
  const y = Number(parts.year);
  const m = Number(parts.month);
  const d = Number(parts.day);
  const h = Number(parts.hour);
  const min = Number(parts.minute);
  if ([y, m, d, h, min].some((n) => isNaN(n))) return null;

  return { y, m, d, h, min };
}

/** Clamp timestamps before 08:15 Nairobi to 08:15 that day. Date-only values use 08:15. */
export function clampToBusinessStart(value: string): number | null {
  const parts = nairobiParts(value);
  if (!parts) return null;

  const hasTime = value.includes("T") || value.includes(" ");
  let { h, min } = parts;
  if (!hasTime) {
    h = BUSINESS_START_HOUR;
    min = BUSINESS_START_MINUTE;
  } else if (h < BUSINESS_START_HOUR || (h === BUSINESS_START_HOUR && min < BUSINESS_START_MINUTE)) {
    h = BUSINESS_START_HOUR;
    min = BUSINESS_START_MINUTE;
  }

  return Date.UTC(parts.y, parts.m - 1, parts.d, h - NAIROBI_UTC_OFFSET_HOURS, min, 0);
}

export function formatPoLoadDuration(
  emailAt: string | null | undefined,
  soAt: string | null | undefined,
): string | null {
  if (!emailAt || !soAt) return null;

  const startMs = clampToBusinessStart(emailAt);
  const endMs = clampToBusinessStart(soAt);
  if (startMs === null || endMs === null || endMs <= startMs) return null;

  const diffMs = endMs - startMs;
  const totalMins = Math.floor(diffMs / 60_000);
  const days = Math.floor(totalMins / 1440);
  const hours = Math.floor((totalMins % 1440) / 60);
  const mins = totalMins % 60;

  if (days > 0) return `${days}d ${hours}h`;
  if (hours > 0) return `${hours}h ${mins}m`;
  return `${mins}m`;
}