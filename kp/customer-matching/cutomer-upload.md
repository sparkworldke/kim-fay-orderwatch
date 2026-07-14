# Customer–Consultant Matching & Upload — Product Requirements Document

**Product:** OrderWatch  
**Module:** Team / KP portfolio — customer assignment  
**File:** `kp/customer-matching/cutomer-upload.md` *(filename retained; “customer” misspelled historically)*  
**Status:** PRD draft — ready for design / engineering  
**Owner:** Product  
**Last updated:** 10 Jul 2026 · **Rev:** MoM / order-cycle / dormant reports + sitewide behavior badge  

**Related**

| Doc / code | Relevance |
|---|---|
| `docs/team.md` §6 L4 Customer assignment | Scope layers & fail-closed rules |
| `user_customer_assignments` table | Explicit portfolio store |
| `CustomerAssignmentService::backfillFromSalesOrders` | SO → assignment already exists as CLI |
| `team:backfill-customers` | Artisan backfill by user / all consultants |
| `SalesConsultantScope` / `OrgScopeService` | Runtime data scoping |
| `kp/fol-requests.md` | FOL already requires portfolio guardrails (GR-01) |
| `kp/kp-enabler.md` | KP team portfolio visibility |

---

## 1. Problem statement

Consultants (KP and other customer-facing sales) must only see **their** customers for FOL, orders, fill rate, and CRM. Today:

1. **SO-based linkage is incomplete in the UI** — backend can backfill from `sales_consultant_rep_code` / `consultant_user_id`, but admins lack a clear **“Match from SOs”** workflow with review/preview.  
2. **Bulk Excel assignment is missing** — commercial ops maintain customer–rep maps offline; no validated upload path into `user_customer_assignments`.  
3. **Rep code ↔ user resolution is fragile** — users without `rep_code`, multi-rep mappings, or stale SO rep codes produce empty or wrong portfolios.  
4. **No guardrails on upload** — risk of assigning non-existent customers, wrong sector (e.g. MT customer to KP-only user), or wiping portfolios silently.  
5. **Audit gap** — who assigned which customer, when, and from which source (SO vs Excel vs manual) is not first-class.  
6. **No safe reassignment** — when a consultant leaves or territory moves, ops need to **migrate one or many customers** to a new user **with full assignment details**, optionally **notifying** the receiving (and/or releasing) user.

---

## 2. Goals & non-goals

### 2.1 Goals

| ID | Goal | Success measure |
|---|---|---|
| G1 | Admin/HOD can **match customers from Sales Orders** to a consultant (by rep code / user) with preview + commit | ≥ 95% of SO customers for that rep land in assignments after commit |
| G2 | Admin can **upload Excel** of `rep_code` + `customer_id` (and optional fields) to create/update assignments | Dry-run + apply; error report downloadable |
| G3 | Matching is **idempotent** and **audited** | Re-upload does not duplicate; every change has actor + source |
| G4 | Assignments **drive portfolio scope** used by FOL, orders, customers, KP modules | Consultant API 403 for unassigned customers |
| G5 | Clear **guardrails** reject bad rows without partial silent corruption | Row-level errors; optional transactional batch modes |
| G6 | Support **bulk “all consultants from SOs”** and **single-consultant** flows | Same engine as CLI, exposed in UI |
| G7 | **Migrate** a **single** or **multiple** customers to a new user, carrying full assignment details | Atomic transfer; from-user loses primary; to-user gains full detail |
| G8 | Optional **notify** on migrate via email and/or **configured notification channels** | Toggle off by default for dry-run; on for apply when chosen |
| G9 | **Sales Consultant report**: MoM customers, order-cycle cohorts, **Dormant** (no purchase in past **2 months**) | Report + export on consultant detail / list |
| G10 | **Sitewide customer behavior badge**: **Recurring** / **Dormant** (and related) from SO behavior | Same badge on customers, consultants, orders, FOL, KP |

### 2.2 Non-goals (this PRD)

- Changing Acumatica customer master (OrderWatch is consumer of sync).  
- Auto-creating users from Excel (use Team staff import).  
- Replacing sector/HOD scope (L2) — customer assignment is **L4**, stacks with sector.  
- Email-import customer matching (mailbox PO matching is a different module).  
- Multi-consultant co-ownership of one outlet as “shared primary” (v1 = one **primary** per row; secondary type optional later).  
- Rewriting historical SOs to the new rep in Acumatica (optional future flag — v1 is **OrderWatch portfolio only** unless “re-tag open SOs” is enabled — see §7).

---

## 3. Personas & permissions

| Persona | Can |
|---|---|
| **Administrator** | All match/upload tools; any user; full reports |
| **Customer Service Manager** | Match/upload for Sales Consultants they manage (existing admin.or.manager pattern) |
| **HOD / C-suite / Executive** | View portfolios in subtree/sector; optional match/upload for reportees (config) |
| **Sales Consultant** | View **own** assigned customers only; no upload |
| **Others** | No access |

**Capability flags (proposed)**

| Permission | Meaning |
|---|---|
| `customers.assign.view` | View assignment UI |
| `customers.assign.manage` | SO match + Excel apply + migrate |
| `customers.assign.manage_all` | Any user (Admin) |
| `customers.assign.export` | Export portfolio / error sheets |
| `customers.assign.migrate` | Explicit migrate single/multi (can alias to manage) |

---

## 4. Concepts & data model

### 4.1 Source of truth

