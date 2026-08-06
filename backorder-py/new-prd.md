# PRD: Sales Order Details by Inventory Item — Back Order & Pricing Report

## 1. Overview
**Purpose:** Extend/rebuild Acumatica's *Sales Order Details by Inventory Item* report to give business users a single view that shows, for every open sales order:
- Which lines are genuinely **back ordered** (not just "unshipped")
- The **line-level pricing** for each item, clearly labeled as **VAT-inclusive or VAT-exclusive (16%)**

**Owner:** [fill in]
**Requested by:** [fill in]
**Target consumers:** Sales Ops, Warehouse/Fulfillment, Finance

## 2. Problem Statement
The stock "Sales Order Details by Inventory Item" report tells you Quantity, Qty on Shipments, and Open Qty — but that's not enough to isolate true back orders. On a multi-line order, one line can be stuck due to zero available stock while a sibling line has stock but simply hasn't been shipped yet. Comparing Open Qty alone conflates these two cases. In addition, the current report doesn't show pricing in a way that tells the reader whether the displayed amount already includes 16% VAT or excludes it — this creates ambiguity for Finance and Sales when reconciling against invoices.

## 3. Goals
1. Flag each SO line as **Back Ordered** vs **Open — Awaiting Shipment** vs **Fully Shipped**, using the correct underlying allocation data (not just Open Qty).
2. Show unit price and extended price per line, with an explicit, unambiguous VAT treatment (Inclusive @16% / Exclusive @16%) per line and per order total.
3. Make the report consumable both as an in-app Generic Inquiry/report and, if needed, as an API-exposed endpoint for external dashboards.

### Non-Goals
- Automating back-order replanning or shipment creation (reporting only).
- Multi-currency / multi-tax-jurisdiction support beyond the standard 16% VAT rate (flagged as an open question below — see §8).

