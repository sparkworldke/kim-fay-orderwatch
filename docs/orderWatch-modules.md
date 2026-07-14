# OrderWatch Modules — Session Status

**Last updated:** 8 Jul 2026  
**Scope:** Work completed and still pending from the `usrr-refactor.md` audit and follow-up implementation in this session.

---

## Executive Summary

| Module | Status | Notes |
|--------|--------|-------|
| Site-wide clickable elements | **DONE** | Shared `entity-links` rolled out; `DateWithActions` wired |
| Inventory system | **DONE** | Warehouse table, pagination, schema, CSV/XLSX seeder, `?sku=` deep link |
| Daily email template | **DONE** | Incomplete-orders line removed; SLA section hidden (commented) |
| Sales Consultant module | **DONE** | Search, detail table, branch accordions, revenue lost on documents |
| Fillrate module | **DONE** | Excel, KP/CS, Mfg/Trading, reasons, Acumatica sync (= Tally module) |
| Inventory Excel/BI seeding | **DONE** | CSV + XLSX via PhpSpreadsheet |
| KP vs CS / naming | **DONE** | "Kimfay Professional" label applied |
| SO reason audit & taxonomy | **DONE** | 33 reasons, hierarchy, `audit:so-reason-capture` |
| Product classification UI | **DONE** | `ProductListingCell` across Fill Rate, Inventory, Backorders, etc. |
| TypeScript build | **DONE** | `npx tsc --noEmit` passes cleanly |
| Feature documentation | **DONE** | `docs/fill-rate-user-guide.md` |
| Fill-rate Excel export RBAC | **DONE** | Restricted to `admin.or.manager` middleware |

---

## 1. Site-Wide Clickable Interactive Elements — DONE

### Implemented
- Shared components in `src/components/entity-links.tsx`:
  - `CustomerLink`, `OrderLink`, `InventoryLink`, `ConsultantLink`
  - `DateLink`, `ViewDateButton`, `DateWithActions` (date + adjacent "View Date" button)
- `OrderLink` supports branch SO routes (`branchId` param)
- Rolled out to: orders, backorders, customers, fill-rate, so-imports, credit-notes, business-optimization, sales-consultants, orders-by-date, dashboard (`app.index.tsx`), customer-orders-shared
- Inventory page honors `?sku=` search param and auto-opens `SkuDetailPanel`
- Consistent hover/focus styling and ARIA labels in shared components

### Pending
- None identified for core requirements

---

## 2. Inventory System — DONE

### Implemented
- `inventory_sku_insights` safeguard migration: `2026_07_12_000001_ensure_updated_at_on_inventory_sku_insights.php`
- Warehouse summary table with SKU counts and clickable rows (`InventoryWarehouseView.tsx`)
- Full detail table with pagination 20/50/100 and entry range format (`PaginationControls`)
- DB columns on `acumatica_inventory_items`: `brand`, `posting_class`, `sub_trading_group`, `supplier`
- Frontend brand/sub-trading-group format via `formatBrandDisplay()` in `inventoryUtils.ts`
- `SeedInventoryFromBi` command supports **CSV and XLSX** (`inventory:seed-from-bi`)
- **Product classification display** via `ProductListingCell` in inventory list
- **Scrollable** `SkuDetailPanel` sidebar (fixed header, scrollable body)

### Pending
- Run seeder against production BI data if classification columns are empty:  
  `php artisan inventory:seed-from-bi --path="Stock Items BI(Data).csv"`

---

## 3. Daily Email Notification Template — DONE

### Implemented (`backend/app/Mail/DailyManagementReportMail.php`)
- Removed prior-month incomplete orders carryover line
- Hidden Nairobi & Mombasa 24hr SLA section (commented in source for future reactivation)
- Revenue Split renumbered to section 4
- Tests updated in `DailyExecutiveReportTest.php`

### Pending
- None

---

## 4. Sales Consultant Module — DONE

### Implemented
- Search by consultant name, rep code, or employee number (frontend + `SalesConsultantController` `q` filter)
- Detail page: customer search, fill rate column, pagination, sortable columns including Rev. Lost
- Parent customer page: accordions with counts & pagination (Whitespot 8, Documents 15, Common Products 20)
- **Branch customer page** now mirrors parent accordion layout
- Documents table shows **Rev. Lost** when fill rate &lt; 100% (backend `revenue_lost` subquery on orders API)
- SO description below SO number in customer documents (from Acumatica payload `Description`)
- Rep codes and employee numbers clickable via `ConsultantLink`
- SQLite-compatible SQL for fill-rate/revenue queries (`LEAST`/`GREATEST` replaced with portable `CASE`)

