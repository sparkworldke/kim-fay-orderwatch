# Acumatica Endpoint Extension Needed — for `new-prd.md`

**Audience:** Acumatica administrator / integration team
**Current endpoint:** `IpayV2` version `22.200.001`
**Prepared:** 2026-07-23, from a live probe against `https://kimfay.acumatica.com`

---

## Summary

`new-prd.md` ("Sales Order Details by Inventory Item — Back Order & Pricing
Report") asks for three things this app cannot currently deliver, because
the underlying Acumatica entities are **not exposed** on `IpayV2/22.200.001`:

| Capability | Entity needed | Live probe result |
|---|---|---|
| True Back Ordered vs. Open-Awaiting-Shipment split (`InItemPlan.PlanType = 68`) | `InItemPlan`, `SOLineSplit` | **404 Entity not found** |
| Per-line/per-order VAT detail (Net/VAT/Gross from Acumatica's own tax engine) | `SOTaxTran` | **404 Entity not found** |
| Live `Qty On Hand` / `Qty Available` / `Qty Allocated` / `Qty on PO` / Reorder Point | `InventoryItem` (+ `WarehouseDetails`), `INSiteStatus` | **404 Entity not found** |

Probe run:

```
GET /entity/IpayV2/22.200.001/InItemPlan?$top=1     -> 404 "Entity InItemPlan not found"
GET /entity/IpayV2/22.200.001/SOLineSplit?$top=1    -> 404 "Entity SOLineSplit not found"
GET /entity/IpayV2/22.200.001/SOTaxTran?$top=1      -> 404 "Entity SOTaxTran not found"
GET /entity/IpayV2/22.200.001/INSiteStatus?$top=1   -> 404 "Entity INSiteStatus not found"
GET /entity/IpayV2/22.200.001/InventoryItem?$top=1  -> 404 "Entity InventoryItem not found"
```

Note `InventoryItem` itself is 404 here too — this is the same gap
`docs/acumatica-endpoint-instructions.md` already flagged (recommending an
extension to `22.200.002`), which does not appear to have shipped on this
tenant yet. `InItemPlan` / `SOLineSplit` / `SOTaxTran` are net-new asks from
`new-prd.md` — they were never part of the earlier extension request.

Until these are published, the Streamlit app computes an **interim**
approximation instead (labelled everywhere as such — see
`src/domain/value_at_risk.py` `FORMULA_VERSION = "v1-openqty-interim"`):

- "Back Ordered" = `OpenQty`-derived (existing PRD-backorder.md logic), not
  the `InItemPlan`/`PlanType=68`-verified figure.
- VAT Net/VAT/Gross = reverse-calculated from the line `UnitPrice` and a
  configurable `VAT_RATE` (16%), not read from `SOTaxTran`.
- No live inventory columns are shown at all (no fallback is safe to
  approximate here — showing stale/fabricated stock figures next to a
  value-at-risk number would be actively misleading).

---

## What to ask the Acumatica admin to expose

Follow the same process already used for the `OpenQty`/`QtyOnHand` fix
(**Main Menu → System → Integration → Web Service Endpoints (SM207060)** →
open `IpayV2` → **Extend Endpoint** → new version, e.g. `22.200.002`):

### 1. Back-order allocation join (`InItemPlan` / `SOLineSplit`)

- Expose `SOLineSplit` as a child collection of `SOLine` (or a comparable
  join), filterable/expandable so each Details line can carry its allocation
  type.
- Expose `InItemPlan` fields needed to filter `PlanType = 68` ("SO Back
  Ordered") per line.
- If a direct REST join isn't practical, an alternative is publishing a
  **Generic Inquiry** (joining `SOLine → SOLineSplit → InItemPlan` filtered
  to `PlanType = 68`) as a contract-based API endpoint — Acumatica supports
  this natively and avoids reconstructing the join client-side.

### 2. Tax detail (`SOTaxTran`)

- Expose `SOTaxTran` (or order-level `TaxCalcMode` plus per-line tax amount)
  so Net/VAT/Gross can be read from Acumatica's own calculation instead of
  reverse-calculated.

### 3. Live inventory (`InventoryItem` / `INSiteStatus`)

- Publish `InventoryItem` per the existing recommendation in
  `docs/acumatica-endpoint-instructions.md` (`ItemStatus`, `QtyOnHand`,
  `QtyAvailable`, `WarehouseDetails`).
- Additionally expose `QtyAllocated`, `Qty on PO` (inbound), and
  `ReorderPoint`/`SafetyStock` if configured — these are required for
  `new-prd.md` FR15-FR18 and are not covered by the earlier request.

### 4. Brand classification field (open question in `new-prd.md` §11.1)

- Confirm which existing field already drives Manufactured vs. Partner
  Brand in Acumatica today (`Item Class`, a custom Attribute, Product
  Workgroup, or primary Vendor) and expose it on `InventoryItem` or
  `SalesOrder.Details`.
- Until confirmed, this app classifies brand from a **description/SKU
  pattern match** (`src/domain/brands.py`, ported from OrderWatch's
  `ProductBrandClassifier`) — not a native Acumatica field. If a native
  field already exists and disagrees with the pattern match, the pattern
  match should be retired in favor of it to avoid two competing brand
  taxonomies (per `new-prd.md`'s own guardrail).

### 5. Back Order Reason (open question in `new-prd.md` §11.2)

- Confirm who owns setting this (Purchasing / Planning / CS) and at what
  point in the process — determines whether this needs a custom attribute
  on `SOLine`/`InItemPlan` or can reuse an existing field.
- This app currently reuses the existing `ReasonCode` field on `Details`
  (when populated) and maps it onto the controlled list in
  `src/domain/reasons.py`. No new Acumatica field has been added on the
  assumption that one may already exist or is still being decided.

---

## After the extension ships

1. Update `ACUMATICA_VERSION` in `backorder-py/.env` to the new version.
2. Re-run the probe script pattern above (see
   `src/acumatica/client.py::fetch_ad_hoc_schema` /
   `AcumaticaClient._request`) against the new version to confirm
   `InItemPlan`, `SOLineSplit`, `SOTaxTran`, and `InventoryItem` resolve.
3. Swap `value_at_risk.py`'s `OpenQty`-derived eligibility for the real
   `InItemPlan.PlanType = 68` join, bump `FORMULA_VERSION`, and update the
   disclaimer text — per `new-prd.md`'s guardrail to version and document
   any change to the lost-value formula.
