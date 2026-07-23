# PRD: Fair Customer Visibility Across Org Hierarchy & Segments

**Status:** Draft, 2026-07-23. Grounded in a codebase investigation (file:line references below), not a greenfield proposal — two of the three gaps this PRD closes already have most of their supporting infrastructure built; they're switched off or bypassed in specific places, not missing outright.

**Owner:** [fill in]
**Requested by:** [fill in]

---

## 1. Problem Statement

Acumatica supports exactly **one sales rep per customer** (`sales_consultant_rep_code`, synced onto `acumatica_sales_orders`). Real org structure doesn't map onto that cleanly: a sector head/superior may be the rep Acumatica has on record for a whole sector, while the actual day-to-day servicing rep for a specific outlet is a junior who isn't Acumatica's recorded rep for that customer — or the reverse. Any module that only trusts Acumatica's rep_code shows a skewed picture: a junior can't see outlets they actually manage, or a superior can't see everything happening in their sector.

Separately: FOL, PCR, and Meetings are assumed to be KP-only functions, but that assumption is only actually enforced in code for one of the three.

## 2. Current State — What's Actually Implemented

### 2.1 Customer assignment table (exists, underused)

`UserCustomerAssignment` (`backend/app/Models/UserCustomerAssignment.php:8-34`, table from `backend/database/migrations/2026_07_14_000001_create_org_chart_and_scoping_tables.php:40-50`) is a genuine many-to-many junction: unique key is `(user_id, customer_acumatica_id)`, not unique on `customer_acumatica_id` alone — the schema already allows more than one user per customer. Columns include `assignment_type` (string, default `'primary'`), `assigned_by`, `notes`, `source`, `source_batch_id`, `last_so_date`, `so_order_count`, `confidence`.

In practice, `assignment_type` is never anything but `'primary'` — every writer (`CustomerAssignmentService.php:81,250`, `CustomerSeeder.php:98`) hardcodes it. `CustomerAssignmentService::syncAssignments()` (`CustomerAssignmentService.php:62-87`) does a **full delete-and-replace per user**: assigning outlets to a user wipes their previous assignment rows and recreates them, with no concept of "this assignment is a different kind and shouldn't be touched."

### 2.2 Org hierarchy (exists, fully wired for assignment-aware modules)

`users.reports_to_user_id` (added in the same migration) plus `org_level`, `department_role`. `OrgTreeService::descendantIds()` (`backend/app/Services/Team/OrgTreeService.php:13-35`) walks the manager tree. `OrgScopeService::effectiveScopeUserIds()` (`OrgScopeService.php:149-159`) gives a superior (`org_level`/`department_role` = executive/c_suite/hod, per `config('departments.org_levels_with_subtree_visibility')`) the **union of every descendant's individually-resolved scope** — not a flat rep_code list.

For each user in that scope, `applySingleUserCustomerScope` (`OrgScopeService.php:164-233`) resolves visible customers in this order:
1. `customerAssignments()` rows, if any exist for that user
2. Fallback: `SalesConsultantScope`/rep_code match against `acumatica_sales_orders.sales_consultant_rep_code`
3. Fallback: sector/department `customer_class` prefix match, with `CustomerDepartmentOverride` exceptions

**This means the hierarchy problem is already solved for anything routed through `DataScope`/`OrgScopeService`** — if a junior has a correct assignment row for an outlet, they see it as their own, and any superior above them in the tree sees it too via the subtree union, automatically, with no double-entry.

### 2.3 KP/MT/GT segmentation (exists)

`config/departments.php:9-13` defines `customer_facing_slugs` (`mt_consumer_sales`, `gt`, `kp`); `class_prefix_map` (`departments.php:68-72`) maps Acumatica `customer_class` prefixes (`KP`, `MT`, `GT`) to department slugs. `user_sector_scopes` (model `UserSectorScope`, column `sector`) lets a user's portfolio be overridden to specific sectors (including a literal `'ALL'`), read in `OrgScopeService::prefixesForUser()` (`OrgScopeService.php:244-270`).

### 2.4 FOL/PCR/Meetings — inconsistent KP enforcement

- **FOL** explicitly enforces it: `FolRequestService::ensureKpCustomer()` (`FolRequestService.php:1333-1339`) checks `str_starts_with(strtoupper($customer->customer_class), 'KP')`, called at creation (lines 186, 268).
- **PCR** has no KP-specific check anywhere in `PriceChangeRequestService.php`/`PriceChangeRequestController.php` — only generic `DataScope::customerAccessible()` (`PriceChangeRequestService.php:400`), which enforces portfolio membership, not segment.
- **Meetings** (`KpMeetingsController.php`) gates by permission (`kp.fol.view`/`kp.accounts.view`), not `customer_class`. Customer search (lines 97-108) uses generic `OrgScopeService::applyCustomerScope` — no KP-prefix filter at the query level.

So today, a rep whose portfolio spans KP and MT/GT customers **can** create a PCR or a Meeting against a non-KP customer. Nothing stops it.

