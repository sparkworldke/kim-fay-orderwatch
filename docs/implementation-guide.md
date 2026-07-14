# OrderWatch — Implementation Guide (July 2026)

Summary of features implemented in this pass: Whitspot on customer orders, Sales Consultant scoping, shipping zones, Fill Rate delivery SLA, and related cleanup.

**Production frontend:** https://orderwatch.fayshop.co.ke (Cloudflare Worker `orderwatchkimfay`)

---

## Quick verification

```bash
cd backend
php artisan migrate
php artisan test --filter="OrderControllerTest|ShippingZoneSyncTest|DeliverySlaEvaluatorTest|CustomerCategoryCronRemovalTest|AcumaticaOperationsSyncTest|SalesConsultantOperationsTest"
```

```bash
# Frontend (from repo root)
npm run build
npx wrangler deploy
```

---

## 1. Whitspot on customer orders

| Status | **Implemented** |
|--------|-----------------|
| What | Full Whitspot table inline on customer/branch order pages (not only a link) |
| UI | `src/routes/app.customer-orders.$customerId.tsx`, `app.customer-orders.$customerId.branch.$branchId.tsx` |
| Shared | `src/components/customer-orders-shared.tsx` — `SuggestedOrdersCard` |
| API | `GET /api/customers/{id}/suggested-orders` |
| Standalone route | `/app/customer-orders/{customerId}/suggested` still works |

**Verify:** Open `/app/customer-orders/CUST100816` → Whitspot section below Common Products.

---

## 2. Sales Consultant — own orders only

| Status | **Implemented** |
|--------|-----------------|
| What | Users with role **Sales Consultant** see only orders where `sales_consultant_rep_code` matches their profile `rep_code`. Stat cards on Dashboard, Orders, Customers, Backorders, Fill Rate, and Business Optimization reflect the same scope. |
| Code | `backend/app/Support/SalesConsultantScope.php` |
| Applied in | `OrderController`, `DashboardController`, `CustomerController`, `OperationsController`, `BusinessOptimizationService` |
| Tests | `backend/tests/Feature/OrderControllerTest.php` |

**Requirements:**
- Each Sales Consultant user must have `rep_code` set (e.g. `P505`).
- Synced orders must have `sales_consultant_rep_code` populated.

**Verify:** Log in as Sales Consultant → Dashboard/Orders counts only show their orders.

---

## 3. Shipping zones

| Status | **Implemented** |
|--------|-----------------|
| What | Import Acumatica **Zone** master data; store `ShippingZoneID` from Customer payload (e.g. CUS08548 → `Z005`) |
| Model | `AcumaticaShippingZone` → table `acumatica_shipping_zones` |
| Customer field | `acumatica_customers.shipping_zone_id` |
| Sync command | `php artisan acumatica:sync-shipping-zones` |
| Admin API | `POST /api/admin/acumatica/sync/shipping-zones` |
| Customer sync | Runs zone sync first, then maps `ShippingZoneID` on each customer |
| List API | `GET /api/customers/shipping-zones` |
| Customer show | Returns `shipping_zone_id` + nested `shipping_zone` object |
| Tests | `backend/tests/Feature/ShippingZoneSyncTest.php` |

**Acumatica:** `Zone` entity must be exposed on the `IpayV2` endpoint (same as Administration zone lookup).

**Deploy:**

```bash
php artisan migrate
php artisan acumatica:sync-shipping-zones
php artisan acumatica:sync-customers   # backfill shipping_zone_id
```

---

## 4. Fill Rate — delivery SLA by shipping zone

| Status | **Implemented** |
|--------|-----------------|
| What | Flag orders whose delivery time exceeds zone-based SLA |
| Rules | **Nairobi Zone / Mombasa Zone:** breach if delivery &gt; **24 hours** |
| | **All other regions:** warning if &gt; **48 hours**, breach if &gt; **72 hours** |
| Timing | From `order_date` (or `approved_at`) to `shipped_at` / `ship_date`, or **now** if still open |
| Code | `backend/app/Services/Operations/DeliverySlaEvaluator.php` |
| API fields | `delivery_hours`, `delivery_sla_status`, `delivery_sla_label`, `shipping_zone_id`, `shipping_zone_description`, `is_metro_zone` |
| Summary | `delivery_sla_breach_count`, `delivery_sla_warning_count`, `delivery_sla_rules` |
| Filter | `GET /api/operations/fill-rate?delivery_sla=breach` or `warning` |
| UI | `src/routes/app.fill-rate.tsx` — stat cards, Delivery SLA column, filter |
| Tests | `backend/tests/Unit/DeliverySlaEvaluatorTest.php`, `AcumaticaOperationsSyncTest` |

**Verify:** Fill Rate page → stat cards **Delivery &gt; SLA** and **Delivery &gt;48h**; table column **Delivery SLA**.

---

## 5. Customer categories cron — removed

| Status | **Implemented** |
|--------|-----------------|
| What | Hourly `acumatica:sync-categories` no longer scheduled or registered as default cron job |
| Removed from | `backend/routes/console.php`, `CronJob::ensureDefaults()` |
| Migration | `2026_07_06_000002_remove_customer_category_sync_cron.php` deletes `acumatica-customer-category-sync` row |
| Manual command | `php artisan acumatica:sync-categories` still exists if needed |
| Test | `backend/tests/Feature/CustomerCategoryCronRemovalTest.php` |

---

## 6. Deployment

| Layer | How |
|-------|-----|
| **Frontend** | `npm run build` → `npx wrangler deploy` → `orderwatch.fayshop.co.ke` |
| **Backend** | Deploy Laravel app + `php artisan migrate` on production server |

Latest frontend Worker version at time of writing: `161bcfb9-ce3a-415a-b84c-bd34c528dd85`.

Backend API changes (Sales Consultant scope, shipping zones, delivery SLA) require the **Laravel host** to be deployed separately from the Cloudflare Worker.

---

## Key files reference

| Area | Files |
|------|--------|
| Whitspot | `customer-orders-shared.tsx`, `CustomerController::suggestedOrders` |
| Sales Consultant scope | `SalesConsultantScope.php`, `OrderController`, `DashboardController` |
| Shipping zones | `AcumaticaShippingZone.php`, `AcumaticaShippingZoneSyncService.php`, `SyncAcumaticaShippingZones.php` |
| Delivery SLA | `DeliverySlaEvaluator.php`, `OperationsController::fillRate` |
| Cron cleanup | `console.php`, `CronJob.php`, migration `2026_07_06_000002_*` |

---

## Production checklist

- [ ] `php artisan migrate` on Laravel server
- [ ] `php artisan acumatica:sync-shipping-zones`
- [ ] `php artisan acumatica:sync-customers` (refresh `shipping_zone_id`)
- [ ] Confirm Sales Consultant users have `rep_code` in Profile / Admin
- [ ] Frontend deployed via Wrangler (done if Worker version is current)
- [ ] Hard-refresh browser on `orderwatch.fayshop.co.ke`

For zones UI, sync commands, IpayV2 fallbacks, and email cron fixes, see **`new-features-and-sync.md`**.
