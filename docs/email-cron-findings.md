# OrderWatch — Cron & Daily Email Findings

**Server:** `dating.sparkworld.co.ke`  
**Backend path:** `/home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend`  
**Date of review:** July 2026

---

## Executive summary

| Area | Status | Action needed |
|------|--------|---------------|
| System cron (`schedule:run`) | **Broken / intermittent** since Sun 5 Jul | Change to **every minute** (`* * * * *`) |
| Daily report scheduler | **Working** when cron fires at 07:00 | No code change required |
| SMTP (fayshop) | **Tested OK** | Save in Admin or `.env`, then `config:clear` |
| Tue 7 Jul 07:00 send | **Skipped** (`already_sent`) | Normal — use `--force` to resend |
| `hourly-auto-match` | **Blocks scheduler 2–10h** | Consider disabling in Admin |

---

## 1. How cron works in OrderWatch

OrderWatch does **not** register one crontab line per job. You need **one** system task that runs every minute:

```bash
* * * * * /usr/bin/php8.3 /home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend/artisan schedule:run >> /home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend/cron.log 2>&1
```

Optional heartbeat (for monitoring):

```bash
echo "Ran at $(date)" >> /home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend/cron-heartbeat.log
```

Laravel’s scheduler (`backend/routes/console.php`) then decides which `orderwatch:*` commands are due.

Verify on server:

```bash
cd /home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend
php artisan schedule:list | grep daily-report
php artisan schedule:list
```

---

## 2. Daily executive email

### Command (use this one)

```bash
php artisan orderwatch:send-daily-report-fixed --source=scheduler
```

**Do not** rely on `orderwatch:send-daily-report` — it is the legacy command and is not on the fixed Tue–Sat schedule.

### Schedule

| Setting | Value |
|---------|--------|
| Cron expression | `0 7 * * 2-6` |
| Timezone | `Africa/Nairobi` (`CRON_TIMEZONE` in `.env`) |
| Runs | **Tuesday–Saturday at 07:00** |
| Report date | **Yesterday** (calendar day before send time) |
| Skips | Sunday and Monday mornings |

### Manual send / resend

```bash
php artisan orderwatch:send-daily-report-fixed --source=manual --force
```

`--force` bypasses the “already sent for this date” check.

### Admin requirements

1. **Administration → Daily Report** — enabled, **Send to** and/or **CC** recipients set  
2. **Administration → Cron Jobs** — “Daily Report Fixed Scheduler” enabled (informational; job is also hardcoded in `routes/console.php`)  
3. Mail transport configured (see §4)

### Diagnostic script (on server)

```bash
php scripts/diagnose_daily_report.php
```

---

## 3. Cron log findings

### 3.1 Heartbeat — cron not running every minute

From `cron-heartbeat.log`:

- **Until Sun 5 Jul ~22:11** — ran roughly **every hour** (CEST server time)
- **Sun 5 Jul 22:11 → Tue 7 Jul 06:55** — **sporadic** (only ~15 runs on Mon 6 Jul; should be ~1,440/day if every minute)
- **Risk:** if `schedule:run` is not called at exactly **07:00 Nairobi**, the daily email window is missed

**Fix:** ensure hosting cron is `* * * * *` (every minute), not hourly.

### 3.2 Daily report runs in `cron-job-logs.md`

`orderwatch:send-daily-report-fixed` **did** run on:

| Date (server log) | Result |
|-------------------|--------|
| Tue 1 Jul 07:00 | DONE (~7 ms) |
| Wed 2 Jul 07:00 | DONE (~12 ms) |
| Thu 3 Jul 07:00 | DONE (~7 ms) |
| Fri 4 Jul 07:00 | DONE (~9 ms) |
| Sat 5 Jul 07:00 | Not seen (cron gap) |
| Sun 6 Jul | Not scheduled (Sunday) |
| Mon 7 Jul | Not scheduled (Monday) |
| Tue 8 Jul 07:00 | DONE (~796 ms) |

