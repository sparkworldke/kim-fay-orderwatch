# Backorder Audit Trail & Lost Sales — Build Spec

**Owner:** Product / MT Commercial
**Audience:** Laravel + React dev team, BI, Supply Chain, Commercial
**Version:** 1.0 — 26 Jul 2026
**Source system:** Acumatica ERP (REST API, real-time sync)
**Reference extract:** `Sales Orders` screen, Order Type = SO, Status = Back Order, Created On = This Month

---

## 0. Read this first — the one-paragraph summary

Acumatica's `Status = Back Order` is a **live state, not a history**. The moment a line is filled, the order leaves the status and disappears from the report. That means Acumatica can tell you *what is short right now* but can never tell you *when it went short, how long it stayed short, or what it cost us*. This build closes that gap by writing an **append-only event ledger** on every sync, so that for every `(order, item)` pair we can state: it entered backorder on 20 Jul, it was resolved on 25 Jul, that is **5 Days Delay**, the window was **20 – 25 Jul**, the cause was `out_of_stock_production`, and the commercial consequence was KES X. Every number on the dashboard, in the API, and in the Excel export must come from one service so all three always agree, and must reconcile to the Acumatica screen through a documented bridge.

---

## 1. What already exists vs. what is missing

| Model (existing) | What it holds | Verdict |
|---|---|---|
| `AcumaticaSalesOrder` | Order header, status, `order_date`, `ship_date`, `approved_at`, `shipped_at`, `completed_at` | Keep. Good header timestamps already. |
| `AcumaticaSalesOrderLine` | Current line state: `order_qty`, `shipped_qty`, `qty_on_shipments`, `open_qty`, `cancelled_qty`, `backorder_qty`, `fill_rate_pct` | Keep. This is the **current-state mirror** of Acumatica. |
| `AcumaticaBackorderLine` | Currently-open backorder lines + `first_backordered_at` + `reason_code` | Keep, but demote to a **materialised view of open episodes**. It must stop being the source of truth for timing. |
| `BackorderResolution` | Closed episodes + `resolved_at` + `days_to_resolve` | Keep and extend. This is 80% of what we need — it just lacks resolution *type* and working-day maths. |
| `AcumaticaFillRateSnapshot` | Order-level fill rate at `computed_at` | Keep. Useful for trend, insufficient for item-level audit. |
| `AcumaticaDeadLetter`, `AcumaticaReconciliationResult` | Sync hygiene | Keep. These become the evidence that the dashboard is trustworthy. |
| **MISSING — `backorder_line_events`** | Append-only ledger of every state transition | **Build this.** Without it there is no audit trail, only a start and an end. |
| **MISSING — `backorder_episodes`** | One row per continuous backorder occurrence, with an `episode_no` | **Build this.** Handles re-opens, which the current design silently overwrites. |
| **MISSING — `reason_code_families`** | Groups the 33 flat codes into 5 root-cause families | **Build this.** You cannot answer "loss due to delayed production" against a flat list. |
| **MISSING — `backorder_daily_snapshots`** | End-of-day position, frozen | **Build this.** This is what ties the dashboard back to the Acumatica screen on any past date. |

### The four defects in the current design

1. **`AcumaticaBackorderLine` is upserted, so history is destroyed.** If a line is 100 cases short on Monday and 40 short on Wednesday, the Monday figure is gone. You cannot draw a burn-down or prove how long the customer waited for each tranche.
2. **No re-open handling.** Line resolves → shipment gets cancelled → line goes short again. Today that either overwrites `first_backordered_at` (understating the delay) or keeps the old one (overstating it). Neither is correct. Episodes fix this.
3. **`first_backordered_at_is_backfilled` exists but nothing consumes it.** Any backfilled row has an *estimated* start date, so its `days_to_resolve` is a **floor, not a fact**. If those rows are averaged in with real ones, every duration on the dashboard is understated and nobody can tell by how much. This flag must propagate all the way to the UI and the Excel.
4. **Timing granularity is capped by sync frequency and nobody has stated it.** If the sync runs daily, `days_to_resolve` carries ±1 day of error. Sales will find one wrong case and stop trusting the whole report.

---

## 2. Canonical definitions — agree these before writing code

These are the definitions the whole company signs off on. Put them in a code constant, not a wiki page.

### 2.1 Quantity ladder

Acumatica exposes several quantity fields that are easy to confuse. Use exactly this ladder:

```
order_qty          -- what the customer asked for
cancelled_qty      -- removed from the order, will never ship
shipped_qty        -- confirmed shipped (goods have left)
qty_on_shipments   -- allocated to an open, unconfirmed shipment (goods are committed)

net_order_qty  = order_qty - cancelled_qty
open_qty       = net_order_qty - shipped_qty                     -- owed to the customer
committed_qty  = qty_on_shipments                                -- owed but already picked
backorder_qty  = GREATEST(0, open_qty - committed_qty)           -- owed and NOT picked  <-- THIS is the backorder
```

**`backorder_qty` is the only quantity that means "short".** `open_qty` includes goods already on a truck; using it inflates the shortage. This is the single most common reporting error — lock it down in one place.

### 2.2 Line fill rate

```
line_fill_rate_pct = shipped_qty / NULLIF(net_order_qty, 0) * 100
```

Cancelled quantity is excluded from the denominator. If you leave it in, a cancelled line permanently drags the fill rate down and Supply Chain gets blamed for a Commercial decision.

### 2.3 Value at risk

```
line_value_at_risk = backorder_qty * (unit_price - unit_discount)
```

Use net-of-discount unit price. `AcumaticaSalesOrderLine.discount_amount` is a line total, so `unit_discount = discount_amount / NULLIF(order_qty, 0)`.

### 2.4 Episode — the unit of the audit trail

An **episode** is one continuous period during which a given `(order_nbr, inventory_id)` had `backorder_qty > 0`.

```
episode_key = (order_nbr, inventory_id, episode_no)

opened_at    = timestamp of the FIRST sync run in which backorder_qty > 0
resolved_at  = timestamp of the FIRST sync run in which backorder_qty = 0, after opened_at
episode_no   = 1 for the first occurrence, incremented on each re-open
```

An episode is the row a customer would recognise: *"you owed me 40 cases of SIFTP0015 on this order from the 20th to the 25th."*

### 2.5 Duration — calendar and working days, both stored

```
days_to_resolve         = DATEDIFF(day, opened_at, resolved_at)              -- calendar
working_days_to_resolve = business days between, excluding Sundays
                          and the Kenyan public holiday calendar
```

**Kim-Fay operates a six-day week.** Saturday counts, Sunday does not. Store both; default the dashboard to **working days** for service KPIs and **calendar days** for customer-facing ageing, and label which is which on screen. Never silently mix them.

Ageing for a still-open episode uses the same maths against `NOW()`.

### 2.6 Resolution type — how the episode ended

This is missing from `BackorderResolution` and it matters more than `days_to_resolve`, because "we shipped it" and "the customer gave up" are opposite outcomes with the same duration.

| `resolution_type` | Detection rule | Commercial meaning |
|---|---|---|
| `shipped_full` | `backorder_qty → 0` and `shipped_qty` increased to `net_order_qty` | Recovered, late |
| `shipped_partial` | `backorder_qty → 0`, `shipped_qty` increased, `cancelled_qty` increased | Part recovered, part lost |
| `cancelled` | `backorder_qty → 0` driven entirely by `cancelled_qty` increasing | **Lost sale** |
| `committed` | `backorder_qty → 0` because `qty_on_shipments` rose (picked, not yet shipped) | In transit — not yet resolved, do not count as recovered |
| `order_closed` | Order header moved to `Completed`/`Cancelled` with the line still open | Investigate — usually a data issue |
| `stale_closed` | No sync activity for N days and the order is inactive | Excluded from all KPIs, flagged for cleanup |

`committed` is a trap. A line that gets picked but not shipped looks resolved on quantity but the customer still has nothing. Treat it as **still open** for service reporting and record the pick as an intermediate event.

---

## 3. Reason-code taxonomy — 5 families over the existing 33 codes

You have 33 codes in a flat array in both `AcumaticaBackorderLine::REASON_CODES` and the deprecated `AcumaticaSalesOrder::REJECTION_REASON_CODES`. They are duplicated and ungrouped, so "loss of sales due to delayed production" is currently unanswerable. Add a family layer. **Do not change the codes** — analysts already use them.

