# PRD: Sales Consultant Dashboard & Shared Accounts Hub

**Status:** Draft v0.1  
**Date:** 2026-07-23  
**Product:** Kim-Fay Sight (OrderWatch)  
**Primary consumers:** Sales Consultants, HODs / managers of consultants, Sales Operations, KP leadership  
**Related surfaces today:** `/app` (operations dashboard), `/app/kp/accounts`, `/app/kp/dormant`, `/app/kp/items-not-ordered`, `/app/backorders`, `/app/fill-rate`, commissions targets, org-scope / rep-code portfolio  

**Phase:** **P1 — Consultant-informative Accounts dashboard** (this document). Later phases can deepen HOD rollups, mobile polish, and AI prompts.

---

## 1. Executive summary

Sales consultants need a **single home** that answers: *Who is in my book? Who is buying? Who is silent? How am I tracking target? What orders and shortfalls sit with my customers? What is likely to order next?*

Today those answers are **fragmented**:

| Need | Exists today? | Gap |
|---|---|---|
| Portfolio accounts list | ✅ KP Accounts (`/app/kp/accounts`) scoped by org / assignment | No KPI strip; not the main “dashboard” experience |
| Active vs dormant (3 months) | ✅ Dormant page; separate from Accounts | Not on consultant home; definitions not re-used as cards |
| Orders / backorders / fill rate | ✅ Global ops pages | Not “my portfolio only” as first-class consultant tabs |
| Target vs time | ⚠️ Commission targets exist | Not shown on Accounts / consultant dashboard |
| Predicted orders / closures | ❌ Partial run-rate elsewhere | No portfolio-level prediction UI |
| Items not ordered by customer accordion | ⚠️ Flat table on KP page | Not grouped accordion; value rules not as specified |
| Who's my best/biggest customer, segment, product | ❌ Not surfaced anywhere as a portfolio view | No "top movers" concept exists at all |
| Am I gaining or losing customers | ❌ Not surfaced | No new-vs-reactivated distinction anywhere |
| Is my situation improving or worsening | ❌ Every existing number is a point-in-time snapshot | No trend/history view for any portfolio KPI |
| Early warning before an account goes dormant | ❌ Dormant is binary and lagging | No "declining but not yet dormant" signal |

This PRD defines a **Sales Consultant Dashboard** built around a shared **Accounts** experience: portfolio-scoped KPIs + tabs for My Orders, My Backorders, My Fill Rate, My Predicted Orders, Items Not Ordered, and Trends — plus a "top movers" row (top client, top segment, top product, new/reactivated/declining customers, concentration) — with explicit formulas, tooltips, org-scope guardrails, and usability requirements so **any** consultant (or HOD viewing their team) can use it without training docs, and can tell not just *where they stand* but *which way things are moving*.

---

## 2. Goals

1. **One portfolio truth** — Every KPI and tab uses the same customer set: customers attached via **rep-code portfolio** and/or **explicit customer assignment**, including customers visible when the viewer is an **HOD/manager of consultants** who own those portfolios.  
2. **Informative at a glance** — Top strip shows counts and money metrics with plain-language tooltips and cut-off dates.  
3. **Actionable drill-downs** — Tabs list the consultant’s orders, backorders, fill-rate performance, predicted demand, and items not ordered **grouped by customer**.  
4. **Safe for all roles** — No leakage across portfolios; empty states explain *why* (no rep code, no assignments, no data yet).  
5. **Honest numbers** — Revenue, fill rate, and predictions use documented formulas; never invent VAT, live Acumatica, or “guaranteed loss” language.
6. **Decisive, not just informative** — every point-in-time number is paired with a "which way is this moving" signal (trend sparkline, top movers, new-vs-reactivated, early-warning decline) so a consultant can prioritize today's calls instead of just admiring a snapshot.

### Non-goals (P1)

- Replacing the executive operations dashboard for org-wide roles (executives keep `/app` ops view).  
- Editing commission targets from this screen (link out to Commissions if needed).  
- Acumatica `InItemPlan` allocation joins or live per-request inventory.  
- Calltronix / DTC-specific workflows (remain under DTC module).  
- Auto-creating sales orders from predictions.

---

## 3. Personas & access

| Persona | Access pattern |
|---|---|
| **Sales Consultant** | Sees only own portfolio (`rep_code` + `user_customer_assignments` + SO-derived book as already modelled). |
| **HOD / manager** | Sees union of portfolios of **direct (and configured) reports** whose manager relationship is modelled in org tree / department roles—same scope rules as `OrgScopeService` today. |
| **Sales Operations / Admin** | Org-wide or impersonation; optional “view as consultant” later (P2). |
| **Non-KP roles** | If they lack KP CRM permission, Accounts hub remains hidden; if granted portfolio scope, same component works for any customer-facing portfolio (not only `KP%` class)—**config flag** `portfolio_customer_class_filter`. |

**Permission gates (recommended):**

