# Acumatica SalesOrder Endpoint — Implementation Guide
### Fill Rate, Backorders, Line Item Import & Inventory Chunking

---

## 1. Endpoint Under Analysis

```
GET https://kimfay.acumatica.com/entity/IpayV2/22.200.001/SalesOrder/
    ?$expand=Details
    &$filter=CustomerID eq 'CUST101239'
      and Date ge datetimeoffset'2026-06-19'
      and Date le datetimeoffset'2026-06-19'
```

**What this tells us:**

| Component | Value | Notes |
|---|---|---|
| Endpoint name | `IpayV2` | Custom endpoint — version `22.200.001` |
| Entity | `SalesOrder` | Top-level SO header |
| Expand | `Details` | Pulls line items inline |
| Filter | `CustomerID`, `Date` range | Single-day, single-customer query |

---

## 2. What the Current Endpoint Returns (Expected Schema)

Based on the endpoint version `22.200.001` and `$expand=Details`, a successful call returns:

```json
[
  {
    "OrderNbr": { "value": "SO-001234" },
    "OrderType": { "value": "SO" },
    "Status": { "value": "Open" },
    "Date": { "value": "2026-06-19T00:00:00+00:00" },
    "CustomerID": { "value": "CUST101239" },
    "CustomerName": { "value": "..." },
    "OrderTotal": { "value": 125000.00 },
    "CuryID": { "value": "KES" },
    "Details": [
      {
        "LineNbr": { "value": 1 },
        "InventoryID": { "value": "KF-TISSUE-200" },
        "TranDesc": { "value": "Fay Toilet Tissue 200s" },
        "OrderQty": { "value": 500 },
        "OpenQty": { "value": 320 },
        "ShippedQty": { "value": 180 },
        "UnitPrice": { "value": 450.00 },
        "ExtPrice": { "value": 225000.00 },
        "UOM": { "value": "PCS" },
        "SiteID": { "value": "MAIN" }
      }
    ]
  }
]
```

### Fields Currently Exposed vs. Needed

| Field | In `Details` now? | Needed for? | Action |
|---|---|---|---|
| `OrderQty` | ✅ likely | Fill rate denominator | Verify it's mapped |
| `OpenQty` | ✅ likely | Backorder quantity | Verify it's mapped |
| `ShippedQty` | ✅ likely | Fill rate numerator | Verify it's mapped |
| `CancelledQty` | ❓ check | Backorder analysis | Add to endpoint |
| `LineType` | ❓ check | Filter non-stock lines | Add to endpoint |
| `Completed` | ❓ check | Line closure flag | Add to endpoint |
| `ApprovedByID` | ❌ header | Approval audit | Add to header |
| `ApprovedDateTime` | ❌ header | Approval timestamp | Add to header |
| `UsrOriginalQty` | ❌ custom | Pre-approval qty snapshot | DAC extension needed |
| `UsrQtyAtApproval` | ❌ custom | Approval-time qty | DAC extension needed |
| `ItemStatus` (INItem) | ❌ | Active product filter | Inventory endpoint |

---

## 3. New "Backorders Imported" Status

### What It Means

When a Sales Order has lines that cannot be fulfilled from available stock, those lines transition to a **Backorder** state. We need to:

1. Track this as a named status within the import pipeline (not just an Acumatica status)
2. Capture the `OpenQty` at the point of approval to measure true backorder volume

### Proposed Status Lifecycle

```
SO Created → Lines Added (UsrOriginalQty captured)
         ↓
    Approval Granted (UsrQtyAtApproval captured per line)
         ↓
    Shipment Processed
         ↓
    [ShippedQty < UsrQtyAtApproval] → Status = "Backorders Imported"
    [ShippedQty = UsrQtyAtApproval] → Status = "Fully Fulfilled"
```

### Implementation: Status Derivation (No Acumatica Customisation Needed)

Rather than modifying Acumatica's native workflow (which requires development effort and testing), derive the status **in your import layer**:

```python
def derive_line_status(line: dict) -> str:
    order_qty      = line.get("OrderQty", 0)
    shipped_qty    = line.get("ShippedQty", 0)
    open_qty       = line.get("OpenQty", 0)
    cancelled_qty  = line.get("CancelledQty", 0)
    completed      = line.get("Completed", False)

    if completed and shipped_qty >= order_qty:
        return "Fully Fulfilled"
    elif open_qty > 0 and shipped_qty < order_qty:
        return "Backorders Imported"
    elif cancelled_qty > 0 and shipped_qty == 0:
        return "Cancelled"
    elif shipped_qty > 0 and open_qty > 0:
        return "Partially Shipped — Backorder Pending"
    else:
        return "Pending Shipment"
```

> **Note:** Store this derived status in your local data warehouse or reporting layer, not in Acumatica. This avoids workflow conflicts and keeps the source system clean.

