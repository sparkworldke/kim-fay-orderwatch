# Backorders — Decision PRD (tight)

**Product:** Kim-Fay Sight  
**Module:** Production → **Backorders** (`/app/backorders`)  
**Status:** Decision draft  
**Screenshot:** `Backorders-—-Kim-Fay-Sight-08-06-2026_12_45_PM.png`  
**Primary users:** Supply / Production / Procurement · Sales & CS · HOD / Exec (read)  

---

## 1. One-sentence goal

Open Backorders and in **&lt; 5 seconds** know: **what can ship now, what is blocked (and why), how much KES is at risk, and who acts next** — without decoding a wall of cards.

---

## 2. What the screenshot shows (baseline)

| Area | Observed |
|---|---|
| Scope | Active tab; date range; many filters (brand group, brand, category, line, warehouse, reason, fulfilment…) |
| Value cards | Backorder value · Invoiced · Order value · RaR (true POS cover) · RaR (partial) · RaR (stock available) |
| Segment strip | Manufactured · Trading · KP · CS (+ open lines / SKUs / customers / completion / open balance) |
| Key guide | Long definitions (value cards, “words we use”) |
| Main table | **Grouped by Inventory ID / Product**: SOs · Customers · Open qty · Revenue at risk · expand |
| Volume | 528 SKUs · 433 loaded · **2,567 / 3,300 lines** (capped load) |
| Actions | Download Excel · Queue download · Update backorders · Queue status |

**Strengths:** Group-by-SKU, RaR, brand/segment split, expand to orders, export.  
**Weakness:** Insight requires studying **many cards + definitions** before action.

---

## 3. Problems → decisions

| # | Problem | Decision |
|---|---|---|
| P1 | Too many primary value cards (6+) | **3 decision cards** only (see §5) |
| P2 | Three “RaR” variants confuse sales | **One headline RaR** = open qty × unit price on active lines; sub-labels for stock available vs no stock |
| P3 | No “can fulfil now” on top | **Card: Ready to release** (stock available ≥ open, or partial cover flag) |
| P4 | Inventory-first only | Keep SKU table as default **ops** view; add **mode toggle**: By SKU \| By customer \| By reason |
| P5 | Definitions dominate the fold | Collapse “Key guide” behind **Help / Definitions** |
| P6 | Filters overload | **Pinned:** Period, Brand group, Search · **More filters** sheet for the rest |
| P7 | No owner / next action on row | Each SKU (and line on expand): **Owner function** + **Next action** |
| P8 | Partial load (433 of 528) | Keep cap; show **“Narrow filters for full set”** + Excel for full extract (already) |
| P9 | Impersonation / portfolio | Respect data scope (team/customer); banner already when impersonating |
| P10 | Mobile | SKU **cards** on phone (`mobile-phone.md`) |

---

## 4. Three questions (always)

1. **What can be fulfilled now?** → Ready to release (full/partial stock).  
2. **What is blocked and why?** → No/low stock · reason code · credit/hold if known.  
3. **Who acts next?** → Sales/CS (customer) · Production · Procurement · Warehouse transfer.

---

## 5. Decisions (closed) — V1

| # | Topic | Decision |
|---|---|---|
| D1 | Headline RaR | **Open qty × unit price** on active backorder lines (same as Excel / ops “true” exposure). Label: **Revenue at risk** |
| D2 | Primary KPI cards (exactly **3**) | **① Revenue at risk** · **② Ready to release (KES / lines)** · **③ Blocked — no stock (KES / lines)** |
| D3 | Secondary (one row, smaller) | Open lines · SKUs · Customers · Completion % · Open balance KES (keep from strip; compact) |
| D4 | Segment chips | Keep Manufactured / Trading / KP / CS as **filters that recompute cards + table** (not four more mini financial statements) |
| D5 | Default table | **By Inventory ID** (as today), sort **RaR desc** |
| D6 | Expand SKU | SO list: customer · SO# · open qty · RaR · reason · promised/expected date if any · action |
| D7 | Next action dictionary (row-level) | `Release / create shipment` · `Transfer stock` · `Produce / procure` · `Call customer` · `Set reason` · `Escalate` |
| D8 | Owner function | Derived: stock available → **Warehouse/CS** · no stock manufactured → **Production** · no stock trading → **Procurement** · reason missing → **Sales/CS** |
| D9 | Invoiced / order value cards | **Demote** to Definitions or secondary analytics (not top fold) |
| D10 | Triple RaR cards | Merge: show **one RaR** + breakdown on click (available / partial / none) |
| D11 | Resolved tab | Keep Active / Resolved split |
| D12 | Excel | Keep Download + Queue for large extracts |