| Family | Codes | Accountable function |
|---|---|---|
| **PRODUCTION** (8) | `out_of_stock_production`, `production_stockout`, `raw_material_stockout`, `order_to_make`, `conversion_delays`, `conversion_issues`, `batch_sequence`, `wrong_moq` | Manufacturing / Planning |
| **PROCUREMENT** (4) | `out_of_stock_procurement`, `out_of_stock_msa`, `delayed_supplier_payment`, `kebs_stickers` | Procurement / Imports / Finance |
| **LOGISTICS** (4) | `delay_in_delivery`, `transfer_delays`, `truck_full`, `did_not_pick_on_shipment` | Warehouse / Distribution |
| **COMMERCIAL** (6) | `discontinued`, `pb_discontinued`, `non_focus`, `promo_product`, `short_expiry`, `npd` | Sales / Marketing / Range |
| **DATA_PROCESS** (11) | `delayed_communication`, `price_difference`, `price_variance`, `price_overcharge`, `invoicing_error`, `stock_variance`, `isolation_error`, `wrong_product_description`, `wrong_code`, `system_error`, `lpo_error` | Master Data / Customer Service |

**Total: 33 — every existing code is mapped, nothing is orphaned.**

Two design notes for the dev team:

- `wrong_moq` sits under PRODUCTION deliberately: it means the order quantity did not fit a production batch, which is a planning constraint, not a sales error. If Commercial disputes this, make it configurable rather than hardcoded — that is exactly why the mapping goes in a table.
- **PRODUCTION + PROCUREMENT = "true supply shortage".** DATA_PROCESS and COMMERCIAL items are *not* stock problems and must be separable, or supply performance looks far worse than it is. In the 23 July extract, 8 of 10 rejected orders were `Account is in arrears` — a credit block, not a stock block. Those must never touch a fill-rate number.

### 3.1 Reason capture is the weak link

`reason_code` is nullable and human-entered (`reason_updated_by`, `reason_updated_at`). An unreasoned episode cannot be attributed to production. Therefore:

- The dashboard must show **reason coverage %** = `episodes with reason_code / total episodes` as a first-class KPI next to every attributed figure.
- Any loss figure must be footnoted with its coverage. *"KES 1.2m attributed to PRODUCTION (reason coverage 64%)"* is honest. A bare KES 1.2m is not.
- Enforce a rule: an episode open more than 48 hours with `revenue_at_risk` above a threshold cannot be left unreasoned — surface it in a work queue.
- Default unreasoned episodes to family `UNCLASSIFIED`, never to a real family.
---

## 4. Loss of sales — three numbers that must never be added together

This is where most dashboards lose credibility. "Lost sales" is three different things and summing them double-counts.

### 4.1 The three measures

```
┌─ 1. REVENUE AT RISK ──────────────────────────────────── still recoverable
│  Episodes currently OPEN.
│  revenue_at_risk = SUM(backorder_qty * net_unit_price) WHERE resolved_at IS NULL
│  Meaning: money we could still collect if stock arrives.
│  Do NOT call this lost.
│
├─ 2. DELAYED REVENUE ──────────────────────────────────── recovered, but late
│  Episodes RESOLVED as shipped_full / shipped_partial.
│  delayed_revenue      = SUM(shipped_late_qty * net_unit_price)
│  revenue_days_delayed = SUM(shipped_late_qty * net_unit_price * days_late)
│  where days_late = GREATEST(0, working_days_to_resolve - promised_lead_time_days)
│  Meaning: a SERVICE cost, not a revenue loss. The sale happened.
│  revenue_days_delayed (KES-days) is the right way to rank causes —
│  it weights a large order held 2 days against a small one held 30.
│
└─ 3. LOST REVENUE ─────────────────────────────────────── gone
   lost_revenue = SUM(cancelled_qty * net_unit_price)
                  WHERE resolution_type IN ('cancelled','shipped_partial')
                + SUM(backorder_qty * net_unit_price)
                  WHERE resolved_at IS NULL
                    AND working_days_open > write_off_threshold_days
   Meaning: revenue we will never invoice. THIS is loss of sales.
```

`write_off_threshold_days` is a **commercial parameter, not a technical one.** Recommended starting values, stored in config and editable without a deploy:

| Channel | Threshold | Rationale |
|---|---|---|
| Modern Trade T1 (Carrefour, Quick Mart) | 7 working days | Buyers re-order on a weekly cycle; past that the slot is filled by a competitor |
| Modern Trade T2 | 10 working days | Slower replenishment rhythm |
| General Trade / Distributor | 14 working days | Distributors hold buffer stock |
| Pharma / Key Accounts | 7 working days | Tender and script-driven, time-critical |
| Professional / Contract | 21 working days | Project-based, longer tolerance |

### 4.2 The headline the business asked for

> **"Loss of sales due to delayed production"**

```sql
SELECT
    SUM(CASE WHEN e.resolution_type IN ('cancelled','shipped_partial')
             THEN e.cancelled_qty * e.net_unit_price ELSE 0 END)
  + SUM(CASE WHEN e.resolved_at IS NULL
              AND e.working_days_open > c.write_off_threshold_days
             THEN e.backorder_qty * e.net_unit_price ELSE 0 END)
        AS lost_sales_production
FROM backorder_episodes e
JOIN reason_code_families f ON f.reason_code = e.reason_code
JOIN channel_thresholds    c ON c.channel    = e.channel
WHERE f.family = 'PRODUCTION'
  AND e.opened_at BETWEEN :from AND :to
  AND e.is_excluded_from_kpi = FALSE;
```

Present it beside — never merged with — `revenue_days_delayed` for the same family. Those two together tell the whole story: *what we lost outright*, and *how long we made customers wait for what we did deliver*.

### 4.3 Exclusion rules — apply to every KPI, no exceptions

Set `is_excluded_from_kpi = TRUE` for any episode where:

| Rule | Why |
|---|---|
| `line_type` is a free / promotional / zero-value line | Value-based fill rate reads 100% while quantity is still open. In the 23 Jul extract, SO366417 (Elburgon Bidii) showed Unbilled = 0 yet status was still Back Order for exactly this reason. |
| `net_unit_price = 0` | Cannot carry a value KPI. |
| Order header `status = 'Rejected'` | Credit block, never reached the warehouse. |
| Order header `status = 'On Hold'` / `'Pending Approval'` | Not yet a commitment to the customer. |
| `order_type != 'SO'` | `IN_SCOPE_ORDER_TYPES` already says SO only — but the reference extract leaked 12 `PP` rows, so the filter must be enforced in code, not trusted from the export. |
| `resolution_type = 'stale_closed'` | Data hygiene artefact. |
| Customer is an internal / cash-sale / staff account | `Cash Sale Staff` appeared 6 times in the extract. Not commercial demand. |

Excluded episodes stay visible in the detail grid with a reason chip. Deleting them destroys the audit trail; hiding them from KPIs is the goal.

---

## 5. Schema — migrations

### 5.1 `reason_code_families`

```php
Schema::create('reason_code_families', function (Blueprint $t) {
    $t->id();
    $t->string('reason_code')->unique();
    $t->string('family');                   // PRODUCTION|PROCUREMENT|LOGISTICS|COMMERCIAL|DATA_PROCESS|UNCLASSIFIED
    $t->string('label');                    // human label for UI + Excel
    $t->string('accountable_function');
    $t->boolean('is_true_supply_shortage')->default(false); // PRODUCTION + PROCUREMENT
    $t->boolean('is_active')->default(true);
    $t->unsignedSmallInteger('sort_order')->default(0);
    $t->timestamps();
});
```

Seed it from the table in §3. Editable via admin UI so Commercial can re-map `wrong_moq` without a release.

### 5.2 `backorder_episodes` — one row per occurrence

