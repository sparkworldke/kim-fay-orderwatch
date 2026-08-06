import type {
  BrandOwnership,
  InventoryItem,
  MonthlySales,
  ReplenishmentEvent,
  Site,
  WarehouseStock,
} from "@/types/Stock/inventory";
import type { SalesRecord } from "@/types/Stock/sales";
import { CHANNELS } from "./channels";
import type { ProductSeed } from "./catalog";
import { TIMELINE } from "./months";
import { makeRandom } from "./random";
import { WAREHOUSES } from "./warehouses";

/** Deliberate edge-case scenarios injected at fixed indexes for demo coverage. */
type Scenario =
  | "normal"
  | "zero-stock"
  | "negative-stock"
  | "below-msi"
  | "thin-cover"
  | "over-stocked"
  | "fast-growing"
  | "declining"
  | "seasonal"
  | "no-recent-sales";

const SCENARIOS: Scenario[] = [
  "normal",
  "fast-growing",
  "below-msi",
  "over-stocked",
  "seasonal",
  "thin-cover",
  "declining",
  "normal",
  "zero-stock",
  "normal",
  "negative-stock",
  "over-stocked",
  "no-recent-sales",
  "normal",
  "seasonal",
  "below-msi",
  "fast-growing",
  "normal",
  "thin-cover",
  "declining",
];

const round = (n: number) => Math.round(n);

const FGS_TRANSFER_DEMO_IDS = new Set([
  "PB-001",
  "PB-002",
  "PB-003",
  "PB-004",
  "PB-005",
  "PB-006",
  "PB-007",
  "PB-008",
  "PB-010",
  "PB-012",
]);

function buildMonthlySales(seed: string, base: number, scenario: Scenario): MonthlySales[] {
  const rnd = makeRandom(`sales-${seed}`);
  return TIMELINE.map((m, i) => {
    const progress = i / (TIMELINE.length - 1);
    let trend = 1;
    if (scenario === "fast-growing") trend = 0.6 + progress * 0.95;
    else if (scenario === "declining") trend = 1.3 - progress * 0.55;
    else trend = 0.85 + progress * 0.3;

    const seasonal =
      scenario === "seasonal"
        ? 1 + 0.45 * Math.sin(((m.monthIndex - 3) / 12) * Math.PI * 2)
        : 1 + 0.08 * Math.sin(((m.monthIndex - 1) / 12) * Math.PI * 2);

    const noise = 0.86 + rnd() * 0.3;
    const spike = rnd() > 0.94 ? 1.55 : 1;
    let qty = base * trend * seasonal * noise * spike;
    if (scenario === "no-recent-sales" && i >= TIMELINE.length - 4) qty = 0;
    return {
      month: m.label,
      monthIndex: m.monthIndex,
      year: m.year,
      quantity: round(Math.max(0, qty)),
      stockAvailable: 0,
    };
  });
}

function buildWarehouseStocks(
  seed: string,
  total: number,
  allowedSites: Site[],
  count: number,
): WarehouseStock[] {
  const rnd = makeRandom(`wh-${seed}`);
  const pool = WAREHOUSES.filter((w) => allowedSites.includes(w.site));
  const chosen: typeof pool = [];
  const copy = [...pool];
  for (let i = 0; i < Math.min(count, copy.length); i++) {
    const idx = Math.floor(rnd() * copy.length);
    chosen.push(copy.splice(idx, 1)[0]);
  }
  const weights = chosen.map(() => 0.2 + rnd());
  const weightSum = weights.reduce((a, b) => a + b, 0);
  return chosen.map((w, i) => {
    const available = round((total * weights[i]) / weightSum);
    const allocated = round(Math.abs(available) * (0.02 + rnd() * 0.12));
    return {
      warehouseId: w.warehouseId,
      warehouseName: w.warehouseName,
      site: w.site,
      qtyOnHand: available + allocated,
      qtyAllocated: allocated,
      qtyAvailable: available,
    };
  });
}

function buildReplenishments(seed: string, base: number): ReplenishmentEvent[] {
  const rnd = makeRandom(`rep-${seed}`);
  const months = TIMELINE.slice(-12);
  const events: ReplenishmentEvent[] = [];
  months.forEach((m) => {
    if (rnd() > 0.62) {
      events.push({
        date: `${m.year}-${String(m.monthIndex).padStart(2, "0")}-15`,
        quantity: round(base * (1.2 + rnd())),
        eventType: rnd() > 0.7 ? "production" : "replenishment",
      });
    }
  });
  return events;
}

