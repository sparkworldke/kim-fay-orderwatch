import type { InventoryItem, InventoryMetrics, MonthlySales } from "@/types/Stock/inventory";
import { calculateCoverageStatus, calculateInventoryStatus, worstStatus } from "./status";

export const sumAvailable = (item: InventoryItem, warehouseIds: string[]) =>
  item.warehouseStocks
    .filter((w) => warehouseIds.includes(w.warehouseId))
    .reduce((a, w) => a + w.qtyAvailable, 0);

export const lastCompleteMonths = (monthlySales: MonthlySales[], count = 3) =>
  monthlySales.slice(-(count + 1), -1);

export const yearToCurrentMonth = (
  monthlySales: MonthlySales[],
  today = new Date(),
): MonthlySales[] => {
  const year = today.getFullYear();
  const currentMonth = today.getMonth() + 1;
  const byMonth = new Map(
    monthlySales
      .filter((point) => point.year === year)
      .map((point) => [point.monthIndex, point]),
  );

  return Array.from({ length: currentMonth }, (_, index) => {
    const monthIndex = index + 1;
    const existing = byMonth.get(monthIndex);
    return existing ?? {
      month: new Date(year, index).toLocaleString("en", {
        month: "short",
        year: "2-digit",
      }),
      monthIndex,
      year,
      orderedQuantity: 0,
      quantity: 0,
      missedOpportunityQuantity: 0,
      missedOpportunityRevenue: 0,
      missedRevenueComplete: true,
      currencyId: "KES",
      stockAvailable: Number.NaN,
    };
  });
};

export const threeMonthRunRate = (monthlySales: MonthlySales[]) => {
  const months = lastCompleteMonths(monthlySales);
  if (!months.length) return 0;
  return months.reduce((a, m) => a + m.quantity, 0) / months.length;
};

export const salesDays = (monthlySales: MonthlySales[]) =>
  monthlySales.reduce(
    (total, month) => total + new Date(month.year, month.monthIndex, 0).getDate(),
    0,
  );

export const monthsOfCover = (qtyAvailable: number, runRate: number) =>
  runRate <= 0 ? Number.POSITIVE_INFINITY : qtyAvailable / runRate;

export const requirement = (msi: number, qtyAvailable: number) =>
  Math.max(0, msi - qtyAvailable);

export function buildMetrics(
  item: InventoryItem,
  warehouseIds: string[],
  msiOverride?: number,
): InventoryMetrics {
  const stocks = item.warehouseStocks.filter((w) => warehouseIds.includes(w.warehouseId));
  const qtyAvailable = stocks.reduce((a, w) => a + w.qtyAvailable, 0);
  const qtyOnHand = stocks.reduce((a, w) => a + w.qtyOnHand, 0);
  const qtyAllocated = stocks.reduce((a, w) => a + w.qtyAllocated, 0);
  const msi = msiOverride ?? item.msi;
  const msiConfigured = msiOverride !== undefined || item.msiConfigured !== false;
  const lastThree = lastCompleteMonths(item.monthlySales);
  const lastThreeTotal = lastThree.reduce((a, m) => a + m.quantity, 0);
  const runRate = lastThree.length ? lastThreeTotal / lastThree.length : 0;
  const daysInSalesPeriod = salesDays(lastThree);
  const dailyRunRate = daysInSalesPeriod ? lastThreeTotal / daysInSalesPeriod : 0;
  const cover = monthsOfCover(qtyAvailable, runRate);
  const msiStatus = calculateInventoryStatus(qtyAvailable, msi);
  const coverageStatus = calculateCoverageStatus(cover);

  return {
    item,
    inventoryId: item.inventoryId,
    productName: item.productName,
    brand: item.brand,
    category: item.category,
    businessLine: item.businessLine,
    site: item.site,
    machine: item.machine,
    qtyAvailable,
    qtyOnHand,
    qtyAllocated,
    safetyStock: item.safetyStock,
    bufferStock: item.bufferStock,
    msi,
    msiConfigured,
    lastThreeMonths: lastThree,
    lastThreeMonthsTotal: lastThreeTotal,
    runRate,
    dailyRunRate,
    monthsOfCover: cover,
    daysOfCover:
      dailyRunRate > 0 ? qtyAvailable / dailyRunRate : Number.POSITIVE_INFINITY,
    msiStatus,
    coverageStatus,
    planningStatus: worstStatus(msiStatus, coverageStatus),
    requirement: requirement(msi, qtyAvailable),
  };
}

export const growthPercent = (current: number, prior: number) => {
  if (!prior) return current > 0 ? 100 : 0;
  return ((current - prior) / prior) * 100;
};