### 3.3 Tue 7 Jul 07:00 — skipped, not failed

From `error-log.md` / `laravel.log`:

```
[2026-07-07 07:00:05] daily_report_fixed_skipped
  reason: already_sent
  report_date: 2026-07-06
  timezone: Africa/Nairobi
```

The scheduler fired correctly. No second email was sent because **6 Jul had already been sent** (manual/test run earlier). This is expected behaviour without `--force`.

### 3.4 `hourly-auto-match` blocking the scheduler

`cron-job-logs.md` shows `orderwatch:hourly-auto-match` holding the scheduler for **2–10 hours** per run, delaying other jobs.

**Recommendation:** disable **Email ↔ Sales Order Auto Match** in **Administration → Cron Jobs** if you use the individual sync jobs (`email-sync`, `sales-orders-sync`, etc.) instead.

### 3.5 Other log notes

- **`APP_ENV=local`** in production logs — set `APP_ENV=production` on the server  
- **Microsoft Graph 429** — Outlook rate limits during email sync (separate from daily report)  
- **07:54 admin run error** — `send-daily-report-fixed` command not found (stale/partial deploy); redeploy latest backend

---

## 4. SMTP settings (tested & working)

Tested successfully from dev against `mail.fayshop.co.ke` (test sent to `customercare@kimfay.com`).

| Setting | Value |
|---------|--------|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `mail.fayshop.co.ke` |
| `MAIL_PORT` | `465` |
| `MAIL_SCHEME` | `smtps` (Laravel 11 — maps from `ssl` / `MAIL_ENCRYPTION=ssl`) |
| `MAIL_USERNAME` | `do-not-reply@fayshop.co.ke` |
| `MAIL_PASSWORD` | *(set on server — keep quoted in `.env` because of `$` and `^`)* |
| `MAIL_FROM_ADDRESS` | `hello@fayshop.co.ke` |
| `MAIL_FROM_NAME` | `Kim-Fay OrderWatch` |

### Production `.env` example

```env
MAIL_MAILER=smtp
MAIL_HOST=mail.fayshop.co.ke
MAIL_PORT=465
MAIL_SCHEME=smtps
MAIL_USERNAME=do-not-reply@fayshop.co.ke
MAIL_PASSWORD="your-password-here"
MAIL_FROM_ADDRESS=hello@fayshop.co.ke
MAIL_FROM_NAME="Kim-Fay OrderWatch"
```

After any `.env` change:

```bash
php artisan config:clear
php artisan cache:clear
```

### Administration UI (preferred after deploy)

**Administration → Health strip → Mail delivery → SMTP**

- Host: `mail.fayshop.co.ke`  
- Port: `465`  
- Encryption: **SSL** (stored as `smtps` internally)  
- Username: `do-not-reply@fayshop.co.ke`  
- Password: *(paste once — stored encrypted)*  
- From address: `hello@fayshop.co.ke`  
- From name: `Kim-Fay OrderWatch`  

Click **Save SMTP settings**.

### SMTP test script (on server)

```bash
php scripts/test_smtp.php your-email@kimfay.com
```

---

## 5. Email report content (current design)

The executive daily email has **5 sections**:

1. **Order Exceptions** — yesterday + week table; prior-month carryover (all incomplete June SOs)  
2. **Fill Rate** — yesterday only  
3. **Backorders** — exposure %, revenue at risk, top Acumatica reasons (no SKUs / outlets)  
4. **Nairobi & Mombasa 24hr SLA** — yesterday only  
5. **Revenue Split** — KP / CS / total  

Payload builder: `DailyExecutiveReportService`  
Mail template: `DailyManagementReportMail`

---

## 6. Production checklist

