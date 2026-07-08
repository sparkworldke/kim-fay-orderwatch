/**
 * ComparisonTable
 *
 * Tabular listing of combined historical + prediction-period months with
 * columns: Month | Predicted Units | Actual Units Sold.
 *
 * Row highlighting rules (Property 10 / Requirements 2.9, 2.10):
 * - Green  (`bg-green-50 border-l-4 border-green-500`) when `(actual - predicted) / predicted * 100 > 20`
 * - Amber  (`bg-amber-50 border-l-4 border-amber-500`) when `(predicted - actual) / predicted * 100 > 20`
 * - No highlight when within the ±20 % threshold.
 *
 * "Actual Units Sold" shows "—" for future months (`is_future: true`).
 *
 * Requirements: 2.8, 2.9, 2.10
 */

import { varianceIndicator } from "@/utils/inventoryUtils";
import type { SkuDetailResponse } from "@/hooks/useSkuDetail";

type MonthlySale = SkuDetailResponse["monthly_sales"][number];

export type ComparisonTableProps = {
  /** Combined historical + prediction-period monthly rows. */
  monthlySales: MonthlySale[];
};

const COL_LABEL = "px-3 py-2 text-left text-xs font-medium uppercase tracking-wide text-muted-foreground";

export function ComparisonTable({ monthlySales }: ComparisonTableProps) {
  if (monthlySales.length === 0) {
    return (
      <p className="py-6 text-center text-sm text-muted-foreground">
        No comparison data available for this period.
      </p>
    );
  }

  return (
    <div className="overflow-x-auto rounded-lg border">
      <table className="w-full text-sm">
        <thead className="bg-muted/40">
          <tr>
            <th className={COL_LABEL}>Month</th>
            <th className={`${COL_LABEL} text-right`}>Predicted Units</th>
            <th className={`${COL_LABEL} text-right`}>Actual Units Sold</th>
          </tr>
        </thead>
        <tbody>
          {monthlySales.map((row) => {
            const indicator = varianceIndicator(row.predicted_qty, row.shipped_qty);
            const rowClass =
              indicator === "green"
                ? "bg-green-50 border-l-4 border-green-500"
                : indicator === "amber"
                  ? "bg-amber-50 border-l-4 border-amber-500"
                  : "border-l-4 border-transparent";

            return (
              <tr key={row.month} className={`border-b last:border-0 ${rowClass}`}>
                <td className="px-3 py-2 font-medium">{row.month_label}</td>
                <td className="px-3 py-2 text-right font-mono tabular-nums">
                  {Number(row.predicted_qty).toLocaleString()}
                </td>
                <td className="px-3 py-2 text-right font-mono tabular-nums">
                  {row.is_future ? "—" : Number(row.shipped_qty).toLocaleString()}
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