### Pending
- Optional: dedicated tests for consultant search (`?q=`), pagination, sort, `revenue_lost`

---

## 5. Fillrate Module — DONE

### Implemented
- Excel download with date range (`FillRateExcelExporter.php`, UI in `app.fill-rate.tsx`)
- SO sync from Acumatica with partial-delivery reason parsing (`AcumaticaFillRateSyncService` — documented as Tally/fill-rate module)
- Summary sheet: revenue loss, Manufactured/Trading split, KP/CS, root causes, customer grouping, SOs Not Fully Delivered, Missing Price, Reason Capture Report
- KP vs CS split (`FillRateCalculator`, case-insensitive `kp` prefix)
- Manufactured vs Partner (Trading) split (`FillRateBusinessCategory`)
- Label **"KP (Kimfay Professional)"** (fixed stray "Key Partner" in `app.customers.tsx`)
- Reason taxonomy: 33 hierarchical sub-reasons (`SalesOrderReasonCatalog`)
- Audit command: `php artisan audit:so-reason-capture`
- API: `GET /api/operations/so-reason-audit`
- SO description on fill-rate order list (`order_description` from payload)
- **Product classification** on fill-rate product sheet via `ProductListingCell`
- **Scrollable** fill-rate product sheet sidebar
- Excel export restricted to admin/manager roles

### Pending
- **Tally external integration:** No separate Tally ERP connector exists; Acumatica → OrderWatch fill-rate sync is the implemented path. Confirm with stakeholders if a distinct Tally system integration is still required.
- Optional: extend `OperationsExcelExportTest` to assert Instructions/Summary sheet order and KPI values
- Optional: user/maintenance documentation for fill-rate features

---

## 6. Product Classification Display (This Session) — DONE

### Requirement
Show **Brand**, **Posting Class**, **Sub Trading Group**, and **Supplier** anywhere a product appears as a column or secondary row. Make product view popup/sidebar scrollable.

### Implemented
- New component: `src/components/inventory/ProductListingCell.tsx`
  - Inventory ID (linked)
  - Description / product name
  - Brand (line 1)
  - `- [Sub Trading Group]` (line 2)
  - `Posting: … · Supplier: …` (meta line)
- Backend: `OperationsCatalogResolver::classificationsForInventoryIds()` enriches API responses
- APIs enriched: backorders, fill-rate products, customer common products/Whitespot, SO detail lines, business optimization
- Sidebars made scrollable: `SkuDetailPanel`, `FillRateProductsSheet`

### Surfaces using `ProductListingCell`
| Surface | File |
|---------|------|
| Inventory list | `InventoryWarehouseView.tsx` |
| Backorders | `app.backorders.tsx` |
| Fill Rate (sheet + flagged) | `app.fill-rate.tsx` |
| Customer Common Products | `customer-orders-shared.tsx` |
| Customer Whitespot | `customer-orders-shared.tsx` |
| SO line items | `customer-orders-shared.tsx` |
| Business Optimization | `app.business-optimization.tsx` |

### Pending
- Populate classification data via seeder if DB columns are null for many SKUs

---

## 7. SO Reason Audit & Taxonomy — DONE

### Implemented
- Hierarchical parent–child reason structure (e.g. `Cancelled Order - Wrong code`)
- `SalesOrderReasonCatalog` with 33 sub-reasons
- `SoReasonAuditService` + `audit:so-reason-capture` command
- `FillRateReasonCaptureReport` in Excel export
- Tests: `SalesOrderReasonCatalogTest`, `SoReasonAuditTest`, `FillRateReasonCaptureTest`

### Pending
- None for core requirements

---

## 8. TypeScript / Build Health — DONE

### Fixed in this session
| File | Issue | Fix |
|------|-------|-----|
| `src/lib/server-api.ts` | Undici Agent/Dispatcher types | Use `undici.Dispatcher` + `undici.RequestInit` cast |
| `src/routes/app.administration.tsx` | `null` in `updateRule`, wrong `update` ref | Allow `null`; use `restore.isPending` |
| `src/routes/app.index.tsx` | `TrendDay` casts, chart data types | `trendDayStatusCount()`, `ChartDayPoint` type |
| `src/routes/app.so-imports.tsx` | Incomplete `ImportStats` fallback | Full default object with all fields |

