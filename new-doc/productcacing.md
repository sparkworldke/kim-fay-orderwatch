# Basic Information Caching Plan

**Product:** Kim-Fay Sight / OrderWatch  
**Goal:** Cache slow-changing reference data so filters, sidebars, and dashboards load fast without re-querying the DB every navigation.  
**Status:** Spec — implement in phases  
**Related:** `DomainCache` + `CacheDomainResponse` (`response.cache:{domain},{ttl}`)

---

## Problem

Many screens re-fetch **basic** data on every visit:

- Brands, segments (KP / MT / GT / CS), warehouses  
- Product / inventory names and IDs  
- Users and consultants  
- Filter option lists  

These rarely change mid-day, but they still hit MySQL and block UI. Heavy order/KPI queries should stay separate; this plan is for **reference + closed-period** data.

---

## What already exists

| Mechanism | Role |
|---|---|
| **Redis** (`CACHE_STORE` / `DASHBOARD_CACHE_STORE`) | Primary response cache |
| **DB fallback** | If Redis is down |
| **`DomainCache`** | Generation counters; `bump('references')` invalidates a whole domain |
| **`response.cache:domain,ttl`** | Caches full GET JSON per user + path + query |
| **React Query** | Client `staleTime` default **60s** |

**Already long-cached (good):**

| Endpoint | Domain | TTL |
|---|---|---|
| `operations/brand-filter-options` | `references` | 3600s (1h) |
| `operations/reason-taxonomy` | `references` | 3600s |
| `orders/filter-options` | `orders` | 300s |
| Many ops summaries | inventory / backorders / fill-rate | 60–120s |

**Not cached today (P0 gaps):**

| Endpoint | Why it hurts |
|---|---|
| `GET dashboard/filter-options` | Brands, segments, consultants on every Dashboard load |
| `GET auth/capabilities` | Menu + permissions every navigation |
| `GET auth/me` | Session user payload |
| Production `reference` / static brand lists | Partner/manufactured brand pickers |
| Shared product catalogue lookups | Name resolution by inventory ID |

---

## Data tiers

### Tier A — Almost static (cache hard)

| Data | Change trigger | Suggested TTL | Invalidation |
|---|---|---|---|
| Brands (partner + manufactured lists) | Inventory sync / brand seeder / admin brand edit | **6–24 h** | `DomainCache::REFERENCES` on inventory sync |
| Segments (KP, MT, GT, CS, …) | Config / channel rules | **24 h** | Channel rule save |
| Warehouses list | Rare ERP change | **6–24 h** | Inventory / warehouse sync |
| Reason taxonomy | Admin edit | **1–6 h** | Already on `references` |
| Product name + inventory ID map | New SKU or rename | **24 h** + weekly rebuild | Inventory sync + weekly cron |
| Unit price snapshot (optional) | Price change | **24 h** or weekly | PCR applied / inventory price sync |

### Tier B — Slow-changing people (cache medium)

| Data | Change trigger | Suggested TTL | Invalidation |
|---|---|---|---|
| Active consultants list | Hire / deactivate / rep code change | **15–60 min** | User save / staff import / portfolio import |
| User display names / rep map | Same | **15–60 min** | Same |
| Capabilities / menu flags | Role or assignment change | **5–15 min** | Role/assignment mutations |
| Portfolio filter options (scoped) | Assignment change | **5–15 min** | Portfolio attach |

### Tier C — Operational (cache soft — already partly done)

| Data | TTL | Notes |
|---|---|---|
| Live dashboard KPIs / trends | 60–120s | Per user + filters |
| Open SO lists | 60s | Status changes often |
| Backorders / fill-rate | 60–120s | Existing |

### Tier D — Closed calendar months (cache very hard)

| Rule | Action |
|---|---|
| Month **M** is finished | After **day 10 of month M+1**, treat M as **closed** |
| Expectation | Most orders for M delivered / stable |
| Cache | Pre-aggregate or store response snapshots for closed M (KPIs, brand totals, completion) with TTL **7–30 days** |
| Invalidate | Only admin force rebuild or rare late status correction job |

**Cron idea:** daily after 02:00 — if `today >= 11th`, ensure previous month snapshot exists; rebuild if missing.

---

## Display / load flow (frontend)

```text
App boot / login
    │
    ├─ Load Tier A (brands, segments, warehouses)  → long staleTime (1h+)
    ├─ Load capabilities + me                     → medium staleTime (5–15m)
    │
    └─ Page (Dashboard)
           ├─ filter-options from Tier A/B cache (instant if warm)
           ├─ KPIs / trend (Tier C — short cache)
           └─ Expand SO lines (lazy, short cache per SO id)
```

**React Query guidance:**

| Query | `staleTime` | `gcTime` |
|---|---|---|
| Brands / segments / warehouses | 1h–24h | 24h |
| Filter options | 30m–1h | 6h |
| Capabilities | 5–15m | 1h |
| Dashboard KPIs | 60–120s | 10m |
| Closed-month snapshots | 24h+ | 7d |

Use stable query keys: `['references','brands']`, `['dashboard','filter-options']`, etc. Prefetch filter-options on layout mount.

---

## Backend plan

### 1. Shared reference service (recommended)

```text
ReferenceDataService
  brands(): list
  segments(): list
  warehouses(): list
  productIndex(): map inventory_id → { name, brand, ownership }
  consultantsActive(): list  (optional scope later)
```

