# Kim-Fay OrderWatch — Session Implementation and Deployment Handover

Date: 30 July 2026  
Application: Kim-Fay OrderWatch / Sight  
Frontend: TanStack React  
Backend: Laravel  
Production backend: `/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend`

## 1. Session Scope

This session covered the following areas:

- Sidebar navigation restructuring and responsive behaviour.
- Production & Stock dashboard UI corrections.
- Production Intelligence Laravel API integration.
- Product catalogue, taxonomy and CSV import.
- Orders and products-not-delivered filters.
- Download centre and downloadable report notifications.
- First-login password and phone-number onboarding.
- Inactive Sales Consultant email summaries.
- Administration controls for product catalogue and consultant alerts.

## 2. Navigation and Layout

### Main sidebar groups

The sidebar is grouped into:

1. OrderWatch
2. Production
3. KP
4. Administration

OrderWatch opens by default. Opening another parent closes the previously opened
parent. Administration uses the same expandable parent-menu behaviour.

Kimfay Genius and Downloads are standalone menu entries.

### Styling

- Sidebar uses a blue background.
- Standard menu text is white.
- Active child menu uses a white background with blue text.
- Mobile layouts use application-style bottom navigation.
- Cards stack and resize on smaller screens.
- Full-screen controls are available.
- Site-wide body text is reduced to the requested compact size.
- Page titles use the requested compact title size.
- Production cards, titles, CTAs and tables have responsive spacing to prevent
  overlaps.
- Selecting a Production table row does not add a dark page overlay.

## 3. Production Volume Intelligence

The production tabs are named (sidebar + in-module nav):

- Manufactured Intel (`/app/production`)
- Partner / Trading Intel (`/app/production/partners`)
- Sales Volume Intel (`/app/production/sales`)

### API-backed data

Production runtime data is sourced from Laravel instead of generated dummy stock
and sales records.

Authenticated endpoints include:

```text
GET    /api/operations/production/inventory
GET    /api/operations/production/inventory/{inventoryId}
GET    /api/operations/production/sales
GET    /api/operations/production/plans
POST   /api/operations/production/plans
PUT    /api/operations/production/plans/{plan}
DELETE /api/operations/production/plans/{plan}
```

### Stock calculations

- Total on hand is the sum of on-hand quantities across selected warehouses.
- Total available is the sum of available quantities supplied by the selected
  warehouses.
- Missing individual warehouse availability remains `null`.
- If every selected warehouse is missing available quantity, total on hand is
  used for MSI health with an `on_hand_fallback` indicator.
- Partial availability is identified separately.
- Missing source values display as `—`.

MSI status:

| Status | Calculation |
|---|---|
| Critical | Resolved stock is below 50% of MSI |
| At Risk | Resolved stock is at least 50% but below 100% of MSI |
| Healthy | Resolved stock is at or above MSI |
| Blank | MSI is unavailable |

Production requirement:

```text
max(MSI - resolved stock, 0)
```

Ordered and shipped sales quantities are returned independently. Sales Volume
Intel can switch between these measures.

## 4. Production Planning

Planning metadata is stored separately from synced Acumatica inventory.

The `production_sku_plans` table supports nullable fields such as:

- Ownership
- Business line
- Site
- Machine
- MSI
- Safety stock
- Buffer stock
- Export MSI or requirement
- Audit users
- Soft deletion

Deleting a plan never deletes its linked Acumatica inventory SKU.

The permission used for planning writes is:

```text
production.planning.manage
```

Authorized users can manage planning through the Production page planning
drawer.

## 5. Product Catalogue and Taxonomy

The catalogue is linked to synced Acumatica inventory by normalized Inventory ID.

### Tables and models

The implementation includes:

- `brands`
- `categories`
- `trading_groups`
- `products`
- `product_import_logs`
- `user_brand_assignments.brand_id`

Laravel models include:

- `Brand`
- `Category`
- `TradingGroup`
- `Product`
- `ProductImportLog`

### CSV mapping

| CSV column | Catalogue destination |
|---|---|
| Inventory ID | Acumatica inventory match |
| First Description | Product name |
| Second Description | Source/category-path description |
| Brand | Brand |
| Item Group | Product category |
| Sub Item Group | Child category/subcategory |
| Sub Trading Group | Displayed trading group |
| Trading Group | Portfolio group |
| Item Class | Item class |
| Posting Class | Posting class |
| Conversion Factor | Conversion factor |
| UOM | Unit of measure |
| Profit Margin | Profit-margin target |
| Supplier | Supplier |

Product ownership is independently editable as:

```text
manufactured
partner   (covers trading brands in Production Intel)
```

Ownership is **not** inferred from the CSV Trading Group / Sub Trading Group
text columns. On import (and via `orderwatch:classify-product-ownership`), it
is set from the brand classifier (Kimfay brand lists vs partner/trading brands)
and inventory ID prefixes so Production Manufactured / Partner tabs populate.

Manual product ownership edits still lock the product from later imports.

### Import rules