```php
Schema::create('backorder_episodes', function (Blueprint $t) {
    $t->id();

    // identity
    $t->string('order_nbr')->index();
    $t->string('order_type', 4)->default('SO');
    $t->string('inventory_id')->index();
    $t->unsignedInteger('episode_no')->default(1);
    $t->foreignId('sales_order_id')->nullable()->index();
    $t->unsignedBigInteger('sales_order_line_id')->nullable()->index();
    $t->unsignedSmallInteger('line_nbr')->nullable();

    // denormalised for fast filtering — refreshed on write
    $t->string('customer_acumatica_id')->index();
    $t->string('customer_name');
    $t->string('main_account_id')->nullable()->index();
    $t->string('main_account_name')->nullable();
    $t->string('customer_class')->nullable()->index();
    $t->string('channel')->nullable()->index();      // Modern Trade T1 / T2 / General Trade / Pharmacy ...
    $t->string('route_code')->nullable()->index();
    $t->string('warehouse_id')->nullable()->index();  // FGS / TPFGS
    $t->string('item_description')->nullable();
    $t->string('uom', 12)->nullable();
    $t->string('currency_id', 5)->default('KES');

    // ---- THE AUDIT TRAIL ----
    $t->timestamp('opened_at')->index();
    $t->boolean('opened_at_is_backfilled')->default(false);
    $t->unsignedBigInteger('opened_sync_run_id')->nullable();
    $t->timestamp('resolved_at')->nullable()->index();
    $t->unsignedBigInteger('resolved_sync_run_id')->nullable();
    $t->string('resolution_type', 24)->nullable();
    $t->integer('days_to_resolve')->nullable();
    $t->integer('working_days_to_resolve')->nullable();
    $t->integer('days_open')->nullable();             // nightly refresh while open
    $t->integer('working_days_open')->nullable();
    $t->integer('days_late')->nullable();             // vs promised_lead_time_days
    $t->unsignedSmallInteger('promised_lead_time_days')->nullable();
    $t->date('scheduled_shipment_date')->nullable();
    $t->date('requested_on')->nullable();
    $t->unsignedInteger('partial_fill_count')->default(0);
    $t->timestamp('first_partial_fill_at')->nullable();
    $t->timestamp('last_movement_at')->nullable();

    // quantities — opening, current, closing
    $t->decimal('order_qty', 18, 4);
    $t->decimal('peak_backorder_qty', 18, 4);         // worst point — what we actually failed on
    $t->decimal('backorder_qty', 18, 4);              // current (0 once resolved)
    $t->decimal('shipped_qty', 18, 4)->default(0);
    $t->decimal('shipped_late_qty', 18, 4)->default(0);
    $t->decimal('cancelled_qty', 18, 4)->default(0);

    // value
    $t->decimal('net_unit_price', 18, 4);
    $t->decimal('revenue_at_risk', 18, 2)->default(0);
    $t->decimal('delayed_revenue', 18, 2)->default(0);
    $t->decimal('revenue_days_delayed', 20, 2)->default(0);
    $t->decimal('lost_revenue', 18, 2)->default(0);

    // attribution
    $t->string('reason_code')->nullable()->index();
    $t->string('reason_family', 24)->nullable()->index();
    $t->text('reason_notes')->nullable();
    $t->unsignedBigInteger('reason_updated_by')->nullable();
    $t->timestamp('reason_updated_at')->nullable();

    // governance
    $t->boolean('is_excluded_from_kpi')->default(false);
    $t->string('exclusion_reason')->nullable();
    $t->string('line_type')->nullable();
    $t->timestamps();

    $t->unique(['order_nbr','inventory_id','episode_no'], 'uq_episode');
    $t->index(['opened_at','resolved_at']);
    $t->index(['reason_family','opened_at']);
    $t->index(['channel','opened_at']);
    $t->index(['is_excluded_from_kpi','opened_at']);
});
```

### 5.3 `backorder_line_events` — append-only, never updated

```php
Schema::create('backorder_line_events', function (Blueprint $t) {
    $t->id();
    $t->foreignId('episode_id')->constrained('backorder_episodes')->cascadeOnDelete();
    $t->string('order_nbr')->index();
    $t->string('inventory_id')->index();

    $t->string('event_type', 32);
    // opened | qty_increased | qty_decreased | partial_fill | picked
    // | reason_set | reason_changed | resolved | reopened | cancelled | excluded

    $t->timestamp('occurred_at')->index();     // sync run timestamp (system truth)
    $t->timestamp('effective_at')->nullable(); // Acumatica last_modified, if available

    // state BEFORE and AFTER — this is what makes it an audit trail
    $t->decimal('backorder_qty_before', 18, 4)->nullable();
    $t->decimal('backorder_qty_after', 18, 4)->nullable();
    $t->decimal('shipped_qty_before', 18, 4)->nullable();
    $t->decimal('shipped_qty_after', 18, 4)->nullable();
    $t->decimal('cancelled_qty_before', 18, 4)->nullable();
    $t->decimal('cancelled_qty_after', 18, 4)->nullable();
    $t->decimal('delta_qty', 18, 4)->nullable();
    $t->decimal('delta_value', 18, 2)->nullable();

    $t->string('order_status_at_event')->nullable();
    $t->string('reason_code_at_event')->nullable();
    $t->string('actor', 64)->default('system');   // 'system' | user id | 'backfill'
    $t->unsignedBigInteger('sync_run_id')->nullable()->index();
    $t->json('raw_snapshot')->nullable();         // the API line payload, for disputes
    $t->timestamp('created_at')->useCurrent();

    $t->index(['episode_id','occurred_at']);
    $t->index(['event_type','occurred_at']);
});
```

**No `updated_at`. No update path. Ever.** If a value was wrong, write a correcting event. This table is the evidence base when Sales challenges a number — and they will.

### 5.4 `backorder_daily_snapshots` — the reconciliation anchor

```php
Schema::create('backorder_daily_snapshots', function (Blueprint $t) {
    $t->id();
    $t->date('snapshot_date')->index();
    $t->timestamp('captured_at');
    $t->string('scope', 32)->default('SO_BACKORDER'); // mirrors the Acumatica filter

    // dashboard figures
    $t->unsignedInteger('open_episode_count');
    $t->unsignedInteger('open_order_count');          // DISTINCT order_nbr
    $t->unsignedInteger('open_item_count');           // DISTINCT inventory_id
    $t->decimal('revenue_at_risk', 18, 2);

    // Acumatica-equivalent figures, for the bridge
    $t->unsignedInteger('acumatica_order_count')->nullable();
    $t->decimal('acumatica_unbilled_total', 18, 2)->nullable();
    $t->decimal('acumatica_line_total', 18, 2)->nullable();
    $t->decimal('variance_amount', 18, 2)->nullable();
    $t->decimal('variance_pct', 8, 4)->nullable();
    $t->json('variance_breakdown')->nullable();       // the bridge, itemised
    $t->boolean('is_reconciled')->default(false);

    $t->unsignedBigInteger('sync_run_id')->nullable();
    $t->timestamps();
    $t->unique(['snapshot_date','scope']);
});
```

### 5.5 Extend `BackorderResolution`

Keep the model for backward compatibility, but add and backfill:

```php
$t->string('resolution_type', 24)->nullable();
$t->integer('working_days_to_resolve')->nullable();
$t->integer('days_late')->nullable();
$t->unsignedInteger('episode_no')->default(1);
$t->foreignId('episode_id')->nullable()->constrained('backorder_episodes');
$t->string('reason_family', 24)->nullable();
$t->decimal('shipped_late_qty', 18, 4)->default(0);
$t->decimal('lost_revenue', 18, 2)->default(0);
$t->decimal('revenue_days_delayed', 20, 2)->default(0);
```

Long term, `BackorderResolution` becomes a read-model projected from `backorder_episodes` where `resolved_at IS NOT NULL`. Do not maintain two write paths.

---

## 6. Laravel pipeline

### 6.1 Job sequence per sync run

```
ScheduleAcumaticaSync (every 15 min, business hours; hourly overnight)
  │
  ├─ 1. SyncSalesOrdersJob        → upsert AcumaticaSalesOrder
  ├─ 2. SyncSalesOrderLinesJob    → upsert AcumaticaSalesOrderLine  (current state mirror)
  │                                  compute open_qty / backorder_qty per §2.1
  ├─ 3. DetectBackorderEventsJob  → *** the new core *** — diff & write ledger
  ├─ 4. RefreshOpenEpisodeAgeingJob → recompute days_open / working_days_open / revenue_at_risk
  ├─ 5. ComputeFillRateSnapshotJob  → existing AcumaticaFillRateSnapshot
  ├─ 6. CaptureDailySnapshotJob     → 23:55 EAT only
  └─ 7. ReconcileWithAcumaticaJob   → 23:59 EAT — write the bridge, raise variances
```