### Status
- `npx tsc --noEmit` exits cleanly (exit code 0)

---

## 9. Tests Run This Session

| Suite | Result |
|-------|--------|
| `DailyExecutiveReportTest` | 6 passed |
| `OrderControllerTest` | 6 passed |
| `SalesConsultantOperationsTest` | 12 passed |
| `AcumaticaOperationsSyncTest` + `OrderControllerTest` (combined) | All passing after Z005 upsert fix |
| `SalesConsultantOperationsTest` (extended) | Search, pagination, revenue_lost |
| `OperationsExcelExportTest` (extended) | Summary sheet KPI assertions |

---

## 10. Key Files Added or Heavily Modified

### New files
- `src/components/inventory/ProductListingCell.tsx`
- `backend/database/migrations/2026_07_12_000001_ensure_updated_at_on_inventory_sku_insights.php`
- `docs/fill-rate-user-guide.md`
- `orderWatch-modules.md` (this file)

### Core backend
- `backend/app/Services/Operations/OperationsCatalogResolver.php` — classification lookup
- `backend/app/Http/Controllers/Api/OperationsController.php` — backorder + fill-rate enrichment
- `backend/app/Http/Controllers/Api/CustomerController.php` — common products / Whitespot enrichment
- `backend/app/Http/Controllers/Api/OrderController.php` — `revenue_lost`, line classifications
- `backend/app/Mail/DailyManagementReportMail.php` — email template edits
- `backend/app/Console/Commands/SeedInventoryFromBi.php` — XLSX support
- `backend/app/Services/Operations/BusinessOptimizationService.php` — product classification

### Core frontend
- `src/components/entity-links.tsx` — `DateWithActions`, branch `OrderLink`
- `src/components/customer-orders-shared.tsx` — entity links, revenue lost, `ProductListingCell`
- `src/components/inventory/SkuDetailPanel.tsx` — scrollable layout
- `src/routes/app.inventory.tsx` — `?sku=` deep link
- `src/routes/app.fill-rate.tsx` — classification + scrollable sheet
- `src/routes/app.backorders.tsx` — `ProductListingCell`
- `src/routes/app.customer-orders.$customerId.branch.$branchId.tsx` — accordions
- Plus: customers, sales-consultants, business-optimization, orders, index, so-imports, credit-notes

---

## 11. Recommended Next Steps — DONE (8 Jul 2026)

| # | Item | Status | Notes |
|---|------|--------|-------|
| 1 | Seed inventory classification | **DONE** | `inventory:seed-from-bi` run against `Stock Items BI(Data).csv` — 1469 created, 581 updated, 44 fillrate-aligned; seeder sanitizes µ/invalid UTF-8 |
| 2 | Confirm Tally requirement | **DOCUMENTED** | Acumatica fill-rate sync = OrderWatch Tally module; no separate Tally ERP connector. See `docs/fill-rate-user-guide.md` — stakeholders still to confirm if a direct Tally API is required. |
| 3 | Fix flaky tests | **DONE** | `AcumaticaOperationsSyncTest` uses `ensureShippingZone()` (`updateOrCreate`) to avoid migration-seeded `Z005` collisions |
| 4 | Fill-rate user docs | **DONE** | `docs/fill-rate-user-guide.md` — Excel export, KP/CS, Mfg/Trading, reason taxonomy, BI seed command |
| 5 | Extend test coverage | **DONE** | `SalesConsultantOperationsTest`: list search, customer pagination/search/sort/revenue_lost; `OperationsExcelExportTest`: Summary sheet KPIs; fixed `sales_order_id` column in `SalesConsultantController` |

---

## 12. Quick Verification Checklist

- [ ] `php artisan migrate` — applies `updated_at` safeguard migration
- [x] `php artisan inventory:seed-from-bi` — populates brand/posting_class/sub_trading_group/supplier
- [x] `php artisan test` — shipping zone flakes fixed; consultant + Summary sheet tests added
- [ ] `npx tsc --noEmit` — TypeScript clean
- [ ] Manual: click Inventory ID in Backorders / Fill Rate → opens scrollable `SkuDetailPanel`
- [ ] Manual: daily email preview excludes incomplete-orders line and SLA section
- [ ] Manual: Fill Rate Excel export requires admin/manager role