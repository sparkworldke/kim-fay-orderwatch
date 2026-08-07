import type { InventoryItem } from "@/types/Stock/inventory";

const normalize = (value: string) => value.toUpperCase().split(/[^A-Z0-9]+/).filter(Boolean);

type WarehouseStock = InventoryItem["warehouseStocks"][number];

export const isRawMaterialsWarehouse = (warehouseId: string, warehouseName = "") =>
  [...normalize(warehouseId), ...normalize(warehouseName)].some((token) => token.includes("RMS"));

export const isFinishedGoodsWarehouse = (warehouseId: string, warehouseName = "") =>
  !isRawMaterialsWarehouse(warehouseId, warehouseName);

/** MSA quantities remain visible in reporting, but MSA cannot supply FGS transfers. */
export const isMsaWarehouse = (warehouseId: string, warehouseName = "") =>
  [...normalize(warehouseId), ...normalize(warehouseName)].some((token) => token.startsWith("MSA"));

export const isPrimaryFgsWarehouse = (warehouseId: string, warehouseName = "") => {
  const idParts = normalize(warehouseId);
  const nameParts = normalize(warehouseName);
  return idParts.at(-1) === "FGS" || nameParts.at(-1) === "FGS";
};

export interface TransferRecommendation {
  sourceWarehouse: string;
  quantity: number;
}

export const isEligibleFgsTransferSource = (stock: WarehouseStock) =>
  !isPrimaryFgsWarehouse(stock.warehouseId, stock.warehouseName) &&
  !isMsaWarehouse(stock.warehouseId, stock.warehouseName) &&
  isFinishedGoodsWarehouse(stock.warehouseId, stock.warehouseName) &&
  (stock.qtyAvailable > 0 || stock.qtyOnHand > 0);

export function getFgsTransferRecommendation(
  item: InventoryItem,
): TransferRecommendation | null {
  const fgs = item.warehouseStocks.find((stock) =>
    isPrimaryFgsWarehouse(stock.warehouseId, stock.warehouseName),
  );
  if (fgs && (fgs.qtyAvailable > 0 || fgs.qtyOnHand > 0)) return null;

  const source = item.warehouseStocks
    .filter(isEligibleFgsTransferSource)
    .sort((a, b) => Math.max(b.qtyAvailable, b.qtyOnHand) - Math.max(a.qtyAvailable, a.qtyOnHand))[0];

  if (!source) return null;

  return {
    sourceWarehouse: source.warehouseName,
    quantity: Math.min(Math.max(source.qtyAvailable, source.qtyOnHand), Math.max(1, item.msi)),
  };
}
