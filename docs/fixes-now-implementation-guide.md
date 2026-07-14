# fixes-now.md — Implementation & Verification Guide

This document confirms what from `fixes-now.md` is implemented, how to verify it, and what was added in the latest pass (Business Optimization reason charts, RBAC UI, fill-rate `QtyOnShipments`).

---

## Quick verification checklist

Run the backend verification suite:

```bash
cd backend
php artisan test --filter="TeamMemberAccountTest|AcumaticaSalesOrderSyncTest|AcumaticaOperationsSyncTest|OrderControllerTest|RbacAccessTest|CronEngineTest|DailyReportFixedScheduleTest|SyncMonitorAlertsCommandTest|FrontendUrlTest|EmailImportConfigControllerTest|FillRateCalculatorTest"
```

Expected: **all tests pass** (47+ assertions).

---

## 1. Account creation / email verification

| Status | **Implemented** |
|--------|-----------------|
| What | New team members get `email_verified_at` set immediately — no incomplete verification state |
| Code | `backend/app/Http/Controllers/Api/Admin/UserController.php` |
| Test | `backend/tests/Feature/TeamMemberAccountTest.php` |

**Verify:** Admin → Team Members → create user → user can log in via OTP without a separate verify step.

---

## 2. Order sync status reconciliation

| Status | **Implemented** |
|--------|-----------------|
| What | Compares local order status vs Acumatica and updates mismatches; dedicated status-only sync job |
| Code | `AcumaticaSalesOrderSyncService::reconcileStatuses()`, `RunSalesOrderStatusSync` |
| Cron | Every 30 min — `orderwatch:sales-order-status-sync` |
| Test | `AcumaticaSalesOrderSyncTest::test_sales_order_sync_reconciles_mismatched_local_statuses` |

**Verify:** Change a local order status manually, run status sync, confirm it realigns with Acumatica.

---

## 3. Inventory sync error message & stop button

| Status | **Implemented** |
|--------|-----------------|
| What | Correct grammar: *"An inventory sync is already running…"*; stale locks cleared; per-module Stop Sync |
| Code | `AcumaticaInventorySyncService`, `InteractsWithAcumaticaSyncRun`, `AcumaticaController::stopSync` |
| UI | `src/routes/app.inventory.tsx`, `src/routes/app.administration.tsx` |
| Test | `AcumaticaOperationsSyncTest::test_inventory_sync_ignores_stale_running_lock_and_stops_when_requested` |

**Verify:**
1. Start inventory sync → Stop button appears → click Stop → sync ends with `stopped` status.
2. No false "already running" after a stale run (>2 min without heartbeat).

---

## 4. Background sync termination

| Status | **Implemented** |
|--------|-----------------|
| What | Errors mark sync `failed`; stop requests throw `AcumaticaSyncStoppedException`; stale runs auto-fail |
| Code | All Acumatica sync services + `AcumaticaSyncLog::failStaleRunning()` |

---

## 5. Backorders UI — graphs, reasons, filters

| Status | **Implemented** |
|--------|-----------------|
| What | Trend charts, lead-time correlation, category/reason pies, date/product-line/warehouse/reason filters, editable reason codes |
| UI | `src/routes/app.backorders.tsx` (reference: `backorder-interface.png`) |
| API | `GET /api/operations/backorders/analytics`, `PATCH /api/operations/backorders/{id}` |
| Test | `AcumaticaOperationsSyncTest::test_backorders_analytics_and_reason_updates_support_operational_workflows` |

**Reason codes (editable):**
- `supplier_delay`, `inventory_shortage`, `production_issue`, `logistics_disruption`, `quality_hold`, `forecast_gap`, `customer_change`, `system_allocation`

**Authorized editors:** Administrator, Customer Service Manager, Sales Operations.

---

## 6. Orders — mandatory rejection reason

| Status | **Implemented** |
|--------|-----------------|
| What | Rejected status requires `rejection_reason_code`; shown in order details |
| UI | `src/routes/app.orders.tsx` |
| API | `OrderController` validation |
| Test | `OrderControllerTest` |

**Rejection codes:** `out_of_stock`, `customer_request`, `invalid_payment`, `address_error`, `credit_limit`, `duplicate_order`, `other`

