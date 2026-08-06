# Customer Portfolio Attribution — Implementation Status & How It Works

> Companion document to [PRD-customer-portfolio-attribution.md](PRD-customer-portfolio-attribution.md).
> Covers the **backend foundation** scope (§7.1–§7.4, §8.2).

---

## Status Legend

✅ &nbsp;Completed &nbsp;|&nbsp; ⬜ &nbsp;Pending / Out of Scope

---

## 1. Completed Work Summary

| # | Deliverable | Status |
|---|-------------|:------:|
| 1 | Database migration (6 new/extended tables) | ✅ |
| 2 | Five new Eloquent models | ✅ |
| 3 | `attribution.php` config (precedence + rule sources) | ✅ |
| 4 | `CustomerAttributionService` — central engine (~550 lines) | ✅ |
| 5 | Value objects (`IdentityResolution`, `CustomerAssignmentResolution`) | ✅ |
| 6 | §7.3 mapped-only gate fix across 3 scoping paths | ✅ |
| 7 | Feature tests (17 new + 1 updated legacy test) | ✅ |
| 8 | Model fillable fix (`main_account_name`, `sales_channel_code`) | ✅ |

**Test result: 41 / 41 passing** (17 `CustomerAttributionTest` + 24 `TeamManagementTest`)

---

## 2. How It Works

### 2.1 PRD §7.1 — Identity Resolution

<span style="color:green">✅ Implemented</span>

When the system encounters a sales-rep alias (e.g. `EMP-123`, `REP-45`, or `JDOE`), it must
deterministically map it to a single active `User`. The resolution walks three sources in strict
priority order:

| Priority | Source | Table / Column |
|:--------:|--------|----------------|
| 1 | Employee number | `users.employee_number` |
| 2 | Rep code | `users.rep_code` |
| 3 | Rep mapping | `user_acumatica_rep_mappings.acumatica_rep_code` |

**Rules:**
- All comparisons use `UPPER(TRIM(value))` normalization (case-insensitive, whitespace-stripped).
- If two **active** users match at the **same priority level** → status is `AMBIGUOUS`.
- If users match at **different priority levels** → status is `CONFLICT`.
- If the only matches are **inactive** users → status is `INACTIVE` (reported separately from unresolved).
- If no matches at all → status is `UNRESOLVED`.

**Entry point:** `CustomerAttributionService::resolveIdentity(string $alias): IdentityResolution`

---

### 2.2 PRD §7.2 — Customer Assignment Precedence

<span style="color:green">✅ Implemented</span>

Each customer can be attributed to a user through six rule sources. The resolver collects all
matching candidates and picks the winner by priority (lowest number wins):

| Priority | Source | Description |
|:--------:|--------|-------------|
| 1 | `manual_override` | Admin-created override (highest authority) |
| 2 | `workbook_customer` | Explicit assignment from the team workbook |
| 3 | `main_account` | Customer belongs to a main-account group owned by the user |
| 4 | `region` | Customer's geographic region matches the user's region rule |
| 5 | `customer_rep_alias` | Customer's rep alias resolves to the user (§7.1) |
| 6 | `so_rep_alias` | Most recent sales order's rep alias resolves to the user |

**Tie-breaking:** If two distinct users tie at the winning priority, the customer is marked
`UNRESOLVED` (no silent winner — requires admin intervention).

**Entry point:** `CustomerAttributionService::resolveCustomerAssignment(string $customerId): CustomerAssignmentResolution`

---

### 2.3 PRD §7.3 — Mapped-Only Sales Consultant Gate

<span style="color:green">✅ Implemented (including bug fix)</span>

This is the **critical security fix**. Before this work, a Sales Consultant's visible customer list
was a **union** of explicit assignments *plus* rep-code sales-order matches. PRD §7.3 mandates the
opposite:

> A mapped-only Sales Consultant (one who holds the Sales Consultant role AND has ≥1 active servicing
> assignment) sees **ONLY** the customers explicitly assigned to them. Rep-code sales-order matches
> are **never** unioned into their portfolio.

**The gate definition:**

```
isMappedOnlyConsultant(user) =
    user.hasRole('Sales Consultant')
    AND user has ≥1 active servicing assignment (effective_from/to brackets today)
```

**Three scoping entry points were fixed:**

| Scoping Class | Method | Old Behavior | New Behavior |
|---------------|--------|-------------|--------------|
| `OrgScopeService` | `applySingleUserCustomerScope()` | Unioned mapped IDs with rep-code SO subquery | Uses `directCustomerIds()` only — no SO union |
| `SalesConsultantScope` | `applyCustomerScope()` / `applyOrderScope()` | Scoped by `rep_code` column | Scopes by `customer_acumatica_id` using mapped IDs |
| `DataScope` | `scopedCustomerAcumaticaIds()` | Short-circuited to org-wide before gate check | Gate evaluated **before** org-wide short-circuit |

**Precedence over secondary roles:** If a consultant also holds a broad-access secondary role
(e.g. Operations), the mapped-only restriction still takes precedence — they see only mapped IDs.

