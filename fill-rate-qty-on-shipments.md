# Fill Rate — QtyOnShipments Implementation

This document describes how OrderWatch computes fill rate using Acumatica's per-line `QtyOnShipments` field, including database changes, guardrails, and API/UI updates.

## Background

Acumatica's IpayV2 Sales Order payload exposes line-level quantity on shipments via `Details[].QtyOnShipments`. This is the preferred numerator for fill rate because it reflects what is actually allocated to shipments per item — not just historical `ShippedQty`.

When `QtyOnShipments` is `0` and the line still has demand, the item is treated as **out of stock** (or partially short if some quantity is on shipments).

### Example payload (`SO359765`)

```json
{
  "OrderNbr": { "value": "SO359765" },
  "Status": { "value": "Rejected" },
  "Details": [
    {
      "InventoryID": { "value": "FAYWP0024" },
      "OrderQty": { "value": 0 },
      "QtyOnShipments": { "value": 0 },
      "UnitPrice": { "value": 1706.89655 }
    },
    {
      "InventoryID": { "value": "FAYWP0025" },
      "OrderQty": { "value": 5 },
      "QtyOnShipments": { "value": 5 },
      "UnitPrice": { "value": 1706.89655 }
    }
  ]
}
```

For an order with demand `10 + 5 = 15` and on-shipments `0 + 5 = 5`, fill rate = **33.33%** with one out-of-stock line.

---

## Formula

### Line level

```
Fill Rate (%) = (QtyOnShipments ÷ DemandQty) × 100
```

Where:

| Field | Source | Fallback |
|---|---|---|
| **Numerator** | `QtyOnShipments` | `ShippedQty` if `QtyOnShipments` is absent from payload |
| **Denominator (DemandQty)** | `UsrQtyAtApproval` | `OrderQty` if approval qty is zero |

### Order level

Fill rate is computed by **rolling up duplicate SKUs first**, then summing:

```
Order Fill Rate = (Σ QtyOnShipments ÷ Σ DemandQty) × 100
```

Do **not** average per-order percentages — that misweights large vs small orders.

### Revenue not shipped

```
Revenue not shipped = Σ max(0, DemandQty − QtyOnShipments) × UnitPrice
```

### Unfilled reason codes

| Condition | `unfilled_reason_code` |
|---|---|
| `QtyOnShipments = 0` and demand > 0, no ERP reason | `inventory_shortage` (out of stock) |
| `0 < QtyOnShipments < DemandQty`, no ERP reason | `inventory_shortage` (partial shortage) |
| Acumatica `ReasonCode` present | Normalized ERP code (e.g. `SUPPLIER_DELAY` → `supplier_delay`) |
| Fully on shipments or no demand | `null` |

---

## Guardrails

### Status exclusions

Orders in **On Hold** or **Pending Approval** return fill rate `N/A` — not `0%`. These statuses have no confirmed shipments; showing `0%` would imply failure when fulfilment hasn't started.

### Legacy payload fallback

If `QtyOnShipments` is missing from a line (empty `[]` or field absent), the system falls back to `ShippedQty`. This is tracked in sync run filters as `lines_shipped_qty_fallback`.

### Sync run metrics

Each fill-rate sync run stores guardrail counts in `acumatica_sync_logs.filters`:

| Filter key | Meaning |
|---|---|
| `orders_computed` | Orders with a valid fill rate percentage |
| `orders_computed_na` | Orders returning N/A |
| `lines_out_of_stock` | Lines with `QtyOnShipments = 0` and demand > 0 |
| `lines_partial_shortage` | Lines with partial on-shipment quantity |
| `lines_shipped_qty_fallback` | Lines using `ShippedQty` fallback |
| `lines_with_acumatica_reason` | Lines with an imported ERP `ReasonCode` |

---

## Database changes

Migration: `2026_07_01_000051_add_qty_on_shipments_to_fill_rate_tables.php`

### `acumatica_sales_order_lines`

| Column | Type | Description |
|---|---|---|
| `qty_on_shipments` | `decimal(15,4)` | Per-line quantity on shipments (fill-rate numerator) |
| `unfilled_reason_code` | `string(80), nullable` | Derived or imported reason when demand is not fully on shipments |

### `acumatica_backorder_lines`

| Column | Type | Description |
|---|---|---|
| `qty_on_shipments` | `decimal(15,4)` | Same field, synced alongside backorder lines |

### `acumatica_fill_rate_snapshots`

