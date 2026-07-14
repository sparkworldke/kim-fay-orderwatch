import type { PcrStatus } from "@/hooks/usePriceChangeRequests";

export const PCR_STATUS_LABEL: Record<PcrStatus, string> = {
  submitted: "Submitted",
  in_approval: "In approval",
  rejected: "Rejected",
  pending_erp_apply: "Pending ERP apply",
  applied_erp: "Applied in ERP",
};

export const PCR_STATUS_CLASS: Record<PcrStatus, string> = {
  submitted: "border-blue-200 bg-blue-50 text-blue-700",
  in_approval: "border-amber-200 bg-amber-50 text-amber-700",
  rejected: "border-red-200 bg-red-50 text-red-700",
  pending_erp_apply: "border-violet-200 bg-violet-50 text-violet-700",
  applied_erp: "border-emerald-200 bg-emerald-50 text-emerald-700",
};

export function money(value: string | number | null | undefined): string {
  const n = Number(value ?? 0);
  return `KES ${n.toLocaleString(undefined, { maximumFractionDigits: 2 })}`;
}

export function pct(value: string | number | null | undefined): string {
  if (value == null) return "-";
  return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}%`;
}

export function shortDate(value: string | null | undefined): string {
  if (!value) return "-";
  return new Date(value).toLocaleDateString();
}