| Capability | Permission (reuse / add) |
|---|---|
| View Accounts hub + KPI strip | Existing `kp.fol.view` **or** new `sales.portfolio.dashboard.view` |
| View dormant actions / handoff | Existing dormant permissions |
| View fill-rate / backorder detail | Existing ops permissions, still portfolio-scoped |
| Configure targets | Existing commission / admin |

---

## 4. Portfolio definition (canonical)

### 4.1 Customer membership (“attached to me”)

A customer **C** is in user **U**’s portfolio if **any** of the following holds (OR):

1. **Explicit assignment:** row in `user_customer_assignments` (or equivalent) for `U`.  
2. **Rep-code book:**  
   - Prefer **master assignment** from Acumatica / assignment service (customer’s salesperson / rep).  
   - Fallback already in code: customers with SO where `sales_consultant_rep_code = U.rep_code`.  
3. **Manager rollup:** for HODs, **C** is in portfolio of any user in `OrgScopeService::effectiveScopeUserIds(U)` (manager subtree).

**Shared Accounts tab:** All consultants who share a customer through assignments or the same rep-code see that customer. HODs see the **union** of their reports’ customers—not a separate “all KP” dump unless they have org-wide access.

### 4.2 Guardrails

| # | Guardrail |
|---|---|
| G1 | Never show another consultant’s customer without org-scope entitlement. |
| G2 | Missing `rep_code` + no assignments → empty portfolio + banner: “Ask Sales Ops to set your rep code or assign customers.” |
| G3 | Impersonation: dashboard metrics follow **impersonated** user, with impersonation banner visible. |
| G4 | All tab queries must call the **same** portfolio resolver (single service method)—no ad-hoc filters per tab. |
| G5 | Customer name/ID always use shared `CustomerLink` → `/app/customer-orders/{id}`. |

---

## 5. Information architecture

### 5.1 Entry points

| Route | Role |
|---|---|
| `/app/kp/accounts` | **Primary** Sales Consultant / HOD Accounts hub (this PRD expands it). |
| `/app` | Role-aware: for Sales Consultant, **redirect or embed** portfolio KPI strip + tab deep-links (P1.1 optional); executives keep ops dashboard. |
| Sidebar | “Accounts” remains under KP CRM; consider renaming to **“My Portfolio”** for consultants only (label A/B). |

### 5.2 Page layout

```
┌─────────────────────────────────────────────────────────────────┐
│ My Portfolio / Accounts                    [Month selector] [↻] │
│ Scope chip: My book | Team (HOD) | All (admin)                   │
├─────────────────────────────────────────────────────────────────┤
│ KPI CARDS (responsive grid, each with a 6-mo sparkline where noted)│
│ [Customers] [Active] [Dormant ℹ ~] [Revenue MTD ~] [Target]      │
│ [Target vs time ℹ] [My Orders] [My BO ~] [My Fill rate ~]        │
│ [Predicted orders / closures ℹ]                                  │
├─────────────────────────────────────────────────────────────────┤
│ TOP MOVERS (compact row)                                         │
│ [Top 5 clients + trend] [Top segment/brand] [Top product]        │
│ [New this month] [Reactivated] [Declining/at-risk] [Concentration]│
├─────────────────────────────────────────────────────────────────┤
│ TABS                                                             │
│ Overview | My Orders | My Backorders | My Fill rate |            │
│ Predicted | Items not ordered | Trends                           │
├─────────────────────────────────────────────────────────────────┤
│ Tab content (tables / accordion / trend charts)                  │
└─────────────────────────────────────────────────────────────────┘
```

**Overview tab:** compact account table (reuse `KpAccountsTable`) + last order date + active/dormant badge.

---

## 6. KPI strip — definitions

Timezone for all date math: **`Africa/Nairobi`**.

### 6.1 All customers attached

| Field | Definition |
|---|---|
| **Count** | Distinct portfolio customers (status Active unless admin toggles “include inactive”). |
| Tooltip | “Customers on your rep code and/or explicit assignments. HODs include team portfolios.” |

### 6.2 Actively purchasing

| Field | Definition |
|---|---|
| **Rule** | Has ≥1 sales order (`order_type = SO`, non-cancelled as per existing SO status policy) with `order_date` **on or after** the active cutoff. |
| **Active cutoff** | First day of the month that is **3 calendar months before** the start of the current month. Equivalent wording on UI: “Purchased within the last 3 full months + current month to date.” |
| **Aligned dormant rule** | Match existing dormant module: dormant = **no SO in the last 3 calendar months measured from the start of the current month** (see `/app/kp/dormant`). |
| **Count** | Portfolio customers who are **not** dormant under that definition and have ≥1 SO in the window. |
| Tooltip | Show **cutoff date** as `YYYY-MM-DD` (start of active window) and “As of first day of this month, looking back 3 months.” |

**Example (July 2026):**  
- Current month start = `2026-07-01`  
- Dormant if last SO **before** `2026-04-01` (or never ordered).  
- Active if last SO ≥ `2026-04-01`.

