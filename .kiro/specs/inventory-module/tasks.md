# Implementation Plan: Inventory Module

## Overview

Implement the Inventory Module in three layers: database migrations → Laravel backend services and
controllers → React frontend components and hooks. Each layer builds on the previous. Testing
sub-tasks are placed close to their implementation counterparts so failures surface early.

The stack is: **Laravel (PHP) backend**, **React 19 / TypeScript** frontend, **Vitest + fast-check**
for frontend tests, **PHPUnit** for backend tests, **Playwright** for the E2E smoke test.

---

## Tasks

- [x] 1. Database migrations
  - [x] 1.1 Add new columns to `acumatica_inventory_items`
    - Create a Laravel migration that adds `item_status VARCHAR(50) NULL`, `last_cost DECIMAL(10,4) NULL`, `average_cost DECIMAL(10,4) NULL`, and `last_modified_at TIMESTAMP NULL` to `acumatica_inventory_items`
    - Run the migration and verify the schema with `php artisan migrate`
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

  - [x] 1.2 Create `inventory_sku_insights` table
    - Create a Laravel migration for the `inventory_sku_insights` table with columns: `id BIGINT PK AUTO_INCREMENT`, `inventory_id VARCHAR(50) INDEXED`, `date_from DATE`, `date_to DATE`, `ai_response JSON`, `ai_status VARCHAR(20)`, `data_gaps JSON NULL`, `generated_at TIMESTAMP`, `expires_at TIMESTAMP`
    - Add unique index on `(inventory_id, date_from, date_to)`
    - _Requirements: 3.1, 3.8_

- [x] 2. Update Acumatica sync to populate new fields
  - [x] 2.1 Extend the existing Acumatica inventory sync service
    - Locate the existing cron/sync service that writes to `acumatica_inventory_items`
    - Parse `ItemStatus.value`, `LastCost.value`, `AverageCost.value`, and `LastModified.value` from the StockItem JSON payload
    - Write parsed values to the four new columns added in task 1.1
    - Skip records where `InventoryID.value` is absent/empty and log a warning with the record index and raw JSON fragment
    - Normalise `DefaultWarehouseID.value` to uppercase before storing in `default_warehouse_id`
    - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6_

  - [ ]* 2.2 Write PHPUnit property test — Property 5: DefaultWarehouseID round-trip
    - **Property 5: DefaultWarehouseID parsing preserves value uppercased (round-trip)**
    - For any non-empty string `w`, parsing `{"value": w}` and reading back `default_warehouse_id` should equal `w.trim().toUpperCase()`
    - **Validates: Requirements 4.6**

  - [ ]* 2.3 Write PHPUnit property test — Property 6: StockItem field parsing round-trip
    - **Property 6: StockItem field parsing round-trip**
    - For any StockItem JSON with non-null `InventoryID.value`, `DefaultWarehouseID.value`, and `ItemClass.value`, parsing then re-serialising should produce identical typed values
    - **Validates: Requirements 4.1, 5.3**

- [x] 3. Backend — `InventorySkuDetailService` and controller
  - [x] 3.1 Create `InventorySkuDetailService`
    - Create `app/Services/InventorySkuDetailService.php`
    - Implement `getDetail(string $inventoryId, string $dateFrom, string $dateTo): array`
    - Query `acumatica_inventory_items` for the item row; return HTTP 404 if not found
    - Query `acumatica_sales_orders` joined with `acumatica_sales_order_lines` where `status = 'Completed'`, `order_type = 'SO'`, `inventory_id = $inventoryId`, and `order_date` within range
    - Implement `monthlySales()`: bucket `shipped_qty` by calendar month (`YYYY-MM`), sum within each month, return one row per month in the union of historical + prediction period
    - Compute prediction period via `predictionPeriod(from, to)`: `pred_from = to + 1 day`, `pred_to = to + 1 + (to - from)` days
    - Request LLM predictions for the prediction period months via the existing `AiConnectorService`
    - Validate that `date_to - date_from` is between 7 and 730 days; throw a 422-suitable exception otherwise
    - _Requirements: 2.4, 2.5, 2.6, 2.7_

  - [ ]* 3.2 Write PHPUnit unit test for `InventorySkuDetailService::monthlySales()`
    - Test specific month-boundary examples including lines that span month boundaries, empty sets, and single-line inputs
    - Test that the prediction period calculation is correct for 7-day and 730-day ranges
    - _Requirements: 2.5, 2.6_

  - [x] 3.3 Create `InventorySkuDetailController`
    - Create `app/Http/Controllers/InventorySkuDetailController.php`
    - Implement `show(Request $request, string $inventoryId)` method
    - Validate query params `date_from` and `date_to` (required, YYYY-MM-DD format, range 7–730 days)
    - Return 422 on invalid range, 404 if item not found, 200 with `SkuDetailResponse` JSON on success
    - Register route: `GET /api/operations/inventory/{inventoryId}/sku-detail`
    - _Requirements: 2.4, 5.2_

