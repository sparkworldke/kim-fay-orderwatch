On the Automated Cron Job always Import sync last 7 Days so that it updates all the SOs, Stock units, statuses etc. 

# Notifications & Backorders

Operational guide for OrderWatch email notification controls and the restructured Backorders experience.

**Last updated:** 2026-07-16  
**Frontend domain:** https://orderwatch.fayshop.co.ke

---

## 1. Email notifications

Volume was reduced by pausing bulk automated mail. Workflow and critical operational emails remain active.

### 1.1 Active emails

| Email | Trigger | Subject / notes |
|--------|---------|-----------------|
| **System Health [CRITICAL]** | Cron `system-health-daily` (daily ~06:00) | `OrderWatch System Health [CRITICAL] — …` only when overall status is **CRITICAL**. HEALTHY / DEGRADED are logged, not emailed. |
| **Daily Notification** | Cron `daily-report-fixed-scheduler` (Tue–Sat 07:00 Africa/Nairobi) | Fixed daily management report (`orderwatch:send-daily-report-fixed`). |
| **FOL workflow (N1–N6)** | FOL submit / approve / reject | Approval and rejection mails for Free of Liability requests. |
| **PCR workflow (P1–P6)** | Price change submit / approve / apply / reject / SLA | Price Change Request notification emails. |

### 1.2 FOL rules (active)

| Rule key | When |
|----------|------|
| `FOL-N1` | Submitted — HOD / stage approval |
| `FOL-N2` | Stage approved — consultant |
| `FOL-N3` | Pending final approval |
| `FOL-N4` | Fully approved — consultant |
| `FOL-N5` | Approved for invoicing |
| `FOL-N6` | Rejected |

**Code path:** `App\Services\Fol\FolRequestService::sendMail`  
Maps template `N1`…`N6` → rule `FOL-N1`…`FOL-N6`.  
If the rule row is missing → **send**. If `is_enabled = false` → **skip**.

### 1.3 PCR rules (active)

| Rule key | When |
|----------|------|
| `PCR-P1` | Submitted |
| `PCR-P2` | Stage approved |
| `PCR-P3` | Final approved — pending ERP apply |
| `PCR-P4` | Rejected |
| `PCR-P5` | Marked applied in ERP |
| `PCR-P6` | SLA breach |

**Code path:** `App\Services\Pricing\PriceChangeRequestService::notify`  
If the rule exists and `is_enabled = false` → **skip**. Missing rule → **send** (workflow default on).

### 1.4 Paused emails

| Source | Details |
|--------|---------|
| **Sync Monitor Alerts** | Cron `sync-monitor-alerts` — **paused**. Hard-coded every-minute schedule removed from `routes/console.php`. Was high volume (success + failure + guardrail). |
| **Order Match R5 / R6** | Queue backlog + duplicate PO. Cron `order-match-notification-evaluation` **paused**. Hard-coded hourly schedule removed. |
| **R1–R3** | Critical orders pending, SLA breach, revenue at risk |
| **SM-P1–P4** | Sales management prompts |
| **Email import low success rate** | Requires enabled rule `R7` (if present); otherwise no mail |

### 1.5 Not treated as “notification volume”

These remain available (transactional / admin-driven):

- OTP / sign-in codes  
- Team member welcome / account emails  
- Manual admin “send notification rules config”  

### 1.6 Database & migrations

| Migration | Purpose |
|-----------|---------|
| `2026_07_16_000001_pause_nonessential_email_notifications` | Disables bulk rules; pauses sync-monitor + order-match evaluation crons; keeps system-health + daily-report enabled |
| `2026_07_16_000002_reenable_fol_and_pcr_email_notifications` | Creates/enables FOL-N1…N6 and PCR-P1…P6 |

**Deploy backend:**

```bash
cd /path/to/backend
php artisan migrate --force
```

**Seeder defaults:** `RolesPermissionsSeeder` — FOL/PCR `is_enabled = true`; bulk rules `false`; R4 (in-app) `true`.

### 1.7 How to re-enable a paused notification

1. **Admin → Notification Rules** — toggle `is_enabled` for the rule, **or**  
2. SQL / tinker:

```php
\App\Models\NotificationRule::where('rule_key', 'R5')->update(['is_enabled' => true]);
```

