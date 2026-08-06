# Backorder Report — Phase 2 (Revised: SalesOrder-Only Scope)

## Why this replaces the original Phase 2

The original Phase 2 (`qty_allocated`, `qty_on_po`, `reorder_point`, `safety_stock`, `next_expected_receipt_date`, `replenishment_in_progress`) was gated on confirming Acumatica exposes usable Purchase Order and extended inventory data. That check is done:

- **`PurchaseOrder` entity**: blocked by role permissions (`403 — insufficient rights to access the PurchaseOrder (PO301000) form`), not a missing-entity error. Fixable by an Acumatica admin grant, but not something to build against until it's actually granted and the real field shape is confirmed.
- **`qty_allocated` / `reorder_point` / `safety_stock` / `qty_on_po`**: confirmed absent from the live `StockItem`/`WarehouseDetails` response entirely — not a permission issue, a field-mapping gap on the custom "IpayV2" endpoint (and possibly not tracked in Acumatica at all for this account).

Per your direction, Phase 2 is now scoped to **only use the `SalesOrder` entity** — the one thing that's fully working today with zero permission or field-mapping risk. That means the original goal ("is more stock inbound, and when?") is off the table for this phase; nothing in `SalesOrder` data can answer that. What's left is reframing the phase around what genuinely *is* derivable from sales-order history: **chronic-vs-transient shortage detection and a data-driven "typical time to clear" estimate**, in place of a real PO receipt date.

## What's already in place (reusable, not new work)

- `AcumaticaBackorderLine.first_backordered_at` (Phase 1) — tracks how long a *currently active* line has been backordered.
- `fulfillment_history_snapshots` / `fulfillment_history_lines` (`FulfillmentHistoryService::captureFirstCompleted()`) — a one-shot snapshot captured when an order first reaches a completed status, recording each line's `order_qty`/`delivered_qty`/`open_qty`/`shortfall_amount` at that moment. This already gives per-SKU shortfall history for completed orders — it's the seed of chronic-shortage detection, just not yet queried that way.
- `pruneStaleLines()` currently **hard-deletes** a backorder line the moment it resolves — so `first_backordered_at` (how long it took to clear) is thrown away at exactly the moment it becomes useful for historical analysis.

## Goals

1. **Chronic-vs-transient shortage detection per SKU** — how often has this Inventory ID shown up short across completed orders in a trailing window (e.g. 90/180 days)? A SKU with 1 isolated incident reads very differently from one that's recurred 8 times.
2. **Historical resolution time per SKU / reason code** — once enough resolved lines are archived (see below), compute median/average days-to-clear, segmented by `inventory_id` and by `reason_code`.
3. **Estimated-clear-date proxy** — `today + median_resolution_days` for that SKU (or its reason code, if the SKU has too few samples), clearly labeled as a historical-pattern estimate, not a real Acumatica receipt date. This is the SalesOrder-only substitute for the PO-based `next_expected_receipt_date` that Phase 2 originally wanted.
4. **Value-at-risk trend over time** — a lightweight daily rollup of aggregate `revenue_at_risk`, captured at the end of each backorder sync run, giving the trend line the original PRD (§10.3) wanted without needing any new Acumatica entity.

### Non-goals (unchanged, still correctly out of scope)

- Anything claiming to know real inbound stock, a real PO, or a real expected receipt date. Every estimate this phase produces must be visibly labeled as derived from historical resolution patterns, not from Acumatica supply data.
- Revisiting `PurchaseOrder`/extended `InventoryItem` fields — still gated on the Acumatica-side permission/field-mapping fixes from the previous discussion; this phase does not depend on either.

## Implementation Changes

1. **Stop discarding resolution data.** When `pruneStaleLines()` (and `pruneStaleLinesForOrders()`) removes a line because it's no longer backordered, archive `inventory_id`, `order_nbr`, `reason_code`, `first_backordered_at`, and a new `resolved_at` (= now) into a small new table (e.g. `backorder_resolution_history`) before deleting. This is the one real gap — everything else in this phase is a read against data that already exists.
2. **Chronic-shortage query**: count of distinct orders per `inventory_id` with `shortfall_amount > 0` in `fulfillment_history_lines` (completed-order path) **plus** count of rows in the new `backorder_resolution_history` (active-backorder path) over a trailing window — combine both since either path can indicate an SKU keeps coming up short.
3. **Resolution-time aggregation**: `AVG`/`MEDIAN(resolved_at - first_backordered_at)` grouped by `inventory_id`, falling back to grouping by `reason_code` when an individual SKU has too few resolved samples (pick a minimum sample threshold, e.g. 3, below which don't show a per-SKU estimate — show the reason-code-level estimate instead, explicitly labeled as such).
4. **Estimated-clear-date field**: computed per active backorder line as `today + resolution_estimate_days`, only shown when a resolution-time estimate exists (SKU or reason-code level) — never fabricated when there's no historical basis.
5. **Daily value-at-risk rollup**: a small table (e.g. `backorder_value_at_risk_daily`) with one row per sync run (or per day, if multiple syncs run daily — take the last one), storing total `revenue_at_risk`, by `product_segment`, by `customer_segment` — populated at the end of `processOrders()` in `AcumaticaBackorderSyncService`. This is additive, no schema risk to existing tables.
6. Expose all of the above as additive fields/endpoints — same compatibility posture as Phase 1 (nothing existing changes shape).

## Open Questions Before Building

1. Is there enough resolution history yet to make per-SKU estimates meaningful, or does this need to run passively for a few weeks first before the estimate is trustworthy? (Directly affects whether to ship the estimated-clear-date field immediately or behind a "insufficient history" fallback state from day one.)
2. Minimum sample threshold for a per-SKU estimate vs falling back to reason-code — is 3 the right number, or does Sales Ops want a higher bar before trusting a number enough to show it?
3. Does the once-per-order `captureFirstCompleted()` snapshot actually run for every completed order today, or only ones touched by a date-range reimport with `--capture-history`? (Determines how much historical chronic-shortage data already exists vs needs backfilling.)

## Acceptance Criteria

- [ ] No dependency on `PurchaseOrder` or extended `InventoryItem`/`WarehouseDetails` fields anywhere in this phase's code.
- [ ] Resolved backorder lines are archived (not just deleted) with `first_backordered_at` and `resolved_at`, feeding the resolution-time and chronic-shortage calculations.
- [ ] Estimated-clear-date is only shown when a real historical sample backs it, and is labeled as a historical-pattern estimate, not a supply-chain fact.
- [ ] Value-at-risk trend data accumulates going forward without needing any backfill of data that doesn't exist.
- [ ] No change to the existing `isBackorderLine()` classification, `revenue_at_risk` formula, or any Phase 1 field.
