# PRD — KP Rep-Code Alignment, Customer Portfolio Import & FOL Technician Eligibility

**Status:** Draft — for review, not yet built
**Date:** 2026-08-05
**Author:** Drafted with Claude, from source data in `excels/KP-Customers 20260805 Agusst.csv` and `excels/Kimfay Employees - HODs.csv`
**Owner:** Commercial Tech Lead

## 1. Background

Two source files were provided:

1. `KP-Customers 20260805 Agusst.csv` — a ~1,000-row Acumatica customer export covering Kim-Fay Professional (KP) accounts, with commercial/classification/address columns not currently captured in full.
2. `Kimfay Employees - HODs.csv` plus a supplied mapping table — three sales consultants whose CSV "Rep Code" differs from their HR employee code, and three KP Technicians whose FOL eligibility needs confirming.

| Name | Employee Code (`users.employee_number`) | Rep Code used on customers (`Rep Code` column) |
|---|---|---|
| Yvonne Achieng Otieno | P317 | YVON |
| Berna Piwang Abondo | P460 | C967 |
| Mercyline Kemunto Moranga | P483 | C1262 |

| Name | Employee Code | Role |
|---|---|---|
| Fredrick Omondi Dede | P051 | KP Technician |
| Shadrack Ochieng Onyango | P163 | KP Technician |
| Maurice Okinyi Odhiambo | P369 | KP Technician |

## 2. Goals

- G1: Every KP customer's full commercial/classification/address data from the CSV is queryable in the platform.
- G2: Each customer has a default company-level contact record.
- G3: Yvonne, Berna, and Mercyline's user profiles carry the correct rep code that matches what appears on their customers, without disturbing their employee code.
- G4: Fredrick, Shadrack, and Maurice are valid technician choices in the FOL assignment flow.
- G5: Reports/admin views that should show "KP only" have an unambiguous, correct filter.

## 3. Non-goals

- Not touching Acumatica sync behavior or the `acumatica_customers` table's sync-owned fields (name, status, class, etc.) — those remain sync-of-record.
- Not building a new technician roster/leave-calendar system. FOL currently assigns one technician per request (`assigned_technician_user_id`); there is no date-based capacity table today, and none is being added here.
- Not rewriting the existing `customer_class LIKE 'KP%'` segment-split logic used across `DashboardController` and `KpAccountsController` for order/sales reporting — see §7 (Open Decision).

## 4. Current schema (as-is, verified in code)