### 6.3 Dormant customers

| Field | Definition |
|---|---|
| **Count** | Portfolio customers with no SO in the window above (or never ordered). |
| Tooltip | “No sales order since {cutoff}. Cutoff is the first day of the month three months before this month’s start ({current_month_start}).” |
| Click-through | Opens Dormant tab filter or `/app/kp/dormant` pre-scoped. |

### 6.4 Revenue to date

| Field | Definition |
|---|---|
| **Default period** | **Month-to-date (MTD)** calendar month (Nairobi). Optional toggle: YTD. |
| **Formula** | Sum of portfolio SO line (or order) revenue using the **same base as commissions / SO totals** already used in Sight (document chosen field: e.g. `order_total` on SO header **or** sum of line `unit_price × shipped/ordered`—**pick one and stick**). |
| **Exclude** | Cancelled / rejected SOs per existing dashboard exclusion list; Goods-Lost-in-Transit special customers if already excluded org-wide. |
| Tooltip | “MTD revenue for orders from your portfolio customers. Estimate of billed/ordered value—not cash collected.” |
| Guardrail | Use `MaskedKES` / sensitivity rules if finance masking applies to role. |

### 6.5 Target

| Field | Definition |
|---|---|
| **Source** | `commission_targets` (or agreed target store) for `user_id` + `period_month = current month`. HOD: optional **sum of reports’ targets** with toggle “My target only | Team target”. |
| **Display** | Absolute KES. If missing: show “No target set” + link for managers with permission. |
| Tooltip | “Monthly sales target for {Month YYYY}.” |

### 6.6 Target vs month / time gone

| Field | Definition |
|---|---|
| **Time gone %** | `elapsed_working_days / total_working_days_in_month` **or** calendar days (document choice; **recommend working days Mon–Fri**, Nairobi holidays optional P2). |
| **Pace** | `expected_revenue_to_date = target × time_gone_pct`. |
| **Variance** | `revenue_mtd − expected_revenue_to_date` (positive = ahead). |
| **UI** | Progress bar: revenue vs target; secondary line “Pace: X% of month elapsed · expected KES … · variance …” |
| Tooltip | Explain working-day pace vs calendar if used. |

### 6.7 My Orders (KPI count)

| Field | Definition |
|---|---|
| **Count** | Distinct SO headers for portfolio customers in selected period (default MTD). |
| **Optional split** | Open / Shipping / Completed badges in tooltip or subtext. |

### 6.8 My Backorders (KPI)

| Field | Definition |
|---|---|
| **Count** | Active backorder **lines** (`shortfall_kind = active_backorder`) for portfolio customers—same `isBackorderLine()` / backorder table semantics as ops. |
| **Optional value** | Sum `revenue_at_risk` (open_qty × unit_price)—label “at risk”, not “lost”. |
| Tooltip | Point to definition in backorder-report. |

### 6.9 My Fill rate (KPI)

| Field | Definition |
|---|---|
| **Scope** | Portfolio customers, selected period (default MTD or last completed month—**default MTD with note if snapshots are nightly**). |
| **Formula** | Reuse existing fill-rate calculator (value-based or qty-based as production uses)—**do not invent a third formula**. |
| Display | Percentage + sample size (orders or lines). |
| Tooltip | “Portfolio fill rate for {period}. Snapshot as of {last_fill_rate_sync}.” |

### 6.10 My Predicted orders & closures

| Field | Definition |
|---|---|
| **Basis** | For each portfolio customer × recurring SKU (or order-level): average order interval and average order value over **trailing 3 months of SO history** + **current run-rate** (revenue in last 30 days annualized or MTD run-rate—**document one**). |
| **Predicted order count (MTD rest-of-month)** | Sum over customers of max(0, expected orders by month-end − orders already placed MTD), floored. |
| **Predicted closures / revenue** | Sum of (expected remaining orders × avg order value) using 3-month averages; show as **estimate**. |
| Tooltip (card + tab) | “Based on each customer’s order cycle and value over the last 3 months, plus current sales run-rate. Not a commitment. Cutoff history: {from} → {to}.” |
| Guardrail | Hide or grey-out customers with &lt; 2 historical orders (“insufficient history”). |

### 6.11 Top client(s)

