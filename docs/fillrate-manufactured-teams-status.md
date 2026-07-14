# Fill Rate · Manufactured Product · Teams

Status of the three modules you started implementing in OrderWatch.

**Last reviewed:** 10 Jul 2026

---

## Quick map

| Area | App route | Status |
|------|-----------|--------|
| **Fill Rate** | `/app/fill-rate` | Mostly complete / production-ready |
| **Manufactured product** | No standalone page — used across Inventory, Fill Rate, Team scope | Implemented as a **classification layer** |
| **Teams** | `/app/team` (+ Administration → Team Members) | Feature-complete; rollout is operational |

```
Acumatica SO lines ──► Fill Rate sync ──► snapshots ──► /app/fill-rate
                              │
Inventory BI + classifiers ───┼── product_type: manufactured | trading
                              │
Team product_type_scope ──────┴── Brand / org scope filters fill rate, inventory, backorders
```

---

## 1. Fill Rate

### What it does

Tracks how completely sales orders are delivered. Data is synced from **Acumatica**, stored as local snapshots, shown in the UI, and exported to Excel. Scheduled twice daily (nightly + noon).

### Where it lives

| Layer | Path |
|-------|------|
| **UI** | `src/routes/app.fill-rate.tsx` |
| **Hooks** | `src/hooks/useOperations.ts` (`useFillRate`, `useFillRateSummary`, `useSyncFillRate`) |
| **API** | `GET /api/operations/fill-rate` |
| | `GET /api/operations/fill-rate/summary` |
| | `GET /api/operations/fill-rate/export` (admin / CS manager) |
| | `POST /api/admin/acumatica/sync/fill-rate` |
| **Model** | `backend/app/Models/AcumaticaFillRateSnapshot.php` |
| **Calculator** | `backend/app/Services/Admin/FillRateCalculator.php` |
| **Sync** | `backend/app/Services/Admin/AcumaticaFillRateSyncService.php` |
| **Excel** | `backend/app/Services/Operations/FillRateExcelExporter.php` |
| **Cron** | `orderwatch:fill-rate-sync` → jobs `fill-rate-nightly`, `fill-rate-noon` |

### Built so far

- [x] Snapshot sync from Acumatica (qty-based fill rate)
- [x] List UI: date range, zone, status, KP/CS segment, brand cascade, search/sort
- [x] KPI cards (healthy / at-risk / critical + delivery SLA)
- [x] **Manufactured vs Trading** split on summary + Excel
- [x] Product lines sheet with reason codes + lost-sales value
- [x] Multi-sheet Excel export
- [x] Reason taxonomy + capture report
- [x] Manual “Update fill rate” sync
- [x] Cron (nightly + noon)
- [x] Unit/feature tests (`FillRateCalculatorTest`, `FillRateReasonCaptureTest`, etc.)

### Also surfaces fill rate on

- Dashboard (`src/routes/app.index.tsx`)
- Business optimization (`app.business-optimization.tsx`)
- Customer feed (`app.customer-feed.tsx`)

### Docs

- `docs/fill-rate-user-guide.md` — primary ops guide  
- `Order-Fill-Rate-Backorder-Dashboard-PRD.md` — PRD  
- `fill-rate-qty-on-shipments.md` — QtyOnShipments formula  
- Sample workbook: `docs/data/Order Fill Rate - JAN  27th   2024 (1).xlsx`

### Still open / notes

| Item | Note |
|------|------|
| Separate “Tally ERP” connector | Not built — Acumatica is source of truth |
| PRD value-weighted fill % | Code uses **quantity** rollup; value used for “revenue not yet shipped” |
| Full TTD zone product as in PRD §3 | Partial — SLA badges + zones exist, not a separate TTD module |

### Manual commands

```bash
cd backend
php artisan orderwatch:fill-rate-sync --source=manual
php artisan orderwatch:fill-rate-sync --variant=noon --source=manual
php artisan so-reasons:seed-taxonomy
php artisan audit:so-reason-capture
```

---

## 2. Manufactured product

### What it is

Not a separate app module. It is the **manufactured vs trading (partners)** classification on inventory and how that splits fill-rate, filters, and team data scope.

### Classification rules (summary)

| Category | Meaning |
|----------|---------|
| **Manufactured** | Kim-Fay own / produced goods |
| **Trading (Partners)** | Partner / distributed brands |

Stored mainly on `acumatica_inventory_items.product_type` (`manufactured` | `trading`), with brand / posting class / sub trading group / supplier for product listing.

### Where it lives

| Layer | Path |
|-------|------|
| **Product listing UI** | `src/components/inventory/ProductListingCell.tsx` |
| **Inventory badges** | `src/components/inventory/InventorySkuTableCells.tsx` |
| **Brand filter** | `src/components/filters/BrandFilterCascade.tsx` |
| **Inventory page filter** | `src/routes/app.inventory.tsx` (`product_type`) |
| **Org scope UI** | `src/components/admin/OrgConfigFields.tsx` (`product_type_scope`) |
| **Fill-rate category** | `backend/app/Services/Operations/FillRateBusinessCategory.php` |
| **Brand classifier** | `backend/app/Services/Admin/ProductBrandClassifier.php` |
| **BI seed** | `php artisan inventory:seed-from-bi` → `SeedInventoryFromBi.php` |
| **Team scope** | `BrandAssignmentScope.php`, user `product_type_scope` |
| **Brand options API** | `GET /api/operations/brand-filter-options` |

### Built so far

