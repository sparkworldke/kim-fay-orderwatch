import { BarChart3 } from "lucide-react";
import { Panel } from "./Panel";
import { formatNumber } from "@/utils/Stock/format";

export interface SalesTrendPoint {
  month: string;
  quantity: number;
}

export function SalesTrendTable({
  points,
  title = "Sales Trend (Units)",
  showRunning = false,
}: {
  points: SalesTrendPoint[];
  title?: string;
  showRunning?: boolean;
}) {
  const total = points.reduce((a, p) => a + p.quantity, 0);
  const max = Math.max(1, ...points.map((p) => p.quantity));
  let running = 0;

  return (
    <Panel title={title} icon={BarChart3} bodyClassName="p-0">
      <div className="w-full">
        <table className="w-full table-fixed text-sm">
          <thead>
            <tr className="border-b border-border text-xs font-semibold text-primary">
              <th className="w-28 px-2 py-2 text-left">Month</th>
              {points.map((p) => (
                <th key={p.month} className="px-1 py-2 text-center text-[11px]">
                  {p.month}
                </th>
              ))}
              <th className="w-24 px-2 py-2 text-right">Total</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td className="px-2 py-2 text-xs font-medium text-muted-foreground">Sales (Units)</td>
              {points.map((p) => (
                <td key={p.month} className="px-1 py-2 text-center text-[11px] tabular-nums">
                  {formatNumber(p.quantity)}
                </td>
              ))}
              <td className="px-2 py-2 text-right text-sm font-bold text-primary tabular-nums">
                {formatNumber(total)}
              </td>
            </tr>
            <tr>
              <td />
              {points.map((p) => (
                <td key={p.month} className="px-1 pb-3 align-bottom">
                  <div className="flex h-8 items-end justify-center gap-0.5">
                    {[0.6, 1, 0.8].map((f, i) => (
                      <span
                        key={i}
                        className="w-1.5 rounded-sm bg-primary"
                        style={{ height: `${Math.max(8, (p.quantity / max) * 100 * f)}%` }}
                      />
                    ))}
                  </div>
                </td>
              ))}
              <td />
            </tr>
            {showRunning ? (
              <tr className="border-t border-border">
                <td className="px-2 py-2 text-xs font-medium text-muted-foreground">Running YTD</td>
                {points.map((p) => {
                  running += p.quantity;
                  return (
                    <td key={p.month} className="px-1 py-2 text-center text-[11px] tabular-nums text-muted-foreground">
                      {formatNumber(running)}
                    </td>
                  );
                })}
                <td />
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}