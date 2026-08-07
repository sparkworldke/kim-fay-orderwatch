import { useQuery } from "@tanstack/react-query";
import { BarChart3, Factory } from "lucide-react";
import { useEffect, useMemo } from "react";
import { MultiSelect } from "@/components/ui/multi-select";
import { SegmentedControl } from "@/components/production/SegmentedControl";
import { machinesForSites } from "@/data/Stock/machines";
import { usePersistentState } from "@/hooks/usePersistentState";
import { useProductionInventory } from "@/hooks/useProductionInventory";
import type { ProductionFilters } from "@/types/Stock/filters";
import type { InventoryItem, Site, StockStatus } from "@/types/Stock/inventory";
import { reconcileSelection } from "@/utils/Stock/filters";
import { ALL_STATUSES, STATUS_LABEL } from "@/utils/Stock/status";
import { isFinishedGoodsWarehouse, isRawMaterialsWarehouse } from "@/utils/Stock/transferRecommendation";
import { StockInventoryDashboard } from "@/pages/StockInventoryDashboard";
import { ProductionPlanningManager } from "@/components/production/ProductionPlanningManager";
import { useCapabilities } from "@/hooks/useCapabilities";
import { useProductionSummary } from "@/hooks/useProductionSummary";
import { useProductionStockView } from "@/features/kimfay-production/stock-view-context";

const SITES: Site[] = ["HQ", "Tatu"];
const EMPTY_INVENTORY: InventoryItem[] = [];

const warehouseHasStock = (stock: { qtyOnHand: number; qtyAvailable: number }) =>
  Number(stock.qtyOnHand) > 0 || Number(stock.qtyAvailable) > 0;

const machineKey = (value: string) => value.trim().toUpperCase().replace(/\s+/g, " ");

const DEFAULTS: ProductionFilters = {
  sites: [...SITES],
  businessView: "Production",
  businessLines: ["Consumer Sales", "Kim-Fay Professional"],
  brands: [],
  categories: [],
  tradingGroups: [],
  machines: [],
  warehouses: [],
  statuses: [...ALL_STATUSES],
  search: "",
};