- [x] DB fields: `product_type`, brand, posting class, sub trading group, supplier
- [x] BI seed from `docs/data/Stock Items BI(Data).csv`
- [x] Inventory filter + manufactured/trading counts
- [x] Product listing cell (ID, name, brand, supplier, etc.)
- [x] Fill-rate KPIs + Excel sheets: **Manufactured Lines** / **Trading Lines**
- [x] Team/org `product_type_scope` (manufactured | trading | both)
- [x] Brand Ops scoping for partner brands

### Seed / refresh classification

```bash
cd backend
php artisan inventory:seed-from-bi --path="../docs/data/Stock Items BI(Data).csv" --dry-run
php artisan inventory:seed-from-bi --path="../docs/data/Stock Items BI(Data).csv"
```

### Still open / notes

| Item | Note |
|------|------|
| Dedicated “Manufactured products” CRUD page | Not built — classification is read-only from Acumatica/BI |
| Dual classification paths | `ProductBrandClassifier` vs `FillRateBusinessCategory` prefixes can disagree if BI not seeded |
| SKU coverage | Depends on ops re-running BI seed when metadata is sparse |

---

## 3. Teams

### What it does

Controls **who can do what** (roles/permissions) and **what data they see** (org tree, sector, department, customers, brands, manufactured vs trading).

| Layer | Controls |
|-------|----------|
| App role | Menus and actions |
| Org level | Reporting tree + visibility (executive → c_suite → hod → sales / brandsops / operations) |
| Department / team | MT, GT, KP, Partner Brands, Finance, Dispatch, … |
| Sector scope | GT, MT, KP, or ALL |
| Customer assignments | Consultant / KAM outlets |
| Brand assignments | Partner brands for Brand Ops |
| Product type scope | Manufactured / trading / both |
| Consultant flag | Link to sales orders / rep codes |

### Where it lives

| Layer | Path |
|-------|------|
| **Primary UI** | `src/routes/app.team.tsx` → `/app/team` |
| **Admin tab** | `src/routes/app.administration.tsx` (Team Members panel) |
| **Components** | `OrgConfigFields`, `BrandAssignmentFields`, `CustomerAssignmentFields`, `StaffImportPanel`, `UserSessionsSheet` |
| **Hooks** | `src/hooks/admin/useAdminSettings.ts` |
| **Services** | `backend/app/Services/Team/*` |
| **API** | `/api/admin/users`, `/api/admin/team/import-staff`, gaps, assignments, sessions, … |
| **CLI** | `team:import-staff`, `team:seed-org-tree`, `team:backfill-customers` |

### Built so far

- [x] Create / edit / delete team members
- [x] Roles, departments, org chart fields
- [x] Product type scope (manufactured / trading / both)
- [x] Brand + customer assignments + SO customer backfill
- [x] Rep code history + restore
- [x] Staff import from HR match + open gaps
- [x] Seed org tree
- [x] Sessions / sign-in logs
- [x] Welcome email + activate/suspend
- [x] Scope enforcement on inventory, fill rate, backorders
- [x] Tests (`TeamManagementTest`, `OrgScopeLeakTest`, `RbacAccessTest`)

### Docs

- `docs/team-module-guide.md` — ops rollout guide  
- `docs/team.md` — technical PRD  
- `docs/team-management.md` — original business requirements  
- Match data: `agent-tools/staff_email_match.json` (xlsx: `docs/data/staff_email_match.xlsx`)

### Rollout still to do (ops, not missing code)

```bash
cd backend
php artisan migrate
php artisan db:seed --class=DepartmentSeeder   # if fresh DB
php artisan team:import-staff --dry-run
php artisan team:import-staff --preserve-manual
php artisan team:seed-org-tree
```

1. Import staff (users created **inactive**)
2. Fix open gaps in UI / `staff_import_gaps`
3. Activate users + send welcome emails
4. Wire reports-to / HODs / customer + brand lists for consultants

### Open stakeholder items (from guide)

- Steve GT email / setup  
- Shared mailboxes policy  
- Jane / Carrefour customer lists  
- Regional managers  
- Revenue masking preferences  

---

## Key files cheatsheet

### Frontend

```
src/routes/app.fill-rate.tsx
src/routes/app.team.tsx
src/routes/app.inventory.tsx
src/components/inventory/ProductListingCell.tsx
src/components/filters/BrandFilterCascade.tsx
src/components/admin/OrgConfigFields.tsx
src/hooks/useOperations.ts
src/hooks/admin/useAdminSettings.ts
```

### Backend

```
backend/app/Http/Controllers/Api/OperationsController.php   # fill-rate endpoints
backend/app/Services/Admin/FillRateCalculator.php
backend/app/Services/Admin/AcumaticaFillRateSyncService.php
backend/app/Services/Operations/FillRateExcelExporter.php
backend/app/Services/Operations/FillRateBusinessCategory.php
backend/app/Services/Team/*                                 # org + brand + import
backend/routes/api.php                                      # operations + admin routes
```

### Docs already in repo

```
docs/fill-rate-user-guide.md
docs/fillrate-manufactured-teams-status.md   ← this file
docs/team-module-guide.md
docs/team.md
docs/Order-Fill-Rate-Backorder-Dashboard-PRD.md
docs/DEPLOY-VPS.md
docs/data/                                   ← spreadsheets & CSVs
docs/samples/                                ← PO sample PDFs
```

---

## Suggested next work (if continuing these modules)

1. **Fill Rate** — align PRD value-weighted % vs qty formula; tighten TTD/zone drill-down if still required  
2. **Manufactured** — single source of truth for classification (prefer BI `product_type`; reduce dual classifier drift)  
3. **Teams** — finish activation / org wiring for real staff; close open gaps; confirm Brand Ops + HOD scopes with stakeholders  
