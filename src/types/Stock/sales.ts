import type { BrandOwnership, BusinessLine } from "./inventory";

export interface SalesRecord {
  salesId: string;
  inventoryId: string;
  productName: string;
  brand: string;
  category: string;
  brandOwnership: BrandOwnership;
  businessLine: BusinessLine | "";
  channel: string;
  warehouseId: string;
  month: string;
  monthIndex: number;
  year: number;
  quantity: number;
  orderedQuantity?: number;
  shippedQuantity?: number;
}

export type TrendStatus = "growing" | "stable" | "declining";

export interface SalesRow {
  salesId: string;
  inventoryId: string;
  productName: string;
  brand: string;
  category: string;
  brandOwnership: BrandOwnership;
  businessLine: BusinessLine | "";
  periodSales: number;
  currentMonthSales: number;
  averageMonthlySales: number;
  priorPeriodSales: number;
  growth: number;
  contribution: number;
  trendStatus: TrendStatus;
}

export interface MonthlyPoint {
  month: string;
  monthIndex: number;
  year: number;
  current: number;
  prior: number;
}
