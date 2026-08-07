env Cred Strucutre:

SFA_DB_USERNAME=
SFA_DB_PASSWORD=
SFA_DB_DATABASE=
SFA_DB_HOST=
SFA_DB_PORT=


# Daily OpenAI management insights
# Replace only the placeholder key. Never expose it through a VITE_* variable.
OPENAI_API_KEY=
OPENAI_DAILY_INSIGHTS_ENABLED=true
OPENAI_INSIGHTS_MODEL=gpt-5.6-terra
OPENAI_INSIGHTS_MODEL=gpt-5.6-terra
OPENAI_IMAGE_MODEL=gpt-image-2
OPENAI_TEXT_MODEL=gpt-5.6
OPENAI_INSIGHTS_TIME=07:00
OPENAI_INSIGHTS_TIMEZONE=Africa/Nairobi
OPENAI_INSIGHTS_EMAIL_ENABLED=true
OPENAI_ASK_EMAIL_ENABLED=true
OPENAI_INSIGHTS_TIMEOUT=120



# SFA (Solutech) Data Sync — Full PRD + Module Migration Guide

**Source studied:** this repo’s Laravel backend (`DataSyncController`, `SyncSfaData`, `SyncSfaTableJob`, `config/sfa_sync.php`, scheduler, models, portable schema kit).  
**Audience:** engineers embedding a **tight SFA sync module** into an **existing system** (or greenfield).  
**Timezone:** Africa/Nairobi (EAT) unless noted.  
**Portable assets:** `php-backend/database/sfa-sync-portable/`

---

## 0. Quick start — building the module in another system

Use this document as the **single source of truth**. Do not invent alternate table names or upsert keys unless you also rewrite all field maps and KPIs.

### 0.1 Recommended approach (existing host app)

Treat SFA as a **bounded module**, not scattered app code:

```
host-app/
  modules/SfaSync/                    # or packages/sfa-sync/
    Config/sfa_sync.php
    Database/
      migrations/…_create_all_sfa_sync_tables.php
      seeders/SfaSyncStateSeeder.php
      seeders/SfaReferenceDataSeeder.php
      seeders/SfaDemoDataSeeder.php
      schema.sql                      # optional pure MySQL
    Models/                           # only SFA entities
    Services/
      SfaRemoteConnection.php
      SfaSyncOrchestrator.php
      Importers/                      # one class per table
        RegionImporter.php
        RepImporter.php
        …
        DailyPerformanceImporter.php
      Metrics/
        AttendanceCalculator.php      # late / present / leave
        PjpPlanner.php
        CoverageCalculator.php
    Jobs/SyncSfaTableJob.php
    Console/SyncSfaDataCommand.php
    Http/SfaSyncController.php
    routes.php
```

### 0.2 Isolation rules (avoid collisions with host app)

| Risk | Rule |
|------|------|
| Host already has `users` / `customers` | **Do not** reuse those tables. Keep SFA `reps` and SFA `customers` as module tables. Link later via match columns. |
| Host already has `regions` | Prefer module prefix **or** document that SFA `regions.id` = Solutech id and must not be auto-increment host data. |
| Shared MySQL database | Use table names from this PRD as-is, or a prefix (`sfa_reps`) **only if** every importer, API, and seeder uses the same prefix. |
| Host auth | Module APIs hang under host auth (`admin` middleware). Sync itself is system/cron, not user-session work. |
| Host queue | Dedicated queue name `sfa-sync` so long ETL does not starve host jobs. |
| Host scheduler | Register module crons from host `schedule:run`; do not require a second cron daemon. |

### 0.3 Copy-paste install paths

**A. Laravel host (preferred)**

```bash
# From this repo:
cp php-backend/database/sfa-sync-portable/migrations/*.php  →  modules/SfaSync/Database/migrations/
cp php-backend/database/seeders/Sfa*.php                    →  modules/SfaSync/Database/seeders/
# Port config/sfa_sync.php + sfa_remote DB connection + services from §10.12

php artisan migrate
php artisan db:seed --class=SfaSyncStateSeeder
# optional offline demo:
php artisan db:seed --class=SfaDemoDataSeeder
```

**B. Any stack (MySQL only)**

```bash
mysql -u USER -p HOST_DB < php-backend/database/sfa-sync-portable/schema.sql
```

Then implement importers against §5 field maps and §15 column catalog.

**C. Env (host `.env`)**

```
SFA_DB_HOST=
SFA_DB_PORT=1166
SFA_DB_DATABASE=
SFA_DB_USERNAME=
SFA_DB_PASSWORD=
SFA_SYNC_TIMEZONE=Africa/Nairobi
SFA_SYNC_QUEUE=sfa-sync
SFA_LATE_THRESHOLD=08:30
QUEUE_CONNECTION=database   # or redis
```

---

## 1. Product summary

Pull operational field-force data from Solutech SFA’s read-only BI MySQL database (`bi_*` tables) into a **local warehouse** (module tables), on a staggered cron schedule during business hours. Transform that data into:

| Capability | Primary sources |
|---|---|
| Customers (SFA outlets) | `bi_customer_master` |
| Users / reps by region & territory | `bi_users`, `bi_routes`, territories on users |
| Sales line entries | `bi_salesmaster` |
| Route coverage / adherence / productivity | visits + shop_routes + user_routes + sales |
| Planned PJP for the day | `bi_user_routes` + `bi_shop_routes` + day/week rules |
| Late arrival (1st outlet check-in after 08:30) | `bi_customer_visits.CHECKINTIME` min per user/day |
| Login / day-start time | `bi_start_end_day.STARTDAYTIME` |
| Absent (with/without leave) | no visits + optional leave roster (not in SFA; separate upload) |

