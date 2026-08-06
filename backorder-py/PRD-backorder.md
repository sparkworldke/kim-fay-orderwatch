# PRD: Streamlit Backorder Analytics (Acumatica → CSV → Dashboard)

| Field | Value |
|-------|--------|
| **Product** | Kim-Fay Backorder Analytics (standalone Python / Streamlit) |
| **Location** | `backorder-py/` |
| **Version** | 1.0 |
| **Date** | 2026-07-23 |
| **Stack** | Python 3.11+, Streamlit, pandas, requests, python-dotenv |
| **Source of truth** | Acumatica Cloud ERP (`kimfay.acumatica.com`) via OAuth2 + Contract REST API |
| **UI inspiration** | `back-numbers.png`, `backorder-calculations.png`, `backorder-calculations-9.png` |
| **Credentials** | `backorder-py/credentials.md` (load into `.env`; do not hard-code secrets in source) |

---

## 1. Executive summary

Build a **standalone Streamlit app** that:

1. **Authenticates** to Acumatica with OAuth2 password grant (credentials from `credentials.md`).
2. **Pulls sales orders** (with line `Details`) from the custom endpoint **`IpayV2` / `22.200.001`**.
3. **Derives backorder lines** from line quantity fields (`OpenQty`, etc.) — not only from order `Status = 'Backorder'`.
4. **Persists** raw and cleaned extracts to **CSV** (and optional parquet) for offline re-analysis.
5. **Renders** a filterable dashboard with **dynamic KPI cards**, segment splits (**Manufactured / Trading**, **KP / CS**), and order-level calculation tables matching the Excel inspiration sheets.
6. Uses **unit prices exclusive of VAT** for all value math.

This tool is a **lightweight analytics sandbox** complementary to OrderWatch (Laravel/React). It does not replace production OrderWatch sync, but formulas and guardrails must stay **aligned** with OrderWatch / Acumatica docs so numbers reconcile.

---

## 2. Goals & non-goals

### 2.1 Goals

| # | Goal |
|---|------|
| G1 | Pull SO data from Acumatica API for a **user-selected date range**. |
| G2 | Save API extracts + derived line tables to **CSV**. |
| G3 | Streamlit UI with **dynamic filters**; all KPI cards recompute on selection. |
| G4 | Prices **exclusive of VAT** (use line `UnitPrice` / `CuryUnitPrice`, not VAT-inclusive extended totals). |
| G5 | Categorize products: **Manufactured** (Kim-Fay brands) vs **Trading Items** (Partner brands). |
| G6 | Categorize customers: **KP** (Kimfay Professional) vs **CS** (Consumer Sales). |
| G7 | Show **open / current outstanding** backorders and **solved (resolved / completed shortfall)** history. |
| G8 | Handle **backorders that span months** (order date in month A, still open in month B; partial fills across periods). |
| G9 | Order-level table: Order Total, Invoice Total, Back order, Order Fulfilment Rate, Order to Delivery, CSI (per Excel inspiration). |

### 2.2 Non-goals (v1)

- Writing back to Acumatica (create/update SO, reasons).
- Production multi-user auth / RBAC (Streamlit is local or internal-only).
- Full OrderWatch reason taxonomy UI (optional later).
- Inventory qty-on-hand deep sync (optional enrich only).

---

## 3. Credentials & environment

**Source file:** `backorder-py/credentials.md`  
**Runtime:** copy values into `backorder-py/.env` (gitignored). App loads via `python-dotenv`.

| Variable | Purpose | Value (from credentials) |
|----------|---------|---------------------------|
| `ACUMATICA_BASE_URL` | Tenant host | `https://kimfay.acumatica.com` |
| `ACUMATICA_TOKEN_URL` | OAuth token endpoint | `https://kimfay.acumatica.com/identity/connect/token` |
| `ACUMATICA_CLIENT_ID` | OAuth client | see `credentials.md` |
| `ACUMATICA_CLIENT_SECRET` | OAuth secret | see `credentials.md` |
| `ACUMATICA_USERNAME` | Integration user | `ipay` |
| `ACUMATICA_PASSWORD` | Integration password | see `credentials.md` |
| `ACUMATICA_ENDPOINT` | Contract endpoint name | `IpayV2` |
| `ACUMATICA_VERSION` | Endpoint version | `22.200.001` |
| `ACUMATICA_TENANT` | Company / tenant | `Kim-Fay Limited` |
| `VAT_RATE` | Optional reverse-calc only | `0.16` (Kenya standard; **do not add VAT to prices**) |
| `PAGE_SIZE` | OData page size | `100` (max ~200) |
| `DATA_DIR` | CSV output folder | `./data` |