| Layer | Role |
|---|---|
| **Acumatica customers** (`acumatica_customers.acumatica_id`) | Customer master (synced) |
| **Acumatica SOs** (`sales_consultant_rep_code`, `consultant_user_id`, `customer_acumatica_id`) | Evidence of who sells to whom |
| **`user_customer_assignments`** | **Explicit portfolio** used by scope (L4) |
| **`users.rep_code`** + `user_acumatica_rep_mappings` | Resolve Excel/SO rep → user |

### 4.2 Assignment record (existing + extensions)

```
user_customer_assignments
  user_id
  customer_acumatica_id          -- = Acumatica Customer ID
  assignment_type                -- primary | secondary | cover (v1: primary default)
  assigned_by
  notes
  source                         -- NEW: so_backfill | excel_upload | manual | so_match_ui | migrate
  source_batch_id                -- NEW: nullable FK/uuid for upload/match/migrate run
  confidence                     -- NEW: optional 0–100 for SO-derived
  last_so_date                   -- NEW: optional snapshot from match
  so_order_count                 -- NEW: optional snapshot
  migrated_from_user_id          -- NEW: previous owner after migrate (nullable)
  migrated_at                    -- NEW: when last migrated onto this user
  timestamps
  UNIQUE(user_id, customer_acumatica_id)
```

### 4.3 Match / upload / migrate batch (new)

```
customer_assignment_batches
  id, uuid
  mode: so_match | excel_upload | migrate_single | migrate_multi
  status: dry_run | applied | failed | cancelled
  initiated_by
  source_user_id                 -- migrate: from consultant
  target_user_id                 -- migrate: to consultant; excel multi null
  filename, file_path            -- excel only
  notify_enabled                 -- bool: send notifications on apply
  notify_channels                -- json: ["email","in_app", ...] from config
  notify_targets                 -- json: ["to_user","from_user","hod","custom"]
  stats_json                     -- {rows, matched, created, updated, migrated, skipped, errors}
  error_report_path              -- generated xlsx/csv
  created_at, applied_at

customer_assignment_batch_rows   -- optional detail for audit UI
  batch_id, row_no
  rep_code, customer_acumatica_id, resolved_user_id
  from_user_id, to_user_id       -- migrate
  action: create | update | skip | error | migrate
  message
  details_snapshot_json          -- full assignment payload moved

customer_assignment_migrations   -- immutable history of each customer move
  id
  batch_id
  customer_acumatica_id
  from_user_id, to_user_id
  assignment_snapshot_json       -- type, notes, source, confidence, last_so_*, etc.
  related_refs_json              -- optional: open FOL ids, open SO counts (read snapshot)
  notified                       -- bool
  notify_channels_used           -- json
  actor_user_id
  created_at
```

---

## 5. Feature A — Match customers from Sales Orders

### 5.1 User story

> As an Admin (or CS Manager), I select a consultant (or all consultants), preview customers found on SOs for their rep code(s), then commit assignments so their portfolio updates.

### 5.2 Resolution rules (SO → customer set)

For a target **user U**:

1. Collect rep codes:  
   - `UPPER(TRIM(U.rep_code))` if set  
   - plus all `user_acumatica_rep_mappings.acumatica_rep_code` for U  
2. Customers from SO where:  
   - `sales_consultant_rep_code IN rep_codes` **OR**  
   - `consultant_user_id = U.id`  
3. Require `customer_acumatica_id IS NOT NULL`  
4. Prefer `salesOrdersOnly` (same SO filter as ops modules)  
5. Optional filters: date range (default last 24 months or all-time config), order status exclude cancelled  
6. Optional: only customers that exist in `acumatica_customers` (default **on** — guardrail)

### 5.3 UI flow (`/app/administration` Team or `/app/team` → Consultant → Customers)

```
[ Select consultant ] → [ Preview SO match ]
    table: customer_id | name | class | SO count | last SO date | already assigned?
    summary: new | already | missing_from_master | sector_mismatch
→ [ Dry-run commit ] → [ Apply ]
→ Toast: N added, M skipped; link to batch audit
```

**Bulk:** “Match all consultants from SOs” → queues job (reuse `team:backfill-customers --all-consultants` semantics) with progress.

### 5.4 Modes

| Mode | Behavior |
|---|---|
| **Add only** (default) | Insert missing assignments; never remove |
| **Sync exact** | Assignments become exactly SO set (with confirm); remove orphans not in SO set — Admin only |
| **Date-window add** | Only SOs in `[from, to]` contribute |

### 5.5 CLI parity (keep)

```bash
php artisan team:backfill-customers --user=ID
php artisan team:backfill-customers --all-consultants
```

UI must call the same service methods as CLI for one truth.

---

## 6. Feature B — Excel upload (rep_code + customer_id)

### 6.1 User story

> As an Admin, I upload a spreadsheet that maps customers to consultants via **rep_code** and **customer_id**, review a dry-run, then apply.

### 6.2 Template columns

| Column | Required | Notes |
|---|---|---|
| `rep_code` | Yes* | *Or `consultant_email` / `employee_number` as alternate key |
| `customer_id` | Yes | Acumatica customer ID (`acumatica_id`) |
| `customer_name` | No | Used for validation display only; not authoritative |
| `assignment_type` | No | default `primary` |
| `notes` | No | Free text |
| `action` | No | `upsert` (default) \| `remove` |

**File types:** `.xlsx`, `.xls`, `.csv`  
**Max size:** e.g. 10 MB / 50k rows (config)  
**Header row:** required; case-insensitive; aliases accepted (`CustomerID`, `Customer Id`, `Rep Code`, `RepCode`).

### 6.3 Row resolution algorithm

For each data row:

1. Normalize `rep_code` → `UPPER(TRIM(...))`.  
2. Resolve user:  
   - Prefer active user with `users.rep_code = rep_code` and (`is_consultant` or role Sales Consultant)  
   - Else mapping table  
   - Else `consultant_email` / `employee_number` if provided  
3. Normalize `customer_id` → trim; reject empty.  
4. Lookup `acumatica_customers` by `acumatica_id`.  
5. Validate sector vs user sector scopes (if user has KP-only, reject MT/GT class unless Admin override flag).  
6. Classify:  
   - **create** — no assignment  
   - **update** — exists (notes/type change)  
   - **skip** — identical  
   - **error** — unresolved user/customer/sector/duplicate conflict  

### 6.4 Apply modes

| Mode | Behavior |
|---|---|
| **Dry-run** | No DB writes; return counts + downloadable error/preview sheet |
| **Apply (upsert)** | Create/update only; no deletes |
| **Apply with removes** | Rows with `action=remove` delete assignment |
| **Stop on first error** vs **best-effort** | Config; default **best-effort** with full error report |

### 6.5 UI flow

```
Administration → Team → [Upload customer map]
  1. Download template
  2. Upload file
  3. Dry-run results (tabs: OK / Errors / Warnings)
  4. Confirm Apply
  5. Batch summary + audit log entry
```

### 6.6 Sample template (illustrative)

| rep_code | customer_id | customer_name | notes |
|---|---|---|---|
| P415 | CUST01001 | Acme Hotel Ltd | Key account |
| P489 | CUST02055 | Gelian Hotel | KP |

---

## 7. Feature C — Migrate customer(s) to a new user

### 7.1 Intent

Move portfolio ownership of **one customer** or **many customers** from consultant **From** → consultant **To**, carrying **all assignment details** (not a bare re-key). Optional **notify** the involved user(s) by **email** and/or any **configured notification channel**.

Use cases: rep exit, territory rebalance, cover → permanent, KP handovers, correction after bad upload.

### 7.2 What “all their details” means (carried payload)

On migrate, copy onto the **To** user’s assignment (or update if already present as secondary):

| Field | Behavior |
|---|---|
| `customer_acumatica_id` | Same customer |
| `assignment_type` | Preserved (primary stays primary on To; From row removed) |
| `notes` | Preserved; system appends migration stamp *optional* |
| `source` | Set to `migrate` |
| `source_batch_id` | Migration batch |
| `confidence`, `last_so_date`, `so_order_count` | Preserved if present |
| `migrated_from_user_id` | = From user |
| `migrated_at` | now() |
| `assigned_by` | Actor performing migrate |

**Also snapshotted into `customer_assignment_migrations.assignment_snapshot_json`** so history is immutable even if notes later change.

**Related context (read-only snapshot, not ownership rewrite in v1):**

- Open FOL request count / ids for that customer (warning if open FOL under From)  
- Open SO count last 90 days  
- Customer name, class, status from master  

**Optional advanced flags (off by default):**

| Flag | Effect |
|---|---|
| `transfer_open_fol_owner` | Update open FOL `sales_consultant_user_id` to To (if column exists) |
| `retarget_local_so_consultant` | Update local `consultant_user_id` / display fields on open SOs (does **not** write Acumatica) |
| `keep_from_as_secondary` | Leave From with `assignment_type=secondary` instead of delete |

Default migrate: **remove From primary**, **upsert To with full details**, no SO rewrite, no FOL rewrite — with **warnings** if open FOL exists.

### 7.3 Single customer migrate

**Entry:** Admin → Team → From consultant → Customers → row actions **Migrate**  
or Customer detail (Admin) → **Reassign consultant**.

```
Select From (pre-filled) → Select customer (1) → Select To user
  → Preview: details to transfer + warnings
  → Toggle: Notify recipients  [ off | on ]
  → If on: channels  [x] Email  [x] In-app  [ ] …configured
           targets   [x] To user  [x] From user  [ ] HOD of To  [ ] Custom emails
  → Confirm reason (required, min 10 chars)
  → Apply
```

### 7.4 Multiple customer migrate

**Entry:** From consultant portfolio → multi-select customers → **Migrate selected**  
or **Migrate all from user** (with confirm type `MIGRATE ALL`).

Same To user, same notify toggles, same reason. Preview table lists each customer + detail fields that will move. Max N customers per batch (config, e.g. 500).

**Excel path (optional same engine):** columns  
`customer_id` | `from_rep_code` or `from_email` | `to_rep_code` or `to_email` | `notes?`  
Mode `migrate` dry-run/apply.

### 7.5 Notify toggle & channels

| Control | Default | Notes |
|---|---|---|
| **Notify** | **Off** | Must be explicit for production handovers |
| **Channels** | From system notification config | At minimum: **`email`**, **`in_app`**. Extensible: SMS, Teams, Slack, WhatsApp if wired in OrderWatch notification rules / services |
| **Targets** | To user on; From user on; HOD optional | Custom CC list from settings |

**Email identity (config):** e.g. from Team/Portfolio notifications or `kp@fayshop.co.ke` / “OrderWatch Portfolio”.

**Email / in-app content (must include):**

- Actor name  
- From consultant → To consultant  
- Count of customers  
- Table: customer_id, name, assignment_type  
- Reason  
- Deep link to To user’s portfolio (if permitted)  
- For single migrate: full notes/type carried  

**Channel resolution:** use shared notification dispatcher if present (`NotificationRule` channels array pattern); otherwise email + in-app table. Unknown channel in request → 422.

### 7.6 UI summary