- [x] 4. Backend — `InventoryInsightService` and controller
  - [x] 4.1 Create `InventoryInsightService`
    - Create `app/Services/InventoryInsightService.php`
    - Implement `getInsights(string $inventoryId, string $dateFrom, string $dateTo): array`
    - Check `inventory_sku_insights` cache: if a non-expired row exists (`expires_at > now()`), return its `ai_response` immediately
    - Query Acumatica for Promotional_Events for the SKU in the analysis period; on failure append `"promotions"` to `data_gaps` and continue
    - Query Acumatica for price history for the SKU; on failure append `"price_history"` to `data_gaps` and continue
    - Implement `classifyPriceChange(oldPrice, newPrice)`: return `"upward pressure on demand"` if `newPrice < oldPrice`, `"downward pressure on demand"` if `newPrice > oldPrice`, no record if equal
    - Implement `buildInsightContext(variances, promotions, priceChanges)`: format combined context for the LLM prompt
    - Call `AiConnectorService` with a 30-second timeout; on timeout/failure set `ai_status: "failed"` and return empty `insights` array
    - Store result in `inventory_sku_insights` with `expires_at = generated_at + 4 hours`
    - Validate each finding's `type` is one of `"promotion_impact"`, `"price_change_impact"`, `"unexplained_variance"` before storing
    - _Requirements: 3.1, 3.2, 3.3, 3.5, 3.6, 3.7, 3.8, 3.11_

  - [ ]* 4.2 Write PHPUnit unit test for `InventoryInsightService::classifyPriceChange()`
    - Test price increase (downward pressure), decrease (upward pressure), and no change cases
    - _Requirements: 3.6_

  - [ ]* 4.3 Write PHPUnit unit test for `InventoryInsightService::buildInsightContext()`
    - Test with and without promotions, with and without price history data
    - _Requirements: 3.2, 3.3_

  - [x] 4.4 Create `InventoryInsightController`
    - Create `app/Http/Controllers/InventoryInsightController.php`
    - Implement `show(Request $request, string $inventoryId)` method
    - Validate `date_from` and `date_to`; check for abort signal before starting LLM call
    - Register route: `GET /api/operations/inventory/{inventoryId}/insights`
    - _Requirements: 3.1, 3.10_

- [x] 5. Checkpoint — backend complete
  - Ensure all backend unit tests pass (`php artisan test`), ask the user if questions arise.

- [x] 6. Backend integration tests
  - [x] 6.1 Write integration test for `/operations/inventory` endpoint
    - Use SQLite in-memory test database with factory-generated `AcumaticaInventoryItem` records
    - Assert response contains at least one item with non-null `inventory_id`, non-null `default_warehouse_id`, and `qty_on_hand >= 0`
    - _Requirements: 5.1_

  - [x] 6.2 Write integration test for SKU detail endpoint
    - Seed factory data spanning 12 calendar months of sales order lines
    - Call `GET /api/operations/inventory/{id}/sku-detail` with a 12-month date range
    - Assert `monthly_sales` array contains at least one entry with valid `month`, `shipped_qty >= 0`, `predicted_qty >= 0`
    - _Requirements: 5.2_

  - [x] 6.3 Write integration test for AI insight endpoint
    - Seed SKU with at least one month where shipped and predicted differ by > 20%
    - Call `GET /api/operations/inventory/{id}/insights` with `Http::fake()` mocking Acumatica
    - Assert response contains at least one finding with `type` in the valid enum set
    - _Requirements: 5.4_

  - [x] 6.4 Write integration test for promotion overlap insight
    - Provide a mocked Acumatica promotion whose start date falls in the same month as a positive-variance month
    - Assert insight output includes a finding of `type: "promotion_impact"` referencing the promotion name
    - _Requirements: 5.6_

