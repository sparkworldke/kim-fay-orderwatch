# Price Change Request (PCR) — Verification Status

**Date:** 12 Jul 2026  
**PRD:** `kp/pricing/price-change.md`  
**Result:** **Working** after migration + middleware + mail hardening  

---

## What was wrong (before check)

| Issue | Impact | Fix applied |
|-------|--------|-------------|
| PCR DB tables **not migrated** | API would 500 on any PCR call | Ran pending migrations (`2026_07_17_000001_create_price_change_request_tables`) |
| FOL migration blocked on `user_roles` unique index | Migrations stuck; PCR never applied | Drop FK → drop unique → multi-role unique → restore FKs |
| `ViewOnlyUnlessPrivileged` blocked Sales Consultant **POST** | Consultants could not create PCR (403 read-only) | Allow `api/operations/price-change-requests` writes |
| SMTP errors rolled back create/approve | PCR create failed when mail broken | `notify()` catches mail failures; still audits sent/failed |

---

## Verified working

### Live smoke (`backend/scripts/smoke_pcr.php`)

- Tables present: requests, stages, events, settings  
- Stages seeded: `hod`, `senior`  
- Admin permissions OK  
- Dashboard loads  
- Resolve price OK  
- Create PCR OK (`PCR-2026-000001` pattern)  
- Admin can approve (`can_actor_approve=1`)  

### Automated tests (`PriceChangeRequestTest`)

```
✓ consultant can create and admin can approve to erp queue
✓ resolve price returns current selling without base for consultant
```

Covers: create → margin redaction for consultant → multi-stage approve → pending ERP → mark applied → dashboard.

---

## How to use in the app

1. User needs permission `pricing.pcr.view` (and `create` / `approve` / `apply_erp` as needed).  
2. Nav: **Workflow → Price Changes** (`/app/price-change-requests`).  
3. Consultant: **New PCR** → pick portfolio customer + SKU → proposed price + justification.  
4. Approver: **Pending Approval** → approve/reject with comment (base/margin visible if `view_margin`).  
5. Ops: **Pending ERP** → **Mark applied in ERP** after Acumatica update.

Admin stages/settings:

- API: `GET/PUT /api/admin/pricing/pcr-settings`  
- Seeded roles include PCR permissions in `RolesPermissionsSeeder`.

---

## API surface

| Method | Path |
|--------|------|
| GET | `/api/operations/price-change-requests` |
| POST | `/api/operations/price-change-requests` |
| GET | `…/resolve-price` |
| GET | `…/dashboard` |
| POST | `…/{id}/decisions` |
| POST | `…/{id}/acknowledge-duplicate` |
| POST | `…/{id}/mark-applied-erp` |
| GET/PUT | `/api/admin/pricing/pcr-settings` |

---

## Residual notes

- **Email** depends on SMTP; PCR workflow no longer fails if mail is down (failures logged on event payload).  
- Consultant still cannot create PCR for customers **outside portfolio** (by design).  
- Admin settings UI panel may be API-only depending on Administration tabs; list/create/approve UI routes exist under `/app/price-change-requests`.  
- Full PRD items (SLA alerts dashboard widgets, drag thresholds UI) — see `kp/pricing/price-change.md` for remaining product scope.

---

## Commands used

```bash
cd backend
php artisan migrate --force
php artisan db:seed --class=RolesPermissionsSeeder --force
php scripts/smoke_pcr.php
php artisan test --filter=PriceChangeRequestTest
```
