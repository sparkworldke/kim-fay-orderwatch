# Backorder Report Refactor — Dashboard & Excel Export

**Status:** Implementation guide (single source of truth)  
**Date:** 2026-07-26  
**Audience:** Laravel + React (OrderWatch)  
**Source materials:** `backorder-excel-docs/` (analysis workbooks, audit export template, `BACKORDER_AUDIT_TRAIL_SPEC.md`), current `BackorderExcelExporter`, `FillRateBusinessCategory`, `app.backorders.tsx`, `OperationsController`

---

## 0. One-paragraph goal

Acumatica’s `Status = Back Order` is a **live state, not history**. The moment a line fills, it drops off the ERP screen. OrderWatch must own the **audit trail** (when it went short, how long it stayed short, what it cost) and present the same numbers on the **dashboard** and in the **Excel export**. Every KPI, filter, and row must be sliceable by three commercial axes that leadership already uses:

| Axis | Meaning | Examples |
|------|---------|----------|
| **Brand** | Item brand from inventory master (fine grain) | Fay Tissues, Sifa, Cosy, Dove, Cow & Gate |
| **Manufactured** | Kim-Fay own production (product segment) | FAY*, SIF*, COS*, TIS*, … |
| **Partner (Trading)** | Third-party / partner goods (product segment) | DOV*, REX*, COW*, LUX*, … |

**Hard rule:** dashboard cards, API JSON, and Excel sheets all call **one** query/metrics service. If the export disagrees with the UI, the service is wrong — not the sheet.

---

## 1. What the Excel docs taught us

### 1.1 Files in `backorder-excel-docs/`

| File | Role |
|------|------|
| `Backorder_Analysis_23Jul2026(1).xlsx` | v1 position: open book, by account, snapshot, shortage diagnosis (order-level only) |
| `Backorder_Analysis_v2_23-26Jul2026.xlsx` | v2 movement: resolution log, new BOs, lost sales, open book, Dev Spec |
| `Backorder_Audit_Export_TEMPLATE.xlsx` | **Target export shape** — Filters, Summary, Loss by Reason, Episode Detail, Event Log, By Item, By Account, Acumatica Bridge |
| `BACKORDER_AUDIT_TRAIL_SPEC.md` | Full domain model: quantity ladder, episodes, events, reason families, loss measures, API, filters |
| `Sales Orders 20260726*.xlsx` | Raw Acumatica extracts (reference; duplicate rows per shipment) |

### 1.2 Business findings that must shape the product

1. **Acumatica overstates row counts** — 84 export rows ≈ 74 distinct orders (shipment-level duplication). Always `COUNT(DISTINCT order_nbr)`.
2. **Unbilled ≠ revenue at risk** — finance view vs operational short qty × net unit price. Report both; bridge them.
3. **Credit blocks are not shortages** — Rejected / arrears must stay out of shortage KPIs.
4. **Free / zero-value lines** keep orders “Back Order” with Unbilled = 0 (e.g. SO366417). Exclude from value KPIs; keep in qty views with a flag.
5. **Orders can regress** Shipping → Back Order (short-pick). Age clock must not reset incorrectly; use **episodes** for re-opens.
6. **Same-day grace** — large same-day orders (e.g. Goodlife) often clear in 1–2 days; optional grace before counting as structural shortage.
7. **Loss of sales due to delayed production** is the commercial headline — needs **reason families** (PRODUCTION vs others), not a flat code list alone.

### 1.3 What production already has (do not throw away)

| Piece | Location | Keep / change |
|-------|----------|----------------|
| Active backorder lines | `acumatica_backorder_lines` | Keep as **open materialised view** |
| Resolutions | `BackorderResolution` | Keep; extend with resolution type / episode link |
| Manufactured vs Trading | `FillRateBusinessCategory` | **Canonical product segment** |
| Brand / supplier / posting class | `OperationsCatalogResolver` + inventory | Brand = primary display |
| Multi-sheet export | `BackorderExcelExporter` | Rebuild sheets to match audit template + brand splits + **executive pack** + FGS stock |
| Dashboard | `app.backorders.tsx` + `operations/backorders*` | Align KPIs, filters, Manufactured/Trading cards |
| Value summary by segment | `backordersValueSummary` | Keep; extend with brand rollups |
| Inventory snapshot | `acumatica_inventory_items` via warehouse stock sync (`config/inventory.php`) | **Use FGS qty for reason support** (see §3.6) — not a live ERP call |

---

## 2. Canonical definitions (lock in code constants)

### 2.1 Quantity ladder (only short qty is “backorder”)

```
order_qty
cancelled_qty
shipped_qty
qty_on_shipments   (= committed)

net_order_qty  = order_qty - cancelled_qty
open_qty       = net_order_qty - shipped_qty
committed_qty  = qty_on_shipments
backorder_qty  = GREATEST(0, open_qty - committed_qty)   ← THE shortage
```

