import type { StockStatus } from "@/types/Stock/inventory";

export function calculateInventoryStatus(qtyAvailable: number, msi: number): StockStatus {
  if (!msi || msi <= 0) return qtyAvailable > 0 ? "healthy" : "critical";
  const ratio = qtyAvailable / msi;
  if (ratio < 0.5) return "critical";
  if (ratio < 1) return "at-risk";
  return "healthy";
}

export function calculateCoverageStatus(monthsOfCover: number): StockStatus {
  if (!Number.isFinite(monthsOfCover)) return "healthy";
  if (monthsOfCover < 1) return "critical";
  if (monthsOfCover < 2) return "at-risk";
  return "healthy";
}

const SEVERITY: Record<StockStatus, number> = { critical: 0, "at-risk": 1, healthy: 2 };

export function worstStatus(...statuses: StockStatus[]): StockStatus {
  return statuses.reduce((a, b) => (SEVERITY[b] < SEVERITY[a] ? b : a), "healthy");
}

export const STATUS_LABEL: Record<StockStatus, string> = {
  critical: "Critical",
  "at-risk": "At Risk",
  healthy: "Healthy",
};

export const STATUS_LEGEND = [
  { status: "critical" as StockStatus, text: "Critical: Stock is below 50% of MSI" },
  { status: "at-risk" as StockStatus, text: "At Risk: Stock is between 50% and 99% of MSI" },
  { status: "healthy" as StockStatus, text: "Healthy: Stock is at or above MSI" },
];

export const ALL_STATUSES: StockStatus[] = ["healthy", "at-risk", "critical"];