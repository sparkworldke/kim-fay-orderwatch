# Backorder observations — translated to current OrderWatch setup

**Date:** 2026-07-23 (screenshots) / written up 2026-07-24  
**Status:** Consolidated observation log — original field notes, screenshot mapping, Unbilled research (Acumatica API/community), resolution UX, and follow-up Q&A (what is resolved vs open; morning→evening amount drop).

**Screenshots:**

| File | What it shows |
|------|----------------|
| `backorder-py/Sales-Orders-07-23-2026_06_00_PM.png` | Acumatica **Sales Orders** list, filter `Status = Back Order`, Created This Month. Order-level grid (Ordered Qty, Unbilled, totals, etc.). |
| `backorder-py/Sales-Orders-l07-23-2026_06_00_PM.png` | **SO367051** (Stage Mattresses) detail → **Details** lines: Inventory ID, Quantity, Qty on Shipments, **Open Qty**, prices, Shipping Rule = Back Order Allowed. |

**Related systems:**

| Layer | What it is today |
|-------|------------------|
| OrderWatch production | Laravel `AcumaticaBackorderSyncService` → `acumatica_backorder_lines` + `backorder_resolutions` → `/api/operations/backorders*` → `/app/backorders` |
| Acumatica API | Contract REST `IpayV2` (typically `22.200.001`), `$expand=Details` on `SalesOrder` |
| Streamlit probe (`backorder-py/`) | Same OpenQty-derived rules, for analysis; not the production UI |

This note is the **single source** for these observations: raw notes → API/field translation → Unbilled deep dive → product gaps → “does this resolve my issues?” → **morning high / evening lower** amount behaviour.

---

## 0. Original field notes (raw)

```
SO366417 - show backord but currently has been fully delivered

unbilled column

Open Quantity on shipment

Sometimes item can be on backorder then delivered so we need to see the
resolved timeline how long did it take to be resolved when the update on back order

Add a filter resolved in a Day, 2,3 increment till month
(Group by 1 Day, 2-7, 8-14, 15-21, 22-30/31) 1 Week, 2 Weeks, 3 Weeks, Month, More than a Month
— suggest the best user experience

Manufactured products we can have them resolved soonest but for partner brands takes time
Think on how we can have the 2 calculation

on the resolved tab also add the filter and stat cards and remove the cost
from back order if the product is resolved

on the backorder sync update the SO and move to resolved
```

---

## 1. Screenshot ground truth (what Acumatica is showing)

### 1.1 List — “BACK ORDERS” (`Sales-Orders-07-23-2026_06_00_PM.png`)

- Inquiry: Sales Orders → **BACK ORDERS** tab / filter `Status = Back Order`, `Order Type = SO`, Created On = This Month.
- Example rows include **SO366417** (Elburgon Bids Store Ltd), Ordered Qty **235**, status still **Back Order**.
- Far-right numeric columns on this GI typically include **order totals** and an **Unbilled** money column (not qty). On SO366417 the pattern called out in review is “looks like Back Order in the list, but business says it is already fully delivered.”

**Key takeaway:** header **Status = Back Order** is an *order-level workflow label*. It is **not** the same as “this line still has open/unshipped qty.” OrderWatch must not treat header status alone as the backorder truth.

### 1.2 Line detail — SO367051 (`Sales-Orders-l07-23-2026_06_00_PM.png`)

- Header: Status **Back Order**, Ordered Qty **1,793**, Order Total / Disc Total visible.
- Line grid (Details) is where truth lives for our app:
  - **Quantity** (`OrderQty`) — ordered
  - **Qty on Shipments** (`QtyOnShipments`) — on shipment documents / delivered allocation
  - **Open Qty** (`OpenQty`) — remaining open on the line (primary backorder remainder)
  - Shipping Rule often **Back Order Allowed** (policy flag, not “this line is currently short”)

Many lines show Quantity ≈ Open Qty with little or no Qty on Shipments (true open backorder). Others can show Open Qty = 0 once shipped/closed.

---

## 2. Field translation: Unbilled vs Open Qty vs Status

### 2.1 Glossary (Acumatica UI → API → OrderWatch)

| Acumatica UI (screenshots) | Likely API field (IpayV2) | Level | What it means | Stored / used in OrderWatch today? |
|----------------------------|---------------------------|-------|---------------|--------------------------------------|
| **Status = Back Order** | `Status` | Order header | Workflow status of the whole SO | Yes — stored as `order_status` on backorder lines; **not** the sole classifier |
| **Ordered Qty** (list) | `OrderedQty` | Header | Sum of line ordered qty | Used indirectly via lines |
| **Quantity** (line) | `OrderQty` | Line | Ordered units for that SKU | Yes → `order_qty` |
| **Qty on Shipments** | `QtyOnShipments` | Line | Qty on shipment docs (IpayV2’s reliable “delivered” signal when `ShippedQty` is missing) | Yes → `qty_on_shipments` (+ often copied into `shipped_qty`) |
| **Open Qty** / Open Quantity | **`OpenQty`** (also `OpenLineQty` fallback) | **Line** | Units still open on the SO line — **primary backorder remainder** | **Yes → `open_qty` / `backorder_qty`** — this is the production definition |
| **Unbilled** (list column) | Usually **Unbilled Amount** (money) | Header GI and/or line | Value **not yet invoiced** | **Line `UnbilledAmount` is on the API payload but not mapped** (see §2.3) |
| Shipping Rule “Back Order Allowed” | `ShippingRule` | Line | Customer/item shipping policy | Not used for classification |