```bash
# 1. Deploy latest backend + frontend
# 2. Migrate
php artisan migrate --force

# 3. Clear caches after .env / Admin mail save
php artisan config:clear
php artisan cache:clear

# 4. Diagnose
php scripts/diagnose_daily_report.php

# 5. Test SMTP
php scripts/test_smtp.php customercare@kimfay.com

# 6. Force send daily report
php artisan orderwatch:send-daily-report-fixed --source=manual --force

# 7. Confirm schedule
php artisan schedule:list
```

### Hosting cron (required)

```cron
* * * * * /usr/bin/php8.3 /home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend/artisan schedule:run >> /home/pesatrendske/web/dating.sparkworld.co.ke/public_html/backend/cron.log 2>&1
```

### Environment

```env
APP_ENV=production
CRON_TIMEZONE=Africa/Nairobi
```

---

## 7. Useful log locations

| File | Purpose |
|------|---------|
| `backend/cron.log` | Output of `schedule:run` |
| `backend/cron-heartbeat.log` | Timestamp proof cron fired |
| `backend/storage/logs/laravel.log` | `daily_report_fixed_*`, mail errors, sync errors |

### Grep examples

```bash
grep daily_report storage/logs/laravel.log | tail -20
grep send-daily-report cron.log | tail -20
tail -20 cron-heartbeat.log
```

---

## 8. Open items (optional changes)

| Item | Current | Possible change |
|------|---------|-----------------|
| Send time | 07:00 hardcoded | Align to 08:00 to match Admin “Send time” |
| Send days | Tue–Sat | Mon–Sat if Monday report needed |
| From address | `hello@fayshop.co.ke` | `do-not-reply@fayshop.co.ke` if deliverability issues (From ≠ SMTP user) |

---

## 9. Related files in repo

| File | Role |
|------|------|
| `backend/routes/console.php` | Scheduler registration |
| `backend/app/Console/Commands/SendDailyManagementReportFixed.php` | Daily send command |
| `backend/app/Services/Admin/MailSettingsService.php` | Admin SMTP persistence |
| `backend/scripts/diagnose_daily_report.php` | Server diagnostics |
| `backend/scripts/test_smtp.php` | SMTP send test |
| `cron-jobs-guide.md` | Full cron reference |


Develop a downloadable Excel spreadsheet that comprehensively displays lost sales attributed to fill rate issues, including detailed supporting analysis and structured organization as specified below:

1.  **Core Data Structure & Organization**:
    - Categorize all lost sales records by individual SKU, with each SKU allocated a dedicated section within the spreadsheet to maintain clear separation between product lines
    - For each SKU, list every recorded lost sales incident with corresponding core attributes including transaction date, order quantity, unit price, and total lost sales value

2.  **Required Analytical Fields**:
    - Include a dedicated column to document the specific root cause of each fill rate failure (e.g., inventory stockout, supplier delay, logistics bottleneck, production shortage, quality control hold)
    - Calculate and display subtotals of lost sales amounts for each individual SKU
    - Generate a grand total summary of all lost sales across all SKUs, positioned in a prominent summary section at the top of the spreadsheet for quick visibility

3.  **Functionality & Usability Requirements**:
    - Implement spreadsheet features including filterable columns, conditional formatting to highlight high-value lost sales incidents, and embedded pivot tables that enable users to slice data by SKU, reason, and date range
    - Ensure the Excel file is compatible with all modern Excel versions (2016 and later) as well as compatible with common spreadsheet viewers to guarantee universal accessibility
    - Add a dedicated summary tab that aggregates key metrics: total overall lost sales, top 5 SKUs with the highest lost sales values, and frequency distribution of fill rate issue root causes
    - Include clear column headers, descriptive sheet names, and a brief instructions tab to guide users on how to use the spreadsheet's analytical features

4.  **Downloadable Implementation**:
    - Configure the file to be directly downloadable with a standardized naming convention that includes a date stamp for version tracking
    - Verify that all formulas, pivot tables, and formatting remain intact when the file is downloaded and opened locally by end-users