import { Warehouse } from "lucide-react";
import { Panel } from "./Panel";
import { formatNumber, formatShare } from "@/utils/Stock/format";
import type { WarehouseStock } from "@/types/Stock/inventory";
import { isMsaWarehouse, isPrimaryFgsWarehouse } from "@/utils/Stock/transferRecommendation";
import { cn } from "@/lib/utils";

/** Prefer available; when ERP omits available, use on-hand so the breakdown still totals correctly. */
function resolvedAvailable(stock: WarehouseStock): number {
  if (!stock.qtyAvailableMissing && Number.isFinite(stock.qtyAvailable)) {
    return Number(stock.qtyAvailable);
  }
  return Number(stock.qtyOnHand) || 0;
}

function resolvedOnHand(stock: WarehouseStock): number {
  return Number(stock.qtyOnHand) || 0;
}

export function WarehouseBreakdown({
  stocks,
  title = "Warehouse Breakdown",
  subtitle,
}: {
  stocks: WarehouseStock[];
  title?: string;
  subtitle?: string;
}) {
  const totalOnHand = stocks.reduce((a, w) => a + resolvedOnHand(w), 0);
  const totalAvailable = stocks.reduce((a, w) => a + resolvedAvailable(w), 0);
  // Share of on-hand is always meaningful when available is missing across sites.
  const shareBase = totalOnHand > 0 ? totalOnHand : totalAvailable;

  return (
    <Panel title={title} subtitle={subtitle} icon={Warehouse} bodyClassName="p-0">
      {/* Mobile cards */}
      <ul className="divide-y divide-border md:hidden">
        {stocks.map((w) => {
          const onHand = resolvedOnHand(w);
          const available = resolvedAvailable(w);
          const share = shareBase > 0 ? (onHand / shareBase) * 100 : 0;
          return (
            <li key={w.warehouseId} className={cn("space-y-1 px-3 py-2", isPrimaryFgsWarehouse(w.warehouseId, w.warehouseName) && "bg-blue-50 dark:bg-blue-950/30")}>
              <div className="min-w-0">
                <p className="truncate text-[9px] font-bold text-navy">
                  {w.warehouseName}
                  {isPrimaryFgsWarehouse(w.warehouseId, w.warehouseName) ? " · Default" : isMsaWarehouse(w.warehouseId, w.warehouseName) ? " · View only" : ""}
                </p>
              </div>
              <div className="grid grid-cols-3 gap-2 text-[9px] text-muted-foreground">
                <span>
                  On hand
                  <br />
                  <b className="text-[9px] text-foreground tabular-nums">{formatNumber(onHand)}</b>
                </span>
                <span>
                  Available
                  <br />
                  <b className="text-[9px] text-primary tabular-nums">
                    {formatNumber(available)}
                    {w.qtyAvailableMissing ? (
                      <span className="ml-0.5 font-normal text-muted-foreground" title="Using on-hand (available not provided by ERP)">
                        *
                      </span>
                    ) : null}
                  </b>
                </span>
                <span>
                  % of total
                  <br />
                  <b className="text-[9px] text-foreground tabular-nums">
                    {shareBase > 0 ? formatShare(share) : "—"}
                  </b>
                </span>
              </div>
            </li>
          );
        })}
      </ul>

      <div className="hidden overflow-x-auto md:block">
        <table className="w-full text-[9px]">
          <thead>
            <tr className="border-b border-border text-left text-[9px] font-semibold text-primary">
              <th className="px-3 py-1.5">Warehouse</th>
              <th className="px-3 py-1.5 text-right">Qty on Hand</th>
              <th className="px-3 py-1.5 text-right">Qty Available</th>
              <th className="px-3 py-1.5 text-right">% of Total</th>
            </tr>
          </thead>
          <tbody>
            {stocks.map((w) => {
              const onHand = resolvedOnHand(w);
              const available = resolvedAvailable(w);
              const share = shareBase > 0 ? (onHand / shareBase) * 100 : 0;
              return (
                <tr
                  key={w.warehouseId}
                  className={cn("border-b border-border/60 last:border-0 hover:bg-secondary/60", isPrimaryFgsWarehouse(w.warehouseId, w.warehouseName) && "bg-blue-50 dark:bg-blue-950/30")}
                >
                  <td className="px-3 py-1.5 font-bold text-foreground">
                    {w.warehouseName}
                    {isPrimaryFgsWarehouse(w.warehouseId, w.warehouseName) ? " · Default" : isMsaWarehouse(w.warehouseId, w.warehouseName) ? " · View only" : ""}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums">{formatNumber(onHand)}</td>
                  <td className="px-3 py-1.5 text-right font-semibold tabular-nums text-primary">
                    {formatNumber(available)}
                    {w.qtyAvailableMissing ? (
                      <span
                        className="ml-0.5 text-[8px] font-normal text-muted-foreground"
                        title="Available not provided by ERP — showing on-hand"
                      >
                        *
                      </span>
                    ) : null}
                  </td>
                  <td className="px-3 py-1.5 text-right tabular-nums text-muted-foreground">
                    {shareBase > 0 ? formatShare(share) : "—"}
                  </td>
                </tr>
              );
            })}
            {stocks.length === 0 ? (
              <tr>
                <td colSpan={4} className="px-4 py-6 text-center text-[9px] text-muted-foreground">
                  No warehouses selected.
                </td>
              </tr>
            ) : null}
          </tbody>
          <tfoot>
            <tr className="border-t border-border bg-brand-soft/60 font-bold text-primary">
              <td className="px-4 py-2">Total</td>
              <td className="px-4 py-2 text-right tabular-nums">{formatNumber(totalOnHand)}</td>
              <td className="px-4 py-2 text-right tabular-nums">{formatNumber(totalAvailable)}</td>
              <td className="px-4 py-2 text-right tabular-nums">
                {shareBase > 0 ? "100.0%" : "—"}
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
      {stocks.some((w) => w.qtyAvailableMissing) ? (
        <p className="border-t border-border px-3 py-1.5 text-[8px] text-muted-foreground">
          * Qty Available falls back to Qty on Hand when Acumatica does not return available for that warehouse.
        </p>
      ) : null}
      {stocks.some((w) => isMsaWarehouse(w.warehouseId, w.warehouseName)) ? (
        <p className="border-t border-border px-3 py-1.5 text-[8px] text-muted-foreground">
          MSA quantities are included for visibility but cannot be used for transfers into FGS.
        </p>
      ) : null}
      <div className="grid grid-cols-3 gap-2 border-t border-border bg-brand-soft/60 px-3 py-2 text-[9px] font-bold text-primary md:hidden">
        <span>Total</span>
        <span className="text-right tabular-nums">{formatNumber(totalOnHand)}</span>
        <span className="text-right tabular-nums">{formatNumber(totalAvailable)}</span>
      </div>
    </Panel>
  );
}