**Team split:** filter field users by `user_channel`:

| Code | Team | Module column |
|---|---|---|
| `1` | GT (General Trade) | `reps.team = 'GT'` |
| `2` | MT (Modern Trade) | `reps.team = 'MT'` |
| `3`, `4` | Other | **not synced** by default |

Later (out of scope for v1 sync): match SFA customers to Acumatica customers; match team members to ASMs/supervisors from Acumatica. Match columns already exist on the schema (`acumatica_*`, `match_status`).

---

## 2. How the current system works (architecture)

```
┌─────────────────────────────┐
│  Solutech SFA BI MySQL      │  connection name: sfa_remote
│  host/port/db from env      │  tables: bi_*  (READ ONLY)
└──────────────┬──────────────┘
               │ SELECT
               ▼
┌─────────────────────────────┐
│  Host scheduler             │  * * * * * php artisan schedule:run
│  config/sfa_sync.php        │  staggered minutes so jobs don’t collide
└──────────────┬──────────────┘
               │ sfa:sync --table=X --queue
               ▼
┌─────────────────────────────┐
│  Queue: sfa-sync            │  SyncSfaTableJob (timeout ≥ 1200s)
│  WithoutOverlapping(table)  │  one job per table
└──────────────┬──────────────┘
               │ Importer per table
               ▼
┌─────────────────────────────┐
│  Module local tables        │  upsert by SFA natural keys
│  sync_logs / sync_state     │  progress + last success
└─────────────────────────────┘
```

### 2.1 Entry points

| Path | Purpose |
|---|---|
| Cron → `sfa:sync --table={t} --queue` | Production scheduled import |
| `POST /api/data-sync/run` | Manual / admin UI (`async=1`, single/all tables, `allow_past=1`) |
| `GET /api/data-sync/status` | Connection health + counts + schedule |
| `GET /api/data-sync/progress/{batchId}` | Poll async batch |
| `POST /api/data-sync/cancel` | Cancel queued batch |
| `GET /api/data-sync/exceptions` | Data quality: missing reps, invalid channels |
| CLI: `sfa:sync --date= --table= --days= --allow-past --queue` | Ops / backfill |

### 2.2 Design rules (must keep in the new module)

1. **Current-day only** on scheduled runs. Historical pull requires explicit `allow_past`.
2. **Staggered crons** — different minute offsets so jobs never all fire at `:00`.
3. **Business window 08:00–18:59 EAT** for transactional tables; masters overnight/morning; last customers pass ~19:00.
4. **Large tables always async** when browser-triggered (avoid HTTP 504).
5. **One job per table** + overlap lock per table name.
6. **Channel filter at the SQL source:** only `user_channel IN (1, 2)`.
7. **Schema drift tolerance:** probe remote column names (case variants) or keep a column-map config.
8. **Upsert by natural SFA IDs** — never wipe-and-reload whole tables on each run.
9. **Soft-null missing FKs** (region/territory missing locally → store null, do not fail the row).
10. **Do not write to Solutech.**

### 2.3 Remote connection

```
SFA_DB_HOST, SFA_DB_PORT, SFA_DB_DATABASE, SFA_DB_USERNAME, SFA_DB_PASSWORD
```

Connection alias: `sfa_remote` (MySQL). BI user should be **SELECT-only** on `bi_*`.

---

## 3. Table inventory & dependency order

Always process full runs in this order:

| # | Local table | Remote source | Type | Depends on | Upsert key |
|---|---|---|---|---|---|
| 1 | `regions` | `bi_routes.region_id` + names | Reference | — | `id` (SFA region_id) |
| 2 | `territories` | `bi_users` territory cols | Reference | — | `id` |
| 3 | `channels` | `bi_customer_master.CHANNEL` | Reference | — | `name` |
| 4 | `rolegroups` | `bi_users.rolegroup_id` | Reference | — | `id` |
| 5 | `products` | `bi_products` | Reference | — | `id` |
| 6 | `uoms` | `bi_uom` | Reference | — | `id` |
| 7 | `uom_quantities` | `bi_uom_quantities` | Reference | products | `id` |
| 8 | `routes` | `bi_routes` | Reference | regions | `sfa_route_id` |
| 9 | `reps` | `bi_users` ch∈{1,2} | Reference | regions, territories, rolegroups | `id` (SFA user) |
| 10 | `customers` | `bi_customer_master` active | Reference | regions, reps | `sfa_shop_id` |
| 11 | `shop_routes` | `bi_shop_routes` not removed | Reference | routes, customers | `id` |
| 12 | `user_routes` | `bi_user_routes` | Reference | routes, reps | `id` |
| 13 | `customer_visits` | `bi_customer_visits` **date** | Date-scoped | reps | `sfa_visit_id` |
| 14 | `start_end_days` | `bi_start_end_day` **date** | Date-scoped | reps | (`user_id`,`start_day_time`) |
| 15 | `sales_entries` | `bi_salesmaster` **date** | Date-scoped | reps | (`entry_id`,`product_id`) |
| 16 | `daily_performances` | **computed** | Date-scoped | visits, sales, PJP | (`rep_id`,`date`) |

Also local (not from Solutech BI):

| Table | Purpose |
|---|---|
| `leaves` | HR leave roster (CSV/API) |
| `sync_logs` | Per-run observability |
| `sync_state` | Enable/disable + last success per table |

**Reference** = full snapshot upsert. **Date-scoped** = `WHERE DATE(col) = :syncDate`.

---

## 4. Cron schedule (production)

From `config/sfa_sync.php` + host scheduler. Timezone: **Africa/Nairobi**.

### 4.1 Once-daily masters (early morning)

