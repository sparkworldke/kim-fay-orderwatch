import { apiFetch } from "@/lib/api";
import type { InventoryItem, MonthlySales } from "@/types/Stock/inventory";

export type TransferRequestEmailLine = {
  inventory_id: string;
  product_name: string;
  brand: string;
  source_warehouse: string;
  quantity: number;
  sources?: Array<{
    warehouse_name: string;
    qty_on_hand: number;
    qty_available: number;
  }>;
};

export type TransferRequestEmailPayload = {
  recipients: string[];
  note?: string;
  requests: TransferRequestEmailLine[];
};

export type TransferRequestEmailResult = {
  message: string;
  recipients: string[];
  request_count: number;
};

type ApiWarehouse = {
  warehouse_id: string;
  warehouse_name: string;
  qty_on_hand: number | null;
  qty_available: number | null;
  qty_allocated: number | null;
};

type ApiMonthly = {
  month: string;
  ordered_qty: number;
  shipped_qty: number;
  missed_qty: number;
  missed_revenue: number | null;
  revenue_complete: boolean;
  currency_id: string | null;
};

type ApiInventory = {
  inventory_id: string;
  product_name: string | null;
  brand: string | null;
  category: string | null;
  trading_group: string | null;
  portfolio_group: string | null;
  ownership: "manufactured" | "partner" | null;
  business_line: "Consumer Sales" | "Kim-Fay Professional" | null;
  site: "HQ" | "Tatu" | null;
  machine: string | null;
  machines: string[];
  msi: number | null;
  safety_stock: number | null;
  buffer_stock: number | null;
  export_requirement: number | null;
  export_msi: number | null;
  stock_basis: "available" | "on_hand_fallback";
  stock_complete: boolean;
  missing_available_warehouses: string[];
  warehouse_stocks: ApiWarehouse[];
  monthly_sales: ApiMonthly[];
  plan_id: number | null;
};

type InventoryResponse = {
  data: ApiInventory[];
  meta: { current_page: number; last_page: number; total: number; freshness?: Record<string, string | null> };
};

const toMonthlySales = (rows: ApiMonthly[] | null | undefined): MonthlySales[] => {
  if (!rows?.length) return [];
  const grouped = new Map<string, {
    ordered: number;
    shipped: number;
    missed: number;
    revenue: number | null;
    revenueComplete: boolean;
    currencyId: string;
  }>();
  rows.forEach((row) => {
    // Backend groups by YYYY-MM; skip malformed points so mapping never throws.
    if (!row?.month || typeof row.month !== "string") return;
    const current = grouped.get(row.month) ?? {
      ordered: 0, shipped: 0, missed: 0, revenue: null, revenueComplete: true, currencyId: "KES",
    };
    grouped.set(row.month, {
      ordered: current.ordered + Number(row.ordered_qty ?? 0),
      shipped: current.shipped + Number(row.shipped_qty ?? 0),
      missed: current.missed + Number(row.missed_qty ?? 0),
      revenue: row.missed_revenue == null
        ? current.revenue
        : (current.revenue ?? 0) + Number(row.missed_revenue),
      revenueComplete: current.revenueComplete && row.revenue_complete !== false,
      currencyId: row.currency_id ?? current.currencyId,
    });
  });
  return [...grouped.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([key, totals]) => {
      const [year, monthIndex] = key.split("-").map(Number);
      if (!Number.isFinite(year) || !Number.isFinite(monthIndex)) {
        return {
          month: key,
          monthIndex: 0,
          year: 0,
          orderedQuantity: totals.ordered,
          quantity: totals.shipped,
          missedOpportunityQuantity: totals.missed,
          missedOpportunityRevenue: totals.revenue ?? Number.NaN,
          missedRevenueComplete: totals.revenueComplete,
          currencyId: totals.currencyId,
          // Historical stock-on-hand is not on this endpoint; omit stock annotations/line.
          stockAvailable: Number.NaN,
        };
      }
      return {
        month: new Date(year, monthIndex - 1).toLocaleString("en", { month: "short", year: "2-digit" }),
        monthIndex,
        year,
        orderedQuantity: totals.ordered,
        quantity: totals.shipped,
        missedOpportunityQuantity: totals.missed,
        missedOpportunityRevenue: totals.revenue ?? Number.NaN,
        missedRevenueComplete: totals.revenueComplete,
        currencyId: totals.currencyId,
        stockAvailable: Number.NaN,
      };
    });
};

const mapItem = (row: ApiInventory): InventoryItem => ({
  inventoryId: row.inventory_id,
  productName: row.product_name ?? row.inventory_id,
  brand: row.brand ?? "",
  category: row.category ?? "",
  tradingGroup: row.trading_group ?? "",
  portfolioGroup: row.portfolio_group ?? "",
  brandOwnership: row.ownership ?? "manufactured",
  businessLine: row.business_line ?? "",
  site: row.site ?? undefined,
  machine: row.machine ?? undefined,
  machines: row.machines ?? (row.machine ? [row.machine] : []),
  warehouseStocks: (row.warehouse_stocks ?? []).map((stock) => ({
    warehouseId: stock.warehouse_id,
    warehouseName: stock.warehouse_name ?? stock.warehouse_id,
    site: row.site ?? "HQ",
    qtyOnHand: Number(stock.qty_on_hand ?? 0),
    qtyAllocated: Number(stock.qty_allocated ?? 0),
    qtyAvailable: Number(stock.qty_available ?? stock.qty_on_hand ?? 0),
    qtyAvailableMissing: stock.qty_available == null,
  })),
  safetyStock: Number(row.safety_stock ?? 0),
  safetyStockConfigured: row.safety_stock != null,
  bufferStock: Number(row.buffer_stock ?? 0),
  bufferStockConfigured: row.buffer_stock != null,
  msi: Number(row.msi ?? 0),
  msiConfigured: row.msi != null,
  exportRequirement: row.export_requirement ?? undefined,
  msiExport: row.export_msi ?? undefined,
  monthlySales: toMonthlySales(row.monthly_sales),
  stockBasis: row.stock_basis,
  stockComplete: row.stock_complete,
  missingAvailableWarehouses: row.missing_available_warehouses ?? [],
  planId: row.plan_id ?? undefined,
});

export type InventoryPage = {
  items: InventoryItem[];
  page: number;
  lastPage: number;
  total: number;
  freshness?: Record<string, string | null>;
};

async function getInventoryPage(ownership: "manufactured" | "partner", page = 1): Promise<InventoryPage> {
  const response = await apiFetch<InventoryResponse>(
    `operations/production/inventory?ownership=${ownership}&per_page=75&page=${page}`,
  );
  return {
    items: (response.data ?? []).map(mapItem),
    page: response.meta.current_page,
    lastPage: response.meta.last_page,
    total: response.meta.total,
    freshness: response.meta.freshness,
  };
}

async function getInventory(ownership: "manufactured" | "partner"): Promise<InventoryItem[]> {
  return (await getInventoryPage(ownership)).items;
}

async function sendTransferRequestEmail(
  payload: TransferRequestEmailPayload,
): Promise<TransferRequestEmailResult> {
  return apiFetch<TransferRequestEmailResult>("operations/production/transfer-requests/email", {
    method: "POST",
    body: payload,
  });
}

export const inventoryKeys = {
  manufactured: ["inventory", "production", "manufactured"] as const,
  partner: ["inventory", "production", "partner"] as const,
};

export const inventoryService = {
  getManufacturedInventory: () => getInventory("manufactured"),
  getPartnerInventory: () => getInventory("partner"),
  getInventoryPage,
  sendTransferRequestEmail,
};