### 2.2 Open Qty inside a Back Order SO — **yes, exposed and used**

Confirmed on live IpayV2 samples (e.g. `backend/storage/app/SO359099-raw.json`):

```json
"OpenQty": { "value": 0 },
"OrderQty": { "value": 150 },
"QtyOnShipments": { "value": 150 },
"UnbilledAmount": { "value": 0 }
```

**Production rules** (`SalesOrderLineFulfillmentDeriver`):

1. Prefer Acumatica **`OpenQty` when the field is present, including explicit `0`**.
2. Only if `OpenQty` is omitted: derive `max(OrderQty − shipped − cancelled, 0)`.
3. **Shipped** prefers `ShippedQty` when present; otherwise **`QtyOnShipments`** (IpayV2 often omits `ShippedQty`).
4. A line is an **active backorder** only if `isBackorderLine()` passes:
   - effective open qty > 0, and
   - fulfillment status ∈ `Backorders Imported` | `Partially Shipped — Backorder Pending` | `Pending Shipment`, and
   - not `Fully Fulfilled` / `Cancelled`.

```
revenue_at_risk = open_qty (or backorder_qty fallback) × unit_price
```

**Important:** We do **not** require header `Status eq 'Back Order'`. Sync pulls open non-terminal SOs and keeps lines by **line OpenQty + fulfillment status**. That is deliberate: status-only filters miss open qty on other open statuses and can keep “Back Order” headers that have no open lines.

### 2.3 Unbilled — what it is, and how to translate it

**Unbilled ≠ Open Qty.**

| Concept | Question it answers | Unit | Use for “is this still a backorder?” |
|---------|---------------------|------|--------------------------------------|
| **Open Qty** | Has stock still not shipped/closed on the line? | Qty | **Yes — this is our definition** |
| **Unbilled Amount** | Has the customer not yet been invoiced for this value? | Money (KES) | **No** — billing lag after shipment is normal |

**API exposure (confirmed):**

- **Line-level `UnbilledAmount`** appears on IpayV2 `SalesOrder.Details` in raw payloads (see SO359099 above). `inspect_so_raw.php` already lists `UnbilledQty` / `BilledQty` as fields to print *if present*.
- OrderWatch **does not currently map** `UnbilledAmount` / `UnbilledQty` into `acumatica_sales_order_lines` or `acumatica_backorder_lines`.
- Header-level “Unbilled” on the GI is typically a **money** rollup (order total − billed). Header `OrderTotal` is stored; a dedicated header Unbilled column is **not** currently persisted.

**How to use Unbilled if we add it later:**

| Signal | Interpretation |
|--------|----------------|
| `OpenQty > 0` | Still an operational backorder (at risk of non-delivery) |
| `OpenQty = 0` and `UnbilledAmount > 0` | Goods may be shipped/closed but **not yet invoiced** — billing follow-up, not warehouse BO |
| `OpenQty = 0` and `UnbilledAmount = 0` | Line fully shipped **and** billed — should leave active BO list |
| Header Status still “Back Order” while all lines OpenQty = 0 | **Stale header status** or closed residual — should **not** count toward revenue-at-risk |

**Recommendation:** Do **not** replace OpenQty with Unbilled for classification. Optionally surface Unbilled as a *secondary* badge (“Unbilled after ship”) for Finance/CS, after mapping `UnbilledAmount` (and `UnbilledQty` if present) through the deriver.

### 2.4 Case study: SO366417 (“shows Back Order but fully delivered”)

| Check | What to look at | Expected if fully delivered |
|-------|-----------------|-----------------------------|
| Header Status | Acumatica list | May still say **Back Order** (stale / not recalculated) |
| Each line `OpenQty` | SO Details or API Details | **0** |
| Each line `QtyOnShipments` | SO Details | ≈ OrderQty (or shipped) |
| OrderWatch active list | `GET /operations/backorders?q=SO366417` | **No rows** if `isBackorderLine` is correct |
| OrderWatch resolved | `GET /operations/backorders/resolved?q=SO366417` | Rows appear **after** a sync that saw OpenQty clear and archived them |

If SO366417 still appears in OrderWatch **active** backorders while Acumatica lines show OpenQty = 0:

1. Re-run backorder sync for that order (or wait for scheduled sync).
2. Confirm raw Details for SO366417 still return `OpenQty > 0` (data lag vs UI).
3. Only then treat as a deriver bug.

If it only appears in Acumatica’s **BACK ORDERS** GI but not in OrderWatch active — that is **expected and correct**: we trust **line OpenQty**, not the GI status filter.

---

## 3. Resolution timeline (item was BO, then delivered)

### 3.1 What already exists

| Piece | Implementation |
|-------|----------------|
| First seen as BO | `acumatica_backorder_lines.first_backordered_at` (+ `first_backordered_at_is_backfilled` for pre-migration rows) |
| Age while open | API computes `backorder_age_days`, `aging_bucket` (`0-7` / `8-14` / `15-30` / `30+`) |
| On clear | Sync **archives** then **deletes** active row (`AcumaticaBackorderSyncService::archiveResolvedLine` → `backorder_resolutions`) |
| Resolution history | `BackorderResolution`: `first_backordered_at`, `resolved_at`, `days_to_resolve`, last open/backorder qty, last `revenue_at_risk`, reason, etc. |
| API | `GET /operations/backorders/resolved` |
| UI | `/app/backorders` → **Resolved** tab: search, resolved date range, table (SO, product, customer, reason, first BO, resolved, days, value) |

