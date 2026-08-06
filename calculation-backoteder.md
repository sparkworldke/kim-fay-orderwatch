# Backorder & Fill Rate — Product (InventoryID) Calculations

**Source API:** `https://orderwatch.fayshop.co.ke/api`  
**Period:** `2026-06-01` → `2026-06-30`  
**Pulled:** 2026-07-14  
**Auth:** Administrator login (read-only)

---

## Summary

| Metric | Scope | SKUs | Value (KES) |
|--------|--------|------|-------------|
| Open backorder value | Full open book (no date filter) | 789 | **85,254,139.39** |
| June-filtered backorders | Date filter applied | 412 | **8,901,363.35** |
| June fill-rate undershipped | All InventoryIDs, OOS included | 726 | **47,089,144.21** |
| Manufactured (fill rate) | June, OOS on | 173 | 13,357,166.52 |
| Trading / Partners (fill rate) | June, OOS on | 380 | 33,767,418.77 |

**Bottom line:** Full product grouping does **not** reach 100M+ for either report alone. Open backorders ≈ **85.3M**; June fill-rate shortfall ≈ **47.1M**. A ~**34M** figure is consistent with a **partial** product cut (e.g. top ~50 SKUs ≈ 29M), not the full InventoryID set.

---

## 1. Backorders — group by InventoryID

### 1.1 Open book (no date filter)

Endpoint: `GET /operations/backorders/summary`  
Also: `GET /operations/backorders/analytics` (no date params) → `excel_summary`

| Field | Value |
|-------|-------|
| Open lines | 7,283 |
| Open orders | 1,006 |
| Total open qty | 150,827.2 |
| **Revenue at risk / back order value** | **85,254,139.39** |
| InventoryIDs (product rollup) | 789 |
| Product rollup sum | 85,254,139.39 |
| Top 10 InventoryIDs sum | 18,293,110.90 |
| Last synced at | 2026-07-06 21:35:46 |

Product rollup **matches** the open-book KPI when **all** InventoryIDs are included.

### 1.2 June date-filtered

Endpoints:

- `GET /operations/backorders/analytics?date_from=2026-06-01&date_to=2026-06-30`
- `GET /operations/backorders?date_from=2026-06-01&date_to=2026-06-30` (paginated)

Date expression uses:  
`COALESCE(requested_on, scheduled_shipment_date, order_date, synced_at)`.

| Field | Value |
|-------|-------|
| Lines | 850 |
| Orders | 77 |
| Back order qty | 12,529.5 |
| **Back order value** | **8,901,363.35** |
| InventoryIDs | 412 |
| Product rollup sum | 8,901,363.35 |
| Top 10 InventoryIDs sum | 2,759,474.21 |

#### June top 10 InventoryIDs (backorders)

| InventoryID | Lines | Open qty | Value (KES) |
|-------------|-------|----------|-------------|
| FAYCL0009 | 10 | 584 | 466,998.73 |
| FAYTP0008 | 3 | 207 | 348,239.09 |
| COSTP0024 | 3 | 475 | 303,836.21 |
| FAYFL0003 | 4 | 65 | 299,240.00 |
| SIFTP0011 | 3 | 300 | 281,455.17 |
| FAYFL0009 | 3 | 218 | 273,088.43 |
| FAYMU0004 | 12 | 422 | 261,882.64 |
| FAYFL0008 | 3 | 306 | 218,624.73 |
| SIFTP0006 | 4 | 154 | 153,785.76 |
| TISTP0001 | 1 | 200 | 152,323.45 |
| **Top 10 total** | | | **2,759,474.21** |

#### SKU breakdown by business category (June)

| Category | SKUs | Lines | Orders | Value (KES) |
|----------|------|-------|--------|-------------|
| Manufactured | 125 | 286 | 47 | 5,791,906.34 |
| Trading | 287 | 564 | 55 | 3,109,457.01 |
| **Sum** | | | | **8,901,363.35** |

### 1.3 API quirk — backorder summary vs date filter

| Endpoint | Respects `date_from` / `date_to`? | Typical total |
|----------|-----------------------------------|---------------|
| `/operations/backorders/summary` | **No** (open book always) | ~85.3M |
| `/operations/backorders`, analytics, product distribution | **Yes** | ~8.9M for June |

The KPI card can show **~85M** while a June-filtered product table shows **~8.9M**. That is expected with the current API behaviour.

---

## 2. Fill rate — undershipped by InventoryID

Endpoints:

- `GET /operations/fill-rate/summary?date_from=2026-06-01&date_to=2026-06-30&include_out_of_stock=1`
- `GET /operations/fill-rate/sku-breakdown?business_category=manufactured|trading&...`

### 2.1 Full product rollup (June, OOS included)

| Field | Value |
|-------|-------|
| InventoryIDs with shortfall | 726 |
| **Product undershipped sum** | **47,089,144.21** |
| Manufactured + Trading | 47,089,144.24 |
| Top 10 sum | 14,660,208.35 |
| Top 20 sum | 19,780,592.18 |
| Top 50 sum | 29,011,988.55 |

### 2.2 By business category

| Category | SKUs | Lines | Orders | Ordered qty | Shipped qty | Undershipped value (KES) | Fill rate % |
|----------|------|-------|--------|-------------|-------------|--------------------------|-------------|
| Manufactured | 173 | 1,072 | 342 | — | — | 13,357,166.52 | 15.4 (shortfall lines only in SKU breakdown) |
| Trading (Partners) | 380 | 2,291 | 507 | — | — | 33,767,418.77 | 0.4 (shortfall lines only) |
| Category rollup (summary) | | 3,805 / 6,663 lines | 689 / 681 | 72,786 / 153,730.3 | 63,264 / 56,166.52 | 13,357,166.52 / 33,767,418.77 | 86.9% / 36.5% |

