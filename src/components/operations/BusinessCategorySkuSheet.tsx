import { useMemo, useState } from "react";
import { FileDown, Package } from "lucide-react";
import { toast } from "sonner";
import { ProductListingCell } from "@/components/inventory/ProductListingCell";
import { MaskedCurrency, useMaskedKESFormatter } from "@/components/MaskedCurrency";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import {
  type BusinessCategorySkuBreakdown,
  useBusinessCategorySkuBreakdown,
} from "@/hooks/useOperations";
import { downloadApiFile } from "@/lib/api";
import { formatNumber } from "@/lib/format";

export type BusinessCategoryKey = "manufactured" | "trading";

export type BusinessCategoryFilterParams = {
  date_from?: string;
  date_to?: string;
  customer_group?: string;
  product_line?: string;
  reason_code?: string;
  shipping_zone_id?: string;
  segment?: string;
  partner_brand?: string;
  brand?: string;
  category?: string;
  warehouse_id?: string;
  q?: string;
  status?: string;
};

type Module = "fill-rate" | "backorders";

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  module: Module;
  businessCategory: BusinessCategoryKey | null;
  filters?: BusinessCategoryFilterParams;
};

export function BusinessCategorySkuSheet({
  open,
  onOpenChange,
  module,
  businessCategory,
  filters = {},
}: Props) {
  const kes = useMaskedKESFormatter();
  const [isDownloading, setIsDownloading] = useState(false);
  const enabled = open && businessCategory != null;

  const query = useBusinessCategorySkuBreakdown(
    module,
    businessCategory ?? "manufactured",
    filters,
    enabled,
  );

  const data = query.data;
  const label = data?.label
    ?? (businessCategory === "manufactured" ? "Manufactured" : "Trading (Partners)");

  const exportPath = useMemo(() => {
    if (!businessCategory) return null;
    const qs = new URLSearchParams();
    qs.set("business_category", businessCategory);
    Object.entries(filters).forEach(([key, value]) => {
      if (value != null && value !== "") qs.set(key, String(value));
    });
    return `operations/${module}/sku-breakdown/export?${qs}`;
  }, [businessCategory, filters, module]);

  async function handleDownload() {
    if (!exportPath || !businessCategory) return;
    setIsDownloading(true);
    try {
      const stamp = new Date().toISOString().slice(0, 16).replace(/[-:T]/g, "");
      const prefix = module === "fill-rate" ? "fill-rate-skus" : "backorder-skus";
      await downloadApiFile(
        exportPath,
        `${prefix}-${businessCategory}-${stamp}.xlsx`,
        { timeoutMs: 180_000 },
      );
      toast.success(`${label} SKU Excel download started.`);
    } catch (error) {
      toast.error(error instanceof Error ? error.message : "Unable to download SKU breakdown.");
    } finally {
      setIsDownloading(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent side="right" className="w-full overflow-y-auto sm:max-w-2xl lg:max-w-3xl">
        <SheetHeader>
          <SheetTitle className="flex items-center gap-2">
            <Package className="h-4 w-4" />
            {label} — SKU breakdown
          </SheetTitle>
          <SheetDescription>
            {module === "fill-rate"
              ? "Fill-rate shortfall lines by SKU for this business category."
              : "Open backorder lines by SKU for this business category."}
            {data?.date_from && data?.date_to ? ` Range ${data.date_from} → ${data.date_to}.` : null}
          </SheetDescription>
        </SheetHeader>

        <div className="mt-4 flex flex-wrap items-center gap-2">
          <Button size="sm" variant="outline" onClick={handleDownload} disabled={!exportPath || isDownloading || query.isLoading}>
            <FileDown className={`mr-2 h-4 w-4 ${isDownloading ? "animate-pulse" : ""}`} />
            {isDownloading ? "Preparing…" : "Download Excel"}
          </Button>
          {data && (
            <Badge variant="secondary">{data.sku_count} SKUs</Badge>
          )}
        </div>

        {query.isLoading && (
          <div className="mt-4 space-y-2">
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
            <Skeleton className="h-16 w-full" />
          </div>
        )}

        {query.isError && (
          <p className="mt-4 text-sm text-destructive">
            {(query.error as Error)?.message ?? "Failed to load SKU breakdown."}
          </p>
        )}

        {data && (
          <>
            <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              <SummaryChip label="SKUs" value={String(data.sku_count)} />
              <SummaryChip label="Lines" value={String(data.line_count)} />
              <SummaryChip label="Orders" value={String(data.order_count)} />
              {module === "fill-rate" ? (
                <>
                  <SummaryChip
                    label="Not shipped"
                    value={kes((data as BusinessCategorySkuBreakdown & { undershipped_value: number }).undershipped_value)}
                  />
                  <SummaryChip
                    label="Fill rate"
                    value={
                      (data as BusinessCategorySkuBreakdown & { fill_rate_pct: number | null }).fill_rate_pct != null
                        ? `${(data as BusinessCategorySkuBreakdown & { fill_rate_pct: number | null }).fill_rate_pct}%`
                        : "N/A"
                    }
                  />
                </>
              ) : (
                <>
                  <SummaryChip
                    label="Open qty"
                    value={formatNumber((data as BusinessCategorySkuBreakdown & { open_qty: number }).open_qty)}
                  />
                  <SummaryChip
                    label="Backorder value"
                    value={kes((data as BusinessCategorySkuBreakdown & { back_order_value: number }).back_order_value)}
                  />
                </>
              )}
            </div>

            <div className="mt-4 overflow-x-auto rounded-md border">
              <table className="w-full text-xs">
                <thead>
                  <tr className="border-b bg-muted/40 text-left text-muted-foreground">
                    <th className="px-3 py-2 font-medium">SKU</th>
                    <th className="px-3 py-2 font-medium text-right">Lines</th>
                    <th className="px-3 py-2 font-medium text-right">Orders</th>
                    {module === "fill-rate" ? (
                      <>
                        <th className="px-3 py-2 font-medium text-right">Ordered</th>
                        <th className="px-3 py-2 font-medium text-right">Shipped</th>
                        <th className="px-3 py-2 font-medium text-right">Short</th>
                        <th className="px-3 py-2 font-medium text-right">Fill %</th>
                        <th className="px-3 py-2 font-medium text-right">Value</th>
                      </>
                    ) : (
                      <>
                        <th className="px-3 py-2 font-medium text-right">Open qty</th>
                        <th className="px-3 py-2 font-medium text-right">Value</th>
                      </>
                    )}
                  </tr>
                </thead>
                <tbody>
                  {data.skus.length === 0 && (
                    <tr>
                      <td colSpan={8} className="px-3 py-6 text-center text-muted-foreground">
                        No SKUs in this category for the current filters.
                      </td>
                    </tr>
                  )}
                  {data.skus.map((sku) => (
                    <tr key={sku.inventory_id} className="border-b last:border-0">
                      <td className="px-3 py-2">
                        <ProductListingCell product={sku} />
                      </td>
                      <td className="px-3 py-2 text-right font-mono">{sku.line_count}</td>
                      <td className="px-3 py-2 text-right font-mono">{sku.order_count}</td>
                      {module === "fill-rate" ? (
                        <>
                          <td className="px-3 py-2 text-right font-mono">
                            {formatNumber((sku as { ordered_qty: number }).ordered_qty)}
                          </td>
                          <td className="px-3 py-2 text-right font-mono">
                            {formatNumber((sku as { shipped_qty: number }).shipped_qty)}
                          </td>
                          <td className="px-3 py-2 text-right font-mono">
                            {formatNumber((sku as { undershipped_qty: number }).undershipped_qty)}
                          </td>
                          <td className="px-3 py-2 text-right font-mono">
                            {(sku as { fill_rate_pct: number | null }).fill_rate_pct != null
                              ? `${(sku as { fill_rate_pct: number }).fill_rate_pct}%`
                              : "—"}
                          </td>
                          <td className="px-3 py-2 text-right font-mono">
                            <MaskedCurrency value={(sku as { undershipped_value: number }).undershipped_value} />
                          </td>
                        </>
                      ) : (
                        <>
                          <td className="px-3 py-2 text-right font-mono">
                            {formatNumber((sku as { open_qty: number }).open_qty)}
                          </td>
                          <td className="px-3 py-2 text-right font-mono">
                            <MaskedCurrency value={(sku as { back_order_value: number }).back_order_value} />
                          </td>
                        </>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}

function SummaryChip({ label, value }: { label: string; value: string }) {
  return (
    <div className="rounded-md border bg-muted/30 px-3 py-2">
      <p className="text-[11px] text-muted-foreground">{label}</p>
      <p className="text-sm font-semibold">{value}</p>
    </div>
  );
}