### 2.5 Gap: Operations modules bypass all of the above

`OperationsController` — Backorders, Fill Rate, Customer Orders — never touches `UserCustomerAssignment` or `OrgScopeService`. `applySalesConsultantBackorderScope()` (`OperationsController.php:1749-1763`) and the equivalent fill-rate/customer-order call sites go straight to `SalesConsultantScope::appliesTo()`/`repCode()` against `sales_consultant_rep_code` — the single Acumatica field, full stop. This is exactly where the superior/junior mismatch bites hardest: these are the modules that show *live operational exposure* (what's backordered, what's fulfilling, what's ordered), and they're the ones still hard-pinned to whichever single name Acumatica happens to have on file.

---

## 3. Goals

| ID | Goal |
|----|------|
| G1 | A customer can have two coexisting assignment records — an **owner** (sector-level oversight, typically the superior) and a **servicing rep** (day-to-day contact, typically the junior) — without assigning one wiping the other. |
| G2 | Backorders, Fill Rate, and Customer Orders visibility respects `UserCustomerAssignment` (with org-tree rollup for superiors) in addition to Acumatica's rep_code — not rep_code alone. |
| G3 | One explicit, documented, enforced answer for whether PCR and Meetings are KP-only like FOL, or intentionally cross-segment. |

### Non-goals

- Changing Acumatica's data model — it stays one-rep-per-customer at the ERP level; this PRD only changes how OrderWatch *interprets and overlays* that.
- A new org-chart/assignment admin UI from scratch — extend whatever bulk/manual attachment tooling already exists (per prior team requirements: manual attach by Customer ID, bulk upload) rather than building a parallel one.
- Auto-migrating existing `'primary'` assignment rows into `owner`/`servicing` by guessing — see Open Questions. That's a human decision, not a script's.

---

## 4. Functional Requirements

### 4.1 Gap 1 — Assignment data model (owner vs. servicing)

| # | Requirement |
|---|---|
| FR1 | `assignment_type` takes real values: `owner`, `servicing`. Existing `'primary'` rows are treated as `servicing` until reviewed (see Open Questions) — never silently reinterpreted as `owner`. |
| FR2 | `CustomerAssignmentService::syncAssignments()` scopes its delete-and-replace to one `assignment_type` at a time. Replacing a user's `servicing` assignments must never delete an `owner` row (for that same user or anyone else) on the same customer, and vice versa. |
| FR3 | Bulk/manual assignment tooling lets an admin specify `assignment_type` when attaching a customer to a user. |
| FR4 | At most one `owner` and one `servicing` assignment per customer at a time (keep the model simple; revisit only if a real multi-servicing-rep case shows up). |
| FR5 | Visibility resolution considers a customer "assigned" to a user if **any** assignment row exists for them, regardless of type — `owner` and `servicing` both count for "this is mine." |

### 4.2 Gap 2 — Extend assignment/org-scope to Operations modules

| # | Requirement |
|---|---|
| FR6 | `backordersFilteredQuery()`, the fill-rate query, and the customer-orders query resolve visible customers via `DataScope`/`OrgScopeService` — not `SalesConsultantScope`'s rep_code-only path alone. |
| FR7 | Resolution priority matches what `OrgScopeService` already does elsewhere: assignment rows first, rep_code fallback second. A rep with no assignment rows yet sees exactly what they see today — this is additive, not a cutover. |
| FR8 | The "My backorders" panel (sales-consultant detail page) uses the fully resolved customer-id set (assignment ∪ rep_code), not `rep_code` alone, once FR6 lands — otherwise it under-reports for anyone whose real portfolio was fixed via assignment but not via Acumatica's rep_code field. |

### 4.3 Gap 3 — KP-only enforcement decision

| # | Requirement |
|---|---|
| FR9 | **Business decision needed** (see Open Questions): should PCR and Meetings hard-check `customer_class` starts-with `KP`, mirroring FOL's `ensureKpCustomer()`? |
| FR10 | If yes: add the same guard to `PriceChangeRequestService` and `KpMeetingsController`, enforced at creation, matching FOL's pattern exactly. |
| FR11 | If no (cross-segment is intentional): document it explicitly in both services so a future contributor doesn't "fix" this into KP-only by mistake, assuming it was an oversight. |

---

## 5. Guardrails

| # | Guardrail |
|---|---|
| G-1 | Visibility only ever **widens by union of already-fair sources** (a user's own assignments, plus org-subtree rollup for superiors) — never by loose inference (e.g. never "same region, so show it too"). |
| G-2 | Assignment writes stay audited — `assigned_by` is already on the table; confirm it's populated on every write path touched by this PRD, including the new owner/servicing distinction. |
| G-3 | No silent overwrite: changing a `servicing` assignment must never delete an `owner` row on the same customer, and the reverse. |
| G-4 | FR6–FR8 (Gap 2) ship additively — a rep who only has rep_code today must see unchanged results until their assignment data is corrected; this is not a replacement cutover. |
| G-5 | The KP-only decision (Gap 3) is one answer applied identically to PCR and Meetings — not decided ad hoc per module, and not left inconsistent with FOL without a documented reason. |

---

## 6. Open Questions

1. **Can a customer have more than one `servicing` rep at once** (a genuinely shared outlet), or is it always exactly one servicing rep + optionally one owner? Affects FR4.
2. **Backfilling existing `'primary'` rows**: how do we know, for each existing assignment, whether it represents a superior's oversight or a junior's actual day-to-day service? This needs a one-time human review (likely by department/HOD), not an automated guess — a wrong guess here silently misattributes visibility.
3. **Rollout order for Gap 2**: ship assignment-aware scoping for Backorders first (it's the module already under active development this session), then Fill Rate, then Customer Orders — or all three together?
4. **Gap 3 decision**: is PCR/Meetings cross-segment access already an intentional design choice (a price change or a customer visit isn't obviously KP-exclusive the way a Free-On-Loan dispenser is), or was FOL's `ensureKpCustomer()` guard simply never copied over? Needs a business call, likely the same stakeholder(s) behind the original FOL KP restriction.

---

## 7. Acceptance Criteria

- [x] A customer can carry an `owner` assignment (e.g. sector head) and a `servicing` assignment (junior rep) simultaneously; writing one never deletes the other.
- [x] A rep with a `servicing` (or `owner`) assignment row for a customer sees that customer's backorders/fill-rate/order history even when Acumatica's `sales_consultant_rep_code` points to someone else.
- [x] A superior's org-subtree visibility covers Backorders/Fill Rate/Customer Orders the same way it already covers FOL/PCR/Meetings/customer lists today.
- [x] Reps with no assignment rows see unchanged results after Gap 2 ships (no regression) — assignment is **unioned** with rep-code, not a replacement.
- [x] PCR and Meetings are **intentionally cross-segment** (documented on services); only FOL enforces KP-only via `ensureKpCustomer()`.
- [ ] Existing `'primary'` assignment rows are reviewed and reclassified as `owner`/`servicing` by a human, not auto-migrated by guess. **(Ops / HOD process — still open)**

## 8. Timeline & Milestones (fill in with your team)

| Milestone | Owner | Target Date | Status |
|---|---|---|---|
| Resolve Open Questions 1, 2, 4 with business stakeholders | | | Q1=one owner+one servicing; Q4=cross-segment PCR/Meetings. Q2 backfill still human. |
| Gap 1 — assignment_type + scoped sync logic | | | **Done** |
| Gap 2 — Backorders onto DataScope/OrgScopeService | | | **Done** |
| Gap 2 — Fill Rate + Customer Orders onto DataScope/OrgScopeService | | | **Done** |
| Gap 2b — Consultant detail / `?rep_code=` uses assignment ∪ rep (FR8) | | | **Done** (2026-07-23) |
| Gap 3 — PCR/Meetings KP guard (or documented exception) | | | **Done** (documented cross-segment) |
| Backfill review of existing `'primary'` assignment rows | | | **Pending** human review |

---

## 9. Implementation Decisions and Outcome (2026-07-23)

### Code complete

| Area | Evidence |
|---|---|
| Owner / servicing types | `UserCustomerAssignment::TYPE_OWNER` / `TYPE_SERVICING`; legacy `primary` kept |
| Scoped sync (no wipe across types) | `CustomerAssignmentService::syncAssignments()` / `assignCustomer()` |
| Admin UI type selector | `CustomerAssignmentFields.tsx` + assignment API `assignment_type` required |
| Ops Backorders / Fill Rate | `DataScope::scopedCustomerAcumaticaIds` in `OperationsController` |
| Consultant detail + `?rep_code=` | `SalesPortfolioService::portfolioCustomerIdsForRepCode()` — assignment ∪ SO rep |
| Sales consultant order metrics | `SalesConsultantController::ordersBaseQuery()` uses same union |
| PCR / Meetings | Docblocks: intentionally cross-segment; FOL remains KP-only |
| My Portfolio dashboard | `SalesPortfolioService` + `/app/kp/accounts` uses personal book / team DataScope |

### Still not automated

- Human reclassification of historical `primary` → `owner` or `servicing` (do not script-guess).
- Optional future: multi-servicing-rep per customer (currently one servicing + one owner max).

### Related product work (same session, separate PRDs)

| PRD / feature | Status |
|---|---|
| My Portfolio dashboard (`PRD-sales-consultant-dashboard`) | Implemented (KPIs, tabs, tooltips) |
| Kimfay Genius AI (`PRD-kimfay-genius-ai-insights`) | Implemented (queue, weekly lock, dual-role) |
| PCR mail recipients | Super-admin list + attach recipients |
| Customer visibility fairness (this doc) | **Gaps 1–3 code done**; FR8 panel fix done; primary backfill pending |
