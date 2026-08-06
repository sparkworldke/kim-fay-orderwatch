# PRD: Backorder Report — Simplification & Refactor

**Status:** Revised 2026-07-22. This report is **not greenfield** — it's a live production feature (`/app/backorders`, `operations/backorders*` API group) that already syncs from Acumatica, classifies backorder lines, tracks reasons, and shows live-ish inventory. This revision replaces an earlier draft that described capabilities (Acumatica `InItemPlan`/`PlanType=68` joins, VAT-inclusive/exclusive pricing) that were never built, and folds in the capabilities that already exist but weren't previously documented. The focus now is **simplifying an already-complex payload and API surface**, not building the report from scratch.

**Owner:** [fill in]
**Requested by:** [fill in]
**Target consumers:** Sales Ops, Warehouse/Fulfillment, Customer Service, Sales Consultants, Finance (value-at-risk reporting)

---

## 1. Overview

The backorder report tells business users which sales-order lines are currently short (open/back-ordered qty), why, and how much revenue is at risk. It exists today as:

- **Backend:** `AcumaticaBackorderSyncService` (sync from Acumatica) → `acumatica_backorder_lines` table → `OperationsController` (8 endpoints) → REST API.
- **Frontend:** `src/routes/app.backorders.tsx` — a single accordion-grouped list page, plus embedded cards on customer/order detail pages (`customer-orders-shared.tsx`).

There is **no Acumatica Generic Inquiry involved and no `InItemPlan`/`SOLineSplit`/`PlanType=68` join anywhere in the codebase** (confirmed via repo-wide search — zero matches). Classification, quantities, and pricing all come from the Sales Order line's own fields, processed in Laravel.

## 2. Current State — What's Actually Implemented

This section is the ground truth the rest of the PRD builds on. Treat any requirement below that isn't listed here as **not yet built**.

### 2.1 Data model

`acumatica_backorder_lines` (`backend/app/Models/AcumaticaBackorderLine.php`), one row per `(order_nbr, inventory_id)`:

| Group | Fields |
|---|---|
| Identity | `order_nbr`, `inventory_id`, `customer_acumatica_id`, `customer_name` |
| Quantities | `order_qty`, `shipped_qty`, `qty_on_shipments`, `invoiced_qty`, `open_qty`, `cancelled_qty`, `backorder_qty`, `qty_at_approval` |
| Status | `fulfillment_status`, `order_status`, `shortfall_kind` (`active_backorder` \| `completed_shortfall`), `invoice_reconciliation_status`, `fulfillment_source` |
| Price/value | `unit_price`, `revenue_at_risk` |
| Reason | `reason_code`, `reason_notes`, `reason_updated_by`, `reason_updated_at` |
| Other | `warehouse_id`, `uom`, `currency_id`, `scheduled_shipment_date`, `requested_on`, `sync_run_id`, `synced_at` |

That's **29 stored columns**, none of which are VAT/tax-related and none of which reference an Acumatica allocation/plan entity.

### 2.2 Back-order classification (real logic)

`SalesOrderLineFulfillmentDeriver::isBackorderLine()` — a line counts as backordered if it has open/backorder qty **and** its derived `fulfillment_status` is `Backorders Imported`, `Partially Shipped — Backorder Pending`, or `Pending Shipment`, and is **not** `Fully Fulfilled`/`Cancelled`. This is a string-status + qty heuristic, not an allocation-table join. It already correctly separates "genuinely short" from "fully shipped," which was the original PRD's stated goal — it just does it more cheaply than the originally-proposed `InItemPlan` join.

### 2.3 Pricing (real logic)

```
revenue_at_risk = open_qty (or backorder_qty fallback) × unit_price   // openLineValue(), rounded 2dp
```