- **`backorder_qty`** = owed and **not** picked. Do not use `open_qty` alone for value-at-risk (inflates by goods already on a truck).
- **Line fill rate** = `shipped_qty / NULLIF(net_order_qty, 0) * 100` (exclude cancelled from denominator).
- **Revenue at risk** = `backorder_qty × net_unit_price` (ex-VAT, net of line discount).

### 2.2 Episode (unit of audit trail)

One continuous period where `(order_nbr, inventory_id)` has `backorder_qty > 0`.

```
episode_key = (order_nbr, inventory_id, episode_no)
opened_at   = first sync where backorder_qty > 0
resolved_at = first later sync where backorder_qty = 0 (and not merely "picked")
```

Re-open after resolve → new `episode_no`, **fresh clock**. Partial fills stay on the same open episode.

### 2.3 Three loss measures — never add them together

| Measure | When | Formula (concept) | Label |
|---------|------|-------------------|--------|
| **Revenue at risk** | Still open | `Σ backorder_qty × net_unit_price` | Recoverable — **not** “lost” |
| **Delayed revenue** | Resolved shipped late | `Σ shipped_late_qty × net_unit_price` | Service cost; sale happened |
| **Lost revenue** | Cancelled / write-off threshold | Cancelled short + open past channel write-off days | **True loss of sales** |

Optional weighted ranking metric: **Revenue-days delayed** = `Σ shipped_late_qty × net_unit_price × days_late`.

### 2.4 Duration

- Store **calendar days** and **working days** (Kim-Fay: Mon–Sat; exclude Sunday + Kenyan public holidays).
- Dashboard default: **working days** for ops KPIs; label which basis is active.
- Backfilled `opened_at` → duration is a **floor**; show `(min.)` and exclude from averages unless user opts in.

### 2.5 Resolution types

| Type | Meaning |
|------|---------|
| `shipped_full` | Shortage cleared by shipping |
| `shipped_partial` | Part shipped, part cancelled |
| `cancelled` | Fully cancelled while short → **lost sale** |
| `committed` | Picked (`qty_on_shipments`) but not shipped → **still open** for service |
| `order_closed` / `stale_closed` | Data/edge cases; exclude or flag |

---

## 3. Classification axes — Brand, Manufactured, Partner (Trading)

These are **three different things**. The UI and Excel must never collapse them into one ambiguous “Brand Type” column without labels.

### 3.1 Axis A — Product segment (coarse): Manufactured vs Partner (Trading)

**Source of truth:** `FillRateBusinessCategory` (`backend/app/Services/Operations/FillRateBusinessCategory.php`).

| Segment | Label in UI / Excel | Rule (inventory ID prefix, after optional product_type override) |
|---------|---------------------|-------------------------------------------------------------------|
| `manufactured` | **Manufactured** | FAY, SIF, COS, TIS, ULT, STD, SHO, ANT, URI, TOI, AIR, ALK, DIS, KLE, … |
| `trading` | **Trading (Partners)** | DOV, REX, LUX, HUG, KOT, COW, APT, BIO, DAB, ORS, VAT, HOB, DUR, FEM, MIS, MSW, IKO, CON, BIG, … |

- Trading prefixes are evaluated **before** manufactured so shared letter patterns do not mis-classify.
- Unknown prefix defaults to **Trading (Partners)** (conservative; partner/unknown stock).
- API filter: `product_segment=manufactured|trading`.
- **Every** value KPI and duration KPI must be available **split by this axis** (and total).

**Why it matters commercially**

| Segment | Typical remediation |
|---------|---------------------|
| Manufactured | Production planning, MOQ, conversion, raw materials |
| Trading (Partners) | Vendor follow-up, import lead time, KEBS, supplier payment |

Do **not** average resolution speed of manufactured into partner open risk. Keep **Active risk** and **Resolution speed** as separate tracks, each split Manufactured / Trading.

### 3.2 Axis B — Brand (fine): inventory `brand`

**Source:** `acumatica_inventory_items.brand` via `OperationsCatalogResolver`.

- Examples: “Fay Tissues”, “Sifa”, “Cosy”, partner brand names when present.
- **Canonical display field** for SKU grouping headers (`ProductListingCell`).
- Secondary item-master fields (filter / detail only, not primary KPI labels):
  - `posting_class`
  - `sub_trading_group`
  - `supplier` (often the partner supplier for trading goods)

**Dashboard**

- Filter: existing `BrandFilterCascade` (`brand` / `partner_brand` / `category`).
- Group-by option: Brand.
- KPI strip optional: top N brands by revenue at risk.

**Excel**

- Column **Brand** on Episode Detail / line sheets = inventory brand string.
- Sheet **By Brand** (new): rollup of open + lost + delayed by brand, with Product Segment column.

### 3.3 Axis C — “Manufacturer” vs “Partner” language

Business language maps as follows — use these labels consistently:

| Business says | System field | Value |
|---------------|--------------|--------|
| Manufactured / own brand / Kim-Fay manufacture | `product_segment` | `manufactured` |
| Partner / trading / third-party | `product_segment` | `trading` |
| Brand name | `brand` | free text from item master |
| Supplier / vendor | `supplier` | free text (detail / partner follow-up) |

