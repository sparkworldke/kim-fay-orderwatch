# OrderWatch — Implementation & Production Guide (July 2026)

This document covers the features, fixes, and production steps delivered in the **July 2026** workstream (dashboard zone/routes, customer assignment, auth errors, daily email idempotency, factories/seeders).

| | |
|--|--|
| **Frontend** | `https://orderwatch.fayshop.co.ke` (Cloudflare Worker `orderwatchkimfay`) |
| **API** | `https://api.orderwatch.fayshop.co.ke/api` (VPS Laravel) |
| **Branch** | `master` |
| **Commits** | `64a7e4c` → `8e7c8ce` → `b0d2fee` → `cc17f12` (and earlier team/rep-code work) |

---

## 1. Feature summary

| # | Area | What shipped |
|---|------|----------------|
| 1 | **Dashboard — Zone Names & Routes** | Accordion tab with per-zone routes and order counts by status (Open, Pending Approval, In Shipment, Completed, etc.) |
| 2 | **Customer assignment upload** | Excel-driven rep codes (any non-empty code); resolve to one active user/mapping; dry-run → apply |
| 3 | **Missing rep-code lists** | Gap CSVs for `Customers 20260713.xlsx` vs active OrderWatch users |
| 4 | **Auth error UX** | Toast + inline alert for invalid credentials / inactive account |
| 5 | **CustomerSeeder (VPS-safe)** | Factory-based seeding — no Excel required on the server |
| 6 | **Daily management email** | Idempotent send (one email per report date); no double cron registration |
| 7 | **Dashboard TS fixes** | `app.index.tsx` type-safe DateLink, zone metrics, orders navigation |

---

## 2. Dashboard: Zone Names & Routes

### Behaviour
- New dashboard tab: **Zone Names & Routes**
- Expands by **shipping zone**; each zone lists **routes** with counts:
  - Open · Pending Approval · **In Shipment** · Completed · Rejected · On Hold · Back Order · **Total**
- Date range uses the same dashboard date filters as Sales Orders
- Excludes special customers (same GLT rules as main KPIs where applied on backend)

### API
```http
GET /api/dashboard/zone-routes?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD
Authorization: Bearer {token}
```

**Response (shape):**
```json
{
  "date_from": "2026-07-01",
  "date_to": "2026-07-14",
  "total": 1234,
  "zones": [
    {
      "shipping_zone_id": "Z001",
      "name": "Westlands",
      "description": "Nairobi",
      "region": "Nairobi",
      "total": 100,
      "routes": [
        {
          "route_code": "1A",
          "route_name": "Kikuyu Town",
          "customer_zone": "Westlands",
          "total": 12,
          "open": 2,
          "pending_approval": 1,
          "shipping": 3,
          "completed": 5,
          "rejected": 0,
          "on_hold": 1,
          "back_order": 0
        }
      ]
    }
  ]
}
```

### Key files
| Layer | Path |
|-------|------|
| Backend | `backend/app/Http/Controllers/Api/DashboardController.php` → `zoneRoutes()` |
| Route | `backend/routes/api.php` → `GET dashboard/zone-routes` |
| Frontend | `src/routes/app.index.tsx` — types, `useZoneRoutes`, `ZoneRoutesPanel`, tab wiring |

### Production notes
- Counts join **sales orders → `acumatica_customers.route_code` / `shipping_zone_id`**
- Zones with **no routes** are omitted; routes with zero orders still appear if the route master exists under that zone
- Deploy **backend + frontend** together so the tab does not 404 the API

---

## 3. Customer assignment upload (Excel)

### Design
- Use the **admin reusable upload flow** (not a one-off seeder) for files like `Customers 20260713.xlsx`
- Excel is the authority for **rep-code format** (any non-empty value after `UPPER(TRIM(...))`)
- Validation fails a row only when the rep code cannot be matched to **exactly one active** user:
  1. `users.rep_code` (active)
  2. else `user_acumatica_rep_mappings.acumatica_rep_code` (active user)
- Customer ID required, normalized upper/trim, must exist in `acumatica_customers.acumatica_id`
- Valid rows upsert `user_customer_assignments` with `source=upload`, `source_batch_id`, `assigned_by`

### Workflow
1. **Upload** → creates `customer_assignment_batches` dry-run (no assignment writes)
2. Review valid / error rows in UI
3. **Apply** → writes only valid rows (idempotent `updateOrCreate`)

### UI copy
> Rep codes are read from the Excel file and must match one active user; customer IDs must exist in the Acumatica master.

### Key files
| Path | Role |
|------|------|
| `backend/app/Services/Team/CustomerAssignmentService.php` | Validation, resolve, apply |
| `src/components/admin/CustomerAssignmentFields.tsx` | Upload UI |
| `backend/tests/Feature/TeamManagementTest.php` | Upload + idempotency tests |