Wrap each run in a `sync_run_id`. Every event, episode and snapshot carries it. That is how you answer *"which sync introduced this number?"*

### 6.2 `DetectBackorderEventsJob` — the algorithm

```php
public function handle(int $syncRunId): void
{
    $now = now();

    // Snapshot of what we currently believe is open, keyed for O(1) lookup
    $openEpisodes = BackorderEpisode::whereNull('resolved_at')
        ->get()
        ->keyBy(fn ($e) => $e->order_nbr.'|'.$e->inventory_id);

    AcumaticaSalesOrderLine::query()
        ->with('order')
        ->whereHas('order', fn ($q) => $q->whereIn('status', self::BACKORDER_CAPABLE_STATUSES))
        ->chunkById(1000, function ($lines) use (&$openEpisodes, $syncRunId, $now) {
            foreach ($lines as $line) {

                $key = $line->order->acumatica_order_nbr.'|'.$line->inventory_id;
                $qty = $this->quantities($line);          // §2.1 ladder, one place
                $episode = $openEpisodes->get($key);

                // ---- CASE A: newly short ----
                if (! $episode && $qty['backorder_qty'] > 0) {
                    $episode = $this->openEpisode($line, $qty, $syncRunId, $now);
                    $this->event($episode, 'opened', null, $qty, $syncRunId, $now);
                    $openEpisodes->put($key, $episode);
                    continue;
                }

                if (! $episode) {
                    continue;                              // never short, nothing to do
                }

                $before = $episode->only([
                    'backorder_qty', 'shipped_qty', 'cancelled_qty',
                ]);

                // ---- CASE B: resolved ----
                if ($qty['backorder_qty'] <= 0) {
                    $type = $this->classifyResolution($before, $qty, $line);

                    // 'committed' means picked but not shipped — customer still has nothing.
                    // Record the pick, keep the episode OPEN.
                    if ($type === 'committed') {
                        $this->event($episode, 'picked', $before, $qty, $syncRunId, $now);
                        $episode->update(['last_movement_at' => $now] + $qty);
                        continue;
                    }

                    $this->closeEpisode($episode, $qty, $type, $syncRunId, $now);
                    $this->event($episode, 'resolved', $before, $qty, $syncRunId, $now);
                    $openEpisodes->forget($key);
                    continue;
                }

                // ---- CASE C: still short, quantity moved ----
                if (bccomp((string) $qty['backorder_qty'], (string) $before['backorder_qty'], 4) !== 0) {

                    $eventType = $qty['shipped_qty'] > $before['shipped_qty']
                        ? 'partial_fill'
                        : ($qty['backorder_qty'] > $before['backorder_qty'] ? 'qty_increased' : 'qty_decreased');

                    $this->event($episode, $eventType, $before, $qty, $syncRunId, $now);

                    $episode->update([
                        'backorder_qty'      => $qty['backorder_qty'],
                        'shipped_qty'        => $qty['shipped_qty'],
                        'cancelled_qty'      => $qty['cancelled_qty'],
                        'peak_backorder_qty' => max($episode->peak_backorder_qty, $qty['backorder_qty']),
                        'revenue_at_risk'    => $qty['backorder_qty'] * $episode->net_unit_price,
                        'last_movement_at'   => $now,
                        'partial_fill_count' => $eventType === 'partial_fill'
                            ? $episode->partial_fill_count + 1
                            : $episode->partial_fill_count,
                        'first_partial_fill_at' => $episode->first_partial_fill_at
                            ?? ($eventType === 'partial_fill' ? $now : null),
                    ]);
                }
            }
        });

    // ---- CASE D: re-open — a previously resolved pair is short again ----
    $this->detectReopens($syncRunId, $now);
}
```

`BACKORDER_CAPABLE_STATUSES` = `['Back Order', 'Open', 'Shipping']`. Deliberately wider than Acumatica's `Back Order` filter: a line can be short on an order whose header reads `Shipping` because other lines went out. Narrowing to the header status is why line counts and header counts never agree — see §7.

### 6.3 Re-open detection

```php
protected function detectReopens(int $syncRunId, Carbon $now): void
{
    // A pair that is short now, whose most recent episode is closed
    $candidates = DB::table('acumatica_sales_order_lines as l')
        ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
        ->selectRaw('o.acumatica_order_nbr, l.inventory_id, l.id as line_id')
        ->whereRaw('GREATEST(0, (l.order_qty - l.cancelled_qty - l.shipped_qty) - l.qty_on_shipments) > 0')
        ->whereNotExists(function ($q) {
            $q->from('backorder_episodes as e')
              ->whereColumn('e.order_nbr', 'o.acumatica_order_nbr')
              ->whereColumn('e.inventory_id', 'l.inventory_id')
              ->whereNull('e.resolved_at');
        })
        ->whereExists(function ($q) {
            $q->from('backorder_episodes as e2')
              ->whereColumn('e2.order_nbr', 'o.acumatica_order_nbr')
              ->whereColumn('e2.inventory_id', 'l.inventory_id');
        })
        ->cursor();

    foreach ($candidates as $row) {
        $lastNo = BackorderEpisode::where('order_nbr', $row->acumatica_order_nbr)
            ->where('inventory_id', $row->inventory_id)
            ->max('episode_no');

        $episode = $this->openEpisode(
            AcumaticaSalesOrderLine::find($row->line_id),
            /* qty */ null,
            $syncRunId,
            $now,
            episodeNo: $lastNo + 1,
        );

        $this->event($episode, 'reopened', null, null, $syncRunId, $now);
    }
}
```

A re-opened episode gets a **fresh clock**. Do not chain durations across episodes — report them separately and show `episode_no` in the UI so a repeat offender is visible.

### 6.4 Working-day calculation

```php
final class WorkingDays
{
    /** Kim-Fay: Mon–Sat operational, Sunday closed. */
    public static function between(CarbonInterface $from, CarbonInterface $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $holidays = app(HolidayCalendar::class)->kenyanHolidays(
            $from->year, $to->year
        ); // cached, seeded table — NOT hardcoded

        $days = 0;
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        while ($cursor->lt($end)) {
            $cursor->addDay();
            if ($cursor->isSunday()) {
                continue;
            }
            if (in_array($cursor->toDateString(), $holidays, true)) {
                continue;
            }
            $days++;
        }

        return $days;
    }
}
```

Seed `holidays` from the Kenyan gazette each December. A hardcoded array will silently drift and every duration on the dashboard drifts with it.

### 6.5 Backfill — one-off, and honest about itself

For episodes already open before this build ships, `opened_at` is unknowable. Estimate it as `MAX(order_date, approved_at)` and set both `opened_at_is_backfilled = TRUE` and an `actor = 'backfill'` event.

```php
BackorderEpisode::create([
    ...,
    'opened_at'               => $order->approved_at ?? $order->order_date,
    'opened_at_is_backfilled' => true,
]);
```

Then, in **every** aggregate:

```php
// Durations from backfilled episodes are a lower bound, not a measurement.
$reliable = $query->clone()->where('opened_at_is_backfilled', false);
$avgDays  = $reliable->avg('working_days_to_resolve');
$coverage = $reliable->count() / max($query->count(), 1);
```

The UI shows `Avg 5 Days Delay` with a footnote: *"based on 62 of 74 episodes with verified start dates (84%). 12 backfilled episodes excluded — their durations are minimums."* This single line is what stops the report being dismissed in the first review meeting.
---

## 7. Tying out to the Acumatica report — the bridge

The dashboard will **not** match the Acumatica screen out of the box, and that is expected. What is not acceptable is being unable to explain the difference. Build the bridge as a visible feature, not a spreadsheet someone keeps privately.

### 7.1 Why the numbers differ — the six causes

Measured against the reference extract (Back Orders, 01–23 Jul 2026: 84 rows, **74 unique orders**, KES 8,436,411 line total, KES 3,861,891 unbilled):