export function StockProductionIntelligencePage({ resetToken }: { resetToken: number }) {
  const { value: stockView } = useProductionStockView();
  const capabilities = useCapabilities();
  const inventory = useProductionInventory("manufactured");
  const items = inventory.items.length ? inventory.items : EMPTY_INVENTORY;
  const isLoading = inventory.isLoading;

  const { value: filters, setValue, reset } = usePersistentState<ProductionFilters>(
    // v4 starts every multi-select with all valid options. Users narrow the
    // dashboard by removing values, and their choices then persist locally.
    "production-intelligence-filters-v4",
    DEFAULTS,
  );

  useEffect(() => {
    if (resetToken > 0) reset();
  }, [resetToken, reset]);

  const validMachines = useMemo(() => machinesForSites(filters.sites), [filters.sites]);
  const warehouseInView = stockView === "finished-goods"
    ? isFinishedGoodsWarehouse
    : isRawMaterialsWarehouse;
  const validWarehouses = useMemo(() => {
    // Finished goods: every non-RMS warehouse that has stock somewhere.
    // Raw materials: every RMS warehouse that has stock.
    const names = new Set<string>();
    for (const item of items) {
      for (const stock of item.warehouseStocks) {
        if (!warehouseInView(stock.warehouseId, stock.warehouseName)) continue;
        if (!warehouseHasStock(stock)) continue;
        names.add(stock.warehouseName || stock.warehouseId);
      }
    }
    return [...names].sort();
  }, [items, stockView]);
  const defaultWarehouses = useMemo(() => {
    return validWarehouses;
  }, [validWarehouses]);
  const validBrands = useMemo(() => [...new Set(items.map((item) => item.brand).filter(Boolean))].sort(), [items]);
  const validCategories = useMemo(
    () =>
      [...new Set(
        items
          .filter(
            (i) =>
              (!filters.brands.length || filters.brands.includes(i.brand)) &&
              (!i.businessLine || filters.businessLines.includes(i.businessLine)) &&
              (!i.site || filters.sites.includes(i.site)),
          )
          .map((i) => i.category),
      )].sort(),
    [items, filters.brands, filters.businessLines, filters.sites],
  );
  const validTradingGroups = useMemo(() => [...new Set(items.map((item) => item.tradingGroup).filter(Boolean))].sort(), [items]);

  // Each stock view starts with all warehouses in that view selected. Users
  // can then remove warehouses they do not want included.
  useEffect(() => {
    setValue((prev) => ({ ...prev, warehouses: defaultWarehouses }));
    // eslint-disable-next-line react-hooks/exhaustive-deps -- intentional: stock view change resets warehouse multi-select to all-in-view
  }, [stockView]);

  // Auto-fill / reconcile dependent selections.
  useEffect(() => {
    setValue((prev) => {
      const machines = prev.machines.length ? reconcileSelection(prev.machines, validMachines) : validMachines;
      const reconciledWarehouses = reconcileSelection(prev.warehouses, validWarehouses);
      const warehouses = reconciledWarehouses.length ? reconciledWarehouses : defaultWarehouses;
      const brands = prev.brands.length ? reconcileSelection(prev.brands, validBrands) : validBrands;
      const categories = prev.categories.length ? reconcileSelection(prev.categories, validCategories) : validCategories;
      const tradingGroups = prev.tradingGroups?.length ? reconcileSelection(prev.tradingGroups, validTradingGroups) : validTradingGroups;
      if (
        machines === prev.machines &&
        warehouses === prev.warehouses &&
        categories === prev.categories &&
        brands === prev.brands &&
        tradingGroups === prev.tradingGroups
      )
        return prev;
      return { ...prev, machines, warehouses, categories, brands, tradingGroups };
    });
  }, [validMachines, validWarehouses, defaultWarehouses, validCategories, validBrands, validTradingGroups, setValue]);

  const warehouseIds = useMemo(
    () =>
      [...new Set(items.flatMap((item) => item.warehouseStocks)
        .filter((w) => filters.warehouses.includes(w.warehouseName))
        .map((w) => w.warehouseId))],
    [items, filters.warehouses],
  );
  const summary = useProductionSummary({
    ownership: "manufactured",
    warehouseIds,
    brands: filters.brands,
    categories: filters.categories,
    tradingGroups: filters.tradingGroups,
    sites: filters.sites,
    machines: filters.machines,
    businessLines: filters.businessLines,
    statuses: filters.statuses,
    search: filters.search,
  });

  const selectedMachineKeys = new Set(filters.machines.map(machineKey));
  const allValidMachineKeys = new Set(validMachines.map(machineKey));
  const allMachinesSelected =
    selectedMachineKeys.size === allValidMachineKeys.size &&
    [...allValidMachineKeys].every((machine) => selectedMachineKeys.has(machine));

  const filtered = useMemo(
    () =>
      items.filter(
        (i) =>
          i.warehouseStocks.some((stock) =>
            warehouseInView(stock.warehouseId, stock.warehouseName),
          ) &&
          (!i.site || filters.sites.includes(i.site)) &&
          (!i.businessLine || filters.businessLines.includes(i.businessLine)) &&
          // Empty taxonomy fields are not present in MultiSelect options — still show those SKUs.
          (!i.brand || filters.brands.includes(i.brand)) &&
          (!i.category || filters.categories.includes(i.category)) &&
          (!i.tradingGroup || filters.tradingGroups.includes(i.tradingGroup)) &&
          ((i.machines?.length || i.machine)
            ? (i.machines?.length ? i.machines : [i.machine!]).some((machine) => selectedMachineKeys.has(machineKey(machine)))
            : allMachinesSelected),
      ),
    [items, filters, stockView, validMachines],
  );

  const statusLabels = filters.statuses.map((s) => STATUS_LABEL[s]);

  return (
    <div className="flex min-h-0 flex-1 flex-col gap-2">
      <StockInventoryDashboard
        pageTitle=""
        pageSubtitle=""
        items={filtered}
        warehouseIds={warehouseIds}
        statuses={filters.statuses}
        search={filters.search}
        onSearchChange={(search) => setValue((p) => ({ ...p, search }))}
        requirementLabel="Production Requirement"
        showMachine
        allowMsiEdit={false}
        isLoading={isLoading}
        serverSummary={summary.data}
        tableTitle={stockView === "finished-goods"
          ? `Finished Goods Inventory (${filters.businessView})`
          : "Raw Materials Inventory (RMS Warehouses)"}
        enableTransferRequests={stockView === "finished-goods"}
        extraActions={
          capabilities.can_manage_production_planning
          || capabilities.permissions.includes("production.planning.manage")
          || ["c_suite", "executive"].includes(capabilities.org_level ?? "")
          || (["production", "stores"].includes(capabilities.department?.slug ?? "")
            && ["hod", "manager", "executive"].includes(capabilities.department_role))
            ? <ProductionPlanningManager items={items} />
            : null
        }
        filters={
          <div className="flex flex-col gap-4">
            <MultiSelect label="Site" options={SITES} selected={filters.sites} onChange={(sites) => setValue((p) => ({ ...p, sites: sites as Site[] }))} allLabel="All Sites" />
            <SegmentedControl
              label="Business View"
              value={filters.businessView}
              onChange={(businessView) => setValue((p) => ({ ...p, businessView }))}
              options={[
                { value: "Production", label: "Production", icon: Factory },
                { value: "Sales", label: "Sales", icon: BarChart3 },
              ]}
            />
            <MultiSelect label="Business Line" options={["Consumer Sales", "Kim-Fay Professional"]} selected={filters.businessLines} onChange={(v) => setValue((p) => ({ ...p, businessLines: v as ProductionFilters["businessLines"] }))} allLabel="All Lines" />
            <MultiSelect label="Brand" options={validBrands} selected={filters.brands} onChange={(brands) => setValue((p) => ({ ...p, brands }))} allLabel="All Brands" />
            <MultiSelect label="Product Category" options={validCategories} selected={filters.categories} onChange={(categories) => setValue((p) => ({ ...p, categories }))} allLabel="All Categories" />
            <MultiSelect label="Trading Group" options={validTradingGroups} selected={filters.tradingGroups} onChange={(tradingGroups) => setValue((p) => ({ ...p, tradingGroups }))} allLabel="All Trading Groups" />
            <MultiSelect label="Machine" hint="(Multi-select)" options={validMachines} selected={filters.machines} onChange={(machines) => setValue((p) => ({ ...p, machines }))} allLabel="All Machines" />
            <MultiSelect
              label="Warehouse"
              hint={stockView === "finished-goods" ? "(FGS default; add other FG stores as needed)" : "(RMS warehouses only)"}
              options={validWarehouses}
              selected={filters.warehouses}
              onChange={(warehouses) => setValue((p) => ({ ...p, warehouses }))}
              allLabel={stockView === "finished-goods" ? "All FG warehouses" : "All RMS warehouses"}
              emptyLabel={stockView === "finished-goods" ? "All FG warehouses" : "All RMS warehouses"}
            />
            <MultiSelect
              label="Status"
              options={ALL_STATUSES.map((s) => STATUS_LABEL[s])}
              selected={statusLabels}
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
