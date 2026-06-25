# Sales Order Type Distinction — Kim-Fay OrderWatch

**Version:** 1.1.0  
**Date:** 2026-06-24  
**Acumatica contract:** `IpayV2` / `22.200.001`

## Problem

Acumatica `SalesOrder` is a **shared OData entity** for multiple document types. `OrderType.value` distinguishes them:

| OrderType | Meaning | Dashboard / Orders | Credit Notes & More |
|-----------|---------|--------------------|---------------------|
| **SO** | Sales Order | ✅ In scope | ❌ |
| **QT** | Quote | ❌ | ✅ |
| **RC** | Return / Credit Note | ❌ | ✅ |
| **CM** | Credit Memo | ❌ | ✅ |
| **PL** | Pick List (or other non-SO doc) | ❌ | ✅ |

**“Open SO”** on the dashboard means `OrderType = SO` **and** `Status = Open`.  
It is **not** the same as “any open document” (QT/RC/CM/PL may also be Open).

## Acumatica endpoint

```
GET /entity/IpayV2/22.200.001/SalesOrder/
  ?$expand=DocumentDetails
  &$filter=CustomerID eq 'CUST101239'
    and OrderType eq 'SO'
    and Date ge datetimeoffset'2026-06-19T00:00:00'
    and Date le datetimeoffset'2026-06-19T23:59:59'
```

> IpayV2 uses `DocumentDetails` (not `Details`). Nested `$select` on lines is avoided — it breaks OData binding on 22.200.001.

### Fetch guardrails

| Use case | OData filter |
|----------|----------------|
| Dashboard KPIs, Orders page, Fill Rate, Backorders | `OrderType eq 'SO'` |
| Credit Notes & More sync / page | `(OrderType eq 'QT' or OrderType eq 'RC' or OrderType eq 'CM' or OrderType eq 'PL')` |

## Fill rate (SO only)

- Source: SO documents with `Status ne 'Completed'` in date range.
- Line qty field: `OrderQty` (alias `OrderedQty` when present).
- **Unique items:** group lines by `InventoryID`, sum `OrderedQty` per SKU, then roll up fill rate.
- Prevents duplicate line rows for the same SKU from skewing totals.

## Email / Outlook read status

- **CRM-only read state:** `emails.is_read` reflects what we store locally for the app UI.
- **Never write back to Outlook:** Graph `$select` excludes `body` (fetching body marks read in Outlook). OAuth scope is `Mail.Read` only — no PATCH to Microsoft Graph.
- Delta sync may update local `is_read` when Outlook reports `isRead` — we do not push CRM read actions to Outlook Online.

## Mailbox folder sync stats

Each folder card shows:

- **All time synced:** count of emails stored in `emails` for that folder (refer-back total).
- **Last sync:** timestamp + count from the latest `order_match_sync_runs` row (`emails_queued`).

## UI surfaces

| Surface | Order types shown |
|---------|-------------------|
| Dashboard | SO only |
| Orders | SO only (locked) |
| Fill Rate | SO only (sync + display) |
| Order Match | SO only |
| **Credit Notes & More** (new menu) | QT, RC, CM, PL |
| Sales Order Imports | All types (audit) |

## Implementation files

- `backend/app/Models/AcumaticaSalesOrder.php` — type constants & scopes
- `backend/app/Services/Admin/AcumaticaClient.php` — filtered fetches
- `backend/app/Services/Admin/AcumaticaSalesOrderSyncService.php` — SO vs credit-notes sync
- `backend/app/Http/Controllers/Api/DashboardController.php` — SO-only KPIs
- `backend/app/Services/Admin/FillRateCalculator.php` — unique-item rollup
- `src/routes/app.credit-notes-more.tsx` — new page
- `src/components/app-sidebar.tsx` — menu entry