**“On the backorder sync update the SO and move to resolved”** — this is already the design:

1. Sync fetches SO + Details from Acumatica.
2. Lines that still pass `isBackorderLine` are upserted on `acumatica_backorder_lines`.
3. Lines that no longer pass (OpenQty cleared / fully fulfilled / order terminal) are **archived** to `backorder_resolutions` with `resolved_at = now()`, then **removed** from the active table.
4. Active KPI **Current outstanding** drops automatically (row gone from active set). Historical value remains on the resolution row for timeline reporting.

### 3.2 “Remove the cost from back order if the product is resolved”

| Layer | Behaviour today | Gap |
|-------|-----------------|-----|
| Active “Current outstanding” / revenue-at-risk | Resolved lines are **not** included (deleted from active) | None for live KPI |
| Resolved tab “Value” column | Still shows **last** `revenue_at_risk` at archive time (historical “what was at risk”) | Product decision: keep as “cleared risk”, relabel, or hide/zero for some roles |
| Completed shortfall KPI | Separate history path (`fulfillment_history_*`) — undershipped on completed orders | Do not mix with active BO value |

**UX suggestion:** keep historical value on Resolved as **“Cleared risk (at resolve)”** with a tip, and ensure Active KPIs never include it. If Finance wants zero on resolved, hide the column rather than writing 0 into the archive (loses analysis).

---

## 4. Resolution-time filters — recommended UX

Original ask: filters for resolved in 1 day, 2–3 days … up to month; buckets like 1 day, 2–7, 8–14, 15–21, 22–30/31, weeks, month, more than a month.

### 4.1 Best UX (recommended)

Use **two complementary controls**, not one overloaded list:

1. **Date range on `resolved_at`** (already on Resolved tab) — “cleared between these calendar dates.”
2. **Duration bucket on `days_to_resolve`** — “how long it took to clear,” independent of calendar month.

**Suggested duration buckets (single multi-select or chips):**

| Bucket id | Label | `days_to_resolve` |
|-----------|--------|-------------------|
| `d0_1` | Same day / 1 day | 0–1 |
| `d2_7` | 2–7 days (1 week) | 2–7 |
| `d8_14` | 8–14 days (2 weeks) | 8–14 |
| `d15_21` | 15–21 days (3 weeks) | 15–21 |
| `d22_30` | 22–30 days (~1 month) | 22–30 |
| `d31_plus` | More than a month | ≥ 31 |

**Why not 1 / 2 / 3 / … / 30 individual chips:** too many options, low usage density, hard on mobile. Collapsing into week-ish bands matches ops language and matches active aging (`0-7` / `8-14` / …) already used on open lines.

**Active tab** already has aging buckets for *open* age (`backorder_age_days`). Keep Active = “how old is the open problem”; Resolved = “how long did it take to clear.”

### 4.2 Stat cards for Resolved tab (proposed)

Mirror Active KPIs with history-focused cards:

| Card | Definition |
|------|------------|
| Resolved lines | Count of resolution rows in filter |
| Distinct SKUs / orders | Same rollups as active |
| Median days to resolve | `MEDIAN(days_to_resolve)` (prefer median over mean; less skew) |
| Average days to resolve | `AVG(days_to_resolve)` secondary |
| Cleared risk value | `SUM(revenue_at_risk)` of archived rows in filter — labeled **cleared**, not outstanding |
| Missing first-BO timestamp | Count where `first_backordered_at` null / backfilled (quality warning) |

### 4.3 API sketch (not yet required fields)

```
GET /operations/backorders/resolved
  ?date_from=&date_to=          // on resolved_at (exists)
  &days_to_resolve_bucket=d2_7  // new
  &product_segment=manufactured|trading
  &brand=...
  &q=
```

Optional summary: `GET /operations/backorders/resolved/summary` with the cards above + distribution by bucket and by Manufactured/Trading.

---

## 5. Two calculation tracks: Manufactured vs Partner brands

Observation: manufactured SKUs often clear faster; partner/trading brands take longer.

### 5.1 Already in the product

| Axis | Where |
|------|--------|
| **Manufactured vs Trading** | `FillRateBusinessCategory` / `product_segment` filter + value-summary cards on Active |
| **Brand / partner brand / category** | Brand filter cascade + inventory classification join |
| Resolution rows | Can join inventory → same `product_segment` / brand for history |

### 5.2 Two calculations to maintain (explicitly)

Keep them separate so numbers never get mixed:

| # | Metric | Formula | Scope |
|---|--------|---------|--------|
| **A. Active risk** | Current outstanding | `Σ open_qty × unit_price` for lines with `isBackorderLine` | Split cards: Manufactured vs Trading (and KP/CS if needed) |
| **B. Resolution speed** | Days to clear | `resolved_at − first_backordered_at` (days) | Aggregate **separately** for Manufactured vs Trading: median + p75, plus optional by brand |

**Do not** fold B into A. Fast resolution on manufactured does not reduce partner open risk; only shipping OpenQty does.

### 5.3 UX