- Only SKUs already matched to synced Acumatica inventory enter the catalogue.
- Unmatched rows are skipped and reported.
- Imports create or update records but never deactivate missing rows.
- Manual product edits lock the entire product from later imports.
- Administrators can explicitly unlock products.
- Blank values are not replaced with invented classifications.
- Duplicate Description headers are handled positionally.
- CSV and XLSX files can be queued for background processing.

### Administration panels

Administration contains catalogue panels for:

- Products
- Brands
- Categories
- Trading Groups

Products can be searched, filtered, edited, activated/deactivated and
locked/unlocked.

## 6. Production CSV Configuration on Hestia

The confirmed VPS CSV path is:

```text
/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv
```

Add this to the backend `.env`:

```env
PRODUCT_CATALOG_CSV_PATH="/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv"
```

The seeder should remain environment-driven:

```php
$path = env(
    'PRODUCT_CATALOG_CSV_PATH',
    storage_path('app/imports/StockItemsBIData.csv')
);

app(ProductCsvImportService::class)->import($path);
```

Confirm the file exists:

```bash
ls -lh "/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv"
```

Confirm it is readable:

```bash
test -r "/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv" \
  && echo "CSV is readable" \
  || echo "CSV is missing or unreadable"
```

If permissions need correction:

```bash
chown kimfaydev:kimfaydev \
  "/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv"

chmod 640 \
  "/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv"
```

Run the initial catalogue import:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend

php artisan optimize:clear
php artisan config:cache
php artisan db:seed --class=ProductBrandSeeder
```

## 7. Orders Improvements

Orders support filtering by:

- Brand
- Customer segment:
  - KP
  - Consumer Sales
- Parent customer
- Child outlet/branch
- Multiple outlets

Where a customer has no branches, the parent customer is retained as the main
selectable outlet.

## 8. Products Not Delivered

The Products Not Delivered view defaults to the current month to date.

It supports:

- Brand filtering
- Outlet visibility
- Inventory comparison
- Differentiating:
  - Not delivered because stock is unavailable
  - Not delivered even though warehouse stock exists
- Warehouse-level availability
- Manufactured and partner ownership
- Excel report export

The report highlights undelivered items that are still available in one or more
warehouses.

## 9. Download Centre

Downloads are handled as a standalone menu.

After a background report completes, users can be notified through:

- Dashboard popup
- Email

The dashboard message is:

```text
Your download is ready
```

The popup contains a direct download link. Where configured, emailed reports can
be delivered as an attachment or through a downloadable link that does not
require login.

## 10. First-Login Onboarding

On the first login, and on later logins until completed, the user is asked to:

- Change their initial password.
- Optionally add a Safaricom phone number for OTP.
- Optionally add a WhatsApp number.
- Copy the Safaricom number into the WhatsApp field when “use same number” is
  selected.

The password-change requirement remains active until the password has actually
been changed.

## 11. Inactive Sales Consultant Email Digest

### Eligibility

The inactivity digest:

- Applies to users with the Sales Consultant role.
- Sends only to active accounts.
- Requires the consultant’s alert toggle to be enabled.
- Becomes eligible after more than 25 hours without login.
- Uses account creation time for users who have never logged in.
- Sends no more than once every 24 hours while the user remains inactive.
- Uses the consultant’s assigned customer portfolio.

The feature is disabled by default until an administrator enables it.

### Email content

The summary includes:

- Total orders
- Completed orders
- Rejected/cancelled orders
- Shipping orders
- Customers and outlets
- Undelivered item quantities
- Undelivered reasons
- Manufactured-brand breakdown
- Partner-brand breakdown
- Unclassified products where catalogue ownership is unavailable
- Recommended follow-up actions
- A link to the consultant portfolio dashboard

Outstanding item quantity is calculated as:

```text
ordered quantity
- max(shipped quantity, quantity on shipments)
- cancelled quantity
```

### Administration controls

Administration contains a `Consultant Alerts` panel with:

- An individual switch for each Sales Consultant
- Activate all
- Deactivate all
- Active/inactive account status
- Last login time
- Last inactivity email time

Admin API endpoints:

```text
GET /api/admin/sales-consultant-digests
PUT /api/admin/sales-consultant-digests/bulk
PUT /api/admin/sales-consultant-digests/{user}
```

### Command and schedule

Preview eligible consultants without sending:

```bash
php artisan orderwatch:send-consultant-inactivity-digests --dry-run
```

Send all eligible notifications:

```bash
php artisan orderwatch:send-consultant-inactivity-digests
```

Check or send a particular consultant:

```bash
php artisan orderwatch:send-consultant-inactivity-digests \
  --user-id=123 \
  --dry-run
```

The Laravel scheduler runs the command hourly at minute 15.

## 12. Local Windows Deployment Commands

### Laravel

```powershell
cd C:\laragon\www\kim-fay-orderwatch\backend

C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan migrate --force
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan optimize:clear
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan config:cache
C:\laragon\bin\php\php-8.3.16-Win32-vs16-x64\php.exe artisan route:cache
```

### Frontend

```powershell
cd C:\laragon\www\kim-fay-orderwatch

