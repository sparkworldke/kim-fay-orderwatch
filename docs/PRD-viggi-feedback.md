# PRD — Vignesh Review Feedback (OrderWatch + KP CRM)

| Field | Value |
|-------|--------|
| **Source** | `docs/viggi-feedback.md` (review call: Titus Mutiso ↔ Vignesh Ramachandran) |
| **Status** | Ready for implementation planning |
| **Date captured** | July 2026 (call transcript) |
| **Owner (product)** | Vignesh (decisions) · Titus (build) |
| **Related** | `roles.md`, `kp/pricing/price-change.md`, FOL / KP CRM routes, `team-module-guide.md` |

---

## 1. One-line summary

Tighten **OrderWatch accuracy** (backorders, partner brands, zones/aging), polish **KP FOL + Price Change** with clear approval math, and make **KP CRM** actionable (dormant outreach, “Items not ordered”, meetings, rep performance)—all under one platform with role-based views and hard **guardrails**.

---

## 2. Problem statement

| Area | Problem today | Business risk |
|------|----------------|---------------|
| Backorders / partner brands | Dashboard numbers diverge from Ruben/Beatrice Excel (~KES 30M deficit; partner ~20 vs ~55) | Wrong ops decisions; fill rate inherits bad math |
| Order / shipment aging | Hard to see PO → entry → approval → ship vs zone SLA (24h / 48h) | Missed delivery promises (Nairobi + regions) |
| FOL UX | Prices on cart; 6‑month volume; weak SKU breakdown for approvers | Slow / wrong HOD approval |
| PCR UX | “Margin” mislabeled; current selling price missing; no counter-price | Approvers do manual Excel; bad discounts |
| Field CRM | White-spot naming confusing; one-by-one only; dormant not owned | Lost sales; Coltronics get bad contacts |
| Platform identity | “OrderWatch” no longer describes full product | Confusion for KP vs ops users |
| Adoption | Trained users may not log in | Build without usage |

---

## 3. Goals & non-goals

### Goals

| ID | Goal | Success signal |
|----|------|----------------|
| G1 | Align backorder & partner-brand figures to shared criteria with Ruben (±10% interim, then exact) | Dashboard vs Excel within agreed tolerance |
| G2 | Ship **order aging + zone SLA** view (PO date → order → approve → ship) | Zone managers can answer “are we within 24/48h?” |
| G3 | FOL: 3‑month volume, cleaner cart, required fields, SKU volume table, SO linkage | Approvers act without spreadsheet |
| G4 | PCR: discount vs current selling; cost margin for privileged; revised price; optional docs; lowest-5 comps | Approver decides in-app |
| G5 | Rename white spot → **Items not ordered**; team-level tab + 30/60/90 opportunity | Reps work from one queue, not one customer at a time |
| G6 | Dormant → contact quality → Coltronics feedback loop | Dormant count down; contact completeness up |
| G7 | KP rep **performance home** (targets, dormant, FOL, PCR, opportunity) | Reps self-serve daily |
| G8 | Adoption: login audit for trained users | % logged in by target date |
| G9 | Naming + nav: one platform, rights-based modules | Clear brand + sidebar groups |

### Non-goals (this PRD)

- Full Acumatica fix for shipment→SO status (external; track only)
- Incentive/commission engine (explicitly “last”; do not rush)
- Replacing Zoho CRM for all of Kim-Fay (KP CRM slice first)
- Auto-create FOL SO in Acumatica **until** joint process meeting (Need, Berna, Susan, Vignesh)

---

## 4. Personas (who sees what)