function buildItems(
  seeds: ProductSeed[],
  ownership: BrandOwnership,
  prefix: string,
): InventoryItem[] {
  return seeds.map((seed, index) => {
    const inventoryId = `${prefix}-${String(index + 1).padStart(3, "0")}`;
    const rnd = makeRandom(`item-${inventoryId}`);
    const scenario = SCENARIOS[index % SCENARIOS.length];
    const base = round(600 + rnd() * 5200);
    const monthlySales = buildMonthlySales(inventoryId, base, scenario);

    const recentThree = monthlySales.slice(-4, -1);
    const runRate = recentThree.reduce((a, m) => a + m.quantity, 0) / 3;
    const msi = round(Math.max(120, runRate * (1.4 + rnd() * 1.1)));
    const safetyStock = round(msi * 0.45);
    const bufferStock = round(msi * 0.75);

    let coverMultiplier = 1.2 + rnd() * 1.6;
    if (scenario === "zero-stock") coverMultiplier = 0;
    else if (scenario === "negative-stock") coverMultiplier = -0.08;
    else if (scenario === "below-msi") coverMultiplier = 0.55 + rnd() * 0.35;
    else if (scenario === "thin-cover") coverMultiplier = 0.28 + rnd() * 0.2;
    else if (scenario === "over-stocked") coverMultiplier = 3.4 + rnd() * 1.6;
    else if (scenario === "no-recent-sales") coverMultiplier = 2.5;

    const totalAvailable = round(Math.max(runRate, base) * coverMultiplier);
    const allowedSites: Site[] =
      ownership === "manufactured" ? [seed.site ?? "HQ"] : ["HQ", "Tatu"];
    const warehouseStocks = buildWarehouseStocks(
      inventoryId,
      totalAvailable,
      allowedSites,
      ownership === "manufactured" ? 2 + Math.floor(rnd() * 2) : 3 + Math.floor(rnd() * 3),
    );

    // Fixed demo case for the FGS transfer notification.
    if (FGS_TRANSFER_DEMO_IDS.has(inventoryId)) {
      const fgsDefinition = WAREHOUSES.find((warehouse) => warehouse.warehouseId === "WH-HQ-FGS");
      const existingFgs = warehouseStocks.find((stock) => stock.warehouseId === "WH-HQ-FGS");

      if (existingFgs) {
        existingFgs.qtyOnHand = 0;
        existingFgs.qtyAllocated = 0;
        existingFgs.qtyAvailable = 0;
      } else if (fgsDefinition) {
        warehouseStocks.unshift({
          ...fgsDefinition,
          qtyOnHand: 0,
          qtyAllocated: 0,
          qtyAvailable: 0,
        });
      }
    }

    // Back-fill month-end stock so the trend chart is consistent with sales.
    const actualAvailable = warehouseStocks.reduce((a, w) => a + w.qtyAvailable, 0);
    let running = actualAvailable;
    for (let i = monthlySales.length - 1; i >= 0; i--) {
      monthlySales[i].stockAvailable = round(Math.max(0, running));
      running = running + monthlySales[i].quantity * (0.75 + (i % 5) * 0.1) - runRate * 0.9;
    }

    return {
      inventoryId,
      productName: seed.productName,
      brand: seed.brand,
      category: seed.category,
      brandOwnership: ownership,
      businessLine: seed.businessLine,
      site: seed.site,
      machine: seed.machine,
      warehouseStocks,
      safetyStock,
      bufferStock,
      msi,
      exportRequirement: ownership === "manufactured" ? round(msi * 0.15) : undefined,
      msiExport: ownership === "manufactured" ? round(msi * 0.2) : undefined,
      monthlySales,
      replenishmentEvents: buildReplenishments(inventoryId, base),
    } satisfies InventoryItem;
  });
}

export function buildInventory(seeds: ProductSeed[], ownership: BrandOwnership, prefix: string) {
  return buildItems(seeds, ownership, prefix);
}

export function buildSalesRecords(items: InventoryItem[]): SalesRecord[] {
  const records: SalesRecord[] = [];
  let counter = 0;
  items.forEach((item) => {
    const rnd = makeRandom(`rec-${item.inventoryId}`);
    const channelCount = 2 + Math.floor(rnd() * 2);
    const channels: string[] = [];
    while (channels.length < channelCount) {
      const c =
        item.businessLine === "Kim-Fay Professional" && channels.length === 0
          ? "Kim-Fay Professional"
          : CHANNELS[Math.floor(rnd() * CHANNELS.length)];
      if (!channels.includes(c)) channels.push(c);
    }
    const weights = channels.map(() => 0.3 + rnd());
    const weightSum = weights.reduce((a, b) => a + b, 0);
    const whIds = item.warehouseStocks.map((w) => w.warehouseId);

    item.monthlySales.forEach((m) => {
      channels.forEach((channel, ci) => {
        const qty = Math.round((m.quantity * weights[ci]) / weightSum);
        if (qty <= 0) return;
        counter += 1;
        records.push({
          salesId: `SL-${String(counter).padStart(5, "0")}`,
          inventoryId: item.inventoryId,
          productName: item.productName,
          brand: item.brand,
          category: item.category,
          brandOwnership: item.brandOwnership,
          businessLine: item.businessLine,
          channel,
          warehouseId: whIds[ci % whIds.length],
          month: m.month,
          monthIndex: m.monthIndex,
          year: m.year,
          quantity: qty,
        });
      });
    });
  });
  return records;
}