| Field | Definition |
|---|---|
| **Metric** | Top 5 portfolio customers by revenue in the selected period (default MTD), each with a **trend arrow** vs the same-length prior period (e.g. MTD vs prior MTD). |
| **Formula** | Same revenue base as §6.4 (whichever field is chosen there — one formula, reused everywhere in this PRD, not re-derived per card). |
| **Trend arrow** | `▲`/`▼`/`—` from `(current_period_revenue − prior_period_revenue) / prior_period_revenue`; `—` when prior period revenue is 0 (avoid divide-by-zero, don't show a fabricated ∞% swing). |
| Tooltip | “Your top 5 customers by revenue this period, with change vs the same period last month.” |
| Click-through | Customer link (`CustomerLink`) to `customer-orders`. |

### 6.12 Top segment / brand

| Field | Definition |
|---|---|
| **Metric** | Revenue share by segment for the portfolio in the selected period — reuse whichever segmentation axis is already canonical (per the backorder-report PRD: `brand` is the canonical item-level classification; `product_segment` Manufactured/Trading is the coarser axis). Show both if cheap: a top-3 brand list, and a Manufactured vs Trading split. |
| **Formula** | Sum of portfolio revenue grouped by `brand` (or `product_segment`), same revenue base as §6.4. |
| Tooltip | “Where your revenue is coming from this period, by brand and by Manufactured/Trading.” |
| Guardrail | Do not introduce a third classification axis — reuse the existing `brand`/`product_segment` fields; see the backorder-report PRD's classification-consolidation guidance. |

### 6.13 Top product (SKU)

| Field | Definition |
|---|---|
| **Metric** | Top 5 SKUs in the portfolio by revenue in the selected period, plus a secondary "by volume" view (toggle or two mini-lists). |
| **Formula** | Sum of portfolio SO line revenue grouped by `inventory_id`, same base as §6.4; volume = summed shipped/ordered qty (document which, consistent with fill-rate's own qty field choice). |
| Tooltip | “Your best-selling products this period, by value and by quantity.” |
| Click-through | `InventoryLink` to inventory detail. |

### 6.14 New customers (month-over-month)

| Field | Definition |
|---|---|
| **Definition** | A portfolio customer whose **first-ever SO** (`MIN(order_date)` across **all** history, not just the trend window) falls inside the selected month. This must be computed from full order history — using only orders visible inside a trend window would falsely mark returning customers as "new" at every window boundary. |
| **Count + list** | Count for the KPI card; full list (customer, first order date, first order value) in the drill-down. |
| Tooltip | “Customers who placed their first-ever order with you this month.” |
| Guardrail | Ownership at time of first order may differ from current portfolio owner if reassigned since — document which one this counts under (recommend: **current owner**, consistent with how the rest of the dashboard resolves portfolio membership; see Open Questions). |

### 6.15 Reactivated (win-back) customers

| Field | Definition |
|---|---|
| **Definition** | A portfolio customer who **was** dormant (per the §6.3 rule, as of the prior month's cutoff) and has placed **at least one SO** in the selected month — i.e. came back. Distinct from §6.14 "new" — this is a returning customer, not a first-ever one. |
| **Count + list** | Count for the KPI card; list shows customer, months dormant before reactivating, reactivation order value. |
| Tooltip | “Customers who were dormant and ordered again this month — a win-back, not a new account.” |

### 6.16 Declining / at-risk accounts (early warning)

| Field | Definition |
|---|---|
| **Definition** | A portfolio customer who is **not yet dormant** (still inside the 3-month active window) but whose trailing-30-day (or current-MTD) revenue has dropped **≥ 40%** (configurable threshold) versus their own trailing-3-month average — a leading indicator before they cross into dormant. |
| **Why it matters** | Dormant (§6.3) is a lagging, binary signal — by the time an account is dormant, 3 months of silence have already passed. This card exists to catch a shrinking account *before* it goes fully quiet, while there's still time to act. |
| **Count + list** | Count for the KPI card; list shows customer, trailing-3-month average, current-period value, % drop, last order date. |
| Tooltip | “Accounts still active but ordering noticeably less than their own recent average — worth a check-in before they go dormant.” |
| Guardrail | Minimum sample size: don't flag a customer with fewer than 2 historical orders in the trailing-3-month average (same "insufficient history" guardrail as §6.10) — a thin baseline makes the % drop meaningless. |

### 6.17 Customer concentration

| Field | Definition |
|---|---|
| **Metric** | % of this period's portfolio revenue coming from the top 5 customers (from §6.11). |
| **Why it matters** | A consultant whose book is 80% one account is one lost account away from missing target — this is a risk signal, not a vanity metric. |
| Tooltip | “Share of your revenue this period coming from your top 5 customers. High concentration means more exposure to any one account slowing down.” |
| Guardrail | Informational only in P1 — no red/amber/green judgment threshold without a documented business call on what's "too concentrated" for this portfolio size. |

### 6.18 Trend sparklines on existing cards

| Requirement | Detail |
|---|---|
| **Applies to** | §6.3 (Dormant), §6.4 (Revenue), §6.8 (Backorders), §6.9 (Fill rate) — the four KPIs where "is this getting better or worse" matters as much as "what is it right now." |
| **Rendering** | A small trailing sparkline (6 months, one point per month-end) directly on the KPI card, not a separate chart — cheap enough to render inline without hurting the "≤ 1 aggregated API" performance guardrail (§U10) if fetched as part of the same summary payload. |
| **Guardrail** | A sparkline must be computed by the **same formula/resolver** as its point-in-time counterpart card, evaluated at each historical month-end — never a separately-derived approximation. See §7.6 for the full-size version of these same series. |

---

## 7. Tabs — detailed requirements

### 7.1 My Orders

| Requirement | Detail |
|---|---|
| Rows | Portfolio SOs in period (default MTD; filter: date range, status, customer search). |
| Columns | SO number (link), order date, customer (link), status badge, order total, rep code (if HOD), line count. |
| Status | Use Acumatica-synced status (Open, Shipping, Completed, On Hold, Cancelled, …)—same badge component as ops. |
| Empty | “No orders for your portfolio in this period.” |
| Export | CSV optional P2. |

### 7.2 My Backorders

| Requirement | Detail |
|---|---|
| Rows | Active backorder lines for portfolio customers. |
| Columns | SO, customer, SKU, open qty, revenue at risk, fulfillment status, age/bucket if available, reason. |
| Filters | Align with ops `fulfillment_status` + free-text `q`. |
| Click | SO detail / customer. |

### 7.3 My Fill rate

| Requirement | Detail |
|---|---|
| Summary | Portfolio fill-rate % + trend sparkline if cheap. |
| Breakdown | By customer (top N) and/or by product segment (Manufactured/Trading)—reuse ops patterns. |
| Guardrail | Nightly snapshot timestamp visible. |

### 7.4 My Predicted orders

| Requirement | Detail |
|---|---|
| Summary | Predicted remaining order count + estimated value (from §6.10). |
| Table | Customer, last order date, avg cycle days, avg order value (3m), expected next date, confidence (high/med/low by history depth). |
| Tooltip | Same as KPI. |
| Actions | “Open account”, “Log meeting” deep-link if meeting module available. |

### 7.5 Items not ordered

**Layout:** Group by **customer** (accordion).

| Level | Content |
|---|---|
| **Accordion header** | Customer name (link) · **count of items not ordered** · optional “time since last order for account”. |
| **Value on header** | **Do not show opportunity value** unless **every** (or the rolled-up) item has a valid **3-month average sales value** for that customer×SKU (see below). If only some items qualify, show count only on header; values only on qualified child rows. |
| **Expanded body** | Table of items: SKU, description, days since last order / cycle overdue (“time gone”), last order date, **value only if** 3-month average sales for that item at that customer exists and is &gt; 0. |
| **Value formula (when shown)** | Average line revenue (or avg qty × last unit price) over SO lines in trailing **90 days / 3 calendar months** for that customer+SKU. If no such lines → value = **hidden**, not zero. |
| **Empty** | “No overdue recurring items for your portfolio.” |

**Tooltip:** “Items your customer used to buy on a cycle but hasn’t ordered recently. Value appears only when we have average sales for that item in the last 3 months of orders.”

**Reuse:** Logic from `KpItemsNotOrderedController` + portfolio scope; UI rewrite to accordion-by-customer.

### 7.6 Trends

**Purpose:** every other tab answers "where do I stand right now"; this tab answers "am I improving." A consultant deciding where to spend today's effort needs the direction of travel, not just a snapshot.

| Chart | Series | Data source / feasibility |
|---|---|---|
| **Revenue trend** | Portfolio revenue per month, trailing 6 months (default; 12-month toggle) | Fully reconstructable from existing SO history — no new storage needed. |
| **Dormant count trend** | Count of portfolio customers dormant *as of* each past month-start, trailing 6 months | Fully reconstructable: dormancy is deterministic from order-date history, so each historical month-end can be recomputed with the same §6.3 rule applied to that date instead of today. No new snapshot table required. |
| **Fill rate trend** | Portfolio fill rate % per month | Reconstructable from existing fill-rate snapshot history (already period-stamped), same formula as §6.9 — no new storage needed. |
| **Backorder value trend** | Portfolio revenue-at-risk per month | **Not retroactively reconstructable.** Historical backorder state isn't preserved before this feature ships — active backorder lines are mutated/replaced on every sync, and only *resolved* lines are archived (with a resolve date, not a full daily snapshot of value-at-risk). This series can only start accumulating **from the day this ships forward**; label it "trend since {ship date}" until 6 months of real history exist. See the backorder-report PRD for the same caveat on the operations-wide version of this problem. |
| **New vs. reactivated customers** | Monthly counts of §6.14 and §6.15, stacked or side-by-side | Fully reconstructable from SO history. |

| Requirement | Detail |
|---|---|
| Default range | Trailing 6 months; togglable to 12. |
| Empty/short history | If a portfolio is younger than the selected range (e.g. rep_code assigned 2 months ago), show only the months with real data — never pad with zeros that look like a real trend. |
| Tooltip | Each chart states its own cutoff/timezone and, for backorder value, the "since {date}" caveat explicitly — this is the one series that is honest about being incomplete. |

---

## 8. Usability requirements (“usable by anyone”)

| # | Requirement |
|---|---|
| U1 | **Plain language** labels; no internal acronyms without tooltip (PCR, SO, MTD explained once). |
| U2 | Every non-obvious metric has **InfoTip** with formula + cut-off date. |
| U3 | Loading skeletons per card; failed card shows retry without blank whole page. |
| U4 | Mobile: KPI cards 2-column stack; tabs scroll horizontally. |
| U5 | **First-run checklist** if portfolio empty or rep_code missing. |
| U6 | Date presets: MTD, Last month, Last 3 months, Custom. |
| U7 | HOD scope toggle: “Me | My team” when manager has reports. |
| U8 | Consistent status colours (orders / backorders). |
| U9 | No dual currencies; KES only unless multi-currency already on SO. |
| U10 | Performance: KPI strip ≤ 1 aggregated API; tabs paginated (default 25). |
| U11 | Accessibility: keyboard tabs, tooltips on focus, contrast AA. |
| U12 | Help link: “How portfolio is calculated” drawer. |

---

## 9. Guardrails (data integrity & product)

| # | Guardrail |
|---|---|
| GR1 | **Single portfolio resolver** shared by KPI + all tabs + dormant + items-not-ordered. |
| GR2 | Backorder classification only via existing `isBackorderLine()` / backorder lines—no `open_qty > 0` alone. |
| GR3 | Revenue-at-risk = open/backorder qty × unit price; never order total as shortfall. |
| GR4 | Fill rate formula unchanged from production calculator. |
| GR5 | Dormant window matches dormant module (3 calendar months from **month start**). |
| GR6 | Predictions always labelled **estimate**; never used as commission input in P1. |
| GR7 | Items-not-ordered value **null/hidden** without 3-month average—not displayed as 0. |
| GR8 | No live Acumatica on dashboard render; use synced SO / backorder / fill-rate / inventory snapshots. |
| GR9 | Scope denials return empty lists, not 500s; 404 on foreign customer deep-link. |
| GR10 | Role without permission cannot open hub; deep-links redirect to allowed home. |
| GR11 | Sync lag: show `data_as_of` / last SO sync time on footer of strip. |
| GR12 | HOD team metrics must not double-count a customer assigned to two reports (distinct customer set). |
| GR13 | Trend charts (§7.6) and sparklines (§6.18) reuse the **exact same formula/resolver** as their point-in-time KPI counterpart, evaluated at each historical marker — never a separately-derived approximation that could silently drift from the live number. |
| GR14 | Backorder value trend can only start accumulating from this feature's ship date — historical backorder state is not reconstructable. Never backfill it with a guessed/interpolated history; label it "since {date}" until real history accumulates. |
| GR15 | "New customer" (§6.14) is computed from **full** order history (`MIN(order_date)` across all time), never from just the trend window — otherwise every window boundary manufactures false "new" customers out of returning ones. |
| GR16 | Top Client/Segment/Product (§6.11–6.13) respect the same portfolio scope and date window as the rest of the dashboard — never an unscoped company-wide ranking leaking into a personal view. |
| GR17 | Declining/at-risk (§6.16) requires the same minimum-history guardrail as Predictions (§6.10) — don't flag a thin baseline as a "40% drop." |

---

## 10. Backend / API sketch

### 10.1 Portfolio service

`SalesPortfolioService::customerIdsFor(User $user, ?string $mode = 'self'|'team'): list<string>`

Uses `OrgScopeService` + rep-code + assignments; **one implementation**.

### 10.2 Endpoints (suggested)

| Method | Path | Purpose |
|---|---|---|
| `GET` | `sales/portfolio/summary` | All KPI strip fields + cutoffs + `data_as_of` |
| `GET` | `sales/portfolio/orders` | Paginated My Orders |
| `GET` | `sales/portfolio/backorders` | Paginated My Backorders |
| `GET` | `sales/portfolio/fill-rate` | Summary + optional breakdown |
| `GET` | `sales/portfolio/predictions` | Predicted orders table |
| `GET` | `sales/portfolio/items-not-ordered` | Grouped by customer (or flat + client group) |
| `GET` | `sales/portfolio/top-movers` | Top client(s), top segment, top product, new/reactivated/declining customer lists, concentration % (§6.11–6.17) |
| `GET` | `sales/portfolio/trends` | Monthly series for revenue, dormant count, fill rate, backorder value (since-ship-date), new vs. reactivated (§7.6) |

Query params: `date_from`, `date_to`, `mode=self|team`, `q`, `page`, `per_page`, `status`. `trends` additionally takes `months` (default 6, max 12).

### 10.3 Response contract (summary — illustrative)

```json
{
  "scope": { "mode": "self", "rep_code": "P415", "customer_count": 120 },
  "windows": {
    "timezone": "Africa/Nairobi",
    "month_start": "2026-07-01",
    "active_from": "2026-04-01",
    "dormant_label": "No SO since 2026-04-01 (3 months from month start)"
  },
  "kpis": {
    "customers_total": 120,
    "customers_active": 85,
    "customers_dormant": 35,
    "revenue_mtd": 1250000.50,
    "target": 2000000,
    "time_gone_pct": 0.55,
    "expected_revenue_to_date": 1100000,
    "variance_to_pace": 150000.50,
    "orders_count": 42,
    "backorder_lines": 17,
    "backorder_revenue_at_risk": 89000,
    "fill_rate_pct": 91.2,
    "predicted_remaining_orders": 12,
    "predicted_remaining_value": 340000
  },
  "data_as_of": "2026-07-23T10:00:00+03:00"
}
```

### 10.4 Response contract — top-movers (illustrative)

```json
{
  "top_clients": [
    { "customer_acumatica_id": "CUST101239", "customer_name": "Acme Ltd", "revenue": 210000, "trend_pct": 0.12 }
  ],
  "top_segments": { "by_brand": [{ "brand": "Fay Tissues", "revenue": 480000, "share_pct": 0.38 }], "by_product_segment": { "manufactured": 620000, "trading": 630000 } },
  "top_products": { "by_value": [{ "inventory_id": "FAYTP0008", "revenue": 95000 }], "by_qty": [{ "inventory_id": "FAYCL0009", "qty": 1200 }] },
  "new_customers": { "count": 3, "customers": [{ "customer_acumatica_id": "CUST102850", "first_order_date": "2026-07-05", "first_order_value": 45000 }] },
  "reactivated_customers": { "count": 2, "customers": [{ "customer_acumatica_id": "CUST101838", "months_dormant": 4, "reactivation_value": 30000 }] },
  "declining_accounts": { "count": 4, "customers": [{ "customer_acumatica_id": "CUST101902", "trailing_3mo_avg": 80000, "current_period": 42000, "drop_pct": 0.475, "last_order_date": "2026-07-02" }] },
  "concentration_pct": 0.62,
  "data_as_of": "2026-07-23T10:00:00+03:00"
}
```

### 10.5 Response contract — trends (illustrative)

```json
{
  "months": ["2026-02", "2026-03", "2026-04", "2026-05", "2026-06", "2026-07"],
  "revenue": [980000, 1050000, 1120000, 990000, 1180000, 1250000],
  "dormant_count": [28, 30, 33, 31, 34, 35],
  "fill_rate_pct": [92.1, 90.4, 93.0, 91.8, 90.9, 91.2],
  "backorder_value_at_risk": [null, null, null, null, 61000, 89000],
  "backorder_value_since": "2026-06-01",
  "new_customers": [1, 0, 2, 1, 0, 3],
  "reactivated_customers": [0, 1, 0, 2, 1, 2]
}
```

---

## 11. Frontend sketch

| Piece | Notes |
|---|---|
| `SalesPortfolioDashboard` page component | Hosts KPI strip + tabs |
| Reuse | `KpAccountsTable`, `CustomerLink`, `InfoTip`, status badges, backorder/fill-rate table patterns |
| Hooks | `usePortfolioSummary`, `usePortfolioOrders`, … |
| Role switch | Consultant default home = portfolio; optional soft prompt on `/app` |

---

## 12. Gaps vs current product (checklist)

| Area | Status | Work |
|---|---|---|
| Org + rep portfolio scope | ✅ Partial | Unify SO-rep vs assignment vs HOD into one resolver used by hub |
| KP Accounts list | ✅ | Add KPI strip + tabs around it |
| Dormant 3-month rule | ✅ | Surface as card + tooltip with cutoff |
| Commission target | ✅ data | Wire into KPI |
| Target vs time | ❌ | New calculation + UI |
| Portfolio orders tab | ❌ | New (scoped list) |
| Portfolio backorders tab | ❌ | New (reuse transformer) |
| Portfolio fill rate | ❌ | New (scoped calculator) |
| Predictions 3m + run-rate | ❌ | New service |
| Items not ordered accordion by customer | ⚠️ | Rewrite UI + value guardrail |
| Consultant-first `/app` | ❌ | Role-aware landing |
| Tooltips everywhere | ⚠️ | Standardize InfoTip |
| Empty / no-rep-code UX | ⚠️ | Banner |
| Top client(s) + trend | ❌ | New (aggregation query, reuses revenue formula) |
| Top segment/brand | ❌ | New (reuses existing `brand`/`product_segment` fields) |
| Top product (SKU) | ❌ | New (aggregation query) |
| New customers (MoM) | ❌ | New (`MIN(order_date)` over full history) |
| Reactivated / win-back | ❌ | New (depends on dormant-as-of-date recompute) |
| Declining / at-risk accounts | ❌ | New (trailing-3mo average vs current period) |
| Customer concentration | ❌ | New (cheap, derives from top-clients query) |
| Revenue / dormant / fill-rate trend (6mo) | ❌ | New — all three reconstructable from existing history, no new storage |
| Backorder value trend | ❌ | New — **cannot be backfilled**; starts accumulating from ship date only |

---

## 13. Test plan

1. **Scope:** Consultant A never sees Consultant B’s customer; HOD sees A∪B without double-count.  
2. **Dormant/active:** Fixture SO dates around month boundary; cutoff matches dormant page.  
3. **Target pace:** Mid-month working-day math golden cases.  
4. **Backorders:** Cancelled lines excluded; revenue_at_risk formula regression.  
5. **Fill rate:** Matches ops number for same customer set/period within rounding.  
6. **Items not ordered:** Header shows count only; value only with 3m average.  
7. **Missing target / missing rep_code:** Soft empty states, not errors.  
8. **Permissions:** 403 without view permission.  
9. **Performance:** Summary query under agreed budget on sample portfolio size (e.g. 500 customers).  
10. **Impersonation:** Metrics switch with banner.
11. **New vs. reactivated:** a customer with a 5-month-old first order and a fresh order this month must be classified as *reactivated*, never *new* — fixture must include full order history, not just the trend window, to catch a resolver that only looks at windowed data.
12. **Trend/point-in-time parity:** for revenue, dormant count, and fill rate, the trend chart's value at the *current* month must exactly match the corresponding KPI card's live value for the same period — same resolver, no drift.
13. **Backorder trend honesty:** before 6 months of real history exist, the chart must show only the months it actually has data for (with the "since {date}" label), never zero-padded or interpolated months.
14. **Declining-accounts threshold:** golden-case fixture at exactly the configured drop threshold (e.g. 40%) to confirm the boundary is inclusive/exclusive as documented, and that a customer with only 1 historical order is excluded regardless of drop %.
15. **Concentration:** top-5 revenue share recomputes correctly when a portfolio has fewer than 5 customers (percentage of whatever's there, not a divide-by-a-fixed-5).

---

## 14. Rollout

| Phase | Deliverable |
|---|---|
| **P1a** | Portfolio resolver + `GET summary` + KPI strip on Accounts |
| **P1b** | Tabs: Orders, Backorders, Fill rate |
| **P1c** | Items not ordered accordion + value rules |
| **P1d** | Predictions + tooltips polish + consultant home entry |
| **P1e** | Top movers (client/segment/product, new/reactivated/declining, concentration) + Trends tab (revenue/dormant/fill-rate reconstructed history; backorder trend starts accumulating live) |
| **P2** | Team vs me toggle polish, CSV export, working-day holiday calendar, view-as-consultant |

---

## 15. Open questions

1. **Revenue base:** SO `order_total` vs sum of lines vs invoiced only?  
2. **Target source of truth:** `commission_targets` only, or separate sales targets table?  
3. **Time-gone:** Working days vs calendar days?  
4. **Predicted “closures”:** Mean order revenue or completed-status only?  
5. **Should non-KP customer classes appear** when a consultant’s book includes them?  
6. **HOD depth:** Direct reports only or full org subtree? (Today: `effectiveScopeUserIds`.)  
7. **Default home for consultant:** `/app` vs `/app/kp/accounts`?  
8. **New-customer ownership:** if a customer's first-ever order was placed under a different rep who has since left/reassigned, does §6.14 credit the *current* owner or the rep who actually acquired them? (Recommend current owner, for consistency with how portfolio membership is resolved everywhere else — but this rewrites acquisition history from the current owner's perspective, worth a deliberate call.)  
9. **Declining-account threshold:** is 40% the right drop threshold, and should it be admin-configurable per department rather than a fixed constant?  
10. **Trend default range:** 6 months or 12 by default — balance "enough to see a trend" against query cost on large portfolios.  
11. **Backorder trend investment:** accept "starts from ship date, grows over time" (no backend work beyond the point-in-time feature), or invest in a daily/monthly value-at-risk snapshot table now so history starts accumulating sooner? (See the backorder-report PRD's own open question on this — same underlying gap, don't solve it twice differently.)

---

## 16. Success metrics

- ≥ 80% of active consultants open Accounts hub weekly within 4 weeks of launch.  
- Reduction in “what’s my dormant list?” support questions.  
- KPI strip load p95 &lt; 2s on production portfolios.  
- Zero cross-portfolio data leakage incidents in QA + first production month.

---

## 17. Document history

| Version | Date | Notes |
|---|---|---|
| 0.1 | 2026-07-23 | Initial PRD — consultant dashboard KPIs, shared Accounts, tabs, guardrails, items-not-ordered accordion rules |
| 0.2 | 2026-07-23 | Added §6.11–6.18 (Top client, top segment, top product, new customers MoM, reactivated/win-back, declining/at-risk accounts, concentration, trend sparklines) and §7.6 Trends tab (6-month revenue/dormant/fill-rate/backorder history). New guardrails GR13–GR17, endpoints `top-movers`/`trends`, gaps-checklist rows, test cases, rollout phase P1e, and open questions 8–11 — notably flagging that backorder value history cannot be backfilled and can only accumulate from ship date forward. |