---

## 6. Target page structure

```text
Header: Backorders · Active|Resolved · Refresh · Excel · Update
Sync chips (last stock / BO / reason) — keep compact
Filters: Period + Brand group + Search · [More filters]
KPI × 3 (D2) · optional RaR breakdown on click
Secondary strip (D3) · Segment chips (D4)
View: [ By SKU | By customer | By reason ]
Table/cards: product · open · RaR · coverage · owner · next action · expand
Footer: loaded vs total · pagination
```

**Coverage badge per SKU:** Full / Partial / None (from free stock vs open qty).

---

## 7. Role lenses (same page, different default sort/filter)

| Role | Default focus |
|---|---|
| **Warehouse / CS** | Ready to release first |
| **Production** | Manufactured + None/Partial coverage |
| **Procurement** | Trading + None coverage |
| **Sales / CS (rep)** | Scoped customers; sort by their RaR; Call customer |
| **HOD / Exec** | KPI × 3 + segment chips only; drill optional |

---

## 8. Actionable build list

| ID | Action | Why |
|---|---|---|
| **B1** | Collapse Key guide into help drawer | Free the fold |
| **B2** | Implement **3 primary cards** (D2) + demote invoiced/order value | Decision speed |
| **B3** | Single RaR + click breakdown (available/partial/none) | End triple-RaR confusion |
| **B4** | Coverage badge + **Ready to release** filter | “What can ship now” |
| **B5** | Columns: Owner · Next action (D7–D8) | Accountability |
| **B6** | Filter UX: pinned vs More filters | Usability |
| **B7** | View toggle By customer / By reason (read models) | Sales vs root-cause |
| **B8** | Mobile SKU cards | Field/HOD phone |
| **B9** | Keep Excel queue; clarify “433 loaded” messaging | Trust |
| **B10** | QA: manufactured vs trading owner rules | Correct function |

---

## 9. Metrics glossary (authoritative)

| Term | Formula / rule |
|---|---|
| **Open qty** | Remaining to ship on active BO line (`open_qty` / backorder qty rules already in app) |
| **Revenue at risk** | Σ open qty × unit price (active lines, filters applied) |
| **Ready to release** | Lines/SKUs where available stock can cover open (full or partial — show both counts) |
| **Blocked — no stock** | Open exposure where available stock = 0 (or below policy) |
| **Completion %** | Keep existing definition; secondary only |

Do **not** invent a second RaR for the headline.

---

## 10. Acceptance (V1)

- [ ] Top of page answers the **3 questions** without scrolling past the fold on laptop.  
- [ ] Exactly **3** primary value cards (D2).  
- [ ] One headline **Revenue at risk**; optional breakdown on demand.  
- [ ] User can filter/sort to **Ready to release** in one control.  
- [ ] SKU row shows **coverage**, **owner**, **next action**.  
- [ ] Expand shows SOs/customers for that SKU.  
- [ ] Segment chips recompute KPIs + table.  
- [ ] Excel still available for full 3k+ lines.  
- [ ] Scoped users only see their customers/lines.  
- [ ] Phone: usable card list.

---

## 11. Explicit non-goals (V1)

- Full workflow/ticketing (assign owner with SLA workflow)  
- Auto-creating production orders or POs in ERP  
- Replacing fill-rate or inventory MSI dashboards  
- AI narrative (optional later)

---

## 12. Related

| Doc / code | Use |
|---|---|
| Screenshot | `Backorders-—-Kim-Fay-Sight-08-06-2026_12_45_PM.png` |
| Excel / audit specs | `backorder-excel-docs/` (line math) |
| Executive pulse | `executive-view.md` (RaR as one gap KPI) |
| My Team | `my-team.md` (team BO KES, not this page) |
| Mobile | `mobile-phone.md` |
| App | `src/routes/app.backorders.tsx` |

---

## 13. One-page decision summary

| Keep | Change |
|---|---|
| Group by SKU, expand to SOs | 3 decision KPIs, not 6+ definitions |
| RaR, filters, Excel, Active/Resolved | One RaR + coverage + owner + next action |
| Manufactured / Trading / KP / CS | As chips, not four full mini-dashboards |
| Date + brand filters | Pin essentials; hide the rest in More |