- [x] 7. Frontend — pure utility functions
  - [x] 7.1 Implement and export utility functions in `src/utils/inventoryUtils.ts`
    - `deriveBand(itemClass: string | null): "A" | "B" | "C" | "D" | "Unclassified"` — split on `_`, match prefix
    - `predictionPeriod(from: Date, to: Date): { from: Date; to: Date }` — uses `date-fns` `differenceInDays` / `addDays`
    - `varianceIndicator(predicted: number, actual: number): "green" | "amber" | "none"`
    - `mapValuationMethod(value: string): string` — FIFO / Average / Standard mappings
    - `formatLastModified(iso: string | null | undefined, tz?: string): string` — EAT (UTC+3) format `DD MMM YYYY HH:mm`, returns "—" on invalid
    - `formatCost(value: number | null | undefined): string` — 2 dp with "KES " prefix, returns "—" on null
    - _Requirements: 4.2, 4.3, 4.5, 4.7, 2.9, 2.10_

  - [ ]* 7.2 Write Vitest unit tests for utility functions
    - `deriveBand`: null, empty, no underscore, unrecognised prefix, each valid band
    - `predictionPeriod`: boundary values at 7 and 730 days
    - `formatLastModified`: valid ISO 8601, missing value, non-parseable string
    - `formatCost`: numeric strings, zero, negative values, non-numeric input
    - `mapValuationMethod`: FIFO, Average, Standard, and unknown values
    - `varianceIndicator`: boundary examples at exactly 20% threshold
    - _Requirements: 4.2, 4.3, 4.5, 4.7, 2.9, 2.10_

  - [ ]* 7.3 Write fast-check PBT — Property 7: band derivation is total and deterministic
    - **Property 7: Band derivation is total and deterministic**
    - For any `ItemClass` string (including null, empty, no underscore), `deriveBand` always returns one of `{A, B, C, D, Unclassified}`, never throws, and returns the same value for the same input
    - **Validates: Requirements 4.7, 1.3**

  - [ ]* 7.4 Write fast-check PBT — Property 8: prediction period has correct start and equal length
    - **Property 8: Prediction period has correct start and equal length**
    - For any `[from, to]` where `to >= from + 7`, `predictionPeriod` starts on `addDays(to, 1)` with length equal to `differenceInDays(to, from)`
    - **Validates: Requirements 2.6**

  - [ ]* 7.5 Write fast-check PBT — Property 10: variance indicator is green, amber, or none — exclusively
    - **Property 10: Variance indicator is green, amber, or none — exclusively and correctly**
    - For any `predicted ∈ [1, 999999]` and `actual ∈ [0, 999999]`, exactly one of green / amber / none applies; conditions are mutually exclusive
    - **Validates: Requirements 2.9, 2.10, 5.5, 5.7**

  - [ ]* 7.6 Write fast-check PBT — Property 11: ValuationMethod mapping is exhaustive
    - **Property 11: ValuationMethod mapping is exhaustive for known values**
    - `mapValuationMethod` never throws and never returns null for any string input
    - **Validates: Requirements 4.5**