| Entity | Cron | Time |
|---|---|---|
| channels | `0 3 * * *` | 03:00 |
| territories | `15 3 * * *` | 03:15 |
| regions | `30 3 * * *` | 03:30 |
| rolegroups | `0 4 * * *` | 04:00 |
| uoms | `0 5 * * *` | 05:00 |
| uom_quantities | `0 6 * * *` | 06:00 |

### 4.2 Multi-shot masters

| Entity | Times |
|---|---|
| customers | 08:00, 12:00, 19:00 |
| reps | 08:05, 11:00, 18:00 |
| products | 08:10, 12:30 |

### 4.3 Transactional window (08:00–18:59 only)

| Entity | Frequency | Stagger minutes |
|---|---|---|
| sales_entries | every 15 min | `:01, :16, :31, :46` |
| start_end_days | every 10 min | `:03, :13, :23, :33, :43, :53` |
| customer_visits | every 10 min | `:06, :16, :26, :36, :46, :56` |
| daily_performances | every 30 min | `:08, :38` |
| user_routes | every 30 min | `:13, :43` |
| routes | hourly | `:17` |
| shop_routes | hourly | `:47` |

### 4.4 Infrastructure

| Job | Schedule | Role |
|---|---|---|
| `queue:work --queue=sfa-sync,default --stop-when-empty --tries=2 --timeout=1800` | every minute | Shared-host friendly worker |
| Host crontab | `* * * * * … schedule:run` | Required |

---

## 5. Field matching (remote → local)

Remote BI columns are often **UPPERCASE**. Local tables use snake_case.

### 5.1 Regions

| Local | Source | Notes |
|---|---|---|
| `id` | `bi_routes.region_id` | SFA id is PK (not host auto-id) |
| `name` | `bi_customer_master.REGIONNAME` (probed) | **Not unique** — two ids may share a name |
| `is_active` | true | |

### 5.2 Territories

| Local | Source |
|---|---|
| `id` | `bi_users.territory_id` (probe cases) |
| `name` | `territory_name` if present else `"Territory {id}"` |

**Do not** read territory from `bi_customer_master` (not present in this BI).

### 5.3 Channels

| Local | Source |
|---|---|
| `name` | distinct `CHANNEL` |

### 5.4 Role groups

| Local | Source |
|---|---|
| `id` | `rolegroup_id` |
| `name` | optional name col or `"RoleGroup {id}"` |

Name is **not** unique.

### 5.5 Products

| Local | Remote |
|---|---|
| `id` | `id` |
| `product_code` | `productcode` / `product_code` |
| `product_category`, `product_name`, `product_desc`, `product_status` | same-ish |
| `short_code`, `tax_code`, `hs_code` | same |
| `focus_product`, `tonne_equivalent`, `apply_discount`, `disable_price_check` | bools/numbers |
| `product_type`, `supplier`, `alternative_group` | same |

### 5.6 UOM / UOM quantities

- `bi_uom` → `uoms` (`uomname` → `uom_name`)
- `bi_uom_quantities` → packaging, quantity, bar_code, dimensions, tonne_equivalent, SFA timestamps

### 5.7 Routes

| Local | Remote |
|---|---|
| `sfa_route_id` | `route_id` |
| `region_id` | `region_id` (null if 0 / missing) |
| `route_name` | `route_name` |
| `route_status` | `routestatus` / `route_status` |

### 5.8 Reps (`bi_users`) — GT/MT

**Filter:** `user_channel IN ('1','2')`.

| Local | Remote |
|---|---|
| `id` | `id` (PK) |
| `user_reference`, `warehouse_code`, `user_type` | same |
| `user_channel` | `1` or `2` |
| `team` | **derived** `GT` / `MT` from channel |
| `region_id`, `territory_id`, `rolegroup_id` | soft-null if missing |
| `rep_category`, `name`, `email`, `phone_number`, `status` | same |
| `acumatica_employee_id`, `supervisor_id`, `asm_id` | **not from SFA** — phase 2 |
| `match_status`, `matched_at` | phase 2 |

### 5.9 Customers (`bi_customer_master`)

**Filter:** `USERID IN (valid GT/MT rep ids)` AND `STATUS = 1`.

| Local | Remote |
|---|---|
| `sfa_shop_id` | `SHOPID` |
| `customer_code` | `CUSTOMERCODE` |
| `customer_name` | `CUSTOMERNAME` |
| `region_id` | `region_id` |
| `supplied_by` | `USERID` |
| `channel` | `CHANNEL` |
| `is_active` | true for synced |
| `acumatica_customer_id`, `match_status`, `matched_at` | phase 2 |

**Never overwrite SFA identity fields during ERP match.**

### 5.10 Shop routes

Filter: `removed_on IS NULL`.  
Fields: `id`, `shop_id`, `route_id`, `shop_route_status`, `outlet_status`, `added_on`, `removed_on`.

### 5.11 User routes (PJP)

| Local | Remote |
|---|---|
| `id` | `id` |
| `route_id` | `route_id` |
| `user_id` | `userid` / `user_id` |
| `visit_frequency` | `visit_frequency` |
| `visit_week` | null / All / Week N / N |
| `visit_day` | e.g. `Monday` |
| `status` | `status` |

### 5.12 Customer visits

**Filter:** `DATE(CHECKINTIME) = :date` AND valid rep.

| Local | Remote |
|---|---|
| `sfa_visit_id` | `visitid` / `id` |
| `shop_id` | `SHOPID` |
| `user_id` / `user_name` | `USERID` / `USERNAME` |
| `checkin_time` / `checkout_time` | `CHECKINTIME` / `CHECKOUTTIME` |
| `time_spent` | `TIMESPENT` (minutes) |
| `outlet_status` | `OUTLETSTATUS` (`SOLD`, `ORDER`, `Ordered`, …) |
| region/route denorm | `REGIONNAME`, `ROUTENAME`, `ROUTEID` |