---

### 2.4 PRD §7.4 — Directional Hierarchy Visibility

<span style="color:green">✅ Implemented</span>

A user's visible customers are the **de-duplicated union** of their subtree's direct portfolios:

```
visibleCustomerIds(user) = union of directCustomerIds(node) for each node in descendants(user) ∪ {user}
```

- Visibility flows **downward** only (via `reports_to_user_id` BFS traversal).
- Reportees cannot see their manager's or siblings' portfolios.
- De-duplication ensures a customer shared across multiple reportees appears once.

**Entry points:**
- `CustomerAttributionService::visibleCustomerIds(int $userId): array`
- `CustomerAttributionService::visibleUserIds(int $userId): array` (delegates to `OrgTreeService::descendantIds`)

---

### 2.5 Effective Portfolio Resolution

<span style="color:green">✅ Implemented</span>

A user's **direct** (non-hierarchical) portfolio is computed as:

1. Explicit `user_customer_assignments` (owner + servicing types)
2. Customers whose assignment resolves to this user via rule sources (§7.2)

**Crucially, this does NOT include rep-code sales-order matches** (per §7.3). This is what distinguishes
`directCustomerIds()` from the old rep-code-scoped approach.

**Entry point:** `CustomerAttributionService::directCustomerIds(int $userId): array`

---

## 3. File Inventory

### Created Files

| File | Purpose |
|------|---------|
| `backend/database/migrations/2026_07_31_100000_create_customer_attribution_tables.php` | Schema: 6 tables/extensions |
| `backend/app/Models/CustomerAssignmentRule.php` | Rule model (manual_override, region, main_account, etc.) |
| `backend/app/Models/CustomerAttributionAudit.php` | Audit trail model |
| `backend/app/Models/SalesChannel.php` | Sales channel catalogue model |
| `backend/app/Models/DepartmentHodAssignment.php` | HOD-to-department mapping model |
| `backend/app/Models/TeamMigrationBatch.php` | Migration batch tracking model |
| `backend/config/attribution.php` | Precedence levels, identity sources, role config |
| `backend/app/Services/Team/IdentityResolution.php` | Value object for §7.1 results |
| `backend/app/Services/Team/CustomerAssignmentResolution.php` | Value object for §7.2 results |
| `backend/app/Services/Team/CustomerAttributionService.php` | Central service (~550 lines) |
| `backend/tests/Feature/CustomerAttributionTest.php` | 17 feature tests |

### Modified Files

| File | Change |
|------|--------|
| `backend/app/Models/AcumaticaCustomer.php` | Added `main_account_name`, `sales_channel_code` to `$fillable` |
| `backend/app/Services/Team/OrgScopeService.php` | Injected `CustomerAttributionService`; added §7.3 gate; removed SO union |
| `backend/app/Support/SalesConsultantScope.php` | Delegated to `CustomerAttributionService`; scope by customer ID |
| `backend/app/Support/DataScope.php` | Added §7.3 gate before org-wide short-circuit |
| `backend/app/Models/UserCustomerAssignment.php` | Added `assignment_rule_id` to `$fillable` (migration support) |
| `backend/app/Models/User.php` | `hasRole()`, `primaryDepartment()` helpers |
| `backend/tests/Feature/TeamManagementTest.php` | Updated legacy test to assert §7.3 exclusion |

---

## 4. Test Coverage

### New Tests — `CustomerAttributionTest.php` (17 tests)

| # | Test | PRD Section |
|---|------|:-----------:|
| 1 | Identity resolves by employee number with priority | §7.1 |
| 2 | Identity resolves by rep code when no employee number match | §7.1 |
| 3 | Identity resolves by rep mapping as lowest priority | §7.1 |
| 4 | Identity reports unresolved for unknown code | §7.1 |
| 5 | Identity reports inactive only matches separately | §7.1 |
| 6 | Identity flags duplicate active matches at same priority | §7.1 |
| 7 | Identity blocks cross priority conflict | §7.1 |
| 8 | Manual override beats workbook assignment | §7.2 |
| 9 | Explicit workbook beats main account rule | §7.2 |
| 10 | Main account rule beats region rule | §7.2 |
| 11 | Unresolved when no candidates exist | §7.2 |
| 12 | Mapped only gate true for consultant with servicing assignment | §7.3 |
| 13 | Mapped only gate false without sales consultant role | §7.3 |
| 14 | Mapped only gate false without active assignment | §7.3 |
| 15 | Direct portfolio excludes rep code sales order matches | §7.3 |
| 16 | Visible customers are de-duped union of subtree | §7.4 |
| 17 | Reportee cannot see sibling or manager portfolio | §7.4 |

### Updated Legacy Test

| Test | Change |
|------|--------|
| `test_consultant_visibility_excludes_rep_code_customers_under_mapped_only_gate` | Renamed + updated assertion: consultant now sees only `ASSIGNED-01`, rep-code match `REP-01` is excluded per §7.3 |

---

## 5. Remaining PRD Sections

Implementation status as of 2026-07-31:

| Section | Description | Status | Implementation |
|---------|-------------|:------:|----------------|
| §7.5 | GT and MT team separation | ✅ | Primary department is authoritative; channel metrics resolve customers independently of shared roles. Team migration preview/apply preserves customer mappings and roles. |
| §7.6 | KP CRM access boundary | ✅ | Central `KpCrmAccessService`, explicit cohort role/table, capability payload, channel gate, and `kp.crm` middleware protect KP Accounts, Dormant, Items Not Ordered, Meetings, and Calendar. |
| §7.7 | Modern Trade business rules | 🟨 | MT1/MT2 classification fixture and Lawrence identity validation are implemented. The reviewed 420-row workbook JSON is still required before explicit assignments can be seeded. |
| §7.8 | Transaction attribution by `customer_acumatica_id` | ✅ | Sales Intelligence metrics derive transactions from the server-resolved customer set; browser-supplied customer IDs are not accepted as authorization. |
| §7.9 | Metrics | ✅ | SO count, CN count, gross/credit/net revenue, and ordered/credited/net volume use the same customer scope. |
| §7.10 | Sales channel classification | 🟨 | Channel catalogue, KP prefix rule, and reviewed MT1/MT2 names are implemented. GT, DTC/DTB, and E-commerce source definitions still require approved master-data rules. |
| §9 | Seeder and import plan | 🟨 | Idempotent `CustomerPortfolioFoundationSeeder` exists and reports missing identities. The version-controlled MT workbook JSON fixture is outstanding. |
| §10 | Admin workflow | 🟨 | User portfolio, KP access, migration preview, and migration apply APIs exist. Dedicated Team Member portfolio and Teams & Departments UI panels remain. |
| §11 | Sales Intelligence navigation + dashboard | ✅ | Channel-aware navigation and dashboard are implemented with server-side channel metrics. |
| §12 | API requirements | 🟨 | Channel metrics, user portfolio, KP access, and migration APIs are implemented. Assignment-rule CRUD/history/export endpoints remain. |
| §8.3 | Performance snapshot tables | ✅ | Effective-assignment and sales-performance snapshot tables exist; `portfolio:rebuild-attribution-snapshots` rebuilds the deterministic assignment cache. |

### 5.1 Current data blockers

The foundation seeder was run with `--force` and reported that the current database does not contain:

- `commercialtechlead@kimfay.com`
- `cco@kimfay.com`
- `susan@kimfay.com`
- `hbains@kimfay.com`
- `rbains@kimfay.com`
- Lawrence as `moderntrade.exec@kimfay.com` with employee number `P272`

No duplicate placeholder users were created. Reconcile or import the approved user dataset first, then rerun:

```bash
php artisan db:seed --class=Database\\Seeders\\CustomerPortfolioFoundationSeeder --force
php artisan portfolio:rebuild-attribution-snapshots
```

---

## 6. Architecture Diagram (Text)

```
                    ┌─────────────────────────┐
                    │   Admin / Workbook UI    │
                    │   (manual assignments)   │
                    └────────────┬────────────┘
                                 │
                                 ▼
         ┌───────────────────────────────────────────┐
         │        user_customer_assignments           │
         │  (effective_from/to, priority, rule_id)    │
         └────────────────────┬──────────────────────┘
                              │
                    ┌─────────▼─────────┐
                    │  customer_         │
                    │  assignment_rules  │
                    │  (6 types)         │
                    └─────────┬─────────┘
                              │
                ┌─────────────▼──────────────┐
                │   CustomerAttributionService │
                │                             │
                │  §7.1 resolveIdentity()     │
                │  §7.2 resolveAssignment()   │
                │  §7.3 isMappedOnlyGate()    │
                │  §7.4 visibleCustomerIds()  │
                │      directCustomerIds()    │
                └─────┬───────┬───────┬───────┘
                      │       │       │
            ┌─────────▼──┐ ┌──▼──────▼────────┐
            │OrgScope    │ │SalesConsultant   │
            │Service     │ │Scope             │
            │(§7.3 gate) │ │(§7.3 gate)       │
            └─────┬──────┘ └────┬─────────────┘
                  │             │
                  └──────┬──────┘
                         ▼
                  ┌──────────────┐
                  │  DataScope   │
                  │  (unified    │
                  │   entry)     │
                  └──────┬───────┘
                         │
            ┌────────────▼────────────┐
            │  Eloquent Query Scopes  │
            │  (customer / order)     │
            └─────────────────────────┘
```

---

## 7. How to Run the Tests

```bash
cd backend

# New attribution tests only
"C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" vendor/phpunit/phpunit/phpunit \
    --testdox --filter CustomerAttributionTest tests/Feature/CustomerAttributionTest.php

# Full regression (attribution + team management)
"C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe" vendor/phpunit/phpunit/phpunit \
    --testdox tests/Feature/CustomerAttributionTest.php tests/Feature/TeamManagementTest.php
```

> **Note:** PHP 8.3.16 is used (not 8.4.15) because the 8.4.15 NTS build lacks the `mbstring`
> extension required by PHPUnit.
