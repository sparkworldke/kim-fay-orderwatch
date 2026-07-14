# Acumatica Endpoint Instructions for Kim-Fay OrderWatch

**Audience:** Acumatica administrator / integration team  
**Endpoint:** `IpayV2` version `22.200.001`  
**Base URL:** `https://kimfay.acumatica.com/entity/IpayV2/22.200.001/`  
**Prepared:** 25 June 2026  
**Application:** Kim-Fay OrderWatch (backorders, inventory, sales orders, fill rate)

---

## Summary

OrderWatch syncs data from Acumatica via the contract API. Several screens are empty or show incorrect values because **required fields are not exposed** on the custom `IpayV2/22.200.001` endpoint.

| Feature | Symptom in OrderWatch | Root cause |
|---------|----------------------|------------|
| Backorders | Sync runs but **0 lines appear**; many order-level errors | `OpenQty` and related line qty fields missing from `Details` expand |
| Inventory | **5,500 items sync** but **qty on hand = 0** for all | `QtyOnHand` / warehouse qty fields not mapped on `InventoryItem` or `StockItem` |
| Inventory failures | ~860 records failed per sync | Inactive items returned when API falls back to unfiltered `StockItem` — fixed in app; prefer server-side Active filter |

**Recommended action:** Extend the endpoint (new version e.g. `22.200.002`), add the fields below, publish, then update OrderWatch `endpoint_version` in Administration.

---

## How to extend the endpoint

1. Go to **Main Menu → System → Integration → Web Service Endpoints (SM207060)**
2. Open endpoint **IpayV2**, version **22.200.001**
3. Click **Extend Endpoint** → assign new version **22.200.002** (do not edit in place if other integrations use `22.200.001`)
4. Add the fields listed in each section below
5. Click **Validate** → fix warnings → **Publish**
6. Notify OrderWatch team to update version to `22.200.002` in app settings

### Discover what is currently exposed

Before adding fields, confirm the live schema:

```
GET /entity/IpayV2/22.200.001/SalesOrder/$adHocSchema
GET /entity/IpayV2/22.200.001/InventoryItem/$adHocSchema
GET /entity/IpayV2/22.200.001/StockItem/$adHocSchema
```

Any field OrderWatch queries that is **not** in `$adHocSchema` will cause `KeyNotFoundException` and the entire request fails.

---

## 1. Sales Order — Backorders & Fill Rate

OrderWatch does **not** filter `Status eq 'Backorder'` at order level. It fetches **all open SOs** and derives backorder lines from **line quantity fields**.

### API calls used

```
GET /entity/IpayV2/22.200.001/SalesOrder
  ?$top=100
  &$skip=0
  &$filter=OrderType eq 'SO' and Status ne 'Completed' and Status ne 'Cancelled' and Status ne 'Canceled' and Status ne 'Rejected'
  &$expand=Details
```

> **Important:** On IpayV2 `22.200.001`, line items are exposed as **`Details`**, not `DocumentDetails`. Using `DocumentDetails` causes OData expand errors.

### SalesOrder header fields to map

| Field | Required | Used for |
|-------|----------|----------|
| `OrderNbr` | Yes | Order identifier |
| `OrderType` | Yes | Filter SO only |
| `Status` | Yes | Open vs completed |
| `CustomerID` | Yes | Customer link |
| `CustomerName` | Recommended | Display in Backorders UI |
| `CurrencyID` or `CuryID` | Recommended | Revenue at risk currency |
| `ScheduledShipmentDate` | Optional | Backorder scheduling |
| `RequestedOn` | Optional | Requested delivery date |
| `Date` | Yes | Date-range syncs |

### Details (line) fields to map — **critical for backorders**

| Field | Required | Used for |
|-------|----------|----------|
| `InventoryID` | Yes | Stock item on line |
| `OrderQty` or `OrderedQty` | Yes | Ordered quantity |
| `ShippedQty` | Yes | Shipped quantity |
| **`OpenQty`** | **Yes** | **Primary backorder detection** |
| `CancelledQty` | Recommended | Derive open qty when `OpenQty` absent |
| `UsrQtyAtApproval` | Recommended | Fill rate denominator |
| `UnitPrice` | Yes | Revenue at risk |
| `WarehouseID` or `SiteID` | Optional | Warehouse display |
| `UOM` | Optional | Unit of measure |
| `LineNbr` | Optional | Line reference |
| `Description` / `TransactionDescr` | Optional | Line description |
| `Completed` | Optional | Fulfillment status |

### Backorder logic (for reference)

A line is stored as a backorder when:

- `OpenQty > 0` (or derived as `OrderQty − ShippedQty − CancelledQty`), **and**
- Line is not fully fulfilled / cancelled

If **`OpenQty` is not returned** in `Details`, OrderWatch can derive it, but only when `OrderQty` and `ShippedQty` are present and accurate.

### Verification query

Probe one known open order with backorder lines:

```
GET /entity/IpayV2/22.200.001/SalesOrder/{OrderNbr}?$expand=Details
```

Confirm each backorder line includes at minimum:

```json
{
  "InventoryID": { "value": "..." },
  "OrderQty":    { "value": 10 },
  "ShippedQty":  { "value": 4 },
  "OpenQty":     { "value": 6 },
  "UnitPrice":   { "value": 100 }
}
```

---

## 2. Inventory — Stock on Hand

### API calls used

OrderWatch prefers **active items only**:

```
GET /entity/IpayV2/22.200.001/InventoryItem
  ?$top=100
  &$skip=0
  &$filter=ItemStatus eq 'Active'
  &$expand=WarehouseDetails
```

If `InventoryItem` or the Active filter fails, the app may fall back to `StockItem` (unfiltered), which pulls **inactive** items and causes unnecessary failures.