$env:Path = "C:\laragon\bin\nodejs\node-v22;$env:Path"
npm install
npm run build
```

## 13. Hestia Production Deployment Commands

Run the following after deploying the latest source:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run the product catalogue import when required:

```bash
php artisan db:seed --class=ProductBrandSeeder
# Or classify ownership only if products already exist:
php artisan orderwatch:classify-product-ownership
```

Build the frontend from the frontend project directory:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html

npm ci
npm run build
```

Do not run `npm run build` inside the Laravel `backend` directory unless the
frontend package is intentionally deployed there.

## 14. Scheduler Configuration

Add one cron entry for Laravel Scheduler:

```cron
* * * * * cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend && php artisan schedule:run >> /dev/null 2>&1
```

For the `kimfaydev` user:

```bash
crontab -u kimfaydev -e
```

Confirm Laravel sees the scheduled jobs:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend
php artisan schedule:list
```

## 15. Queue Worker

Background CSV imports, exports and other queued work require an active queue
worker:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend

php artisan queue:work \
  --sleep=3 \
  --tries=3 \
  --timeout=300 \
  --max-time=3600
```

For production, manage this command using Hestia, Supervisor or systemd so it
restarts automatically.

After deploying new code, restart workers:

```bash
php artisan queue:restart
```

## 16. Required Environment Checks

Confirm production `.env` values for:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://datacontroller.fayshop.co.ke

PRODUCT_CATALOG_CSV_PATH="/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/StockItemsBIData.csv"

QUEUE_CONNECTION=database

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="Kim-Fay OrderWatch"
```

Use the actual production mail settings. Do not commit production credentials to
Git.

After editing `.env`:

```bash
php artisan optimize:clear
php artisan config:cache
```

## 17. Verification Commands

### Database

```bash
php artisan migrate:status
```

### Routes

```bash
php artisan route:list --path=operations/production
php artisan route:list --path=sales-consultant-digests
```

### Scheduler

```bash
php artisan schedule:list
```

### Inactivity digest test

```bash
php artisan orderwatch:send-consultant-inactivity-digests --dry-run
```

### Laravel automated tests

```bash
php artisan test --filter=SalesConsultantInactivityDigestTest
php artisan test --filter=ProductionIntelligenceTest
php artisan test --filter=ProductCatalogueTest
php artisan test --filter=FirstLoginOnboardingTest
```

### Frontend

```bash
npm run build
```

## 18. Verification Completed During Development

- Sales Consultant digest: 3 tests passed with 12 assertions.
- Digest PHP files passed syntax validation.
- Consultant-alert admin routes were registered.
- Hourly consultant digest appeared in `schedule:list`.
- The inactivity-digest database migration completed successfully.
- The TanStack/Vite production build completed successfully.
- Product catalogue tests passed during its implementation.
- First-login onboarding tests passed during its implementation.

### Re-verification 2026-07-30 (post Manufactured / Partner menu + ownership classify)

| Suite | Result |
|---|---|
| `SalesConsultantInactivityDigestTest` | 3 passed |
| `ProductionIntelligenceTest` | 2 passed |
| `ProductCatalogueTest` | 2 passed (ownership now brand-classified on import) |
| `FirstLoginOnboardingTest` | 2 passed |
| `ItemsNotDeliveredTest` | 1 passed (export is XLSX, not CSV) |
| `ExportDownloadTest` | 6 passed |
| **Total** | **16 passed / 90 assertions** |

Also confirmed:

- Production API routes registered (`inventory`, `sales`, `plans` CRUD).
- Admin consultant-digest routes registered.
- Migrations for production/catalogue/onboarding/digest all **Ran**.
- `sales-order-sync-3h` cron expression: `0 */2 * * *` (every 2 hours).
- Consultant digest schedule: hourly at minute 15.
- Permission `production.planning.manage` present.
- Tables: `brands`, `categories`, `trading_groups`, `products`, `product_import_logs`, `production_sku_plans`, `export_downloads`.

## 19. Production Activation Checklist

1. Deploy the latest backend and frontend source.
2. Confirm the CSV exists at the configured production path.
3. Add `PRODUCT_CATALOG_CSV_PATH` to backend `.env`.
4. Confirm database, queue and mail environment variables.
5. Run Composer installation.
6. Run Laravel migrations.
7. Clear and rebuild Laravel caches.
8. Run `ProductBrandSeeder` if the catalogue has not been imported.
9. Build the frontend.
10. Ensure Laravel Scheduler runs every minute.
11. Ensure the queue worker is supervised.
12. Run the inactivity digest using `--dry-run`.
13. Open Administration → Consultant Alerts.
14. Enable selected consultants or choose Activate all.
15. Verify one test email before enabling the feature broadly.

## 20. Important Operational Notes

- Synced Acumatica inventory is never deleted when planning or catalogue metadata
  is removed.
- Missing payload values remain blank and are not silently converted to zero.
- Manual product edits are protected from imports until administrators unlock
  them.
- The consultant digest does not email inactive accounts.
- Bulk consultant-alert activation changes preferences for Sales Consultants;
  delivery still requires the user account itself to be active.
- Scheduler configuration is required even when the Laravel code and migrations
  have been deployed successfully.
- Queue-worker configuration is required for queued CSV imports and background
  exports.

