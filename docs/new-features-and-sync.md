# New Features and Sync

OrderWatch — features shipped in the July 2026 pass, Acumatica sync commands, production setup, and troubleshooting.

**Frontend (live):** https://orderwatch.fayshop.co.ke  
**Worker name:** `orderwatchkimfay`  
**Latest deploy version:** `c1a16962-bc49-4051-bd50-0aefcf683b3d`

**Backend (Laravel API):** deploy separately to the production host (e.g. `dating.sparkworld.co.ke/public_html/backend`).

---

## Quick start — production sync order

Run on the **Laravel server** after deploying backend code and running migrations:

```bash
cd backend
php artisan migrate

# 1. Build zone master list (IpayV2 has no Zone entity — uses customer fallback)
php artisan acumatica:sync-shipping-zones --from-customers

# 2. Import customers and map ShippingZoneID on each row
php artisan acumatica:sync-customers
```

**Frontend deploy** (from repo root):

```bash
npm run build
npx wrangler deploy
```

Hard-refresh the browser (Ctrl+Shift+R) after deploy.

---

## 1. Zones tab (new UI)

| Item | Detail |
|------|--------|
| **Route** | `/app/zones` |
| **Nav** | Operations → **Zones** |
| **Page** | `src/routes/app.zones.tsx` |
| **Hook** | `src/hooks/useShippingZones.ts` |
| **API** | `GET /api/customers/shipping-zones` |
| **Sync API** | `POST /api/admin/acumatica/sync/shipping-zones` |

**What you see**

- Zone directory: ID, description, delivery SLA badge (24h metro vs 48–72h regional)
- Stat cards: total zones, metro count, regional count, customers assigned
- Search by zone ID or description
- Click a row → sheet with customers in that zone
- **View orders** / **Fill rate orders** → opens Fill Rate filtered by zone
- **Sync from Acumatica** (Administrator / CS roles with sync permission)

---

## 2. Fill Rate — filter by shipping zone

| Item | Detail |
|------|--------|
| **UI** | Fill Rate → **Shipping zone** dropdown |
| **Deep link** | `/app/fill-rate?shipping_zone_id=Z005` |
| **List API** | `GET /api/operations/fill-rate?shipping_zone_id=Z005` |
| **Summary API** | `GET /api/operations/fill-rate/summary?...&shipping_zone_id=Z005` |
| **Code** | `OperationsController::fillRateFilteredQuery()` |

Summary stat cards and the order table both respect the active zone filter (same as customer group / product line filters).

**Delivery SLA rules** (unchanged, now tied to zone data):

| Zone type | Rule |
|-----------|------|
| Nairobi / Mombasa (metro) | Breach if delivery &gt; **24 hours** |
| Other regions | Warning if &gt; **48 hours**, breach if &gt; **72 hours** |

Evaluator: `backend/app/Services/Operations/DeliverySlaEvaluator.php`

---

## 3. Shipping zones — backend and sync

### Database

| Table / column | Purpose |
|----------------|---------|
| `acumatica_shipping_zones` | Zone master (`acumatica_id`, `description`, `synced_at`) |
| `acumatica_customers.shipping_zone_id` | From Acumatica `Customer.ShippingZoneID` |

Migrations: `2026_07_06_000003_*`, `2026_07_06_000004_*`

### Acumatica IpayV2 limitation

The **`Zone` entity returns 404** on Kim-Fay’s IpayV2 endpoint. Sync does **not** fail anymore:

1. Tries entities: `Zone` → `ShippingZone` → `ShipZone`
2. If all 404 → **fallback**: scan all customers for `ShippingZoneID`
3. Descriptions from `config/shipping_zones.php` when Acumatica does not provide them

### Artisan commands

| Command | Purpose |
|---------|---------|
| `php artisan acumatica:sync-shipping-zones` | Zone sync (master attempt, then customer fallback) |
| `php artisan acumatica:sync-shipping-zones --from-customers` | Skip master; build zones only from customers |
| `php artisan acumatica:sync-customers` | **New** — full customer import + `shipping_zone_id` |

**File:** `backend/app/Console/Commands/SyncAcumaticaCustomers.php`

Customer sync also runs a lightweight zone master attempt first (`allowCustomerFallback: false`); zones are still created per customer during upsert via `ensureZoneExists()`.

### Known zone descriptions

Edit `backend/config/shipping_zones.php` as you discover IDs:

```php
'known_descriptions' => [
    'Z005' => 'Nairobi Zone',
    'Z010' => 'Mombasa Zone',
],
```

These drive metro SLA matching when Acumatica descriptions are missing.

### Fixes applied this session

| Issue | Fix |
|-------|-----|
| `404 Not Found` on Zone entity | Multi-entity try + customer fallback |
| `Array to string conversion` | `AcumaticaClient::scalarVal()` for nested/empty `ShippingZoneID` payloads |
| Missing `acumatica:sync-customers` | New artisan command (was API-only before) |

**Key files**

- `AcumaticaClient.php` — `fetchAllShippingZones()`, `scalarVal()`
- `AcumaticaShippingZoneSyncService.php` — fallback, `ensureZoneExists()`
- `ShippingZoneDescription.php` — config-backed labels
- `SyncAcumaticaShippingZones.php`, `SyncAcumaticaCustomers.php`

### Expected CLI output (zones)

```
Zone master entity not exposed on Acumatica endpoint; synced from Customer.ShippingZoneID.
Shipping zone sync complete (customers): X/Y synced, 0 failed.
```

### Expected CLI output (customers)

```
Customer sync complete: X/Y synced, 0 failed.
```

### Alternative: Admin UI / API

- Administration → Acumatica → sync customers / shipping zones
- `POST /api/admin/acumatica/sync/customers`
- `POST /api/admin/acumatica/sync/shipping-zones`

