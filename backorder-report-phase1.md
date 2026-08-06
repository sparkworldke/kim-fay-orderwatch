# Backorder Report Refactor — Phase 1

## Summary

Refactor the existing backorder module without changing its classification or revenue-at-risk formulas. Preserve the full list payload for compatibility, centralize response construction so list/export/breakdown endpoints stop drifting from each other, make `brand` the canonical display classification, add the `fulfillment_status` filter, and implement backorder aging + missing-reason exception flagging. Audit (but do not yet act on) reason-code usage and endpoint consumption.

**Explicitly excluded from this phase** (deferred to Phase 2, gated on confirming Acumatica exposes a usable Purchase Order entity — nothing in the current integration syncs PO data today): `qty_allocated`, `qty_on_po`, `reorder_point`, `safety_stock`, `next_expected_receipt_date`, `replenishment_in_progress`, and any new PO-snapshot table/sync job.

**Also explicitly excluded, permanently:** VAT calculations, `InItemPlan`/`SOLineSplit`/`PlanType=68` joins, and per-request live Acumatica calls. None of this is implemented today and none of it is in scope for this refactor.

## Implementation Changes

### 1. Centralize response construction
- Extract the per-row transform currently built inline in `OperationsController::backorders()` (product name resolution, `brand`/`posting_class`/`sub_trading_group`/`supplier` classification fields, `customer_name`, `uom`, `order_date`, `order_status`, effective `reason_code`, `qty_on_hand`/`qty_available`/`stock_shortfall`) into a single shared resource/transformer.
- Reuse it from `backorders()`, `exportBackorders()`, and `backordersSkuBreakdown`/`backordersByAccount` wherever they build line-shaped rows, so these stop drifting into slightly different field sets over time.
- Preserve every field currently returned by `GET operations/backorders` — this is a compatibility/hygiene refactor, not a payload trim.

