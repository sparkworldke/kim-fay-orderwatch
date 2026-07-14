/** Parent workflow contexts for hierarchical reason display. */
export const WORKFLOW_PARENT_LABELS: Record<string, string> = {
  cancelled_order: "Cancelled Order",
  rejected_order: "Rejected Order",
  on_hold_order: "On Hold Order",
  backorder: "Backorder",
  fill_rate_shortfall: "Fill Rate Shortfall",
};

/** Approved sub-reasons (slug => display label). */
export const APPROVED_SUB_REASONS: Record<string, string> = {
  out_of_stock_procurement: "Out of stock - Procurement",
  out_of_stock_production: "Out of stock - Production",
  delay_in_delivery: "Delay in delivery",
  promo_product: "Promo product",
  transfer_delays: "Transfer Delays",
  short_expiry: "Short Expiry",
  out_of_stock_msa: "Out of stock - MSA",
  raw_material_stockout: "Raw material stockout",
  discontinued: "Discontinued",
  pb_discontinued: "PB Discontinued",
  delayed_communication: "Delayed Communication",
  truck_full: "Truck Full",
  price_difference: "Price Difference",
  invoicing_error: "Invoicing Error",
  stock_variance: "Stock Variance",
  isolation_error: "Isolation Error",
  non_focus: "Non focus",
  wrong_moq: "Wrong MOQ",
  order_to_make: "Order To make",
  kebs_stickers: "Kebs stickers",
  wrong_product_description: "Wrong Product Description",
  system_error: "System error",
  conversion_delays: "Conversion delays",
  wrong_code: "Wrong code",
  price_variance: "Price Variance",
  delayed_supplier_payment: "Delayed Supplier Payment",
  lpo_error: "LPO Error",
  batch_sequence: "Batch Sequence",
  conversion_issues: "Conversion issues",
  price_overcharge: "Price Overcharge",
  npd: "NPD",
  did_not_pick_on_shipment: "Did not pick on shipment",
  production_stockout: "Production Stokout",
};

export const REJECTION_REASON_OPTIONS = Object.entries(APPROVED_SUB_REASONS).map(
  ([value, label]) => ({ value, label }),
);

export const REJECTION_REASON_LABELS: Record<string, string> = {
  ...APPROVED_SUB_REASONS,
};

export function hierarchicalReasonLabel(
  parentCode: string | null | undefined,
  subReasonCode: string | null | undefined,
): string | null {
  if (!subReasonCode) return null;
  const sub = subReasonLabel(subReasonCode);
  if (!parentCode) return sub;
  const parent = WORKFLOW_PARENT_LABELS[parentCode] ?? parentCode;
  return `${parent} - ${sub}`;
}

export function subReasonLabel(code: string | null | undefined): string | null {
  if (!code) return null;
  return APPROVED_SUB_REASONS[code] ?? code.replaceAll("_", " ");
}

export function rejectionReasonLabel(code: string | null | undefined): string | null {
  return subReasonLabel(code);
}

export const STATUSES_REQUIRING_WORKFLOW_REASON = [
  "Rejected",
  "Cancelled",
  "On Hold",
  "Credit Hold",
] as const;

export function statusRequiresWorkflowReason(status: string | null | undefined): boolean {
  if (!status) return false;
  return (STATUSES_REQUIRING_WORKFLOW_REASON as readonly string[]).includes(status);
}

export function workflowParentForStatus(status: string | null | undefined): string | null {
  const normalized = (status ?? "").toLowerCase().trim();
  if (normalized === "canceled" || normalized === "cancelled") return "cancelled_order";
  if (normalized === "rejected") return "rejected_order";
  if (normalized === "on hold" || normalized === "credit hold" || normalized === "hold") return "on_hold_order";
  return null;
}

export function workflowReasonLabel(order: {
  workflow_reason_label?: string | null;
  workflow_parent_reason?: string | null;
  workflow_sub_reason_code?: string | null;
  rejection_reason_code?: string | null;
}): string | null {
  if (order.workflow_reason_label?.trim()) return order.workflow_reason_label.trim();
  const hierarchical = hierarchicalReasonLabel(
    order.workflow_parent_reason,
    order.workflow_sub_reason_code ?? order.rejection_reason_code,
  );
  if (hierarchical) return hierarchical;
  return rejectionReasonLabel(order.rejection_reason_code);
}

export type WorkflowReasonOption = { value: string; label: string };

export function workflowReasonOptionsForStatus(
  status: string,
  taxonomyParents?: { code: string; sub_reasons: { code: string; label: string; hierarchical_label: string }[] }[],
): WorkflowReasonOption[] {
  const parentCode = workflowParentForStatus(status);
  if (parentCode && taxonomyParents?.length) {
    const parent = taxonomyParents.find((entry) => entry.code === parentCode);
    if (parent?.sub_reasons.length) {
      return parent.sub_reasons.map((sub) => ({
        value: sub.code,
        label: sub.hierarchical_label,
      }));
    }
  }

  return REJECTION_REASON_OPTIONS.map((option) => ({
    value: option.value,
    label: parentCode
      ? hierarchicalReasonLabel(parentCode, option.value) ?? option.label
      : option.label,
  }));
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
 * Best-effort explanation for a line's fill rate/backorder shortfall.
 */
export function fillRateIssueReason(line: {
  unfilled_reason_code?: string | null;
  backorder_qty?: string | number | null;
  fulfillment_status?: string | null;
}): string | null {
  if (line.unfilled_reason_code) return subReasonLabel(line.unfilled_reason_code) ?? titleCase(line.unfilled_reason_code);
  if (line.fulfillment_status === "Cancelled") return "Cancelled";
  const backorderQty = Number(line.backorder_qty ?? 0);
  if (Number.isFinite(backorderQty) && backorderQty > 0) return "Out of stock - Procurement";
  return null;
}