- **`users`** (`app/Models/User.php`) already has both `employee_number` and `rep_code` columns. `StaffImportService` auto-backfills `rep_code` only when it matches `^(?:P\d{3,}|C\d{3,})$` — `YVON` does not match that pattern, so it was never auto-populated.
- Customers are **not** a bespoke table — `acumatica_customers` (Acumatica-synced) plus an overlay table `customer_data` (`app/Models/CustomerData.php`) that was built specifically for this kind of Excel import (`source` column literally defaults to `'excel_upload'`). Its existing columns already match most of the CSV headers 1:1 (`route_code`, `customer_zone`, `customer_group`, `category`, `customer_region`, `sage_code`, `credit_limit`, `main_ac_owner`, `country`, `city`, address lines, `email`, etc.), keyed by `customer_acumatica_id` = the CSV's `Customer ID`.
- **Missing from `customer_data`:** `rep_code` and `sales_rep` (the CSV's "Rep Code"/"Sales Rep" columns have no home anywhere today). `Zone ID` and `Route Name` are *not* missing — they're derivable via existing relations (`shipping_zone_id` → `acumatica_shipping_zones.acumatica_id`, `route_code` → `acumatica_routes.route_name`).
- **`customer_contacts`** (`app/Models/CustomerContact.php`) exists: `first_name`, `last_name` (both required, not nullable), `designation_key`/`designation_label` (fixed set: CEO_MD, CFO_FINANCE, CCO_COO, HEAD_PROCUREMENT, **CUSTOM**), `is_primary` (not `is_default`), `is_active`.
- **FOL technician eligibility** (`FolRequestService::technicianQuery()`) is role-based: a user qualifies if `role = 'Technician'` OR they hold the `Technician` app-role (via `user_roles` → `roles` → `role_permissions`) OR that role carries permission `kp.fol.install.execute`. The `Technician` role and its permission already exist (seeded by `RolesPermissionsSeeder`). There is no persisted date/roster table — "allocation" today is just `assigned_technician_user_id` + `technician_assigned_at` on each `fol_requests` row.
- **KP segment filter today:** `customer_class LIKE 'KP%'` is used pervasively (`DashboardController`, `KpAccountsController`), including a hand-carved exclusion for `KPCCLNRS` in one code path. The CSV's own `Customer Group` column (`"Kim-Fay Professional"` vs `"Consumer sales"`) is a cleaner, more literal signal but is **not currently wired into any of those existing filters.**

## 5. Proposed changes

### 5.1 Migration — extend `customer_data`
Add two nullable columns to the existing overlay table (no new table):
- `rep_code` (string, 50, indexed)
- `sales_rep` (string, 150)

### 5.2 Seeder — customer portfolio import
New seeder reads `KP-Customers 20260805 Agusst.csv` (copied into `database/seeders/data/` so it's version-controlled and reproducible, not a dependency on the untracked `excels/` folder) and, per row, upserts `customer_data` by `customer_acumatica_id` (= CSV `Customer ID`) with:

| CSV column | `customer_data` column |
|---|---|
| Route Code | `route_code` |
| Zone ID | `shipping_zone_id` |
| Customer Zone | `customer_zone` |
| Customer Group | `customer_group` |
| Tax Registration ID | `tax_registration_id` |
| Currency ID | `currency_id` |
| Price Class ID / Name | `price_class_id` / `price_class_name` |
| Main A/CC Owner | `main_ac_owner` |
| Rep Code | `rep_code` *(new)* |
| Sales Rep | `sales_rep` *(new)* |
| Category | `category` |
| Customer Region | `customer_region` |
| Sage Code | `sage_code` |
| Business Account ID | `business_account_id` |
| Credit Limit | `credit_limit` (strip thousands separators) |
| Statement Type / cycle | `statement_type` / `statement_cycle` |
| Shipping Rule | `shipping_rule` |
| Delivery | `delivery` |
| Country / City | `country` / `city` |
| Address Line 1–3 | `address_line_1/2/3` |
| Email | `email` |

Columns intentionally **not** duplicated because they already live on `acumatica_customers` (sync-owned): Customer Name, Customer Class, Customer Status, Parent Code, Terms. `Route Name` and full `Zone`/region detail are read via the existing `AcumaticaRoute`/`AcumaticaShippingZone` relations, not re-stored. `Selected` (always `False` in the export) is ignored.

Idempotent, safe to re-run: `updateOrCreate` by `customer_acumatica_id`; `created_by`/`source='excel_upload'`/`synced_at` set on each run for provenance.

### 5.3 Seeder — default customer contact
For every customer row that has **no existing `customer_contacts` row at all** (existing manually-curated contacts are left untouched), create one:
- `first_name` = Customer Name, `last_name` = `''` (schema requires non-null)
- `designation_key` = `CUSTOM`, `designation_label` = `'Custom'`
- `is_primary` = `true`, `is_active` = `true`
- `email` = CSV Email column, if present

### 5.4 Seeder — rep-code alignment (3 users)
Whitelisted update, mirroring the existing `StaffImportService` pattern (only touch the named field, never touch password/unlisted fields, only match by `employee_number`):
- P317 → `rep_code = 'YVON'`
- P460 → `rep_code = 'C967'`
- P483 → `rep_code = 'C1262'`

Each change logged to `UserRepCodeHistory` for audit, same as the existing reconciliation flow.

### 5.5 Seeder — FOL technician eligibility (3 users)
Additive only — does not touch `role`, `designation`, or anything else on their profile ("keep the other setups"). Attach the existing `Technician` app-role (via `user_roles`) to P051, P163, P369 if not already present, so they satisfy `FolRequestService::technicianQuery()` and appear in the technician-assignment dropdown and calendar.

### 5.6 Reporting scope
Recommend introducing `customer_data.customer_group = 'Kim-Fay Professional'` as the filter for any *new* "KP customers" admin surface built on top of this import (e.g. a listing driven by `customer_data`). See open decision below before touching existing report code.

## 6. Rollout sequence

1. Run migration (`customer_data` gets `rep_code`, `sales_rep`).
2. Dry-run the portfolio-import seeder against a copy of the DB; review row counts, unmatched customer IDs, and any credit-limit parse failures.
3. Run the rep-code alignment and FOL-eligibility seeders (small, 6 rows total) — verify via `UserRepCodeHistory` and the technician dropdown in FOL.
4. Run the full portfolio-import + default-contact seeders.
5. Spot-check: pull 5–10 customers from the CSV and confirm `customer_data`/`customer_contacts` match.

## 7. Open decision — needs your call before implementation

**"KP users only" in reports.** I found the KP/non-KP split is not one filter but an established convention (`customer_class LIKE 'KP%'`) baked into multiple live report/dashboard code paths, including a special-case exclusion for `KPCCLNRS`. Rewriting that convention app-wide to use `customer_group = 'Kim-Fay Professional'` instead is a materially bigger and riskier change than adding a filter to a new screen — it affects existing, working dashboard/order segment filters. I'd rather not fold that rewrite into this PRD implicitly. Please confirm exactly which admin surface needs the "KP only" scope (a specific page/report — I didn't find a dedicated frontend admin customer-listing page in this codebase, which is why I need the name of the report) so the change stays contained to that surface rather than touching the shared segment-filter logic.

## 8. Acceptance criteria

- [ ] `customer_data` has `rep_code`/`sales_rep` populated for all matched CSV rows.
- [ ] Every customer in the CSV has exactly one `is_primary` contact (new or pre-existing).
- [ ] Yvonne/Berna/Mercyline's `users.rep_code` matches the CSV; `employee_number` unchanged.
- [ ] Fredrick/Shadrack/Maurice appear in `GET kp/fol/technicians`.
- [ ] No changes to `acumatica_customers`, no changes to existing `customer_contacts` rows, no changes to the three technicians' `role`/`designation`.

## 9. Known repo state affecting related seeders (unrelated to this PRD, flagged for awareness)

`backend/database/seeders/data/products-with-brands.csv`, `stock-items-bi.csv`, `stocks-production-plans.json`, `README-stocks-production.md`, and `2026-07-staff-and-portfolio-import.json` were deleted from disk during this session and were never committed to git, so they cannot be restored from git history. `InventoryBrandSeeder`, `SeedInventoryFromBi`, and `StaffPortfolioImport2026JulySeeder` depend on these files and will fail if run until they're restored from another source (teammate copy, cloud backup, editor local history).