- Active: existing Manufactured / Trading value cards stay as **risk**.
- Resolved: add **“Median days to resolve — Manufactured”** vs **“… Trading / Partners”** side by side.
- Optional chart later: resolution-time histogram stacked by segment (Phase 2 chronic-shortage / estimate work in `backorder-report-phase2.md`).

---

## 6. End-to-end “truth” flow (current setup)

```
Acumatica SO (header Status may be "Back Order")
    │  $expand=Details
    ▼
Line fields: OrderQty, OpenQty, QtyOnShipments, UnitPrice, [UnbilledAmount present but unused]
    │
    ▼
SalesOrderLineFulfillmentDeriver
    • open_qty ← OpenQty (incl. 0)
    • shipped  ← ShippedQty or QtyOnShipments
    • isBackorderLine? → active upsert
    │
    ├─ YES → acumatica_backorder_lines  → Active tab / Current outstanding
    │         first_backordered_at set/kept
    │
    └─ NO (was active last sync) → archive BackorderResolution → delete active
              resolved_at, days_to_resolve → Resolved tab
```

**Guardrails (do not regress):**

1. Never classify active BO from header Status alone.
2. Never treat full OrderQty as backorder when OpenQty is a smaller remainder.
3. Never use Unbilled as a substitute for OpenQty.
4. Prefer OpenQty including explicit 0 (fully shipped lines must leave the active list on next sync).

---

## 7. Gaps vs this observation (action checklist)

| # | Observation | Status | Suggested next step |
|---|-------------|--------|---------------------|
| 1 | SO366417 false “Back Order” while delivered | Data / status lag vs line qty | Validate with `inspect_so_raw.php SO366417`; confirm OpenQty=0 → active empty after sync |
| 2 | Unbilled column meaning | **API has line `UnbilledAmount`; app does not map it** | Optional: map + display as billing signal only |
| 3 | Open Qty on BO SO | **Already primary field** | Document for ops; show Open Qty + Qty on Shipments in line expand if UI still hides them |
| 4 | Resolution timeline | **Mostly built** (`first_backordered_at` → `resolved_at` / `days_to_resolve`) | Backfill caveat remains on age for pre-migration lines |
| 5 | Resolved duration bucket filters | **Not built** | Add `days_to_resolve` bucket filter + chips (§4) |
| 6 | Resolved stat cards | **Thin** (table + count only) | Add median/avg days, cleared value, segment split |
| 7 | Remove cost when resolved | Active yes; Resolved still shows historical value | Relabel / optional hide; do not drop archive amount |
| 8 | Sync moves to resolved | **Built** (`archiveResolvedLine`) | Ensure scheduled backorder sync runs often enough for “same day” resolution visibility |
| 9 | Manufactured vs partner resolve speed | Segment exists for **active** value; not for **resolve speed** KPIs | Add dual median-days cards on Resolved |
| 10 | True PlanType=68 “SO Back Ordered” allocation | **Not on IpayV2** (`InItemPlan` / `SOLineSplit` 404) | Still interim OpenQty definition; see `backorder-py/ACUMATICA-ENDPOINT-EXTENSION-NEEDED.md` |

---

## 8. Practical checks for the team

```bash
# Raw Acumatica payload for a disputed SO
cd backend
php scripts/inspect_so_raw.php SO366417

# Expect for "fully delivered":
#   OpenQty = 0, QtyOnShipments ≈ OrderQty, UnbilledAmount = 0 (if present)
# Then after sync: not in active backorders; may appear in resolved.
```

OrderWatch API:

- Active: `GET /api/operations/backorders?q=SO366417`
- Resolved: `GET /api/operations/backorders/resolved?q=SO366417`

Acumatica UI:

- List **BACK ORDERS** = Status filter only (can disagree with OpenQty).
- SO → **Details** columns **Open Qty** / **Qty on Shipments** = same basis as OrderWatch.

---

## 9. One-line answers to the open questions

| Question | Answer |
|----------|--------|
| Is **Open Qty** on a Back Order SO available in the API? | **Yes** — line `OpenQty` on `SalesOrder` Details; this is OrderWatch’s primary backorder qty. |
| Is **Unbilled** available? | **Line `UnbilledAmount` is present on IpayV2 payloads** (confirmed on sample SO JSON). It is **money not yet invoiced**, not open qty. **Not mapped** into OrderWatch tables/UI yet. |
| How do we know something left backorder? | Sync sees line no longer `isBackorderLine` → archive to `backorder_resolutions` with `resolved_at` / `days_to_resolve`, remove from active. |
| How should resolution filters work? | Keep `resolved_at` date range + add **days-to-resolve buckets** (0–1, 2–7, 8–14, 15–21, 22–30, 31+); dual Manufactured vs Trading speed KPIs. |

---

## 10. Deep dive: Unbilled vs Billed vs Open — research (when they match, 0, or go negative)

This section answers observed UI/API scenarios: Unbilled **matches** billed residual, **never matches** open/backorder value, is **0**, or shows **negative**. Sources: Acumatica community + help tooltips, PO form definitions (same “unbilled” concept), OrderWatch live IpayV2 payload (`SO359099-raw.json`), and OrderWatch classification rules.

### 10.1 Two different questions Acumatica is answering

