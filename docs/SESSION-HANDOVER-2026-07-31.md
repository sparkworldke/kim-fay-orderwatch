# Session Handover: Customer Portfolios, FOL, KP Operations, Redis, and Dashboard Filters

**Date:** 31 July 2026  
**Project:** Kim-Fay OrderWatch / Sight  
**Workspace:** `C:\laragon\www\kim-fay-orderwatch`

## 1. Session Objective

This session defined and implemented the foundation for customer-to-user portfolio attribution, directional HOD/reportee visibility, GT/MT/KP team administration, Sales Intelligence navigation, KP access boundaries, FOL equipment and consumable controls, Redis-backed caching, sales-channel classification, and scoped dashboard filtering.

The governing product documents are:

- `docs/PRD-customer-portfolio-attribution.md`
- `docs/customer-portfolio.md`
- `docs/PRD-FOL-review.md`
- `docs/PRODUCTION-PERFORMANCE-REDIS.md`

## 2. Business Rules Confirmed

### 2.1 Customer attribution

- The canonical join key for dashboard transactions is `customer_acumatica_id`.
- Manual customer mappings must use the exact Acumatica customer ID.
- Acumatica identity resolution uses both `users.employee_number` and `users.rep_code`.
- Identity aliases resolve a user but do not silently override an approved customer assignment.
- Ambiguous or duplicate identity matches are reconciliation errors, not automatic matches.
- A user with the canonical `Sales Consultant` role and active customer mappings sees only their mapped customers.
- This mapped-only rule takes precedence even when the user has additional ordinary roles.
- A Sales Consultant without a portfolio mapping retains the legacy rep-code fallback until mappings are completed.
- An HOD or other eligible manager sees the deduplicated union of their own portfolio and their reportee subtree.
- Managers can drill down from a reportee to that reportee's customers.

### 2.2 Teams and transfers

- GT and MT are separate teams.
- Teams may be migrated to a new HOD.
- Selected members may be transferred between GT, MT, KP, and other departments.
- Transfers require auditable effective dates and must invalidate affected portfolio caches/snapshots.

### 2.3 Modern Trade notes

- Lawrence Amukhono: employee number `P272`, email `Moderntrade.exec@kimfay.com`, Consumer Sales / Modern Trade / Modern Trade Executive, reporting to Purity as HOD.
- Beryl Muga covers all Coast outlets and George Amenya covers all Nyanza outlets; neither has a main account.
- Georgina, Lucy, and Jane own Quick Mart, Naivas, and Majid main accounts respectively, irrespective of outlet or region.
- Kevin Werunga owns Chandarana, China Village, and Onn the Way main accounts; remaining outlets come from the outlet workbook.
- Zipporah's accounts come from the Main Account sheet.
- Lilian owns Magunas and other Mountain MT outlets, including Naivas outlets in Thika.
- Lawrence owns Khetias and MT outlets in Rift.
- Dennis owns Kassmart, Leestar, Jaza, Eastleigh, Kamindi, and Kikuyu Selfridges, plus Nairobi Magunas and selected Powerstar/Cleanshelf outlets.
- Final workbook mappings still need reconciliation against exact Acumatica customer IDs before production assignment.

### 2.4 KP access

- KP CRM access is limited to explicitly mapped KP, Admin, Executive, and C-suite access.
- Named intended viewers include the KP team, Titus, Vignesh, Susan (HOD), Hartaj, and Raj.
- Named users missing from the current database are not fabricated by the seeder; they are reported as warnings for reconciliation.
- KP CRM and FOL are grouped under **KP Operations**.
- **KP Cumulative Sales** is available under **Sales Intelligence**.

### 2.5 Sales channels

Classification precedence is:

1. Exact customer-ID override.
2. KP customer prefix rule.
3. Customer-category rule.
4. Unclassified/reconciliation queue.

Initial rules:

- `CSECOMM` maps to E-commerce.
- `CSDIST` maps to GT.
- `CSWSALERS` maps to GT.
- MT must use exact customer-ID overrides where `CSECOMM` is shared or ambiguous.
- A category that resolves to multiple primary channels must not be silently classified.

## 3. Navigation Implemented

The Sales Intelligence menu provides channel and personal portfolio entry points, including MT, GT, DTC/DTB, E-commerce, KP Cumulative Sales, and My Portfolio as applicable.

KP CRM was removed from Sales Intelligence and merged into the KP Operations navigation group alongside FOL.

An Administration entry was added for customer sales-channel classification and exact customer overrides.

## 4. Portfolio Backend Foundation

The implementation includes:

- Deterministic customer attribution and identity resolution.
- Effective customer-assignment snapshots.
- HOD/reportee directional scope.
- KP CRM access assignments and middleware.
- Team migration services and audit records.
- Sales Intelligence aggregation services and API endpoints.
- Performance snapshot storage.
- Channel category rules and exact customer overrides.
- Administration APIs for assignments, channel rules, and team workflows.
- Cache-domain invalidation following mapping/team changes.

Relevant migrations:

- `2026_07_31_100000_create_customer_attribution_tables.php`
- `2026_07_31_100002_create_portfolio_performance_and_access_tables.php`
- `2026_07_31_100003_add_channel_rules_and_report_indexes.php`

Snapshot command:

```bash
php artisan portfolio:rebuild-attribution-snapshots
```

The last local rebuild reported 210 resolved customers and 90 unresolved or ambiguous customers. Those exceptions require data reconciliation rather than arbitrary assignment.

## 5. FOL Enhancement

Inventory items can be classified as:

- `fol_item`: equipment/dispenser issued free on loan.
- `consumable`: items used to calculate supporting sales evidence.
- `both`: permitted in either catalogue role as a safety classification.