---

## 4. Fill Rate Calculation

### Formula

```
Fill Rate (%) = (ShippedQty ÷ UsrQtyAtApproval) × 100
```

If `UsrQtyAtApproval` is not yet available via a DAC extension, use `OrderQty` as the denominator for now:

```
Fill Rate (%) = (ShippedQty ÷ OrderQty) × 100
```

### Per-Line Calculation

```python
def calculate_fill_rate(line: dict) -> float:
    shipped   = line.get("ShippedQty", 0)
    approved  = line.get("UsrQtyAtApproval") or line.get("OrderQty", 0)
    
    if approved == 0:
        return 0.0
    return round((shipped / approved) * 100, 2)
```

### SO-Level Aggregate Fill Rate

```python
def so_fill_rate(details: list) -> float:
    total_shipped  = sum(d.get("ShippedQty", 0) for d in details)
    total_approved = sum(
        d.get("UsrQtyAtApproval") or d.get("OrderQty", 0) for d in details
    )
    if total_approved == 0:
        return 0.0
    return round((total_shipped / total_approved) * 100, 2)
```

### Backorder Volume

```python
def backorder_qty(line: dict) -> float:
    approved = line.get("UsrQtyAtApproval") or line.get("OrderQty", 0)
    shipped  = line.get("ShippedQty", 0)
    return max(approved - shipped, 0)
```

---

## 5. Inventory Import — Chunked & Active Products Only

### Why Chunking

The Acumatica `InventoryItem` endpoint can return thousands of records. Pulling everything in one call risks:
- API timeout (default 30s on many configurations)
- Memory pressure in the import layer
- Rate limiting / throttling

### Recommended Endpoint

```
GET https://kimfay.acumatica.com/entity/IpayV2/22.200.001/InventoryItem/
    ?$filter=ItemStatus eq 'Active'
    &$top=100
    &$skip=0
    &$select=InventoryID,InventoryCD,Descr,ItemClassID,ItemStatus,
             BaseUnit,SalesUnit,LastStdCost,ItemType,ValMethod
```

### Chunking Logic

```python
import requests
import time

BASE_URL   = "https://kimfay.acumatica.com/entity/IpayV2/22.200.001"
CHUNK_SIZE = 100   # Records per page
MAX_RETRIES = 3
DELAY_SECS  = 1.5  # Polite delay between pages

def fetch_active_inventory(session: requests.Session) -> list:
    all_items = []
    skip = 0

    while True:
        url = (
            f"{BASE_URL}/InventoryItem/"
            f"?$filter=ItemStatus eq 'Active'"
            f"&$top={CHUNK_SIZE}"
            f"&$skip={skip}"
            f"&$select=InventoryID,InventoryCD,Descr,ItemClassID,"
            f"ItemStatus,BaseUnit,SalesUnit,LastStdCost,ItemType"
        )

        for attempt in range(MAX_RETRIES):
            try:
                resp = session.get(url, timeout=30)
                resp.raise_for_status()
                break
            except requests.RequestException as e:
                if attempt == MAX_RETRIES - 1:
                    raise
                time.sleep(2 ** attempt)   # exponential backoff

        data = resp.json()
        if not data:
            break   # No more records

        all_items.extend(data)
        print(f"  Fetched {skip + len(data)} items so far...")

        if len(data) < CHUNK_SIZE:
            break   # Last page

        skip += CHUNK_SIZE
        time.sleep(DELAY_SECS)

    print(f"Total active inventory items fetched: {len(all_items)}")
    return all_items
```

### Active Product Filter

The filter `ItemStatus eq 'Active'` on `INItem.ItemStatus` ensures only sellable, active SKUs are imported. This excludes:

| Excluded Status | Reason |
|---|---|
| `Inactive` | Discontinued or suspended items |
| `ToDelete` | Staged for removal |
| `NoSales` | Items blocked from SO lines |
| `NoPurchases` | Procurement-only items |

---

## 6. Guardrails

### 6.1 API Guardrails

| Guardrail | Implementation |
|---|---|
| **Timeout on every call** | Always set `timeout=30` on requests |
| **Exponential backoff** | Retry up to 3× with 1s, 2s, 4s delays |
| **Max pages cap** | Set a hard stop at e.g. 500 pages to prevent infinite loops |
| **Chunk size** | Never exceed `$top=200` — Acumatica throttles above this |
| **Date range mandatory** | Reject any SO import call missing a `Date` filter |
| **Customer filter mandatory** | Reject inventory pulls scoped to a customer without ID |