**Security guardrails**

- Never commit real secrets to git; only `credentials.md` / `.env` (local).
- Mask secrets in Streamlit UI (show last 4 chars of client id only).
- Token in memory only; cache with short TTL (e.g. 15–20 min), refresh on 401.

---

## 4. Acumatica API integration

### 4.1 Authentication (OAuth2 password grant)

```http
POST {ACUMATICA_TOKEN_URL}
Content-Type: application/x-www-form-urlencoded

grant_type=password
&client_id={ACUMATICA_CLIENT_ID}
&client_secret={ACUMATICA_CLIENT_SECRET}
&username={ACUMATICA_USERNAME}
&password={ACUMATICA_PASSWORD}
&scope=api
```

Response: `access_token` → send as `Authorization: Bearer {token}` on all entity calls.

Entity base:

```
{ACUMATICA_BASE_URL}/entity/{ACUMATICA_ENDPOINT}/{ACUMATICA_VERSION}/
```

Example:

```
https://kimfay.acumatica.com/entity/IpayV2/22.200.001/
```

### 4.2 Backorder fetch strategy (critical)

**Do not** rely only on `Status eq 'Backorder'`.

OrderWatch and this PRD **fetch open sales orders** and **derive** backorder lines from **line qty fields**:

```http
GET /entity/IpayV2/22.200.001/SalesOrder
  ?$top=100
  &$skip=0
  &$filter=OrderType eq 'SO'
    and Status ne 'Completed'
    and Status ne 'Cancelled'
    and Status ne 'Canceled'
    and Status ne 'Rejected'
  &$expand=Details
```

**Date-ranged import** (user-selected From / To):

```http
GET .../SalesOrder
  ?$filter=OrderType eq 'SO'
    and Status ne 'Completed' and Status ne 'Cancelled'
    and Status ne 'Canceled' and Status ne 'Rejected'
    and Date ge datetimeoffset'{from}T00:00:00'
    and Date le datetimeoffset'{to}T23:59:59'
  &$expand=Details
  &$top=100&$skip={n}
```

**Optional second pass — historical / solved backorders** (completed orders that had shortfall in period):

```http
GET .../SalesOrder
  ?$filter=OrderType eq 'SO'
    and Date ge datetimeoffset'{from}T00:00:00'
    and Date le datetimeoffset'{to}T23:59:59'
  &$expand=Details
```

Use this pass for **fill-rate style** Order Total / Invoice Total / Fulfilment Rate tables (Excel inspiration), including fully completed SOs.

### 4.3 Expand name: `Details` not `DocumentDetails`

On **IpayV2 `22.200.001`**, line items are exposed as **`Details`**.  
Using `$expand=DocumentDetails` causes OData `KeyNotFoundException`.

Never use nested `$select` on Details until fields are confirmed in:

```
GET /entity/IpayV2/22.200.001/SalesOrder/$adHocSchema
```

### 4.4 Header fields (SalesOrder)

| Field | Required | Use |
|-------|----------|-----|
| `OrderNbr` | Yes | SO identifier (e.g. SO359099) |
| `OrderType` | Yes | Filter `SO` only |
| `Status` | Yes | Open / Backorder / Completed / etc. |
| `CustomerID` | Yes | Customer link |
| `CustomerName` | Recommended | UI search / tables |
| `Date` | Yes | Date range + month-spanning logic |
| `RequestedOn` | Optional | SLA / order-to-delivery |
| `ScheduledShipmentDate` | Optional | Scheduling |
| `CurrencyID` / `CuryID` | Recommended | Currency display (KES) |
| `CustomerClass` / class via customer | Recommended | KP vs CS segment |
| `OrderTotal` / `CuryOrderTotal` | Optional | Cross-check; prefer line rollup ex-VAT |
| `Branch` / location | Optional | Region / warehouse context |

### 4.5 Line fields (`Details[]`) — critical for backorders

