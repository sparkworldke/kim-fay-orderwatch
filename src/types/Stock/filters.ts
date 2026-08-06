import type { BrandOwnership, BusinessLine, Site, StockStatus } from "./inventory";

export type BusinessView = "Production" | "Sales";
export type PartnerView = "Stocks" | "Sales";
export type OwnershipView = "manufactured" | "partner" | "combined";
export type SalesPeriod = "YTD 2026" | "Last 12 Months" | "2025" | "2024";

export interface ProductionFilters {
  sites: Site[];
  businessView: BusinessView;
  businessLines: BusinessLine[];
  brands: string[];
  categories: string[];
  tradingGroups: string[];
  machines: string[];
  warehouses: string[];
  statuses: StockStatus[];
  search: string;
}

export interface PartnerFilters {
  view: PartnerView;
  businessLines: BusinessLine[];
  warehouses: string[];
  brands: string[];
  categories: string[];
  tradingGroups: string[];
  statuses: StockStatus[];
  search: string;
}

export interface SalesFilters {
  ownership: OwnershipView;
  businessLines: BusinessLine[];
  brands: string[];
  categories: string[];
  warehouses: string[];
  period: SalesPeriod;
  channels: string[];
  search: string;
}

export type { BrandOwnership, BusinessLine, Site, StockStatus };