3. For **sync-monitor** or **order-match evaluation**: set `cron_jobs` row `is_enabled = true`, `status = active`, and re-add the schedule in `backend/routes/console.php` if the hard-coded schedule was removed.

### 1.8 Key files (notifications)

| Path | Role |
|------|------|
| `backend/routes/console.php` | Scheduler; daily report hard-coded; sync-monitor / order-match schedules commented out |
| `backend/app/Models/CronJob.php` | Defaults for `syncMonitor()`, `orderMatchNotificationEvaluation()`, `systemHealthCheck()` |
| `backend/app/Console/Commands/RunSystemHealthCheck.php` | CRITICAL-only email gate |
| `backend/app/Console/Commands/RunSyncMonitorAlerts.php` | Sync monitor mail (paused via cron) |
| `backend/app/Services/Fol/FolRequestService.php` | FOL emails |
| `backend/app/Services/Pricing/PriceChangeRequestService.php` | PCR emails |
| `backend/app/Services/OrderMatch/OrderMatchNotificationService.php` | R5/R6 |
| `backend/app/Models/NotificationRule.php` | Rule model + recipients |

---

## 2. Backorder calculation (critical)

### 2.0 Formula

| Metric | Correct formula | Wrong (previous bug) |
|--------|-----------------|----------------------|
| **Missing qty** | `OpenQty` (else order − shipped − cancelled) | `OrderQty − ShippedQty` alone (can equal full line) |
| **Unit price** | `CuryUnitPrice` / `UnitPrice` | Using ExtendedPrice / order total |
| **Value / revenue at risk** | **open qty × unit price** e.g. `460 × 24 = 11,040` | Invoice / document total e.g. `570,000` |

Example (SO359099): document total **570,000**; missing snack line **24 CS @ 460 = 11,040**.

### 2.0.1 Re-import June 2026 (or any range)

On the **backend** host:

```bash
cd /path/to/backend

# Default window is June 2026. Re-sync SO lines + backorder table:
php artisan orderwatch:import-backorders --from=2026-06-01 --to=2026-06-30 --resync-orders

# Backorder table only:
php artisan orderwatch:import-backorders --from=2026-06-01 --to=2026-06-30

# Sales orders only (SO detail card):
php artisan orderwatch:import-backorders --from=2026-06-01 --to=2026-06-30 --orders-only
```
# Phase A — sales orders
php artisan orderwatch:import-backorders --from=2026-06-01 --to=2026-06-07 --orders-only && \
php artisan orderwatch:import-backorders --from=2026-06-08 --to=2026-06-14 --orders-only && \
php artisan orderwatch:import-backorders --from=2026-06-15 --to=2026-06-21 --orders-only && \
php artisan orderwatch:import-backorders --from=2026-06-22 --to=2026-06-28 --orders-only && \
php artisan orderwatch:import-backorders --from=2026-06-29 --to=2026-06-30 --orders-only && \

# Phase B — backorders only
php artisan orderwatch:import-backorders --from=2026-06-01 --to=2026-06-07 && \
php artisan orderwatch:import-backorders --from=2026-06-08 --to=2026-06-14 && \
php artisan orderwatch:import-backorders --from=2026-06-15 --to=2026-06-21 && \
php artisan orderwatch:import-backorders --from=2026-06-22 --to=2026-06-28 && \
php artisan orderwatch:import-backorders --from=2026-06-29 --to=2026-06-30

# Ctrl+A then D to detach

Order matters: finish all SO weeks (or at least the week for an SO you care about) before the matching backorder week, so open qty / unit price are already correct on the lines.\\\




Command: `App\Console\Commands\ImportBackordersDateRange`  
Signature: `orderwatch:import-backorders`

After reimport, hard-refresh SO359099 and confirm Backorder **Value = 11,040**, not 570,000.

---

## 2. Backorders UI

### 2.1 Main Backorders page

**Route:** `/app/backorders`  
**File:** `src/routes/app.backorders.tsx`

Restructured to a simple operational list:

1. **Filters** — search, date preset / range (default **month to date**), product line, customer group, warehouse, root cause, brand cascade  
2. **KPI strip** — open lines, SKU count, open orders, revenue at risk  
3. **Grouped table** — by **Inventory ID**, with accordion  