| Surface | Action |
|---|---|
| Portfolio table | Migrate (single); bulk select → Migrate |
| Migrate wizard | From / To / customers / details preview / notify toggle / channels / apply |
| Batch history | mode=`migrate_*`, notify flags, download affected list |

```
[From: Shirleen P505]  →  [To: Titus P415]
Customers (3): CUST01, CUST02, CUST03
Carry: type, notes, SO stats, …
⚠ 1 open FOL on CUST02 — FOL owner not auto-changed (default)

[ ] Notify users
    Channels: [x] Email  [x] In-app
    Notify:   [x] Receiving consultant  [x] Previous consultant  [ ] HOD

Reason: _______________________
[Cancel]  [Migrate]
```

### 7.7 API sketch (migrate)

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/admin/customer-assignments/migrate` | Body below |
| GET | `/api/admin/customer-assignments/migrations` | History filter by customer/user |
| GET | `/api/admin/customer-assignments/migrations/{id}` | Detail + snapshot |

**Request body**

```json
{
  "from_user_id": 12,
  "to_user_id": 45,
  "customer_acumatica_ids": ["CUST01", "CUST02"],
  "mode": "move",
  "keep_from_as_secondary": false,
  "transfer_open_fol_owner": false,
  "retarget_local_so_consultant": false,
  "reason": "Territory handover East region",
  "dry_run": false,
  "notify": {
    "enabled": true,
    "channels": ["email", "in_app"],
    "targets": ["to_user", "from_user"],
    "custom_emails": []
  }
}
```

`customer_acumatica_ids` length 1 = single; &gt;1 = multi. Omit or pass `"*"` only with `migrate_all_from_user: true` + confirm token.

---

## 8. Feature D — Sales Consultant purchase-behavior reports

### 8.1 Intent

On the **Sales Consultant** module (`/app/sales-consultants` and `/app/sales-consultants/{id}`), give consultants and managers a **customer health report** driven purely by **Sales Order (SO)** activity in the consultant’s portfolio (assignments +/or SO rep scope).

Three core cuts:

| Report | Question answered |
|---|---|
| **MoM customers** | Who ordered this month vs last month? Growth, churn, retained. |
| **Order cycles** | Who is due / overdue vs their usual reorder rhythm? |
| **Dormant** | Who has **not purchased in the past 2 months** (rolling from today, EAT)? |

### 8.2 Scope of data

| Rule | Detail |
|---|---|
| Portfolio | Customers in `user_customer_assignments` for the consultant **union** customers with SO `sales_consultant_rep_code` / `consultant_user_id` (config: prefer assignments when non-empty) |
| SO set | `salesOrdersOnly`; exclude cancelled void types if already filtered in ops |
| Timezone | **Africa/Nairobi (EAT)** for “month” and “2 months” |
| Amount | Prefer `order_total` / line value consistent with consultant customer table |
| Permissions | Consultant: **own** only. HOD/Admin/Exec/C-suite: any consultant + rollups |

### 8.3 MoM customers (Month-over-Month)

**Definitions (EAT calendar months)**

| Cohort | Rule |
|---|---|
| **Ordered this month (M0)** | ≥1 SO with `order_date` in current calendar month |
| **Ordered last month (M-1)** | ≥1 SO in previous calendar month |
| **Retained** | In M0 **and** M-1 |
| **New / reactivated** | In M0 **not** in M-1 (optional split: never ordered before M0 vs gap) |
| **Churned (MoM)** | In M-1 **not** in M0 |
| **MoM customer count Δ** | \|M0\| − \|M-1\| |
| **MoM value Δ** | sum(M0 order value) − sum(M-1) |

**UI**

- KPI cards: M0 count, M-1 count, Δ%, retained, churned, new/reactivated  
- Tabs/tables for each cohort with customer link, last order date, order count M0/M-1, value  
- Trend sparkline last 6 months (optional v1.1)  
- Export Excel  

**API (sketch)**  
`GET /api/operations/sales-consultants/{id}/reports/mom-customers?as_of=YYYY-MM-DD`

### 8.4 Order cycles

For each portfolio customer with enough history:

1. Compute inter-order gaps (days) from last N SOs (default N=6, min 2 orders).  
2. **Expected cycle days** = median gap (or mean; config).  
3. **Days since last SO** = today − max(order_date).  
4. **Status**

| Status | Rule (defaults) |
|---|---|
| **On cycle** | days_since_last ≤ expected_cycle × 1.1 |
| **Due soon** | expected_cycle × 1.1 &lt; days_since_last ≤ expected_cycle × 1.5 |
| **Overdue cycle** | days_since_last &gt; expected_cycle × 1.5 |
| **Insufficient history** | &lt; 2 historical SOs |
| **Dormant** | days_since_last ≥ **60** (2 months) — aligns with §8.5; overrides cycle labels in UI badge |

**UI:** sortable table — customer, last order, expected cycle (days), days since last, status badge, suggested next order window.  
**API:** `GET .../reports/order-cycles`

### 8.5 Dormant customers (2 months)

| Field | Definition |
|---|---|
| **Dormant** | No SO in the rolling window **[today − 60 days, today]** (EAT start-of-day). Config: `dormant_days` default **60** (“past 2 months”). |
| **As-of date** | Default today; allow `as_of` for backtesting |
| **Never ordered** | In portfolio but zero SOs ever — separate bucket **Never purchased** (not lumped into dormant silently) |

**UI:** Dormant list + count KPI on consultant detail; filter “Dormant only” on customer table.  
**API:** `GET .../reports/dormant?dormant_days=60`

### 8.6 Consultant report shell UX

`/app/sales-consultants/{id}` → tab **Customer health** (or section under customers):

```
[ MoM ] [ Order cycles ] [ Dormant ]
KPI row: Active (ordered ≤60d) | Dormant | Due/Overdue cycle | MoM Δ
```

List page (`/app/sales-consultants`): optional columns — dormant count, MoM Δ for managers.

---

## 9. Feature E — Sitewide customer behavior badge (SO-driven)

### 9.1 Intent

Show a consistent **behavior badge** on a customer **everywhere** the customer appears: customers list, customer orders, sales consultant customer tables, FOL, order lists, dashboard widgets, KP modules.

Badges derived only from **SO purchase behavior** (not CRM notes).

### 9.2 Taxonomy (v1)

| Badge | Color (suggest) | Rule (defaults, config) |
|---|---|---|
| **Recurring** | green | ≥2 SOs in last 180 days **and** not dormant (last SO &lt; 60 days) **and** (optional) median cycle ≤ 45 days |
| **Dormant** | amber/red | Last SO ≥ **60 days** ago (or never ordered in window but had history before) |
| **New** | blue | First SO ever within last 60 days (or first SO in last 90 days) |
| **One-off** | gray | Exactly 1 SO ever, and last SO &lt; 60 days |
| **Unknown / No SO** | muted | No SO history in system |

**Precedence (highest first):** `No SO` → `Dormant` → `New` → `One-off` → `Recurring`.

> Product language: **Recurring** = actively buying on a pattern; **Dormant** = was a buyer but silent ≥ 2 months.

### 9.3 Computation service (single source of truth)

```
CustomerPurchaseBehaviorService
  input: customer_acumatica_id | list, as_of?, dormant_days=60
  output: {
    behavior: recurring|dormant|new|one_off|no_so,
    label: "Recurring"|"Dormant"|...,
    last_order_date,
    days_since_last_order,
    order_count_total,
    order_count_180d,
    expected_cycle_days | null,
    dormant_days_threshold: 60
  }