---

## 4. Email sync cron — reliability fix

**Cron:** `php artisan orderwatch:email-sync` (every 2 hours, odd hours)

Large mailboxes could run **60+ minutes** while the default cron lock expired at **55 minutes**, causing overlap and failures.

| Change | Detail |
|--------|--------|
| Lock TTL | **2 hours** (`120 * 60` seconds) |
| PHP time limit | `set_time_limit(0)` for the sync run |
| Failure output | Prints `error_summary` when a run fails |
| Partial success | Improved detection when some mailboxes succeed |

**File:** `backend/app/Console/Commands/RunEmailSync.php`

**If email sync fails**, check Administration → Cron Jobs or:

```bash
php artisan orderwatch:email-sync --source=manual
```

Common causes: expired Microsoft OAuth token (reconnect mailbox), Graph timeout, or `sync_from_date` pulling too much history.

---

## 5. Other features in this implementation pass

These were implemented in the same codebase pass and ship with the current frontend/backend:

| Feature | Summary |
|---------|---------|
| **Whitspot** | Inline suggested orders on customer/branch order pages |
| **Sales Consultant scope** | Role sees only orders matching their `rep_code` |
| **Customer categories cron removed** | `acumatica-customer-category-sync` no longer scheduled |
| **Fill Rate delivery SLA** | Metro/regional SLA columns, filters, summary counts |

See `implementation-guide.md` for Whitspot, Sales Consultant, and SLA detail.

---

## 6. API reference (zones & fill rate)

| Method | Endpoint | Notes |
|--------|----------|-------|
| GET | `/api/customers/shipping-zones` | List zones + `customer_count` |
| GET | `/api/customers?shipping_zone_id=Z005` | Customers in zone |
| POST | `/api/admin/acumatica/sync/shipping-zones` | Trigger zone sync |
| POST | `/api/admin/acumatica/sync/customers` | Trigger customer sync |
| GET | `/api/operations/fill-rate?shipping_zone_id=Z005` | Filtered orders |
| GET | `/api/operations/fill-rate/summary?shipping_zone_id=Z005` | Filtered summary |
| GET | `/api/operations/fill-rate?delivery_sla=breach` | SLA breach filter |

---

## 7. Tests

```bash
cd backend
php artisan test --filter="ShippingZoneSyncTest|DeliverySlaEvaluatorTest|AcumaticaOperationsSyncTest|CustomerCategoryCronRemovalTest"
```

Notable cases:

- `test_shipping_zone_sync_falls_back_to_customer_shipping_zone_ids`
- `test_shipping_zone_sync_skips_empty_or_nested_shipping_zone_fields`
- `test_sync_customers_command_runs_customer_import`
- `test_fill_rate_list_filters_by_shipping_zone`

---

## 8. Production checklist

### Backend (Laravel host)

- [ ] Deploy latest `backend/` code
- [ ] `php artisan migrate`
- [ ] `php artisan acumatica:sync-shipping-zones --from-customers`
- [ ] `php artisan acumatica:sync-customers`
- [ ] Confirm `acumatica:sync-customers` exists: `php artisan list acumatica`
- [ ] Deploy `RunEmailSync.php` if email cron was failing on long runs

### Frontend (Cloudflare)

- [x] `npm run build && npx wrangler deploy` → `orderwatch.fayshop.co.ke`
- [ ] Hard-refresh browser
- [ ] Verify **Operations → Zones** appears
- [ ] Verify Fill Rate **Shipping zone** dropdown populates after backend sync

### Data quality

- [ ] Add more entries to `config/shipping_zones.php` as zone IDs are discovered
- [ ] Sales Consultant users have `rep_code` set in profile/admin
- [ ] Mailboxes reconnected if email sync shows token errors

---

## 9. Troubleshooting

| Symptom | Action |
|---------|--------|
| `Command "acumatica:sync-customers" is not defined` | Deploy `SyncAcumaticaCustomers.php`; run `php artisan list acumatica` |
| Zone sync `404 Not Found` | Use `--from-customers`; ensure latest `AcumaticaClient.php` is deployed |
| Zone sync `Array to string conversion` | Deploy `scalarVal()` fix in `AcumaticaClient.php` + `AcumaticaShippingZoneSyncService.php` |
| Zones tab empty | Run zone + customer sync commands above |
| Fill Rate zone dropdown empty | Same — zones come from `acumatica_shipping_zones` table |
| Email sync failed ~68 min | Deploy `RunEmailSync.php`; check mailbox token; re-run manually |
| Zones UI missing after deploy | Hard-refresh; confirm Worker version matches latest deploy |

---

## 10. File index (this session)

| Area | Paths |
|------|--------|
| Zones UI | `src/routes/app.zones.tsx`, `src/hooks/useShippingZones.ts`, `src/components/app-sidebar.tsx`, `src/lib/nav-permissions.ts` |
| Fill rate zone filter | `src/routes/app.fill-rate.tsx`, `src/hooks/useOperations.ts` |
| Zone sync service | `backend/app/Services/Admin/AcumaticaShippingZoneSyncService.php` |
| Acumatica client | `backend/app/Services/Admin/AcumaticaClient.php` |
| Zone config | `backend/config/shipping_zones.php` |
| Commands | `SyncAcumaticaShippingZones.php`, `SyncAcumaticaCustomers.php` |
| Customer API | `backend/app/Http/Controllers/Api/CustomerController.php` |
| Fill rate API | `backend/app/Http/Controllers/Api/OperationsController.php` |
| Email cron | `backend/app/Console/Commands/RunEmailSync.php` |
| Deploy config | `wrangler.jsonc` |

---

*Last updated: July 2026 — frontend Worker `c1a16962-bc49-4051-bd50-0aefcf683b3d`.*