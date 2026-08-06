import type { Site } from "@/types/Stock/inventory";

export interface WarehouseDef {
  warehouseId: string;
  warehouseName: string;
  site: Site;
}

export const WAREHOUSES: WarehouseDef[] = [
  { warehouseId: "WH-HQ-FGS", warehouseName: "HQ FGS", site: "HQ" },
  { warehouseId: "WH-HQ-DTC", warehouseName: "HQ DTC", site: "HQ" },
  { warehouseId: "WH-HQ-EXP", warehouseName: "HQ Export", site: "HQ" },
  { warehouseId: "WH-HQ-FGS3", warehouseName: "HQ FGS3", site: "HQ" },
  { warehouseId: "WH-HQ-PFGS", warehouseName: "HQ PFGS", site: "HQ" },
  { warehouseId: "WH-HQ-MAIN", warehouseName: "HQ Main Warehouse", site: "HQ" },
  { warehouseId: "WH-HQ-PROF", warehouseName: "HQ Professional Warehouse", site: "HQ" },
  { warehouseId: "WH-TT-FGS", warehouseName: "Tatu FGS", site: "Tatu" },
  { warehouseId: "WH-TT-MAIN", warehouseName: "Tatu Main Warehouse", site: "Tatu" },
  { warehouseId: "WH-TT-RAW", warehouseName: "Tatu Raw Materials", site: "Tatu" },
  { warehouseId: "WH-TT-FG", warehouseName: "Tatu Finished Goods", site: "Tatu" },
  { warehouseId: "WH-TT-DIS", warehouseName: "Tatu Dispatch", site: "Tatu" },
];

export const warehousesForSites = (sites: Site[]) =>
  WAREHOUSES.filter((w) => sites.includes(w.site));

export const warehouseName = (id: string) =>
  WAREHOUSES.find((w) => w.warehouseId === id)?.warehouseName ?? id;