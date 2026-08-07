# Backorders — Executive PRD (tight)

**Product:** Kim-Fay Sight  
**Module:** Backorders · `/app/backorders`  
**Audience:** Executives & HODs (read) · Sales/CS · Supply · Production · Procurement  
**Status:** Decision PRD — restructured for leadership  
**Related:** `backoirder-review.md` (ops UX decisions) · screenshot `Backorders-—-Kim-Fay-Sight-08-06-2026_12_45_PM.png`  
**Date:** 2026-08-07  

---

## 1. Why this exists (30 seconds)

When a customer orders and stock is short, money sits **open** until we ship, cancel, or partially fulfil. Leadership needs one place that answers:

| # | Question | Answer we show |
|---|----------|----------------|
| 1 | **How much is at risk right now?** | Total **Revenue at Risk (RaR)** — open qty × unit price |
| 2 | **Where is it?** | By **brand segment** (Manufactured / Trading) and **brands under each** |
| 3 | **Who is waiting?** | **Parent customer → branch → SO → line** drill |
| 4 | **What product is stuck?** | SKUs ranked by **value** or **volume** |
| 5 | **How long has it been open / when did it clear?** | Dates + age + time-to-resolve |
| 6 | **How do we clear it?** | Stock cover + next action (release / transfer / produce / procure / call customer) |

**Rule:** Active backorder is **exposure**, not “lost sale” until cancelled or written off. Delay and transfer gaps are **risk signals** we measure separately (see §5).

---

## 2. Current system (studied — baseline)

### 2.1 What already works

| Capability | Today |
|------------|--------|
| **Source** | Acumatica SO lines → `acumatica_backorder_lines` after SO sync; chain via `RunSalesOrderSync` / backorder jobs |
| **Active vs resolved** | Active tab = open shortfall; Resolved tab = archive in `backorder_resolutions` |
| **Headline value** | **RaR** = open qty × unit price (same math in UI, metrics, Excel) |
| **KPIs (page)** | RaR · Ready to release · Blocked — no stock (+ secondary open lines / SKUs / customers) |
| **Stock cover** | FGS (or line warehouse) on-hand vs open → Full / Partial / None |
| **Segments** | Product: **Manufactured** (Kim-Fay) vs **Trading** (Partners); Customer: **KP / CS** chips |
| **Default table** | Grouped by **Inventory ID (SKU)** → expand to SOs / customers |
| **Reasons** | Catalog on line (e.g. production OOS, procurement OOS, **transfer_delays**, wrong code, LPO error…) |
| **Age** | `first_backordered_at` → age days / aging bucket; resolved has `resolved_at` + `days_to_resolve` |
| **Excel** | Multi-sheet workbook (`BackorderExcelExporter`): Start Here, Summary, lines, Manufactured / Trading, SKU, customer, product, orders, resolved |
| **Scope** | Portfolio / role scoping applies |

### 2.2 Gaps this PRD closes

| Gap | Why it matters |
|-----|----------------|
| **Customer hierarchy drill** | Chains (e.g. Naivas parent → branches) not first-class on BO page |
| **Brand-under-segment rollup** | Segment chips exist; **dynamic brands under Manufactured / Partner** need clear drill for execs |
| **Lifecycle dates on every export line** | SO date, entered BO, resolved, days open — partial today; must be **mandatory columns** on export |
| **Supply path clarity** | “How do I supply this?” = stock cover + multi-warehouse transfer signal + next action (partially done; transfer **network stock** incomplete) |
| **Business scenarios** | Partial ship, lots, cancel SKU (fill-rate), transfer shortfall — not modelled as explicit **scenario tags** for reporting |
| **Delay / transfer “at risk time”** | No standard **delay exposure** metric for late partial/lots (see §5 — V1.1) |

---

## 3. Business model (example → rules)

**Example:** Customer **Sojpar** orders **Tishu Pos 450 · 1,000 bales** today. At 8am stock = 0 → line enters **backorder**.

