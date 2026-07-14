# Backend Deploy — Update Release (July 2026)

Use this guide to **update** an existing OrderWatch Laravel API on the VPS after new frontend/backend changes.

For a **first-time** server install, use [`DEPLOY-VPS.md`](./DEPLOY-VPS.md) instead.

| Layer | Where | Deploy method |
|-------|--------|----------------|
| **Frontend** | Cloudflare Worker (`orderwatch.fayshop.co.ke`) | `npm run build` + `npx wrangler deploy` |
| **Backend** | VPS (`api.orderwatch.fayshop.co.ke`) | rsync/git + `composer` + `migrate` + cache clear |

---

## Hotfix — reports-to cycle (July 2026)

**Symptom:** Team/user edit fails with `Reports-to assignment would create a cycle` when assigning a normal manager (e.g. Adan → `partnerbrands@kimfay.com`).

**Cause:** Cycle check was inverted and the update path saved `reports_to_user_id` *before* validation, so the user always appeared as a “descendant” of their new manager.

**Files to deploy on the API host:**

```text
backend/app/Services/Team/OrgTreeService.php
backend/app/Services/Team/UserOrgService.php
backend/app/Http/Controllers/Api/Admin/UserController.php
backend/routes/api.php
```

**Dynamic reports-to guardrail:** any active user may report to any other active user. Only blocked: self, inactive manager, and reportee-subtree (cycle). APIs:

```text
GET /api/admin/users/reports-to-options
GET /api/admin/users/{id}/reports-to-options
```

After upload:

```bash
cd /var/www/orderwatch/backend
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
sudo systemctl reload php8.3-fpm
```

Optional: set the Adan assignment once (or use the Team UI):

```bash
php artisan tinker --execute="
\$a = \App\Models\User::where('email','brandoperations.unilever@kimfay.com')->first();
\$m = \App\Models\User::where('email','partnerbrands@kimfay.com')->first();
app(\App\Services\Team\UserOrgService::class)->applyOrgConfig(\$a, ['reports_to_user_id' => \$m->id]);
echo \$a->fresh()->reports_to_user_id;
"
```

No database migration required for this hotfix. Frontend (Wrangler) not required.

---

## What’s in this update

| Area | Changes to expect on the server |
|------|----------------------------------|
| **Dashboard** | Goods Lost in Transit tab (`CUST102641` excluded from main SO KPIs); SO totals strip; compact UI |
| **Fill rate** | OOS toggle; Manufactured/Trading SKU breakdown + Excel; reason catalog |
| **Inventory** | Per-warehouse crons (DTC, FGS, FGS2, FGS2 RETURNS, MSA, EXPORT, PRMS, RMS1, TRMS) |
| **Sales orders** | Prune missing SOs after sync; status sync recheck |
| **Email** | Domain grouping (incl. gmail.com); same-day 3h sync watermark |
| **Daily report** | Scheduler: Tue–Sat **07:00** `Africa/Nairobi` → `orderwatch:send-daily-report-fixed` |
| **Team / org** | Team management, departments, scoping tables (new migrations) |
| **Config files** | `config/dashboard.php`, `config/inventory.php`, `config/departments.php`, `config/org_tree.php` |

### New / important migrations (run with `php artisan migrate --force`)

```text
2026_07_09_000002_add_employee_number_to_users_table
2026_07_10_000001_add_classification_columns_to_inventory_items
2026_07_11_000001_add_workflow_reason_fields_to_acumatica_sales_orders
2026_07_11_000002_create_so_reason_taxonomy_tables
2026_07_12_000001_ensure_updated_at_on_inventory_sku_insights
2026_07_13_000001_create_team_management_tables
2026_07_14_000001_create_org_chart_and_scoping_tables
2026_07_14_000002_backfill_department_user_pivot
2026_07_14_000003_create_staff_import_gaps_table
```

(Plus any earlier migrations not yet applied on the VPS.)