| Field | Required | Use |
|-------|----------|-----|
| `InventoryID` | Yes | SKU |
| `OrderQty` / `OrderedQty` | Yes | Ordered qty |
| `ShippedQty` | Yes | Shipped qty |
| **`OpenQty`** | **Yes** | **Primary backorder qty** |
| `CancelledQty` | Recommended | Derive open when needed |
| `UnitPrice` / `CuryUnitPrice` | Yes | **Ex-VAT unit price** for value |
| `WarehouseID` / `SiteID` | Optional | Warehouse filter |
| `UOM` | Optional | Display |
| `LineNbr` | Optional | Stable line key |
| `Description` / `TransactionDescr` | Optional | Product name + brand classify |
| `Completed` | Optional | Fulfillment status |
| `UsrQtyAtApproval` | Optional | Fill-rate denominator fallback |
| `ReasonCode` | Optional | Root cause |

**Example line shape (probe):**

```json
{
  "InventoryID": { "value": "ITEM-001" },
  "OrderQty":    { "value": 10 },
  "ShippedQty":  { "value": 4 },
  "OpenQty":     { "value": 6 },
  "UnitPrice":   { "value": 100 }
}
```

Backorder value for that line (ex-VAT): `6 × 100 = 600`.

### 4.6 Pagination

- Page size: 100 (configurable; hard cap 200).
- Loop `$skip` until page length &lt; page size or empty.
- Inter-page delay ~0.3–0.5s to avoid rate limits / SSL timeouts.
- Max pages guard (e.g. 500) with clear UI error if hit.

### 4.7 Related reference docs (repo)

| Doc | Relevance |
|-----|-----------|
| `docs/acumatica-endpoint-instructions.md` | IpayV2 fields, OpenQty, Details expand |
| `docs/acumatica-integration-guide(1-09).md` | Backorder + fill rate + revenue formulas |
| `docs/notifications-and-backorders.md` | Correct value = open qty × unit price |
| `acumatica-payload/acumatica-sales-order-payload.reference.json` | Token + fetch template |
| OrderWatch `AcumaticaClient::openSalesOrdersForBackordersFilter()` | Production filter parity |

---

## 5. Domain model & formulas

All money figures are **KES, exclusive of VAT**, using line **unit price** (not document totals with tax).

### 5.1 Line-level quantities

| Metric | Formula |
|--------|---------|
| **Open / backorder qty** | Prefer `OpenQty` if present (including explicit `0`). Else `max(OrderQty − ShippedQty − CancelledQty, 0)`. |
| **Shipped qty** | Prefer `ShippedQty`; fallback `QtyOnShipments` if present. |
| **Ordered qty** | `OrderQty` (or `OrderedQty`). |

A line is an **active backorder line** when:

```
open_qty > 0
AND line not fully cancelled
AND order status not in {Completed, Cancelled, Canceled, Rejected}  # for "current open" views
```

### 5.2 Line-level values (ex-VAT)

| Metric | Formula | Card / Excel label |
|--------|---------|-------------------|
| **Backorder value** | `open_qty × unit_price` | Backorder value — *Unshipped remainder × unit price* |
| **Invoiced value** | `shipped_qty × unit_price` | Invoiced value — *Shipped qty × unit price* |
| **Order value** | `ordered_qty × unit_price` ≈ invoiced + backorder (+ cancelled residual) | Order value — *Ordered qty × unit price* |

**Guardrail:** Do **not** use Acumatica document `OrderTotal` / invoice grand total as “backorder value”.  
Example from production: SO359099 document total **570,000** vs true missing line **460 × 24 = 11,040**.

### 5.3 Order-level rollup (Excel inspiration)

From `backorder-calculations.png` / `backorder-calculations-9.png`:

| Column | Definition |
|--------|------------|
| **Region / SO** | Display key = `OrderNbr` (sheet header says “Region” but rows are SO numbers). |
| **Order Total** | Sum over lines of `ordered_qty × unit_price` (ex-VAT). |
| **Invoice Total** | Sum over lines of `shipped_qty × unit_price` (ex-VAT). Proxy for invoiced/shipped value when invoice entity not pulled. |
| **Back order** | `Order Total − Invoice Total` (or sum of `open_qty × unit_price` when open-only; must document which mode is active). |
| **Order Fulfilment Rate** | `(Invoice Total ÷ Order Total) × 100` when Order Total &gt; 0; else `N/A`. |
| **Order to Delivery** | Placeholder / optional: % of lines with ship date ≤ requested date, or 100% if fully shipped with no delay data. Default **100%** when no delivery dates; **50%** style partial when only half lines have delivery evidence (v1: compute if dates exist, else show `N/A`). |
| **CSI** | Customer Service Index — v1 definition: **same as Order Fulfilment Rate** (matches sample rows where CSI = Fulfilment). Document if product later redefines CSI. |

### 5.4 Fill rate (line → order → period)

```
Fill Rate % = (Σ shipped_qty ÷ Σ ordered_qty) × 100
```

- Roll up by summing qty across lines first; **do not** average per-order rates.
- Cap shipped at ordered so rate never exceeds 100%.
- Status On Hold / Pending Approval → show **N/A**, not 0%.
- Zero ordered qty → skip (no divide-by-zero).

### 5.5 Product category: Manufactured vs Trading Items

| UI label | Meaning | Rule |
|----------|---------|------|
| **Manufactured** | Kim-Fay brands / own production | Default when no trading brand match |
| **Trading Items** (Trading / Partners) | Partner brands distributed by Kim-Fay | Match brand patterns on description + inventory ID |

**Trading brand patterns** (case-insensitive; align with OrderWatch `ProductBrandClassifier`):

Huggies, Kotex, Vatika, Dabur, Miswak, Bio-Oil, Duracell, Dove, Lux, Rexona, Fem, Hobby, ORS, Dermoviva.

Segment cards (from `back-numbers.png`):

- **Manufactured** — Kim-Fay products — backorder value (+ invoiced subtext).
- **Trading (Partners)** — Third-party brands — backorder value (+ invoiced subtext).

### 5.6 Customer segment: KP vs CS

| Segment | Rule |
|---------|------|
| **KP** | Customer class starts with `KP` (case-insensitive) — Kimfay Professional |
| **CS** | All other classes — Consumer Sales |

Cards: **KP** and **CS** backorder (and invoiced) values for the active filter set.

### 5.7 Solved / completed shortfall

| Concept | Definition |
|---------|------------|
| **Active / open backorder** | `open_qty > 0` on a non-terminal order. |
| **Completed shortfall** | Order/line historically under-shipped but now **cleared** (`open_qty = 0`) while `shipped_qty < ordered_qty` at some point, or order completed with residual shortfall recorded. In Streamlit v1: lines where `ordered_qty > shipped_qty` and `open_qty = 0` and status Completed → **completed shortfall value** = `(ordered − shipped) × unit_price`. |
| **Current outstanding** | Live open balance = sum of active backorder value for filtered set. |
| **Solved backorders** | Toggle / tab listing lines that had open qty in a prior extract but are now fully shipped or cancelled; or completed shortfall lines for the date range. |

### 5.8 Backorders shared across months

Backorders often **start in month M and remain open in M+1**.

| Rule | Behaviour |
|------|-----------|
| **Order-date window** | Default date filter = SO `Date` in [From, To] (Acumatica `$filter` on `Date`). |
| **Still-open overlay** | “Include open BOs ordered before From but still open as of To” optional checkbox — second API query without lower date bound (or open-orders fetch), then keep lines with `open_qty > 0` and `order_date < From`. |
| **No double count** | Line key = `(OrderNbr, LineNbr or InventoryID)`; de-dupe when merging range + still-open sets. |
| **Month attribution cards** | When reporting a single month, **active BO value** = open lines as of end of month; **created in month** = lines with order date in month; show both if space. |
| **CSV columns** | Always store `order_date`, `open_qty`, `synced_at` so multi-month analysis is possible offline. |

---

## 6. CSV persistence

### 6.1 Output files (under `DATA_DIR`)

| File | Contents |
|------|----------|
| `raw_sales_orders_{from}_{to}.csv` | Flattened SO headers (one row per order). |
| `raw_sales_order_lines_{from}_{to}.csv` | One row per Detail line + parent OrderNbr, Date, CustomerID, Status. |
| `backorder_lines_{from}_{to}.csv` | Derived active (and optional completed-shortfall) lines with formulas applied. |
| `order_calc_{from}_{to}.csv` | Order-level Order Total / Invoice Total / Back order / Fulfilment / CSI. |
| `last_sync_meta.json` | Timestamp, filters used, row counts, API errors. |

