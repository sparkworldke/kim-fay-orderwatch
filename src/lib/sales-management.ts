import type { SalesPromptStatus, SalesPromptType } from "@/hooks/useSalesManagement";

export const SALES_PROMPT_TYPE_LABEL: Record<SalesPromptType, string> = {
  order_cycle_follow_up: "Order cycle follow-up",
  not_billed_month: "Month close gap",
  debt_collection: "Debt collection",
  volume_delta: "Volume delta",
  whitespot_customer: "Customer whitespot",
  whitespot_product: "Product whitespot",
  incentive_review: "Incentive review",
};

export const SALES_PROMPT_STATUS_LABEL: Record<SalesPromptStatus, string> = {
  open: "Open",
  snoozed: "Snoozed",
  resolved: "Resolved",
  dismissed: "Dismissed",
};

export function promptSeverityClass(severity: string) {
  if (severity === "overdue") return "border-red-200 bg-red-50 text-red-700";
  if (severity === "due") return "border-amber-200 bg-amber-50 text-amber-700";
  return "border-blue-200 bg-blue-50 text-blue-700";
}

export function money(value: string | number | null | undefined) {
  const amount = Number(value ?? 0);
  return `KES ${amount.toLocaleString("en-KE", { maximumFractionDigits: 2 })}`;
}

export function shortDate(value: string | null | undefined) {
  if (!value) return "-";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return value;
  return date.toLocaleDateString("en-KE", { timeZone: "Africa/Nairobi" });
}