### New API endpoints (examples)

```text
GET /api/dashboard/goods-lost-in-transit?date_from=&date_to=
GET /api/dashboard/kpis   → includes so_totals + goods_lost_in_transit
```

Fill-rate OOS / SKU breakdown endpoints remain under operations (see Admin / Fill Rate UI).

---

## 0. Frontend deploy (Cloudflare) — do this first or in parallel

From the **monorepo root** on your machine (not the VPS):

```bash
# Ensure API base URL points at production
# .env / .env.production should include e.g.:
#   VITE_API_BASE_URL=https://api.orderwatch.fayshop.co.ke/api

npm run build
npx wrangler deploy
```

Worker name: `orderwatchkimfay`  
Route: `orderwatch.fayshop.co.ke` (custom domain)

Confirm:

```bash
npx wrangler whoami
curl -sI https://orderwatch.fayshop.co.ke
```

---

## 1. Upload backend code to the VPS

### Option A — rsync from local machine (typical)

From your PC project root (PowerShell or Git Bash):

```bash
rsync -avz --delete \
  --exclude vendor \
  --exclude node_modules \
  --exclude storage/logs \
  --exclude storage/framework/cache \
  --exclude storage/framework/sessions \
  --exclude storage/framework/views \
  --exclude .env \
  --exclude database/database.sqlite \
  ./backend/ user@YOUR_VPS_IP:/var/www/orderwatch/backend/
```

**Never overwrite production `.env`.**

### Option B — Git on the VPS

```bash
sudo -u orderwatch -i
cd /var/www/orderwatch/repo   # or your clone path
git pull origin master
# If backend is a symlink into the monorepo, pull is enough.
# Otherwise rsync/copy backend/ into /var/www/orderwatch/backend
```

### Option C — zip upload

```bash
# Local (exclude secrets + vendor)
# zip backend-update.zip excluding .env vendor storage/logs ...

# On VPS:
cd /var/www/orderwatch/backend
# unzip carefully over existing tree, preserving .env and storage/*
composer install --no-dev --optimize-autoloader
```

Fix ownership after upload:

```bash
sudo chown -R orderwatch:www-data /var/www/orderwatch/backend
sudo find /var/www/orderwatch/backend/storage /var/www/orderwatch/backend/bootstrap/cache -type d -exec chmod 775 {} \;
```

---

## 2. Install dependencies and migrate

```bash
cd /var/www/orderwatch/backend

composer install --no-dev --optimize-autoloader

# See what will run
php artisan migrate:status

# Apply new schema
php artisan migrate --force
```

### Optional one-time seeds (only if needed)

```bash
# Roles/permissions if missing (safe-ish; do not re-seed users in prod blindly)
# php artisan db:seed --class=RolesPermissionsSeeder --force

# SO reason taxonomy (out-of-stock codes etc.)
php artisan orderwatch:seed-so-reason-taxonomy   # if command exists

# Org / departments (if empty)
# php artisan db:seed --class=DepartmentSeeder --force
```

Refresh cron job rows (warehouse inventory jobs, daily report, etc.):

```bash
php artisan tinker --execute="\\App\\Models\\CronJob::ensureDefaults();"
```

---

## 3. Clear caches and reload PHP

```bash
cd /var/www/orderwatch/backend

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Opcache picks up new PHP files
sudo systemctl reload php8.3-fpm

# If you run a queue worker:
# sudo systemctl restart orderwatch-queue
# or: php artisan queue:restart
```

---

## 4. Confirm scheduler (daily notification + crons)

System crontab **must** run every minute:

```cron
* * * * * cd /var/www/orderwatch/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

```bash
# As user orderwatch
crontab -l