### 2. `brand` as canonical classification
- No schema change — the fields already exist via the `acumatica_inventory_items` join.
- Document `brand` as the canonical grouping/display field (already what the frontend's `ProductListingCell` leads with). Document `posting_class`, `sub_trading_group`, and `supplier` as secondary/filter-only metadata, and document `product_segment` (Manufactured/Trading, via `FillRateBusinessCategory`) as a separate, coarser axis — not a synonym for `brand`.
- No behavior change required beyond updating API/README documentation and confirming the frontend doesn't need to change (it already uses `brand` first in the group header).

### 3. `fulfillment_status` filter
- Add a validated `fulfillment_status` parameter to `backordersFilteredQuery()`, accepting the values already defined in `SalesOrderLineFulfillmentDeriver` (`Fully Fulfilled`, `Backorders Imported`, `Cancelled`, `Partially Shipped — Backorder Pending`, `Pending Shipment`).
- Apply it everywhere `backordersFilteredQuery()` is the base query: list, summary, analytics, SKU breakdown (+ export), by-account, export.
- Add `fulfillment_statuses` to the `filters` object returned by `backordersAnalytics()`, alongside the existing `product_lines`/`customer_groups`/`departments`/`warehouse_ids`/`reason_codes`.
- Frontend: add a `fulfillment_status` filter control to `app.backorders.tsx`, populated from `backordersAnalytics().filters.fulfillment_statuses`, wired into the existing `filterParams` object alongside the other filters. Continue using free-text `q` for order-number lookup — do not add a separate `order_nbr` param.

### 4. Backorder aging + missing-reason exception
- New nullable `first_backordered_at` (timestamp) column on `acumatica_backorder_lines`.
  - Migration backfill: for rows currently classified as backordered (`isBackorderLine()` true / `shortfall_kind = 'active_backorder'`), set `first_backordered_at` to the row's current `synced_at` as the best available lower bound. For everything else, leave null.
  - **This backfill is a known limitation, not a bug**: lines already backordered before this migration will show an artificially recent `first_backordered_at`, understating true age until enough sync history accumulates. Surface this explicitly — see "Public Interfaces" below.
- Sync service (`AcumaticaBackorderSyncService`) behavior on each upsert:
  - Row was backordered last sync and still is → keep existing `first_backordered_at` unchanged.
  - Row was not backordered (or didn't exist) and now is → set `first_backordered_at` to the current sync timestamp.
  - Row is no longer backordered (fully fulfilled, cancelled, or moved to `completed_shortfall`) → clear `first_backordered_at` back to null, so a future re-backorder on the same order/item is treated as a new instance rather than continuing the old age count.
- Computed (not stored) per response: `backorder_age_days` = days between now and `first_backordered_at`; `aging_bucket` ∈ `0-7` / `8-14` / `15-30` / `30+` (null bucket when `first_backordered_at` is null).
- `missing_reason_exception`: boolean — true when `backorder_age_days` exceeds a configurable threshold **and** there is no effective reason (`reason_code` is null and the fallback Acumatica `unfilled_reason_code` is also empty). Same "effective reason" resolution already used for display should suppress the exception, not just a manually-assigned `reason_code`.
- Threshold must be a config value (e.g. `config('backorders.missing_reason_exception_days')`, default 7), **not hardcoded** — this number hasn't been confirmed with stakeholders and should be changeable without a redeploy if the answer turns out to be different.

### 5. Reason-code usage audit (audit only — no automatic retirement)
- Add an artisan command that reports, per code in `AcumaticaBackorderLine::REASON_CODES`: count of usage in the trailing 90 days, count of all-time stored references, and a recommendation (`retire` if both are zero, `keep` otherwise).
- Do not auto-remove codes from the selectable list based on this report — retirement is a human decision on the audit output, not an automated side effect of this refactor.
- If/when codes are retired: keep them valid for **display and filtering** on historical rows (don't break old data), only remove them from the set the `PATCH` endpoint accepts for **new** assignments. Never merge differently-named codes into one without an explicit, reviewed alias mapping.

### 6. Endpoint consumption audit (audit only — no removals)
- Document, for each of the 8 `operations/backorders*` endpoints, which known consumer(s) call it (grep `src/` for usage, check exports/cron/other controllers). Note explicitly that this can't rule out external/BI consumers outside this repo.
- Mark endpoints with no found repository consumer as **deprecation candidates** in the documentation — do not remove any route in this phase.
- While auditing, confirm account-level value endpoints (`backordersByAccount`) already enforce appropriate role restrictions; add the check if missing.

### 7. Documentation
- Update `backorder-report.md` (the main PRD) to reflect: full-payload compatibility policy, `brand` as canonical classification, the new aging/missing-reason feature and its config threshold and backfill caveat, the endpoint and reason-code audit results, and the confirmed Phase 2 scope (PO/inventory work, gated on confirming the Acumatica PO entity exists).

## Public Interfaces and Data

- `GET operations/backorders*` (list, summary, analytics, sku-breakdown, by-account, export) accept an optional `fulfillment_status` parameter.
- `backordersAnalytics().filters` gains `fulfillment_statuses`.
- Existing `BackorderLine` fields are unchanged. New additive fields per line: `first_backordered_at`, `backorder_age_days`, `aging_bucket`, `missing_reason_exception`.
- Add a response-level (or per-line) indicator that aging data is backfilled from sync history and may understate true age for lines already backordered before the migration ran — e.g. a `first_backordered_at_is_backfilled` boolean, or a note in the summary/analytics payload with the migration cutover date. Don't let this ship silently as if it were exact.
- New config value: `config/backorders.php` → `missing_reason_exception_days` (default 7, overridable via env without a full redeploy).
- Add DB indexes supporting the new aging/exception queries (`first_backordered_at`, and a composite covering the missing-reason-exception filter if it's exposed as a query param).

## Test Plan

- `first_backordered_at` behavior: active backorder continues → unchanged; new backorder → set to sync time; fully fulfilled/cancelled/completed-shortfall → cleared; a line that resolves and later re-backorders gets a fresh timestamp, not the old one.
- Aging bucket boundaries: 0–7, 8–14, 15–30, 30+ day edges land in the correct bucket.
- `missing_reason_exception` boundary at the configured threshold; verify the fallback Acumatica `unfilled_reason_code` suppresses the exception the same as a manually-assigned `reason_code`.
- `fulfillment_status` filter produces consistent results across list, summary, analytics, SKU breakdown, by-account, and export.
- Reason-code audit command: verify usage counts against known seeded/test data; verify retiring a code from the selectable list doesn't break existing rows' display or filterability.
- API contract/snapshot test proving every pre-refactor payload field is still present and unchanged after the resource/transformer extraction.
- Regression tests: `isBackorderLine()` classification, cancellation exclusion, and `open_qty × unit_price` revenue-at-risk totals are unchanged by this refactor.
- Backend feature/unit tests, frontend type-check and build.

## Assumptions

- Missing-reason SLA defaults to 7 days pending explicit confirmation; implemented as a config value specifically so this default can change without a code deploy.
- No endpoint or reason code is removed in this phase — both audits produce recommendations for a human-reviewed follow-up, not automatic changes.
- Phase 2 (`qty_allocated`, `qty_on_po`, `reorder_point`, `safety_stock`, `next_expected_receipt_date`, `replenishment_in_progress`, PO snapshot table) is intentionally out of scope here and should not be started until Acumatica's configured endpoint is confirmed to expose a usable Purchase Order entity.
- No existing endpoint field, classification logic, or revenue formula changes as a side effect of this refactor.