```

- Prefer **batch** endpoint for lists (avoid N+1).  
- Optional cache table `customer_purchase_behavior` refreshed by cron (hourly/nightly) + on SO sync; always allow live recompute for detail pages.  
- Scoped queries still apply (consultant only sees own customers’ badges in lists).

### 9.4 Sitewide placement (must use shared component)

| Surface | Placement |
|---|---|
| `/app/customers` | Column + chip next to name |
| `/app/customer-orders/*` | Header next to customer name |
| `/app/sales-consultants/{id}` customer table | Column **Behavior** |
| `/app/orders`, orders-by-date | Optional chip on customer cell |
| FOL request customer header | Chip |
| KP / migrate / assignment UIs | Chip |
| Exports | Column `behavior` / `behavior_label` |

**Shared UI:** `CustomerBehaviorBadge` in `src/components/` (same pattern as status badges).  
**API enrichment:** include `purchase_behavior` on customer DTOs and consultant customer rows.

### 9.5 Config (Admin)

| Setting | Default |
|---|---|
| `dormant_days` | **60** (2 months) |
| `recurring_min_orders_180d` | 2 |
| `recurring_max_days_since_last` | 60 (must be active) |
| `new_customer_first_order_days` | 60 |
| `cycle_median_orders` | 6 |
| Badge feature flag | on |

---

## 10. Guardrails (non-negotiable)

### 10.1 Identity & resolution

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-C01 | `customer_id` must exist in `acumatica_customers` (default) | Reject row; no orphan assignment |
| GR-C02 | `rep_code` must resolve to **exactly one** active user | Reject if 0 or &gt;1 (unless email disambiguates) |
| GR-C03 | Inactive users cannot receive new assignments | Reject unless Admin force flag |
| GR-C04 | Customer ID normalized (trim; preserve Acumatica casing rules consistently) | Single normalizer service |
| GR-C05 | Duplicate rows in file for same (rep, customer) → last wins + warning | Dry-run warning |

### 10.2 Scope & sector

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-C10 | Do not assign customer outside user’s **sector scope** (e.g. KP-only user + non-KP class) | Reject row; Admin override requires reason |
| GR-C11 | SO match only includes `salesOrdersOnly` orders | Same as ops |
| GR-C12 | Excel cannot expand a consultant past fail-closed `deny_all` without setting scoped mode | Warning + optional auto-set `data_scope_mode=scoped` with confirm |

### 10.3 Write safety

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-C20 | **Dry-run required** before Apply for Excel (UI) | Two-step; API accepts `dry_run=true` |
| GR-C21 | **Add-only** is default; Sync exact requires typed confirm `SYNC` | UI + API flag |
| GR-C22 | Unique `(user_id, customer_acumatica_id)` — no duplicate rows | DB unique + upsert |
| GR-C23 | Batch size limit | Config max rows |
| GR-C24 | Partial apply records per-row results; never silent drop without error report | Batch rows table |
| GR-C25 | Removes via Excel only when `action=remove` column present | No accidental wipe |

### 10.4 Permissions & audit

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-C30 | Only users with `customers.assign.manage` (+ manage_all for cross-tree) | 403 |
| GR-C31 | CS Manager cannot assign to Administrator/Executive targets | Role policy |
| GR-C32 | Every apply writes audit: actor, batch id, source, counts | `org_chart_audits` or dedicated log |
| GR-C33 | Source stamped on assignment (`so_backfill` \| `excel_upload` \| `manual` \| `so_match_ui` \| `migrate`) | Column required on write |

### 10.5 Downstream consumers

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-C40 | FOL create uses same portfolio as assignments (existing FOL GR-01) | Shared `CustomerScope` |
| GR-C41 | After apply, consultant session/capabilities need not re-login for new customers | Scope reads DB live |
| GR-C42 | Matching must not change `users.role` or passwords | Assignment tables only |

### 10.6 Customer migrate (single / multi)

| ID | Guardrail | Enforcement |
|---|---|---|
| **GR-C50** | `from_user_id` ≠ `to_user_id` | 422 |
| **GR-C51** | Every customer in the migrate set must currently be assigned to **From** (unless Admin force orphan attach) | Reject missing |
| **GR-C52** | **To** user must be active and eligible (consultant / assignable) | 422 |
| **GR-C53** | Sector/scope of **To** must allow each customer (same as assign) | Reject row or whole batch (config) |
| **GR-C54** | Migrate carries **full assignment details** (type, notes, stats, source history via snapshot) — not empty shell | Service copies fields |
| **GR-C55** | Default: remove From assignment (or demote secondary if flag); no dual primary | Unique + delete/upsert |
| **GR-C56** | **Reason required** on apply (min 10 chars) | 422 |
| **GR-C57** | Multi migrate is **atomic per batch** if `transactional=true` (default true): all succeed or none | DB transaction |
| **GR-C58** | Open FOL under From → **warning**; auto transfer only if `transfer_open_fol_owner=true` | Preview + flags |
| **GR-C59** | **Notify is opt-in** (`notify.enabled`); never send on dry-run | No mail if dry_run or toggle off |
| **GR-C60** | Notify channels must be in **allowed config** (`email`, `in_app`, …) | 422 unknown channel |
| **GR-C61** | Notify does not leak full portfolio of To to From beyond migrated customers list | Template scoping |
| **GR-C62** | Every migrated customer writes `customer_assignment_migrations` row | Immutable history |
| **GR-C63** | Migrate does not rewrite Acumatica SO rep codes unless explicit future flag | Default off |

### 10.7 Purchase behavior reports & sitewide badge

| ID | Guardrail | Enforcement |
|---|---|---|
| **GR-C70** | Dormant = no SO in rolling **`dormant_days`** (default **60** / 2 months) from `as_of` EAT | Single service; no ad-hoc windows in UI |
| **GR-C71** | Behavior badge uses **same service** sitewide | No per-page reimplementation |
| **GR-C72** | Consultant reports only include customers in **their** scope | OrgScope / assignments |
| **GR-C73** | MoM months use **calendar months EAT** | Documented in API |
| **GR-C74** | “Never purchased” not labeled Dormant | Separate status/badge |
| **GR-C75** | Order-cycle stats require min order history; else `insufficient_history` | No fake cycles |
| **GR-C76** | List endpoints batch behavior (no N+1 SO queries per row) | Service batch / cache |
| **GR-C77** | Badge does not expose revenue if user has `mask_revenue` | Respect existing mask |

---

## 11. Acceptance criteria

### 11.1 SO match

| ID | Criterion |
|---|---|
| AC-01 | **Given** consultant with rep `P415` and SOs for customers A,B, **when** SO match preview runs, **then** A and B appear with SO counts. **[Auto]** |
| AC-02 | **Given** A already assigned, **when** match apply (add-only), **then** only B is created; A skipped. **[Auto]** |
| AC-03 | **Given** SO customer not in `acumatica_customers`, **when** preview with master-check on, **then** row listed under missing_from_master and not applied. **[Auto]** |
| AC-04 | **Given** `--all-consultants` / bulk UI, **when** job completes, **then** each consultant with rep code gets non-decreasing assignment count. **[Auto]** |
| AC-05 | **Given** user without assign permission, **when** POST match, **then** 403. **[Auto]** |

### 11.2 Excel upload

| ID | Criterion |
|---|---|
| AC-10 | **Given** valid template row (known rep + known customer), **when** dry-run, **then** action=create and no DB change. **[Auto]** |
| AC-11 | **Given** same file applied twice (upsert), **when** second apply, **then** created=0, skipped≥1, no duplicate assignments. **[Auto]** |
| AC-12 | **Given** unknown rep_code, **when** dry-run, **then** row error “user not resolved”. **[Auto]** |
| AC-13 | **Given** unknown customer_id, **when** dry-run, **then** row error “customer not found”. **[Auto]** |
| AC-14 | **Given** KP-scoped user and MT customer class, **when** dry-run without override, **then** sector_mismatch error. **[Auto]** |
| AC-15 | **Given** action=remove for existing assignment, **when** apply, **then** assignment deleted. **[Auto]** |
| AC-16 | **Given** file &gt; max rows, **when** upload, **then** 422 before processing. **[Auto]** |
| AC-17 | **Given** apply completes with errors, **when** download error report, **then** file lists row_no + message for each error. |

### 11.3 Guardrails & audit

| ID | Criterion |
|---|---|
| AC-20 | **Given** applied batch, **when** inspect assignments, **then** `source` and `source_batch_id` set. **[Auto]** |
| AC-21 | **Given** apply, **when** audit log queried, **then** entry includes actor email and stats. **[Auto]** |
| AC-22 | **Given** consultant portfolio {A}, **when** they FOL-request for B, **then** still 403 (FOL GR-01). **[Auto]** |

### 11.4 Customer migrate (single & multi)

| ID | Criterion |
|---|---|
| AC-30 | **Given** From has customer A with notes “VIP” and type primary, **when** migrate A → To, **then** To has A with notes “VIP”, type primary, `source=migrate`, `migrated_from_user_id=From`; From no longer has primary A. **[Auto]** |
| AC-31 | **Given** From has A,B,C, **when** multi-migrate [A,B] → To, **then** To gains A,B with full details; From retains only C. **[Auto]** |
| AC-32 | **Given** customer X not on From, **when** migrate X → To, **then** 422 / row error. **[Auto]** |
| AC-33 | **Given** from_user_id = to_user_id, **when** migrate, **then** 422. **[Auto]** |
| AC-34 | **Given** apply without reason, **when** migrate, **then** 422. **[Auto]** |
| AC-35 | **Given** `notify.enabled=false`, **when** migrate applies, **then** no notification dispatched. **[Auto]** |
| AC-36 | **Given** `notify.enabled=true`, channels `["email","in_app"]`, targets `["to_user","from_user"]`, **when** migrate applies, **then** both users notified on allowed channels only; dry_run would not notify. **[Auto]** |
| AC-37 | **Given** `notify.channels=["carrier_pigeon"]`, **when** migrate, **then** 422 unknown channel. **[Auto]** |
| AC-38 | **Given** open FOL for customer A under From and default flags, **when** preview migrate, **then** warning shown and FOL owner unchanged after apply. **[Auto]** |
| AC-39 | **Given** multi migrate with `transactional=true` and one invalid customer mid-list, **when** apply, **then** no customers move (all-or-nothing). **[Auto]** |
| AC-40 | **Given** successful migrate, **when** query migrations table, **then** one history row per customer with `assignment_snapshot_json` containing prior notes/type. **[Auto]** |

### 11.5 Sales consultant MoM / cycles / dormant

| ID | Criterion |
|---|---|
| AC-50 | **Given** customer with last SO 70 days ago, **when** dormant report (`dormant_days=60`), **then** customer is listed dormant. **[Auto]** |
| AC-51 | **Given** customer with SO yesterday, **when** dormant report, **then** not listed. **[Auto]** |
| AC-52 | **Given** portfolio customer with zero SOs, **when** dormant report, **then** in **Never purchased** not Dormant. **[Auto]** |
| AC-53 | **Given** M-1 has A,B and M0 has B,C, **when** MoM report, **then** retained={B}, churned={A}, new/reactivated={C}. **[Auto]** |
| AC-54 | **Given** regular 30-day cycle and 50 days since last SO, **when** order-cycle report, **then** status is overdue (or due soon per thresholds). **[Auto]** |
| AC-55 | **Given** consultant user, **when** they request another consultant’s report, **then** 403. **[Auto]** |

### 11.6 Sitewide behavior badge

| ID | Criterion |
|---|---|
| AC-60 | **Given** last SO ≥ 60 days, **when** behavior service runs, **then** `behavior=dormant`, label **Dormant**. **[Auto]** |
| AC-61 | **Given** ≥2 SOs in 180d and last SO &lt; 60d, **when** service runs, **then** `behavior=recurring`, label **Recurring**. **[Auto]** |
| AC-62 | **Given** first SO ever 10 days ago, **when** service runs, **then** `behavior=new` (not Recurring). **[Auto]** |
| AC-63 | **Given** customers list API, **when** fetched, **then** each row includes `purchase_behavior` without N+1 query explosion (batch). **[Auto]** |
| AC-64 | **Given** same customer on consultant table and customers page, **when** both render, **then** badge label matches. **[Auto]** |
| AC-65 | **Given** revenue-masked role, **when** behavior DTO returned, **then** no order value fields leaked. **[Auto]** |

---

## 12. UX placement

| Surface | Action |
|---|---|
| **Administration → Team Members** | Per-user: “Customers” sheet — list, manual add, **Match from SOs**, **Migrate**, export |
| **Administration → Team Members** (or Data tools) | **Upload customer map** (Excel) |
| **Team module** (`/app/team`) | HOD view of reportee portfolios (read); match/migrate if permitted |
| **Sales Consultants detail** | Customers + **Customer health** (MoM / cycles / Dormant) + **Behavior** column |
| **Sales Consultants list** | Optional dormant count / MoM Δ columns |
| **Customers / Orders / FOL / KP** | Sitewide **CustomerBehaviorBadge** |

Screens:

1. Portfolio table (search, class, source badge, **behavior badge**, last SO, multi-select)  
2. SO match preview modal  
3. Excel wizard (template → dry-run → apply)  
4. **Migrate wizard** (single / multi) — details preview + **Notify** toggle + channels  
5. Batch / migration history list  
6. **Consultant Customer health** tabs: MoM · Order cycles · Dormant  

---

## 13. API sketch (for engineering)

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/admin/users/{id}/customer-assignments` | Existing list |
| PUT | `/api/admin/users/{id}/customer-assignments` | Existing sync (manual multi-select) |
| POST | `/api/admin/users/{id}/customer-assignments/match-so` | Body: `{ dry_run, mode, date_from?, date_to? }` |
| POST | `/api/admin/customer-assignments/match-so-bulk` | All consultants job |
| POST | `/api/admin/customer-assignments/upload` | multipart file + `dry_run` |
| POST | `/api/admin/customer-assignments/batches/{id}/apply` | Apply prior dry-run |
| GET | `/api/admin/customer-assignments/batches/{id}` | Status + stats |
| GET | `/api/admin/customer-assignments/batches/{id}/errors` | Download report |
| GET | `/api/admin/customer-assignments/template` | Empty xlsx template |
| POST | `/api/admin/customer-assignments/migrate` | Single or multi migrate + notify (see §7.7) |
| GET | `/api/admin/customer-assignments/migrations` | History |
| GET | `/api/admin/customer-assignments/migrations/{id}` | Detail + snapshot |
| GET | `/api/operations/sales-consultants/{id}/reports/mom-customers` | MoM cohorts |
| GET | `/api/operations/sales-consultants/{id}/reports/order-cycles` | Cycle due/overdue |
| GET | `/api/operations/sales-consultants/{id}/reports/dormant` | No SO in past N days (default 60) |
| GET | `/api/operations/customers/purchase-behavior` | Batch behavior for ids |
| GET | `/api/operations/customers/{id}/purchase-behavior` | Single customer |

Reuse: `CustomerAssignmentService` + new **`CustomerPurchaseBehaviorService`** (SO-only, shared badge).

---

## 14. Notifications

| Event | Who | Message |
|---|---|---|
| Bulk match completed | Initiator | Counts added/skipped/errors |
| Excel apply completed | Initiator | Link to error report if any |
| Consultant portfolio empty after sync exact | Admin | Warning |
| **Customer migrate applied** | Per **notify** toggle | See §7.5 — To / From / HOD / custom; channels email + in_app (+ configured) |
| **Dormant digest (optional v1.1)** | Consultant / HOD | Weekly: customers newly dormant in portfolio |

**Notify defaults (migrate)**

| Setting | Default |
|---|---|
| Notify on migrate | **Off** (explicit opt-in) |
| Channels available | `email`, `in_app` (extend via config / notification rules) |
| Targets | `to_user`, `from_user` suggested on; HOD off |
| Dry-run | **Never** sends |

---

## 15. Phased delivery

| Phase | Scope | Exit |
|---|---|---|
| **CM-0** | Extend assignment columns (`source`, batch); permissions | Migrations green |
| **CM-1** | SO match preview + apply (single user) in Admin UI | AC-01–05 |
| **CM-2** | Excel template + dry-run + apply + error report | AC-10–17 |
| **CM-3** | Bulk SO match job + progress; sector guardrails | HOD/ops can run monthly |
| **CM-4** | Batch history UI; export portfolio; HOD reportee match | Audit complete |
| **CM-5** | **Migrate single + multi** with full detail carry + history | AC-30–34, 38–40 |
| **CM-6** | **Notify toggle** (email + in_app + channel config) | AC-35–37 |
| **CM-7** | **`CustomerPurchaseBehaviorService` + sitewide badge** (Recurring/Dormant/…) | AC-60–65 |
| **CM-8** | **Consultant reports**: MoM, order cycles, dormant (60d) | AC-50–55 |

---

## 16. Metrics

| Metric | Target |
|---|---|
| % active consultants with ≥1 assignment | ≥ 90% after first bulk match |
| Excel dry-run → apply conversion without critical errors | ≥ 80% of batches |
| Support tickets “wrong customers on FOL” | ↓ after go-live |
| Time to onboard new KP consultant portfolio | &lt; 15 min (upload or SO match) |
| Territory handovers via migrate (not ad-hoc SQL) | 100% of reassignments after CM-5 |
| Migrate with notify used when both parties need awareness | Tracked; no silent dry-run emails |
| Dormant customers contacted / reactivated (ops KPI) | Track MoM dormant → ordered |
| Badge consistency incidents (mismatched labels) | 0 |

---

## 17. Open questions

1. Should **one customer** allow multiple consultants (`secondary` type) in v1?  
2. Default SO lookback window: all-time vs 12/24 months?  
3. On sector mismatch, hard reject or Admin override with reason?  
4. After Excel assign, auto-set `is_consultant=true` if false?  
5. Who may run bulk match — Admin only or CS Manager too?  
6. Keep filename `cutomer-upload.md` or rename to `customer-upload.md`?  
7. On migrate, default **keep_from_as_secondary** or hard remove From?  
8. Should notify also include a **PDF/Excel attachment** of migrated customer list?  
9. Auto-transfer open FOL owner on migrate — default off or on for KP?  
10. Dormant window: fixed **60 days** vs calendar “2 months”? (PRD default: **rolling 60 days EAT**.)  
11. Is **Recurring** allowed for irregular buyers with frequent orders, or must median cycle be stable?

---

## 18. Summary

| Capability | Description |
|---|---|
| **SO match** | Derive portfolio from SO rep code / consultant_user_id → `user_customer_assignments` |
| **Excel upload** | Map `rep_code` + `customer_id` with dry-run, upsert, remove, error report |
| **Migrate (single / multi)** | Move customers From → To with **full assignment details**; optional flags for FOL/SO; immutable migration history |
| **Notify** | Toggle on apply: **email** and/or **configured channels** (`in_app`, …); targets To / From / HOD / custom; never on dry-run |
| **Consultant reports** | **MoM** cohorts, **order cycles** (due/overdue), **Dormant** (no SO in past **2 months / 60 days**) |
| **Sitewide behavior badge** | **Recurring** / **Dormant** / New / One-off / No SO from SO history — one service, all customer surfaces |
| **Guardrails** | Master data, sector, dry-run, audit, migrate atomicity, opt-in notify (GR-C50–63), behavior consistency (GR-C70–77) |
| **Integration** | Same L4 portfolio used by FOL, orders, KP modules |

**Engineering north star:** one `CustomerAssignmentService` for portfolio ops + one **`CustomerPurchaseBehaviorService`** for MoM/cycles/dormant reports and **sitewide Recurring/Dormant badges**.

---

*Stacks with `docs/team.md` L4; builds on `CustomerAssignmentService`, `user_customer_assignments`, and SO models (`salesOrdersOnly`).*