There is **no separate Acumatica “manufacturer” entity** in the current model. “Manufactures” in the product sense = **Manufactured segment**. Do not invent a third classification table unless master data later adds an explicit manufacturer code.

### 3.4 Classification matrix (examples)

| Inventory ID | Product segment | Brand (typical) | Notes |
|--------------|-----------------|-----------------|-------|
| SIFTP0015 | Manufactured | Sifa / Sifa TP… | Production shortage codes apply |
| COSTP0004 | Manufactured | Cosy | |
| FAYFL0010 | Manufactured | Fay | |
| COWGT0001 | Trading (Partners) | Cow & Gate | Procurement / partner codes |
| DOV… | Trading (Partners) | Dove | |

### 3.5 Required splits on every summary surface

For **dashboard summary** and **Excel Summary** sheet, always show:

```
Total Revenue at Risk
  ├─ Manufactured ………………… KES X   (n episodes / lines)
  └─ Trading (Partners) ……… KES Y   (n episodes / lines)

[+ optional secondary: by Brand top 10]

Resolved: median / avg working days
  ├─ Manufactured ………………… d days
  └─ Trading (Partners) ……… d days
```

Lost revenue and PRODUCTION-family headline may further filter by reason family **within** each segment.

### 3.6 FGS inventory — decision support for reasons (not live ERP)

**FGS** (Finished Goods Store) is Kim-Fay’s primary Nairobi FG warehouse. Most SO backorder lines ship from **FGS** (line `warehouse_id`; secondary sites include MSA / TPFGS / DTC as applicable).

#### What exists today

| Fact | Detail |
|------|--------|
| Not live | Report does **not** call Acumatica on each page load. Stock is a **snapshot** from warehouse inventory cron (`AcumaticaInventorySyncService`, per-warehouse jobs in `config/inventory.php`). |
| Warehouses synced | DTC, **FGS**, FGS2, FGS2 RETURNS, MSA, EXPORT, PRMS, RMS1, TRMS |
| Storage shape | `acumatica_inventory_items` holds one row per `inventory_id` with `qty_on_hand`, `qty_available`, `default_warehouse_id`, `synced_at`. Warehouse-scoped sync writes that warehouse’s `WarehouseDetails` qty onto the row. |
| Backorder join today | `OperationsCatalogResolver::stockForInventoryIds()` → attach `qty_on_hand`, `qty_available`, `stock_shortfall` (`qty_on_hand < open_qty`). **Not explicitly labelled as FGS.** |
| Admin default | Inventory UI warehouse filter defaults to **FGS**. |

#### Requirement for the refactor

Backorder **reasons** and executive diagnosis must use **FGS stock position** (or the line’s own `warehouse_id` when it is MSA/other), not an ambiguous last-synced warehouse.

1. **Resolve stock for the line warehouse**  
   - Prefer qty for `line.warehouse_id` when that warehouse has a known snapshot.  
   - Default commercial view: **FGS** (`warehouse_id = FGS`) for Nairobi FG shortages.  
   - Surface columns: `fgs_qty_on_hand`, `fgs_qty_available`, `fgs_synced_at`, and when line warehouse ≠ FGS also `line_wh_qty_on_hand`.

2. **Prefer durable FGS qty**  
   - Ideal: store per-warehouse balances (e.g. `inventory_warehouse_balances` or JSON on item).  
   - Interim: ensure FGS stock-only sync runs on a reliable cadence and backorder join **filters** `default_warehouse_id = 'FGS'` (or dedicated FGS cache) so partner/MSA syncs do not overwrite the number used for reason checks.

3. **Stock snapshot stamp everywhere**  
   - Dashboard data-quality strip: `FGS stock as-at {synced_at}`.  
   - Excel Filters sheet: same stamp. Never present FGS qty as “live”.

#### How FGS stock helps reason codes

Use stock as **guidance for the reasoner** and as a **consistency check** — humans still set the code; the system surfaces a suggested family and a mismatch flag.

| FGS / line-warehouse stock signal | Suggests | Unlikely reasons |
|-----------------------------------|----------|------------------|
| `qty_on_hand <= 0` and segment = Manufactured | `out_of_stock_production`, `production_stockout`, `raw_material_stockout`, `order_to_make`, `conversion_*` | `did_not_pick_on_shipment`, `truck_full` as primary |
| `qty_on_hand <= 0` and segment = Trading (Partners) | `out_of_stock_procurement`, `delayed_supplier_payment`, `kebs_stickers` | Production codes |
| `qty_on_hand > 0` but `qty_available <= 0` or Available &lt; Open (oversold / committed) | Allocation pressure; may still be production/procurement root cause on *net* cover — flag **overselling** | Blind “plenty of stock” narrative |
| `qty_on_hand >= open_qty` (covers the open line) | **Not a true stock-out** → logistics / process: `did_not_pick_on_shipment`, `truck_full`, `transfer_delays`, `delay_in_delivery`, `stock_variance`, data/process codes | `out_of_stock_*` without notes (flag **reason vs stock mismatch**) |
| Line warehouse = MSA and short | `out_of_stock_msa` / transfer from FGS | Pure FGS production (unless FGS also zero) |
| Free / zero-value line | Exclude from value KPIs | Any commercial loss code |

