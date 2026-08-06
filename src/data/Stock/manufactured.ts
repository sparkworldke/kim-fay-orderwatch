import { MANUFACTURED_SEEDS } from "./catalog";
import { buildInventory } from "./generate";

export const MANUFACTURED_INVENTORY = buildInventory(
  MANUFACTURED_SEEDS,
  "manufactured",
  "INV",
);