### 6.2 Minimum CSV columns — `backorder_lines_*.csv`

```
order_nbr, order_date, status, customer_id, customer_name, customer_segment,
inventory_id, description, brand, product_type, warehouse_id, uom, line_nbr,
ordered_qty, shipped_qty, open_qty, cancelled_qty, unit_price_ex_vat,
order_value, invoiced_value, backorder_value, fulfillment_status, reason_code,
is_active_backorder, is_completed_shortfall, synced_at
```

### 6.3 Offline mode

Streamlit can run **from last CSV** without calling Acumatica (toggle: “Use cached CSV”). Useful for demos and reconciling Excel.

---

## 7. Streamlit UI (inspired by `back-numbers.png`)

### 7.1 Layout

```
┌──────────────────────────────────────────────────────────────────────────┐
│  Kim-Fay Backorder Analytics                          [Refresh from API] │
├──────────────────────────────────────────────────────────────────────────┤
│  FILTERS                                                                 │
│  Brand group | Brand | Category (optional)                               │
│  Search SO / Customer / Product                                          │
│  Date preset | From | To          (default: current month to date)       │
│  Product line | Customer group | Warehouse | Order state | Root cause    │
│  ☑ Include still-open BOs ordered before range                           │
│  ☑ Include completed shortfall (solved)                                  │
├──────────────────────────────────────────────────────────────────────────┤
│  KPI ROW 1                                                               │
│  [Backorder value KES]  [Invoiced value KES]  [Order value KES]          │
├──────────────────────────────────────────────────────────────────────────┤
│  KPI ROW 2 — by segment                                                  │
│  [Manufactured] [Trading (Partners)] [KP] [CS]                           │
├──────────────────────────────────────────────────────────────────────────┤
│  KPI ROW 3                                                               │
│  [Open lines] [SKUs] [Open orders] [Completed shortfall] [Outstanding]   │
├──────────────────────────────────────────────────────────────────────────┤
│  TABS: Active lines | Order calculations | Solved / shortfall | Export   │
└──────────────────────────────────────────────────────────────────────────┘
```

### 7.2 Filters (dynamic — cards recompute on any change)

| Control | Default | Behaviour |
|---------|---------|-----------|
| Brand group | All brand groups | Manufactured / Trading / All |
| Brand | All brands | Filtered list from data |
| Category | All categories | Optional posting class / item class |
| Search | empty | Match SO #, customer id/name, inventory id, product name |
| Date preset | This Month | Presets: Today, Yesterday, This Week, This Month, Last Month, Custom |
| From / To | Month start → today | Inclusive; drives API pull and/or CSV filter |
| Product line | All | From inventory / line metadata if available |
| Customer group | All | KP / CS / raw class if present |
| Warehouse | All | From line WarehouseID |
| Order state | All shortfalls | Active BO / Partially shipped / Completed shortfall / All |
| Root cause | All reasons | From ReasonCode if present |

**Dynamic cards rule:** Every KPI is a pure function of the **filtered dataframe**. Changing any filter recalculates totals immediately (no page reload required beyond Streamlit rerun).

### 7.3 KPI card definitions (match UI copy)

| Card | Color hint | Subtitle | Formula on filtered set |
|------|------------|----------|-------------------------|
| **Backorder value** | Red | Unshipped remainder × unit price | `Σ open_qty × unit_price` (active lines) |
| **Invoiced value** | Green | Shipped qty × unit price | `Σ shipped_qty × unit_price` |
| **Order value** | Neutral | Ordered qty × unit price (invoiced + backorder) | `Σ ordered_qty × unit_price` |
| **Manufactured** | Red | Kim-Fay products | Backorder value where `product_type = manufactured` (+ invoiced subtext) |
| **Trading (Partners)** | Red | Third-party brands | Backorder value where `product_type = trading` |
| **KP** | Red | Kimfay Professional customers | Backorder value where segment = KP |
| **CS** | Red | Consumer Sales customers | Backorder value where segment = CS |
| **Open lines** | Neutral | Count | Count of active backorder lines |
| **SKUs (Inventory IDs)** | Neutral | Distinct | `nunique(inventory_id)` on active lines |
| **Open orders** | Neutral | Distinct SO | `nunique(order_nbr)` on active lines |
| **Completed shortfall** | Neutral | Historical residual | `Σ (ordered − shipped) × unit_price` where completed shortfall |
| **Current outstanding** | Neutral | Live open balance | Same as Backorder value for active open set |