**Derived fields (API + Episode Detail + FGS Stock sheet)**

| Field | Meaning |
|-------|---------|
| `fgs_qty_on_hand` | Snapshot on-hand at FGS |
| `fgs_qty_available` | Snapshot available at FGS (if present) |
| `fgs_synced_at` | When that snapshot was written |
| `stock_covers_open` | `fgs_qty_on_hand >= open_qty` (or line WH qty when not FGS) |
| `stock_shortfall` | On-hand &lt; open qty |
| `suggested_reason_family` | PRODUCTION / PROCUREMENT / LOGISTICS / UNCLASSIFIED from matrix above |
| `reason_stock_mismatch` | `true` when coded `out_of_stock_*` but stock covers open, or coded logistics while FGS is zero |

Dashboard: show a **stock chip** on each episode (e.g. `FGS: 100 CASE · short` / `FGS: covers open · check pick`).  
Reason edit dialog: pre-fill suggested family from FGS matrix; never auto-overwrite a human reason without confirmation.

---

## 4. Reason-code families (for “loss due to delayed production”)

Keep the existing ~33 codes; add a **family** layer (table or config):

| Family | Accountable | True supply shortage? |
|--------|-------------|------------------------|
| PRODUCTION | Manufacturing / Planning | Yes |
| PROCUREMENT | Procurement / Imports | Yes |
| LOGISTICS | Warehouse / Distribution | No |
| COMMERCIAL | Sales / Marketing / Range | No |
| DATA_PROCESS | Master data / CS | No |
| UNCLASSIFIED | — | No (unreasoned default) |

**Headline KPI (dashboard + Summary sheet):**

> Loss of sales due to delayed production  
> = Lost revenue where `reason_family = PRODUCTION`  
> always footnoted with **reason coverage %**.

PRODUCTION + PROCUREMENT = “true supply shortage” filter for ops views.

---

## 5. KPI exclusions (every card, every export total)

Set `is_excluded_from_kpi` (or filter equivalent) when:

| Rule | Why |
|------|-----|
| Free / zero-value line | Value fill rate lies |
| Order status Rejected / On Hold / Pending Approval | Not warehouse demand |
| `order_type != SO` (default) | PP leakage in extracts |
| Internal / cash-sale staff accounts | Not commercial demand |
| Stale closed data hygiene | Noise |

Excluded rows remain visible in detail grids with a chip; they do **not** enter headline totals unless the user toggles “include excluded”.

---

## 6. Shared filter contract (dashboard = export)

One payload; one `BackorderQueryBuilder` (name flexible); used by summary, grids, analytics, and Excel.

```ts
interface BackorderFilters {
  // period
  date_field: 'opened_at' | 'resolved_at' | 'order_date';  // default opened_at
  date_from?: string;
  date_to?: string;
  date_preset?: string;

  // state
  state: 'open' | 'resolved' | 'all';           // default open
  resolution_type?: string[];
  age_bucket?: string[];
  duration_basis: 'working_days' | 'calendar_days';

  // cause
  reason_family?: string[];
  reason_code?: string[];
  true_supply_shortage_only?: boolean;
  unreasoned_only?: boolean;

  // commercial
  channel?: string[];
  customer_class?: string[];
  main_account_id?: string[];
  customer_acumatica_id?: string[];
  segment?: string[];                 // KP / CS (customer)

  // *** product classification (required) ***
  product_segment?: ('manufactured' | 'trading')[];  // Manufactured | Partner
  brand?: string[];
  partner_brand?: string[];
  category?: string[];
  supplier?: string[];
  inventory_id?: string[];
  item_class?: string[];              // product_line

  // supply
  warehouse_id?: string[];

  // governance (defaults matter — show in UI chips)
  order_type: string[];               // default ['SO']
  include_excluded: boolean;          // default false
  include_backfilled: boolean;        // default false for duration avgs
  include_zero_value_lines: boolean;  // default false
  include_internal_accounts: boolean; // default false

  min_revenue_at_risk?: number;
  min_days_open?: number;

  group_by?: 'none' | 'reason_family' | 'brand' | 'product_segment' | 'channel' | 'main_account' | 'item' | 'warehouse';
  sort?: string;
  page?: number;
  per_page?: number;
}
```

**UI rules**

1. Manufactured | Trading | Both is a **first-class** control (not buried).
2. Brand cascade stays next to product segment.
3. Active filters render as removable chips, including defaults (`SO only`, `Excluding backfilled`).
4. Same period label string everywhere: `20 – 25 Jul 2026` (shared formatter PHP + TS).
5. Excel **Filters & Definitions** sheet echoes this payload verbatim.

---