### 5.13 Start / end day (login)

**Filter:** `DATE(STARTDAYTIME) = :date`.

| Local | Remote |
|---|---|
| (`user_id`, `start_day_time`) | `USERID`, `STARTDAYTIME` |
| `user_name` | `USERNAME` |
| `day_status` | `DAYSTATUS` |

### 5.14 Sales entries

**Filter:** `DATE(ENTRY_TIME) = :date` AND valid rep.

| Local | Remote (minimum) |
|---|---|
| `entry_id` | `ENTRY_ID` |
| `sales_rep_id` | `USERID` |
| `sales_rep_name` | `SALES_REP` |
| `customer_id` | `SHOPID` |
| `customer_name` | `CUSTOMER_NAME` |
| `product_name` / `product_id` | product fields |
| `quantity` / `value_sold` | `QUANTITY` / `VALUE_SOLD` |
| `entry_time` | `ENTRY_TIME` |

Local unique: **(`entry_id`, `product_id`)** — not entry_id alone (multi-line entries).

### 5.15 Daily performances (computed)

Per `(rep_id, date)` — see §6–7 for formulas. Key outputs:

`sales_achieved`, `volume_achieved`, `customers_in_route`, `actual_visits`, `unique_visits`, `coverage`, `successful_visits`, `first_checkin`, `last_checkout`, `working_time`, conversion rates, `mapped_outlets`.

---

## 6. Planned PJP for the day

1. `dayName = weekday of D` (e.g. `Monday`)
2. `weekOfMonth = min(ceil(day_of_month / 7), 4)` → 1..4
3. `user_routes` where Active + route Active + `visit_day = dayName` + week rule match
4. Planned outlets = distinct `shop_id` in `shop_routes` for those routes
5. Coverage route preference = route of **first visit** of the day, else all PJP routes

```
route_adherence = clamp(visited_planned / planned * 100, 0, 100)   # target 90%
productivity    = (orders_collected / actual_visits) * 100         # NOT capped; target 60%
```

---

## 7. Late, login, absent, leave

| Flag | Condition |
|---|---|
| PRESENT | `actual_visits > 0` (or day-start recorded) |
| LATE | present AND time(`first_checkin`) **> 08:30** EAT |
| LATE_LOGIN | optional: `start_day_time` > 08:30 |
| ON_LEAVE | `leaves` covers name + date (approved range) |
| ABSENT | active rep, not present, not on leave |

- Login time UI: `start_end_days.start_day_time`
- First outlet time UI: `daily_performances.first_checkin` or `MIN(customer_visits.checkin_time)`
- Config: `SFA_LATE_THRESHOLD=08:30`
- Leave is **not** in Solutech; separate upload table

**Attendance API contract (implement in module):**

```json
{
  "date": "2026-08-07",
  "team": "GT",
  "reps": [{
    "rep_id": 123,
    "name": "Jane Doe",
    "region": "NAIROBI",
    "territory": "WEST",
    "login_time": "2026-08-07T07:55:00+03:00",
    "first_checkin": "2026-08-07T08:42:00+03:00",
    "is_late": true,
    "late_threshold": "08:30",
    "present": true,
    "on_leave": false,
    "attendance_status": "LATE"
  }]
}
```

`attendance_status`: `PRESENT | LATE | ABSENT | ON_LEAVE | UNKNOWN`.

---

## 8. Observability & ops

### 8.1 `sync_logs`

`batch_id`, `table_name`, `data_date`, `status` ∈ {queued, running, success, failed, partial, skipped}, row counts, `duration_seconds`, `message`, `error_details`, `started_at`, `completed_at`.

### 8.2 `sync_state`

One row per table: `is_enabled`, `last_sync_at`, `last_success_at`, `last_data_date`, `sync_mode`.  
Disable a table without deploy: `is_enabled = 0`.

### 8.3 Failures

- Log critical  
- Alert admins  
- Cancel: cache flag `sync.cancel.{batchId}` + prune queued jobs  

### 8.4 Exceptions API

- sales_rep_ids in sales with no rep  
- active reps without region  
- active reps with invalid channel  
- regions with no active reps  

---

## 9. Gap analysis vs product asks

| Requirement | Status | Module action |
|---|---|---|
| Customers from SFA | Done | Local `customers` |
| Match Acumatica customers | Phase 2 | `acumatica_customer_id` + matcher job |
| Group by region/territory | Done | `reps.region_id` / `territory_id` |
| Sales entries | Done (slim) | Expand columns if needed |
| Route adherence / productivity | Done | formulas in metrics service |
| Planned PJP | Done | `user_routes` + `shop_routes` |
| Late > 08:30 + login time | Facts ready | explicit attendance service |
| Absent ± leave | Leave upload | `leaves` + attendance |
| GT vs MT | `user_channel` 1/2 | also persist `team` |
| ASM/supervisor Acumatica | Phase 2 | `asm_id` / `supervisor_id` |

---

## 10. Module PRD — goals, APIs, phases

### 10.1 Goals

1. Reliable current-day SFA BI → module warehouse  
2. Near-real-time field visibility (10–15 min) 08:00–19:00 EAT  
3. Single source for GT/MT field KPIs  
4. Clean hooks for Acumatica match (phase 2)  
5. **Tight boundary** so host app stays clean  

### 10.2 Non-goals (v1)

- Writing back to Solutech  
- Nightly full history backfill (ops only)  
- Replacing Acumatica as ERP of record  
- Replacing host `users` / auth  