- Wrap each in `Cache::remember` **or** rely on `response.cache:references,{ttl}` on thin controllers.  
- Prefer **one** `/api/references/bootstrap` payload for app shell (brands + segments + warehouses) to cut round-trips.

### 2. Wire middleware (quick wins)

| Route | Middleware |
|---|---|
| `dashboard/filter-options` | `response.cache:references,1800` (30m) or `3600` |
| `auth/capabilities` | `response.cache:references,600` (10m) **or** dedicated `capabilities` domain |
| `auth/me` | Short cache **careful** with impersonation — key already includes user id; still bump on impersonate stop |
| Production reference | `response.cache:references,3600` |

### 3. Invalidation map

| Event | Bump domains |
|---|---|
| Inventory / brand sync completed | `references`, `inventory` |
| User / role / rep / portfolio import | `references`, `sales-portfolio`, `customer-analytics` |
| Channel classification rules | `references` |
| Sales order sync | `orders`, `fill-rate`, `backorders`, `sales-portfolio` — **not** brands list |
| Price change applied | product price keys / `references` if prices in bootstrap |
| Closed-month rebuild | dedicated `closed-period:{Y-m}` keys |

`CacheDomainResponse` already bumps domain on non-GET success for that route’s domain — **mutations on other routes must call `DomainCache::bump` explicitly.**

### 4. Product catalogue cache (weekly + on sync)

- Nightly or **weekly** job: rebuild `product_index:{generation}` hash/JSON.  
- Fields: `inventory_id`, `description`, `brand`, `ownership`, optional `unit_price`.  
- Use for filter labels, SO line accordion names, partner brand rollups.  
- Full rebuild after inventory sync if delta large; otherwise generation bump + lazy rebuild.

### 5. Closed month exercise (your “after 10 days” rule)

```text
Schedule daily:
  closedMonth = previous calendar month
  if day_of_month >= 11:
      ensureSnapshot(closedMonth)   // KPIs, brand totals, completion
      mark snapshot immutable unless force=true
```

Snapshot key example:  
`closed-period:v{gen}:{userOrScope}:{Y-m}:{filterHash}`

For admin/global dashboards, prefer **scope-level** snapshots (all KP / all partner brands) rather than per-user when data is not portfolio-scoped.

---

## Database: compress / poll faster (alongside cache)

Caching removes repeats; DB still must answer cold misses quickly.

| Action | Why |
|---|---|
| Indexes on `order_date`, `status`, `customer_acumatica_id`, `sales_consultant_rep_code` | Dashboard filters |
| Index `acumatica_sales_order_lines (sales_order_id, inventory_id)` | SO accordion lines |
| Index `acumatica_inventory_items (brand, inventory_id)` | Brand filter exists |
| Avoid `SELECT *` on filter-options | Only id/name/code columns |
| Materialized monthly summary table (optional) | Closed-month snapshots without re-scan |
| Connection pooling / Redis on prod | Keep `dashboard_store=redis` |

Do **not** compress JSON in Redis unless payloads are huge; prefer smaller payloads and generation invalidation.

---

## Implementation phases

### P0 — Fast filters (1–2 days)

1. Add `response.cache:references,1800` to `dashboard/filter-options`.  
2. Cache `auth/capabilities` (10–15m) with bump on role/assignment changes.  
3. Frontend: raise `staleTime` for filter-options / brands / capabilities.  
4. Prefetch filter-options in app shell after login.

### P1 — Reference bootstrap

1. `GET /api/references/bootstrap` → brands, segments, warehouses.  
2. Single long TTL + `REFERENCES` bump on inventory sync.  
3. Product name map endpoint or include slim catalogue in bootstrap if size allows.

### P2 — Product weekly rebuild

1. Artisan `orderwatch:rebuild-product-cache`.  
2. Schedule weekly + call after inventory sync.  
3. SO line UIs read names from cache first.

### P3 — Closed month snapshots

1. Job after day 10 of month.  
2. Store prior-month dashboard aggregates.  
3. Dashboard date range entirely in closed months → serve snapshot first.

### P4 — Measure

- Log `X-Dashboard-Cache: HIT|MISS` rates.  
- Track p95 for filter-options and dashboard KPIs before/after.

---

## Acceptance criteria

- [ ] Dashboard filter dropdowns (brand, segment, consultant) load from cache on second visit within TTL (header `X-Dashboard-Cache: HIT` or React Query cache).  
- [ ] Changing brand list (inventory sync) invalidates brand options within one bump.  
- [ ] Capabilities refresh after role change without waiting full day.  
- [ ] Closed previous month (after day 10) does not re-aggregate from raw SOs on every page load.  
- [ ] Redis failure falls back to DB cache without breaking the app.  
- [ ] Impersonation / user switch never shows another user’s cached private payload (user id in cache key — already true for middleware).

---

## Out of scope (this plan)

- Caching POST bodies or exports  
- Replacing Acumatica as source of truth  
- Infinite client-only cache without server invalidation  
- Caching live open-order line detail longer than ~60–120s  

---

## Quick decision summary

| Cache aggressively | Cache lightly / not at all |
|---|---|
| Brands, segments, warehouses | Live open SO lines |
| Product names / IDs | In-flight backorder edits |
| Filter option lists | Active cron sync status |
| Closed months (after day 10) | Current month open KPIs (short TTL only) |
| Consultants / roles (medium TTL) | Auth tokens |

---

## Next step when ready to build

Start **P0**: middleware on `dashboard/filter-options` + longer React Query `staleTime` for reference queries. That alone removes a large share of “loading…” on Dashboard and Partner brand filters.