## 7. Dashboard refactor (`/app/backorders`)

### 7.1 Layout

```
FilterBar (sticky)
  Date preset + date_field toggle + range
  State: Open | Resolved | All
  Product segment: All | Manufactured | Trading (Partners)
  BrandFilterCascade
  Reason family / reason code
  Channel / warehouse / account
  Governance toggles (4)
  Duration basis
  Active chips + Export

DataQualityStrip
  reason coverage % | backfilled % | reconciled? | last SO sync | FGS stock as-at

KpiGrid (respect filters; always show Manufactured / Trading split cards)
  Open episodes | Open orders | Open SKUs
  Revenue at risk (total)
  Revenue at risk — Manufactured
  Revenue at risk — Trading (Partners)
  Avg / median days delay (verified starts)
  Longest open
  Lost revenue | Delayed revenue
  [Headline] Lost sales — PRODUCTION family
  Stock diagnosis mini-cards: RaR true stockout (FGS=0) | RaR stock available not shipped

Tabs
  Overview     — loss by family chart; ageing; trend; Manufactured vs Trading bars; FGS cover mix
  Episodes     — line/episode table + FGS stock chip + drawer timeline
  By Brand     — brand rollup (brand + product_segment columns)
  By Item      — SKU ranked by revenue-days delayed / at risk + FGS cover
  By Account   — main account tree
  FGS Stock    — same spine as Excel FGS Stock vs Backorder
  Reconcile    — Acumatica bridge (optional phase 2 if data ready)
```

### 7.2 Active vs Resolved tracks

| Track | Primary metric | Segment split |
|-------|----------------|---------------|
| **Active** | Revenue at risk (open short qty × price) | Manufactured vs Trading cards |
| **Resolved** | Median / avg working days to resolve; cleared value | Median days Manufactured vs Trading **side by side** |

Do not merge “fast manufactured clearance” into “partner open risk”.

### 7.3 Episode row display (minimum)

- Order, date, customer, main account  
- Inventory ID, description, **Brand**, **Product segment** badge (Manufactured | Trading)  
- Peak / current backorder qty, UOM, unit price, revenue at risk  
- Window label (`10 – 23 Jul 2026`), duration label (`13 Days Delay` / `(min.)`)  
- Reason + family, resolution type if resolved  
- **FGS stock chip**: on hand / available / covers open? / reason–stock mismatch  
- Suggested reason family from FGS matrix (§3.6) when unreasoned

### 7.4 API alignment

Prefer evolving existing routes rather than a big-bang rename:

| Capability | Existing / target |
|------------|-------------------|
| List + filters | `GET operations/backorders` (+ resolved) |
| KPIs | `GET operations/backorders/summary` — add family loss, segment split, data_quality |
| Analytics | `GET operations/backorders/analytics` — ensure brand + product_segment distributions |
| By brand | extend analytics or `GET .../by-brand` |
| By account | `GET operations/backorders/by-account` |
| Export | `GET operations/backorders/export` — rebuild via new sheet map |
| Reason patch | `PATCH operations/backorders/{id}` |

Longer term (per audit spec): episode ledger tables + `GET /api/backorders/episodes/{id}` timeline. Phase that under the hood without breaking the current Active/Resolved UX.

---

## 8. Excel export refactor

Two packs in **one workbook** (or two download modes: `?pack=executive` vs `?pack=full`). Executive pack is the default email/WhatsApp attachment for leadership; full pack is for CS / warehouse / audit.

### 8.1 Executive pack (default — keep lean, high signal)

Sheets an MD / commercial lead will actually open. All numbers from the **same** metrics service as the dashboard.

| # | Sheet | Why executives care | Key columns / blocks |
|---|--------|---------------------|----------------------|
| E1 | **Exec Summary** | One page: “what is on fire?” | Period, as-at, open orders/SKUs, **RaR total**, RaR **Manufactured vs Trading**, lost revenue, delayed revenue, avg days delay, longest open, **Lost sales — PRODUCTION**, reason coverage %, **FGS stock as-at**, reconciliation ✓/✗ |
| E2 | **Manufactured vs Trading** | Production vs partner remediation path | Side-by-side: open episodes, RaR, lost, delayed, avg/median days, top 5 SKUs each segment |
| E3 | **Loss by Reason** | Accountability (who owns the loss) | Family totals + codes; true-supply-shortage flag; RaR / delayed / lost / KES-days / avg days |
| E4 | **Top Risk SKUs** | What to allocate / make / buy first | Inventory ID, brand, product segment, open orders, peak BO qty, RaR, days open, **FGS on hand**, **FGS available**, stock covers open?, reason family, suggested family |
| E5 | **Account Concentration** | Which customers are waiting | Main account, channel, orders, RaR, lost, oldest open days, fill-stage mix — ranked by RaR |
| E6 | **Ageing** | Service risk / write-off risk | Buckets same day / 1–3 / 4–7 / 8–14 / 15+ working days × RaR × Manufactured vs Trading |
| E7 | **FGS Stock vs Backorder** | Separates true stock-out from pick/logistics | SKU, brand, segment, open BO qty (sum), RaR, **FGS on hand**, **FGS available**, cover gap (`open − FGS`), signal (`true_stockout` / `partial` / `stock_available_not_shipped`), dominant reason, reason–stock mismatch count |
| E8 | **Filters & Definitions** | Stops numbers being quoted out of context | Echoed filters, glossary (Brand / Manufactured / Trading), FGS snapshot stamp, exclusion rules |

