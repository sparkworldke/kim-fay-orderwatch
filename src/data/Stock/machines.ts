import type { Site } from "@/types/Stock/inventory";

const alphabetically = (values: string[]) =>
  [...values].sort((a, b) =>
    a.localeCompare(b, "en", { sensitivity: "base", numeric: true }),
  );

export const TATU_MACHINES = alphabetically([
  "4 DECK",
  "New TP Continuous",
  "OLD TP",
  "PERINI",
]);

export const HQ_MACHINES = alphabetically([
  "2 DECK",
  "4 DECK",
  "Box Packing",
  "COCKTAIL",
  "CONTINUOUS SEALING",
  "DINNER",
  "MANUAL",
  "NEW HANDTOWEL M/C",
  "NEW FOIL",
  "New TP Continuous",
  "New TP Start Stop",
  "OLD FOIL",
  "OLD TP",
  "PERINI",
  "POCKET PACK",
  "PRINTING M/C",
  "ROTARY",
  "Sanitizer Line",
  "SCOURING PAD M/C",
  "START STOP",
  "V Fold",
  "Wipes One",
  "Wipes Two",
]);

export const machinesForSites = (sites: Site[]): string[] => {
  const set = new Set<string>();
  if (sites.includes("HQ")) HQ_MACHINES.forEach((m) => set.add(m));
  if (sites.includes("Tatu")) TATU_MACHINES.forEach((m) => set.add(m));
  return alphabetically([...set]);
};
