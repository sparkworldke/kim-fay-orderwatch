# Kim-Fay Backorder Analytics (Streamlit)

Standalone Python/Streamlit analytics sandbox that pulls sales orders from
Acumatica (`IpayV2` / `22.200.001`), derives backorder lines from **line qty
fields** (never `Status eq 'Backorder'` alone), persists CSV extracts, and
renders a filterable dashboard with dynamic KPI cards. See
[`PRD-backorder.md`](./PRD-backorder.md) for the full spec, formulas, and
acceptance criteria this app implements.

It is complementary to OrderWatch (Laravel/React) — same filters, same open
qty / unit price rules, same brand and KP/CS classification — so numbers
reconcile between the two.

## Setup

```bash
cd backorder-py
python -m venv .venv
.venv\Scripts\activate          # Windows
pip install -r requirements.txt
```

Copy the values from `credentials.md` into `.env` (already gitignored; see
`.env.example` for the shape). Never commit `.env` or `credentials.md`.

## Run

```bash
streamlit run app.py
```

- **Refresh from API** pulls sales orders for the selected date range and
  writes CSV extracts to `./data`.
- **Offline mode** (sidebar) loads the last cached CSV extract for the
  selected date range instead of calling Acumatica — useful for demos or
  reconciling against Excel without API access.
- **Include still-open BOs ordered before range** runs a second, unbounded
  open-orders query and merges in lines with `open_qty > 0` and an order date
  before the selected "From", de-duped by `(OrderNbr, LineNbr)`.
- **Include completed shortfall (solved)** switches the primary pull to the
  status-unfiltered "fill-rate" query so completed orders with historical
  shortfall are available in the "Solved / shortfall" tab.
- **Only Status = Backorder (narrower/faster pull)** adds `Status eq
  'Backorder'` to the fetch. Off by default — the PRD's guardrail (section
  4.2) is to derive backorders from line `OpenQty` across *all* non-terminal
  statuses, since the header Status label can miss genuinely backordered
  lines sitting on orders in other open statuses. Turn this on only when you
  deliberately want a smaller/faster pull; expect numbers to diverge from
  OrderWatch when it's on.

## `new-prd.md` — Value at Risk, VAT breakdown, Back Order Reason

[`new-prd.md`](./new-prd.md) asks for a more rigorous back-order signal
(`InItemPlan`/`PlanType=68` allocation join) plus Acumatica-native VAT detail
and live inventory. A live probe of `IpayV2/22.200.001` confirmed none of
`InItemPlan`, `SOLineSplit`, `SOTaxTran`, `INSiteStatus`, or even
`InventoryItem` are exposed on this tenant (all 404) — see
[`ACUMATICA-ENDPOINT-EXTENSION-NEEDED.md`](./ACUMATICA-ENDPOINT-EXTENSION-NEEDED.md)
for exactly what to ask your Acumatica admin to publish.

Until that ships, this app implements the subset that's buildable without
it, clearly labelled as interim:

- **Value at Risk tab** — headline Net/VAT/Gross card, breakdowns by
  Inventory Item / Customer / Brand Type, aging buckets (0-7/8-14/15-30/30+
  days), a trend chart from `data/value_at_risk_history.csv` (appended every
  sync), and a Missing-Reason exceptions list. Figures are the existing
  `OpenQty`-derived backorder definition, **not** the `InItemPlan`-verified
  one — every view carries a disclaimer and a formula version
  (`src/domain/value_at_risk.py::FORMULA_VERSION`) so it's traceable if the
  definition changes later.
- **Net / VAT (16%) / Gross columns** on the Active Lines and Order
  Calculations tabs, reverse-calculated from the existing ex-VAT unit price
  via the configurable `VAT_RATE` — not read from Acumatica's `SOTaxTran`
  (not exposed), so "Price Basis" is always labelled as an assumption.
- **Order-total reconciliation** — the Order Calculations tab flags (never
  silently drops) any order where the computed Gross total drifts >1% from
  Acumatica's `SalesOrder.OrderTotal` header field.
- **Back Order Reason** — normalizes the existing `ReasonCode` field onto a
  controlled list (`src/domain/reasons.py`) and flags lines that are
  back-ordered past 15 days with no reason as a data-quality exception,
  surfaced in the Value at Risk tab rather than hidden.
- **Brand Type** is still the description/SKU pattern match from
  `PRD-backorder.md`, not a native Acumatica field — `new-prd.md` §11.1
  leaves open which field (if any) already drives this distinction; see the
  extension doc.

## Project layout

```
app.py                      # Streamlit entry point
src/config.py                # .env loader
src/acumatica/                # OAuth2 auth, paginated SalesOrder/Customer client, field parsers
src/domain/                   # open/shipped/backorder qty, ex-VAT values, brand + KP/CS
                               # classification, order-level rollup, raw->line-table pipeline
src/storage/csv_store.py      # CSV + last_sync_meta.json persistence, offline cache loading
src/ui/                       # filters, KPI cards, tables
tests/                        # guardrail unit tests (GT-01..GT-06) — run with `pytest`
data/                         # CSV outputs (gitignored)
```

## Guardrails this app follows

See `PRD-backorder.md` sections 5 and 10 for the full list. The load-bearing
ones:

- `$expand=Details` only — never `DocumentDetails` (causes
  `KeyNotFoundException` on IpayV2).
- Backorder qty prefers Acumatica `OpenQty` (including explicit `0`); falls
  back to `order − shipped − cancelled` only when `OpenQty` is absent.
- All money values are **ex-VAT unit price × qty** — never a document/invoice
  grand total.
- Manufactured vs Trading and KP vs CS classification ports the same rules as
  OrderWatch's `ProductBrandClassifier` / `FillRateCalculator` so figures
  reconcile.

## Tests

```bash
pytest
```

Covers the guardrail tests (GT-01 through GT-06) from `PRD-backorder.md`
section 9.6.