**Executive pack does not need** full event logs or every line — those live in the full pack. Target: **8 sheets**, printable Summary + decision sheets.

### 8.2 Full / ops pack (audit + warehouse + CS)

Align with `Backorder_Audit_Export_TEMPLATE.xlsx` **and** current brand-split exporter.

| # | Sheet | Content |
|---|--------|---------|
| 1 | **Filters & Definitions** | Same as E8 (shared) |
| 2 | **Summary** | Full KPI block (same figures as Exec Summary, more footnotes) |
| 3 | **Exec Summary** | Optional duplicate of E1 when full pack is downloaded |
| 4 | **Loss by Reason** | Family + code detail |
| 5 | **Episode Detail** | One row per episode/line — full audit + Brand + Product Segment + **FGS stock fields** |
| 6 | **Event Log** | Append-only transitions (Phase B; omit until ledger exists) |
| 7 | **Manufactured Lines** | `product_segment = manufactured` |
| 8 | **Trading (Partners) Lines** | `product_segment = trading` |
| 9 | **By Brand** | Brand rollup + segment + RaR / lost / delayed |
| 10 | **By Item** | SKU ranked by revenue-days delayed / RaR + FGS cover |
| 11 | **By Account** | Main account rollup (same spine as E5, more columns) |
| 12 | **Ageing** | Same as E6 |
| 13 | **FGS Stock vs Backorder** | Same as E7 (ops deep-dive) |
| 14 | **Reason Work Queue** | Open + unreasoned, or reason–stock mismatch, or open &gt; 48h high RaR |
| 15 | **Acumatica Bridge** | Reconciliation to SO Back Order screen |
| 16 | **Instructions** | How to read sheets + Manufactured/Trading prefixes + FGS reason matrix |

*Mandatory for Phase A full export: Filters, Summary, Loss by Reason, Episode Detail, Manufactured Lines, Trading Lines, By Brand, By Item, By Account, FGS Stock vs Backorder, Ageing. Event Log + Bridge = Phase B.*

### 8.3 Episode Detail columns (required)

Identity: Order Nbr, Type, Order Date, Customer, Main Account, Channel, Line Warehouse  

Item: Inventory ID, Description, UOM, **Brand**, **Product Segment** (Manufactured | Trading (Partners)), Supplier (optional)  

Qty / value: Order Qty, Unit Price (net), Peak BO Qty, Current BO Qty, Shipped, Shipped Late, Cancelled, Partial fills, Fill Rate %  

**FGS / stock (reason support):** FGS Qty On Hand, FGS Qty Available, FGS Synced At, Stock Covers Open?, Stock Shortfall?, Suggested Reason Family, Reason–Stock Mismatch?  

Audit: Backordered On, Resolved On, Window, Days calendar, Days working, Delay Label, Start verified?, Episode No.  

Outcome: Revenue at Risk, Delayed Revenue, Revenue-Days Delayed, Lost Revenue, Resolution Type  

Cause: Reason Code, Reason Family, Notes, Set by / at  

Governance: Excluded from KPI?, Exclusion reason, State (Open/Resolved), Sync run ids  

### 8.4 Exec Summary sheet — required blocks

1. Period label + as-at timestamp + **FGS stock as-at**  
2. Core KPIs (open episodes/orders/SKUs, RaR, lost, delayed, avg days, longest open)  
3. **Manufactured vs Trading (Partners)** value and count split (mandatory)  
4. Top 5 brands by RaR (short table)  
5. **Lost sales due to delayed production** (PRODUCTION family only) + reason coverage footnote  
6. Stock diagnosis strip: % of RaR on SKUs with FGS = 0 vs FGS covers open (logistics candidates)  
7. Data quality block (reason coverage, backfilled %, excluded count, reconciled flag)  

### 8.5 FGS Stock vs Backorder sheet — required logic

```
For each inventory_id with open backorder in filter:
  open_bo_qty   = SUM(backorder_qty)
  rar           = SUM(revenue_at_risk)
  fgs_on_hand   = snapshot qty at FGS (see §3.6)
  cover_gap     = open_bo_qty - fgs_on_hand

  signal =
    if fgs_on_hand <= 0                    → true_stockout
    else if fgs_on_hand < open_bo_qty      → partial_cover
    else                                   → stock_available_not_shipped
```

Rank by RaR descending. Colour or column-filter by `signal` so leadership sees “money stuck with stock sitting in FGS” vs “money stuck because FGS is empty”.

### 8.6 Implementation notes