| Persona | Primary modules | Must not see |
|---------|-----------------|--------------|
| **Sales Consultant (KP)** | Orders (own), FOL request, PCR create, meetings, dormant/own, items not ordered, own performance | Cost, true margin %, admin, mailbox, order-match, other reps’ full portfolios (unless org scope) |
| **HOD / Susan-style** | FOL approve L1, PCR stage if assigned, meetings reporting, portfolio dormant | Platform admin; may see margin if `view_margin` |
| **Executive (Vignesh)** | PCR final path, margin + cost, lowest-5 prices, KP executive reporting, opportunity size | Need not live in detailed rep forms |
| **Sales Ops / Finance prices** | PCR after approve → ERP apply | Field-only edit of cost |
| **Technician / Tech Mgr** | FOL install calendar | PCR margin, dormant bulk reassign |
| **CS / OrderWatch ops** | Dashboard, fill rate, backorders, zones, mailbox | KP PCR cost unless granted |
| **Administrator** | Everything + train/audit logs | — |

> Guardrail: **Permissions gate actions; org scope gates data.** See `docs/roles.md`.

---

## 5. Easy decision options (defaults)

Use these when implementing. **Recommended = default** unless Vignesh overrides.


### 5.2 FOL → Acumatica SO after final approval

| Option | Description | Default |
|--------|-------------|---------|
| **A — Manual SO only** | User creates SO; system links SO number to FOL | Current until design meeting |
| B — One-click “Create SO draft” in OrderWatch calling Acumatica API | Semi-auto | **Target after process meeting** |
| C — Fully automatic SO on final approve | Highest risk | Later; needs finance rules |

**Guardrail:** Never invent SO without audit (who, when, payload, Acumatica id). No “bleeding/naming” SO outside linked FOL.

### 5.3 PCR approval path

| Option | Description | Default |
|--------|-------------|---------|
| **A — Configurable stages** (HOD then Executive) | Matches existing seeder | Keep for non-sensitive |
| **B — Fast path to Executive** for margin-sensitive | “Straight to me”; sales never see margin | **B for margin fields visibility** |
| C — Single stage only | Simpler | Only if product confirms |

**Guardrail:** Consultants never receive cost / true margin / lowest-5 competitor-style internal prices unless a future permission is explicit.  
Approvers with `pricing.pcr.view_margin` see cost + margin %; others see **discount % only**.

### 5.4 “Items not ordered” placement

| Option | Description | Default |
|--------|-------------|---------|
| **A — Own nav tab** under KP CRM (team portfolio) | Not buried in customer detail | **A** |
| B — Only on customer history accordion | Current-ish “whitespot” | Keep as drill-in |
| C — Both A + B | List + detail | **C (recommended full)** |

### 5.5 Dormant handoff to Coltronics / DTC

| Option | Description | Default |
|--------|-------------|---------|
| **A — Soft queue** | Reps re-engage first; after rules, handoff with contacts | **A** |
| B — Auto-assign all dormant to Calltronix | Fast volume | Only after contact completeness |
| C — Report-only (no feedback form) | Weak | Reject |

**Guardrail:** No handoff if **primary contact** missing (name + phone or email). Schools excluded from dormant definition unless config toggled.

---

## 6. Feature requirements by domain

### 6.1 OrderWatch — Dashboard, backorders, partner brands

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| OW-1 | Segment views: **Manufactured**, **Trading**, **KP**, **Consumer** (as shown in review) | P0 |
| OW-2 | Reconcile backorder value with Ruben’s method (document formula in app help or admin note) | P0 |
| OW-3 | After re-sync through target date, validate within **±10%** then tighten | P0 |
| OW-4 | Partner-brands total aligns with shared Power BI / Excel criteria | P0 |
| OW-5 | Fill rate correctness depends on backorder correctness — fix BO first | P0 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-OW-1 | Publish **one written formula** for backorder value (price source, open qty, order types, cancel/delete lines). |
| G-OW-2 | Exclude non-SO types from operational BO totals unless product opts in (see Order Type guardrail docs). |
| G-OW-3 | If Acumatica line deleted/added after BT edit, recalculate from **current open lines**, not frozen snapshot alone. |
| G-OW-4 | Sync watermark visible on dashboard (“data as of …”). |
| G-OW-5 | Do not ship fill-rate “fixes” until BO tolerance agreed. |

#### Easy options — price basis for backorder value

