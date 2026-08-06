import { useQuery } from "@tanstack/react-query";
import { BarChart3, Boxes } from "lucide-react";
import { useEffect, useMemo } from "react";
import { MultiSelect } from "@/components/ui/multi-select";
import { SegmentedControl } from "@/components/production/SegmentedControl";
import { usePersistentState } from "@/hooks/usePersistentState";
import { useProductionInventory } from "@/hooks/useProductionInventory";
import type { PartnerFilters } from "@/types/Stock/filters";
import type { InventoryItem, StockStatus } from "@/types/Stock/inventory";
import { reconcileSelection } from "@/utils/Stock/filters";
import { ALL_STATUSES, STATUS_LABEL } from "@/utils/Stock/status";
import { StockInventoryDashboard } from "./StockInventoryDashboard";
import { useProductionSummary } from "@/hooks/useProductionSummary";

const EMPTY_INVENTORY: InventoryItem[] = [];

const DEFAULTS: PartnerFilters = {
  view: "Stocks",
  businessLines: ["Consumer Sales", "Kim-Fay Professional"],
  warehouses: [],
  brands: [],
  categories: [],
  tradingGroups: [],
  statuses: [...ALL_STATUSES],
  search: "",
};

export function StockPartnerIntelligencePage({ resetToken }: { resetToken: number }) {
  const inventory = useProductionInventory("partner");
  const items = inventory.items.length ? inventory.items : EMPTY_INVENTORY;
  const isLoading = inventory.isLoading;

  const { value: filters, setValue, reset } = usePersistentState<PartnerFilters>(
    "partner-intelligence-filters",
    DEFAULTS,
  );

  useEffect(() => {
    if (resetToken > 0) reset();
  }, [resetToken, reset]);

  const validCategories = useMemo(
    () =>
      [...new Set(
        items
          .filter((i) => filters.brands.includes(i.brand) && (!i.businessLine || filters.businessLines.includes(i.businessLine)))
          .map((i) => i.category),
      )].sort(),
    [items, filters.brands, filters.businessLines],
  );
  const validBrands = useMemo(() => [...new Set(items.map((item) => item.brand).filter(Boolean))].sort(), [items]);
  const validTradingGroups = useMemo(() => [...new Set(items.map((item) => item.tradingGroup).filter(Boolean))].sort(), [items]);
  const warehouseOptions = useMemo(() =>
    [...new Set(items.flatMap((item) => item.warehouseStocks.map((stock) => stock.warehouseName)))].sort(),
    [items],
  );

  useEffect(() => {
    setValue((prev) => {
      const categories = prev.categories.length
        ? reconcileSelection(prev.categories, validCategories)
        : validCategories;
      const brands = prev.brands.length ? reconcileSelection(prev.brands, validBrands) : validBrands;
      const warehouses = prev.warehouses.length ? reconcileSelection(prev.warehouses, warehouseOptions) : warehouseOptions;
      const tradingGroups = prev.tradingGroups?.length ? reconcileSelection(prev.tradingGroups, validTradingGroups) : validTradingGroups;
      return categories === prev.categories && brands === prev.brands && warehouses === prev.warehouses && tradingGroups === prev.tradingGroups
        ? prev : { ...prev, categories, brands, warehouses, tradingGroups };
    });
  }, [validCategories, validBrands, validTradingGroups, warehouseOptions, setValue]);

  const warehouseIds = useMemo(
    () => [...new Set(items.flatMap((item) => item.warehouseStocks)
      .filter((w) => filters.warehouses.includes(w.warehouseName)).map((w) => w.warehouseId))],
    [items, filters.warehouses],
  );
  const summary = useProductionSummary({
    ownership: "partner",
    warehouseIds,
    brands: filters.brands,
    categories: filters.categories,
    tradingGroups: filters.tradingGroups,
    businessLines: filters.businessLines,
    statuses: filters.statuses,
    search: filters.search,
  });

  const filtered = useMemo(
    () =>
      items.filter(
        (i) =>
          (!i.businessLine || filters.businessLines.includes(i.businessLine)) &&
          // Empty taxonomy fields are not present in MultiSelect options — still show those SKUs.
          (!i.brand || filters.brands.includes(i.brand)) &&
          (!i.category || filters.categories.includes(i.category)) &&
          (!i.tradingGroup || filters.tradingGroups.includes(i.tradingGroup)),
      ),
    [items, filters],
  );

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-2">
      <StockInventoryDashboard
        pageTitle="Partner / Trading Intel"
        pageSubtitle="Partner (trading) brands — inventory, warehouse availability and demand visibility"
        items={filtered}
        warehouseIds={warehouseIds}
        statuses={filters.statuses}
        search={filters.search}
        onSearchChange={(search) => setValue((p) => ({ ...p, search }))}
        requirementLabel="Replenishment Requirement"
        showMachine={false}
        showBusinessLineColumn
        allowMsiEdit={false}
        isLoading={isLoading}
        serverSummary={summary.data}
        tableTitle="Inventory Overview (Cumulative Across Selected Warehouses)"
        filters={
          <div className="flex flex-col gap-4">
            <SegmentedControl
              label="View"
              value={filters.view}
              onChange={(view) => setValue((p) => ({ ...p, view }))}
              options={[
                { value: "Stocks", label: "Stocks", icon: Boxes },
                { value: "Sales", label: "Sales", icon: BarChart3 },
              ]}
            />
            <MultiSelect label="Business Line" options={["Consumer Sales", "Kim-Fay Professional"]} selected={filters.businessLines} onChange={(v) => setValue((p) => ({ ...p, businessLines: v as PartnerFilters["businessLines"] }))} allLabel="All Lines" />
            <MultiSelect label="Warehouse" hint="(Multi-select)" options={warehouseOptions} selected={filters.warehouses} onChange={(warehouses) => setValue((p) => ({ ...p, warehouses }))} allLabel="All Warehouses" />
            <MultiSelect label="Select Brands" hint="(Multi-select)" options={validBrands} selected={filters.brands} onChange={(brands) => setValue((p) => ({ ...p, brands }))} allLabel="All Brands" />
            <MultiSelect label="Product Category" options={validCategories} selected={filters.categories} onChange={(categories) => setValue((p) => ({ ...p, categories }))} allLabel="All Categories" />
            <MultiSelect label="Trading Group" options={validTradingGroups} selected={filters.tradingGroups} onChange={(tradingGroups) => setValue((p) => ({ ...p, tradingGroups }))} allLabel="All Trading Groups" />
            <MultiSelect
              label="Status"
              options={ALL_STATUSES.map((s) => STATUS_LABEL[s])}
              selected={filters.statuses.map((s) => STATUS_LABEL[s])}
              onChange={(labels) =>
                setValue((p) => ({
                  ...p,
                  statuses: ALL_STATUSES.filter((s) => labels.includes(STATUS_LABEL[s])) as StockStatus[],
                }))
              }
              allLabel="All Statuses"
            />
          </div>
        }
      />
    </div>
  );
}