php artisan schedule:list | head -40
```

You should see at least:

| Expression | Command |
|------------|---------|
| `0 7 * * 2-6` | `orderwatch:send-daily-report-fixed --source=scheduler` |
| `0 * * * *` | `orderwatch:evaluate-order-match-notifications` |
| Warehouse inventory crons | `orderwatch:inventory-sync --job-key=inventory-sync-…` |

Timezone: `CRON_TIMEZONE=Africa/Nairobi` in `.env`.

Smoke-test daily report (does not wait for schedule):

```bash
php artisan orderwatch:send-daily-report-fixed --source=manual --force
# or without force if not yet sent for yesterday
```

Diagnose if email did not send:

```bash
php scripts/diagnose_daily_report.php
```

---

## 5. Smoke tests after deploy

```bash
# API up
curl -sS https://api.orderwatch.fayshop.co.ke/up

# Login
curl -sS -X POST https://api.orderwatch.fayshop.co.ke/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"YOUR_ADMIN_EMAIL","password":"YOUR_PASSWORD"}'

# Dashboard KPIs include so_totals (use token from login)
# curl -sS "https://api.orderwatch.fayshop.co.ke/api/dashboard/kpis?date_from=2026-07-01&date_to=2026-07-10" \
#   -H "Authorization: Bearer TOKEN" -H "Accept: application/json"

# Goods Lost in Transit
# curl -sS "https://api.orderwatch.fayshop.co.ke/api/dashboard/goods-lost-in-transit?date_from=2026-07-01&date_to=2026-07-10" \
#   -H "Authorization: Bearer TOKEN" -H "Accept: application/json"
```

UI checks on `https://orderwatch.fayshop.co.ke`:

1. **Operations Dashboard** → tabs **Sales Orders** / **Goods Lost in Transit**
2. SO calculation strip: All SO = Dashboard SO + GLT SO
3. Fill Rate → OOS toggle + Manufactured/Trading drill-down
4. Admin → Cron Jobs → warehouse inventory jobs + Daily Report Fixed Scheduler present

Logs:

```bash
tail -n 80 /var/www/orderwatch/backend/storage/logs/laravel.log
sudo tail -n 40 /var/log/nginx/orderwatch-api.error.log
```

---

## 6. One-shot update script (VPS)

Save as `/var/www/orderwatch/backend/deploy-update.sh` and `chmod +x` it.  
Run **after** new code is already on disk (rsync/git).

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /var/www/orderwatch/backend

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan tinker --execute="\\App\\Models\\CronJob::ensureDefaults();" || true
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart 2>/dev/null || true

echo "Deploy update complete. Reload PHP-FPM if needed:"
echo "  sudo systemctl reload php8.3-fpm"
```

---

## 7. Rollback notes

| Layer | How |
|-------|-----|
| Frontend | `npx wrangler rollback` (or redeploy previous build) |
| Backend code | Restore previous rsync/git revision |
| Database | Migrations are forward-only — restore DB snapshot if a migration breaks prod |

Always take a DB dump before large schema updates:

```bash
mysqldump -u orderwatch -p kimfay_orderwatch > ~/orderwatch-backup-$(date +%Y%m%d).sql
```

---

## 8. Checklist

- [ ] Frontend built and `wrangler deploy` succeeded  
- [ ] Backend files uploaded **without** overwriting `.env`  
- [ ] `composer install --no-dev`  
- [ ] `php artisan migrate --force`  
- [ ] `CronJob::ensureDefaults()`  
- [ ] Config/route caches rebuilt  
- [ ] `php8.3-fpm` reloaded  
- [ ] Crontab still runs `schedule:run` every minute  
- [ ] `schedule:list` shows daily report Tue–Sat 07:00  
- [ ] Dashboard GLT tab + fill-rate UI smoke-tested  
- [ ] `MAIL_*` and Acumatica credentials still valid  

---

## Related docs

- Full first-time install: [`DEPLOY-VPS.md`](./DEPLOY-VPS.md)  
- Cron details: [`cron-jobs-guide.md`](./cron-jobs-guide.md)  
- Project status: [`PROJECT-OVERVIEW.md`](./PROJECT-OVERVIEW.md)  