| Option | Use | Default |
|--------|-----|---------|
| Unit price on open SO line | Matches sales order | **Default candidate** |
| Current price class | Can diverge from SO | Only if Ruben uses it |
| Base/list | Accounting | Avoid for ops BO |

---

### 6.2 Inventory prediction (run rate)

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| INV-1 | Show recent months sales + run-rate style forecast inputs (as demoed) | P1 |
| INV-2 | Export for Nick / Christine validation | P1 |
| INV-3 | Label predictions as **indicative**, not ERP commitments | P1 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-INV-1 | Predictions disabled or badged when underlying SO/inventory sync is stale. |
| G-INV-2 | Do not auto-create POs in Acumatica from predictions. |

---

### 6.3 Zones, delivery aging, partner brand drill-down

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| ZN-1 | Group zones for **Nairobi** vs other regions (not only CBT / Ngong-style fragments) | P0 |
| ZN-2 | **Aging report** for orders/shipments with milestones: | P0 |
| | 1) PO date vs order entry date — same day? Y/N | |
| | 2) Order approved same day? Y/N | |
| | 3) Ship time vs zone SLA (**24h or 48h** by zone) | |
| ZN-3 | Partner Brands: delivered vs not, drill to **SKU**, reasons, linked **SOs**, outlet name | P0 |
| ZN-4 | UX: SO panel can occupy **~half page** with outlet name; easy close | P1 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-ZN-1 | SLA clock definition must be config-driven (start: order approve vs order entry — **document choice**; default start = **approved datetime** unless ops chooses entry). |
| G-ZN-2 | Timezone: **Africa/Nairobi** for all aging. |
| G-ZN-3 | Incomplete shipment status in Acumatica: show **source status as-is** + banner “ERP shipment may lag SO” (Mayur/Acumatica bug). |
| G-ZN-4 | Brand filter never leaks SKUs outside user’s brand/sector scope. |

#### Easy options — aging start event

| Option | Description | Default |
|--------|-------------|---------|
| **A — From order approval** | Matches “ready to fulfill” | **A (recommended)** |
| B — From order entry | Includes credit hold delays | Optional toggle |
| C — From customer PO date | Measures CS capture speed only | Separate column, not SLA clock |

---

### 6.4 Training & adoption

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| AD-1 | Finish training for remaining office teams (CS / office after MT1/MT2) | P0 |
| AD-2 | All trained users **signed in by Monday** (access issues escalated to Titus) | P0 |
| AD-3 | By **Wednesday**: login report for trained cohort — logged in vs not | P0 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-AD-1 | Login report from **authoritative session/audit table**, not browser cookies. |
| G-AD-2 | Shared mailboxes excluded from “user adoption” counts. |

---

### 6.5 KP FOL (Free On Loan)

#### Requirements (product decisions from call)

| ID | Requirement | Priority |
|----|-------------|----------|
| FOL-1 | Lookback for revenue / volume / averages: **last 3 months** (was 6) | P0 |
| FOL-2 | Remove from line cart UI: **unit price, line total** (and similar money lines on cart) | P0 |
| FOL-3 | “How did you get the order?” options: **Visit · Phone · Email** only — **remove Other** | P0 |
| FOL-4 | **Reason for request** = required | P0 |
| FOL-5 | Purpose flags remain (new install / replacement / maintain, etc.) | P0 |
| FOL-6 | Evidence attachments: contract / PO / agreement (sales history no longer required attach — system-sourced) | P0 |
| FOL-7 | Installation address free-text when new install (KP branches often missing) | P0 |
| FOL-8 | Approver snapshot: **volume breakdown by SKU** for consumables / top ~10 items last **3 months** (not a single “volume 6 months” blob) | P0 |
| FOL-9 | Full approval trail visible to **requester** (not only admin) | P0 |
| FOL-10 | On final approval path: **link SO** to FOL; list shows SO status for processing visibility | P0 |
| FOL-11 | Installation → **technician calendar**; tech sees day allocations; Susan/Doreen allocate by location | P1 |
| FOL-12 | Customer portfolio scoping for non-admin requesters | P0 |
| FOL-13 | Contacts on customer; add on the fly | P0 |
| FOL-14 | Design joint session: auto SO (options in §5.2) | P1 design |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-FOL-1 | Only **FOL-eligible** inventory in picker (admin-marked). |
| G-FOL-2 | Consultants only customers in **their portfolio / org scope**. |
| G-FOL-3 | Cannot submit without required reason + channel + arrears field (as currently required). |
| G-FOL-4 | FOL SO order type / naming convention documented with finance (even if price is nominal 1 / 0.7). Do not hide accounting oddities—show ERP price if SO created. |
| G-FOL-5 | Approval notifications email + in-app; failures must not silently drop stage. |
| G-FOL-6 | Reject/approve always writes audit + notifies both sides. |
| G-FOL-7 | Volume tables are **read-only snapshots at submit + refresh on open** for approver (store snapshot_id to avoid “moving target” disputes). |
| G-FOL-8 | New customer: manual promise fields allowed; still require arrears explanation. |