| # | Scenario | What happens | System treatment |
|---|----------|--------------|------------------|
| **S1** | **SLA cancel (SKU only)** | Invoice timeline passes; other PO lines ship; this SKU cancelled | Open → cancel; drops active RaR; hits **fill rate** for that SKU/order; reason if captured |
| **S2** | **Partial accept** | By 10am stock = 300; customer takes 300; **700 stays BO** | Ship 300; open/RaR on remainder; cover = Partial if stock mid-way |
| **S3** | **Deliver in lots** | Multiple shipments until 1,000 filled | Same line (or successive open qty); age continues until open = 0 → **resolved** |
| **S4** | **Transfer shortfall** | Main warehouse empty; other warehouses hold enough in total | Reason / cover: transfer opportunity; **network stock** vs request warehouse (build) |
| **S5** | **Wrong order / admin** | Wrong code, LPO error, price, description | Reason codes already; not stock-driven; next action = Sales/CS |

**Fill rate:** S1 (and incomplete S2/S3 vs customer policy) affect fill-rate reporting — Backorders page shows **open exposure**; fill-rate dashboards own completion %. Do not double-count “lost” on both without a defined cancel/write-off event.

---

## 4. Executive dashboard (must show)

**Route:** `/app/backorders` · period filter applies to all tiles and tables.

### 4.1 Primary numbers (exactly three)

| Card | Definition |
|------|------------|
| **1. Total backorder value** | Σ open qty × unit price on **active** lines (filters applied). Label: **Revenue at risk** |
| **2. Ready to release** | RaR (and line count) where stock cover = **Full or Partial** — can act now (ship / partial / transfer) |
| **3. Blocked — no stock** | RaR where cover = **None** — produce / procure / transfer-from-network |

Secondary (compact strip only): open lines · unique SKUs · unique customers · optional completion % if already available.

### 4.2 Brand segment rollup

| Level | What |
|-------|------|
| **L0** | Total company RaR |
| **L1** | **Manufactured** (Kim-Fay) vs **Trading / Partners** |
| **L2** | Dynamic **brands** under each segment (from product catalog classification) |
| **Action** | Click segment or brand → recompute cards + table |

Customer-channel chips (**KP / CS**) stay as filters, not four extra full financial statements.

### 4.3 Customer hierarchy drill

```text
Parent customer (e.g. Naivas · Parent Acumatica ID)
  → Branch / ship-to (child customer ID)
    → Sales order (SO#)
      → Line: Inventory ID · open qty · unit price · RaR · cover · reason · dates
```

| Level | Show |
|-------|------|
| Parent | Name · Parent ID · **RaR total** · # branches · # SOs |
| Branch | Name · Customer ID · RaR · # SOs |
| SO | SO# · order date · RaR · line count |
| Line | SKU · qty open · value · first BO date · age · cover · next action |

**Data key:** `acumatica_customers.parent_acumatica_id` / main account flags already in CRM sync.

### 4.4 Product (SKU) view

| Sort | Default |
|------|---------|
| **By revenue (RaR)** | Default |
| **By volume (open qty)** | Toggle |

Columns: Inventory ID · name · open qty · RaR · # SOs · # customers · cover badge · expand to lines.

### 4.5 View modes (same data)

| Mode | Primary user |
|------|----------------|
| **By SKU** | Ops / supply (default) |
| **By customer (parent tree)** | Sales / CS / exec commercial |
| **By reason** (if already supported) | Root cause |

### 4.6 Dates on screen (active + resolved)

| Date | Meaning |
|------|---------|
| **SO / order date** | When the order was placed (from SO) |
| **Entered backorder** | `first_backordered_at` — first time line was open shortfall |
| **Age (open)** | Days from entered → today (active only) |
| **Resolved at** | When open cleared (resolved archive) |
| **Days to resolve** | entered → resolved |

**“How do I supply this?”** on each active line/SKU:

| Cover | Next action (owner) |
|-------|---------------------|
| Full | Release / create shipment · Warehouse/CS |
| Partial | Partial release + plan remainder · Warehouse |
| None + stock in other WH | Transfer stock · Warehouse |
| None + Manufactured | Produce · Production |
| None + Trading | Procure · Procurement |
| Non-stock reason | Call customer / fix order · Sales/CS |

---

## 5. Metrics (authoritative — no dual math)

| Term | Formula / rule |
|------|----------------|
| **Open qty** | Residual open on active BO line (prefer Acumatica residual; never invent a second residual) |
| **Revenue at risk (RaR)** | Σ open qty × unit price · active · filters applied |
| **Ready to release** | RaR + lines where stock cover ∈ {Full, Partial} |
| **Blocked — no stock** | RaR + lines where stock cover = None (or below policy) |
| **Days open** | `today − first_backordered_at` (calendar days) |
| **Days to resolve** | `resolved_at − first_backordered_at` |
| **Brand segment** | Manufactured vs Trading via existing classifier (`FillRateBusinessCategory`) |

### 5.1 Delay & transfer (V1.1 — define before build)

| Concept | Intent | V1 | V1.1 |
|---------|--------|----|------|
| **Delay exposure** | Partial/lots still open past customer delivery policy | Show **age** + reason | Optional: RaR × days open or “past SLA” flag if policy data exists |
| **Transfer opportunity** | Main WH short, **network** qty ≥ open | Reason `transfer_delays` + manual | **Network available** qty across warehouses; RaR tagged “transferable if moved” |

Do **not** label delay exposure as “lost sales” until cancel/write-off is recorded.

---

## 6. Excel export (exec + ops)

One workbook for the **selected period / filters**. Reading order for executives: **Summary first**, then detail.

### 6.1 Mandatory date columns (all line-level sheets)

| Column | Source |
|--------|--------|
| Order / SO date | Sales order |
| Entered backorder | `first_backordered_at` |
| Resolved at | `resolved_at` (blank if active) |
| Days open / days to resolve | Computed |
| (Optional) Scheduled / requested ship | Existing fields if present |

### 6.2 Sheets (required set)

| Sheet | Content |
|-------|---------|
| **Summary** | Total RaR · Ready · Blocked · by brand segment · top brands · top parent customers · top SKUs |
| **By customer** | Parent → branch totals (and branch rows); RaR, lines, SOs |
| **By SO** | One row per SO with BO; customer; RaR; line count; order date |
| **By product** | SKU · open qty · RaR · # SOs · # customers (sort-ready) |
| **Manufactured** | Kim-Fay lines only |
| **Trading (Partners)** | Partner lines only |
| **Raw / line detail** | Full active lines for period + **all dates** + cover + reason + next action |
| **Resolved** | Cleared lines: entered, resolved, days to resolve, last open qty |

Keep existing **Start Here** guidance sheet if useful; do not bury Summary.

### 6.3 Summary sheet mirrors dashboard

1. Total backorder value (RaR)  
2. RaR by brand segment (+ brand breakdown under each)  
3. RaR by parent customer (top N + full on By customer sheet)  
4. Top SKUs by RaR and by volume  

---

## 7. Decisions (closed)

| ID | Decision |
|----|----------|
| **D1** | Headline money = **RaR only** (open × price). No second competing “backorder value”. |
| **D2** | Exactly **3** primary cards: Total RaR · Ready · Blocked. |
| **D3** | Default ops view remains **By SKU**; add **By customer (parent tree)** as equal mode. |
| **D4** | Customer drill uses **parent_acumatica_id** hierarchy. |
| **D5** | Brand rollup = Manufactured / Trading → brands under each. |
| **D6** | Lifecycle dates mandatory on Excel line sheets; visible on expand / resolved UI. |
| **D7** | Supply path = stock cover + next action; transfer network stock = V1.1 if multi-WH stock already synced. |
| **D8** | Delay “loss” metrics = V1.1; V1 ships age + SLA flag only if policy data exists. |
| **D9** | Active ≠ lost sale; cancel/write-off is the loss event (fill-rate owns delivery failure %). |
| **D10** | Role scope unchanged (portfolio users only see their customers). |