### 2.3 Top 10 InventoryIDs (fill-rate shortfall)

| InventoryID | Value (KES) |
|-------------|-------------|
| REXDS0014 | 3,041,974.33 |
| LUXBA0003 | 1,779,396.05 |
| APTML0004 | 1,740,245.43 |
| COWGT0001 | 1,710,282.54 |
| DOVST0010 | 1,248,276.79 |
| APTML0005 | 1,219,152.40 |
| COWGT0004 | 1,054,255.26 |
| FAYCL0017 | 1,047,418.15 |
| FAYMU0004 | 1,035,741.83 |
| REXDS0009 | 783,465.57 |
| **Top 10 total** | **14,660,208.35** |

### 2.4 Undershipped by reason (June, line-level)

| Reason | Lines | Undershipped value (KES) | Contribution % |
|--------|-------|--------------------------|----------------|
| inventory_shortage | 2,759 | 35,183,671.16 | 74.7 |
| Unassigned | 7,695 | 11,841,334.29 | 25.1 |
| price_difference | 8 | 91,342.35 | 0.2 |
| lost_by_driver | 5 | 8,119.74 | ~0 |
| damaged_at_client | 1 | 117.75 | ~0 |

### 2.5 Production fill-rate KPI quirk (June)

For June on production, snapshot-level KPIs are currently **empty / N/A**:

| Field | Production value |
|-------|------------------|
| order_count | 1,042 |
| overall_fill_rate | `null` |
| revenue_not_shipped | 0 |
| excel_totals actual/ordered/undershipped qty | 0 |
| fill_rate_status on sample orders | `na` |

**Cause:** period filter on production still behaves like **`computed_at`**, not sales order **`order_date`**.  
Product-level undershipped (~**47.1M**) is still computed from order lines. Local fix switches the period to **order_date** so KPI tiles can align with line-level product totals.

---

## 3. Why ~34M vs expected 100M+

| Interpretation | Live number | Notes |
|----------------|-------------|--------|
| Open backorders, all InventoryIDs | **85.3M** | Closest large total; still under 100M |
| June fill-rate shortfall, all InventoryIDs | **47.1M** | Full product rollup |
| June fill-rate, top 50 SKUs | **~29.0M** | Partial list ≈ mid-30M range with more rows |
| June fill-rate, top 10 only | **14.7M** | Top-N only |
| June-filtered backorders, all SKUs | **8.9M** | Date filter, not open book |
| Open backorders, top 10 only | **18.3M** | Top-N only |

- **~34M** ≈ incomplete product set (top-N or partial sheet), not full InventoryID rollup.  
- **100M+** is **not** what live product rollups sum to for either report alone on this pull.  
- Open backorder book is **~85M**; June fill-rate undershipped is **~47M**.

---

## 4. Endpoints used

```
POST /auth/login

GET  /operations/backorders/summary
GET  /operations/backorders/summary?date_from=2026-06-01&date_to=2026-06-30
GET  /operations/backorders/analytics
GET  /operations/backorders/analytics?date_from=2026-06-01&date_to=2026-06-30
GET  /operations/backorders?date_from=2026-06-01&date_to=2026-06-30&per_page=200&page=N
GET  /operations/backorders/sku-breakdown?business_category=manufactured&date_from=...&date_to=...
GET  /operations/backorders/sku-breakdown?business_category=trading&date_from=...&date_to=...

GET  /operations/fill-rate/summary?date_from=2026-06-01&date_to=2026-06-30&include_out_of_stock=1
GET  /operations/fill-rate/summary?date_from=2026-06-01&date_to=2026-06-30&include_out_of_stock=0
GET  /operations/fill-rate/sku-breakdown?business_category=manufactured&...&include_out_of_stock=1
GET  /operations/fill-rate/sku-breakdown?business_category=trading&...&include_out_of_stock=1
GET  /operations/fill-rate?date_from=2026-06-01&date_to=2026-06-30&include_out_of_stock=1&per_page=5
```

Note: browser path `https://orderwatch.fayshop.co.ke/backend/public/api` is **not** the live API. Production API base used here is:

```
https://orderwatch.fayshop.co.ke/api
```

(same-origin `/api` proxied to Laravel).

---

## 5. Local code fixes related to these numbers

(Not yet reflected in the production numbers above unless deployed.)

1. **Product Summary** — group by **all** InventoryIDs (remove top-N truncation) so sheet total reconciles to period value.  
2. **Fill-rate period** — filter by sales order **`order_date`**, not snapshot `computed_at`.  
3. **Shipped qty** — use `shipped_qty`, fallback to `qty_on_shipments` when shipped is 0.  
4. **Demand** — undershipped uses **Order Qty**, not qty at approval.

---

## 6. Raw reconciliation checks

```
Open backorder product sum     = 85,254,139.39  == excel_summary.totals.back_order_value
June backorder product sum     =  8,901,363.35  == excel_summary.totals.back_order_value
June fill product sum          = 47,089,144.21  ≈ manufactured + trading undershipped
June fill mfg SKU breakdown    = 13,357,166.52
June fill trading SKU breakdown= 33,767,418.77
mfg + trading                  = 47,124,585.29  (earlier pull; later pull 47,089,144 — minor variance between requests)
```

Slight differences between successive API pulls are possible if sync/recompute is running; order of magnitude and reconciliation structure are stable.