| Axis | Business question | Primary fields | Domain |
|------|-------------------|----------------|--------|
| **Fulfillment (ops / warehouse)** | How much is still **not shipped / not closed**? | Line `OpenQty`, `QtyOnShipments`, header **Unshipped Amount** | Backorder / fill rate |
| **Billing (AR / Finance)** | How much of the order is still **not invoiced**? | Line `UnbilledAmount` (API), GI **Unbilled** column, header unbilled balance | Invoicing lag / prepay / credit memos |

They **only line up when shipping and invoicing move in lockstep**. In Kim-Fay’s flow they often do **not**: ship first, invoice later (or partial invoice, credit memo, return line).

Acumatica’s own help text for **Unshipped Amount** (SO Totals — community-cited HELP icon) is quantity-driven, **not** unbilled-driven:

> Sum of unshipped amounts for lines with nonzero unshipped qty of stock items.  
> Per line: `(Ext. Price / Qty) × Open Qty`  
> At order creation (nothing shipped) ≈ Line Total; **excludes freight**.

So if you compare **Unbilled** to **OpenQty × unit price** (OrderWatch revenue-at-risk) and they diverge — that is often **correct ERP behaviour**, not a sync bug.

### 10.2 What “Unbilled” means (conceptually)

At line level (what IpayV2 returns as `UnbilledAmount` on Details):

```
UnbilledAmount ≈ line extended value still not covered by released SO/AR invoices
```

At order level (list GI “Unbilled” column / unbilled balance):

```
Unbilled balance ≈ order value still available to invoice
               (order line totals − billed amounts, with taxes/discounts per Acumatica rules)
```

Related Acumatica behaviour (community + release notes):

- Payments applied to an SO are limited by **unbilled balance** (“Applied Amount Cannot Exceed the Unbilled Balance”).
- If status is Open and **unbilled amount = 0**, payment application is steered to the **invoice** instead of the order.
- Shipped-not-invoiced is a **known intermediate state** (IN/COGS timing vs revenue) — goods can leave warehouse while unbilled amount remains.

**Official REST docs** (`SalesOrder` entity chapter on help.acumatica.com) show request examples (create SO, expand Details, shipments, payments) but do **not** publish a field-by-field semantic dictionary for `UnbilledAmount`. Semantics come from:

1. Form/DAC behaviour on SO301000 Totals + Details  
2. Contract fields present on your endpoint (IpayV2)  
3. Community + partner notes when Unshipped ≠ Unbilled  

**On your tenant (confirmed):** line Details include `"UnbilledAmount": { "value": … }` next to `OpenQty` / `QtyOnShipments` / `OrderQty`. OrderWatch does **not** map it yet.

### 10.3 Scenario matrix — what you saw, what it means

| Observation | Typical meaning | Safe for backorder “value at risk”? |
|-------------|-----------------|-------------------------------------|
| **Unbilled ≈ remaining line value**, OpenQty high | Ordered, not shipped, **and** not invoiced — classic open SO | Use **OpenQty × unit_price** (not Unbilled) for ops risk |
| **Unbilled = 0**, OpenQty = 0, shipped full | Fully shipped **and** fully invoiced — closed for both axes | Not active BO |
| **Unbilled = 0**, OpenQty **> 0** | **Invoiced ahead of ship** (or billed qty/amount covers open remainder) — rare for stock but possible with billing rules / non-stock / advanced SO invoice | Still a **fulfillment** risk if OpenQty > 0; Unbilled hides it |
| **Unbilled > 0**, OpenQty = **0** | **Shipped (or closed) but not yet invoiced** — AR lag | **Not** a warehouse backorder; optional “shipped not billed” Finance flag |
| **Unbilled never matches** OpenQty × price or Order Total − something | Different formulas: taxes, line vs doc discounts, freight, partial invoices, multi-invoice, currency, or known SO total quirks | Do **not** force-match; treat as separate metric |
| **Unbilled = 0** while Status still Back Order | Status lag **or** all remaining value already billed while open qty/status lag | Trust **OpenQty** for BO list |
| **Negative Unbilled** | See §10.4 | Do **not** add into revenue-at-risk; flag for Finance review |

### 10.4 Negative Unbilled — what it usually means

Negative unbilled is **not** “negative backorder stock.” Common drivers:

| Driver | What happens in Acumatica |
|--------|---------------------------|
| **Return / credit / RMA lines** | Negative qty or credit memo value reduces billed-vs-order balance; unbilled can go negative relative to original positive lines |
| **Over-invoicing vs current order qty** | Order qty reduced after invoice, or invoice amount > remaining order residual → unbilled dips below zero until CM/adjustment |
| **Credit memo / cancellation invoice** | Reverses billed amount; balance math can show negative residual until documents settle |
| **Exchange / negative SO lines** | Acumatica allows negative qty on some order types (returns/exchanges); amounts follow sign of qty |
| **Discount / tax reallocations after partial bill** | Document-level discount or tax change after partial invoice can skew residual unbilled |

**Analysis rule:**  
`if UnbilledAmount < 0` → classify as **billing exception**, exclude from Σ revenue-at-risk, optionally surface count of exception lines.

### 10.5 “Unbilled matched billed” / “never matched”

Interpret carefully:

1. **Matched residual (Unbilled ≈ expected open value)**  
   - Often early lifecycle: nothing shipped, nothing billed → Unbilled ≈ line amount; OpenQty ≈ OrderQty.  
   - Or partial progress where **same %** was shipped and billed together.