### 10.3 Functional requirements

| ID | Requirement | P |
|---|---|---|
| FR-01 | Read-only Solutech BI connection | P0 |
| FR-02 | Sync all tables §3 with maps §5 | P0 |
| FR-03 | GT/MT filter + `team` column | P0 |
| FR-04 | Date-scoped visits / day-start / sales / rollup | P0 |
| FR-05 | Staggered schedule §4 | P0 |
| FR-06 | Current-day guard + `allow_past` | P0 |
| FR-07 | Queue isolation | P0 |
| FR-08 | Idempotent upserts | P0 |
| FR-09 | sync_logs + progress + enable/disable | P0 |
| FR-10 | daily_performances compute | P0 |
| FR-11 | Planned PJP API | P0 |
| FR-12 | Late flag > 08:30 | P0 |
| FR-13 | Login time from start_end_day | P0 |
| FR-14 | Attendance present/leave/absent | P0 |
| FR-15 | Filters team / region / territory | P0 |
| FR-16 | Failure alerts | P1 |
| FR-17 | Missing-rep repair | P1 |
| FR-18–19 | Acumatica customer + hierarchy match | P2 |

### 10.4 Non-functional

| ID | Requirement |
|---|---|
| NFR-01 | TZ Africa/Nairobi |
| NFR-02 | Worker timeout ≥ 20 min per large table |
| NFR-03 | Per-table overlap lock |
| NFR-04 | Remote column probe or map |
| NFR-05 | Secrets in env only |
| NFR-06 | Idempotent re-runs |
| NFR-07 | Row counts + duration metrics |
| NFR-08 | Module tables do not require host FKs to auth users |

### 10.5 Sync algorithm (per table)

```
1. Acquire table lock (WithoutOverlapping)
2. SyncLog status=running
3. Open sfa_remote (fail → log + alert + exit)
4. valid_rep_ids = bi_users WHERE user_channel IN (1,2)
5. Pull remote (full or DATE filter)
6. For each row: soft-null FKs → upsert natural key
7. SyncState last_success
8. SyncLog success + counts + duration
9. If daily_performances → optional downstream hooks
```

### 10.6 Module API surface

```
POST   /api/sfa-sync/run              { table?, tables?, date?, async?, allow_past? }
GET    /api/sfa-sync/status
GET    /api/sfa-sync/logs
GET    /api/sfa-sync/progress/:batch
POST   /api/sfa-sync/cancel           { batch_id }
GET    /api/sfa-sync/exceptions
GET    /api/sfa-sync/metrics/daily    ?date&team=GT|MT&region&territory
GET    /api/sfa-sync/metrics/attendance ?date&team
GET    /api/sfa-sync/metrics/pjp      ?date&rep_id
```

(Host may keep `/api/data-sync/*` aliases.)

### 10.7 Phased delivery

| Phase | Deliverable |
|---|---|
| **P0** | Schema migrate, remote conn, 16 importers, cron, queue, logs, GT/MT |
| **P1** | daily_performances, PJP, attendance APIs, GT/MT dashboards |
| **P2** | Acumatica customer + ASM/supervisor matchers |
| **P3** | watermarks, column-map versioning, SLOs, DLQ |

### 10.8 Acceptance tests

1. `reps` sync only channels 1 and 2; `team` set correctly  
2. Re-run `sales_entries` same day → no duplicate lines  
3. High-frequency crons do not share identical minute+hour  
4. After 19:00 EAT no transactional jobs until 08:00  
5. Visits only for today without `allow_past`  
6. first_checkin 08:31 → LATE; 08:29 → not late  
7. Zero visits + no leave → ABSENT; with leave → ON_LEAVE  
8. Monday PJP uses `visit_day=Monday` + week rule  
9. Remote down → failed SyncLog + alert  
10. Cancel batch → queued tables skip  

### 10.9 Env checklist

See §0.3.

### 10.10 Implementation map (this repo → module)

| Concern | Source path |
|---|---|
| Portable schema + SQL | `php-backend/database/sfa-sync-portable/` |
| Seeders | `php-backend/database/seeders/Sfa*.php` |
| Schedule config | `php-backend/config/sfa_sync.php` |
| Scheduler | `php-backend/routes/console.php` |
| CLI | `php-backend/app/Console/Commands/SyncSfaData.php` |
| ETL (fat — split into Importers) | `php-backend/app/Http/Controllers/DataSyncController.php` |
| Queue job | `php-backend/app/Jobs/SyncSfaTableJob.php` |
| Models | `Rep`, `Customer`, `CustomerVisit`, `SalesEntry`, `DailyPerformance`, `StartEndDay`, `UserRoute`, `ShopRoute`, `Route`, `SyncLog`, `SyncState`, `Leave`, … |
| API routes | `php-backend/routes/api.php` (`/data-sync/*`) |
| Frontend KPI formulas | `src/lib/calculations.ts`, `routeAdherence.ts`, `productivity.ts` |
| Product notes | `SFA-data0sync.md`, `sync-settings.md` |

---

## 11. Operational runbook

**Daily automatic path**

1. 03:00–06:00 — masters  
2. 08:00 — customers/reps/products + transactional loop  
3. Every 10–15 min — visits, day-start, sales  
4. Every 30 min — daily_performances  
5. 19:00 — last customers; stop transactional  
6. Night idle  

**Backfill**

```bash
php artisan sfa:sync --date=2026-08-01 --days=3 --allow-past --queue
php artisan sfa:sync --table=sales_entries --date=2026-08-01 --allow-past
```

**Health**

- status endpoint `connection_status: connected`  
- compare remote vs local counts  
- latest `sync_logs` for failures  