`unit_price` resolves `CuryUnitPrice` → `UnitPrice` → `DiscountedUnitPrice` → `ext/orderQty`. **There is no VAT rate, no tax-inclusive/exclusive split, no Net/VAT/Gross columns anywhere** — not in the model, sync service, controller, or frontend type (`BackorderLine` in `src/hooks/useOperations.ts`). Whatever tax status Acumatica's price field carries is passed through unlabeled.

### 2.4 Classification / "Brand" (real logic — two separate axes)

1. **Item-master fields**, joined live from `acumatica_inventory_items` per row: `brand`, `posting_class`, `sub_trading_group`, `supplier`, plus `item_class` (exposed as `product_line`). Resolved via `OperationsCatalogResolver::classificationFieldsFor()`.
2. **Manufactured vs. Trading** — a *different* classification (`FillRateBusinessCategory::classify()`), used for the `product_segment` filter and the value-summary breakdown. Not the same field as #1.

So "Brand Type" from the original PRD isn't one field — it's up to five overlapping fields (`brand`, `posting_class`, `sub_trading_group`, `supplier`, `product_segment`) that already ship in every row.

### 2.5 Back Order Reason (real logic — already implemented)

`AcumaticaBackorderLine::REASON_CODES` holds **31 canonical codes** (e.g. `out_of_stock_procurement`, `delay_in_delivery`, `promo_product`, `truck_full`, `wrong_moq`, `npd`, `did_not_pick_on_shipment`, …), resolved through `SalesOrderReasonCatalog`. If nothing is manually set, it falls back to Acumatica's own `unfilled_reason_code` from the SO line. Manual override: `PATCH operations/backorders/{id}` (`updateBackorderReason`), role-gated to Administrator / Customer Service Manager / Sales Operations, validated against an approved sub-reason list, notes capped at 2000 chars, stamped with `reason_updated_by`/`reason_updated_at`.