| # | Cause | Effect | Handling |
|---|---|---|---|
| 1 | **Acumatica repeats orders per shipment.** 84 rows for 74 orders. SO361051 appears 4 times, SO365985 3 times. | Naïve row-count and value sums overstate by ~13% | Always `COUNT(DISTINCT order_nbr)`; never `SUM` an order-level column off the raw export |
| 2 | **`Unbilled Order Total` ≠ `backorder_qty × price`.** Unbilled includes goods shipped but not yet invoiced, and excludes zero-value lines. | Acumatica's KES 3.86m ≠ our `revenue_at_risk` | Report both. `revenue_at_risk` is the operational truth; unbilled is the finance view. Bridge them explicitly. |
| 3 | **Header status vs line status.** Acumatica filters `Status = Back Order` on the header. A short line can sit on a `Shipping` header. | Our episode count exceeds Acumatica's order count | Expected. Show both, labelled *"orders in Back Order status"* vs *"order-items with open shortage"* |
| 4 | **Order type leakage.** The screen filter said SO (72 records) but the export contained 12 `PP` rows. | ±12 orders | Enforce `order_type = 'SO'` in code per `IN_SCOPE_ORDER_TYPES`; expose a toggle to include PP for comparison |
| 5 | **Zero-value / free lines.** SO366417 read Unbilled = 0 while still `Back Order`. | Value fill rate reads 100% on an unfilled order | Exclude from value KPIs, keep in quantity KPIs, flag in UI |
| 6 | **Timing.** The extract was 23/07 17:41. Our snapshot is 23:55. | Orders shipped in the gap | Compare snapshot-to-snapshot, never live-to-historic-export |

### 7.2 The reconciliation view — ship this as a UI tab

```
Acumatica: Back Orders, 01–23 Jul 2026                      Orders        Value (KES)
──────────────────────────────────────────────────────────────────────────────────────
Acumatica raw export rows                                       84         8,436,411
  less duplicate rows (one per shipment)                       (10)        (  ... )
= Acumatica distinct orders                                     74         8,436,411
  Acumatica "Unbilled Order Total"                                         3,861,891

Bridge to dashboard:
  Unbilled total (Acumatica)                                               3,861,891
  less shipped-not-yet-invoiced                                           (       X )
  less zero-value / free lines                                            (       X )
  less non-SO order types (PP)                                            (       X )
  less rejected / on-hold headers                                         (       X )
  plus short lines on 'Shipping' headers                                        X
= Dashboard Revenue at Risk                                                      X
──────────────────────────────────────────────────────────────────────────────────────
Unexplained variance                                                             0     ✓
```

**Gate:** `ReconcileWithAcumaticaJob` sets `is_reconciled = TRUE` only when unexplained variance ≤ 0.5% of unbilled total. Above that, raise an `AcumaticaReconciliationResult` with `severity = 'high'` and show a banner on the dashboard. A dashboard that silently disagrees with the ERP is worse than no dashboard.

Reuse the existing `AcumaticaReconciliationResult` model — it already has `field_name`, `local_value`, `acumatica_value`, `severity`, `remediation_status`. That is exactly the right shape.

---

## 8. API contract

### 8.1 Endpoints

```
GET  /api/backorders/summary          → KPI cards
GET  /api/backorders/episodes         → paginated grid
GET  /api/backorders/episodes/{id}    → episode + full event timeline (the audit trail)
GET  /api/backorders/by-reason        → family / code breakdown
GET  /api/backorders/by-customer      → account & channel breakdown
GET  /api/backorders/by-item          → SKU breakdown, ranked by revenue_days_delayed
GET  /api/backorders/trend            → daily series from backorder_daily_snapshots
GET  /api/backorders/reconciliation   → the §7.2 bridge
GET  /api/backorders/filter-options   → dropdown sources
POST /api/backorders/export           → queued Excel job, returns job id
PATCH /api/backorders/episodes/{id}/reason → set reason_code (writes a reason_set event)
```

**Every endpoint accepts the identical filter payload** (§9). One `BackorderFilterRequest` FormRequest, one `BackorderQueryBuilder` service. This is the mechanism that guarantees the Excel and the dashboard agree — they are literally the same query.

### 8.2 Summary response

```json
{
  "period": {
    "from": "2026-07-20",
    "to": "2026-07-25",
    "label": "20 – 25 Jul 2026",
    "days": 6
  },
  "kpis": {
    "open_episodes":        { "value": 128, "delta_pct": -12.4 },
    "open_orders":          { "value": 74 },
    "open_items":           { "value": 41 },
    "revenue_at_risk":      { "value": 3861890.51, "currency": "KES" },
    "resolved_episodes":    { "value": 96 },
    "avg_days_to_resolve":  {
      "value": 5,
      "label": "5 Days Delay",
      "basis": "working_days",
      "sample_size": 62,
      "excluded_backfilled": 12,
      "confidence": 0.84
    },
    "longest_open": {
      "value": 13,
      "label": "13 Days Delay",
      "order_nbr": "SO363506",
      "inventory_id": "SIFTP0015",
      "window_label": "10 – 23 Jul 2026"
    },
    "lost_revenue":          { "value": 412300.00 },
    "delayed_revenue":       { "value": 1204560.00 },
    "revenue_days_delayed":  { "value": 8431920.00, "unit": "KES-days" }
  },
  "loss_by_family": [
    { "family": "PRODUCTION",   "label": "Production",  "lost_revenue": 268400.00,
      "revenue_days_delayed": 5120300.00, "episodes": 34, "avg_days": 7,
      "avg_days_label": "7 Days Delay" },
    { "family": "PROCUREMENT",  "label": "Procurement", "lost_revenue": 92100.00, "...": "..." }
  ],
  "data_quality": {
    "reason_coverage_pct": 64.2,
    "backfilled_episode_pct": 16.2,
    "excluded_episode_count": 19,
    "is_reconciled": true,
    "last_sync_at": "2026-07-26T08:45:00+03:00",
    "variance_pct": 0.31
  }
}
```

`data_quality` is **not optional and not collapsible**. It renders as a strip under the KPI cards. Every figure above it is only as good as those four numbers.

### 8.3 Episode timeline response — the audit trail view

```json
{
  "episode": {
    "id": 8821,
    "order_nbr": "SO363506",
    "episode_no": 1,
    "inventory_id": "SIFTP0015",
    "item_description": "Sifa TP Emb. Unwrap. Twinpack 20x2s White",
    "customer_name": "Khetia Drapers Ltd - Plus",
    "main_account_name": "Khetia Drapers Ltd",
    "channel": "Modern Trade T2",
    "warehouse_id": "FGS",
    "window_label": "10 – 23 Jul 2026",
    "duration_label": "13 Days Delay",
    "duration_basis": "working_days",
    "days_to_resolve": null,
    "working_days_open": 13,
    "opened_at_is_backfilled": false,
    "status": "open",
    "reason_code": "out_of_stock_production",
    "reason_family": "PRODUCTION",
    "order_qty": 1356,
    "peak_backorder_qty": 680,
    "backorder_qty": 420,
    "shipped_qty": 936,
    "revenue_at_risk": 94472.29
  },
  "timeline": [
    { "occurred_at": "2026-07-10T09:14:00+03:00", "event_type": "opened",
      "label": "Entered backorder", "backorder_qty_after": 680,
      "delta_value": 152900.00, "actor": "system" },
    { "occurred_at": "2026-07-11T11:02:00+03:00", "event_type": "reason_set",
      "label": "Reason set: Out of stock — production", "actor": "j.mwangi" },
    { "occurred_at": "2026-07-15T14:30:00+03:00", "event_type": "partial_fill",
      "label": "Partial fill — 180 CASE shipped", "backorder_qty_before": 680,
      "backorder_qty_after": 500, "delta_qty": -180, "delta_value": -40480.00 },
    { "occurred_at": "2026-07-18T08:05:00+03:00", "event_type": "partial_fill",
      "label": "Partial fill — 80 CASE shipped", "backorder_qty_after": 420,
      "delta_qty": -80 }
  ]
}
```

The timeline **is** the audit trail. Render it as a vertical stepper. When Khetia's buyer calls, this is the screen you open.

---

## 9. Filter bar — the shared contract

One payload, used identically by every endpoint and by the Excel export.

