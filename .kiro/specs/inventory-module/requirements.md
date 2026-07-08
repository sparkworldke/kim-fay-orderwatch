# Requirements Document

## Introduction

The Inventory Module extends the existing Kim-Fay OrderWatch inventory page to deliver three integrated capabilities:

1. **Warehouse inventory listing** — a structured view of all stock items organised by warehouse (FGS2, FGS, MSA, DTC, PRMS, RMS1, TRMS, etc.) and band classification, parsed from Acumatica StockItem payloads.
2. **Interactive SKU detail view** — a drill-down panel for any single SKU showing historical units sold in a user-selected period, AI-generated sales predictions for an equivalent future period, and a side-by-side comparison chart of predicted vs actual performance.
3. **AI-powered insight generation** — automated variance analysis that cross-references sales anomalies against Acumatica promotional events and historical price changes to explain over- and under-performance.

The module is built on the existing TanStack Router + React 19 + Recharts + TanStack Query stack. All data originates from the Acumatica REST API (version 23.200.001) and is cached in the existing Laravel backend before being surfaced to the front-end.

---

## Glossary

- **Inventory_Module**: The front-end feature set described in this document, rendered at `/app/inventory`.
- **Warehouse**: A physical or logical stock location identified in Acumatica by a `WarehouseID` such as FGS, FGS2, MSA, DTC, PRMS, RMS1, TRMS.
- **SKU**: A single stock-keeping unit uniquely identified by `InventoryID` (e.g. `VATSH0083`).
- **Band**: A classification tier (A, B, C, D) assigned to each SKU based on sales velocity or revenue contribution, derived from the `ItemClass` field or a computed metric.
- **Prediction_Period**: The future date range equivalent in length to the user-selected historical date range (e.g. if the user selects the past 90 days, the prediction covers the next 90 days).
- **AI_Engine**: The server-side component (Laravel backend) that calls an LLM API to generate sales predictions and variance insights.
- **Acumatica**: The ERP system exposing the REST API at `https://<instance>.acumatica.com/entity/Default/23.200.001/`.
- **StockItem**: The Acumatica entity representing a single inventory item with fields including `InventoryID`, `DefaultWarehouseID`, `ItemClass`, `LastCost`, `AverageCost`, `ItemStatus`.
- **SalesOrder**: The Acumatica entity representing a customer order with `Details[]` line items containing per-SKU `OrderQty`, `ShippedQty`, and `UnitPrice`.
- **Promotional_Event**: A record in Acumatica (e.g. a discount, campaign, or price override) associated with a date range and one or more SKUs, retrievable via the Promotions or Price Schedule endpoints.
- **Price_Change**: A historical adjustment to a SKU's `UnitPrice` or `SalesPrice` stored in Acumatica's price history.
- **Run_Rate**: The average daily units sold for a SKU over a given period, computed as `total_shipped_qty ÷ number_of_days`.
- **Days_Until_Stockout**: `qty_on_hand ÷ run_rate`, indicating how many days of stock remain at current consumption.
- **Variance**: The difference between predicted units and actual shipped units for a given period bucket (e.g. monthly).

---

## Requirements

### Requirement 1: Warehouse Inventory Listing

**User Story:** As an operations manager, I want to view all inventory items grouped by warehouse and band classification, so that I can quickly assess stock levels across all locations.

#### Acceptance Criteria

1. WHEN the Inventory_Module page loads, THE Inventory_Module SHALL display all active SKUs fetched from the `/operations/inventory` backend endpoint, organised into sections by `DefaultWarehouseID`.

2. WHEN the backend returns one or more SKUs assigned to a given `DefaultWarehouseID`, THE Inventory_Module SHALL display that warehouse as a distinct labelled section; supported warehouses include FGS, FGS2, MSA, DTC, PRMS, RMS1, TRMS, and any additional values present in the payload.

3. WHEN a warehouse section is rendered, THE Inventory_Module SHALL group its SKUs by Band classification (A, B, C, D) derived from the `ItemClass` field, displaying each band as a collapsible sub-group.