- [x] 8. Frontend — TanStack Query hooks
  - [x] 8.1 Implement `useInventoryByWarehouse` hook
    - Create `src/hooks/useInventoryByWarehouse.ts`
    - Wrap the existing `useInventory` / `useOperations` hook result
    - Add client-side grouping: `Map<string, Map<BandLabel, InventoryItem[]>>` keyed by `default_warehouse_id` then band (A, B, C, D, Unclassified)
    - Expose `groupedItems`, `isLoading`, `isError`, `error`, and `refetch`
    - Query key: `["operations-inventory", params]` (no new endpoint)
    - _Requirements: 1.1, 1.2, 1.3_

  - [ ]* 8.2 Write fast-check PBT — Property 1: warehouse grouping is exhaustive and non-overlapping
    - **Property 1: Warehouse grouping is exhaustive and non-overlapping**
    - For any array of inventory items with non-empty `default_warehouse_id`, the grouping produces exactly one group per distinct warehouse ID and every item appears in exactly one group
    - **Validates: Requirements 1.2, 1.3**

  - [ ]* 8.3 Write fast-check PBT — Property 2: search filter is case-insensitive and complete
    - **Property 2: Search filter is case-insensitive and complete**
    - For any item list and non-empty search string, filter returns exactly items where `inventory_id` or `description` contains the search string case-insensitively
    - **Validates: Requirements 1.7**

  - [ ]* 8.4 Write fast-check PBT — Property 3: warehouse filter restricts to selected warehouses only
    - **Property 3: Warehouse filter restricts to selected warehouses only**
    - For any non-empty set of selected warehouse IDs and any item list, every returned item's `default_warehouse_id` is in the selected set and no eligible item is omitted
    - **Validates: Requirements 1.8**

  - [ ]* 8.5 Write fast-check PBT — Property 4: stat card aggregates match item array values
    - **Property 4: Stat card aggregates match item array values**
    - For any array of items, `total_sku_count = items.length`, `total_qty_on_hand = sum(qty_on_hand)`, `low_days_count = count(days_until_stockout ≤ 14)`
    - **Validates: Requirements 1.6**

  - [x] 8.6 Implement `useSkuDetail` hook
    - Create `src/hooks/useSkuDetail.ts`
    - Query key: `["inventory-sku-detail", inventoryId, dateFrom, dateTo]`
    - Only enabled when `inventoryId !== null`
    - Set `timeoutMs: 10000` on the `apiFetch` call
    - Return `{ data: SkuDetailResponse | undefined, isLoading, isError, error, refetch }`
    - _Requirements: 2.4, 2.13_

  - [x] 8.7 Implement `useSkuInsights` hook
    - Create `src/hooks/useSkuInsights.ts`
    - Query key: `["inventory-sku-insights", inventoryId, dateFrom, dateTo]`
    - Set `staleTime: 0, gcTime: 0, keepPreviousData: false`
    - Pass `AbortSignal` through to `apiFetch` for cancellation on parameter change
    - _Requirements: 3.9, 3.10_

  - [ ]* 8.8 Write fast-check PBT — Property 9: monthly bucketing sums ShippedQty correctly
    - **Property 9: Monthly bucketing sums ShippedQty correctly**
    - For any list of sales order lines, the bucketing function produces groups where each `YYYY-MM` key is unique, `shipped_qty` equals the sum of lines in that month, and no line is double-counted or omitted
    - **Validates: Requirements 2.5**

  - [ ]* 8.9 Write fast-check PBT — Property 12: price change pressure classification is deterministic
    - **Property 12: Price change pressure classification is deterministic**
    - For any `old_price > 0` and `new_price > 0`, classification is `"upward pressure"` iff `new_price < old_price` and `"downward pressure"` iff `new_price > old_price`; equal prices produce no record
    - **Validates: Requirements 3.6**

  - [ ]* 8.10 Write fast-check PBT — Property 13: insight finding type is always a valid enum member
    - **Property 13: Insight finding type is always a valid enum member**
    - Every finding in any AI insight response has `type` equal to one of `"promotion_impact"`, `"price_change_impact"`, or `"unexplained_variance"`
    - **Validates: Requirements 3.4**

  - [ ]* 8.11 Write fast-check PBT — Property 5: DefaultWarehouseID parsing preserves value uppercased (frontend)
    - **Property 5: DefaultWarehouseID parsing preserves value uppercased (round-trip)**
    - Parsing `{"value": w}` and reading back the stored warehouse ID returns `w.trim().toUpperCase()`
    - **Validates: Requirements 1.10, 4.6**

