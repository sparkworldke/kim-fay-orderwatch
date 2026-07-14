/**
 * Inventory module utility functions.
 *
 * All helpers are pure (no side-effects) so they are straightforward to
 * unit-test and property-test.
 *
 * Requirements: 4.2, 4.3, 4.5, 4.7, 2.9, 2.10
 */

import { addDays, differenceInDays } from "date-fns";

// ─────────────────────────────────────────────────────────────────────────────
// Band derivation
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Derives the A/B/C/D band classification from an Acumatica `ItemClass` value.
 *
 * The `ItemClass` field follows the pattern `<BAND>_<DEPARTMENT>` (e.g.
 * `A_BEVERAGES`, `B_SNACKS`).  The band is extracted by taking the substring
 * before the first underscore (or the entire string if no underscore is
 * present) and matching it case-insensitively against {A, B, C, D}.
 *
 * If `itemClass` is null / empty / has an unrecognised prefix the function
 * returns `"Unclassified"`.
 *
 * @requirements 4.7, 1.3
 */
export function deriveBand(
  itemClass: string | null,
): "A" | "B" | "C" | "D" | "Unclassified" {
  if (!itemClass) return "Unclassified";
  const prefix = itemClass.split("_")[0].toUpperCase();
  if (prefix === "A") return "A";
  if (prefix === "B") return "B";
  if (prefix === "C") return "C";
  if (prefix === "D") return "D";
  return "Unclassified";
}

// ─────────────────────────────────────────────────────────────────────────────
// Prediction period
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Computes the prediction period for a given historical date range.
 *
 * The prediction period starts the day after `to` and has the same length
 * in days as the historical period:
 *
 *   pred.from = addDays(to, 1)
 *   pred.to   = addDays(to, 1 + differenceInDays(to, from))
 *
 * @requirements 2.6
 */
export function predictionPeriod(
  from: Date,
  to: Date,
): { from: Date; to: Date } {
  const lengthDays = differenceInDays(to, from);
  return {
    from: addDays(to, 1),
    to: addDays(to, 1 + lengthDays),
  };
}

// ─────────────────────────────────────────────────────────────────────────────
// Variance indicator
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns the variance indicator colour for a comparison-table row.
 *
 * Rules (predicted must be > 0):
 * - "green"  when `(actual - predicted) / predicted × 100 > 20`
 *            i.e. actual significantly exceeded the prediction
 * - "amber"  when `(predicted - actual) / predicted × 100 > 20`
 *            i.e. actual significantly fell short of the prediction
 * - "none"   otherwise (within ±20 % of prediction)
 *
 * If `predicted` is 0 or negative the function returns "none" to avoid
 * division-by-zero.
 *
 * @requirements 2.9, 2.10
 */
export function varianceIndicator(
  predicted: number,
  actual: number,
): "green" | "amber" | "none" {
  if (predicted <= 0) return "none";
  const overPct = ((actual - predicted) / predicted) * 100;
  const underPct = ((predicted - actual) / predicted) * 100;
  if (overPct > 20) return "green";
  if (underPct > 20) return "amber";
  return "none";
}

// ─────────────────────────────────────────────────────────────────────────────
// Valuation method mapping
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Maps an Acumatica `ValuationMethod.value` string to a human-readable label.
 *
 * Known mappings:
 *   "FIFO"     → "First In First Out"
 *   "Average"  → "Average Cost"
 *   "Standard" → "Standard Cost"
 *
 * Any other value is returned unchanged.  The function never throws and never
 * returns null.
 *
 * @requirements 4.5
 */
export function mapValuationMethod(value: string): string {
  switch (value) {
    case "FIFO":
      return "First In First Out";
    case "Average":
      return "Average Cost";
    case "Standard":
      return "Standard Cost";
    default:
      return value;
  }
}

// ─────────────────────────────────────────────────────────────────────────────
// Last-modified formatter
// ─────────────────────────────────────────────────────────────────────────────

/** East Africa Time offset in minutes (UTC+3). */
const EAT_OFFSET_MINUTES = 3 * 60;

/** Short month names used by formatLastModified. */
const SHORT_MONTHS = [
  "Jan", "Feb", "Mar", "Apr", "May", "Jun",
  "Jul", "Aug", "Sep", "Oct", "Nov", "Dec",
] as const;

/**
 * Formats an ISO 8601 timestamp for display in the EAT timezone (UTC+3).
 *
 * Output format: `DD MMM YYYY HH:mm`  e.g. `15 Jan 2024 14:30`
 *
 * Returns `"—"` when:
 * - `iso` is null or undefined
 * - `iso` cannot be parsed as a valid date
 *
 * The optional `tz` parameter is accepted for API compatibility but the
 * implementation always uses UTC+3 (EAT / Africa/Nairobi) as required by the
 * spec.  If you pass a numeric UTC-offset string like `"+05:30"` it will be
 * ignored in favour of UTC+3.
 *
 * @requirements 4.3
 */
export function formatLastModified(
  iso: string | null | undefined,
  // eslint-disable-next-line @typescript-eslint/no-unused-vars
  _tz?: string,
): string {
  if (iso == null || iso === "") return "—";

  const date = new Date(iso);
  if (isNaN(date.getTime())) return "—";

  // Shift the UTC timestamp to EAT (UTC+3).
  const eatMs = date.getTime() + EAT_OFFSET_MINUTES * 60 * 1000;
  const d = new Date(eatMs);

  const day = String(d.getUTCDate()).padStart(2, "0");
  const month = SHORT_MONTHS[d.getUTCMonth()];
  const year = d.getUTCFullYear();
  const hours = String(d.getUTCHours()).padStart(2, "0");
  const minutes = String(d.getUTCMinutes()).padStart(2, "0");

  return `${day} ${month} ${year} ${hours}:${minutes}`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Cost formatter
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Formats a cost value with the "KES " prefix, 2 decimal places, and comma
 * thousands separators.
 *
 * Examples:
 *   1234.5   → "KES 1,234.50"
 *   0        → "KES 0.00"
 *   -50      → "KES -50.00"
 *   null     → "—"
 *   undefined → "—"
 *
 * @requirements 4.2
 */
export function formatCost(value: number | null | undefined): string {
  if (value == null) return "—";
  // toLocaleString with en-US gives comma thousands + 2 dp reliably across
  // all JS environments.
  const formatted = value.toLocaleString("en-US", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
  return `KES ${formatted}`;
}

// ─────────────────────────────────────────────────────────────────────────────
// Brand / Sub Trading Group display formatter
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Formats a two-line display for Brand and Sub Trading Group.
 *
 * Line 1: Brand name (bold)
 * Line 2: "- [Sub Trading Group]" (muted)
 *
 * If Brand is null/empty, only the Sub Trading Group line is shown (or "—"
 * if both are missing).
 *
 * @returns An object with `brandLine` and `subGroupLine` for rendering.
 */
export function formatBrandDisplay(
  brand: string | null | undefined,
  subTradingGroup: string | null | undefined,
): { brandLine: string | null; subGroupLine: string | null } {
  const brandLine = brand && brand.trim() !== "" ? brand.trim() : null;
  const subGroupLine =
    subTradingGroup && subTradingGroup.trim() !== ""
      ? `- ${subTradingGroup.trim()}`
      : null;

  return { brandLine, subGroupLine };
}