Currency format: `KES X,XXX.XX` (2 decimals).

### 7.4 Tab: Active backorder lines

Table columns: Order #, Date, Customer, Segment, Inventory ID, Description, Brand, Type (Manufactured/Trading), Ordered, Shipped, Open, Unit price (ex-VAT), Backorder value, Warehouse, Status, Reason.

Sort default: Backorder value descending.

### 7.5 Tab: Order calculations (Excel inspiration)

Columns:

| Region (SO) | Order Total | Invoice Total | Back order | Order Fulfilment Rate | Order to Delivery | CSI |
|-------------|-------------|---------------|------------|----------------------|-------------------|-----|

- Sortable by CSI / Fulfilment ascending (worst first), matching the sample sheet.
- Highlight rows with Fulfilment &lt; 80% (critical) / 80–94% (at risk) / ≥ 95% (healthy).

### 7.6 Tab: Solved / completed shortfall

- Lines or orders that cleared open qty in the period, or completed with residual shortfall.
- Supports “consider solved backorders” from the original brief.

### 7.7 Tab: Export

- Download current filtered CSVs.
- Button: “Re-pull from Acumatica” (shows progress bar, page count).

### 7.8 Sidebar

- Connection status (token OK / last error).
- Last sync time.
- Endpoint / version display (`IpayV2` / `22.200.001`).
- Offline vs Live mode.

---

## 8. Application structure (proposed)

```
backorder-py/
├── PRD-backorder.md          # this document
├── credentials.md            # secrets (local)
├── .env                      # runtime config (gitignored)
├── .env.example              # placeholder keys only
├── requirements.txt
├── README.md
├── app.py                    # Streamlit entry
├── src/
│   ├── config.py             # load env
│   ├── acumatica/
│   │   ├── auth.py           # OAuth2 token
│   │   ├── client.py         # GET SalesOrder pages
│   │   └── parsers.py        # unwrap { "value": ... } fields
│   ├── domain/
│   │   ├── quantities.py     # open/shipped/backorder qty
│   │   ├── values.py         # ex-VAT money formulas
│   │   ├── brands.py         # Manufactured vs Trading
│   │   ├── segments.py       # KP vs CS
│   │   └── order_calc.py     # Order Total / Invoice / Fulfilment / CSI
│   ├── storage/
│   │   └── csv_store.py      # read/write CSV + meta
│   └── ui/
│       ├── filters.py
│       ├── kpi_cards.py
│       └── tables.py
└── data/                     # CSV outputs (gitignored)
```

### 8.1 `requirements.txt` (minimum)

```
streamlit>=1.32
pandas>=2.1
requests>=2.31
python-dotenv>=1.0
```

### 8.2 Run

```bash
cd backorder-py
python -m venv .venv
.venv\Scripts\activate          # Windows
pip install -r requirements.txt
copy credentials.md values into .env
streamlit run app.py
```

---

## 9. Acceptance criteria

### 9.1 Auth & API

| ID | Criterion | Pass |
|----|-----------|------|
| AC-01 | App loads client id/secret/user/password/endpoint from env (sourced from `credentials.md`) | Token request returns 200 + `access_token` |
| AC-02 | SalesOrder list uses `$expand=Details` (not DocumentDetails) | No KeyNotFoundException on expand |
| AC-03 | Filter is `OrderType eq 'SO'` and status excludes Completed/Cancelled/Canceled/Rejected for open BO pull | Matches OrderWatch filter semantics |
| AC-04 | Date range filter uses `Date ge/le datetimeoffset'...'` | Pull limited to user From/To |
| AC-05 | Pagination walks all pages until exhausted | Row count stable on re-pull; meta records page count |
| AC-06 | 401 mid-run refreshes token once and retries | Recover without full restart |

### 9.2 Derivation & pricing