#### Easy options — FOL cart money fields

| Option | Description | Default |
|--------|-------------|---------|
| **A — Hide all money on request cart** | Matches Vignesh | **A** |
| B — Show for admin only | Debug | Optional |
| C — Show ERP list price | Rejected for field UX | No |

#### Easy options — volume window

| Option | Default |
|--------|---------|
| **3 calendar months rolling** | **Yes** |
| 90 days rolling | Acceptable equivalent if labeled |
| 6 months | Remove as default |

---

### 6.6 Price Change Requests (PCR)

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| PCR-1 | Label **discount** (not “margin”) for: current selling → proposed | P0 |
| PCR-2 | **Current selling price** auto-resolved per customer (price class / customer-specific), not generic base only | P0 |
| PCR-3 | **Base / list** displayed as secondary reference for approvers only | P0 |
| PCR-4 | **True margin %** = f(cost, proposed) for users with `pricing.pcr.view_margin` | P0 |
| PCR-5 | Cost = manufactured / unit cost from configured ERP field | P0 |
| PCR-6 | Approver actions: **Approve as proposed** · **Feedback with revised price** · Reject | P0 |
| PCR-7 | Optional **document upload** (proof) on create | P1 |
| PCR-8 | Approver panel: **lowest 5 selling prices** for same SKU across customers + customer names | P0 (approver only) |
| PCR-9 | Sales team **must not** see cost / true margin / lowest-5 internal comps | P0 |
| PCR-10 | Notifications both sides; finance/ops path for ERP apply unchanged in spirit | P0 |

#### Formulas (display)

```
discount_pct = (current_selling - proposed) / current_selling * 100
  // if proposed < current_selling → positive “discount asked”
  // if proposed > current_selling → show as negative discount / increase

margin_pct   = (proposed - unit_cost) / proposed * 100   // approvers only
  // document exact denominator if finance prefers cost-based; default sell-price margin
```

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-PCR-1 | API redaction: consultants never receive `unit_cost`, `base_price`, `margin_pct`, `lowest_prices[]`. |
| G-PCR-2 | Resolve price **server-side** at submit; snapshot immutable on request. |
| G-PCR-3 | Revised price creates audit event; requester notified; may re-enter stage or final as configured. |
| G-PCR-4 | Portfolio: consultant may only open PCR for in-scope customers. |
| G-PCR-5 | Lowest-5 list: same **SKU**, active customers, **current selling**; exclude demo/test accounts if flagged. |
| G-PCR-6 | Margin floor / authority thresholds (existing PCR PRD) still apply before approve. |
| G-PCR-7 | Optional attachment: virus/size limits; not required to submit. |

#### Easy options — revised price flow

| Option | Description | Default |
|--------|-------------|---------|
| **A — Approver sets revised price → request becomes “Countered” → requester accepts or withdraws** | Clear | **A** |
| B — Approver’s revised price is final on approve | Faster | Optional for Exec-only stage |
| C — Only free-text feedback, no numeric revised | Weak | No |

