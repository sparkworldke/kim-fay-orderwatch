export type ShortcutCategory = "date" | "metric" | "report";

export interface AiShortcut {
  key: string;
  label: string;
  description: string;
  category: ShortcutCategory;
  /** Text inserted after / or @ */
  insert: string;
  keywords?: string[];
}

export const AI_SHORTCUTS: AiShortcut[] = [
  { key: "today", label: "Today", description: "Metrics for today", category: "date", insert: "today", keywords: ["now", "current"] },
  { key: "yesterday", label: "Yesterday", description: "Previous calendar day", category: "date", insert: "yesterday", keywords: ["prior day"] },
  { key: "mtd", label: "MTD", description: "Month-to-date through today", category: "date", insert: "mtd", keywords: ["month", "month-to-date"] },
  { key: "last-week", label: "Last 7 days", description: "Rolling last week", category: "date", insert: "last-week", keywords: ["week", "7d"] },
  { key: "last-month", label: "Last month", description: "Previous calendar month", category: "date", insert: "last-month", keywords: ["prior month"] },
  { key: "ytd", label: "YTD", description: "Year-to-date", category: "date", insert: "ytd", keywords: ["year"] },

  { key: "orders", label: "Orders", description: "Order volume, value & status", category: "metric", insert: "orders", keywords: ["sales orders"] },
  { key: "completed", label: "Completed", description: "Completed / captured orders", category: "metric", insert: "completed", keywords: ["captured", "fulfilled"] },
  { key: "uncaptured", label: "Uncaptured", description: "Outstanding uncaptured orders", category: "metric", insert: "uncaptured", keywords: ["outstanding", "open"] },
  { key: "revenue", label: "Revenue at risk", description: "Value still at risk", category: "metric", insert: "revenue", keywords: ["risk", "value"] },
  { key: "emails", label: "Emails", description: "Inbox ingestion & PO emails", category: "metric", insert: "emails", keywords: ["inbox", "mailbox"] },
  { key: "matches", label: "Matches", description: "Email-to-order matching", category: "metric", insert: "matches", keywords: ["matching", "po"] },
  { key: "customers", label: "Customers", description: "Account performance", category: "metric", insert: "customers", keywords: ["accounts", "clients"] },
  { key: "cron", label: "Cron jobs", description: "Automation run health", category: "metric", insert: "cron", keywords: ["sync", "automation"] },
  { key: "risk", label: "Risks", description: "Exceptions needing attention", category: "metric", insert: "risk", keywords: ["issues", "alerts"] },

  { key: "compare", label: "Compare", description: "Period-over-period comparison", category: "report", insert: "compare", keywords: ["vs", "trend"] },
  { key: "summary", label: "Summary", description: "Executive-style brief", category: "report", insert: "summary", keywords: ["brief", "overview"] },
];

export const SHORTCUT_CATEGORY_LABELS: Record<ShortcutCategory, string> = {
  date: "Dates",
  metric: "Metrics",
  report: "Reports",
};

export interface ShortcutTriggerContext {
  trigger: "/" | "@";
  query: string;
  start: number;
  end: number;
}

export function getShortcutTriggerContext(text: string, cursor: number): ShortcutTriggerContext | null {
  const before = text.slice(0, cursor);
  const match = before.match(/(?:^|[\s])(([@/])([a-zA-Z0-9_-]*))$/);
  if (!match) return null;

  const full = match[1];
  const start = before.length - full.length;

  return {
    trigger: match[2] as "/" | "@",
    query: match[3] ?? "",
    start,
    end: cursor,
  };
}

export function filterShortcuts(query: string): AiShortcut[] {
  const q = query.toLowerCase();
  if (!q) return AI_SHORTCUTS;

  return AI_SHORTCUTS.filter((shortcut) => {
    const haystack = [shortcut.key, shortcut.label, shortcut.insert, ...(shortcut.keywords ?? [])]
      .join(" ")
      .toLowerCase();
    return haystack.includes(q) || shortcut.key.startsWith(q);
  });
}

export function applyShortcut(
  text: string,
  context: ShortcutTriggerContext,
  shortcut: AiShortcut,
): { value: string; cursor: number } {
  const insert = `${context.trigger}${shortcut.insert} `;
  const value = text.slice(0, context.start) + insert + text.slice(context.end);
  const cursor = context.start + insert.length;
  return { value, cursor };
}