---

## 12. Decision notes for a tight module

1. **Service + Importer classes**, not a 1.8k-line controller.  
2. **Channel filter in SQL**, not only UI.  
3. **Column map config** for BI drift.  
4. **SFA identity ≠ ERP identity** — mapping columns only.  
5. **Late threshold configurable.**  
6. **Monday vs Friday** comparison is reporting, not sync.  
7. Prefer **no FK constraints** from SFA tables to host `users` so module installs cleanly.  
8. Prefer **SFA ids as PKs** on `reps`, `regions`, `products`, etc., even if host uses UUIDs elsewhere.  

---

## 13. Database structures (full module schema)

### 13.1 Portable kit location

```
php-backend/database/sfa-sync-portable/
  README.md
  schema.sql
  migrations/2026_08_07_000001_create_all_sfa_sync_tables.php
  seeders/SfaSyncStateSeeder.php
  seeders/SfaReferenceDataSeeder.php
  seeders/SfaDemoDataSeeder.php
```

Live app also has:

- `database/migrations/2026_08_07_120000_add_sfa_team_and_acumatica_match_columns.php`
- `database/seeders/Sfa*.php`

### 13.2 Table catalog (final form)

#### `regions` — PK = SFA region_id

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | **Not** auto-increment in portable schema |
| name | VARCHAR | **not unique** |
| code | VARCHAR(50) NULL | |
| description | TEXT NULL | |
| is_active | BOOL default 1 | |
| timestamps | | |

#### `territories` — PK = SFA territory_id

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| name | VARCHAR | |
| code | VARCHAR(50) NULL | |
| region_id | BIGINT NULL | optional link |
| description | TEXT NULL | |
| is_active | BOOL | |
| timestamps | | |

#### `channels`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT AI PK | local |
| name | VARCHAR UNIQUE | from CHANNEL |
| code, description, is_active | | |
| timestamps | | |

#### `rolegroups` — PK = SFA rolegroup_id

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | |
| name | VARCHAR **not unique** | |
| code, description, is_active | | |
| timestamps | | |

#### `products` — PK = SFA product id

| Column | Type |
|---|---|
| id | BIGINT UNSIGNED PK |
| product_code | VARCHAR(100) NULL indexed |
| product_category, product_name, product_desc | |
| product_status | default Active |
| short_code, tax_code, hs_code | |
| focus_product | BOOL |
| tonne_equivalent | DECIMAL(12,6) |
| apply_discount, disable_price_check | BOOL |
| product_type, supplier, alternative_group | |
| timestamps | |

#### `uoms` — PK = SFA id

`id`, `uom_name`, `status`, timestamps

#### `uom_quantities` — PK = SFA id

`id`, `product_id`, `packaging_id`, `quantity`, `bar_code`, `length`, `width`, `height`, `weight`, `weight_unit`, `tonne_equivalent`, `custom_unit`, `status`, `sfa_created_at`, `sfa_deleted_at`, timestamps

#### `routes`

| Column | Type | Notes |
|---|---|---|
| id | BIGINT AI PK | local surrogate |
| sfa_route_id | BIGINT UNIQUE | Solutech route_id |
| region_id | BIGINT NULL | |
| route_name | VARCHAR | |
| route_status | VARCHAR default Active | |
| timestamps | | |

#### `reps` — PK = SFA user id (**core module entity**)

| Column | Type | Notes |
|---|---|---|
| id | BIGINT UNSIGNED PK | bi_users.id |
| user_reference | VARCHAR NULL indexed | |
| warehouse_code, user_type | | |
| user_channel | VARCHAR indexed | `1` / `2` |
| team | VARCHAR(10) indexed | `GT` / `MT` derived |
| region_id, territory_id, rolegroup_id | BIGINT NULL indexed | |
| rep_category, name, email, phone_number | | |
| last_time_active | | |
| trackactivities, demoaccount | BOOL | |
| billing | | |
| status | VARCHAR default Active indexed | |
| deactivation_reason | VARCHAR(500) NULL | |
| vehicle_id, added_by | | |
| **acumatica_employee_id** | VARCHAR NULL indexed | phase 2 |
| **supervisor_id**, **asm_id** | VARCHAR NULL | phase 2 |
| **match_status**, **matched_at** | | phase 2 |
| timestamps | | |

#### `customers` — SFA outlets (not host ERP customers)

| Column | Type | Notes |
|---|---|---|
| id | BIGINT AI PK | local |
| sfa_shop_id | INT UNIQUE | SHOPID |
| customer_code | VARCHAR indexed | |
| customer_name | VARCHAR | |
| shop_name, shop_code | | |
| region_id, territory_id, channel_id | NULL FKs soft | |
| group_id, supplied_by | supplied_by = rep id | |
| shop_cat_id, shop_subcat_id | | |
| account_region_name, location_id, location_name | | |
| customer_category, territory_name, channel | denorm strings | |
| relationship, supplier | | |
| phone_number, email_address, contact_person | | |
| verified, verified_by, verified_date | | |
| added_by, user_id, user_category | | |
| krapin, last_visit, last_sale | | |
| latitude, longitude | | |
| is_active | BOOL | |
| date_created | SFA created | |
| **acumatica_customer_id** | VARCHAR NULL indexed | phase 2 |
| **match_status**, **matched_at** | | phase 2 |
| timestamps | | |

#### `shop_routes`

| Column | Type |
|---|---|
| id | BIGINT PK (SFA) |
| shop_id, route_id | indexed |
| shop_route_status | default Active |
| outlet_status | NULL |
| added_on, removed_on | DATETIME NULL |
| timestamps | |