```ts
interface BackorderFilters {
  // ── period ──────────────────────────────────────────────
  date_field: 'opened_at' | 'resolved_at' | 'order_date';  // default 'opened_at'
  date_preset: 'today' | 'yesterday' | 'last_7_days' | 'this_month'
             | 'last_month' | 'mtd' | 'ytd' | 'custom';
  date_from?: string;   // YYYY-MM-DD, required when custom
  date_to?: string;

  // ── state ───────────────────────────────────────────────
  state: 'all' | 'open' | 'resolved';                      // default 'open'
  resolution_type?: string[];                              // shipped_full | cancelled | ...
  age_bucket?: ('same_day'|'1_3'|'4_7'|'8_14'|'15_plus')[];
  duration_basis: 'working_days' | 'calendar_days';        // default 'working_days'

  // ── cause ───────────────────────────────────────────────
  reason_family?: string[];                                // PRODUCTION | ...
  reason_code?: string[];
  true_supply_shortage_only?: boolean;                      // PRODUCTION + PROCUREMENT
  unreasoned_only?: boolean;                                // the work queue

  // ── commercial ──────────────────────────────────────────
  channel?: string[];
  customer_class?: string[];
  main_account_id?: string[];
  customer_acumatica_id?: string[];
  route_code?: string[];

  // ── supply ──────────────────────────────────────────────
  warehouse_id?: string[];
  inventory_id?: string[];
  item_class?: string[];

  // ── governance toggles (default state matters!) ─────────
  order_type: string[];                 // default ['SO']
  include_excluded: boolean;            // default false
  include_backfilled: boolean;          // default false  ← durations only
  include_zero_value_lines: boolean;    // default false
  include_internal_accounts: boolean;   // default false

  // ── thresholds ──────────────────────────────────────────
  min_revenue_at_risk?: number;
  min_days_open?: number;

  // ── output ──────────────────────────────────────────────
  group_by?: 'none'|'reason_family'|'reason_code'|'channel'|'main_account'|'item'|'warehouse';
  sort: string;                          // default '-revenue_at_risk'
  page: number;
  per_page: number;                      // max 200
}
```

### 9.1 Non-negotiable UI rules for the filter bar

1. **The four governance toggles must be visible, not buried in an "advanced" drawer.** Their defaults change the headline number by double digits. A user who does not know `include_backfilled` is off will misread every duration.
2. **Active filters render as removable chips** under the bar, including defaults that are doing work (`SO only`, `Excluding backfilled`).
3. **The resolved filter payload is echoed into the Excel export header sheet.** Any file circulating in a WhatsApp group must state the filters that produced it, or the numbers get quoted out of context.
4. **The period label uses the shared date formatter** — the same `20 – 25 Jul 2026` string appears in the filter bar, the KPI subtitle, the page title and the Excel. One function, one output.
5. `date_field` defaults to `opened_at`. Be explicit in the UI: *"Backorders that STARTED in this period"* vs *"…that were RESOLVED in this period"*. These produce wildly different cohorts and it is the most common misread of this kind of report.

---

## 10. Display formatters — shared between Laravel and React

Both languages need identical output. Define once in PHP for the Excel, mirror in TS for the UI, and cover both with the same test fixtures.

### 10.1 Duration → `"5 Days Delay"`

```php
final class DurationFormatter
{
    public static function label(?int $days, bool $isBackfilled = false): string
    {
        if ($days === null)  return '—';
        if ($days === 0)     return 'Same Day';

        $label = $days === 1 ? '1 Day Delay' : "{$days} Days Delay";

        return $isBackfilled ? $label.' (min.)' : $label;
    }
}
```

| Input | Output |
|---|---|
| 0 | `Same Day` |
| 1 | `1 Day Delay` |
| 5 | `5 Days Delay` |
| 13, backfilled | `13 Days Delay (min.)` |
| null | `—` |

The `(min.)` suffix is how a backfilled estimate stays honest wherever it surfaces.

### 10.2 Date range → `"20 – 25 Jul 2026"`

```php
final class DateRangeFormatter
{
    public static function label(?CarbonInterface $from, ?CarbonInterface $to): string
    {
        if (! $from)                      return '—';
        if (! $to)                        return $from->format('j M Y').' – ongoing';
        if ($from->isSameDay($to))        return $from->format('j M Y');

        // same month & year → 20 – 25 Jul 2026
        if ($from->isSameMonth($to) && $from->isSameYear($to)) {
            return $from->format('j').' – '.$to->format('j M Y');
        }

        // same year, different month → 28 Jun – 3 Jul 2026
        if ($from->isSameYear($to)) {
            return $from->format('j M').' – '.$to->format('j M Y');
        }

        return $from->format('j M Y').' – '.$to->format('j M Y');
    }
}
```

| From | To | Output |
|---|---|---|
| 2026-07-20 | 2026-07-25 | `20 – 25 Jul 2026` |
| 2026-07-20 | 2026-07-20 | `20 Jul 2026` |
| 2026-06-28 | 2026-07-03 | `28 Jun – 3 Jul 2026` |
| 2026-12-28 | 2027-01-04 | `28 Dec 2026 – 4 Jan 2027` |
| 2026-07-20 | null (open) | `20 Jul 2026 – ongoing` |

Use an en-dash (`–`), not a hyphen. It survives copy-paste into Excel and reads correctly in the deck.

### 10.3 TypeScript mirror

```ts
export const durationLabel = (days: number | null, isBackfilled = false): string => {
  if (days === null) return '—';
  if (days === 0) return 'Same Day';
  const base = days === 1 ? '1 Day Delay' : `${days} Days Delay`;
  return isBackfilled ? `${base} (min.)` : base;
};

export const dateRangeLabel = (from?: string, to?: string): string => {
  if (!from) return '—';
  const f = new Date(from);
  if (!to) return `${fmt(f, 'd MMM yyyy')} – ongoing`;
  const t = new Date(to);
  if (isSameDay(f, t)) return fmt(f, 'd MMM yyyy');
  if (isSameMonth(f, t) && isSameYear(f, t))
    return `${fmt(f, 'd')} – ${fmt(t, 'd MMM yyyy')}`;
  if (isSameYear(f, t)) return `${fmt(f, 'd MMM')} – ${fmt(t, 'd MMM yyyy')}`;
  return `${fmt(f, 'd MMM yyyy')} – ${fmt(t, 'd MMM yyyy')}`;
};
```

Ship one shared fixture file (`duration_labels.json`, `date_ranges.json`) asserted by both PHPUnit and Vitest. When they drift, CI fails — which is the only reliable way to keep them aligned.

---

## 11. React implementation

### 11.1 Component tree

```
<BackorderPage>
  <FilterBar>                        ← sticky top; owns URL query state
    <DatePresetSelect/> <DateFieldToggle/> <DateRangePicker/>
    <StateSegmented/>                  Open | Resolved | All
    <MultiSelect name="reason_family"/> <MultiSelect name="channel"/>
    <MultiSelect name="warehouse_id"/> <MultiSelect name="main_account_id"/>
    <GovernanceToggles/>               ← 4 switches, always visible
    <DurationBasisToggle/>             Working days | Calendar days
    <ActiveFilterChips/>
    <ExportButton/>
  </FilterBar>

  <DataQualityStrip/>                ← reason coverage · backfilled % · reconciled ✓ · last sync
  <KpiGrid/>                         ← 8 cards, each with delta vs prior period
  <Tabs>
    <Tab id="overview">   <LossByFamilyChart/> <AgeingBuckets/> <TrendChart/>
    <Tab id="episodes">   <EpisodeTable/> → <EpisodeDrawer><EventTimeline/></EpisodeDrawer>
    <Tab id="items">      <ItemTable/>     ranked by revenue_days_delayed
    <Tab id="accounts">   <AccountTable/>  parent/branch tree via AcumaticaCustomer
    <Tab id="reconcile">  <ReconciliationBridge/>
  </Tabs>
</BackorderPage>
```

### 11.2 State management — filters live in the URL

```tsx
const [filters, setFilters] = useBackorderFilters();   // syncs to ?query params

const { data: summary } = useQuery({
  queryKey: ['backorders', 'summary', filters],
  queryFn: () => api.post('/backorders/summary', filters),
  staleTime: 60_000,
  placeholderData: keepPreviousData,   // no flicker on filter change
});
```

URL-encoded filters mean a link pasted into Slack reproduces exactly what the sender saw. Without this, every disagreement becomes *"it doesn't show that on mine."*

### 11.3 Excel export must reuse the same payload

```tsx
const exportExcel = async () => {
  const { data } = await api.post('/backorders/export', {
    ...filters,                      // byte-identical to what the grid used
    format: 'xlsx',
  });
  pollJob(data.job_id);              // queued; email + in-app download when ready
};
```

`ExportBackorderReport` job calls **the same `BackorderQueryBuilder`** the API uses. No second query, no re-derived totals. If the Excel and the dashboard can disagree, they eventually will.