- [x] 9. Frontend — `InventoryWarehouseView` component
  - [x] 9.1 Create `InventoryWarehouseView` component
    - Create `src/components/inventory/InventoryWarehouseView.tsx`
    - Props: `items: InventoryItem[]`, `onSkuClick: (item: InventoryItem) => void`
    - Render one collapsible `<WarehouseSection>` per distinct `default_warehouse_id` from `useInventoryByWarehouse`
    - Each section contains `<BandSubGroup>` sub-groups (A, B, C, D, Unclassified) using `deriveBand`
    - Each section includes a `<WarehouseStatCard>` with total SKU count, total `qty_on_hand`, count of SKUs with `days_until_stockout ≤ 14`
    - Hide warehouse sections with zero SKUs after filters
    - Wire search filter (300ms debounce) and warehouse filter dropdown
    - Show loading skeleton while awaiting data; show full-page error on initial load failure; show inline error banner on reload failure
    - Show "No inventory items found" empty state when response has zero SKUs
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8, 1.9, 1.11, 1.12_

  - [ ]* 9.2 Write Vitest component tests for `InventoryWarehouseView`
    - Render with multi-warehouse mock data and assert correct section headings
    - Assert empty-state message renders when `items = []`
    - Assert search filter hides non-matching rows and shows matching ones
    - Assert warehouse filter hides unselected warehouse sections
    - _Requirements: 1.5, 1.7, 1.8, 1.12_

- [x] 10. Frontend — `SalesHistoryChart` and `ComparisonTable`
  - [x] 10.1 Create `SalesHistoryChart` component
    - Create `src/components/inventory/SalesHistoryChart.tsx`
    - Recharts `ComposedChart` with `Bar` (blue) for `shipped_qty` and `Line` (amber dashed) for `predicted_qty`
    - X-axis: `month_label` strings (e.g. "Jan 2024"), Y-axis: units
    - Show "No sales data available for this period" message when `monthly_sales` is empty (no empty chart rendered)
    - _Requirements: 2.5, 2.7, 2.11_

  - [x] 10.2 Create `ComparisonTable` component
    - Create `src/components/inventory/ComparisonTable.tsx`
    - Columns: Month | Predicted Units | Actual Units Sold
    - Apply green row highlight (`bg-green-50 border-l-4 border-green-500`) when `(actual - predicted) / predicted × 100 > 20`
    - Apply amber row highlight (`bg-amber-50 border-l-4 border-amber-500`) when `(predicted - actual) / predicted × 100 > 20`
    - Show "—" for Actual Units Sold in future (`is_future: true`) months
    - _Requirements: 2.8, 2.9, 2.10_

  - [ ]* 10.3 Write Vitest component tests for `ComparisonTable`
    - Render with mock rows and assert green class on > +20% variance months
    - Assert amber class on < -20% variance months
    - Assert "—" displayed for future months
    - Assert no highlight class on within-threshold rows
    - _Requirements: 2.9, 2.10, 5.5_

- [x] 11. Frontend — `InsightsSection` component
  - [x] 11.1 Create `InsightsSection` component
    - Create `src/components/inventory/InsightsSection.tsx`
    - Props: `inventoryId: string`, `dateRange: { from: Date; to: Date }`
    - Use `useSkuInsights` hook
    - While loading: show Skeleton loader
    - On error: show inline error message with retry button; chart and comparison table must remain unaffected
    - On success: render each finding as a card with a type badge (`Promotion Impact` / `Price Change Impact` / `Unexplained Variance`) and the finding text
    - If `data_gaps` is non-empty, show a note listing unavailable data sources
    - _Requirements: 3.4, 3.8, 3.9, 3.11_

  - [ ]* 11.2 Write Vitest component tests for `InsightsSection`
    - Assert Skeleton rendered while `isLoading`
    - Assert error state with retry button rendered on failure
    - Assert all three finding types render the correct badge label
    - Assert `data_gaps` note renders when present
    - _Requirements: 3.4, 3.8, 3.9, 3.11_