FOL requests now store equipment and consumable selections separately. Consumable evidence includes previous three-month and six-month sales metrics, item-level snapshots, and the metric timestamp used for approval.

Guardrails prevent:

- Consumables from being issued as free equipment.
- The same SKU being used simultaneously as equipment and evidence on one request.
- Unsupported inventory classifications from entering the approval workflow.

Relevant migration:

- `2026_07_31_100001_enhance_fol_consumable_evidence.php`

## 6. Orders Customer Selector Correction

The orders customer multi-select behavior was corrected so **Select all** operates on the currently searched result set. For example, searching `Chandarana` and selecting all selects only results containing `Chandarana`, not every customer in the database.

## 7. Dashboard Filters

The Operations Dashboard now includes filters for:

- Customer name or Acumatica customer ID.
- Status.
- Customer segment.
- Brand.
- Consultant.

The same filter state applies to:

- KPI totals.
- Order-volume trend graph.
- Open Orders by Date table.
- Expanded daily status order lists.

The API endpoint `GET /api/dashboard/filter-options` returns server-authoritative filter options and the consultant lock state.

For a user holding the canonical Sales Consultant role:

- The consultant field displays only their name and is read-only.
- Requested consultant parameters cannot broaden backend access.
- Graphs and lists use the user's mapped Acumatica customer portfolio.
- If no approved mapping exists, the legacy rep-code fallback applies.

## 8. Redis and Performance Work

The application now supports Redis-first dashboard caching with database fallback.

Key configuration:

```dotenv
CACHE_STORE=redis
DASHBOARD_CACHE_STORE=redis
CACHE_FALLBACK_STORE=database
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
```

`predis/predis` was added as a Composer dependency. Response/domain caching was added for expensive dashboard, KP CRM, KP Operations, and Sales Intelligence reads. Relevant writes and sync operations bump their cache domains.

The Items Not Delivered query caches non-pagination result rows for 15 minutes using Redis first and the database cache as fallback. Supporting report indexes were added in migration `100003`.

The service worker was changed to cache static assets only. It no longer intercepts API or navigation requests, preventing cached HTML from causing React hydration failures after deployment.

## 9. Production Deployment Issue Found

Production returned:

```text
Target class [Database\Seeders\CustomerPortfolioFoundationSeeder] does not exist.
```

The server check returned no output:

```bash
test -f database/seeders/CustomerPortfolioFoundationSeeder.php && echo "Seeder found"
```

This confirms the file was absent from production. Locally, the seeder and many related portfolio files are currently **untracked by Git**. A Git-based deployment will not include untracked files.

Before deployment, review and explicitly add every required implementation file. Do not commit unrelated dirty-worktree changes blindly.

At minimum, confirm these are tracked:

- Portfolio migrations `100000` through `100003`.
- `CustomerPortfolioFoundationSeeder.php`.
- Portfolio attribution, snapshot, KP access, team migration, and channel-classification services.
- Associated models, controllers, middleware, commands, routes, frontend routes, and documentation.

## 10. Production Runbook

From the deployed backend directory:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend

test -f database/seeders/CustomerPortfolioFoundationSeeder.php && echo "Seeder found"
composer install --no-dev --optimize-autoloader
composer show predis/predis
redis-cli ping

php artisan optimize:clear
php artisan migrate --force
php artisan db:seed --class='Database\Seeders\CustomerPortfolioFoundationSeeder' --force
php artisan portfolio:rebuild-attribution-snapshots

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Expected Redis response:

```text
PONG
```

If `redis-cli` is unavailable, verify through Laravel:

```bash
php artisan tinker --execute="Cache::store('redis')->put('redis_test', 'working', 60); dump(Cache::store('redis')->get('redis_test'));"
```

Expected value:

```text
"working"
```

The foundation seeder is designed to be idempotent and may be rerun with `--force` after data corrections.

## 11. Verification Completed

- FOL tests: 4 passed, 22 assertions.
- Portfolio/backend test suite at the completed foundation stage: 49 passed, 198 assertions.
- Dashboard legacy consultant-scope regression test: passed, 4 assertions.
- PHP syntax check for the dashboard controller: passed.
- Dashboard filter-options route registration: passed.
- Production frontend build: passed.
- `git diff --check` for the latest dashboard files: passed.
- A full TypeScript check still reports unrelated pre-existing errors in other modules; no new dashboard TypeScript error appeared in that output.

## 12. Remaining Operational Work

1. Reconcile the Excel MT outlet/main-account rows to exact Acumatica customer IDs.
2. Create or reconcile missing named users, including Lawrence and the named KP viewers.
3. Assign canonical many-to-many roles rather than relying only on `users.role` or `is_consultant`.
4. Resolve the 90 unresolved/ambiguous attribution records.
5. Review all untracked implementation files and commit the intended deployment set.
6. Deploy migrations, code, Composer lockfile, and frontend build together.
7. Verify Redis connectivity from the PHP/Laravel runtime, not only from the shell.
8. Run the seeder and snapshot rebuild after deployment.
9. Test with a Sales Consultant, an HOD with reportees, a KP-authorized user, and a denied user.
10. Confirm dashboard totals, graphs, and expanded lists return the same scoped population for identical filters.

## 13. Acceptance Checks

- A mapped Sales Consultant cannot select or retrieve another consultant's customers.
- Adding a secondary role does not bypass the Sales Consultant mapped-only gate.
- An HOD sees reportees and can drill into each reportee's customers.
- A peer cannot see a sibling consultant's portfolio.
- Exact customer overrides take precedence over category-based channel classification.
- KP CRM endpoints deny users without an explicit qualifying assignment.
- Team transfers update future scope without rewriting historical attribution.
- Redis failure falls back to the configured database cache without exposing broader data.
- Dashboard KPI totals equal the filtered trend/table totals for the same date and filter set.

