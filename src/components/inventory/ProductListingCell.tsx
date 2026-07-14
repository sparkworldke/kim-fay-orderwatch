import { InventoryLink } from "@/components/entity-links";
import { formatBrandDisplay } from "@/utils/inventoryUtils";
import { cn } from "@/lib/utils";

export type InventoryProductFields = {
  inventory_id: string | null | undefined;
  product_name?: string | null;
  description?: string | null;
  brand?: string | null;
  posting_class?: string | null;
  sub_trading_group?: string | null;
  supplier?: string | null;
};

type ProductListingCellProps = {
  product: InventoryProductFields;
  /** Wrap inventory ID in a link to the inventory detail panel. */
  link?: boolean;
  /** Show the inventory ID row (disable when ID is shown in its own column). */
  showInventoryId?: boolean;
  /** Show product name / description when present. */
  showDescription?: boolean;
  /** Show brand, sub trading group, posting class, and supplier. */
  showClassification?: boolean;
  className?: string;
};

/**
 * Standard product listing cell used across Fill Rate, Inventory, Backorders,
 * and other tables with a product column or secondary product row.
 *
 * Layout:
 *  - Inventory ID (mono, linked when `link` is true)
 *  - Optional description / product name
 *  - Brand (line 1)
 *  - - [Sub Trading Group] (line 2)
 *  - Posting class · Supplier (muted meta line)
 */
export function ProductListingCell({
  product,
  link = true,
  showInventoryId = true,
  showDescription = true,
  showClassification = true,
  className,
}: ProductListingCellProps) {
  if (!product.inventory_id) {
    return <span className="text-muted-foreground">—</span>;
  }

  const displayName = product.product_name ?? product.description ?? null;
  const { brandLine, subGroupLine } = formatBrandDisplay(product.brand, product.sub_trading_group);

  const metaParts: string[] = [];
  if (showClassification) {
    if (product.posting_class?.trim()) {
      metaParts.push(`Posting: ${product.posting_class.trim()}`);
    }
    if (product.supplier?.trim()) {
      metaParts.push(`Supplier: ${product.supplier.trim()}`);
    }
  }

  const body = (
    <div className={cn("min-w-0 space-y-0.5", className)}>
      {showInventoryId && (
        <div className="font-mono text-xs font-semibold text-foreground">{product.inventory_id}</div>
      )}
      {showDescription && displayName && (
        <div className="truncate text-sm font-medium text-foreground">{displayName}</div>
      )}
      {brandLine && (
        <div className="truncate text-xs font-medium text-foreground">{brandLine}</div>
      )}
      {subGroupLine && (
        <div className="truncate text-xs text-muted-foreground">{subGroupLine}</div>
      )}
      {metaParts.length > 0 && (
        <div className="truncate text-[11px] text-muted-foreground">{metaParts.join(" · ")}</div>
      )}
    </div>
  );

  if (!link) {
    return body;
  }

  return (
    <InventoryLink
      inventoryId={product.inventory_id}
      description={displayName}
      className="block"
    >
      {body}
    </InventoryLink>
  );
}