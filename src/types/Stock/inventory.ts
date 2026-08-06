export type BrandOwnership = "manufactured" | "partner";
export type BusinessLine = "Consumer Sales" | "Kim-Fay Professional";
export type Site = "HQ" | "Tatu";
export type StockStatus = "critical" | "at-risk" | "healthy";

export interface WarehouseStock {
  warehouseId: string;
  warehouseName: string;
  site: Site;
  qtyOnHand: number;
  qtyAllocated: number;
  qtyAvailable: number;
  qtyAvailableMissing?: boolean;
}

export interface MonthlySales {
  /** Short label e.g. "Jun 25" */
  month: string;
  /** 1-12 */
  monthIndex: number;
  year: number;
  /** Customer demand recorded on sales orders. */
  orderedQuantity: number;
  /** Fulfilled quantity shipped to customers. */
  quantity: number;
  /** Ordered demand that was not delivered during the month. */
  missedOpportunityQuantity: number;
  /** Value of missed demand; blank when no valid line price is available. */
  missedOpportunityRevenue: number;
  missedRevenueComplete: boolean;
  currencyId: string;
  /** month-end available stock, used by the demand & stock trend chart */
  stockAvailable: number;
}

export interface ReplenishmentEvent {
  date: string;
  quantity: number;
  eventType: "production" | "replenishment" | "adjustment";
}

export interface InventoryItem {
  inventoryId: string;
  productName: string;
  brand: string;
  category: string;
  tradingGroup: string;
  portfolioGroup: string;
  brandOwnership: BrandOwnership;
  businessLine: BusinessLine | "";
  site?: Site;
  machine?: string;
  machines?: string[];
  warehouseStocks: WarehouseStock[];
  safetyStock: number;
  safetyStockConfigured?: boolean;
  bufferStock: number;
  bufferStockConfigured?: boolean;
  msi: number;
  msiConfigured?: boolean;
  stockBasis?: "available" | "on_hand_fallback";
  stockComplete?: boolean;
  missingAvailableWarehouses?: string[];
  planId?: number;
  exportRequirement?: number;
  msiExport?: number;
  monthlySales: MonthlySales[];
  replenishmentEvents?: ReplenishmentEvent[];
}

/** Derived, filter-aware view of an inventory item. */
export interface InventoryMetrics {
  item: InventoryItem;
  inventoryId: string;
  productName: string;
  brand: string;
  category: string;
  tradingGroup: string;
  portfolioGroup: string;
  businessLine: BusinessLine | "";
  site?: Site;
  machine?: string;
  qtyAvailable: number;
  qtyOnHand: number;
  qtyAllocated: number;
  safetyStock: number;
  bufferStock: number;
  msi: number;
  msiConfigured: boolean;
  lastThreeMonths: MonthlySales[];
  lastThreeMonthsTotal: number;
  runRate: number;
  dailyRunRate: number;
  monthsOfCover: number;
  daysOfCover: number;
  msiStatus: StockStatus;
  coverageStatus: StockStatus;
  planningStatus: StockStatus;
  requirement: number;
}
