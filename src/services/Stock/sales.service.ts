import { apiFetch } from "@/lib/api";
import type { InventoryItem } from "@/types/Stock/inventory";
import type { SalesRecord } from "@/types/Stock/sales";

type ApiSalesRow = {
  inventory_id: string;
  product_name: string | null;
  brand: string | null;
  category: string | null;
  ownership: "manufactured" | "partner" | null;
  business_line: "Consumer Sales" | "Kim-Fay Professional" | null;
  warehouse_id: string | null;
  month: string;
  ordered_qty: number;
  shipped_qty: number;
};

export const salesKeys = {
  records: ["sales", "production", "records"] as const,
  catalog: ["sales", "production", "catalog"] as const,
};

export const salesService = {
  getSalesRecords: async (): Promise<SalesRecord[]> => {
    const from = new Date();
    from.setMonth(from.getMonth() - 42);
    const response = await apiFetch<{ data: ApiSalesRow[] }>(
      `operations/production/sales?date_from=${from.toISOString().slice(0, 10)}`,
    );
    return (response.data ?? [])
      .filter((row) => row?.month && typeof row.month === "string")
      .map((row, index) => {
        const [year, monthIndex] = row.month.split("-").map(Number);
        const shipped = Number(row.shipped_qty ?? 0);
        const safeYear = Number.isFinite(year) ? year : 0;
        const safeMonthIndex = Number.isFinite(monthIndex) ? monthIndex : 0;
        return {
          salesId: `${row.inventory_id}-${row.warehouse_id ?? "none"}-${row.month}-${index}`,
          inventoryId: row.inventory_id,
          productName: row.product_name ?? row.inventory_id,
          brand: row.brand ?? "",
          category: row.category ?? "",
          brandOwnership: row.ownership ?? "manufactured",
          businessLine: row.business_line ?? "",
          channel: "",
          warehouseId: row.warehouse_id ?? "",
          month: safeYear && safeMonthIndex
            ? new Date(safeYear, safeMonthIndex - 1).toLocaleString("en", { month: "short", year: "2-digit" })
            : row.month,
          monthIndex: safeMonthIndex,
          year: safeYear,
          quantity: shipped,
          orderedQuantity: Number(row.ordered_qty ?? 0),
          shippedQuantity: shipped,
        };
      });
  },
  getCatalog: async (): Promise<InventoryItem[]> => [],
};
