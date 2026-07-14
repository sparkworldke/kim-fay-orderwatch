import type { FolStatus } from "@/hooks/useFol";

export const FOL_STATUS_LABEL: Record<FolStatus, string> = {
  draft: "Draft",
  submitted: "Submitted",
  in_approval: "In Approval",
  rejected: "Rejected",
  ready_for_invoicing: "Ready for Invoicing",
  so_linked: "SO Linked",
  invoiced: "Invoiced",
  fulfilled: "Fulfilled",
};

export const FOL_STATUS_CLASS: Record<FolStatus, string> = {
  draft: "bg-muted text-muted-foreground border-border",
  submitted: "bg-blue-50 text-blue-700 border-blue-200",
  in_approval: "bg-amber-50 text-amber-700 border-amber-200",
  rejected: "bg-red-50 text-red-700 border-red-200",
  ready_for_invoicing: "bg-emerald-50 text-emerald-700 border-emerald-200",
  so_linked: "bg-cyan-50 text-cyan-700 border-cyan-200",
  invoiced: "bg-indigo-50 text-indigo-700 border-indigo-200",
  fulfilled: "bg-green-50 text-green-700 border-green-200",
};

export function formatFolDate(value: string | null | undefined) {
  if (!value) return "-";
  return new Date(value).toLocaleString("en-KE", { timeZone: "Africa/Nairobi" });
}