4. THE Inventory_Module SHALL display the following columns for each SKU row: `InventoryID`, `Description`, `ItemClass`, `qty_on_hand`, `qty_available`, `Run_Rate` (units/day), `Days_Until_Stockout`, and prediction status badge.

5. WHEN a warehouse section contains zero SKUs after applying active filters, THE Inventory_Module SHALL hide that warehouse section rather than rendering an empty group.

6. WHEN warehouse sections are visible, THE Inventory_Module SHALL display a summary stat card for each visible warehouse reflecting the currently applied filter state, showing: total SKU count, total `qty_on_hand` units, and count of SKUs with `Days_Until_Stockout` ≤ 14.

7. WHEN the user types in the search field, THE Inventory_Module SHALL filter the displayed rows to those where `InventoryID` or `Description` contains the search string, case-insensitively, within 300ms of the last keystroke.

8. WHEN the user selects one or more warehouses from the warehouse filter dropdown, THE Inventory_Module SHALL restrict the displayed sections to only the selected warehouses.

9. IF the backend endpoint returns an error response during a reload (data was previously loaded), THEN THE Inventory_Module SHALL display an inline error banner with a retry button without clearing the previously loaded data. IF the backend endpoint returns an error response on the initial page load (no data was previously loaded), THEN THE Inventory_Module SHALL display a full-page error message with a retry button and no inventory table.

10. THE Inventory_Module SHALL parse the `DefaultWarehouseID` field from the Acumatica StockItem JSON payload (format: `{"value": "FGS"}`) and normalise the value to uppercase for consistent grouping.

11. WHILE the Inventory_Module is awaiting a response from the `/operations/inventory` backend endpoint, THE Inventory_Module SHALL display a loading skeleton in place of the warehouse sections.

12. IF the backend endpoint returns a response containing zero SKUs, THEN THE Inventory_Module SHALL display an empty-state message ("No inventory items found") and suppress all warehouse sections and stat cards.

---

### Requirement 2: SKU Detail View

**User Story:** As a product manager, I want to click on any SKU and see its full sales history, AI-generated predictions, and a comparison chart, so that I can make informed restocking decisions.

#### Acceptance Criteria

1. WHEN the user clicks on any SKU row in the warehouse listing, THE Inventory_Module SHALL open a slide-over panel displaying the SKU detail view for the selected `InventoryID`.

2. THE Inventory_Module SHALL display the following SKU metadata in the detail panel header: `InventoryID`, `Description`, `DefaultWarehouseID`, `ItemClass`, `ItemStatus`, `ValuationMethod`, `LastCost`, and `AverageCost`.

3. THE Inventory_Module SHALL provide a date range picker in the SKU detail panel with a default selection of the 90 days ending on today's date, allowing the user to select a historical period with a minimum range of 7 days and a maximum range of 730 days.

4. WHEN a historical date range is selected, THE Inventory_Module SHALL fetch units-sold data from the backend within 10 seconds for that SKU and period by querying completed SalesOrder line items where `InventoryID` matches and `OrderDate` falls within the range.

5. WHEN units-sold data is loaded, THE Inventory_Module SHALL display a monthly-bucketed bar chart showing `ShippedQty` per month for the selected historical period, labelled with the month name and year.

6. WHEN a historical date range is selected, THE Inventory_Module SHALL request AI-generated sales predictions from the backend for a Prediction_Period of equal length starting the day after the historical period ends.

7. THE Inventory_Module SHALL display the AI sales prediction as a monthly-bucketed overlay on the same chart, rendered as a distinct line or bar series in a different colour, alongside the historical actuals.

8. THE Inventory_Module SHALL display a comparison table below the chart listing each month in the combined historical and Prediction_Period with columns: Month, Predicted Units, Actual Units Sold. For future months within the Prediction_Period, Actual Units Sold SHALL display "—".

9. WHEN actual units sold exceed predicted units such that `(actual - predicted) / predicted × 100 > 20`, THE Inventory_Module SHALL highlight that month row in the comparison table with a green indicator.