---

## 8. Build list (priority)

| ID | Item | Priority |
|----|------|----------|
| **B1** | Confirm / tighten 3 KPI cards + demote clutter (already partly done) | P0 |
| **B2** | **By customer** mode: parent → branch → SO → line with RaR rollups | P0 |
| **B3** | Brand-under-segment drill on dashboard (L2 brands) | P0 |
| **B4** | SKU sort toggle: RaR vs open qty | P0 |
| **B5** | Excel: enforce date columns on Raw + Resolved; Summary matches §6.3 | P0 |
| **B6** | Excel sheets: By SO, By product, Manufactured, Trading (align names with §6.2) | P0 |
| **B7** | UI: show entered BO date + age on active expand; resolved already has pair | P1 |
| **B8** | Multi-warehouse **network stock** for transfer opportunity | P1 |
| **B9** | Scenario tags (partial / lots / cancel / transfer / admin) for analytics | P2 |
| **B10** | Delay exposure / past-SLA flag once customer delivery policy is available | P2 |

---

## 9. Acceptance (V1)

- [ ] Exec can state **total RaR** and **Manufactured vs Trading** split without opening Excel.  
- [ ] Click brand segment → brands under it → table/cards recompute.  
- [ ] Parent customer row shows total RaR; drill to branch → SO → line qty/value.  
- [ ] SKU list sortable by **value** and **volume**.  
- [ ] Active lines show **entered BO date** and **age**; resolved shows **entered + resolved + days**.  
- [ ] Expand/SKU row shows **cover** and **how to supply** (next action).  
- [ ] Excel Summary matches the four dashboard rollups; Raw includes **all mandatory dates**.  
- [ ] Sheets exist for By SO, By product, Manufactured, Trading, Raw, Resolved.  
- [ ] Scoped users cannot see other portfolios.  
- [ ] Period filter consistent across cards, drills, export.

---

## 10. Non-goals (V1)

- Full ticket/SLA workflow with assigned owners in Sight  
- Auto create production orders or purchase orders in Acumatica  
- Replacing fill-rate or production MSI dashboards  
- Labelling open BO as “lost sales” without cancel/write-off  
- AI narrative summaries (optional later)

---

## 11. Code map (for implementers)

| Area | Location |
|------|----------|
| UI | `src/routes/app.backorders.tsx` |
| Metrics | `backend/app/Services/Operations/BackorderMetricsService.php` |
| Line math / cover | `backend/app/Services/Operations/BackorderLineTransformer.php` |
| Excel | `backend/app/Services/Operations/BackorderExcelExporter.php` |
| Active model | `backend/app/Models/AcumaticaBackorderLine.php` |
| Resolved model | `backend/app/Models/BackorderResolution.php` |
| Sync | `backend/app/Services/Admin/AcumaticaBackorderSyncService.php` |
| Segment classify | `backend/app/Services/Operations/FillRateBusinessCategory.php` |
| Customer parent | `acumatica_customers.parent_acumatica_id` |
| Ops UX prior PRD | `new-doc/backoirder-review.md` |

---

## 12. One-page summary

| Keep | Add / change |
|------|----------------|
| RaR = open × price | Customer **parent → branch → SO** drill |
| Active / Resolved + age dates | Mandatory lifecycle dates on **every Excel line** |
| Manufactured / Trading | **Brands under segment** as L2 |
| SKU group + Excel multi-sheet | Explicit sheets: **By SO**, **By product**, Summary = exec mirror |
| Ready / Blocked stock cover | Clear **how to supply** + transfer network (P1) |
| Reason codes | Scenario framing for partial / lots / cancel / transfer (P2 analytics) |

**Success:** An executive opens Backorders or the Excel Summary and in under a minute knows **how much is stuck, in which brand and customer chain, on which SKUs, for how long, and who can clear it.**