## 4. Definitions
| Term | Definition |
|---|---|
| **Back Ordered line** | An SO line whose remaining open quantity has been classified to the **SO Back Ordered** allocation type — technically an `InItemPlan` record with `PlanType = 68`, referenced via `SOLineSplit`. |
| **Open — Awaiting Shipment** | Line has `OpenQty > 0` but is *not* flagged with the SO Back Ordered allocation type (i.e., stock exists, it just hasn't shipped). |
| **Fully Shipped** | `OpenQty = 0` for the line. |
| **VAT Inclusive price** | Unit/extended price already contains the 16% VAT component. |
| **VAT Exclusive price** | Unit/extended price excludes VAT; VAT is calculated and shown as a separate amount. |

## 5. Data Sources & Field Mapping

### 5.1 Back order status
| Field / Object | Source | Purpose |
|---|---|---|
| `SOOrder.Status` | Sales Order header | Order-level flag (`Back Order` status) |
| `SOLine.OrderQty`, `SOLine.OpenQty` | SO line | Baseline ordered vs. remaining quantity |
| `SOLineSplit` → `INItemPlan` (`PlanType = 68`) | Inventory Allocation Details (IN402000) | Authoritative source for **true** back-order classification at line level |

**Join path:** `SOLine → SOLineSplit → INItemPlan (filter PlanType = 68)`

This join is what distinguishes a genuinely stock-short line from a line that simply hasn't shipped yet.

### 5.2 Pricing
| Field | Source | Notes |
|---|---|---|
| `SOLine.CuryUnitPrice` | SO line | Unit price as entered on the order |
| `SOLine.CuryExtPrice` | SO line | Extended price (Qty × Unit Price) before line-level discounts/tax |
| `SOLine.CuryTranAmt` / `CuryLineAmt` | SO line | Net line amount after discounts |
| `SOOrder.TaxCalcMode` (or equivalent tax zone setting) | SO header / Tax Zone config | Determines whether the order is configured **Tax Inclusive** or **Tax Exclusive** |
| `SOTaxTran` / `CuryTaxAmt` | Tax detail | Actual VAT amount calculated per line or per order |

**Design decision needed:** Acumatica calculates tax at the **order/tax-zone level** (via the Tax Calculation Mode setting — Inclusive or Exclusive), not as a manual per-line toggle. The report should:
- Pull the order's `TaxCalcMode` to determine Inclusive/Exclusive.
- Display a clear label column: `Price Basis = "VAT Inclusive (16%)"` or `"VAT Exclusive (16%)"`.
- Show three price columns per line: **Net (Excl. VAT)**, **VAT Amount (16%)**, **Gross (Incl. VAT)** — this avoids ambiguity regardless of which mode the order was entered in, since both figures are always derivable from the tax detail.

## 6. Functional Requirements

| # | Requirement |
|---|---|
| FR1 | Report/GI returns one row per SO line, joined to allocation and tax data. |
| FR2 | Each line shows a `Fulfillment Status` column: `Back Ordered` / `Open — Awaiting Shipment` / `Fully Shipped`. |
| FR3 | Each line shows `Inventory ID`, `Description`, `Warehouse`, `Ordered Qty`, `Shipped Qty`, `Open Qty`, `Back Order Qty`. |
| FR4 | Each line shows `Unit Price (Net)`, `Ext. Price (Net)`, `VAT Amount (16%)`, `Ext. Price (Gross)`, and a `Price Basis` label. |
| FR5 | Report filterable by: Order Nbr, Customer, Inventory ID, Warehouse, Fulfillment Status, Date range. |
| FR6 | Order-level summary row/total: total Net, total VAT, total Gross per SO. |
| FR7 | (If API-exposed) A published Generic Inquiry endpoint returning the above fields via REST, for consumption by external dashboards/BI tools. |

## 7. Technical Approach
1. **Build the Generic Inquiry (GI)** in Acumatica joining `SOLine → SOLineSplit → INItemPlan` (filtered `PlanType = 68`) to derive true back-order status, plus `SOTaxTran`/tax zone data for VAT breakdown.
2. **Validate against known edge cases**: multi-line orders with mixed fulfillment status; orders where header status is "Back Order" but a given line is fully shipped (this happens — header status can lag line-level reality).
3. **Expose the GI as a REST endpoint** (Acumatica supports publishing GIs to the contract-based API) if external/BI consumption is required — this avoids reconstructing the InItemPlan join logic client-side.
4. **Add the Price Basis + VAT breakdown columns**, sourced from the order's tax calculation mode plus `SOTaxTran` detail, so Net/VAT/Gross are always shown regardless of how the order was originally entered.
5. **Review with Finance** to confirm 16% is the only VAT rate in scope, and confirm rounding rules (line-level vs order-level VAT rounding).

## 8. Open Questions
- Is 16% VAT the only tax rate in scope, or should the report support multiple tax zones/rates dynamically?
- Should "Back Order Qty" be shown even for lines where the order header hasn't yet flipped to "Back Order" status (i.e., surfacing line-level issues before they affect the whole order)?
- Does Finance need VAT shown per line, or is an order-level VAT summary sufficient?
- In-app GI/report only, or does this need to be exposed via API for an external BI tool (e.g., Power BI, Excel, custom dashboard)?
- Should cancelled/void lines be excluded, or shown with a distinct status?

## 9. Guardrails

These protect data integrity, prevent misreporting, and keep the report from being misused as a source of truth it wasn't designed to be.

### 9.1 Data integrity guardrails
| Guardrail | Why it matters |
|---|---|
| Only count a line as **Back Ordered** if the `InItemPlan.PlanType = 68` join resolves — never infer it from `OpenQty > 0` alone. | Prevents conflating "not yet shipped" with "genuinely out of stock," which was the original problem this report solves. |
| Exclude **Cancelled**, **Void**, and **On Hold** orders from active back-order and lost-value totals; show them in a separate filtered view instead. | Prevents inflating "lost business" figures with orders that were never going to ship anyway. |
| Reconcile report-level Net/VAT/Gross totals against `SOOrder` header totals on every refresh; flag (don't silently drop) any order where totals don't tie out. | Catches join errors (e.g., duplicate `SOLineSplit` rows) before they reach Finance or Sales leadership. |
| Lock the VAT rate as a configurable parameter (default 16%), not a hardcoded constant in the query. | Rate changes happen; hardcoding forces a report rebuild instead of a config change. |
| Time-stamp every report run and snapshot back-order data at time of run. | Back-order status changes daily; without a snapshot, "lost to business" figures aren't reproducible or auditable. |

### 9.2 Access & usage guardrails
| Guardrail | Why it matters |
|---|---|
| Restrict the "lost to business" value view to Sales Ops / Finance / Leadership roles; line-level fulfillment detail can stay open to Warehouse/CS. | Revenue-at-risk figures are commercially sensitive and easily misread out of context. |
| Add a visible disclaimer on the report: *"Lost value is an estimate based on current back-order status and is not a guaranteed revenue loss — orders may still ship."* | Prevents the figure being quoted externally or in board decks as a confirmed loss. |
| Cap how the figure can be exported (e.g., no raw CSV export of customer-level lost-value data without an approval step), if customer-sensitive. | Limits accidental sharing of customer-specific shortfall data outside the company. |
| Version and document any change to the "lost value" formula (see 10.2) so historical figures remain comparable. | Silent formula changes make trend reporting meaningless. |

## 10. "Lost to Business" View

This is the piece that turns the back-order data into a business-facing number: **the revenue currently at risk because ordered stock can't be fulfilled.**

### 10.1 Definition
**Lost-to-Business Value** = the Gross (VAT-inclusive) extended value of all lines currently flagged **Back Ordered** (per the InItemPlan/PlanType=68 join), summed per order, customer, item, or company-wide, as of the report run timestamp.

This is a **value-at-risk** metric, not a confirmed loss — it represents demand that exists today but can't currently be fulfilled. It should never be labeled "lost revenue" outright; use "value at risk" or "at-risk revenue" in any customer- or board-facing material, and reserve "lost to business" for internal ops framing only.

### 10.2 Calculation
| Field | Formula |
|---|---|
| Back Order Qty (line) | `SOLine.OpenQty` where allocation type = SO Back Ordered (`InItemPlan.PlanType = 68`) |
| Net Value at Risk (line) | `Back Order Qty × Unit Price (Net)` |
| VAT at Risk (line) | `Net Value at Risk × 16%` |
| Gross Value at Risk (line) | `Net Value at Risk + VAT at Risk` |
| Value at Risk (order/customer/item/company) | Sum of line-level Gross Value at Risk, grouped accordingly |

### 10.3 Suggested display
- **Headline card:** Total Gross Value at Risk (company-wide, as of run date) — the single number leadership scans first.
- **Breakdown table:** Value at Risk by Inventory Item (which SKUs are costing the most in unfulfilled demand) — this directly supports purchasing/replenishment prioritization.
- **Breakdown table:** Value at Risk by Customer (which accounts are most exposed) — supports account management and proactive customer communication.
- **Trend line:** Value at Risk over time (daily/weekly snapshot) — shows whether the back-order problem is improving or worsening, not just a point-in-time number.
- **Aging bucket:** Value at Risk split by how long the line has been back-ordered (0–7 / 8–14 / 15–30 / 30+ days) — surfaces chronic vs. transient shortages.
- Always pair the headline number with the disclaimer from §9.2 and the report run timestamp, so it's never read as a static, confirmed figure.

### 10.4 Acceptance criteria for this section
- [ ] Value-at-risk figures only ever derive from the InItemPlan/PlanType=68 join, never from raw Open Qty.
- [ ] Cancelled/Void/On Hold orders are excluded from all value-at-risk totals.
- [ ] Every value-at-risk view carries the run timestamp and the "estimate, not guaranteed loss" disclaimer.
- [ ] Access to customer-level value-at-risk detail is role-restricted per §9.2.
- [ ] Formula changes are versioned and documented.

## 11. Brand Classification, Back Order Reason & Live Inventory

### 11.1 Brand Classification — Manufactured vs. Partner Brand
**Assumption (please confirm):** the system already has *some* existing field used to classify items this way — most likely one of: `Item Class`, a custom **Attribute** on the Stock Item, `Product Manager`/`Product Workgroup`, or the primary **Vendor** on the item. The report should **reuse whatever field already drives this distinction today** rather than introduce a new one — a second, competing "brand" field is a common source of data drift.

| Requirement | Detail |
|---|---|
| FR8 | Report/GI includes a `Brand Type` column (`Manufactured` / `Partner Brand`), sourced from the existing classification field — **not** a new custom field, unless confirmation shows no such field currently exists. |
| FR9 | Report filterable and groupable by `Brand Type`, in addition to the filters in FR5. |
| FR10 | §10 (Lost-to-Business) breakdowns are also cut by `Brand Type`, so leadership can see whether value-at-risk skews toward in-house manufactured items or partner-sourced items — these usually have very different remediation paths (production scheduling vs. vendor follow-up). |

**Open question:** What is the exact field/table currently used to flag Manufactured vs. Partner Brand — Item Class, an Attribute, Product Workgroup, or Vendor? This needs to be confirmed before the join is built, since it determines whether this is a one-column addition or a new mapping table.

### 11.2 Reason for Back Order
Acumatica doesn't natively capture *why* a line is back ordered — only *that* it is (via the `InItemPlan.PlanType = 68` allocation). To make the report actionable rather than just descriptive, a reason needs to be captured somewhere.

| Requirement | Detail |
|---|---|
| FR11 | Add a `Back Order Reason` field, using a fixed set of reason codes (recommend starting with: `Vendor/Supplier Delay`, `Production Capacity`, `Demand Spike`, `Component Shortage`, `Quality Hold`, `Other`). |
| FR12 | Reason should be captured **as close to the source as possible** — e.g., set by Purchasing when a PO slips, or by Production when a work order is delayed — not backfilled by whoever runs the report. |
| FR13 | If no existing field/process captures this today, this requires a small customization: either a custom attribute on the `SOLine`/`INItemPlan` record, or a lightweight linked table keyed by Order Nbr + Line Nbr, populated via a screen extension or a manual entry point for Purchasing/Planning. |
| FR14 | Reason should be **optional but flagged** — lines back-ordered more than N days (see aging bucket, §10.3) without a reason assigned should surface as a data-quality exception, not be silently excluded. |

**Open question:** Who owns setting this reason — Purchasing, Planning, Customer Service — and at what point in the process (when the PO is placed, when it's confirmed late, when the shipment is attempted)? This determines where the capture point needs to live.

### 11.3 Current Inventory Visibility
Back-order and lost-value figures are far more actionable next to real-time stock position — e.g., "is more stock inbound, or is this item simply not being replenished?"

| Requirement | Detail |
|---|---|
| FR15 | Pull live inventory data per item/warehouse: `Qty On Hand`, `Qty Available`, `Qty Allocated`, `Qty on Purchase Order` (inbound), and `Reorder Point`/`Safety Stock` if configured — standard fields off `INSiteStatus`/warehouse-level inventory summary. |
| FR16 | Display alongside each back-ordered line so a reader can see, without leaving the report, whether stock is inbound and roughly when (`Qty on PO` + expected receipt date, if available). |
| FR17 | Add a simple flag: `Replenishment in Progress` (Yes/No) based on whether `Qty on PO > 0` for that item — quick visual triage for Purchasing. |
| FR18 | Inventory figures should be pulled live at report run time (not cached), since stock position changes daily and is the main variable this report exists to surface. |

### 11.4 Updated Guardrails (extends §9)
| Guardrail | Why it matters |
|---|---|
| `Brand Type` must come from the single existing source-of-truth field — confirm this before build to avoid a second, conflicting brand taxonomy. | Prevents Manufactured/Partner Brand splits disagreeing with other reports already in use. |
| `Back Order Reason` is a **controlled list**, not free text. | Free text makes the field unreportable within a quarter; a fixed code list keeps it aggregable. |
| Lines missing a `Back Order Reason` past the aging threshold are surfaced as an exception, not hidden. | Keeps the reason field from silently going stale/unused. |
| Inventory figures are always live, timestamped, and never cached across report runs. | A stale "Qty Available" next to a live value-at-risk figure is misleading and can drive bad purchasing decisions. |

## 12. Acceptance Criteria
- [ ] Report correctly separates "Back Ordered" from "Open — Awaiting Shipment" using the InItemPlan/PlanType=68 join, verified against at least 3 known multi-line test orders with mixed statuses.
- [ ] Every line shows Net, VAT (16%), and Gross price, with a clear Price Basis label.
- [ ] Order-level totals reconcile to the SO header totals in Acumatica.
- [ ] Report is filterable by the fields in FR5.
- [ ] (If in scope) GI is published as a working REST endpoint and returns the same data as the in-app view.
- [ ] `Brand Type` is sourced from the confirmed existing field, not a newly invented one.
- [ ] `Back Order Reason` uses the agreed controlled list, and lines missing a reason past the aging threshold are surfaced, not dropped.
- [ ] Live inventory figures (`Qty On Hand`, `Available`, `Allocated`, `on PO`) refresh at report run time and are never cached.

## 13. Timeline & Milestones (fill in with your team)
| Milestone | Owner | Target Date |
|---|---|---|
| GI build & join validation | | |
| Pricing/VAT columns added | | |
| UAT with Sales Ops & Finance | | |
| API publish (if in scope) | | |
| Go-live | | |