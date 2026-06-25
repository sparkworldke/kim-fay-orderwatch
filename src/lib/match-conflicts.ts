export interface MatchConflict {
  field: string;
  email_value: string;
  acumatica_value: string;
  reason: string;
  email_value_inc_vat?: string;
  vat_rate?: string;
  amount_delta?: string;
}

export function conflictFieldLabel(field: string): string {
  if (field === "total") return "Order total";
  if (field === "currency") return "Currency";
  if (field === "branch") return "Branch / location";
  if (field === "delivery_date") return "Delivery date";
  if (field.startsWith("quantity:")) return `Quantity (${field.slice("quantity:".length)})`;
  if (field.startsWith("unit_price:")) return `Unit price (${field.slice("unit_price:".length)})`;
  if (field === "sku") return "SKU / item";

  return field.replaceAll("_", " ");
}

/** Short flag label for the Orders table Flag column. */
export function conflictFlagLabel(field: string): string {
  if (field === "total") return "Email Amount";
  if (field === "currency") return "Email Currency";
  if (field === "branch") return "Email Branch";
  if (field === "delivery_date") return "Email Date";
  if (field.startsWith("quantity:")) return "Email Qty";
  if (field.startsWith("unit_price:")) return "Email Price";
  if (field === "sku") return "Email SKU";

  return `Email ${field.replaceAll("_", " ")}`;
}

function parseMoney(value: string | undefined): number | null {
  if (!value) return null;
  const parsed = Number.parseFloat(value.replaceAll(",", ""));
  return Number.isFinite(parsed) ? parsed : null;
}

/** Email basis used for total comparison (VAT-inclusive when available). */
export function conflictCompareEmailValue(conflict: MatchConflict): number | null {
  return parseMoney(conflict.email_value_inc_vat) ?? parseMoney(conflict.email_value);
}

/** Acumatica − Email. Positive = higher SO total (business advantage). */
export function conflictAmountDelta(conflict: MatchConflict): number | null {
  if (conflict.field !== "total") return null;
  if (conflict.amount_delta !== undefined) {
    const preset = parseMoney(conflict.amount_delta);
    if (preset !== null) return preset;
  }
  const acumatica = parseMoney(conflict.acumatica_value);
  const email = conflictCompareEmailValue(conflict);
  if (acumatica === null || email === null) return null;
  return acumatica - email;
}

export function formatSignedAmount(value: number): string {
  const sign = value > 0 ? "+" : value < 0 ? "" : "";
  return `${sign}${value.toLocaleString("en-KE", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export function conflictDeltaTone(delta: number): "positive" | "negative" | "neutral" {
  if (delta > 0.005) return "positive";
  if (delta < -0.005) return "negative";
  return "neutral";
}

export function formatConflictAmount(conflict: MatchConflict, showVat = true): string {
  if (conflict.field !== "total") {
    return `Email ${conflict.email_value} vs Acumatica ${conflict.acumatica_value}`;
  }

  const delta = conflictAmountDelta(conflict);
  const deltaSuffix = delta !== null ? ` (${formatSignedAmount(delta)})` : "";

  if (showVat && conflict.email_value_inc_vat) {
    return `Email ${conflict.email_value} (+VAT ${conflict.vat_rate ?? "16"}% ${conflict.email_value_inc_vat}) vs Acumatica ${conflict.acumatica_value}${deltaSuffix}`;
  }

  return `Email ${conflict.email_value} vs Acumatica ${conflict.acumatica_value}${deltaSuffix}`;
}

export function conflictSummary(conflict: MatchConflict): string {
  const label = conflictFieldLabel(conflict.field);
  return `${label}: ${formatConflictAmount(conflict)}`;
}

export function conflictSummaries(conflicts: MatchConflict[] | null | undefined): string[] {
  return (conflicts ?? []).map(conflictSummary);
}