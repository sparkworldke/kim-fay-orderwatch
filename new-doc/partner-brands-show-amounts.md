# Partner Brands on Operations Dashboard — Amounts, SKUs & Skipped Lines

**Product:** Kim-Fay Sight — Operations Dashboard (`/app`)  
**Reference UI:** Open Orders by Date (screenshot 08-06-2026) with **Brand = Dove**  
**Status:** Spec — display flow  
**Users:** Partner Brands team, operations, commercial (amount visibility by role)

---

## What the screenshot shows today

**Path:** Dashboard → Sales Orders → filters (Brand: Dove) → Open Orders by Date → expand a day → expand **Shipping** (or Pending Approval) → SO table:

| SO Number | Customer | Amount | Quantity |
|---|---|---|---|

Example: `SO370527` · Majid Al Futtaim … · KES 29,341.38 · 60

**Gaps:**

1. Amount/qty on the SO row are **header totals** (whole order), not always the **brand-filtered** slice (e.g. Dove-only).  
2. There is **no accordion on each SO** to list SKUs.  
3. There is **no view of skipped / shortfall** vs original ordered SKUs or quantities.

---

## Goal

When Brand (and other filters) are applied on the main Operations Dashboard:

1. SO list **amount** and **quantity** reflect the **filtered brand’s contribution** on that order (or show both: header total + brand slice).  
2. Each SO row is an **accordion**: expand to see SKU lines for that order.  
3. Accordion also shows **Skipped / shortfall** vs original order (SKU removed/cancelled, or qty reduced).  
4. Totals recompute when Brand / period / status filters change.

---

## Display flow (matches current page structure)

```text
Operations Dashboard
│
├─ Filters: Customer · Status · Segment · Brand (e.g. Dove) · Consultant
│
├─ KPI strip (Total / Open / Completed / …)  ← brand-scoped when brand set
├─ Order Volume Trend
├─ Cumulative Orders by Month
│
└─ Open Orders by Date
     │
     ├─ Day row (e.g. 6 August 2026)  [expand]
     │     │
     │     ├─ Pending Approval (N)  [accordion]
     │     │     └─ SO list
     │     │
     │     └─ Shipping (N)  [accordion]
     │           └─ SO list  ← each SO is expandible (NEW)
     │                 │
     │                 ├─ SO header: SO# · Customer · Amount · Qty
     │                 │     (prefer brand-scoped amount/qty when Brand filter on)
     │                 │
     │                 └─ SO accordion body (NEW)
     │                       ├─ A. SKUs on this order (matching brand filter)
     │                       └─ B. Skipped / shortfall vs original order
```

---

## 1. SO list row (status panel)

Keep current columns; tighten meaning when Brand is set:

| Column | Without brand filter | With brand filter (e.g. Dove) |
|---|---|---|
| SO Number | Link to order | Same |
| Customer | Name | Same |
| Amount | Order header total | **Brand line amount sum** on that SO (optional secondary: full order total in muted text) |
| Quantity | Sum line order qty (or header) | **Brand line qty sum** |

Optional badge on row: `3 Dove lines` / `1 skipped`.

---

## 2. SO accordion — expand one sales order

Click chevron on the SO row (not only the SO link). Body has **two sections**.

### A. SKUs on this order (in filter)

Lines that belong to the selected brand(s). If no brand filter: all lines (or all partner lines per product rule).

| Column | Source |
|---|---|
| Inventory ID | Line inventory |
| Product / description | Line description |
| Brand | Catalogue brand |
| Ordered qty | `order_qty` |
| Shipped / on shipments | `shipped_qty` / `qty_on_shipments` |
| Open qty | `open_qty` |
| Unit price | `unit_price` |
| Line amount | `order_qty × unit_price` (or open × price if “at risk” mode) |
| Status | Line fulfilment / cancelled flag |

**Footer for section A:**  
`Subtotal qty · Subtotal amount` = sum of visible brand lines → must match SO row brand amount/qty.

### B. Skipped / shortfall vs original order

“Skipped” = not fully delivered as originally ordered. Show clearly for partner follow-up.

