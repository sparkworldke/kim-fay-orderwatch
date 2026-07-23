# OrderWatch — Full Feature & Implementation Status (Code-Verified)

**Date:** 23 Jul 2026
**Method:** Every claim below was verified directly against the current codebase (backend Laravel controllers/services/models, frontend TanStack routes/hooks) — not copied from prior docs. Prior docs (`PROJECT-OVERVIEW.md` 10 Jul, `orderWatch-modules.md` 8 Jul, `implementation-and-production-2026-07.md` 14 Jul) were used as a starting hypothesis only and are noted as stale wherever code disagrees with them.
**Scope:** Six areas of the system were independently audited: (1) Acumatica sync engine & core operations, (2) email ingestion & order matching, (3) KP CRM suite, (4) FOL / Price Change Requests / DTC Calltronix, (5) AI features, (6) admin/platform plumbing.

---

## 0. Executive summary — what's real, what's not

OrderWatch is a large, genuinely built system — the large majority of what follows is fully implemented with real database-backed logic, not scaffolding. The list below is the set of **concrete gaps, dead code paths, and mismatches between UI and backend** found during this audit. These are the things worth fixing or at least being aware of before relying on the affected features:

| # | Finding | Area | Impact |
|---|---|---|---|
| 1 | **FOL notification emails are globally redirected to a single test inbox** (`fol.mail_testing_mode`, default `true`) | FOL | Real FOL approval/notification emails are not reaching actual recipients today |
| 2 | **FOL `duplicate_policy` setting is fully built in admin UI but never enforced** — no duplicate-FOL detection exists in code at all | FOL | Admin thinks a control exists; it does nothing |
| 3 | **PCR approval stages are hardcoded in a seeder**, no admin API/UI to edit them (unlike FOL, which is fully dynamic) | Price Change Requests | Changing PCR approval chain requires a code/seeder change, not an admin action |
| 4 | **PCR settings page has no frontend at all** — API works, nothing calls it | Price Change Requests | Margin-floor %, ERP-updater roles, mail recipients are only editable via raw API calls |
| 5 | **Mailbox "Stop Sync" button is cosmetic** — the cancel flag it sets is only read by dead code; the real sync path ignores it entirely | Email/Order Match | Clicking Stop shows "stopped" in the UI but the sync keeps running to completion |
| 6 | **AI PO-extraction fallback (Claude/OpenAI/OCR extractors) is fully coded but never wired into the extraction service's constructor** — dead code, never invoked | Email/Order Match | The "AI extracts PO when patterns fail" behavior described in code comments doesn't happen |
| 7 | **`ai_fallback_enabled` toggle on sender import configs is stored and editable in the UI but never read by any extraction logic** | Email/Order Match | The toggle does nothing |
| 8 | **AI-scored Order Match pipeline only runs when a human clicks "Run match pipeline"** — no cron ever calls it; scheduled jobs use the older deterministic matcher instead | Email/Order Match | The match queue AI scoring is not actually automatic despite looking like a background pipeline |
| 9 | **Order Match "Mark duplicate," "Rerun," and the AI-pipeline audit log have real backend routes/services with zero frontend callers** | Email/Order Match | Invisible-but-functional API surface; not a bug, but not usable today without new UI |
| 10 | **AI Chat Assistant cannot actually use xAI** even though the key-management layer supports it (binary OpenAI/Anthropic branching in the controller) | AI | Configuring only an xAI key breaks Chat while Intelligence/Genius work fine |
| 11 | **"Sales Management Prompts" contains zero AI/LLM calls** — it's a deterministic statistics engine (median order-cycle gaps), despite living next to the AI controllers | AI | Not a bug, but a naming/expectation mismatch worth flagging to stakeholders |
| 12 | **Inventory AI insight's promotion/price-change correlation is hard-coded to return `[]`** — explicitly commented as not-yet-wired | Inventory | AI SKU insights only explain variance, never correlate to promos/price changes |
| 13 | **`/app/discrepancies` is an orphaned page built entirely on mock demo data**, not linked from any nav — superseded by the real match-status handling inside `/app/orders` | Orders | Dead/confusing page if anyone finds the URL directly |
| 14 | **Daily Management Report's "send time" config field doesn't drive the actual schedule** — the real cron is hard-coded `Tue–Sat 07:00 Africa/Nairobi` in `routes/console.php` | Admin | An admin editing "Send time" in the UI will see no effect until a code deploy |
| 15 | **Notification Rules are fully configurable in the UI but the automated dispatch cron that would use them is disabled by default** | Admin | Rule config has no live effect until that cron job is re-enabled |
| 16 | **KP Meetings write endpoints (create/update/delete/respond/target) are blocked for every role except Administrator/CS Manager/CS Agent** by a middleware allowlist gap — despite the feature being explicitly designed for cross-role use (Sales Consultants, HODs, Executives) | KP CRM | Sales Consultants likely cannot create/edit their own meetings today; looks like an unflagged bug |
| 17 | **`kp.accounts.view` permission is checked in 4 controllers but never seeded to any role** — access is really gated by `kp.fol.view` instead, and HOD lacks that permission by default | KP CRM | HODs may be unable to open KP Accounts/Dormant/Meetings/Calendar despite being an intended persona |
| 18 | **Two different "Items Not Ordered" algorithms coexist** (KP CRM page vs. Sales Portfolio Dashboard tab) with different overdue thresholds and value formulas | KP CRM | Not a bug, but the two views can disagree with each other for the same customer |
| 19 | **`KpAccountsController::summary()` is fully coded but has no route** — dead code; a frontend legacy-fallback path targets this nonexistent route and will always fail | KP CRM | Cosmetic dead code, no user-visible effect (superseded by Sales Portfolio's own summary) |
| 20 | **Sales Portfolio Dashboard deviates from its own PRD** in several calculations: "concentration" is top-3 customers everywhere in code though the PRD specifies top-5; "declining" uses a blunt 45-day-silence rule instead of the PRD's 40%-drop-vs-3-month-average rule; fill rate is a simplified re-derivation, not confirmed to match the Operations fill-rate calculator | KP CRM | Numbers on the portfolio dashboard may not match Operations' fill-rate numbers for the same customer |
| 21 | **Sales Portfolio Dashboard is missing an entire Trends tab, segment/brand/product breakdowns, and new/reactivated-customer detection** per its own PRD's phased plan (P1d/P1e not built yet) | KP CRM | Known, PRD-documented gap, not a surprise finding |
| 22 | **Hourly auto-match cron job is confirmed still disabled by default** (`is_enabled: false`) | Sync/Cron | Matches what old docs already said — still true |
| 23 | **Backorder advanced charts (trend, lead-time correlation, category distribution) are now fully built** — this contradicts the stale `PROJECT-OVERVIEW.md` claim that they were still "requested" | Operations | Documentation was behind code, in the good direction |
| 24 | **`AcumaticaRoute` records are not synced from Acumatica** — they're a byproduct of customer-assignment Excel imports; there is no dedicated Routes admin page | Operations | "Routes" is not a first-class synced entity despite appearing in dashboard drill-downs |
| 25 | **Reconciliation results table is only populated by the customer sync** — order/inventory/backorder/fill-rate syncs never write to it; dead letters have no retry action, only re-sync-and-hope | Operations | Reconciliation coverage is narrower than the feature name implies |
| 26 | User bulk-import field whitelist **(memory note) confirmed accurate**: password is never set from bulk/import data, and updates only touch matched fields — with one nuance: the main staff-import run auto-creates new (inactive) users for high-confidence unmatched rows, not just matched ones | Admin | Slightly broader than "only touch matched users" — worth noting precisely |

Everything else documented below is **fully implemented** unless explicitly marked partial/gap in its own section.

---

## 1. Acumatica Sync Engine & Core Operations

### 1.1 Sync engine architecture

- **Client**: `backend/app/Services/Admin/AcumaticaClient.php` — OAuth2 + OData wrapper around Acumatica's IpayV2 contract API. Caches the access token, auto-refreshes on 401, hand-builds OData `$filter`/`$expand` params (Guzzle's own encoding breaks Acumatica), pages results 100 at a time (500-page cap, 500ms delay between pages), and maintains a 6-hour "entity unavailable" cache so it stops re-probing endpoints this Acumatica tenant doesn't expose (falls back through candidate names, e.g. `InventoryItem` → `StockItem`; `Zone` → `ShippingZone` → `ShipZone`).
- **Sync services** (`backend/app/Services/Admin/`), each logged to `AcumaticaSyncLog` (status, counts, filters, heartbeat-based stale detection, stop-request flag):
  - **Sales orders** (`AcumaticaSalesOrderSyncService`) — full sync, status-only resync, customer-scoped sync, credit-notes-and-more sync, and `pruneMissingSalesOrders` (deletes local SOs Acumatica no longer returns, cascading to backorder lines/fill-rate snapshots/SO lines). Every full sync also runs a two-phase reconciliation that purges anything not present in the latest payload.
  - **Inventory** (`AcumaticaInventorySyncService`) — full item sync vs. stocks-only (qty/cost) sync; classifies brand/product type; logs every quantity change to `AcumaticaInventoryRunRateLog` for run-rate prediction.
  - **Backorders** (`AcumaticaBackorderSyncService`) — derives backorder lines from open SO details, tags `shortfall_kind = active_backorder`, archives resolved lines to `BackorderResolution` before deleting, and (on manual date-limited runs) also reconstructs **completed-order shortfalls** from released invoice lines, because `QtyOnShipments` reflects allocation, not proof of delivery.
  - **Fill rate** (`AcumaticaFillRateSyncService`) — computes/stores per-SO snapshots via `FillRateCalculator`.
  - **Customers** (`AcumaticaCustomerSyncService`) — pulls all customers, resolves parent/child hierarchy, links shipping zones (auto-creates a zone stub if none exists), and writes field-level diffs to `AcumaticaReconciliationResult` for watched fields (customer class, payment terms, tax zone, shipping zone).
  - **Shipping zones** (`AcumaticaShippingZoneSyncService`) — tries a real zone master entity first, falls back to deriving zones from `Customer.ShippingZoneID` when this tenant doesn't expose one (recorded as `source: master|customers|none`).
  - **Sync diagnostics** (`AcumaticaSyncDiagnosticsService`) — reads the last 20 sync logs and asks OpenAI for likely cause/next steps, with a full rule-based fallback (failure rate, top errors, per-type counts) if AI isn't configured — never depends on AI being available.