### 11.4 The KPI card, done properly

```tsx
<KpiCard
  label="Avg Time to Resolve"
  value={durationLabel(summary.kpis.avg_days_to_resolve.value)}   // "5 Days Delay"
  sublabel={`${summary.period.label} · ${basisLabel(filters.duration_basis)}`}
  footnote={
    `Based on ${k.sample_size} of ${k.sample_size + k.excluded_backfilled} episodes ` +
    `with verified start dates (${pct(k.confidence)}). ` +
    `${k.excluded_backfilled} backfilled episodes excluded — their durations are minimums.`
  }
  delta={k.delta_pct}
  deltaGood="down"
/>
```

Every duration and every loss figure carries its sample size and confidence. This is the difference between a report that survives scrutiny and one that gets quietly abandoned after two meetings.
---

## 12. Excel export — layout spec

The export must be recognisable to anyone who uses the Acumatica screen, and must tie to the dashboard exactly. Eight sheets, generated by `ExportBackorderReport` via `BackorderQueryBuilder`.

| # | Sheet | Contents |
|---|---|---|
| 1 | **Filters & Definitions** | The resolved filter payload in plain English, generated-at timestamp, generated-by user, data-quality strip, and the §2 formula definitions verbatim. Non-negotiable — this is what stops the file being misquoted. |
| 2 | **Summary** | The 8 KPI values, with sample size and confidence beside each duration. Formulas referencing sheet 4, not hardcoded values. |
| 3 | **Loss by Reason** | Family → code hierarchy. Columns: Episodes, Peak Qty, Revenue at Risk, Lost Revenue, Delayed Revenue, Revenue-Days Delayed, Avg Days Delay, Reason Coverage %. |
| 4 | **Episode Detail** | One row per episode — the workhorse sheet. Column list below. |
| 5 | **Event Log** | The full append-only ledger for the filtered episodes. One row per event. This is the audit trail in flat form. |
| 6 | **By Item** | SKU ranking by Revenue-Days Delayed, with peak shortfall and repeat-episode count. |
| 7 | **By Account** | Main account → branch tree (via `AcumaticaCustomer.parent_acumatica_id`), matching the parent/branch rollup on the dashboard. |
| 8 | **Acumatica Bridge** | The §7.2 reconciliation, with the variance lines itemised and the pass/fail gate. |

### 12.1 Sheet 4 — `Episode Detail` column order

Columns 1–12 deliberately mirror the Acumatica export so a user can put the two side by side.

| Col | Header | Source / formula |
|---|---|---|
| A | Order Nbr. | `order_nbr` |
| B | Order Type | `order_type` |
| C | Order Date | `order_date` |
| D | Customer | `customer_name` |
| E | Main Account | `main_account_name` |
| F | Channel | `channel` |
| G | Warehouse | `warehouse_id` |
| H | Inventory ID | `inventory_id` |
| I | Item Description | `item_description` |
| J | UOM | `uom` |
| K | Order Qty | `order_qty` |
| L | Unit Price (net) | `net_unit_price` |
| M | **Backordered On** | `opened_at` — *date* |
| N | **Resolved On** | `resolved_at` — *date*, blank if open |
| O | **Window** | `=IF(N{r}="",TEXT(M{r},"d mmm yyyy")&" – ongoing", …)` → `20 – 25 Jul 2026` |
| P | Days (calendar) | `days_to_resolve` / `days_open` |
| Q | Days (working) | `working_days_to_resolve` / `working_days_open` |
| R | **Delay Label** | `=IF(Q{r}=0,"Same Day",Q{r}&IF(Q{r}=1," Day Delay"," Days Delay"))` → `5 Days Delay` |
| S | Start Date Verified? | `NOT opened_at_is_backfilled` → `Yes` / `No (estimated)` |
| T | Episode No. | `episode_no` |
| U | Peak Backorder Qty | `peak_backorder_qty` |
| V | Current Backorder Qty | `backorder_qty` |
| W | Shipped Qty | `shipped_qty` |
| X | Shipped Late Qty | `shipped_late_qty` |
| Y | Cancelled Qty | `cancelled_qty` |
| Z | Partial Fills | `partial_fill_count` |
| AA | Fill Rate % | `=IFERROR(W{r}/(K{r}-Y{r}),"")` |
| AB | Revenue at Risk | `=V{r}*L{r}` |
| AC | Delayed Revenue | `=X{r}*L{r}` |
| AD | Revenue-Days Delayed | `=AC{r}*MAX(0,Q{r}-promised_lead_time)` |
| AE | Lost Revenue | `lost_revenue` |
| AF | Resolution Type | `resolution_type` |
| AG | Reason Code | `reason_code` |
| AH | Reason Family | `reason_family` |
| AI | Reason Notes | `reason_notes` |
| AJ | Reason Set By | `reason_updated_by` |
| AK | Reason Set At | `reason_updated_at` |
| AL | Excluded from KPI? | `is_excluded_from_kpi` |
| AM | Exclusion Reason | `exclusion_reason` |
| AN | Sync Run (opened) | `opened_sync_run_id` |
| AO | Sync Run (resolved) | `resolved_sync_run_id` |

**Rules:**
- Columns O, P, Q, R, AA, AB, AC, AD are **live Excel formulas**, not baked values. Supply Chain will re-slice this file with a pivot; hardcoded results break the moment they filter.
- Conditional formatting: column Q amber above the channel threshold, red above 2×. Column S red where `No (estimated)`. Column AL grey-fills the whole row.
- Freeze at `A2`, auto-filter across the header row, `KES #,##0;(#,##0);-` on all value columns, Arial 10.
- Columns AN/AO are the join back to `AcumaticaSyncLog`. Keep them — they are how you debug a disputed row six weeks later.

### 12.1.1 Three helper columns — AP, AQ, AR

Add these after AO. They exist because two things break silently in Excel:

| Col | Header | Formula | Why it is needed |
|---|---|---|---|
| AP | State | `=IF(ISBLANK(N{r}),"Open","Resolved")` | `COUNTIFS(range,"")` on a **date** column does not reliably match blank cells — it under-counts, and it does so without erroring. Every downstream `SUMIFS`/`COUNTIFS` must test `AP="Open"`, never `N=""`. |
| AQ | Order 1st open row | `=IF(AND($AP{r}="Open",$AL{r}="No",COUNTIFS($A$5:$A{r},$A{r},$AP$5:$AP{r},"Open",$AL$5:$AL{r},"No")=1),1,0)` | Distinct order count. `SUM(AQ)` = open orders. The `SUMPRODUCT(cond/COUNTIF(...))` idiom returns fractions when the condition and the divisor disagree — it silently produced `7.5 distinct SKUs` in testing. |
| AR | Item 1st open row | Same as AQ, keyed on column H | Distinct SKU count via `SUM(AR)`. |

Both defects produce plausible-looking wrong numbers rather than errors, which is precisely why they are dangerous. Whoever writes the export must reproduce these three columns exactly, and the acceptance test in §13 must assert `SUM(AQ)` against a known distinct-order count.

### 12.2 Sheet 5 — `Event Log`

`episode_id`, `order_nbr`, `inventory_id`, `occurred_at`, `event_type`, `event_label`, `backorder_qty_before`, `backorder_qty_after`, `delta_qty`, `delta_value`, `order_status_at_event`, `reason_code_at_event`, `actor`, `sync_run_id`.

Sorted by `episode_id`, then `occurred_at` ascending. No formulas — this sheet is evidence and must be immutable.

---

## 13. Acceptance criteria — definition of done

The build is not done until each of these passes.

### Correctness
- [ ] `backorder_qty` computed in exactly one place; a unit test asserts the §2.1 ladder against 10 fixture lines including negative-availability and over-shipped cases.
- [ ] `COUNT(DISTINCT order_nbr)` on the reference extract period returns **74**, not 84.
- [ ] The Excel export's `SUM(AQ)` equals the API's `open_orders` value, and `SUM(AR)` equals `open_items`. No fractional results.
- [ ] An episode that resolves, re-opens and resolves again produces **2** episodes with `episode_no` 1 and 2, **6** events, and two independent durations.
- [ ] A line that is picked but not shipped stays `open` and produces a `picked` event, never `resolved`.
- [ ] Working-day maths excludes Sundays and the seeded Kenyan holiday calendar; a Fri→Mon episode returns 1 working day, 3 calendar days.
- [ ] All 33 reason codes resolve to a family; a new unmapped code falls to `UNCLASSIFIED` and raises a log warning rather than throwing.