- Rewrite `BackorderExcelExporter` sheet list to match §8.1–§8.2; keep `FillRateBusinessCategory` for segment sheets.  
- Add `export_pack=executive|full` query param (default **executive** for the big Export button; full from “Detailed export”).  
- Column **Brand** = inventory brand (not segment label).  
- Column **Product Segment** = Manufactured | Trading (Partners) only.  
- Never put segment labels in the Brand column.  
- Join **FGS** stock for reason support; stamp `fgs_synced_at` on Filters + Exec Summary.  
- Filters sheet must list `product_segment`, brand filters, and FGS snapshot time.  
- Prefer server-computed KPI values that match JSON summary (safer than fragile Excel formulas on large extracts). Template formulas are the **spec** for what to compute.

---

## 9. Data / pipeline work (backend)

### 9.1 Phase A — Align current report (fast, high value)

No new ledger tables required.

1. Centralise quantity + value math in one service used by list, summary, export.  
2. **FGS stock join for backorders** — resolve `fgs_qty_on_hand` / `fgs_qty_available` / `fgs_synced_at` per inventory_id; compute `stock_covers_open`, `suggested_reason_family`, `reason_stock_mismatch` (§3.6). Ensure inventory cron keeps FGS snapshot fresh (morning + midday already scheduled).  
3. Ensure every summary response includes:

   ```json
   "by_product_segment": {
     "manufactured": { "revenue_at_risk": 0, "line_count": 0, "order_count": 0 },
     "trading":      { "revenue_at_risk": 0, "line_count": 0, "order_count": 0 }
   },
   "by_brand": [ { "brand": "…", "product_segment": "manufactured", "revenue_at_risk": 0, "line_count": 0 } ],
   "stock_diagnosis": {
     "rar_true_stockout": 0,
     "rar_partial_cover": 0,
     "rar_stock_available_not_shipped": 0,
     "fgs_synced_at": null
   },
   "data_quality": { "reason_coverage_pct": 0, "backfilled_pct": 0, "excluded_count": 0 }
   ```

4. Resolved summary: median/avg days **split by product_segment**.  
5. Rebuild Excel: **executive pack** (§8.1) default + full pack (§8.2).  
6. Dashboard KPI cards + By Brand tab + FGS stock chip + stock diagnosis strip.  
7. Document formatters: `DurationFormatter` / `DateRangeFormatter` (PHP + TS + shared fixtures).

### 9.2 Phase B — Audit trail ledger (from `BACKORDER_AUDIT_TRAIL_SPEC`)

1. Migrations: `reason_code_families`, `backorder_episodes`, `backorder_line_events`, `backorder_daily_snapshots`.  
2. `DetectBackorderEventsJob` after line sync.  
3. Demote `AcumaticaBackorderLine` to open-episode materialisation.  
4. Extend `BackorderResolution` with `resolution_type`, `episode_id`, working days, lost/delayed fields.  
5. Event Log sheet + episode drawer timeline.  
6. Daily snapshot + Acumatica bridge reconciliation job.

### 9.3 Phase C — Polish

- Channel write-off thresholds for lost revenue.  
- Unreasoned work queue (open &gt; 48h + high RaR) + **reason–stock mismatch** queue.  
- Working-day holiday calendar seed.  
- Same-day grace parameter for shortage intake.  
- True multi-warehouse balances table so FGS qty never fights MSA/DTC overwrites on the same item row.

---

## 10. Display formatters (shared)

| Concept | Output |
|---------|--------|
| Duration 0 | `Same Day` |
| Duration 5 | `5 Days Delay` |
| Backfilled | `5 Days Delay (min.)` |
| Range same month | `20 – 25 Jul 2026` |
| Open episode | `10 Jul 2026 – ongoing` |
| Product segment | `Manufactured` / `Trading (Partners)` |
| Currency | KES with existing masked formatter on UI |

Ship shared fixtures asserted by PHPUnit and Vitest.

---

## 11. Acceptance criteria

### Dashboard

- [ ] Filter Manufactured-only and Trading-only changes all cards and tables consistently.  
- [ ] Brand filter + product segment filter compose correctly (AND).  
- [ ] Revenue at risk Manufactured + Trading = Total (within rounding), for the same exclusion set.  
- [ ] Resolved tab shows median days Manufactured vs Trading side by side.  
- [ ] Duration labels use working days by default and mark backfilled `(min.)`.  
- [ ] Data quality strip always visible, including **FGS stock as-at**.  
- [ ] Episodes show FGS stock chip; reason dialog can show suggested family from stock matrix.  
- [ ] Stock diagnosis mini-cards: RaR on true stockout vs stock-available-not-shipped.  
- [ ] Export download uses **identical** filters as on-screen chips.

### Excel