### Gap analysis artifacts (Excel 2026-07-13)
| File | Purpose |
|------|---------|
| `docs/data/customer-assignment-rep-code-gaps-20260713.csv` | Unresolved / placeholder / empty rep codes |
| `docs/data/customer-assignment-rep-code-match-summary-20260713.csv` | All Excel rep codes + resolve status |
| `docs/data/orderwatch-active-rep-codes-20260713.csv` | Active OW users with rep codes |

**Match snapshot (local DB at analysis time):**
- ~2,545 Excel rows resolved to active users  
- ~45 distinct resolved codes (`P505`, `YVON`, `JUM`, `C967`, …)  
- Problem codes needing users/mappings (examples): `P038`, `P039`, `P086`, `P271`, `P274`, `LITIGATION`, `INACTIVE`, `BADDEBTPRO`, plus empty rep rows  

**Helper scripts (optional):**
```bash
cd backend
# Live DB (Laravel)
php scripts/match_customer_rep_codes.php

# Or offline after dumping users/mappings TSV into changes/
php scripts/match_customer_rep_codes_offline.php
```

### Production: assign customers
1. Provision missing rep codes on active users (or mappings)
2. Admin → Team / assignment upload → upload Excel → review errors → **Apply valid rows**
3. Re-upload is safe (idempotent)

---

## 4. Auth: credential / inactive errors

### Backend (`AuthController@login`)
| Condition | HTTP | Message |
|-----------|------|---------|
| Wrong email/password | `422` | `Invalid credentials. Please check your email and password.` |
| User exists but inactive | `403` | `Your account is not active. Please contact an administrator.` |

### Frontend
- `src/lib/api.ts` — `getErrorMessage()`, better Laravel `errors` bag extraction  
- `src/components/ui/sonner.tsx` — richColors not overridden by background classes; longer duration  
- `src/routes/auth.tsx` — **toast + inline red banner** for login/OTP failures  

### Tests
```bash
cd backend
php artisan test --filter=AuthLoginTest
```

### Production
- Deploy **backend** (message body) + **frontend** (toaster/banner)
- Smoke: wrong password → toast + banner; inactive user → inactive message

---

## 5. Seeders & factories (VPS-safe)

### Principle
Anything that used **Excel/CSV on the server** for bulk demo data is factory/static-seed based. Production customer↔rep mapping uses the **upload API**, not seeders.

| Seeder | Data source | VPS-safe |
|--------|-------------|----------|
| `ShippingZoneSeeder` | Hardcoded zone list | Yes |
| `RouteSeeder` | Hardcoded routes + zone links | Yes |
| `CustomerSeeder` | **Eloquent factories** (no Excel) | Yes |
| `UserRepCodeSeeder` | Hardcoded rep map by user id (skips missing IDs) | Yes (skips FK crashes) |
| Customer Excel assignment | **Admin upload**, not seeder | Yes |

### Factory files
- `backend/database/factories/AcumaticaCustomerFactory.php` (`withRoute()`)
- `backend/database/factories/CustomerDataFactory.php` (`forCustomer()`)

### VPS seed (dev/staging only — careful on production)
```bash
cd /var/www/orderwatch/backend
php artisan db:seed --class=ShippingZoneSeeder
php artisan db:seed --class=RouteSeeder
# Only if you intentionally want synthetic customers:
php artisan db:seed --class=CustomerSeeder
```

Do **not** rely on `Customers 20260713.xlsx` existing on the VPS for seeding.

---

## 6. Daily management email (cron) — no multiple sends

### Problem fixed
- Fixed command always called the runner with **`force=true`**, so “already sent” was weak under concurrency  
- Possible **double schedule registration** (hard-coded schedule + `cron_jobs` row / legacy rows)  
- Result: multiple emails for the same report date  

### Correct behaviour
| Item | Value |
|------|--------|
| Command | `php artisan orderwatch:send-daily-report-fixed` |
| Schedule | Tue–Sat **07:00** `Africa/Nairobi` (`0 7 * * 2-6`) |
| Report date | **Previous calendar day** |
| Idempotency | One successful send per `(config, report_date)` unless `--force` |
| Lock | Cache lock + re-check under lock |
| Schedule owner | **Only** `backend/routes/console.php` (DB row is visibility; not double-scheduled) |

### Commands
```bash
# Normal / scheduled (skips if already sent)
php artisan orderwatch:send-daily-report-fixed --source=scheduler
php artisan orderwatch:send-daily-report-fixed --source=manual

# Intentional resend only
php artisan orderwatch:send-daily-report-fixed --source=manual --force

# Diagnose
php scripts/diagnose_daily_report.php
```

### Key files
| Path | Role |
|------|------|
| `backend/app/Console/Commands/SendDailyManagementReportFixed.php` | CLI entry |
| `backend/app/Services/Reports/DailyReportRunnerService.php` | Lock + already-sent |
| `backend/app/Services/Reports/DailyReportMailerService.php` | Single message; unique To/CC |
| `backend/routes/console.php` | Single schedule registration |
| `backend/app/Models/CronJob.php` → `fixedDailyReport()` | Admin row; pauses legacy duplicates |