- [x] 12. Frontend — `SkuDetailPanel` component
  - [x] 12.1 Create `SkuDetailPanel` component
    - Create `src/components/inventory/SkuDetailPanel.tsx`
    - Props: `inventoryId: string | null`, `onClose: () => void`
    - Use existing `vaul` Drawer or Radix Sheet for the slide-over container
    - Internal state: `dateRange: { from: Date; to: Date }` default 90 days ending today
    - Render metadata header from `useSkuDetail` data: `InventoryID`, `Description`, `DefaultWarehouseID`, `ItemClass`, `ItemStatus` badge, `ValuationMethod`, `LastCost` (KES), `AverageCost` (KES)
    - Render `<DateRangePicker>` (min 7 days, max 730 days) wired to `dateRange` state
    - Render `<SalesHistoryChart>`, `<ComparisonTable>`, `<InsightsSection>` from the same data
    - Wire Escape key via `useEffect` on `keydown` event; wire overlay click to `onClose`
    - Show inline error with retry button when `useSkuDetail` fails; keep panel open
    - _Requirements: 2.1, 2.2, 2.3, 2.12, 2.13_

  - [ ]* 12.2 Write Vitest component tests for `SkuDetailPanel`
    - Assert panel renders with correct metadata from mock `useSkuDetail` data
    - Assert `onClose` is called when Escape key is pressed
    - Assert `onClose` is called when overlay is clicked
    - Assert inline error rendered when hook returns error (panel stays open)
    - _Requirements: 2.1, 2.2, 2.12, 2.13_

- [x] 13. Frontend — wire `SkuDetailPanel` into `app.inventory.tsx`
  - [x] 13.1 Update `app.inventory.tsx` route
    - Replace the existing flat inventory table with `<InventoryWarehouseView>`
    - Add `selectedInventoryId: string | null` state (initially `null`)
    - Pass `onSkuClick={(item) => setSelectedInventoryId(item.inventory_id)}` to `InventoryWarehouseView`
    - Render `<SkuDetailPanel inventoryId={selectedInventoryId} onClose={() => setSelectedInventoryId(null)} />`
    - _Requirements: 1.1, 2.1_

- [x] 14. Checkpoint — frontend complete
  - Ensure all Vitest tests pass (`npx vitest --run`), ask the user if questions arise.

- [ ] 15. E2E smoke test
  - [~] 15.1 Write Playwright smoke test
    - Create `e2e/inventory-smoke.spec.ts`
    - Navigate to `/app/inventory`
    - Wait up to 10 seconds for at least one warehouse section heading to appear in the DOM
    - Click the first SKU row
    - Assert within 5 seconds that a `<canvas>` element (Recharts chart) is present in the DOM
    - _Requirements: 5.8_

- [~] 16. Final checkpoint — all tests pass
  - Run `php artisan test` and `npx vitest --run` to confirm all unit, PBT, and integration tests pass
  - Ensure all tests pass, ask the user if questions arise.

---

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP delivery
- Each task references specific requirements for traceability
- Checkpoints at tasks 5, 14, and 16 ensure incremental validation
- Property tests validate universal correctness guarantees (13 properties from the design)
- Unit tests validate specific examples and edge cases
- Backend PBTs use PHPUnit with an arbitrary generator helper; frontend PBTs use fast-check (install via `npm install --save-dev fast-check`)
- All four new `acumatica_inventory_items` columns are nullable to allow graceful handling of items where Acumatica does not return those fields

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "1.2"] },
    { "id": 1, "tasks": ["2.1"] },
    { "id": 2, "tasks": ["2.2", "2.3", "3.1", "7.1"] },
    { "id": 3, "tasks": ["3.2", "3.3", "7.2", "7.3", "7.4", "7.5", "7.6"] },
    { "id": 4, "tasks": ["4.1", "4.4", "8.1", "8.6"] },
    { "id": 5, "tasks": ["4.2", "4.3", "8.2", "8.3", "8.4", "8.5", "8.7"] },
    { "id": 6, "tasks": ["6.1", "6.2", "8.8", "8.9", "8.10", "8.11", "9.1", "10.1", "10.2"] },
    { "id": 7, "tasks": ["6.3", "6.4", "9.2", "10.3", "11.1"] },
    { "id": 8, "tasks": ["11.2", "12.1"] },
    { "id": 9, "tasks": ["12.2", "13.1"] },
    { "id": 10, "tasks": ["15.1"] }
  ]
}
```