| Column | Type | Description |
|---|---|---|
| `out_of_stock_line_count` | `unsignedSmallInteger` | Count of lines with `QtyOnShipments = 0` at computation time |

> **Note:** `total_shipped_qty` on snapshots now stores the rolled-up **QtyOnShipments** total (the fill-rate numerator), not Acumatica's `ShippedQty`. The raw `shipped_qty` is still stored per line for reference.

---

## Code changes

### Core services

| File | Role |
|---|---|
| `SalesOrderLineFulfillmentDeriver.php` | Maps `QtyOnShipments`, derives `unfilled_reason_code`, computes line-level fill rate |
| `FillRateCalculator.php` | Rolls up by SKU, computes order-level fill rate and `out_of_stock_line_count` |
| `AcumaticaFillRateSyncService.php` | Syncs fill-rate snapshots, records guardrail metrics |
| `AcumaticaSalesOrderSyncService.php` | Persists `qty_on_shipments` and `unfilled_reason_code` on order lines |
| `AcumaticaBackorderSyncService.php` | Persists `qty_on_shipments` on backorder lines |

### Key methods

**`SalesOrderLineFulfillmentDeriver::resolveQtyOnShipments()`**
- Returns `[qty, source]` where `source` is `qty_on_shipments` or `shipped_qty_fallback`

**`SalesOrderLineFulfillmentDeriver::deriveUnfilledReasonCode()`**
- Assigns `inventory_shortage` when `QtyOnShipments = 0`, or uses Acumatica `ReasonCode` when present

**`FillRateCalculator::compute()`**
- Returns `fill_rate_pct`, `fill_rate_status`, `total_ordered_qty`, `total_shipped_qty`, `revenue_not_shipped`, `out_of_stock_line_count`

### API

`GET /api/operations/fill-rate` — product lines now include:

```json
{
  "inventory_id": "FAYWP0024",
  "order_qty": "10.0000",
  "shipped_qty": "0.0000",
  "qty_on_shipments": "0.0000",
  "line_fill_rate_pct": "0.00",
  "unfilled_reason_code": "inventory_shortage",
  "not_shipped_value": "17068.97"
}
```

`not_shipped_value` is now based on `DemandQty − QtyOnShipments`, not open qty.

### Frontend

| File | Change |
|---|---|
| `src/hooks/useOperations.ts` | Added `qty_on_shipments`, `unfilled_reason_code` to `FillRateProduct` type |
| `src/routes/app.fill-rate.tsx` | Shows "On shipments" column and unfilled reason when present |

---

## Tests

| Test file | Coverage |
|---|---|
| `FillRateCalculatorTest.php` | Rollup, approval denominator, out-of-stock counting, N/A statuses |
| `SalesOrderLineFulfillmentDeriverTest.php` | QtyOnShipments mapping, out-of-stock reason, ShippedQty fallback, ERP reason preference |
| `AcumaticaOperationsSyncTest.php` | End-to-end sync, guardrail filters, API enrichment |

Run fill-rate tests:

```bash
php artisan test --filter="FillRateCalculatorTest|SalesOrderLineFulfillmentDeriverTest|AcumaticaOperationsSyncTest::test_fill_rate"
```

---

## Deployment steps

1. Run migration:
   ```bash
   php artisan migrate
   ```

2. Re-sync fill rate for the desired date range (Administration → Operations sync, or cron):
   ```bash
   php artisan acumatica:sync-fill-rate
   ```

3. Verify guardrail counts on the latest sync log:
   ```sql
   SELECT filters FROM acumatica_sync_logs
   WHERE sync_type = 'fill_rate'
   ORDER BY started_at DESC LIMIT 1;
   ```

---

## Fields still to add (future)

From `fielsd to consider.md` — not yet implemented:

**Header (SOOrder):** `ApprovedDateTime`, `CompletedDate`, `RejectedBy`, `RejectedDateTime`

**Line (SOLine):** `ShippedQty` (kept for reference), `CancelledQty`, `UnbilledQty`, `DemandQty`, `SchedShipDate`, `PONbr`, `OrigOrderQty`, `ItemStatus`, `ItemClassID`, `QtyOnHand`, `QtyAvail`, `QtySOReserved`, `QtySOBackOrdered`

**Sub-entity:** `EPApproval` (ApprovedByID, ApproveDate, Status, Reason)

These would further improve backorder reasoning and fill-rate accuracy, especially for rejected orders where `OrderQty` has been zeroed out but `OrigOrderQty` would preserve the original demand.