| ID | Criterion | Pass |
|----|-----------|------|
| AC-10 | Backorder qty prefers `OpenQty` | Matches sample line OpenQty |
| AC-11 | Fallback open = order − shipped − cancelled when OpenQty missing | Documented in CSV `open_qty_source` optional column |
| AC-12 | Unit price from `CuryUnitPrice` / `UnitPrice` | Never VAT-inclusive document total as unit price |
| AC-13 | All KPI values ex-VAT | No × 1.16 on display values |
| AC-14 | Backorder value = open × unit price | Example SO359099-style line: 460×24=11040, not 570000 |
| AC-15 | Order value ≈ invoiced + backorder (within rounding, ignoring cancelled) | Card identity holds on clean data |

### 9.3 Categories & segments

| ID | Criterion | Pass |
|----|-----------|------|
| AC-20 | Trading brands list classifies Huggies/Duracell/etc. as Trading Items | Spot-check 10 SKUs |
| AC-21 | Non-matching SKUs = Manufactured | Default path |
| AC-22 | KP = customer class prefix `KP`; else CS | Segment cards sum to total BO value |

### 9.4 Streamlit UX

| ID | Criterion | Pass |
|----|-----------|------|
| AC-30 | Default date = current month to date | On first load |
| AC-31 | Changing From/To or any filter recalculates all cards | Totals change without manual “Calculate” |
| AC-32 | Cards match labels/subtitles from `back-numbers.png` | Visual review |
| AC-33 | Order calculations table has Order Total, Invoice Total, Back order, Fulfilment %, CSI | Matches Excel columns |
| AC-34 | Search filters SO / customer / product | Partial string match |
| AC-35 | Export downloads filtered CSV | File opens in Excel |
| AC-36 | Offline mode works from last CSV when API unavailable | KPIs still render |

### 9.5 Solved & cross-month

| ID | Criterion | Pass |
|----|-----------|------|
| AC-40 | Completed shortfall lines appear when toggle on | Value &gt; 0 for known completed under-ships |
| AC-41 | “Include still-open before range” adds open lines with order_date &lt; From | No duplicate (OrderNbr, line) keys |
| AC-42 | Active vs solved clearly separated in UI | Two tabs or order-state filter |

### 9.6 Guardrail tests (automated preferred)

| ID | Test |
|----|------|
| GT-01 | `open_qty=0` and full ship → not counted as active BO |
| GT-02 | `open_qty=6`, price=100 → value 600.00 |
| GT-03 | Order with only cancelled remainder → not active BO |
| GT-04 | Divide-by-zero: Order Total 0 → Fulfilment `N/A` |
| GT-05 | Over-ship does not produce fill rate &gt; 100% |
| GT-06 | De-dupe merge of range + still-open sets |

---

## 10. Guardrails (Acumatica + business)

### 10.1 API / endpoint

1. **Never** `$expand=DocumentDetails` on IpayV2 — use **`Details`**.
2. Avoid `$select` until `$adHocSchema` confirms fields (prevents KeyNotFoundException).
3. Do not filter only `Status eq 'Backorder'` for “all shortfalls”; derive from line qty.
4. Page size ≤ 200; back off on timeouts; log failed pages without discarding whole run.
5. Probe one known BO order after config change:

   `GET .../SalesOrder/{OrderNbr}?$expand=Details`

### 10.2 Quantity & value

6. Prefer **OpenQty** for missing/backorder qty; never treat full OrderQty as backorder when OpenQty is a smaller remainder.
7. Value = **qty × unit price**, never invoice/document grand total.
8. **Prices exclusive of VAT** — use line unit price fields; do not apply VAT uplift.
9. Cancelled qty reduces open derivation when OpenQty absent.
10. Display currency as KES with 2 decimals; store full float precision in CSV.

### 10.3 Status & eligibility

11. Exclude Completed / Cancelled / Canceled / Rejected from **active open** pull filter.
12. On Hold / Pending Approval: fulfilment rate **N/A**, not 0%.
13. Only `OrderType eq 'SO'` (exclude QT, CM, etc. unless a future toggle is added).

### 10.4 Classification

14. Manufactured vs Trading is deterministic from brand patterns; unknown → Manufactured.
15. KP vs CS is exhaustive (no third bucket).
16. Brand group filter must not drop rows with null brand when “All” selected.

