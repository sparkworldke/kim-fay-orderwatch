export const REJECTION_REASON_OPTIONS = [
  { value: "out_of_stock", label: "Out of Stock" },
  { value: "customer_request", label: "Customer Request" },
  { value: "invalid_payment", label: "Invalid Payment" },
  { value: "address_error", label: "Address Error" },
  { value: "pricing_dispute", label: "Pricing Dispute" },
  { value: "credit_limit", label: "Credit Limit" },
  { value: "duplicate_order", label: "Duplicate Order" },
  { value: "fraud_screen", label: "Fraud Screen" },
] as const;

export const REJECTION_REASON_LABELS: Record<string, string> = Object.fromEntries(
  REJECTION_REASON_OPTIONS.map((option) => [option.value, option.label]),
);

export function rejectionReasonLabel(code: string | null | undefined): string | null {
  if (!code) return null;
  return REJECTION_REASON_LABELS[code] ?? code.replaceAll("_", " ");
}

function titleCase(text: string): string {
  return text
    .replaceAll("_", " ")
    .split(" ")
    .filter(Boolean)
    .map((word) => word[0].toUpperCase() + word.slice(1).toLowerCase())
    .join(" ");
}

/**
 * Best-effort explanation for a line's fill rate/backorder shortfall. Acumatica's own
 * ReasonCode (when present) is authoritative; otherwise we fall back to a generic
 * inventory-shortage label since the sync doesn't distinguish stock-out vs partial-ship
 * beyond what Acumatica itself reports.
 */
export function fillRateIssueReason(line: {
  unfilled_reason_code?: string | null;
  backorder_qty?: string | number | null;
  fulfillment_status?: string | null;
}): string | null {
  if (line.unfilled_reason_code) return titleCase(line.unfilled_reason_code);
  if (line.fulfillment_status === "Cancelled") return "Cancelled";
  const backorderQty = Number(line.backorder_qty ?? 0);
  if (Number.isFinite(backorderQty) && backorderQty > 0) return "Inventory Shortage";
  return null;
}