---

## 7. Sync reasons from Acumatica

| Status | **Implemented** |
|--------|-----------------|
| Backorder reasons | `AcumaticaBackorderSyncService` imports `ReasonCode` + notes; validation summary on sync log |
| Rejection reasons | `AcumaticaSalesOrderSyncService` imports `RejectionReasonCode` + description |
| Fill-rate unfilled | `QtyOnShipments = 0` → `inventory_shortage` on sales order lines (see `fill-rate-qty-on-shipments.md`) |
| Tests | `test_backorder_sync_imports_reason_codes…`, `test_sales_order_sync_imports_rejection_and_on_hold_reasons` |

---

## 8. FRONTEND_URL in emails

| Status | **Implemented** |
|--------|-----------------|
| What | `FrontendUrl` helper injects `FRONTEND_URL` into OTP, welcome, daily report, order-match links |
| Config | `FRONTEND_URL` in `.env` → `config/app.php` → `frontend_url` |
| Test | `FrontendUrlTest`, `TeamMemberAccountTest`, `OtpAuthTest` |

**Verify:** Set `FRONTEND_URL=https://your-domain.com` in production `.env`; click links in test emails — no 404s.

---

## 9. Email import guardrails

| Status | **Partial** |
|--------|-------------|
| Implemented | Wildcard regex (admin-only), unsafe pattern block, 500/hr cap, dual admin approval for branch senders, metrics widget, guardrail status on emails, IMAP retry, 90-day dormancy deactivation |
| Gaps | Unique `(message_id, from_email)` index; branch-manager scoped viewing; branch→inventory queue routing; 7-day staging comparison |

| Code | `EmailImportConfig`, `EmailImportConfigController`, `OutlookEmailService` |
| Test | `EmailImportConfigControllerTest`, `EmailImportConfigGuardrailTest` |

---

## 10. Cron scheduling (no queues)

| Status | **Implemented** |
|--------|-----------------|
| Schedule | `backend/routes/console.php` + seeded jobs in migration `000050` |

| Job | Interval | Command |
|-----|----------|---------|
| Email sync | Every 3h | `orderwatch:email-sync` |
| Order matching | Every 3h | `orderwatch:order-matching` |
| Sales orders | Every 3h | `orderwatch:sales-orders-sync` |
| SO status | Every 30 min | `orderwatch:sales-order-status-sync` |
| Inventory | Every 5h | `orderwatch:inventory-sync` |
| Backorders | Daily 4 PM | `orderwatch:backorders-process` |
| Fill rate | Nightly | `orderwatch:fill-rate-sync` |

Idempotency: `CronExecutionService` enforces minimum interval between successful runs.

**Test:** `CronEngineTest`

**Production:** Ensure system cron runs `php artisan schedule:run` every minute.

---

## 11. Daily report & sync monitor alerts

| Status | **Implemented** |
|--------|-----------------|
| Daily report | Tue–Sat 7:30 AM, previous calendar day — `SendDailyManagementReportFixed` |
| Sync monitor | Alerts to `commercialtechlead@kimfay.com` on sync success + guardrail failures — `RunSyncMonitorAlerts` |
| Send To / CC | Admin → Daily Notifications panel |
| Tests | `DailyReportFixedScheduleTest`, `SyncMonitorAlertsCommandTest`, `DailyReportRoutingFieldsTest` |

---

## 12. RBAC

| Status | **Partial → improved** |
|--------|------------------------|
| Backend API | `ViewOnlyUnlessPrivileged`, `AdminOnly`, `AdminOrCustomerService` middleware |
| Frontend (new) | `src/lib/nav-permissions.ts` filters sidebar, header sync button, admin tabs |
| Test | `RbacAccessTest` |

### Role matrix

| Role | Modules | Sync | Edit orders/backorder reasons |
|------|---------|------|-------------------------------|
| **Administrator** | All | Yes | Yes |
| **Customer Service** | All except Acumatica, AI Keys, Roles, Permissions, Notification Rules | Mail sync yes; Acumatica sync admin-only | Yes |
| **Sales Operations** | Dashboard, Orders, Operations views | No (API 403) | Backorder reasons + order rejection only |
| **Executive** | View-only subset | No | No |