| Case | How to detect | Show as |
|---|---|---|
| **SKU cancelled / removed** | Line cancelled, or qty cancelled ≥ ordered, or line no longer active | Tag **Skipped SKU** — inventory, original ordered qty, amount voided |
| **Qty reduced** | `order_qty` still on line but open/backorder/cancelled partial | Tag **Qty shortfall** — ordered vs shipped vs open/cancelled |
| **Not in brand filter but on SO** | Other brands on same SO when Dove is filtered | Optional third strip **Other brands on SO** (muted) — so user sees order is multi-brand |

**Section B columns (minimum):**

| Column | Content |
|---|---|
| Inventory ID | SKU |
| Product | Description |
| Kind | `Skipped SKU` · `Qty shortfall` · `Cancelled` |
| Original ordered qty | Original / order_qty |
| Delivered / shipped qty | What left the warehouse |
| Skipped / open / cancelled qty | Remainder not fulfilled |
| Unit price | If available |
| Amount at risk / voided | skipped_qty × unit_price |

**Footer for section B:**  
`Skipped qty · Amount at risk` for this SO under current filters.

**Empty states:**

- A empty + brand filter: “No [Dove] lines on this SO” (order may appear only if inclusion rule uses any line — prefer **only list SOs that have ≥1 brand line**).  
- B empty: “No skipped SKUs or shortfall on this order.”

---

## 3. Brand filter dynamics (e.g. Dove)

| User action | Result |
|---|---|
| Set Brand = Dove | Day counts, status buckets, SO lists, amounts/qty = Dove-related only |
| Expand Shipping | SO list only orders with ≥1 Dove line that day/status |
| Expand SO370527 | Section A = Dove SKUs + amounts; Section B = Dove skipped/shortfall |
| Clear Brand | Back to all brands; accordion still shows all SKUs + skipped for whole order |
| Multi-brand (Dove + Lux) | SO included if any selected brand; Section A only selected brands’ lines |

KPI cards and “Total SO calculation” strip should stay consistent with the same brand scope (or show a note: “KPIs brand-scoped”).

---

## 4. Definitions (single source of truth)

| Term | Definition |
|---|---|
| **Ordered qty** | Acumatica line `order_qty` |
| **Shipped qty** | `shipped_qty` (and/or qty on shipments per existing fill-rate rules) |
| **Open qty** | `open_qty` still to ship |
| **Cancelled qty** | `cancelled_qty` |
| **Line amount** | `order_qty × unit_price` (ordered value) |
| **Brand amount on SO** | Sum of line amounts for lines whose inventory brand ∈ filter |
| **Brand qty on SO** | Sum of ordered (or open) qty for those lines |
| **Skipped SKU** | Line fully cancelled / zeroed / no longer fulfilling original intent |
| **Qty shortfall** | Ordered − shipped − cancelled > 0 (still short) **or** cancelled portion of original |
| **Excluded statuses** | Rejected / cancelled headers out of fill-rate; still listable under their status accordion |

Align shortfall math with existing `SalesOrderLineFulfillmentDeriver` / backorder open qty logic so dashboard matches Products Not Delivered / fill-rate.

---

## 5. Interaction examples

### Example — Brand Dove, Shipping, SO370515

1. Filters: Brand **Dove**, date range 1–6 Aug.  
2. Open **6 August** → **Shipping (22)**.  
3. Row: `SO370515` · Naivasha Self Service Store · **KES 1,151,214.68** · **2,209**  
   - If these are full-order totals today, after change they become **Dove slice** (or dual display).  
4. Expand SO accordion:

**A. Dove SKUs on order**

| Inventory | Product | Ordered | Open | Amount |
|---|---|---|---:|---:|
| DV-… | Dove … | 100 | 40 | KES … |
| … | … | … | … | … |
| **Subtotal** | | **…** | **…** | **KES …** |

**B. Skipped / shortfall**

| Inventory | Kind | Original | Shipped | Skipped | Amount at risk |
|---|---|---:|---:|---:|---:|
| DV-… | Qty shortfall | 100 | 60 | 40 | KES … |
| DV-… | Skipped SKU | 24 | 0 | 24 | KES … |
| **Subtotal** | | | | **…** | **KES …** |

5. User sees why quantity on the header does not match “what Dove still needs” without leaving the dashboard.