10. WHEN actual units sold fall below predicted units such that `(predicted - actual) / predicted × 100 > 20`, THE Inventory_Module SHALL highlight that month row in the comparison table with an amber indicator.

11. IF the backend returns no sales history for the selected SKU and date range, THEN THE Inventory_Module SHALL display the message "No sales data available for this period" in the chart area without rendering an empty chart.

12. WHEN the detail panel is open and the user presses the Escape key or clicks outside the panel, THE Inventory_Module SHALL close the detail panel and return focus to the inventory table.

13. IF the backend returns an error response when fetching SKU detail data, THEN THE Inventory_Module SHALL display an inline error message in the panel with a retry button, without closing the panel.

---

### Requirement 3: AI-Powered Insight Generation

**User Story:** As a sales analyst, I want the system to automatically explain why actual sales deviated from predictions by checking promotions and price changes, so that I can attribute performance shifts to root causes.

#### Acceptance Criteria

1. WHEN the SKU detail panel finishes loading prediction vs actual data, THE AI_Engine SHALL analyse the Variance for each monthly bucket and generate a plain-language insight summary for the SKU.

2. WHEN generating insights, THE AI_Engine SHALL query Acumatica for Promotional_Events affecting the SKU during the analysis period, using the Promotions or Price Schedule endpoint, and include any matching promotions in the insight context.

3. WHEN generating insights, THE AI_Engine SHALL query the price history for the SKU in Acumatica and identify any Price_Change records falling within the analysis period, and include them in the insight context.

4. WHEN the AI_Engine returns an insight response, THE Inventory_Module SHALL display the insight summary in a dedicated "Insights" section below the comparison table, formatted as a structured list of findings with each finding labelled as one of: Promotion Impact, Price Change Impact, or Unexplained Variance.

5. WHEN a Promotional_Event is found that overlaps with a month where actual sales exceeded predictions, THE AI_Engine SHALL include in the insight text: the promotion name, its date range, the absolute unit Variance (actual minus predicted), and the percentage Variance for that month.

6. WHEN a Price_Change is found during a month where actual sales deviated from predictions, THE AI_Engine SHALL classify the effect as "upward pressure on demand" if the price decreased, or "downward pressure on demand" if the price increased, and SHALL state the direction and magnitude of the price change in the insight text.

7. WHEN no Promotional_Events and no Price_Change records are found for a month with Variance greater than 20%, THE AI_Engine SHALL label that month's Variance as "Unexplained" in the insight output.

8. IF the AI_Engine API call fails or times out after 30 seconds, THEN THE Inventory_Module SHALL display an error message in the Insights section without blocking the chart and comparison table from rendering.

9. WHILE the AI_Engine is generating insights, THE Inventory_Module SHALL display a loading skeleton in the Insights section and SHALL replace it with the result when the response arrives.

10. WHEN the user selects a different date range in the SKU detail panel, THE Inventory_Module SHALL cancel any in-flight AI insight request and THE AI_Engine SHALL initiate a new insight generation request for the updated period.

11. IF the Acumatica Promotions or Price Schedule sub-query fails, THEN THE AI_Engine SHALL proceed with insight generation using only the available data and SHALL include a note in the Insights section stating which data source was unavailable.

---

### Requirement 4: JSON Payload Parsing and Data Foundation

**User Story:** As a developer, I want all module features to be built on accurately parsed Acumatica StockItem and SalesOrder payloads, so that data displayed to users is consistent with the ERP source of truth.

#### Acceptance Criteria

1. THE Inventory_Module SHALL parse every Acumatica StockItem field using the pattern `field.value` (e.g. `InventoryID.value`, `DefaultWarehouseID.value`) and, IF `InventoryID.value` is absent or empty, SHALL skip that record and log a warning containing the record index and the raw JSON fragment.

2. THE Inventory_Module SHALL parse numeric fields (`LastCost.value`, `AverageCost.value`) as floating-point numbers and display them rounded to 2 decimal places with the currency symbol KES.