```python
GUARDRAILS = {
    "max_chunk_size":    200,
    "max_pages":         500,
    "request_timeout":   30,
    "retry_limit":       3,
    "inter_page_delay":  1.5,   # seconds
    "require_date_filter": True,
}

def validate_so_request(params: dict):
    if GUARDRAILS["require_date_filter"]:
        if "Date" not in params.get("filter", ""):
            raise ValueError("SO import requires a Date filter to prevent full-table scans.")
```

### 6.2 Data Quality Guardrails

```python
def validate_line_item(line: dict, so_nbr: str, line_nbr: int) -> list[str]:
    warnings = []

    if line.get("OrderQty", 0) <= 0:
        warnings.append(f"SO {so_nbr} Line {line_nbr}: OrderQty is zero or missing")

    if line.get("ShippedQty", 0) > line.get("OrderQty", 1):
        warnings.append(f"SO {so_nbr} Line {line_nbr}: ShippedQty exceeds OrderQty")

    if not line.get("InventoryID"):
        warnings.append(f"SO {so_nbr} Line {line_nbr}: Missing InventoryID — line skipped")

    if line.get("UnitPrice", 0) <= 0:
        warnings.append(f"SO {so_nbr} Line {line_nbr}: Zero unit price — check if free-of-charge")

    return warnings
```

### 6.3 Fill Rate Guardrails

```python
def safe_fill_rate(shipped: float, approved: float) -> float:
    if approved <= 0:
        return None    # Cannot compute — flag for review, do not default to 0 or 100
    rate = (shipped / approved) * 100
    if rate > 100:
        return 100.0   # Cap at 100% (over-delivery edge case)
    return round(rate, 2)
```

> Never default a missing denominator to `0%` or `100%` — flag it as `NULL` in the data model so dashboards surface it as a data gap, not a metric.

### 6.4 Inventory Import Guardrails

```python
INVENTORY_GUARDRAILS = {
    "allowed_statuses":   ["Active"],
    "excluded_item_types": ["Service", "NonStockItem"],   # Optional: exclude non-physical SKUs
    "max_cost_delta_pct": 50,   # Alert if LastStdCost changes >50% vs prior load
}

def filter_inventory_item(item: dict) -> bool:
    status = item.get("ItemStatus", {}).get("value", "")
    item_type = item.get("ItemType", {}).get("value", "")
    
    if status not in INVENTORY_GUARDRAILS["allowed_statuses"]:
        return False
    if item_type in INVENTORY_GUARDRAILS["excluded_item_types"]:
        return False
    return True
```

---

## 7. Endpoint Test Checklist

Before implementing in production, validate the endpoint against these checks:

```
[ ] 1. Authenticate — confirm session/cookie or OAuth token is valid
[ ] 2. GET single SO with $expand=Details — confirm Details array is populated
[ ] 3. Check all required fields present in Details:
        OrderQty, OpenQty, ShippedQty, CancelledQty, InventoryID,
        UOM, UnitPrice, ExtPrice, LineType, Completed
[ ] 4. Confirm Date filter works — response contains only 2026-06-19 orders
[ ] 5. Confirm CustomerID filter — all records have CustomerID = CUST101239
[ ] 6. Test $skip + $top pagination on inventory endpoint (page 1 and 2)
[ ] 7. Test ItemStatus = 'Active' filter — no inactive items in response
[ ] 8. Confirm ApprovedByID and ApprovedDateTime at header level
[ ] 9. Simulate a line with OpenQty > 0 — confirm backorder status derives correctly
[ ] 10. Confirm fill rate = ShippedQty / OrderQty matches expected value manually
```

---

## 8. Recommended Additional Endpoint Fields to Expose

Open your endpoint in **Acumatica → System → Customisation → Endpoint** and add:

### SalesOrder header
- `ApprovedByID`
- `ApprovedDateTime`
- `Approved` (boolean)
- `Hold`

### Details (SOLine)
- `CancelledQty`
- `LineType`
- `Completed`
- `ReasonCode`
- `POSource`
- `DemandQty`
- `BackorderAllowed`

### Optional custom fields
- `UsrOriginalQty` — qty when line was first saved
- `UsrQtyAtApproval` — qty snapshotted by workflow action on approval
- `UsrInitialLineCount` — header-level count of lines at creation

---

## 9. Summary Roadmap

| Phase | What | Effort |
|---|---|---|
| **Phase 1 — Now** | Test endpoint, verify field coverage, derive backorder status in import layer | Low |
| **Phase 2 — Short term** | Expose missing fields (`CancelledQty`, `Completed`, approval fields) in endpoint editor | Low |
| **Phase 3 — Medium term** | Build chunked inventory import with active filter and guardrails | Medium |
| **Phase 4 — Later** | DAC extensions for `UsrOriginalQty` and `UsrQtyAtApproval` for precise fill rate | High |

---

*Prepared for Kim-Fay East Africa — Acumatica IpayV2 endpoint v22.200.001*n