- **Concurrency guard**: a shared trait blocks starting an overlapping sync type while one is already running; stale/orphaned "running" rows self-heal whenever the sync log list is viewed or a new sync starts.

### 1.2 Cron dispatch (fully DB-driven)

`CronJob::ensureDefaults()` seeds/repairs ~16 default job rows on every bootstrap. `routes/console.php` iterates all `CronJob` rows with `trigger_type=scheduler`; anything `is_enabled=false` or `status=paused` is never registered with Laravel's scheduler at all.

**Confirmed disabled by default in code** (not just DB drift — these are force-paused every time `ensureDefaults()` runs):
- `email-sales-order-auto-match` (hourly auto-match) — matches old docs, still true.
- `sync-monitor-alerts` and `order-match-notification-evaluation` — both paused "to control email volume."
- Legacy all-warehouse `inventory-sync-5h` — superseded by per-warehouse jobs.

**Active by default**: SO sync (every 2h), SO status sync (every 30 min), SO prune-missing (every 6h), per-warehouse inventory stock sync (twice daily, staggered), backorder processing (daily 00:30), fill-rate sync (00:01 + 12:30), FOL→SO retry (every 30 min), daily system health check (06:00), daily management report (Tue–Sat 07:00, hard-coded — see finding #14).

**"Tally" naming** — confirmed still just an internal nickname for the fill-rate module in code comments/docs; there is exactly one fill-rate data path, 100% Acumatica-sourced, no separate Tally ERP integration exists anywhere in code.

### 1.3 Orders

`OrderController` — scoped/paginated list with rich filtering (date range, customer, status, order type SO/QT/RC/CREDIT_NOTES_MORE, match status, flag source, has-email, free text); optional `with_fulfillment=1` adds a quantity-weighted fill-rate subquery and revenue-lost per order. Status changes to Rejected/Cancelled/On Hold/Credit Hold require a standardized reason code from the SO reason taxonomy (422 otherwise) — hierarchical parent/sub-reason, not free text. Create/delete are deliberately blocked (405 "Orders are managed via Acumatica sync") — by design. Match-discrepancy fields (matched PO number, conflicts) are joined in from the email-matching subsystem.

**Gap**: `/app/discrepancies` (finding #13) is a 146-line orphaned page built entirely on client-side mock data (`ORDERS` from `src/lib/demo-data`), zero API calls, not linked from any nav component. The real discrepancy/match-status handling lives inside `/app/orders` instead.

### 1.4 Inventory

`OperationsController` inventory endpoints + `InventoryRunRatePredictor` — real summary counts (low-stock, at-risk, out-of-stock), per-warehouse breakdown, and a genuine two-tier run-rate prediction (30-day depletion-only delta log, falling back to 30-day shipped-qty average when history is thin).

AI-powered SKU insight (`InventoryInsightController`/`InventorySkuDetailController`) caches to a DB table (4h TTL), calls OpenAI/Anthropic to explain sales variance, and forecasts monthly quantities — genuinely live, not mocked, and degrades gracefully (`ai_status: unavailable|failed`) rather than 500ing.

**Gap** (finding #12): `fetchPromotions()`/`fetchPriceChanges()` inside the insight service unconditionally return `[]`, with an explicit code comment that Acumatica's promotions/price-schedule endpoints aren't wired up yet.

### 1.5 Fill Rate

Formula, code-confirmed: eligible only for completed orders; `SUM(min(shipped,ordered)) / SUM(ordered) × 100`, rolled up by SKU (dedupes duplicate lines), capped at 100%. This is **quantity-weighted**, not value-weighted — a known, still-accurate PRD-vs-code discrepancy flagged in old docs.

KP (Kimfay Professional) vs. CS (Consumer Sales) segmentation is real and code-driven off `customer_class` prefix. Manufactured-vs-Trading classification prefers an explicit `product_type` field but falls back to a **hardcoded inventory-ID-prefix list** when unset — new SKU prefixes introduced without updating this list silently default to "Trading" (worth watching).

Delivery SLA evaluation is fully implemented and admin-configurable (region-keyed thresholds, configurable clock-start). Reason taxonomy (31 canonical sub-reasons under 5 parents, plus ~25 legacy Acumatica alias mappings) is DB-editable with a hardcoded fallback when the DB tables are empty.

### 1.6 Backorders

Two shortfall kinds tracked in one table: `active_backorder` (open SOs) and `completed_shortfall` (reconstructed from invoice lines once an order completes) — this is the mechanism that catches orders marked "Completed" with missing quantity. Revenue-at-risk is always `open_qty × unit_price` (code comments explicitly call out and guard against a prior bug of using order total instead). Aging buckets (0-7/8-14/15-30/30+ days) and a "missing reason exception" flag (backordered >7 days with no reason code) are both real. Resolved lines are archived to `BackorderResolution` before deletion, feeding a genuine Resolved tab/export.

**Analytics are fully built** (trend by day, lead-time correlation buckets, category distribution, reason distribution) — this directly contradicts the stale old-doc claim that "advanced charts" were still just requested (finding #23).

### 1.7 Zones & Routes

Shipping zones: fully implemented, real sync with graceful fallback, full frontend directory with stat cards, customer drill-down, and deep links to Fill Rate.

**Gap** (finding #24): Routes are not synced from a dedicated Acumatica entity — `AcumaticaRoute` rows are only created as a side effect of customer-assignment Excel imports. There's no dedicated Routes admin page; route data only surfaces via the dashboard's zone→route drill-down.

### 1.8 Dashboard

KPIs, trend, orders-by-status, zone-routes (finding-free, fully implemented — real join logic, not a placeholder), and a deliberate "Goods Lost in Transit" customer exclusion from main totals, explicitly surfaced in the API response's own reconciliation formula. All queries respect org/rep-code data scoping.

### 1.9 Credit Notes & More / SO Imports

Import browsers for orders/customers/emails/workflow, plus admin-only destructive truncate endpoints (real deletes, not no-ops). One **minor scope mismatch**: the sync endpoint accepts document types `QT/RC/CM/PL`, but the model's display-scope constant (`AcumaticaSalesOrder::CREDIT_NOTES_AND_MORE_TYPES`) only lists `QT`/`RC` — CM/PL documents can be synced but may not appear in the tab that's supposed to display them. Not confirmed to cause a visible bug, but worth a look.

### 1.10 Reconciliation / Dead Letters

**Gap** (finding #25): `AcumaticaReconciliationResult` is only ever populated by the customer sync (watched fields: customer class, payment terms, tax zone, shipping zone) — order/inventory/backorder/fill-rate syncs never write to it. `AcumaticaDeadLetter` is comprehensively populated across every sync service and is browsable, but there is no retry/replay action — recovery only happens by re-running the sync.

---

## 2. Customer PO Email Ingestion & Order Matching

### 2.1 Architecture note — two parallel pipelines

There are two loosely-connected matching systems sharing the same `Email` table:
1. **Legacy/deterministic** (`OrderMatchingService`, driven by `EmailImportConfig` sender rules) — surfaced in Administration → Email Import, and the only one actually run on a schedule.
2. **AI-scored** (`OrderMatchPipelineService`/`OrderMatchAiMatchingService`/`OrderMatchQueueService`) — surfaced on `/app/order-match`, **manual-trigger only** (finding #8 — no cron references this pipeline at all).

Both read/write overlapping `Email` columns, so state can diverge between the two views of the same email.

### 2.2 Mailbox OAuth & folder sync

Standard MS Graph OAuth flow (`MailboxController`/`OutlookEmailService`), encrypted token storage, CSRF-protected state, recursive folder discovery, and same-day watermark-based scheduled sync (never full-history rescans). Manual/date-range syncs cap at 90 days. Sync execution fires via Laravel `defer()` (no queue worker required for the mailbox controller path) or a dispatched job (for folder-level and Order Match syncs). The OAuth diagnostics endpoint (`checkOAuth`) genuinely validates env creds, redirect URI, and live per-mailbox token status.

**Gap** (finding #5): the "Stop Sync" button sets a cache cancel-flag that is only ever read inside a private method (`OutlookEmailService::syncFolder()`) which is **dead code — never called from anywhere**. Every real sync path has no cancel check in its paging loop at all. Clicking Stop flips the log to "stopped" in the UI immediately but the background sync keeps running to completion regardless.

Per-folder config (sync enabled, order-folder flag, trust level, customer mapping) and rule-mapping (map Outlook inbox rules to customers) are both fully implemented.

### 2.3 PO extraction & customer normalizers

Dedicated normalizer classes exist and are actively used for **Carrefour, Chandarana, and Quickmart** (each parsing that retailer's specific PO-number format with fallback match keys). **Naivas** has no separately-named class but is fully handled inside the generic canonical normalizer and match resolver — functionally complete, just organized differently than the other three. PDF-specific enrichment per partner (fax metadata, watermarks, totals) is implemented via `PartnerPoPdfContextService`.

**Gap** (finding #6/#7): the deterministic extraction service accepts an AI-extractor fallback array in its constructor, and three concrete extractor classes exist (Claude/OpenAI/local-OCR) — but nothing in the app ever binds or injects them, so the "try AI when patterns fail" step never executes. The `ai_fallback_enabled` toggle on sender configs is stored/editable in the admin UI but never read by any extraction code. Separately, actual image-attachment OCR (via Claude/OpenAI vision) *is* live and used — that's a different, working code path from the dead PO-extraction AI fallback.

### 2.4 AI match suggestion & queue

`OrderMatchAiMatchingService` tries an exact Acumatica order match first (no AI call needed), and only calls an LLM to semantically rank up to 20 open-SO candidates when no exact match exists — every call logged to `AiPromptLog`. Duplicate detection (same PO across multiple customers/emails, or a PO already accepted before) is implemented. Notification rules for queue backlog and duplicate-PO alerts are wired with dedup windows.

**Gap** (finding #8): this entire pipeline is only invoked by the "Run match pipeline" button — confirmed via repo-wide search, no cron/console command references it. The scheduled matching cron uses the separate legacy deterministic matcher instead.

### 2.5 Accept / Reject / Duplicate / Rerun

Accept/Reject are fully wired end-to-end with proper guardrails (blocks accepting a flagged duplicate without resolving it first; requires explicit low-confidence confirmation; append-only audit log via `MatchLog`).

**Gap** (finding #9): "Mark duplicate" and "Rerun" both have working backend routes/services but **no frontend button calls either one** — invisible but functional. The AI-pipeline's own audit log/CSV export endpoints are likewise backend-complete with zero frontend surface (a different, unrelated legacy audit log — for the deterministic pipeline — is the one actually shown in the product).

### 2.6 Email filters & import config

Filters (sender email/domain, subject keyword, date range, AND-combined) are fully implemented with a live match-count preview. Sender import configs support exact/wildcard/regex matching with real guardrails (exact-mode requires dual approval — creator can't self-approve; regex patterns are constrained to a hardcoded Chandarana-domain allowlist, verified to actually reject unscoped patterns). Dormant exact configs auto-deactivate after 90 days of no imports.

**Minor gap**: passing a specific filter ID to a mailbox sync is supposed to scope the sync to that rule's matches, but the code path that would honor that (`syncLegacyInbox`) is dead code — same category of issue as the Stop button.

---

## 3. KP CRM Suite

*(This entire domain post-dates the last written docs — built without a corresponding PRD in most cases, except Sales Portfolio Dashboard, which has a same-day-dated PRD.)*

### 3.1 KP Accounts

Paginated Acumatica-customer directory scoped to `KP*` customer classes (or all classes), with active-contact counts and last-order date, plus a "My Team" accordion view aggregating per-consultant KPIs (active/dormant/on-hold counts, MTD revenue, target pace). Fully implemented and used by `/app/accounts` and the Contract Cleaners variant.

**Gap** (finding #19): a fuller `summary()` method exists on the controller but has no route registered — dead code. A frontend legacy-fallback path targets this nonexistent route and will always fail silently (superseded by the real Sales Portfolio summary endpoint, so no visible impact).

**Gap** (finding #17): access is gated by `hasPermission('kp.fol.view') || hasPermission('kp.accounts.view')`, but `kp.accounts.view` is never seeded to any role — so access really depends entirely on `kp.fol.view`, which HODs don't have seeded by default despite being an intended manager persona.

### 3.2 Dormant Customers

Dormancy = no SO in the last N calendar months (configurable, default 3, from start-of-month), with configurable customer-class exclusions (schools, by default). Full workflow: list → log a contact attempt (outcome/comments) → hand off to Calltronix (requires an active contact with phone/email and at least one prior attempt, snapshots the contact at hand-off time). Fully implemented, DB-backed, no stubs.

### 3.3 Meetings & Calendar

Rich data model: meetings (purpose, customer snapshot pulled live from Acumatica to prevent spoofing, notes lifecycle, action items, participants with accept/decline, per-user monthly targets, admin-managed purpose/category taxonomies capped at 4 "main" categories). Completing a meeting enforces notes + outcome + a follow-up date or explicit no-follow-up reason. A rich per-owner dashboard cross-references meetings against actual SO activity for that customer/month.

Calendar view merges local meetings with live Microsoft Graph calendar events for the connected mailbox, degrading gracefully if Outlook isn't connected or lacks calendar scope.

**Gap** (finding #16, the most significant KP CRM finding): every Meetings **write** endpoint (create/update/delete, respond-to-invite, save target, manage purposes/categories) sits behind the global `view.only` middleware, which blocks all non-GET requests for any role other than Administrator/CS Manager/CS Agent — and Meetings routes are **not** on that middleware's exception allowlist (unlike FOL, PCR, Commissions, Dormant Customers, and others, which are explicitly whitelisted). Since Meetings is explicitly designed so any consultant can log their own meeting (`owner_user_id` defaults to the acting user), this looks like an unflagged bug that would 403 a Sales Consultant, Sales Operations user, HOD, or Executive trying to create or edit a meeting today. No existing test exercises a write as a non-privileged role, which is likely why this has gone unnoticed.

### 3.4 Items Not Ordered

**Gap** (finding #18): two independent implementations exist with different rules. The standalone KP CRM page projects a next-expected-order date per customer×SKU from historical order intervals and flags overdue only once actually past due (30/60/90-day buckets); the Sales Portfolio Dashboard's "Items" tab instead uses a flat "not reordered in N days but ordered in the last 6 months" rule. Both are fully functional — this is a genuine conceptual duplication, not a defect, but the two views can disagree for the same customer/SKU.

### 3.5 Sales Portfolio Dashboard ("My Portfolio")

Backed by a 900-line service, directly comparable to its same-day PRD (`docs/PRD-sales-consultant-dashboard.md`). Implements: unified portfolio resolution (assignment ∪ rep-code-derived book), customer counts (active/dormant), revenue MTD + prior-period comparison (MoM/YoY), target + working-day pace, order counts, backorder value-at-risk, an inline fill-rate approximation, a naive 3-month run-rate prediction, top-5 customers with trend, and a declining-customer list. Overview/Orders/Backorders/Items tabs are all built.

**Gaps vs. its own PRD** (findings #20/#21): "concentration" is computed and labeled as **top-3** everywhere in code, though the PRD specifies top-5; "declining" uses a blunt 45-day-silence rule instead of the PRD's 40%-drop-vs-3-month-average definition; the inline fill-rate number is a simplified re-derivation not confirmed to match the Operations fill-rate calculator (a real risk of the two views showing different fill-rate % for the same customer). Not yet built at all (matches the PRD's own phased-rollout gap list): a Trends tab, segment/brand breakdown, top-product breakdown, new-customer detection, reactivated-customer detection, and dedicated fill-rate/predictions/top-movers/trends endpoints.

### 3.6 Sales Consultants directory

Fully implemented directory with month-to-date aggregation, per-consultant customer lists (fill rate, revenue lost, naive next-order-date prediction), and an Excel/Acumatica-users import path. No stubs found.

### 3.7 Commissions

A genuinely mature calculation engine: eligibility-filtered, tiered attainment-based rules (rate scales with % of target hit), period lifecycle (draft → approved → locked, with reversal spawning a new draft version and auto-recalculating), per-line commission entries with a full order snapshot for audit, and manual adjustments (only while draft). Every transition is audit-logged. Fully implemented, real DB-transaction-wrapped math — no hardcoded numbers, no stubs. No PRD exists for this feature; it was built ahead of/without a written spec.

### 3.8 Adoption Report

Small but fully functional: lists trained users cross-referenced against actual sign-in history, lets an admin mark a user as trained. By default only Administrator can see it (the `adoption.report.view` permission isn't explicitly granted to any other role in the seeder).

---

## 4. FOL (Free On Loan), Price Change Requests, DTC Calltronix

*(Docs referenced by other files — `docs/kp/fol-requests.md`, `docs/kp/pricing/price-change.md` — do not exist in the repo. `docs/fol-technician-calendar.md` and `docs/price-change-request-status.md` are the closest things to a spec and both check out as accurate against current code.)*

### 4.1 FOL Request Workflow

End-to-end and mature: draft creation validates the customer is a KP customer and every line is FOL-eligible, auto-snapshots 6-month consumables history (or requires an override reason for manual entry), and resolves the requestor via the customer-contacts system. Submission enforces attachment/override requirements per admin settings. The approval chain is genuinely **admin-configurable** (see 4.3) — stage-gated decisions check role/user-list/manager-of-submitter membership. Approving the final stage **automatically creates a real Acumatica Sales Order** at zero unit price and links it back locally, with every attempt (success/fail/skip) logged; a DB-driven cron retries any approved FOL that ended up without a linked SO. Manual SO linking and PO-number-based auto-matching (with a 422+candidate-list response when multiple SOs could match) are both implemented. The technician install/assignment workflow (assign, resolve, role-gated) is fully implemented, not a stub.

**Gaps**: the admin-configurable `duplicate_policy` setting (block/warn/allow) is fully built in settings and UI but **never actually enforced** — no duplicate-FOL detection exists anywhere in the request/submit code (finding #2). FOL notification email is **globally redirected to a single test inbox** by default (`fol.mail_testing_mode=true`), with intended recipients only logged, not actually mailed (finding #1) — this needs to be flipped off for production notification delivery to work.

### 4.2 FOL Technician / Install Calendar

Matches its own status doc closely: month KPIs, day-grouped assigned items, per-technician scoping (a technician can only see their own calendar; managers/admins can see any), all built directly on `fol_requests` fields rather than a separate installs domain — an intentional MVP scope, not a shortfall.

### 4.3 FOL Settings / Products Admin

The most complete admin tooling of the three workflow areas: approval stages are **fully dynamic** — an admin can add/remove/reorder stages, set assignee mode (role / specific users / manager-of-submitter), require comments, and set SLA hours, all without a deploy. Attachment limits, mail-from, invoicing roles, CC watchers, and the (unenforced) duplicate policy are all admin-editable. FOL product eligibility supports both single-item toggling and bulk CSV upload with flexible header detection.

### 4.4 Price Change Request (PCR) Workflow

Price resolution has a documented fallback chain for both selling price and (permission-gated) cost/margin, with consultants lacking view-margin permission never seeing cost fields at all. **Duplicate detection is real here** (unlike FOL) — a new request for the same customer+SKU within 48 hours flags `duplicate_ack_required`, and approval is blocked until acknowledged (unless the approver is an Administrator). Multi-stage approval, plus a **counter-offer flow FOL doesn't have**: an approver can counter with a revised price, and the submitter can accept (restarting the approval chain at the new price) or withdraw. Final approval sets a "pending ERP apply" status; a separate manual step marks it actually applied in Acumatica. Mail failures are logged but never roll back the request.

**Gaps** (findings #3/#4): unlike FOL, PCR approval stages are **hardcoded in a seeder** with no admin API or UI to edit them — changing the approval chain requires a code/seeder change. The PCR settings API (margin floor %, ERP-updater roles, mail recipients) is fully functional server-side but has **no frontend page at all** — it's reachable only via direct API calls today.

### 4.5 DTC Calltronix — Pricing, Quotes, Sales Orders

A mature, defensively-coded pipeline mirrored from a legacy plugin design: two-step sync (seed a local price-list catalog from inventory, then pull live prices from Acumatica with UOM-alias normalization and a graceful fallback to default pricing if the price inquiry fails), synchronous Excel/CSV price import with flexible header detection and detailed per-row results, VAT-aware pricing logic, PDF price-list export, and a scheduled daily sync (05:30). Quotes flow through draft → submit (creates a real Acumatica Quote) → convert (idempotency-guarded against double-submission, re-validates the remote quote hasn't changed before converting, then creates a POS sales order) → optional post-hoc customer-detail correction. Sales order listing reconciles which orders originated from quote conversion vs. direct POS import.

**Minor note**: the POS customer ID that all converted orders post against is a hardcoded constant (a real Acumatica customer, not a placeholder) rather than admin-configurable — would need a code change if that account ever changes.

---

## 5. AI-Powered Features

### 5.1 Architecture note — two integration patterns at different maturity levels

AI Chat Assistant uses a legacy, hand-rolled pattern (its own direct OpenAI/Anthropic HTTP calls). AI Intelligence and Kimfay Genius both use a newer, unified `LlmClient` that supports OpenAI, xAI (Grok), and Anthropic uniformly with retries and health checks. **This split is real and causes finding #10**: Chat's provider branching is binary (Anthropic vs. OpenAI), so if xAI is the resolved provider it sends the request to OpenAI's URL using an xAI key — which fails. Intelligence and Genius don't have this problem.

### 5.2 AI Chat Assistant ("Kim-Fay Genius" floating widget)

Genuinely sophisticated for what it is: classifies user intent via keyword scoring (no ML), gathers **real, live DB data** per relevant domain (orders/emails/matches/customers/cron), builds structured response cards with action links, embeds the live data snapshot into the system prompt, and calls the provider synchronously (30s timeout). Every call is logged. Fully functional for OpenAI/Anthropic; broken for xAI-only configurations (see above).

### 5.3 AI Intelligence Briefings (company-wide)

Metrics (order/customer trends, projections) are pure SQL/statistics, computed and cached independent of any AI call. Generating an AI narrative is a genuine async job: dispatch → poll a job-status endpoint by UUID → job calls the unified LLM client with a compact, cost-controlled payload → parses strict JSON into executive summary/highlights/predictions/actions. An optional template-based fallback narrative exists for when AI is unavailable, explicitly tagged as such (not disguised as real AI output), and is **disabled by default** — a failed generation surfaces honestly as failed rather than silently faking success. Fully implemented, no stubs.

### 5.4 Kimfay Genius Coaching (per-consultant weekly)

One AI-generated coaching brief per consultant per week, locked against regeneration until the following week unless an admin forces it. Portfolio metrics (MTD orders/revenue, active/dormant customers, top customers, backorder risk) are computed from real, consultant-scoped SQL before being sent to the LLM. Same async job/poll pattern as Intelligence. No scheduled/automatic trigger exists — generation is always a manual click. Fully implemented, one of the more mature AI sub-features.

### 5.5 AI Connector / Key Management

Three providers (OpenAI, xAI, Anthropic) fully supported at the storage layer — encrypted at rest, DB key takes precedence over env var fallback, with a genuine live health-check call per provider. One thing worth a security look: key-save/delete audit log entries include the raw key value in the payload passed to the audit logger (upstream masking behavior wasn't confirmed either way).

### 5.6 AI Prompt Logs

A unified observability layer — every AI call path (Chat, Intelligence, Genius) logs through the same table with prompt, intent, provider, response time, and success/failure, plus a stats endpoint (success rate, average latency, breakdown by intent/provider). Fully implemented.

### 5.7 Sales Management Prompts (finding #11 — not actually AI)

Despite living next to the AI controllers, this feature contains **zero calls to any LLM**. "Generate" means two deterministic statistical checks: median order-cycle-gap analysis (flags a customer/consultant pair as due/overdue for their next order) and month-close gap detection (orders in a prior month that don't look billed yet). Fully implemented as a rule-based engine with real settings (thresholds, lookback windows), idempotent generation, and a full resolve/dismiss/snooze lifecycle with audit trail. Trigger is manual/admin-button or CLI only — not on any automatic schedule.

---

## 6. Admin & Platform Plumbing

### 6.1 Auth & OTP

Password login (unrestricted-lifetime token, revokes all prior tokens) and OTP login (6-digit hashed code, 15-min expiry, 5-attempt lockout, resend throttling, optional password-and-OTP mode, 8-hour token expiry) are both fully implemented. Every OTP verify attempt is logged with a **hashed** email (never plaintext). Session tracking (open/close per login method, with reason) is real, and self-service sign-in-log/session views exist alongside admin views of any user's history.

### 6.2 RBAC: Roles / Permissions / Capabilities

`Administrator` (or `is_super_admin`) always has every permission at the model layer. A `/api/auth/capabilities` endpoint returns permissions, menu visibility, revenue-masking, org scope, and idle-timeout per user. Coarse HTTP-layer role gates (admin-only, admin-or-manager, admin-or-cs) plus a `view.only` middleware block all non-GET requests for non-privileged roles except an explicit path allowlist — this is the mechanism that lets, e.g., a Sales Consultant submit a FOL while still being blocked from most other writes (and is the source of finding #16's Meetings gap, since Meetings isn't on that allowlist).

**By design, not a gap**: Role/Permission definitions are **read-only in the UI** — `/app/roles` is a pure viewing matrix; changing what a role can do requires re-running the permissions seeder. This matches existing docs' description exactly.

### 6.3 User Management, Bulk Import & Activation

**Memory-note whitelist claim: confirmed accurate.** Bulk-activate touches only `is_active`/`email_verified_at` on explicitly-posted user IDs (and skips self/unmanageable users). Per-user update uses an explicit hardcoded field list that never includes `password` (password changes are a dedicated OTP-gated endpoint). Staff import similarly whitelists an explicit field set on **update** and never touches `password` for existing users; new users created from a low-confidence "gap" review get a random never-emailed password and start inactive.

**One nuance worth stating precisely**: the *main* staff-import run — not just the gap-review flow — will auto-create brand-new (inactive, random-password) user accounts for any row whose match confidence is high enough, even if no existing user matched that email. So it's accurate to say password/unlisted fields are never touched and updates are scoped to matched fields, but it's *not* accurate to say the import process "only touches matched users" — it can also create new ones automatically for high-confidence rows (low-confidence rows alone require the explicit manual gap-review step).

### 6.4 Team Org Structure, Customer/Brand Assignment, Impersonation

Org tree (free-form reports-to, cycle-detection, org levels driving default data-scope mode) is fully implemented with a full audit trail on every change. Customer assignment supports three data sources (SO-derived, Acumatica customer-endpoint-derived, Excel upload) all funneling through a common preview (dry-run) → batch → explicit-apply pipeline — nothing writes directly. Impersonation issues a genuinely separate, time-limited token for the target user without touching the admin's own session, fully audit-logged both ways.

### 6.5 Audit Logs, Cron Management, System Health

Audit logging is pervasive and auto-masks any change payload key that looks like a secret/password/token before persisting; failures are swallowed (never block the primary action). Cron management genuinely drives the real Laravel scheduler for the general case — but two jobs are deliberately excluded from that dynamic mechanism and hard-coded instead: OTP pruning and the daily management report (see finding #14), each with an explicit code comment explaining why, to prevent double-scheduling.

### 6.6 Daily Management Report, Delivery SLA Config, Notification Rules

Daily report manual operations (config, test-send, resend, run history) are fully implemented and audit-logged, including idempotency protection against duplicate sends for the same report date. The scheduled send time itself, however, is hard-pinned in code (finding #14), not driven by the config UI's "send time" field. Delivery SLA config is fully implemented with sensible built-in fallback defaults. Notification rule configuration (direct + role-based recipients, resolved dynamically against currently-active users) is fully built, but the evaluator cron that would actually dispatch notifications using these rules is disabled by default (finding #15) — configuring rules today has no live effect until that's turned back on.

### 6.7 Customer Contacts CRM, Profile/Account Management

Both fully implemented. Contacts access is governed by data-scope (not role) — any authenticated user can manage contacts, but only for customers within their own org/sector/assignment scope. Profile self-service intentionally limits editable fields (name, phone, rep code for consultants only) with `employee_number` explicitly commented as read-only from this endpoint; password changes require their own OTP loop and revoke all sessions on success.

---

## 7. Documentation freshness notes

- `docs/PROJECT-OVERVIEW.md` (10 Jul) and `docs/orderWatch-modules.md` (8 Jul) predate the entire KP CRM suite, DTC Calltronix, and Commissions — those areas simply don't appear in either doc.
- `docs/implementation-and-production-2026-07.md` (14 Jul) is accurate for the specific features it covers (zone routes, customer assignment upload, auth error UX, daily report idempotency).
- Two PRDs referenced elsewhere in the docs tree (`docs/kp/fol-requests.md`, `docs/kp/pricing/price-change.md`) do not exist in the repository — either they were never committed or the references are stale.
- `docs/PRD-sales-consultant-dashboard.md` is dated the same day as this report (23 Jul) and describes a phased plan; code matches its early phases (P1a–c) and is missing its later phases (P1d–e) exactly as the PRD's own gap checklist would predict.
- The backorder "advanced charts" gap called out in `PROJECT-OVERVIEW.md` and `fixes-now.md` has been closed in code since those docs were written.

---

*Compiled 23 Jul 2026 from a six-way parallel code audit (Acumatica/operations, email/order-match, KP CRM, FOL/PCR/DTC, AI features, admin/platform). Every numbered finding above was traced to specific file/method-level evidence during that audit; ask if you want the underlying file:line citations for any specific item.*