3. THE Inventory_Module SHALL parse the `LastModified.value` ISO 8601 timestamp and display it in the format `DD MMM YYYY HH:mm` in the East Africa Time zone (UTC+3). IF `LastModified.value` is absent or cannot be parsed as a valid ISO 8601 timestamp, THE Inventory_Module SHALL display "—" in that field.

4. WHEN the `ItemStatus.value` field equals `"Active"`, THE Inventory_Module SHALL display the SKU with a green "Active" badge; WHEN it equals any other non-empty value, THE Inventory_Module SHALL display a grey badge with the status text verbatim.

5. THE Inventory_Module SHALL map the `ValuationMethod.value` field to a human-readable label: `"FIFO"` → "First In First Out", `"Average"` → "Average Cost", `"Standard"` → "Standard Cost". IF the value does not match any of these, THE Inventory_Module SHALL display the raw value unchanged.

6. FOR ALL StockItem records ingested, THE Inventory_Module SHALL store the parsed `DefaultWarehouseID.value` in the `default_warehouse_id` column of the inventory database table such that a subsequent read of that column returns a string equal to the original `DefaultWarehouseID.value` (case-normalised to uppercase).

7. WHEN the `ItemClass.value` field contains a recognised band segment, THE Inventory_Module SHALL extract the department segment for display and derive the Band classification using the configured band mapping rules. IF no recognised band segment is present, THE Inventory_Module SHALL assign Band "Unclassified" and display the full `ItemClass.value` string as the department.

---

### Requirement 5: End-to-End Testing

**User Story:** As a QA engineer, I want comprehensive end-to-end tests that verify warehouse listing, SKU detail, prediction comparison, and AI insight integration, so that regressions are caught before deployment.

#### Acceptance Criteria

1. WHEN the integration test calls the `/operations/inventory` backend endpoint, THE Test_Suite SHALL verify that the response contains at least one item where `inventory_id` is a non-null, non-empty string, `default_warehouse_id` is a non-null, non-empty string, and `qty_on_hand` is an integer ≥ 0.

2. WHEN the integration test calls the SKU detail endpoint for a known `InventoryID` with a date range spanning the 12 calendar months ending on the test execution date, THE Test_Suite SHALL verify that the response contains a `monthly_sales` array with at least one entry where `month` is a non-null string, `shipped_qty` is an integer ≥ 0, and `predicted_qty` is an integer ≥ 0.

3. THE Test_Suite SHALL include a property-based test verifying that for all StockItem JSON payloads where `InventoryID.value`, `DefaultWarehouseID.value`, and `ItemClass.value` are non-null, non-empty strings, parsing and then re-serialising those three fields produces an object where each field's value and type are identical to the original input.

4. WHEN the integration test calls the AI insight endpoint for a SKU with at least one calendar month where shipped and predicted quantities differ by more than 20%, THE Test_Suite SHALL verify that the response contains at least one finding where the `type` field equals one of `"promotion_impact"`, `"price_change_impact"`, or `"unexplained_variance"`.

5. WHEN the UI component test renders the SKU detail panel with mock data containing months where actual and predicted quantities are provided, THE Test_Suite SHALL verify that months where `(actual - predicted) / predicted × 100 > 20` carry the green indicator CSS class, and months where `(predicted - actual) / predicted × 100 > 20` carry the amber indicator CSS class.

6. WHEN the integration test provides an Acumatica promotional event whose start date falls within the same calendar month as a month of positive variance, THE Test_Suite SHALL verify that the AI insight output for that month includes a finding of type `"promotion_impact"` referencing the promotion name.

7. FOR ALL monthly variance calculations where the predicted value is an integer in the range [1, 999999] and the actual value is an integer in the range [0, 999999], THE Test_Suite SHALL include a property-based test verifying that the variance indicator is "green" when and only when `(actual - predicted) / predicted × 100 > 20`, and "amber" when and only when `(predicted - actual) / predicted × 100 > 20`, and no indicator when neither condition holds.

8. WHEN the end-to-end smoke test loads the `/app/inventory` route, THE Test_Suite SHALL wait up to 10 seconds for at least one warehouse section to render, then select a SKU row, open the detail panel, and assert within 5 seconds that the chart canvas element is present in the DOM.