#### Easy options — who sees PCR first

| Option | Description | Default |
|--------|-------------|---------|
| A — Multi-stage HOD → Exec | Existing seed | Keep where configured |
| **B — Exec-only for KP price** (as Vignesh described for margin decisions) | Config stage roles | **Confirm in admin settings** |

---

### 6.7 Dormant customers & contact quality

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| DRM-1 | Dormant = no purchase in **3 months**, **exclude schools** (config list) | P0 |
| DRM-2 | Allocate each dormant account to a rep (or unassigned queue) | P0 |
| DRM-3 | Feedback form: contacted Y/N, who, outcome, comments — **single source of truth** | P0 |
| DRM-4 | Contacts: multiple, designations (MD, procurement, etc.), **primary** contact for Calltronix order of dial | P0 |
| DRM-5 | Seed contacts from Zoho/export where available; rep **confirms** before handoff | P1 |
| DRM-6 | Escalation: no contact / no update for **3 months** → alert managers (“slap”) | P1 |
| DRM-7 | Only after contacts + attempt history → pass to Coltronics/Calltronix workflow | P1 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-DRM-1 | Definition of dormant is **config** (days + excluded customer classes). |
| G-DRM-2 | Cannot mark “handed to Calltronix” without primary contact phone **or** email. |
| G-DRM-3 | Feedback immutable after submit except admin correction with audit. |
| G-DRM-4 | Respect org scope — reps only see their dormant set. |

---

### 6.8 Customer history — “Items not ordered” (ex–white spot)

#### Naming

| Old | New |
|-----|-----|
| White spot | **Items not ordered** |

> **White spot** reserved for true new opportunity not yet sold (future). Do not reuse.

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| INO-1 | Per customer: items expected from run rate / order cycle but not ordered | P0 |
| INO-2 | **Separate KP CRM tab/list**: all customers in portfolio with items not ordered | P0 |
| INO-3 | Aging buckets: **30 / 60 / 90 days** | P0 |
| INO-4 | Risk labels: 30 = low, 60 = medium, 90 = **lost sales** | P0 |
| INO-5 | Opportunity size (KES) at portfolio + executive dashboard | P0 |
| INO-6 | Filters: last 30/60/90, rate of sale, order cycles | P0 |
| INO-7 | Keep **common products** panel for onboarding new reps to an account | P1 |
| INO-8 | Customer-level history still shows documents, delivery rate, dates | P1 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-INO-1 | Opportunity value uses same price basis as ops (document: last sell / avg / list). |
| G-INO-2 | Do not double-count FOL vs commercial SO without clear type filter. |
| G-INO-3 | Executive “opportunity size” respects sector/org scope (no leak across GT/MT/KP unless org-wide role). |
| G-INO-4 | Empty state if insufficient history (&lt; N orders) — don’t invent cycles. |

#### Easy options — opportunity KES basis

| Option | Default |
|--------|---------|
| **Avg order line value × expected qty from run rate** | **Recommended** |
| Last unit price × expected qty | Alt |
| Base price × qty | Avoid for sales ops |

---

### 6.9 Meetings (KP CRM)

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| MTG-1 | Meeting targets per consultant (e.g. 80 KP default; configurable) | P1 |
| MTG-2 | Create meeting: title, purpose (condensed with Susan — reduce Zoho clutter), customer, on-site/remote, schedule | P1 |
| MTG-3 | Companion / accompaniment user | P1 |
| MTG-4 | Previous notes auto-pull (empty at start of system life) | P1 |
| MTG-5 | Post-visit required: notes, outcome, opportunities, person met, budget, competitor, **timeline to close**, **next follow-up date** | P1 |
| MTG-6 | Actions: dynamic list (not fixed mega dropdown); assign owner (self or companion); due date; reminders | P1 |
| MTG-7 | Walk **Divya** through CRM for input before freeze | P1 |

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-MTG-1 | Notes/outcome required only **after** meeting end (or status = Completed)—not at create. |
| G-MTG-2 | Follow-up date triggers reminder; open actions re-notify until closed. |
| G-MTG-3 | Meetings scoped to portfolio customers. |