Index: `(route_id, shop_route_status)`

#### `user_routes` — PJP assignments

| Column | Type |
|---|---|
| id | BIGINT PK (SFA) |
| route_id | indexed |
| visit_frequency, visit_week, visit_day | |
| user_id | string SFA rep id indexed |
| status | default Active |
| timestamps | |

Index: `(user_id, visit_day, status)`

#### `customer_visits` — date-scoped

| Column | Type |
|---|---|
| id | BIGINT AI |
| sfa_visit_id | indexed |
| appointment_id | |
| shop_id | indexed |
| customer_code, customer_name, account | |
| user_id | indexed |
| user_name, user_category, region_name | |
| location_id, location_name | |
| route_name, route_id | |
| checkin_latitude, checkin_longitude | |
| checkin_time | indexed |
| checkout_time | |
| time_spent | minutes |
| outlet_status, route_type | |
| timestamps | |

Index: `(user_id, checkin_time)`

#### `start_end_days` — login / day start

| Column | Type |
|---|---|
| id | BIGINT AI |
| user_id | indexed |
| user_name, user_category, region_name | |
| start_day_time | indexed |
| start_battery, start_odometer | |
| start_latitude, start_longitude, gps_accuracy | |
| start_comment | |
| close_day_time, close_day_comment | |
| close_latitude, close_longitude | |
| end_odometer, end_battery | |
| day_status | |
| timestamps | |

Index: `(user_id, start_day_time)` — composite upsert key for sync

#### `sales_entries` — date-scoped fact

| Column | Type | Notes |
|---|---|---|
| id | BIGINT AI | |
| entry_id | VARCHAR NULL | with product_id unique |
| lpo_number, entry_type | | |
| sales_rep_id | indexed | |
| sales_rep_name | | |
| distributor_name, rep_category, rep_category_code, supervisor | | |
| customer_id | indexed | shop id |
| customer_code, customer_name, customer_region | | |
| verification, supplier, customer_category, customer_sub_category | | |
| location_name, territory_name, route_name, region_name | | |
| product_category, product_name, product_sku, product_code, product_id, brand_name | | |
| unit_price, quantity, value_sold | DECIMAL | |
| entry_time | DATETIME indexed | |
| latitude, longitude | | |
| is_kimfay_product | BOOL | |
| voided_at, voided_by, void_reason | void soft-hide | |
| timestamps | | |

Indexes: `entry_time`, `(sales_rep_id, entry_time)`, unique `(entry_id, product_id)`

#### `daily_performances` — computed rollup

| Column | Type |
|---|---|
| id | BIGINT AI |
| rep_id + date | UNIQUE |
| rep_name, region | |
| sales_target, sales_achieved, sales_performance | |
| volume_target, volume_achieved | |
| customers_in_route, target_visits, actual_visits, unique_visits, coverage | |
| successful_visits, unique_successful_visits | |
| conversion_rate_successful, conversion_rate_unique_successful | |
| mapping_target, mapped_outlets, mapping_performance | |
| target_hours, working_time, time_spent_performance | |
| first_checkin, last_checkin, last_checkout | strings/datetimes |
| time_spent_per_outlet, off_route_requests | |
| timestamps | |

#### `leaves` — HR (not Solutech)

| Column | Type |
|---|---|
| id | BIGINT AI |
| leave_id | external id |
| user_name | match to rep **by name** |
| category | |
| request_start/end, requested_days, request_notes | |
| entry_time | |
| approved_start/end, approved_notes | |
| status, approved_by, approval_date | |
| timestamps | |

Indexes: `user_name`, `(approved_start, approved_end)`

#### `sync_logs`

| Column | Type |
|---|---|
| id | BIGINT AI |
| batch_id | VARCHAR(50) indexed |
| table_name | indexed |
| data_date | DATE NULL |
| status | queued\|running\|success\|failed\|partial\|skipped |
| rows_processed/inserted/updated/failed | INT |
| duration_seconds | DECIMAL |
| message, error_details | TEXT |
| started_at, completed_at | |
| timestamps | |

#### `sync_state`

| Column | Type |
|---|---|
| id | BIGINT AI |
| table_name | UNIQUE |
| last_sync_at, last_success_at | |
| last_data_date | DATE NULL |
| sync_mode | full\|incremental |
| is_enabled | BOOL default 1 |
| timestamps | |

### 13.3 Required indexes (performance)

```
customer_visits (user_id, checkin_time)
sales_entries (entry_time)
sales_entries (sales_rep_id, entry_time)
sales_entries UNIQUE (entry_id, product_id)
daily_performances UNIQUE (rep_id, date)
reps (user_channel), (team), (region_id), (territory_id), (status)
customers (sfa_shop_id), (customer_code), (supplied_by)
user_routes (user_id, visit_day, status)
shop_routes (route_id, shop_route_status)
```

### 13.4 ER relationships (logical)

```
regions 1──* routes
regions 1──* reps
territories 1──* reps
rolegroups 1──* reps
reps 1──* customers (supplied_by)
routes 1──* shop_routes  *──1 customers (shop_id ≈ sfa_shop_id)
routes 1──* user_routes *──1 reps (user_id)
reps 1──* customer_visits
reps 1──* start_end_days
reps 1──* sales_entries
reps 1──* daily_performances
products 1──* uom_quantities
```

Hard DB FKs are optional; production sync often soft-nulls missing parents. Portable SQL omits rigid FKs for easier host install.

### 13.5 Install commands

```bash
# MySQL
mysql -u USER -p DB < php-backend/database/sfa-sync-portable/schema.sql

# Laravel module
php artisan migrate
php artisan db:seed --class=SfaSyncStateSeeder
php artisan db:seed --class=SfaDemoDataSeeder   # optional
```

