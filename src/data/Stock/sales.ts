import { buildSalesRecords } from "./generate";
import { MANUFACTURED_INVENTORY } from "./manufactured";
import { PARTNER_INVENTORY } from "./partners";

export const ALL_INVENTORY = [...MANUFACTURED_INVENTORY, ...PARTNER_INVENTORY];

export const SALES_RECORDS = buildSalesRecords(ALL_INVENTORY);