2. **Never matched OpenQty × unit_price**  
   Expected when:
   - Unit price vs Ext. Price / qty after **line discounts** (Unshipped uses Ext.Price/Qty × OpenQty per help tooltip).
   - **VAT / tax** on totals tab vs exclusive unit price (OrderWatch uses unit price unlabeled for tax).
   - **Freight** on order but not in line OpenQty value.
   - Partial invoice on different lines than open lines.
   - Community reports of **Unshipped Amount / Unbilled Balance not matching documented formulas** on some builds (flagged as known issues on older 23R2 builds) — always compare to **line OpenQty** for ops.

3. **Unbilled matched “billed” columns in the UI**  
   If you saw Unbilled equal to a Billed column, check whether the GI columns are actually:
   - Unbilled vs **Order Total** (easy to misread on a busy grid), or  
   - Fully open order where billed = 0 and unbilled = order total (they “match” only in the sense unbilled = full order).

### 10.6 Should you incorporate Unbilled into OrderWatch analysis?

**Recommendation: yes as a secondary Finance signal — never as the primary backorder classifier.**

| Use case | Use OpenQty? | Use UnbilledAmount? |
|----------|--------------|---------------------|
| Active backorder list / revenue at risk | **Yes (required)** | No |
| Resolve / clear from active BO | OpenQty → 0 / not `isBackorderLine` | Irrelevant only |
| “Shipped not invoiced” AR worklist | OpenQty = 0 | UnbilledAmount > 0 |
| “Invoiced not shipped” exception | OpenQty > 0 | UnbilledAmount ≈ 0 |
| Payment / prepay headroom | No | Unbilled balance (order-level) |
| Negative residual / CM / overbill | No | UnbilledAmount < 0 → exception bucket |

**Proposed analysis formulas (additive, do not replace existing):**

```
# Already production
revenue_at_risk = open_qty × unit_price   # only if isBackorderLine

# Optional Finance overlays (if we map UnbilledAmount)
shipped_not_billed_value = UnbilledAmount
  where OpenQty <= 0 and UnbilledAmount > 0

invoiced_but_still_open = open_qty × unit_price
  where OpenQty > 0 and UnbilledAmount <= 0

billing_exception = UnbilledAmount < 0
```

**Do not** redefine:

```
# WRONG for ops
revenue_at_risk = UnbilledAmount
```

That would drop true backorders that were pre-invoiced and inflate “risk” with pure AR lag after full ship.

### 10.7 How to pull it from the Acumatica API (your setup)

Contract REST (same pattern OrderWatch already uses):

```http
GET /entity/IpayV2/{version}/SalesOrder?
  $filter=OrderType eq 'SO' and Status ne 'Completed' and ...
  &$expand=Details
```

On each Details element (already seen in production payload):

| Field | Role |
|-------|------|
| `OpenQty` | Fulfillment remainder |
| `OrderQty` | Ordered |
| `QtyOnShipments` | On shipment docs |
| `UnbilledAmount` | Money not yet invoiced (line) |
| `UnitPrice` / `CuryUnitPrice` | Price basis for OpenQty risk |
| `Amount` / `ExtendedPrice` | Line extended (discounted) |

Optional next probes (if missing on IpayV2, extend endpoint — same process as OpenQty historically):

- Header unbilled / unshipped totals (names vary: often `CuryUnbilledOrderTotal`, `UnbilledOrderTotal`, or Totals tab fields — confirm via `$adHocSchema` or Web Service Endpoints SM207060).
- Line `UnbilledQty` / `BilledQty` if published (`inspect_so_raw.php` already looks for them).

**Default system endpoint docs** (examples only, not field glossary):  
https://help.acumatica.com → Integration → REST API → SalesOrder entity examples.

### 10.8 Practical decision for Kim-Fay

| Priority | Action |
|----------|--------|
| P0 | Keep **OpenQty** as sole active BO / revenue-at-risk driver (current). |
| P1 | Map `UnbilledAmount` read-only on SO line + backorder row for diagnostics. |
| P2 | Badge / filter: **Shipped not billed** (`open=0`, `unbilled>0`) and **Billing exception** (`unbilled<0`). |
| P3 | Do **not** change Resolved timeline or Manufactured/Partner resolve-speed math based on Unbilled. |
| Avoid | Summing Unbilled into Current Outstanding or replacing OpenQty. |

### 10.9 Worked micro-example (from live sample SO359099)

| Line | OrderQty | OpenQty | QtyOnShipments | Amount | UnbilledAmount | Read |
|------|----------|---------|----------------|--------|----------------|------|
| FAYMU0004 | 150 | 0 | 150 | 285000 | 0 | Fully shipped + fully billed |
| COSTP0025 | 150 | 0 | 150 | 285000 | 0 | Same |

Status header: **Completed**. No active BO; no unbilled lag. This is the “everything zero residual” happy path.

Contrast target cases to spot-check next:

1. Open BO line: OpenQty > 0, UnbilledAmount ≈ open value (aligned).  
2. Shipped waiting invoice: OpenQty = 0, UnbilledAmount > 0.  
3. Negative: return/CM line with UnbilledAmount < 0.  
4. Status Back Order + OpenQty = 0 (SO366417-style) — ignore Unbilled for BO; clear via OpenQty.

---

## 11. Screenshot & code reference paths