### 10.5 Time & double counting

17. Date filter is inclusive on both ends (Africa/Nairobi calendar dates).
18. Cross-month still-open merge must de-dupe by order + line.
19. CSV `synced_at` required for any “solved since last run” logic.
20. Do not sum “revenue lost fill rate” and “revenue lost backorders” into one number when they overlap — show cards separately (OrderWatch integration guide rule).

### 10.6 Data quality flags

21. Flag lines with `unit_price <= 0` (Missing Price).
22. Flag lines with missing `InventoryID`.
23. Surface API partial-failure counts in sidebar (orders fetched vs lines saved).

### 10.7 Security

24. Secrets only from env / `credentials.md`; never printed in Streamlit full text.
25. Do not upload production CSVs with customer data to public locations.

---

## 11. Alignment with OrderWatch (for reconciliation)

| Concern | OrderWatch | This Streamlit app |
|---------|------------|--------------------|
| Auth | Laravel encrypted config | `.env` from `credentials.md` |
| Open SO filter | `openSalesOrdersForBackordersFilter()` | Same OData filter |
| Line expand | `Details` | `Details` |
| Open qty | `SalesOrderLineFulfillmentDeriver::resolveOpenQty` | Same rules |
| BO value | open × unit price | Same |
| Brands | `ProductBrandClassifier` | Port same pattern list |
| KP/CS | customer class prefix KP | Same |
| UI | React `/app/backorders` | Streamlit port of card layout |

When numbers disagree: re-pull same date range, compare `backorder_lines_*.csv` open qty and unit price to Acumatica UI line fields first.

---

## 12. Implementation phases

| Phase | Scope | Done when |
|-------|--------|-----------|
| **P0** | Config, auth, paginated SalesOrder+Details pull, CSV write | CSV has real SO lines from IpayV2 |
| **P1** | Qty/value derivation, brand + KP/CS, offline CSV load | Unit tests on formulas pass |
| **P2** | Streamlit filters + dynamic KPI cards (back-numbers layout) | Visual match to inspiration |
| **P3** | Order calculations table (Excel columns), solved shortfall, cross-month toggle | AC-30–AC-42 pass |
| **P4** | Polish: progress bar, error panel, export, README | Handoff-ready |

---

## 13. Original brief (preserved)

> - Pull SO from Acumatica using API  
> - Save in CSV  
> - Work with Streamlit  
> - Price should be exclusive of VAT  
> - Dates are dynamic — user can select dates  
> - Cards displaying totals are dynamic on selection  
> - Products categorized by Brands: Kimfay Brands → **Manufactured**; Partner Brands → **Trading Items**  
> - Acceptance criteria and Guardrails using Acumatica documentation on BackOrder API from Sales Order  
> - Consider **solved** backorders  
> - Consider backorders that are **shared within months**

---

## 14. Open questions (resolve during P1 if needed)

1. Should Invoice Total use true **Sales Invoice** entity (`fetchSalesInvoicesForSalesOrders`) when available, or always **shipped_qty × unit_price**?  
   - **Recommendation v1:** shipped × unit price (simpler, matches line-level truth).
2. Is CSI formally identical to Order Fulfilment Rate, or a weighted blend with Order-to-Delivery?  
   - **Recommendation v1:** CSI = Fulfilment Rate (matches sample sheet).
3. Confirm trading brand list completeness with commercial team.
4. Preferred timezone for date boundaries: **Africa/Nairobi** (assumed).

---

## 15. References

- UI mock: `back-numbers.png`
- Excel calc samples: `backorder-calculations.png`, `backorder-calculations-9.png`
- Credentials: `backorder-py/credentials.md`
- Acumatica endpoint instructions: `docs/acumatica-endpoint-instructions.md`
- Acumatica integration guide: `docs/acumatica-integration-guide(1-09).md`
- Backorder calculation notes: `docs/notifications-and-backorders.md`
- Payload reference: `acumatica-payload/acumatica-sales-order-payload.reference.json`
- OrderWatch production client: `backend/app/Services/Admin/AcumaticaClient.php`
- Fulfillment deriver: `backend/app/Services/Admin/SalesOrderLineFulfillmentDeriver.php`
- Brand classifier: `backend/app/Services/Admin/ProductBrandClassifier.php`
