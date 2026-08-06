import { PARTNER_SEEDS } from "./catalog";
import { buildInventory } from "./generate";

export const PARTNER_INVENTORY = buildInventory(PARTNER_SEEDS, "partner", "PB");