- List (Status = Back Order): `backorder-py/Sales-Orders-07-23-2026_06_00_PM.png`
- Line Open Qty (SO367051): `backorder-py/Sales-Orders-l07-23-2026_06_00_PM.png`
- Production deriver: `backend/app/Services/Admin/SalesOrderLineFulfillmentDeriver.php`
- Sync + archive: `backend/app/Services/Admin/AcumaticaBackorderSyncService.php`
- Resolved model: `backend/app/Models/BackorderResolution.php`
- UI: `src/routes/app.backorders.tsx` (Active + Resolved tabs)
- Sample API payload with `OpenQty` + `UnbilledAmount`: `backend/storage/app/SO359099-raw.json`
- Probe script: `backend/scripts/inspect_so_raw.php`
- Community (Unshipped formula / Unbilled ≠ Unshipped): [Unshipped Amount Calculation](https://community.acumatica.com/distribution-6/unshipped-amount-calculation-20000), [unbilled balance/unshipped amount](https://community.acumatica.com/distribution-6/unbilled-balance-unshipped-amount-17807)
- Community (payments vs unbilled balance): [Applied Amount Cannot Exceed the Unbilled Balance](https://community.acumatica.com/retail-113/adjusting-sales-orders-applied-amount-cannot-exceed-the-unbilled-balance-28906)
- Official REST SalesOrder examples (help site): Integration Development Guide → SalesOrder entity (field semantics not fully listed; use form help + payload + `$adHocSchema`)

---

## 12. Does this resolve our issues? (status after research)

**Partly.** Unbilled + OpenQty research **clarifies** the confusing UI/API cases. It does **not** by itself ship every product gap (rich Resolved filters, dual Manufactured/Partner resolve-speed cards, Unbilled badges in the app). The **morning high → evening lower** pattern on open BO value is usually **expected** when fulfillment runs — OpenQty-based risk is the right story, not Unbilled.

### 12.1 Issue → resolution status

| Issue | Resolved by research/docs? | In the product today? |
|--------|----------------------------|------------------------|
| SO shows **Back Order** but looks fully delivered (e.g. SO366417) | **Explained** — trust line **OpenQty**, not header Status | **Yes** if sync sees OpenQty=0 → leaves active list / archives to Resolved |
| What is **Unbilled**? | **Yes — explained** (billing residual, not BO qty) | Analysis only; **not mapped** into OrderWatch UI/tables yet |
| Unbilled **matches** / **never matches** / is **0** / goes **negative** | **Yes — explained** (§10 scenario matrix) | Optional Finance overlay **not built** |
| **Open Qty** on a Back Order SO | **Yes** — exposed on IpayV2 Details; we use it | **Yes** — primary BO / revenue-at-risk field |
| **Open Quantity on shipment** (Qty on Shipments vs Open Qty) | **Yes** — `QtyOnShipments` = delivered signal; `OpenQty` = remainder | **Yes** in deriver/sync; often under-displayed in UI |
| Resolution timeline (how long until cleared) | Design clear | **Mostly built** — `first_backordered_at` → `resolved_at` / `days_to_resolve` + Resolved tab |
| Resolved filters (1 day, 2–7, weeks, month+) + stat cards | Spec only (§4) | **Not fully** — basic Resolved tab (search + date range + table) |
| Remove cost from active BO when resolved | Spec + current behaviour | **Active yes** (row deleted); Resolved still shows historical “cleared risk” value |
| Sync moves SO line to resolved | Design = current code | **Built** — `archiveResolvedLine` then delete active |
| Manufactured vs partner **resolve speed** | Spec only (§5) | Segment on **active** value exists; **dual median-days resolve KPIs not built** |
| Amount high in morning, lower by evening | **Expected** for OpenQty risk when stock ships (§13) | Active KPI falls as OpenQty clears / lines archive |

### 12.2 What research fixed vs what still needs product work

| Fixed as understanding | Still open as product work |
|------------------------|----------------------------|
| Do not classify BO from header Status alone | Same-day delta UI (“opened this morning vs still open now”) optional |
| Do not use Unbilled as revenue-at-risk | Map `UnbilledAmount` + badges (shipped-not-billed / negative exception) |
| OpenQty is API-available and already primary | Rich Resolved duration buckets + stat cards |
| Negative Unbilled = billing exception, not stock | Manufactured vs Trading resolve-speed cards |
| Morning→evening drop on open risk is normal | End-of-day / history rollup if leadership wants a chart |

### 12.3 Bottom line (for stakeholders)

1. **Confusion about Unbilled / Open / Status** — largely **resolved as analysis** (this document).  
2. **Product gaps** (Resolved UX, dual resolve speed, Unbilled badges) — **not all done**.  
3. **Morning high → evening lower on open BO amount** — **yes, normal** when goods ship and sync reduces/prunes open lines. Prefer **OpenQty-based Current outstanding**, not Unbilled, for that story.  
4. Unbilled research does **not** replace shipping as the driver of “amount went down.”

---

## 13. Scenario: amount high in the morning, lower by evening

### 13.1 Is this normal?

**Yes — for OrderWatch “Current outstanding” / revenue at risk:**

```text
revenue_at_risk = open_qty × unit_price   # active backorder lines only (isBackorderLine)
```

During the day ops typically:

1. **Create shipments** → `QtyOnShipments` ↑, **`OpenQty` ↓**  
2. **Backorder sync** runs → lines with lower open qty update; lines that no longer pass `isBackorderLine` are **archived** to `backorder_resolutions` and **removed** from active  
3. Orders **complete / cancel** → leave the open set  
4. New shortages / new SOs can also **raise** the total — so evening is not always lower every day  

So:

- **Morning book** = what is still open at start of day (e.g. after overnight sync)  
- **Evening book** = what is still open after today’s fulfillments (and syncs)  
- **Less by evening** on open risk is usually a **good ops signal** (stock moved), not a bug  

### 13.2 What each “amount” does across the day

| Amount | Morning → evening often lower? | Why |
|--------|--------------------------------|-----|
| **Active BO / Current outstanding** (`OpenQty × unit_price`) | **Often yes** | Shipments clear open qty; prune removes fully cleared lines |
| **Unbilled** | Maybe, or **not** same day | Only drops when **invoices** (or credit memos) post — can stay high **after** ship |
| **Header Status = Back Order** count / GI list | Can **lag** | Status may stay “Back Order” after all lines OpenQty = 0 |
| **Resolved “cleared risk”** (history) | **Rises** as day progresses | Archive of what left the active list (`resolved_at` today) |

**Important:** If you watch **Unbilled** only, evening can still look high even when warehouse risk fell — that is **shipped not billed** (AR lag), not “still on backorder.”

### 13.3 What this does *not* mean

| Misread | Correct read |
|---------|----------------|
| Unbilled “fixed” the false Back Order case | **OpenQty + sync** fixes false active BO; Status is secondary |
| Amounts always fall every day | New orders / new shortages can push morning **or** evening **up** |
| One-day drop without warehouse activity is always good | Rarer causes: cancels, qty reductions, price edits, sync catch-up after lag — investigate if no shipments |
| Evening Unbilled lower = BO resolved | Only if OpenQty also cleared; Unbilled alone is invoicing |

### 13.4 Practical same-day check (ops)

Same filters / same snapshot definition, twice:

1. **Morning:** active BO total + a few top lines’ **OpenQty** (and optionally Unbilled if probing Acumatica)  
2. **Evening:** same  

| Pattern | Interpretation |
|---------|----------------|
| Total ↓; lines OpenQty ↓ or gone from active | Expected fulfillment; check **Resolved** for those SO/SKU with `resolved_at` today |
| Total ↓; **Unbilled** barely moved | Fulfillment worked; invoicing still pending (**expected**) |
| Total flat; lots of shipments claimed | Sync lag or shipments not reducing OpenQty yet — re-sync / inspect raw SO |
| Total ↑ | New BO lines or qty increases outweigh clearances |
| Unbilled ↓ but OpenQty still high | Invoiced (or CM) without full ship — **invoiced still open** exception |

CLI / API:

```bash
# Morning / evening spot-check a disputed SO
cd backend
php scripts/inspect_so_raw.php SO366417
```

```http
GET /api/operations/backorders?q=SO366417
GET /api/operations/backorders/resolved?q=SO366417
GET /api/operations/backorders/summary   # open-book style KPI (check date filter behaviour)
```

### 13.5 Optional product enhancement (not built)

**Same-day delta** (or end-of-day snapshot):

- Capture active `Σ revenue_at_risk` at morning sync and evening sync  
- Show: “Opened / still open / cleared today” counts and value  
- Do **not** mix Unbilled into that delta unless labeled as a separate AR series  

Value-at-risk daily rollup is already sketched in `backorder-report-phase2.md` (`backorder_value_at_risk_daily`) — that supports multi-day trend; same idea works for intra-day if product wants it.

### 13.6 One-line answer for the team

> **Yes — if “amount” means open backorder risk (OpenQty × price), high in the morning and lower by evening is normal when we ship and sync.**  
> Unbilled may not fall the same day. Header Back Order status may lag. Use OpenQty for ops risk; use Unbilled only for billing lag analysis.

---

## 14. Conversation log index (what was captured)

| Topic | Where in this doc |
|-------|-------------------|
| Raw field notes (SO366417, Unbilled, Open Qty, resolved UX, manufactured vs partner) | §0 |
| Screenshot ground truth | §1 |
| Field translation table (Status / OpenQty / Unbilled / QtyOnShipments) | §2 |
| SO366417 case study | §2.4 |
| Resolution timeline + archive on sync | §3 |
| Resolved duration buckets UX | §4 |
| Manufactured vs Partner two calculations | §5 |
| End-to-end truth flow + guardrails | §6 |
| Action checklist / gaps | §7 |
| Practical checks | §8 |
| One-line Q&A | §9 |
| Unbilled deep dive (match / zero / negative / API / incorporate?) | §10 |
| References | §11 |
| Does research resolve our issues? | §12 |
| Morning high → evening lower | §13 |

---

## 15. Guardrails (do not regress) — final checklist

1. Never classify active BO from header **Status = Back Order** alone.  
2. Prefer Acumatica **OpenQty** including explicit **0**; never treat full OrderQty as BO when OpenQty is smaller.  
3. Prefer **QtyOnShipments** when `ShippedQty` is missing (IpayV2).  
4. **Never** set `revenue_at_risk = UnbilledAmount`.  
5. Negative Unbilled → billing exception only; exclude from ops risk.  
6. When a line clears: **archive** then remove from active so morning→evening drop is visible and timeline is preserved.  
7. Active KPI = live open book; Resolved value = historical cleared risk (label clearly; do not double-count).  
8. Manufactured vs Trading **active risk** and **resolve speed** are two metrics — do not fold into one number.
)
