export const MONTH_LABELS = [
  "Jan",
  "Feb",
  "Mar",
  "Apr",
  "May",
  "Jun",
  "Jul",
  "Aug",
  "Sep",
  "Oct",
  "Nov",
  "Dec",
];

/** Fixed "today" for the dummy dataset. */
export const CURRENT_YEAR = 2026;
export const CURRENT_MONTH = 7; // July 2026
export const HISTORY_MONTHS = 36;
export const LAST_UPDATED = "29 July 2026, 7:30 PM";

export interface MonthKey {
  monthIndex: number; // 1-12
  year: number;
  label: string; // "Jul 26"
  longLabel: string; // "Jul 2026"
}

export const makeMonthKey = (monthIndex: number, year: number): MonthKey => ({
  monthIndex,
  year,
  label: `${MONTH_LABELS[monthIndex - 1]} ${String(year).slice(2)}`,
  longLabel: `${MONTH_LABELS[monthIndex - 1]} ${year}`,
});

/** Chronological list ending at the current month. */
export const TIMELINE: MonthKey[] = (() => {
  const out: MonthKey[] = [];
  let m = CURRENT_MONTH;
  let y = CURRENT_YEAR;
  for (let i = 0; i < HISTORY_MONTHS; i++) {
    out.unshift(makeMonthKey(m, y));
    m -= 1;
    if (m === 0) {
      m = 12;
      y -= 1;
    }
  }
  return out;
})();

/** The three latest COMPLETE months (current month excluded). */
export const RUN_RATE_MONTHS = TIMELINE.slice(-4, -1);

export const LAST_12_MONTHS = TIMELINE.slice(-12);