**Remaining gap:** Customer Service cannot trigger Acumatica inventory/order sync via API (admin-only endpoints). Move sync routes to `admin.or.cs` if CS should run them.

---

## Business Optimization — new in this pass

Aligned with `backorder-interface.png` and fill-rate `QtyOnShipments` work.

### New charts (distinct colours per segment)

| Chart | Data source | Meaning |
|-------|-------------|---------|
| **Backorder value by reason** | `charts.backorders_by_reason` | Pie — revenue at risk grouped by `reason_code` |
| **Fill rate — zero on shipments by reason** | `charts.fill_rate_unfilled_reasons` | Pie — lines where `QtyOnShipments = 0` but demand > 0 |
| Customer bar chart | Per-customer backorder risk | Multi-colour bars |
| Revenue bleeding split | Backorders vs fill-rate gap | Two distinct bar colours |

### New KPIs / alerts

- `revenue_bleeding.zero_qty_on_shipments_lines` — count of lines with demand but nothing on shipments
- `revenue_bleeding.backorders_without_reason` — backorder lines missing `reason_code`
- Executive alerts when either count > 0

### API

`GET /api/operations/business-optimization?date_from=…&date_to=…`

### Code

- `backend/app/Services/Operations/BusinessOptimizationService.php`
- `src/routes/app.business-optimization.tsx`
- `src/hooks/useOperations.ts`

### Test

`AcumaticaOperationsSyncTest::test_business_optimization_returns_insight_sections`

---

## Fill rate — QtyOnShipments (related)

See **`fill-rate-qty-on-shipments.md`** for full formula, guardrails, and migration details.

**Key rule:** `QtyOnShipments = 0` with demand → **out of stock** (`inventory_shortage`).

---

## Operational runbook

### After deploy

```bash
php artisan migrate
php artisan db:seed --class=RolesPermissionsSeeder   # if fresh env
```

### Refresh operations data

1. **Administration → Sync Operations** (or cron):
   - Sales orders → Backorders → Fill rate → Inventory
2. Check sync log guardrail counts (`filters` JSON on `acumatica_sync_logs`)
3. Open **Business Optimization** — confirm reason pies populate
4. Open **Backorders** — confirm charts + reason editor
5. Open **Fill Rate** — confirm "On shipments" column + unfilled reasons

### Environment variables

| Variable | Purpose |
|----------|---------|
| `FRONTEND_URL` | Email links (required in prod) |
| `SYNC_MONITOR_ALERT_EMAIL` | Override default `commercialtechlead@kimfay.com` |
| `FILLRATE_AMBER_KES` / `FILLRATE_RED_KES` | Alert thresholds (optional) |

---

## Known gaps (future work)

1. Email import: unique sender+message index, branch-scoped policies, inventory queue routing
2. RBAC: grant Customer Service Acumatica sync triggers if required by ops
3. Daily report: end-to-end reply-all recipient retention test across mail clients
4. Acumatica fields from `fielsd to consider.md`: `OrigOrderQty`, `ShippedQty`, header approval/rejection timestamps
5. Frontend E2E / mobile tests for backorders and business optimization charts

---

## File index

| Area | Primary files |
|------|----------------|
| Fill rate QtyOnShipments | `fill-rate-qty-on-shipments.md`, `FillRateCalculator.php`, `SalesOrderLineFulfillmentDeriver.php` |
| Business optimization | `BusinessOptimizationService.php`, `app.business-optimization.tsx` |
| Backorders UI | `app.backorders.tsx`, `OperationsController.php` |
| RBAC UI | `nav-permissions.ts`, `app-sidebar.tsx`, `app-header.tsx`, `app.administration.tsx` |
| Cron | `routes/console.php`, `CronExecutionService.php`, `config/cron.php` |
| Email guardrails | `EmailImportConfig.php`, `OutlookEmailService.php` |
| Sync stop/error | `InteractsWithAcumaticaSyncRun.php`, `AcumaticaSyncLog.php` |
s
---

*Last verified: automated test suite (47 tests) + Business Optimization reason charts + RBAC sidebar filtering.*