Not implemented: an aging-based exception flag for lines missing a reason past N days (the original PRD's FR14).

### 2.6 Live inventory (real logic — partially implemented)

`backorders()` already returns `qty_on_hand`, `qty_available`, and a computed `stock_shortfall` boolean (`qty_on_hand < open_qty`), sourced from the `acumatica_inventory_items` snapshot table — **synced periodically, not called live against Acumatica per report run.**

Not implemented: `qty_allocated`, `qty_on_po` (inbound), `reorder_point`/`safety_stock`, or a `replenishment_in_progress` flag.

### 2.7 API surface (8 endpoints, all under `operations/backorders*`)

| Endpoint | Purpose |
|---|---|
| `GET backorders` | Paginated line list (the main report) |
| `GET backorders/summary` | KPI totals: open lines/orders, revenue at risk, fill-rate-style completed shortfall stats |
| `GET backorders/analytics` | Trend/lead-time/category/reason/customer/department distributions + filter option lists |
| `GET backorders/sku-breakdown` (+ `/export`) | Rollup by Inventory ID |
| `GET backorders/export` | Excel export of the filtered line list |
| `GET backorders/by-account` | Top-N customer rollup |
| `GET backorders/reconciliation` | (separate reconciliation view) |
| `PATCH backorders/{id}` | Manual reason-code edit |

### 2.8 Filters actually supported (`backordersFilteredQuery()`)

`q` (free text over order/item/customer), `date_from`/`date_to`, `customer_id` (+`include_branches`), `customer_group`, `product_line`, `warehouse_id`, `reason_code` (incl. an `unassigned` branch), `segment` (KP/CS), `product_segment` (manufactured/trading), `shortfall_kind`, `brand`/`partner_brand`/`category`, plus server-side scope filters (sales-consultant scope, department-portfolio scope, brand-assignment scope).

**Gap vs. UI:** there is no dedicated `order_nbr` filter (folded into `q`) and no dedicated `fulfillment_status` filter (the frontend shows the status as a badge but never lets a user filter by it).

### 2.9 Frontend (real logic)

`app.backorders.tsx` groups the flat API rows client-side by `inventory_id` into an accordion. **The UI only renders 6 pieces of information per line**: SO + order date, status badge, customer, open qty (+ UOM, unit price), revenue at risk, reason badges, and an edit action. Group headers show brand/sub-trading-group/posting-class/supplier via `ProductListingCell`.

That means roughly **20 of the 29+ fields returned by `GET backorders`** (e.g. `qty_on_shipments`, `qty_at_approval`, `cancelled_qty`, `invoice_reconciliation_status`, `fulfillment_source`, `currency_id`, `lead_time_days`, `posting_class` vs `brand` vs `sub_trading_group` vs `supplier` as four separate strings) are shipped over the wire but never displayed. This is the core simplification opportunity — see §4.

---

## 3. Problem Statement

The original ask ("isolate true back orders, show unambiguous VAT pricing") is **already solved for the back-order-classification half** — the live `fulfillment_status` + qty heuristic works and ships to production. The VAT half was never built and nothing in Finance's current workflow depends on it (grep across the backend found zero VAT/tax handling in this pipeline).

The *actual* problem today is different: **the payload and API surface have grown past what any single consumer uses.** One row carries 29+ stored columns plus ~10 computed/joined fields; the UI renders 6 of them. There are 8 report endpoints with overlapping purposes (`summary` vs `analytics` vs `reconciliation` vs `by-account` all return revenue-at-risk-shaped numbers, sliced differently). There are two competing item-classification axes (`brand`/`posting_class`/`sub_trading_group`/`supplier` vs `product_segment` manufactured/trading) with no documented relationship between them. 31 reason codes exist with no visibility into which are actually used.

This makes the report hard to reason about for anyone new to the codebase, inflates every API response, and makes "add one more column" the default instinct instead of "which existing field already covers this."

## 4. Goals

1. **Simplify the payload**: trim `GET backorders` (and the export/sku-breakdown variants) down to fields the frontend actually renders, plus a small, explicitly-justified set for future use. Anything dropped from the default payload should still be derivable/available on request (e.g. a detail endpoint), not deleted from the DB.
2. **Consolidate classification**: pick one canonical "grouping" field for the report's primary view and demote the other three item-master strings (`posting_class`, `sub_trading_group`, `supplier`) to optional/detail fields; clearly document how `product_segment` (Manufactured/Trading) relates to `brand`.
3. **Rationalize reason codes**: audit real usage of the 31 codes against `backorders/analytics`'s `reason_distribution`; retire unused codes rather than let the list grow unchecked.
4. **Close the two real UX gaps**: add a `fulfillment_status` filter and decide whether a dedicated `order_nbr` filter is worth adding alongside the existing free-text `q`.
5. **Document, don't rebuild, back-order classification and value-at-risk** — the existing `isBackorderLine()` heuristic and `revenue_at_risk` formula are correct and in production; this PRD's job is to make them legible, not replace them with the previously-proposed Acumatica join.

### Non-Goals

- **VAT-inclusive/exclusive pricing.** Not implemented, not requested by Finance in current workflows, and would require pulling Acumatica's tax-zone/`TaxCalcMode` data that nothing else in this pipeline touches. If Finance needs this, it should be scoped as its own follow-up PRD, not bundled into a simplification pass.
- **Acumatica `InItemPlan`/`PlanType=68`/`SOLineSplit` join.** The existing status+qty heuristic already achieves the stated goal (separating genuinely-short lines from merely-unshipped ones) without an additional Acumatica entity dependency. Don't add this join unless a specific case is found where the heuristic misclassifies a line.
- **True real-time inventory calls.** `qty_on_hand`/`qty_available` come from a periodic snapshot sync today and that's an accepted tradeoff, not a regression to fix as part of this pass.
- Automating back-order replanning or shipment creation (reporting only).

---

## 5. Simplification & Refactor Recommendations

These are the concrete changes, in priority order:

1. **Trim the default row payload.** Keep: `order_nbr`, `order_date`, `inventory_id`, `product_name`, one classification field (see #2), `uom`, `customer_acumatica_id`/`customer_name`, `open_qty`, `backorder_qty`, `unit_price`, `revenue_at_risk`, `order_status`/`fulfillment_status`, `reason_code`, `qty_on_hand`/`stock_shortfall`. Move `qty_on_shipments`, `qty_at_approval`, `cancelled_qty`, `invoice_reconciliation_status`, `fulfillment_source`, `currency_id`, `lead_time_days`, `qty_available` behind a `?detail=1` param or a separate line-detail endpoint, only fetched when a row is expanded/edited.
2. **Pick one classification field for the report's primary grouping.** Recommend `brand` (already used in the group header via `ProductListingCell`) as the headline field; keep `posting_class`/`sub_trading_group`/`supplier` as filter-only/detail fields, not default payload columns. Document explicitly that `product_segment` (Manufactured/Trading) is a *different, coarser* classification used for value-summary splits, not a synonym for `brand`.
3. **Audit and prune `REASON_CODES`.** Pull `reason_distribution` from `backorders/analytics` over a representative window (e.g. trailing 90 days); any code with near-zero usage is a candidate to retire or merge into an adjacent code. Keep the list a controlled vocabulary (already true today), just smaller.
4. **Add a `fulfillment_status` filter** to `backordersFilteredQuery()` — it's already computed and displayed as a badge, so this is a small, low-risk addition that closes a real gap.
5. **Decide on `order_nbr` as a first-class filter** vs. continuing to fold it into `q`. If order lookup is a common support workflow, a dedicated param is cheap to add and clearer than relying on free-text search behavior.
6. **Rationalize the 8 endpoints.** Confirm which of `summary` / `analytics` / `reconciliation` / `by-account` are actually consumed by the frontend today vs. leftover from earlier iterations (this needs a frontend usage check before removing anything — don't drop an endpoint that an admin tool or export still calls).
7. **Leave the classification heuristic and value formula alone.** Both are correct and live; documenting them (done in §2.2–§2.3 above) is the fix, not rewriting them.

---

## 6. Functional Requirements

| # | Requirement | Status |
|---|---|---|
| FR1 | Report returns one row per SO line with classification, reason, and inventory data joined in. | ✅ Implemented (`OperationsController::backorders()`) |
| FR2 | Each line shows a fulfillment/status indicator. | ✅ Implemented (`fulfillment_status`/`order_status`) — heuristic-based, not allocation-join-based |
| FR3 | Each line shows Inventory ID, description, warehouse, ordered/shipped/open/backorder qty. | ✅ Implemented |
| FR4 | ~~Net/VAT/Gross price columns~~ | ❌ Not implemented — moved to Non-Goals (§4) |
| FR5 | Filterable by customer, product line, warehouse, reason, segment, brand, date range. | ✅ Implemented — **add** `fulfillment_status`; **decide on** dedicated `order_nbr` |
| FR6 | Order/customer/product/company-level revenue-at-risk summaries. | ✅ Implemented (`backordersSummary`, `backordersValueSummary`, `backordersByAccount`) |
| FR7 | REST-exposed for external/BI consumption. | ✅ Implemented — already a Laravel REST API, no Acumatica GI publish step needed |
| FR8 | Brand/classification column sourced from existing item-master field. | ✅ Implemented — but from **four** overlapping fields; see simplification #2 |
| FR9 | Filterable/groupable by classification. | ✅ Implemented (`product_line`, `brand`/`partner_brand`/`category`, `product_segment`) |
| FR10 | Value-at-risk breakdowns cut by classification. | ✅ Implemented (`by_product_segment`, `by_customer_segment` in `backordersValueSummary`) |
| FR11 | Controlled reason-code list. | ✅ Implemented — 31 codes, audit for pruning (§5.3) |
| FR12 | Reason captured close to source. | ⚠️ Partial — falls back to Acumatica's `unfilled_reason_code`; manual entry is after-the-fact via CS/Sales Ops, not at PO/production time |
| FR13 | Reason customization mechanism if none exists. | ✅ Implemented (`reason_code`/`reason_notes` columns + PATCH endpoint) |
| FR14 | Missing-reason aging exception surfaced, not hidden. | ❌ Not implemented — real gap if this is still wanted |
| FR15 | Live inventory: on-hand, available, allocated, on-PO, reorder point. | ⚠️ Partial — on-hand/available exist (snapshot-based); allocated, on-PO, reorder point/safety stock **not implemented** |
| FR16 | Show inbound stock + expected receipt date next to backordered lines. | ❌ Not implemented |
| FR17 | `Replenishment in Progress` flag. | ❌ Not implemented |
| FR18 | Inventory pulled live, never cached. | ❌ Not implemented — current source is a periodic snapshot sync (accepted per Non-Goals) |

---

## 7. Guardrails

Carried over from data-integrity/access principles that still apply; VAT-specific guardrails removed since there's no VAT feature.

| Guardrail | Why it matters |
|---|---|
| Only count a line as backordered via `SalesOrderLineFulfillmentDeriver::isBackorderLine()` — never infer it from `open_qty > 0` alone in ad-hoc queries. | This is the one piece of logic that must stay centralized; duplicating the heuristic elsewhere risks drift from the production definition. |
| Exclude Cancelled lines from active back-order and value-at-risk totals (already handled — `isBackorderLine()` excludes `STATUS_CANCELLED`); keep this exclusion explicit in any new endpoint. | Prevents inflating "at risk" figures with orders that were never going to ship. |
| `reason_code` stays a controlled vocabulary (`REASON_CODES`) — never let the PATCH endpoint accept free-form codes. | Already enforced server-side; don't regress this when pruning the list. |
| Time-stamp every sync (`synced_at`, `sync_run_id` already exist) and keep them in every exported view. | Back-order status changes daily; without a timestamp, "value at risk" figures aren't reproducible. |
| Restrict customer-level value-at-risk detail (`backordersByAccount`) to appropriate roles; keep line-level fulfillment detail open to Warehouse/CS as today. | Revenue-at-risk figures are commercially sensitive and easily misread out of context. |
| Keep the "value at risk is an estimate, not guaranteed loss" framing on any headline/company-wide number. | Prevents the figure being quoted externally as a confirmed loss. |

---

## 8. Value-at-Risk / "Lost to Business" View

### 8.1 Definition (matches what's live today)

**Revenue at Risk** = `open_qty (or backorder_qty fallback) × unit_price`, summed per order/customer/product/company as of the sync timestamp. This is what `backordersSummary`, `backordersValueSummary`, and `backordersByAccount` already return — **no VAT/gross adjustment**, since `unit_price` is passed through from Acumatica unlabeled for tax status.

This is a value-at-risk estimate, not a confirmed loss. Use "value at risk" / "at-risk revenue" in customer- or board-facing material.

### 8.2 Existing breakdowns (already implemented — don't rebuild)

- By product segment: Manufactured vs. Trading (`by_product_segment` in `backordersValueSummary`).
- By customer segment: KP vs. CS (`by_customer_segment`, via `FillRateCalculator::segmentForCustomerClass`).
- By account: top-N customers (`backordersByAccount`).
- By SKU: `backorders/sku-breakdown` (+ Excel export).
- Reason distribution, category distribution, lead-time correlation, trend: `backorders/analytics`.

### 8.3 Real gap

No aging bucket (0–7 / 8–14 / 15–30 / 30+ days) exists today. If leadership wants chronic-vs-transient visibility, this is new work, not a rename of an existing field — `requested_on`/`scheduled_shipment_date` would need to be diffed against `synced_at` or "today," and there's no stored history of *when* a line first became backordered (only the latest sync snapshot).

### 8.4 Acceptance criteria

- [ ] Value-at-risk figures continue to derive only from `isBackorderLine()` + `open_qty`/`backorder_qty` × `unit_price` — no ad-hoc reimplementation in a new endpoint.
- [ ] Cancelled lines remain excluded from all value-at-risk totals.
- [ ] Every value-at-risk view carries `synced_at`/`last_synced_at` and the "estimate, not guaranteed loss" framing.
- [ ] Customer-level detail (`backordersByAccount`) stays role-restricted.

---

## 9. Open Questions

1. Which of the 8 endpoints (`summary`, `analytics`, `reconciliation`, `by-account`, `sku-breakdown`, `sku-breakdown/export`, `export`, base list) does the frontend/any external consumer actually call today? (Needed before removing/merging any of them — see §5.6.)
2. Is `brand` the right single field to promote as the primary classification, or does Sales Ops actually key off `sub_trading_group` or `supplier` day-to-day?
3. Of the 31 reason codes, which have near-zero usage in `reason_distribution` over the last 90 days and are safe to retire?
4. Is a `fulfillment_status` filter and/or dedicated `order_nbr` filter worth adding, or does the current free-text `q` cover real usage well enough?
5. Is the missing-reason aging exception (FR14) still wanted, or has manual CS/Sales-Ops reason assignment made it unnecessary?
6. Does anyone actually need VAT-inclusive/exclusive pricing on this report, or was that carried over from a different (Finance-facing) request that doesn't apply here?

## 10. Acceptance Criteria

- [ ] Default `GET backorders` payload trimmed to the fields listed in §5.1; detail fields moved behind a param or separate endpoint.
- [ ] One classification field documented as canonical for grouping; the relationship between it and `product_segment` is documented in API docs, not left implicit.
- [ ] Reason-code list reviewed against real usage; unused codes retired or merged.
- [ ] `fulfillment_status` filter added (pending answer to Open Question 4).
- [ ] Endpoint usage audited; any confirmed-dead endpoint flagged for removal in a follow-up change (not deleted in the same pass without confirmation).
- [ ] No regression to the existing `isBackorderLine()` classification or `revenue_at_risk` formula — verified against current production output for a sample of orders before/after the payload trim.

## 11. Timeline & Milestones (fill in with your team)

| Milestone | Owner | Target Date |
|---|---|---|
| Endpoint usage audit (Open Question 1) | | |
| Reason-code usage audit (Open Question 3) | | |
| Payload trim + classification consolidation | | |
| `fulfillment_status` filter added | | |
| Docs update (canonical classification field, endpoint map) | | |
| Review with Sales Ops / CS / Finance | | |

---

## 12. Phase 1 Implementation Update (2026-07-22)

Phase 1 preserves the full `GET operations/backorders` payload. `brand` is the canonical display classification; `posting_class`, `sub_trading_group`, and `supplier` are secondary metadata, while `product_segment` remains the separate Manufactured/Trading axis.

The report now supports a validated `fulfillment_status` filter across the shared backorder query and exposes filter options through analytics. Free-text `q` remains the supported SO-number lookup.

Backorder rows now track `first_backordered_at`. Existing active rows are initialized from their latest available sync timestamp and marked with `first_backordered_at_is_backfilled=true`, so their displayed age may understate the real age before the migration cutover. New sync observations preserve the timestamp for a continuing row; resolved rows are pruned by the existing snapshot lifecycle, so a future reappearance starts a new aging episode.

Line responses add `backorder_age_days`, `aging_bucket` (`0-7`, `8-14`, `15-30`, `30+`), and `missing_reason_exception`. The exception uses the effective stored-or-Acumatica reason and the configurable `BACKORDER_MISSING_REASON_EXCEPTION_DAYS` threshold (default 7 elapsed days).

Reason vocabulary and endpoint consumption are audit-only in this phase. See `docs/backorder-phase1-audits.md`; no reason code or endpoint is automatically removed. Purchase-order/inbound inventory fields remain gated Phase 2 work.