---

## 6. Layout detail (SO accordion UI)

```text
▼ SO370515   Naivasha Self Service Store        KES 1,151,214.68    2,209
  ┌─────────────────────────────────────────────────────────────┐
  │ SKUs on order (Dove) · 8 lines                              │
  │ [table A]                                                   │
  │ Subtotal: 2,100 qty · KES 1,100,000                         │
  ├─────────────────────────────────────────────────────────────┤
  │ Skipped / shortfall vs original · 2 lines                   │
  │ [table B — amber/red styling]                               │
  │ Skipped: 109 qty · KES 51,214 at risk                       │
  └─────────────────────────────────────────────────────────────┘
```

- Lazy-load lines when accordion opens (avoid fetching all SO lines for 22 rows at once).  
- SO# link still navigates to full order page; chevron only toggles accordion.  
- Respect **mask revenue**: hide/mask Amount columns for roles with `mask_revenue`.

---

## 7. API (suggested)

**List SOs in status/day (existing + fields):**

```json
{
  "order_nbr": "SO370515",
  "customer_name": "…",
  "amount": 1151214.68,
  "quantity": 2209,
  "brand_amount": 1100000,
  "brand_quantity": 2100,
  "brand_line_count": 8,
  "skipped_line_count": 2,
  "skipped_qty": 109,
  "skipped_amount": 51214
}
```

**On expand — GET lines for SO (+ brand filter):**

```json
{
  "order_nbr": "SO370515",
  "on_order": [
    {
      "inventory_id": "…",
      "description": "…",
      "brand": "Dove",
      "order_qty": 100,
      "shipped_qty": 60,
      "open_qty": 40,
      "cancelled_qty": 0,
      "unit_price": 400,
      "line_amount": 40000
    }
  ],
  "skipped": [
    {
      "inventory_id": "…",
      "kind": "qty_shortfall",
      "order_qty": 100,
      "shipped_qty": 60,
      "skipped_qty": 40,
      "unit_price": 400,
      "amount_at_risk": 16000
    }
  ]
}
```

---

## 8. Acceptance criteria

- [ ] With Brand = Dove, Open Orders by Date SO lists only orders that contain Dove lines.  
- [ ] SO row amount/qty match Dove contribution (or dual display is clearly labelled).  
- [ ] Expanding an SO shows **SKU table** for lines on the order (brand-filtered).  
- [ ] Same accordion shows **Skipped SKU / qty shortfall** vs original ordered qty.  
- [ ] Section A subtotals reconcile to brand amount/qty on the SO row.  
- [ ] Section B amounts use skipped/open/cancelled qty × unit price.  
- [ ] Accordion loads lines on expand only; works for Shipping and Pending Approval panels.  
- [ ] Clearing brand filter shows full-order lines; skipped section still works.  
- [ ] Masked-revenue roles see qty/skipped qty without leaking KES.  
- [ ] Multi-select brands behaves as union of selected brands.

---

## 9. Phasing

| Phase | Deliver |
|---|---|
| **P0** | SO accordion: list SKUs (qty + amount) for brand filter |
| **P0** | Brand-scoped amount/qty on SO row when Brand filter active |
| **P1** | Skipped / shortfall section (cancelled + open shortfall) |
| **P2** | Lazy API + dual display full order vs brand slice |
| **P3** | “Other brands on this SO” muted strip; export line drill |

---

## 10. Related code anchors

| Area | Location |
|---|---|
| Dashboard page | `src/routes/app.index.tsx` — `DailyOrderTable`, `DayRowOrdersPanel`, `StatusOrderList` |
| SO list row today | Flat table: SO · Customer · Amount · Quantity (no line expand) |
| Brand filter | Dashboard filters + backend KPI/order queries join inventory brand |
| Line fulfilment | `SalesOrderLineFulfillmentDeriver`, open/cancelled/shipped on `acumatica_sales_order_lines` |
| Amount mask | `MaskedKES` / `mask_revenue` capabilities |

---

## Out of scope

- Redesigning KPI strip layout  
- Goods Lost in Transit tab (can reuse same SO accordion later)  
- Changing Acumatica sync rules (uses existing line fields)  
- Manufactured production MSI dashboard  