#### Accordion behaviour

| Level | Content |
|-------|---------|
| **Group (Inventory ID)** | Product listing, # SOs, # customers, open qty, revenue at risk |
| **Expanded rows** | **SO** (link), **Date** (SO order date), **Customer Name** (link), open qty, unit price × qty, rev at risk, reason, edit action |

- Client groups up to **1,000** lines from the API for the current filters  
- Pagination is by **SKU groups** (25 / 50 / 100)  
- If more lines match than loaded, a banner suggests narrowing filters or Excel export  

#### Actions retained

- Update backorders (Acumatica sync for date range)  
- Download Excel  
- Edit root-cause reason (Administrator / CSM / Sales Operations)  

#### Removed from primary view

Charts, “most affected accounts”, Excel-style summary panels, and stock-sync-only button (can be re-added if needed).

### 2.2 Customer documents page

**Route:** `/app/customer-orders/$customerId`  
**Files:**

- `src/routes/app.customer-orders.$customerId.tsx`  
- `src/routes/app.customer-orders.$customerId.branch.$branchId.tsx`  
- `src/components/customer-orders-shared.tsx` → `CustomerBackorderCard`

| Feature | Behaviour |
|---------|-----------|
| Default dates | Current month → today |
| Backorder card | After filters |
| Grouping | By Inventory ID |
| Accordion | SO numbers + unit price × qty + value |
| Parent accounts | `include_branches=1` so branch SOs are included |

**API:** `GET operations/backorders?customer_id=…&date_from=…&date_to=…&include_branches=1`  
**Backend:** `OperationsController::backordersFilteredQuery` — when `include_branches` is true, includes child customers where `parent_acumatica_id` matches.

### 2.3 SO document detail

**Routes:**

- `/app/customer-orders/$customerId/so/$orderId`  
- `/app/customer-orders/$customerId/branch/$branchId/so/$orderId`  

**Component:** `BackorderCard` in `customer-orders-shared.tsx`

| Feature | Behaviour |
|---------|-----------|
| Position | **Top of page** (above “Attached To” and line fill-rate table) |
| Content | Lines with `backorder_qty > 0` |
| Columns | Item, **Unit Price × Qty** (with UOM), Value |
| Totals | Units + total value |

### 2.4 Key files (backorders)

| Path | Role |
|------|------|
| `src/routes/app.backorders.tsx` | Main backorders page |
| `src/components/customer-orders-shared.tsx` | `BackorderCard`, `CustomerBackorderCard`, `OrderDetailBody` |
| `src/hooks/useOperations.ts` | `useBackorders`, `include_branches` query param |
| `backend/app/Http/Controllers/Api/OperationsController.php` | List/export/filter; branch-aware `customer_id` |

### 2.5 Frontend deploy

```bash
# From repo root
npm run build
npx wrangler deploy
```

Worker: `orderwatchkimfay`  
Custom domain: `orderwatch.fayshop.co.ke`

---

## 3. Quick reference — current mail policy

```
ACTIVE
  ├── System Health email          → CRITICAL only
  ├── Daily management report      → Tue–Sat 07:00 EAT
  ├── FOL N1–N6                    → workflow
  └── PCR P1–P6                    → workflow

PAUSED
  ├── Sync monitor alerts
  ├── Order match R5 / R6
  ├── Ops rules R1–R3
  └── Sales management SM-P1–P4
```

---

## 4. Related docs

- [Daily notifications.md](./Daily%20notifications.md)  
- [daily-email-executive.md](./daily-email-executive.md)  
- [cron-jobs-guide.md](./cron-jobs-guide.md)  
- [Order-Fill-Rate-Backorder-Dashboard-PRD.md](./Order-Fill-Rate-Backorder-Dashboard-PRD.md)  
- [Email Issues.md](./Email%20Issues.md)  
- [BACKEND-DEPLOY-UPDATE.md](./BACKEND-DEPLOY-UPDATE.md) 

/usr/bin/php8.3 /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/artisan queue:work --stop-when-empty >> /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/cron-worker.log 2>&1; echo "Ran at $(date)" >> /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/cron-worker-heartbeat.log