---

### 6.10 KP performance home (rep + executive)

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| PERF-1 | Rep home: items not ordered 30/60/90 + KES opportunity | P0 |
| PERF-2 | My dormant customers count + list | P0 |
| PERF-3 | FOL status summary (mine) | P0 |
| PERF-4 | Entry to create PCR | P0 |
| PERF-5 | CRM / meetings entry | P0 |
| PERF-6 | Target vs time elapsed (“am I ahead/behind?”) — **targets first; incentives later** | P1 |
| PERF-7 | Executive: drill to user, KP reporting without full admin clutter | P1 |
| PERF-8 | Rep home: **my backorder exposure** — value at risk, aging distribution (0-7/8-14/15-30/30+), and lines resolved this period, scoped to the rep's own portfolio | P0 |

#### Implementation status (2026-07-23)

PERF-8 is live on the sales consultant detail page (`/app/sales-consultants/:id`, "My backorders" panel):

- KPI cards: value at risk on open lines, open line count, and revenue + count resolved in the selected date range.
- A bar chart of open exposure by age bucket (0-7 / 8-14 / 15-30 / 30+), colored on a green→red severity ramp so the "needs a follow-up call" bucket (30+) reads at a glance.
- Backed by the same live backorder/resolved-backorder data as `/app/backorders`, filtered server-side by `rep_code` (the order's `sales_consultant_rep_code`) — not a separate rep-facing dataset, so it never drifts from the ops view of the same numbers.
- Not yet done: this panel is additive to the *existing* consultant detail page — it is not yet part of a dedicated "rep home" landing page with PERF-1/2/3/5/6 (items not ordered, dormant, FOL status, meetings entry, target-vs-time) assembled alongside it. Those live on their own separate pages today (`/app/kp/items-not-ordered`, `/app/kp/dormant`, `/app/kp/fol`); PERF-7 (executive drill-down without admin clutter) is also still open.

#### Guardrails

| # | Guardrail |
|---|-----------|
| G-PERF-1 | No incentive calc until rules documented (Vignesh: do not rush). |
| G-PERF-2 | Cards click through to filtered lists, not dead ends. |
| G-PERF-3 | Rep-scoped backorder data must be filtered server-side by `rep_code`, never by a client-side rep lookup — an explicit `rep_code` param on the backorders endpoints is ANDed with any existing consultant-scope restriction, so a non-privileged consultant can only narrow their own data, never widen it to another rep's. |

---

### 6.11 Platform packaging & privileges

#### Requirements

| ID | Requirement | Priority |
|----|-------------|----------|
| PLAT-1 | One platform; modules by rights (sidebar: OrderWatch / KP CRM / Administration) | P0 (done in spirit) |
| PLAT-2 | Executive view: dashboard, orders, fill rate, backorders, KP approve/pricing/meetings reporting | P1 |
| PLAT-3 | Consultants: no need for full OrderWatch admin surfaces | P0 |
| PLAT-4 | Rename branding proposal shortlist | P2 |

---

### 6.12 Infrastructure (from call close)

| ID | Item | Priority |
|----|------|----------|
| INF-1 | VPS migration for speed / downtime (quote ~7.5/mo, offer ~6 for 2y) — **needs Vignesh approval** | P0 business |
| INF-2 | Track Acumatica shipment→SO bug with Mayur; keep Titus in loop | P0 external |

**Guardrail:** No production cutover without backup + rollback window.

---

## 7. Cross-cutting guardrails (always on)

| # | Rule |
|---|------|
| X1 | **Role + permission + org scope** on every list API (fail closed for `gap` / unscoped). |
| X2 | **Revenue masking** for roles that must not see money (CS Agent etc. per `roles.md`). |
| X3 | **Audit** all approve / reject / revised price / FOL SO link / dormant handoff. |
| X4 | **Email failure must not roll back** core workflow writes (log + retry). |
| X5 | **Idempotent** Acumatica SO create if implemented (no duplicate SO on double-click). |
| X6 | **Timezone** EAT for all KP SLA and meeting times. |
| X7 | **Do not rename** product URLs without redirects. |
| X8 | **No silent data** — empty states explain missing sync / missing contacts / insufficient history. |
| X9 | **School exclusion** and dormant days are config, not hard-coded only in UI. |
| X10 | **Items not ordered ≠ white spot** in code strings, UI, and exports. |

---

## 8. Phased delivery

| Phase | Focus | Exit criteria |
|-------|--------|---------------|
| **P0a — Trust the numbers** | BO formula with Ruben, re-sync, partner brands, sync watermark | ±10% then signed-off formula |
| **P0b — FOL polish** | 3mo volume, cart cleanup, required fields, SKU table, requester trail | Vignesh can approve from snapshot |
| **P0c — PCR polish** | Discount naming, current sell, cost margin, revised price, lowest-5, redaction | Smoke + role tests green |
| **P0d — Items not ordered + dormant core** | Rename, tab, aging, opportunity KES, feedback form | Reps use daily queue |
| **P1 — Aging zones + calendar + meetings** | Zone SLA aging, install calendar, meetings v1, Divya review | Ops + KP use in week |
| **P2 — Performance + brand + auto SO** | Rep home, exec KP, rename, FOL SO automation post-meeting | Signed process + pilot |

---

## 9. Acceptance criteria (testable)

### OrderWatch

- [ ] Manufactured / trading / KP / consumer filters return consistent totals with documented formula.
- [ ] Backorder total within agreed tolerance vs Ruben file for same as-of date.
- [ ] Partner brand total within agreed tolerance.
- [ ] Aging report answers Y/N for same-day PO→order, same-day approve, ship vs 24/48h zone.
- [ ] Partner brand SKU drill opens SOs with outlet name in side panel.

### FOL

- [ ] New FOL uses **3-month** metrics only in UI copy and calculations.
- [ ] Cart has **no** unit price / line total for consultants.
- [ ] Channel options are only Visit / Phone / Email.
- [ ] Submit blocked without reason.
- [ ] Approver sees top-N SKU volume table for 3 months.
- [ ] Requester sees stage history and linked SO after approval path.

### PCR

- [ ] UI says **Discount**, not Margin, for sell-to-proposed delta.
- [ ] Current selling price populated from customer-specific resolution.
- [ ] Consultant API response excludes cost/margin/lowest-5.
- [ ] Approver can set **revised price** and requester is notified.
- [ ] Optional attachment works; submit works without it.
- [ ] Lowest 5 prices show customer names for approver.

### CRM

- [ ] UI string “White spot” gone; “Items not ordered” everywhere.
- [ ] Portfolio tab lists customers with aging filters 30/60/90.
- [ ] Opportunity size card navigates to filtered list.
- [ ] Dormant feedback stores contact attempt; primary contact enforced for handoff.

### KP performance home

- [x] Rep sees their own backorder value at risk, open line count, and aging distribution on their consultant detail page (PERF-8, shipped 2026-07-23).
- [x] Rep sees lines resolved in the selected period (count + value), independent of when those lines first went on backorder.
- [ ] Items not ordered, dormant, FOL status, and target-vs-time cards assembled onto the same page as PERF-8 (currently separate pages).
- [ ] Executive drill-down view without full admin clutter (PERF-7).

### Adoption

- [ ] Report: trained users login count by Wednesday target.
- [ ] Shared mailboxes excluded.

---

## 10. Action items from the call (tracking)

| Owner | Action | Due (from call) |
|-------|--------|------------------|
| Titus | Align backorder calc with Ruben | ASAP / same week |
| Titus | Re-sync through 17th; validate ±10% | Same week |
| Titus | Close loop Mayur + Acumatica on shipment/SO bug; keep group copy | Ongoing |
| Titus | Train remaining office teams; everyone signed in by Monday | Next week |
| Titus | Pull login logs for trained users by Wednesday | Wednesday |
| Titus | Joint FOL process meeting: Vignesh, Need, Berna, Susan | Schedule |
| Titus | Document FOL eligibility rules + process | After meeting |
| Titus | Implement FOL/PCR/CRM tweaks from this PRD | Before next review |
| Titus | Walk Divya through CRM | After polish pass |
| Titus | Name shortlist for platform rebrand | P2 |
| Vignesh | Approve VPS migration commercial terms | Decision |
| Vignesh | Next review (target ~Wednesday after) | Confirm |

---

## 11. Open questions (resolve in review)

| # | Question | Options | Owner |
|---|----------|---------|-------|
| Q1 | Exact backorder value formula Ruben uses? | Document in OW-2 | Ruben + Titus |
| Q2 | Aging SLA starts on approve or entry? | §6.3 options | Beatrice + Vignesh |
| Q3 | PCR: Exec-only vs HOD→Exec for KP? | §5.3 / §6.6 | Vignesh |
| Q4 | FOL auto-SO: draft vs full create? | §5.2 | Joint meeting |
| Q5 | Margin denominator: on sell or on cost? | State in PCR help | Finance |
| Q6 | Final product name | Genius vs alternatives | Vignesh + Titus |
| Q7 | Schools list for dormant exclusion | Customer class / flag | Susan + Titus |

---

## 12. Dependencies

| Dependency | Blocks |
|------------|--------|
| Ruben calculation notes / Excel | BO + fill rate sign-off |
| Acumatica Mayur fix | “Stuck in shipping” accuracy |
| Price class / cost fields in ERP | PCR current sell + margin |
| Zoho/contact export | Dormant contact seed |
| VPS approval | Performance / downtime |
| Divya feedback | Meetings field freeze |
| Roles/permissions | Margin redaction, FOL approve, dormant |

---

## 13. Out of scope reminders

- Incentive engine details  
- Full white-spot opportunity CRM (new logo pipeline)  
- Changing Acumatica FOL accounting price policy (1 / 0.7) — finance issue  
- Bulk ops emails R1–R3 (separate notification product)  

---

## 14. Traceability map (transcript → PRD)

| Topic in call | PRD section |
|---------------|-------------|
| ~30M backorder deficit, Ruben calc | §6.1 |
| Manufactured / trading / KP / consumer | §6.1 OW-1 |
| Partner brands 55 vs 20 | §6.1 OW-4 |
| Inventory prediction Nick/Christine | §6.2 |
| Stuck shipping July 1–4, Mayur | §6.3 G-ZN-3, INF-2 |
| Zones, 24/48h, aging PO→approve→ship | §6.3 |
| Partner brand SKU drill, half-page SO | §6.3 ZN-3/4 |
| Training MT, login by Wed | §6.4 |
| FOL 3 months, cart prices off, Other off, reason required | §6.5 |
| Approver volume by SKU | §6.5 FOL-8 |
| SO link after approval | §6.5 FOL-10 |
| Install calendar / tech | §6.5 FOL-11 |
| PCR discount, current sell, cost margin, revised price, upload, lowest 5 | §6.6 |
| Dormant 1074, Coltronics, contacts, primary | §6.7 |
| White spot → items not ordered; 30/60/90; tab | §6.8 |
| Meetings, actions, Divya | §6.9 |
| KP performance home | §6.10 |
| Rename platform Genius | §5.1, §6.11 |
| VPS 6–7.5 | §6.12 |

---

## 15. Next review checklist

Bring to next session with Vignesh:

1. Side-by-side **backorder** number vs Ruben (same date).  
2. FOL create + approve screenshot with **3-month SKU table**, no cart prices.  
3. PCR create as consultant (no margin) + approve as exec (discount + margin + lowest 5 + revised).  
4. **Items not ordered** tab with 30/60/90 opportunity.  
5. Login adoption table for trained users.  
6. Decision log: Q1–Q7 answers.  

---

*This PRD turns the review transcript into buildable scope with explicit options and guardrails. Implementation may split into tickets aligned to phases P0a–P2.*