---

## 14. Seeders (module bootstrap)

### 14.1 `SfaSyncStateSeeder` — **always run in production**

Creates `sync_state` rows for all 16 sync tables, `is_enabled=true`, `sync_mode=full`.

Tables list (order):

```
regions, territories, channels, rolegroups, products, uoms, uom_quantities,
routes, reps, customers, shop_routes, user_routes,
customer_visits, start_end_days, sales_entries, daily_performances
```

```bash
php artisan db:seed --class=SfaSyncStateSeeder
```

Safe to re-run (`firstOrCreate`).

### 14.2 `SfaReferenceDataSeeder` — local/dev only

When remote SFA is offline:

- 5 regions (NAIROBI, COAST, WESTERN, RIFT VALLEY, MOUNT KENYA) with fixed ids  
- 6 territories linked to regions  
- 4 channels (General Trade, Modern Trade, Wholesale, Key Account)  
- 4 rolegroups (Sales Rep, Merchandiser, Supervisor, Van Sales)  

### 14.3 `SfaDemoDataSeeder` — offline integration tests

Depends on reference + sync state. Seeds a **full demo day** (today EAT):

| Rep | Team | Scenario | Use in tests |
|---|---|---|---|
| Jane Wanjiku (1001) | GT | Present, on-time first check-in 08:10 | PRESENT |
| Peter Otieno (1002) | GT | Present, first check-in 09:05 | **LATE** |
| Amina Hassan (2001) | MT | Present MT | MT filter |
| David Mwangi (2002) | MT | No visits, no leave | **ABSENT** |
| Grace Achieng (1003) | GT | Leave approved today | **ON_LEAVE** |

Also seeds: products, routes, customers, shop_routes, user_routes (PJP for today), start_end_days, visits, sales_entries, daily_performances, one leave row.

```bash
php artisan db:seed --class=SfaDemoDataSeeder
# or
SEED_SFA_DEMO=true php artisan db:seed
```

### 14.4 Host `DatabaseSeeder` integration

```php
$this->call(SfaSyncStateSeeder::class);          // always
if (env('SEED_SFA_DEMO', false)) {
    $this->call(SfaDemoDataSeeder::class);       // never on prod by default
}
```

---

## 15. Module service contracts (implement these interfaces)

Keep the host app dependent only on these contracts — not on Solutech SQL.

```text
SfaSyncOrchestrator
  run(table|null, date, async, allowPast): BatchResult
  cancel(batchId): void
  status(): ConnectionAndCounts
  progress(batchId): BatchProgress

TableImporter (one per table)
  name(): string
  sync(date, useLocal): TableResult

AttendanceService
  forDate(date, team?, region?): AttendanceRow[]

PjpService
  plannedFor(date, repId): PlannedRoute[]
  adherence(date, repId): number

DailyMetricsService
  rollup(date, filters): DailyPerformance[]
```

Importer registry order **must** match §3.

---

## 16. Config shape (`sfa_sync.php`)

```php
return [
    'timezone' => env('SFA_SYNC_TIMEZONE', 'Africa/Nairobi'),
    'queue'    => env('SFA_SYNC_QUEUE', 'sfa-sync'),
    'late_threshold' => env('SFA_LATE_THRESHOLD', '08:30'),
    'channels' => ['1', '2'], // GT, MT
    'tables' => [
        // scheduleKey => ['cron' => '...', 'label' => '...', 'table' => optional override]
        'channels'       => ['cron' => '0 3 * * *',  'label' => '03:00 daily'],
        'territories'    => ['cron' => '15 3 * * *', 'label' => '03:15 daily'],
        // ... full map from config/sfa_sync.php in this repo
        'sales_entries'  => ['cron' => '1,16,31,46 8-18 * * *', 'label' => 'every 15 min, 08–19'],
        // ...
    ],
];
```

Port the complete table from `php-backend/config/sfa_sync.php` verbatim for production parity.

---

## 17. Suggested build checklist (other existing system)

- [ ] Create module folder / package boundary  
- [ ] Add `sfa_remote` DB connection (read-only)  
- [ ] Run portable migration or `schema.sql`  
- [ ] Seed `SfaSyncStateSeeder`  
- [ ] Port 16 importers with field maps §5  
- [ ] Port queue job + per-table lock  
- [ ] Register staggered crons §4  
- [ ] Expose status / run / progress / cancel APIs  
- [ ] Implement AttendanceService + late threshold  
- [ ] Implement PJP + coverage metrics  
- [ ] Wire GT/MT filters on all metric queries  
- [ ] Optional: `SfaDemoDataSeeder` for CI without remote  
- [ ] Phase 2: Acumatica match jobs using match columns  
- [ ] Run acceptance tests §10.8  

---

## 18. Summary

You are embedding a **read-only ETL module** from Solutech BI into a host system:

| Pillar | Rule |
|---|---|
| Scope | 16 sync tables + leaves + sync control |
| Identity | SFA natural keys; separate from host/ERP |
| Teams | `user_channel` 1=GT, 2=MT → `team` |
| Time | Current day; 08:00–19:00 transactional; EAT |
| Schedule | Staggered; queue `sfa-sync` |
| KPIs | Coverage, productivity, late, PJP, attendance |
| Phase 2 | Acumatica customer + ASM/supervisor columns already on schema |
| Assets | `php-backend/database/sfa-sync-portable/` + this PRD |

**Start here for schema & seeders:** `php-backend/database/sfa-sync-portable/README.md`  
**Start here for ETL behaviour:** `DataSyncController` + `config/sfa_sync.php`  
**Start here for module boundaries:** §0 and §15 of this document.