### Consistency
- [ ] `/summary`, `/episodes` and the Excel export, called with identical filters, return identical totals. Automated test compares all three.
- [ ] `durationLabel` and `DurationFormatter::label` agree on the shared fixture file, asserted in both PHPUnit and Vitest.
- [ ] `dateRangeLabel` and `DateRangeFormatter::label` likewise, including the year-boundary case.
- [ ] The period label string is identical in the filter bar, KPI subtitle, page title and Excel sheet 1.

### Trust
- [ ] Every duration KPI exposes `sample_size`, `excluded_backfilled` and `confidence`.
- [ ] Backfilled episodes are excluded from duration averages by default and labelled `(min.)` wherever shown.
- [ ] The reconciliation bridge closes to ≤ 0.5% or the dashboard shows a warning banner.
- [ ] `backorder_line_events` has no update or delete path in application code. A test asserts the model throws on `save()` of an existing record.
- [ ] Reason coverage % renders adjacent to every attributed loss figure.

### Performance
- [ ] `/summary` responds in < 800 ms at 250k episodes / 2m events. Pre-aggregate into `backorder_daily_snapshots` if not.
- [ ] `DetectBackorderEventsJob` completes in < 3 min for the full open-order book.
- [ ] Excel export of 50k episodes completes in < 90 s on the queue.

---

## 14. Rollout — 5 sprints

| Sprint | Deliverable | Exit test |
|---|---|---|
| **1** | `reason_code_families` seeded; `backorder_episodes` + `backorder_line_events` migrations; `DetectBackorderEventsJob` writing on every sync | Events appear for live shortages; a manual Acumatica shipment produces a `resolved` event within one sync cycle |
| **2** | Backfill of existing open lines; working-day service; ageing refresh job; daily snapshot job | Snapshot for yesterday reproduces yesterday's Acumatica extract within 0.5% |
| **3** | API endpoints + `BackorderQueryBuilder` + shared formatters + fixtures | Cross-language formatter tests green; `/summary` matches a hand-built spreadsheet on one week of data |
| **4** | React dashboard: filter bar, KPI grid, episode table, event timeline drawer | Supply Chain manager reproduces a known Khetia case end-to-end unaided |
| **5** | Excel export, reconciliation bridge tab, admin UI for families and thresholds | Excel totals equal dashboard totals on 5 random filter combinations; bridge closes |

**Do not build sprint 4 before sprint 2.** A dashboard on top of an unvalidated ledger will be shown to the board, questioned, and lose the project its credibility. The snapshot-reproduces-Acumatica test in sprint 2 is the gate that protects everything after it.

---

## 15. Rundown for the team — the ten things everyone must agree on

Read this section in the kick-off. If there is disagreement, resolve it here, not in code.

1. **Acumatica cannot answer the question we are asking.** Its `Back Order` status is live. We are not "fixing a report" — we are building the history layer Acumatica never kept. Expect our numbers to differ from the screen, and expect to explain the difference every time.

2. **The unit of measurement is the episode, not the order.** One order can have eight short items with eight different causes and eight different durations. Reporting at order level averages away the thing we need to act on. Order-level views are rollups of episodes, never the base grain.

3. **`backorder_qty`, not `open_qty`.** `open_qty` includes goods already picked and sitting on a truck. Using it inflates the shortage. One function computes it, everything calls that function.

4. **Three loss numbers, never one.** Revenue at Risk (recoverable) · Delayed Revenue and Revenue-Days Delayed (service cost, sale happened) · Lost Revenue (gone). Adding them double-counts. The board slide shows all three, labelled.

5. **"Loss of sales due to delayed production" = Lost Revenue filtered to `reason_family = 'PRODUCTION'`**, with Revenue-Days Delayed shown beside it. Both are footnoted with reason coverage %.

6. **Working days by default, six-day week, Sundays and gazetted holidays excluded.** Every duration on screen states its basis. A "5 Days Delay" that silently mixes bases is worse than no number.

7. **Backfilled start dates are minimums, not measurements.** They are excluded from averages by default and labelled `(min.)` wherever they appear. This is the first thing a sceptical reviewer will probe.

8. **Reason coverage gates every attribution.** If only 64% of episodes carry a reason, then every family split is a 64% sample and must say so. Chasing coverage above 85% is a process job for Customer Service, not an engineering one — but the dashboard must make the gap impossible to ignore.

9. **Credit blocks are not stock problems.** In the 23 July extract, 8 of 10 rejected orders were `Account is in arrears` — KES 1.08m that never reached the warehouse. Rejected, On Hold and Pending Approval headers are excluded from every fill-rate and shortage KPI. Conflating them makes Supply Chain accountable for Finance's decisions.

10. **The overselling finding is the real prize.** Screenshot evidence from SO367051: item `SIFTP0015` showed **On Hand 100 CASE but Available −87 CASE**. Nothing was missing from the warehouse — those cases were already promised elsewhere. If that pattern repeats across the top short SKUs, the fix is an available-to-promise check at order entry, not more inventory. Build a report that counts episodes where `on_hand > 0 AND available < 0` at the moment of opening; that number is the business case for this whole project.

---

## 16. Open decisions — needs a named owner before sprint 1

| # | Decision | Recommendation | Owner |
|---|---|---|---|
| 1 | Sync frequency, which caps timing precision | 15 min in business hours, hourly overnight → durations accurate to ±15 min | Dev lead |
| 2 | `promised_lead_time_days` per channel | Take from the customer's agreed SLA where one exists; otherwise 2 days MT, 3 days GT | Commercial |
| 3 | `write_off_threshold_days` per channel | Table in §4.1 as the starting point, reviewed quarterly | Commercial + Supply Chain |
| 4 | Does `wrong_moq` belong to PRODUCTION or COMMERCIAL? | PRODUCTION (planning constraint), but keep it table-driven | Supply Chain |
| 5 | Should `PP` order types appear in the dashboard? | No by default, toggle available — `IN_SCOPE_ORDER_TYPES` already says SO only | Product |
| 6 | Do we need item-level inventory availability captured at episode open? | **Yes** — without `on_hand` and `available` at open, point 10 above is unprovable. Add `AcumaticaInventoryAvailability` sync. | Product + Dev |
| 7 | Who owns reason coverage? | Customer Service, with a daily work queue of unreasoned episodes above a value threshold | Ops |
| 8 | Retention on `backorder_line_events` | 36 months hot, then archive to cold storage. Never delete. | Dev lead |

---

## 17. Appendix — reference figures from the 23 Jul 2026 extract

Use these to validate the first build. They come from the Acumatica export used to scope this work.

| Figure | Value |
|---|---|
| Extract | Back Orders, Order Type SO, created 01–23 Jul 2026, snapshot 23/07 17:41 EAT |
| Raw export rows | 84 |
| Distinct orders | **74** |
| Total line value | KES 8,436,411 |
| Unbilled (open) value | KES 3,861,891 |
| Value fill rate | 54.2% |
| Orders with zero shipments raised | 15 (KES 2,267,948 open) |
| Orders older than 7 days | 41 (KES 1,281,681 open) |
| Largest single exposure | SO367174 Goodlife Pharmacy — KES 1,892,526, 0% shipped, same-day |
| Largest structural block | 10 Jul MT drop — 32 orders (Khetia 21 branches, Maguna's 10, Karen Provision 1), KES 2,875,397 ordered, KES 1,196,457 open, ~59% filled after **13 Days Delay** |
| Hard stockout cluster | Majid Al Futtaim (5 branches) + Quick Mart (5 branches) — 10 orders, KES 273,214 open, **zero** shipments raised |
| Rejected same-day (credit, not stock) | 10 orders, KES 1,076,505 — 8 of them `Account is in arrears` |
| Overselling evidence | `SIFTP0015` — On Hand 100 CASE, Available **−87** CASE, Available for Shipping 0.00 |

**First validation test:** run the new pipeline against 23 Jul 2026 and confirm the daily snapshot reproduces 74 distinct orders and KES 3,861,891 unbilled through the bridge in §7.2. If it does, the ledger is trustworthy and everything downstream can be built with confidence.
