import type { MonthlySales } from "@/types/Stock/inventory";

export interface ChartAnnotation {
  month: string;
  label: string;
  tone: "critical" | "warning" | "success" | "info";
}

function argMaxFinite(values: number[]): number {
  let bestIdx = -1;
  let best = Number.NEGATIVE_INFINITY;
  for (let i = 0; i < values.length; i++) {
    const v = values[i];
    if (!Number.isFinite(v)) continue;
    if (v > best) {
      best = v;
      bestIdx = i;
    }
  }
  return bestIdx;
}

function argMinFinite(values: number[]): number {
  let bestIdx = -1;
  let best = Number.POSITIVE_INFINITY;
  for (let i = 0; i < values.length; i++) {
    const v = values[i];
    if (!Number.isFinite(v)) continue;
    if (v < best) {
      best = v;
      bestIdx = i;
    }
  }
  return bestIdx;
}

export function buildTrendAnnotations(series: MonthlySales[]): ChartAnnotation[] {
  if (series.length < 3) return [];
  const out: ChartAnnotation[] = [];
  const quantities = series.map((s) => s.quantity);
  const finiteQty = quantities.filter((v) => Number.isFinite(v));
  const avg = finiteQty.length ? finiteQty.reduce((a, b) => a + b, 0) / finiteQty.length : 0;

  const maxIdx = argMaxFinite(quantities);
  const minIdx = argMinFinite(quantities);
  if (maxIdx >= 0) {
    out.push({ month: series[maxIdx].month, label: "Sales spike", tone: "success" });
  }
  if (minIdx >= 0 && minIdx !== maxIdx) {
    out.push({ month: series[minIdx].month, label: "Low season", tone: "warning" });
  }

  // Live API inventory does not provide historical stock-available series
  // (stockAvailable is often NaN). Skip stock annotations when no finite values.
  const stocks = series.map((s) => s.stockAvailable);
  const lowStockIdx = argMinFinite(stocks);
  if (lowStockIdx >= 0) {
    out.push({
      month: series[lowStockIdx].month,
      label: stocks[lowStockIdx] <= 0 ? "Stockout" : "Low stock",
      tone: "critical",
    });

    let replenIdx = -1;
    let bestJump = 0;
    for (let i = 1; i < stocks.length; i++) {
      if (!Number.isFinite(stocks[i]) || !Number.isFinite(stocks[i - 1])) continue;
      const jump = stocks[i] - stocks[i - 1];
      if (jump > bestJump) {
        bestJump = jump;
        replenIdx = i;
      }
    }
    if (replenIdx > -1 && bestJump > avg * 0.5) {
      out.push({ month: series[replenIdx].month, label: "Replenishment", tone: "info" });
    }
  }

  // de-duplicate by month, keep first
  const seen = new Set<string>();
  return out.filter((a) => (seen.has(a.month) ? false : seen.add(a.month)));
}