- [ ] **Executive pack** (default) has Exec Summary, Manufactured vs Trading, Loss by Reason, Top Risk SKUs, Account Concentration, Ageing, FGS Stock vs Backorder, Filters.  
- [ ] Filters & Definitions lists product_segment, brand filters, and FGS snapshot stamp.  
- [ ] Summary / Exec Manufactured / Trading totals match dashboard for same filter set.  
- [ ] Episode Detail (full pack) has **Brand**, **Product Segment**, and FGS stock columns, never swapped.  
- [ ] Manufactured Lines and Trading (Partners) Lines partition Episode Detail with no overlap and no drop.  
- [ ] By Brand / Top Risk SKUs rank exposure with segment + FGS cover.  
- [ ] PRODUCTION lost-sales block present with reason coverage note.  
- [ ] FGS Stock vs Backorder signals partition RaR into true_stockout / partial / stock_available_not_shipped.  
- [ ] SO-only, exclusions, and distinct order counts documented.

### Domain

- [ ] `backorder_qty` definition matches §2.1 in one code path.  
- [ ] Credit rejected / free lines excluded from value KPIs by default.  
- [ ] No double-count of RaR + delayed + lost in a single “total loss” number.  
- [ ] FGS stock used for reason support is warehouse-scoped snapshot, not claimed live; mismatch flag works.

---

## 12. Mapping: old exporter → new

| Current `BackorderExcelExporter` sheet | Disposition |
|----------------------------------------|-------------|
| Instructions | Keep → merge into Filters & Definitions + Instructions |
| Summary | Rewrite KPIs + segment + PRODUCTION + **stock diagnosis**; also feed **Exec Summary** |
| Backorders | Become **Episode Detail** (+ FGS stock columns) |
| Manufactured Lines | Keep (full pack) |
| Trading (Partners) Lines | Keep (full pack) |
| Exposure by SKU | Merge into **By Item** + executive **Top Risk SKUs** |
| Reason Summary | Become **Loss by Reason** (families + codes) |
| Customer Summary | Become **By Account** / **Account Concentration** |
| Product Summary | Fold into By Item / By Brand |
| Orders with Backorders | Optional appendix or drop if Episode Detail covers it |
| Missing Price Values | Keep as quality appendix or data_quality only |
| Resolved Backorders | Merge into Episode Detail with State = Resolved |
| *(new)* | **Exec Summary**, **Manufactured vs Trading**, **Ageing**, **FGS Stock vs Backorder**, **Reason Work Queue** |

---

## 13. Non-goals (this refactor)

- VAT inclusive/exclusive columns (ex-VAT net unit price only).  
- **Live** per-request Acumatica inventory calls — use **FGS warehouse snapshot** on a known sync cadence instead (still valuable for reasons).  
- `InItemPlan` / PlanType 68 joins (existing fulfillment heuristic stays until proven wrong).  
- Auto-writing reason codes without user confirmation (suggestions only).  
- Writing backorders or shipments into Acumatica.  
- Replacing fill-rate report (sister report; share `FillRateBusinessCategory` only).

---

## 14. Suggested implementation order

1. **FGS stock join** — attach FGS qty + cover signals + suggested reason family to backorder rows (§3.6).  
2. **Classification clarity** — payload + UI labels: Brand vs Product Segment; glossary on export.  
3. **Summary service** — KPIs + `by_product_segment` + `by_brand` + `stock_diagnosis` + `data_quality`.  
4. **Dashboard cards & filters** — Manufactured / Trading / Brand + FGS chips.  
5. **Excel executive pack** — §8.1 (default export).  
6. **Excel full pack** — §8.2 Episode Detail + segment line sheets.  
7. **Shared formatters + tests** — parity fixtures including stock signals.  
8. **Phase B ledger** — episodes, events, bridge (per audit spec).  

---

## 15. Source index

| Doc / artefact | Use for |
|----------------|---------|
| `backorder-excel-docs/BACKORDER_AUDIT_TRAIL_SPEC.md` | Episodes, events, loss math, API, filters, schema |
| `backorder-excel-docs/Backorder_Audit_Export_TEMPLATE.xlsx` | Exact export sheet structure and Summary formulas |
| `backorder-excel-docs/Backorder_Analysis_v2_*.xlsx` Dev Spec | Entry/exit rules, regression Shipping→BO, lost sales |
| `backorder-excel-docs/Backorder_Analysis_23Jul2026*.xlsx` | Concentration by account, diagnosis patterns |
| `FillRateBusinessCategory.php` | Manufactured vs Trading prefixes |
| `BackorderExcelExporter.php` | Current multi-sheet baseline |
| `AcumaticaInventorySyncService.php` + `config/inventory.php` | FGS warehouse stock snapshot |
| `OperationsCatalogResolver::stockForInventoryIds` | Current stock join (extend for FGS-scoped) |
| `docs/backorder-observation.md` §5 | Two calculation tracks (risk vs resolution speed) |
| `backorder-report.md` | Existing production surface & simplification notes |

---

*End of refactor guide. Implement dashboard and export from this file; treat the Excel template as the visual contract and the audit trail spec as the long-term data contract. Brands, Manufactured, and Partner (Trading) are first-class axes; **FGS inventory snapshot** is the stock truth used to guide and challenge reason codes; the **executive pack** is the default export for leadership.*
