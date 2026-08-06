export type DatePresetId =
  | "today"
  | "yesterday"
  | "this_week"
  | "last_week"
  | "this_month"
  | "last_month"
  | "custom";

export interface DateRangeValue {
  from: string;
  to: string;
}

export interface DatePreset {
  id: DatePresetId;
  label: string;
}

export const DATE_PRESETS: DatePreset[] = [
  { id: "today", label: "Today" },
  { id: "yesterday", label: "Yesterday" },
  { id: "this_week", label: "This Week" },
  { id: "last_week", label: "Last Week" },
  { id: "this_month", label: "This Month" },
  { id: "last_month", label: "Last Month" },
  { id: "custom", label: "Date Range" },
];

/** Calendar date in the user's local timezone (not UTC). */
function localDateIso(d: Date) {
  const year = d.getFullYear();
  const month = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

export function resolveDatePreset(preset: DatePresetId): DateRangeValue {
  const now = new Date();
  const start = new Date(now.getFullYear(), now.getMonth(), now.getDate());

  switch (preset) {
    case "today":
      return { from: localDateIso(start), to: localDateIso(start) };
    case "yesterday": {
      const y = new Date(start);
      y.setDate(y.getDate() - 1);
      return { from: localDateIso(y), to: localDateIso(y) };
    }
    case "this_week": {
      const day = start.getDay();
      const mondayOffset = day === 0 ? -6 : 1 - day;
      const monday = new Date(start);
      monday.setDate(monday.getDate() + mondayOffset);
      return { from: localDateIso(monday), to: localDateIso(start) };
    }
    case "last_week": {
      const day = start.getDay();
      const mondayOffset = day === 0 ? -6 : 1 - day;
      const thisMonday = new Date(start);
      thisMonday.setDate(thisMonday.getDate() + mondayOffset);
      const from = new Date(thisMonday);
      from.setDate(from.getDate() - 7);
      const to = new Date(thisMonday);
      to.setDate(to.getDate() - 1);
      return { from: localDateIso(from), to: localDateIso(to) };
    }
    case "this_month": {
      const from = new Date(start.getFullYear(), start.getMonth(), 1);
      return { from: localDateIso(from), to: localDateIso(start) };
    }
    case "last_month": {
      const from = new Date(start.getFullYear(), start.getMonth() - 1, 1);
      const to = new Date(start.getFullYear(), start.getMonth(), 0);
      return { from: localDateIso(from), to: localDateIso(to) };
    }
    default:
      return { from: localDateIso(start), to: localDateIso(start) };
  }
}

export function formatRangeLabel(from: string, to: string) {
  const fmt = (value: string) =>
    new Date(value + "T00:00:00").toLocaleDateString("en-KE", { day: "numeric", month: "short", year: "numeric" });
  return from === to ? fmt(from) : `${fmt(from)} – ${fmt(to)}`;
}

export function toDateInputValue(value: string) {
  return value.slice(0, 10);
}

export function toDateTimeLocalValue(value: string, edge: "start" | "end") {
  if (value.includes("T")) {
    return value.length >= 16 ? value.slice(0, 16) : value;
  }
  return `${value}T${edge === "start" ? "00:00" : "23:59"}`;
}

export function formatSyncRangeLabel(from: string, to: string) {
  const fmt = (value: string) => {
    const parsed = new Date(value.includes("T") ? value : `${value}T00:00:00`);
    const hasTime = value.includes("T") && !value.endsWith("T00:00") && !value.endsWith("T23:59");
    return parsed.toLocaleString("en-KE", {
      day: "numeric",
      month: "short",
      year: "numeric",
      ...(hasTime ? { hour: "2-digit", minute: "2-digit" } : {}),
    });
  };
  return from === to ? fmt(from) : `${fmt(from)} – ${fmt(to)}`;
}