### InventoryItem / StockItem header fields to map

| Field | Required | Used for |
|-------|----------|----------|
| `InventoryID` | Yes | Primary key |
| `Description` | Yes | Display name |
| `ItemStatus` | **Yes** | Filter Active only |
| `ItemClass` | Optional | Classification |
| `DefaultUOM` / `BaseUOM` | Optional | Unit of measure |
| `IsStockItem` | Optional | Stock vs non-stock |
| `DefaultWarehouseID` | Optional | Default site |
| `ValuationMethod` | Optional | Valuation |
| `SalesPrice` / `DefaultPrice` | Optional | Pricing |

### Quantity fields — **critical for inventory screen**

Qty is often **not** on the item header; it lives on warehouse rows. Map **both** header totals (if available) **and** warehouse detail:

| Field | Location | Required |
|-------|----------|----------|
| `QtyOnHand` | Header or warehouse row | **Yes** |
| `QtyAvailable` | Header or warehouse row | Recommended |
| `TotalQtyOnHand` | Header | Alternative total |
| `WarehouseDetails` | Child entity / expand | **Yes** if header qty empty |

### WarehouseDetails child entity

Enable expand **`WarehouseDetails`** (or equivalent inventory-by-warehouse view on your build) with at least:

| Field | Required |
|-------|----------|
| `WarehouseID` | Optional |
| `QtyOnHand` | **Yes** |
| `QtyAvailable` | Recommended |

OrderWatch sums `QtyOnHand` across all warehouse rows when header fields are empty.

### Inactive items

- Sync should only process **`ItemStatus eq 'Active'`**
- In a recent production sync: **6,360 records**, **5,500 active saved**, **~860 inactive failed**
- Ensure `ItemStatus` is mapped so the Active filter works on `InventoryItem` (preferred) or `StockItem`

### Verification query

```
GET /entity/IpayV2/22.200.001/InventoryItem?$top=5&$filter=ItemStatus eq 'Active'&$expand=WarehouseDetails
```

Confirm at least one item returns non-zero quantity, e.g.:

```json
{
  "InventoryID": { "value": "ITEM-001" },
  "ItemStatus":  { "value": "Active" },
  "QtyOnHand":   { "value": 125 },
  "WarehouseDetails": [
    {
      "WarehouseID": { "value": "MAIN" },
      "QtyOnHand":   { "value": 125 }
    }
  ]
}
```

If `QtyOnHand` is missing everywhere, the inventory screen will show **0** even when sync completes successfully.

---

## 3. Errors seen in production logs

### OData `KeyNotFoundException` on expand

```
The given key was not present in the dictionary.
at Microsoft.Data.OData.Query.SyntacticAst.ExpandBinder.GenerateExpandItem
```

**Cause:** `$expand` or `$select` references a field/child entity not registered on the endpoint.

**Common triggers:**
- `$expand=DocumentDetails` → use **`Details`** on IpayV2
- `$select` includes unmapped fields (`CurrencyID`, `CustomerName`, `QtyOnHand`, etc.)
- `$expand=WarehouseDetails` when warehouse child entity not mapped

### Backorder sync: high failed count, zero lines in UI

Example log: **Records 1127, Success 0, Failed 1083**

**Cause:** Database upsert errors when writing backorder lines (missing app migrations) **or** no lines matching because `OpenQty` / qty fields absent from API response.

**After endpoint fix:** Re-run backorder sync; Success should show **number of lines saved**, not orders fetched.

---

## 4. Safe minimum queries (known-good baselines)

Use these to test after extending the endpoint.

### Sales order — backorders

```
GET /entity/IpayV2/22.200.002/SalesOrder
  ?$top=5
  &$filter=OrderType eq 'SO' and Status ne 'Completed'
  &$expand=Details
```

### Inventory — active with qty

```
GET /entity/IpayV2/22.200.002/InventoryItem
  ?$top=5
  &$filter=ItemStatus eq 'Active'
  &$expand=WarehouseDetails
```

Avoid `$select` until all listed fields are confirmed in `$adHocSchema`. Add fields incrementally if debugging.

---

## 5. Checklist for Acumatica team

- [ ] Extend `IpayV2` to version `22.200.002` (or agreed version)
- [ ] **SalesOrder → Details:** map `InventoryID`, `OrderQty`, `ShippedQty`, **`OpenQty`**, `UnitPrice`
- [ ] **SalesOrder header:** map `CustomerName`, `CurrencyID`, `ScheduledShipmentDate`, `RequestedOn`
- [ ] Confirm line expand name is **`Details`** (not `DocumentDetails`)
- [ ] **InventoryItem:** map `ItemStatus`, `QtyOnHand`, `QtyAvailable`
- [ ] **InventoryItem → WarehouseDetails:** map `QtyOnHand` per warehouse
- [ ] Verify `ItemStatus eq 'Active'` filter works on `InventoryItem`
- [ ] Publish endpoint and share new version number with OrderWatch team
- [ ] Provide sample JSON for one backorder SO and one inventory item with non-zero qty

---

## 6. OrderWatch team actions after Acumatica publish

1. Update **Administration → Acumatica settings** → endpoint version to `22.200.002`
2. On server: `git pull` and `php artisan migrate --force`
3. Re-run **Inventory sync** — expect ~5,500 active items, qty on hand populated
4. Re-run **Backorder sync** — expect backorder lines on Backorders page
5. If issues remain, export one `raw_payload` from `acumatica_inventory_items` and one probed SO for joint review

---

## Contact / references

- OrderWatch production API: `https://dating.sparkworld.co.ke/backend/public/api`
- Internal integration notes: `acumatica-integration-guide(1-09).md` (Section: OData KeyNotFoundException)
- OrderWatch sync types: `inventory`, `backorders`, `sales_orders`, `fill_rate`