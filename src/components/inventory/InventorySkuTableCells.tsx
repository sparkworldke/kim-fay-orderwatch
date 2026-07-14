import { InventoryLink } from "@/components/entity-links";
import type { InventoryItem } from "@/hooks/useOperations";
import { cn } from "@/lib/utils";

/** Column 1 — product name (row 1) + inventory ID (row 2). */
export function InventoryProductColumnCell({
  item,
  className,
}: {
  item: Pick<InventoryItem, "inventory_id" | "description">;
  className?: string;
}) {
  const productName = item.description?.trim() || "—";

  return (
    <div className={cn("min-w-0 space-y-0.5", className)}>
      <p className="truncate text-sm font-medium leading-snug" title={productName}>
        {productName}
      </p>
      <InventoryLink
        inventoryId={item.inventory_id}
        description={item.description}
        className="font-mono text-xs text-muted-foreground"
      />
    </div>
  );
}

/** Column 2 — brand (row 1) + manufactured / trading label (row 2). */
export function InventoryBrandColumnCell({
  item,
  className,
}: {
  item: Pick<InventoryItem, "brand" | "product_type">;
  className?: string;
}) {
  const brand = item.brand?.trim() || "—";
  const categoryLabel =
    item.product_type === "trading" ? "Trading (Partner)" : "Manufactured";

  return (
    <div className={cn("min-w-0 space-y-0.5", className)}>
      <p className="truncate text-sm font-medium leading-snug" title={brand}>
        {brand}
      </p>
      <p className="text-xs text-muted-foreground">{categoryLabel}</p>
    </div>
  );
}