### Production checklist (cron)
```bash
# System crontab: ONLY schedule:run (do not also call send-daily-report-fixed directly)
crontab -l | grep -E 'schedule:run|daily-report'

# Should list ONE daily-report event
php artisan schedule:list | grep daily-report

# Align cron_jobs defaults / pause legacy
php artisan tinker --execute="\\App\\Models\\CronJob::ensureDefaults(); echo 'ok';"

php artisan test --filter=DailyReportFixed
```

---

## 7. Production deploy

### Frontend (Cloudflare)
From monorepo root on a machine with Wrangler auth:

```bash
# Ensure VITE_API_BASE_URL points at production API, e.g.:
# VITE_API_BASE_URL=https://api.orderwatch.fayshop.co.ke/api

npm run build
npx wrangler deploy
```

Worker: `orderwatchkimfay` · Domain: `orderwatch.fayshop.co.ke`

### Backend (VPS)
```bash
# Git (example paths — adjust to your server layout)
sudo -u orderwatch -i
cd /var/www/orderwatch/repo   # monorepo clone
git pull origin master

# If backend is a separate tree, rsync/copy backend/ then:
cd /var/www/orderwatch/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache   # if used
sudo systemctl reload php8.3-fpm
```

**Never overwrite production `.env`.**

### Post-deploy smoke tests
| Check | How |
|-------|-----|
| Login errors | Wrong password → toast + banner; inactive → contact admin |
| Zone routes | Dashboard → **Zone Names & Routes** tab loads counts |
| API | `GET /api/dashboard/zone-routes?date_from=…&date_to=…` with token |
| Daily email schedule | `schedule:list` shows one fixed daily report line |
| Daily email idempotency | Run command twice same day → second skips |
| Customer upload | Dry-run Excel → errors on unknown rep; apply only valids |

### Related env (daily email / links)
| Variable | Purpose |
|----------|---------|
| `MAIL_*` | SMTP for daily report |
| `CRON_TIMEZONE` / app timezone | Prefer `Africa/Nairobi` for schedule |
| `FRONTEND_URL` | Links inside emails → app UI |

---

## 8. API quick reference (new / critical)

| Method | Path | Notes |
|--------|------|--------|
| `GET` | `/api/dashboard/zone-routes` | Zone accordion data |
| `POST` | `/api/auth/login` | Structured invalid/inactive messages |
| `POST` | `/api/admin/customer-assignments/upload` | Dry-run batch |
| `POST` | `/api/admin/customer-assignments/batches/{id}/apply` | Apply valid rows |
| `GET` | `/api/admin/daily-reports/config` | Daily email config |
| `POST` | `/api/admin/daily-reports/test-send` | Test send |
| `POST` | `/api/admin/daily-reports/resend-last` | Resend last payload |

---

## 9. Automated tests to run before/after deploy

```bash
cd backend

php artisan test --filter=AuthLoginTest
php artisan test --filter=TeamManagementTest
php artisan test --filter=DailyReportFixedCommandTest
php artisan test --filter=DailyReportFixedScheduleTest
php artisan test --filter=DailyManagementReportTest
```

---

## 10. Commit map (this workstream)

| Commit | Summary |
|--------|---------|
| `64a7e4c` | Zone-routes API + UI; factory CustomerSeeder; rep-code gap CSVs; auth inactive login API |
| `8e7c8ce` | Auth toasts/inline errors; zone label polish; AuthLoginTest |
| `b0d2fee` | Daily report double-send fix (lock, force, single schedule) |
| `cc17f12` | `app.index.tsx` TypeScript corrections |

---

## 11. Operational do’s and don’ts

**Do**
- Keep a single system cron: `* * * * * php artisan schedule:run`
- Provision missing rep codes before large customer assignment applies
- Use `--force` only when you intentionally want a second daily email for the same report date
- Deploy frontend and backend when changing dashboard tabs that need new API routes

**Don’t**
- Force-push / rebase published `master` (Lovable history)
- Run Excel-dependent seeders on VPS for customer assignment
- Register a second crontab line that calls `send-daily-report-fixed` directly
- Treat placeholder Excel codes (`LITIGATION`, `INACTIVE`, …) as special — they only work if an active user/mapping exists

---

## 12. Related docs

| Doc | Topic |
|-----|--------|
| [`BACKEND-DEPLOY-UPDATE.md`](./BACKEND-DEPLOY-UPDATE.md) | VPS update release checklist |
| [`DEPLOY-VPS.md`](./DEPLOY-VPS.md) | First-time VPS install |
| [`cron-jobs-guide.md`](./cron-jobs-guide.md) | Full cron inventory |
| [`daily-email-executive.md`](./daily-email-executive.md) | Executive email payload sections |
| [`team-management.md`](./team-management.md) | Team / roles (if present) |

---

*Last updated: 2026-07-14 · OrderWatch / Kim-Fay*
