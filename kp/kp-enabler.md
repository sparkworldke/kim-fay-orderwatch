# KP Enabler — OrderWatch Roadmap

**Status:** Planning draft  
**Date:** 10 Jul 2026  
**Sources:**
- `kp-project.png` — *KP Tech Enablement Commercial Requirements & Prioritization*
- `kp/KP Workflows and Requirements (1).docx` — KimFay Professional workflows (Apr 2026)
- `kp/HorecaOS_PRD_v1.0.md` — HorecaOS PRD v1.0 (field / FOL / PCR detail)
- Current OrderWatch codebase (Acumatica, fill rate, consultants, team RBAC, inventory)

**Goal:** Enable **Kimfay Professional (KP)** teams inside OrderWatch with field, CRM, sales, and process workflows — without building a disconnected second product — while reusing what already works (orders, fill rate, backorders, consultants, Acumatica, team org tree).

---

## 1. Vision

OrderWatch becomes the **single control tower** for KP commercial operations:

| Layer | What KP gets |
|---|---|
| **Visibility** | KP-scoped customers, orders, fill rate, aging, FOL assets, visits |
| **Execution** | Journey plans, interaction logging, order follow-ups, collection prompts |
| **Governance** | Price change + FOL approval chains with audit |
| **Intelligence** | Whitespot / cohort prompts, scorecarding, RAAS (later) |

Field reps may still need a **mobile companion** later (HorecaOS Flutter path). **Phase 1–2 prioritise web** so C-suite / Executive / Admin / KP managers get value immediately on the existing stack (React + Laravel + Acumatica).

---

## 2. Who can see KP modules (permissions)

### 2.1 Access policy (hard requirement)

| Audience | Access | Data scope |
|---|---|---|
| **Administrator** | Full KP suite + config | Org-wide |
| **Executive** (org_level `executive`) | Full KP suite (read + approve where configured) | Org-wide |
| **C-suite** (org_level `c_suite`) | Full KP suite (read + high-threshold approvals) | Org-wide |
| **KP team** | KP suite only (or KP + shared ops menus) | **KP portfolio only** |
| Other departments (MT/GT/Production/etc.) | **No** KP-only menus by default | Unchanged |

> **Rule:** API enforces scope. UI menu hide is not enough.

### 2.2 How this maps to OrderWatch today

| Existing building block | Use for KP |
|---|---|
| `departments.slug = kp` | KP department flag |
| `class_prefix_map`: `KP` → `kp` | Customer portfolio from Acumatica `customer_class` |
| `sector_scopes` includes `KP` | Cross-sector assignments |
| `org_level`: executive / c_suite / hod / brandsops / sales | Hierarchy for approvals & subtree visibility |
| `UserCapabilitiesService` + `hidden_menus_by_department` | Menu gating |
| `OrgScopeService` | Customer / order / consultant data scope |
| `Sales Consultant` + `rep_code` | Field rep identity tied to Acumatica salesperson |
| Fill rate **segment = KP** | Already classifies Kimfay Professional vs CS |

### 2.3 Proposed capability flags (new)

Add fine-grained permissions (seed + role attach), not only menu hide:

| Permission | Admin | Executive | C-suite | KP HOD | KP Sales / Consultant |
|---|:---:|:---:|:---:|:---:|:---:|
| `kp.view` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `kp.field.execute` (visits, interactions) | ✓ | — | — | ✓ | ✓ |
| `kp.crm.manage` | ✓ | ✓ | ✓ | ✓ | limited |
| `kp.sales.prompts` | ✓ | ✓ | ✓ | ✓ | ✓ |
| `kp.approve.price` | ✓ | ✓ | ✓ | threshold | — |
| `kp.approve.fol` | ✓ | ✓ | ✓ | threshold | — |
| `kp.config` | ✓ | — | — | — | — |
| `kp.export` | ✓ | ✓ | ✓ | ✓ | own data |

**Menu slug proposals:** `kp-home`, `kp-field`, `kp-crm`, `kp-sales`, `kp-process`, `kp-fol`, `kp-reports`.

Register in `UserCapabilitiesService::allMenuSlugs()` and `nav-permissions.ts`.

---

## 3. What OrderWatch already covers (reuse, don’t rebuild)

| Need | Status in OrderWatch | Gap for KP |
|---|---|---|
| Orders / SO visibility | Strong (`/app/orders`, customer orders) | KP-only default filter; LPO field UX |
| Fill rate / undership | Strong + KP segment | Surface on KP home as KPI |
| Backorders / stock shortfall | Strong | Link from visit / order follow-up |
| Inventory + stockout prediction | Strong | FOL volume vs commitment needs purchase trends |
| Sales consultants + rep scope | Strong | Field visit attach to rep |
| Team org + departments | Strong | KP HOD subtree approvals |
| Customer feed / mailbox | Strong (CS-heavy) | CRM notes vs email import split |
| Acumatica sync | Strong | Writes (price, FOL asset) not in scope yet |
| Daily executive report | Partial KP/CS split | KP team daily field adherence report |

**Principle:** KP Enabler **extends** OrderWatch with new modules and deep-links into existing order / inventory / fill-rate screens.

---

## 4. Commercial requirements matrix

From `kp-project.png` (Priority **1** = P0, **2** = P1, **3** = P2/backlog).  
Delivery phases map to §6.

### 4.1 Team Management

| Requirement | Pri | OrderWatch approach | Phase |
|---|:---:|---|:---:|
| Geo-fenced client visitations | **1** | Visit check-in with GPS + client geofence radius; store lat/lng/accuracy | 1 |
| Route planning & adherence report — management daily summary | **1** | PJP day plan + adherence % vs planned sequence; daily email / dashboard for HOD+ | 1 |
| Time spent report — transit vs at client | **3** | Dwell vs travel from GPS breadcrumb / check-in pairs | 3 |

### 4.2 Client Management (CRM)

| Requirement | Pri | OrderWatch approach | Phase |
|---|:---:|---|:---:|
| Log interaction type — Phone / Email / Meeting | **1** | `kp_interactions` polymorphic log | 1 |
| Log reason for interaction | **1** | Reason taxonomy (configurable) | 1 |
| Guided questionnaire for customer interactions | **1** | Template questions (4 defaults from workflow doc) | 1 |
| Scorecarding interaction — AI enabled | **3** | Score later via AI keys module | 4 |
| Prompt outreach based on aging | **2** | Jobs from AR aging buckets → consultant tasks | 2 |
| Follow-up prompts on agreed timelines with customer | **1** | Next-action date on interaction; task queue | 1 |
| Account opening process tracking (company-wide) | **2** | Pipeline stages (Lead → Docs → Credit → Live) | 2 |
| Price review request and management | **2** | PCR workflow + Acumatica write-back (later) | 3 |
| Customer payment aging and prompting | **2** | Aging dashboard + promise-to-pay | 2 |
| One-click download of account statement | **3** | Acumatica statement export / PDF | 3 |
| Alerts on accounts put on hold — realtime | **2** | Poll/webhook credit hold → push notification | 2 |
| CSI integration — turnaround & fulfilment levels | **3** | CSI feed or OrderWatch fill-rate proxy first | 4 |
| Competitor survey / whitespot data building | **2** | Structured survey forms + whitespot tags | 2 |
| CRM → Outlook integration | **3** | Optional: Graph calendar / email log link | 4 |

### 4.3 Sales Management

| Requirement | Pri | OrderWatch approach | Phase |
|---|:---:|---|:---:|
| Prompt follow-up for orders based on order cycles | **1** | Per-customer cadence from SO history | 1–2 |
| Push CRM items not billed for month by customer → consultant | **1** | Month close job: ordered vs billed gap | 2 |
| Flag delta on purchase volumes vs average | **3** | Cohort analytics | 3 |
| Debt collection prompts | **2** | Same engine as aging prompts | 2 |
| Incentive calculation automation | **3** | Rules engine + export | 4 |
| Whitespot prompting — customer cohorts | **2** | Cross-sell matrix | 3 |
| Whitespot prompting — product cohorts | **2** | SKU gap vs peer set | 3 |

### 4.4 Process Management

| Requirement | Pri | OrderWatch approach | Phase |
|---|:---:|---|:---:|
| RAAS process automation | **3** | Spec with KP ops; placeholder module | 4 |
| **Free On Loan (FOL)** + Acumatica purchase trends | **1** | FOL request → approve → asset register; volume vs commitment from SO | **1–3** |
| Price review process automation with margin & thresholds | **2** | PCR with server-side margin + authority matrix | 3 |

Highlighted commercial priority: **FOL is P0 for process** even if full asset lifecycle is multi-phase.

---

## 5. Core workflow modules (from KP workflows + HorecaOS)

### 5.1 Meeting management & PJP (Field)

**Intent:** ≥ 4 verified meetings / rep / day.

| Feature | Notes |
|---|---|
| Automated PJP | Daily visit list per rep from frequency rules + priority |
| GPS check-in / geofence | Mandatory start; dwell rules later |
| Interaction type + reason | Phone / Email / Meeting |
| Guided questionnaire | 4 structured questions (text / photo later) |
| Action items | Carry-forward from prior visits |
| Daily adherence report | Planned vs done; for HOD / Executive / C-suite |

**Guardrails (from PRD):** consent for location; working-hours only; manager-only visibility of GPS; audit timestamps.

### 5.2 Order management (Commercial)

Reuse OrderWatch orders; add **KP-specific prompts**:

- Order cycle follow-up (no order in N days vs usual cycle)
- Fulfilment visibility deep-link to existing fill rate / backorders
- Account-on-hold block signal from Acumatica
- LPO reference capture on suggested / matched orders

### 5.3 Payment & collections

- Aging buckets (0–30 / 30–60 / 60–90 / 90+)
- Promise-to-pay logging
- Escalation to HOD after configurable days
- Statement download (Phase 3)

**Data source decision (open):** Acumatica AR open invoices vs finance export. Prefer Acumatica if endpoint available.

### 5.4 Price change request (PCR)

Steps: Customer request → Rep submit → Manager review (margin) → Threshold escalate → ERP write-back → Notify.

OrderWatch holds **workflow + audit**; Acumatica remains **price master**.

### 5.5 Free on Loan (FOL) dispensers

Steps: Request → volume commitment → credit / asset check → approve → install + serial → monthly volume vs commitment → corrective / recall.

**Acumatica integration:** purchase trends from SO lines for commitment SKUs; asset master may live in OrderWatch first if Acumatica asset module is thin.

---

## 6. Delivery roadmap (OrderWatch-native)

### Phase 0 — Foundations (1–2 weeks)

| Work item | Detail |
|---|---|
| Permissions & menus | `kp.*` permissions; menus visible to Admin / Executive / C-suite / KP dept |
| KP home shell | `/app/kp` dashboard: KP fill rate, open orders, visits today, FOL flags |
| Scope middleware | `EnsureKpAccess` + `OrgScopeService` filter `customer_class LIKE 'KP%'` |
| Audit events | All KP mutations → existing audit logger |
| Config | Geofence radius, visit target/day, aging thresholds, approval KES thresholds |

**Exit criteria:** Non-KP users cannot open `/app/kp/*`; KP HOD only sees KP portfolio.

---

### Phase 1 — Field + interaction CRM (P0) — ~6–8 weeks

**Pillars:** Team Management (P1 items) + Client interaction log + FOL **request intake**

| Epic | Deliverables |
|---|---|
| **A. Visits & geofence** | Customers: lat/lng (manual pin or geocode); check-in/out; geofence pass/fail; daily PJP list |
| **B. Interactions** | Phone/Email/Meeting + reason + questionnaire + next follow-up date |
| **C. Route adherence report** | Planned sequence vs actual check-ins; management daily summary card + optional email |
| **D. Order cycle prompts** | “Due for order” list for consultants from SO history |
| **E. FOL request (intake only)** | Create FOL request with volume commitment; status = submitted/pending; list for HOD |

**UI routes (web):**
- `/app/kp` — KP Home  
- `/app/kp/field` — Today’s PJP, check-in  
- `/app/kp/interactions` — CRM log & tasks  
- `/app/kp/fol` — FOL requests (intake)  
- Deep links → existing `/app/orders`, `/app/fill-rate?segment=KP`, `/app/customers`

**Exit criteria:** Geo visit logging live for pilot KP reps; HOD daily adherence view; FOL requests creatable.

---

### Phase 2 — Sales prompts + payments (P1) — ~6–8 weeks

| Epic | Deliverables |
|---|---|
| **F. Not billed this month** | Job: ordered vs invoiced / open SO value → consultant queue |
| **G. Payment aging** | Dashboard + promises + collection prompts |
| **H. Account on hold alerts** | Realtime-ish (cron ≤ 15 min) from Acumatica credit status |
| **I. Account opening tracker** | Simple stage board (company-wide visibility for Admin/Exec/C-suite) |
| **J. Competitor / whitespot capture** | Survey form + tags (who supplies whitespot SKUs) |
| **K. Debt collection prompts** | Task generation from aging |

**Exit criteria:** Aging matches finance sample; hold alerts fire; monthly unbilled push used by KP HOD.

---

### Phase 3 — Process automation (PCR + FOL lifecycle + depth) — ~8–10 weeks

| Epic | Deliverables |
|---|---|
| **L. PCR full workflow** | Margin calc, thresholds, escalate, notify; Acumatica price staging write-back |
| **M. FOL full lifecycle** | See **`kp/fol-requests.md`**: approvals, SO match, monthly issued + anomaly reports, install calendar + multi-role Technician Manager + job cards, then asset/recall |
| **N. Statement download** | One-click account statement |
| **O. Time spent transit vs client** | From GPS segments |
| **P. Volume delta flags** | Purchase vs average |

**Exit criteria:** 100% PCR/FOL decisions in audit log; FOL volume review job accurate vs Acumatica SO.

---

### Phase 4 — Intelligence & integrations (P2) — ~8+ weeks

| Epic | Deliverables |
|---|---|
| **Q. AI interaction scorecarding** | Uses existing AI keys infrastructure |
| **R. Whitespot cohort prompts** | Customer + product cohorts |
| **S. Incentive engine** | Configurable rules |
| **T. CSI integration** | Or certified fill-rate proxy with SLA labels |
| **U. Outlook / Graph CRM link** | Optional calendar + email association |
| **V. RAAS automation** | After process definition workshop |
| **W. Mobile (Flutter) MVP** | Only if field offline/GPS demands exceed web+PWA |

---

## 7. Architecture (fit with monorepo)

```
┌─────────────────────────────────────────────────────────────┐
│  Frontend (TanStack Start / Cloudflare)                     │
│  /app/kp/*  · gated by capabilities.kp.view                 │
└───────────────────────────┬─────────────────────────────────┘
                            │ Sanctum API
┌───────────────────────────▼─────────────────────────────────┐
│  Laravel API                                                │
│  Kp/* Controllers · KpScope · ApprovalService · FolService  │
│  Reuse: OrgScope, FillRate, Orders, Inventory, Notifications│
└───────┬───────────────────────────────┬─────────────────────┘
        │                               │
        ▼                               ▼
  MySQL (new kp_* tables)         Acumatica (SO, AR, stock,
                                  customer class, optional price write)
```

### 7.1 Suggested new tables (Phase 1)

| Table | Purpose |
|---|---|
| `kp_customer_sites` | lat/lng, geofence_m, visit frequency |
| `kp_pjps` / `kp_pjp_stops` | Daily planned journey |
| `kp_visits` | Check-in/out, GPS, dwell, adherence |
| `kp_interactions` | Type, reason, questionnaire JSON, next_action_at |
| `kp_tasks` | Prompts queue (order cycle, aging, FOL review) |
| `kp_fol_requests` | FOL intake + status |
| `kp_fol_assets` | Phase 3 register |
| `kp_price_change_requests` | Phase 3 PCR |
| `kp_account_openings` | Phase 2 pipeline |

### 7.2 Integration points

| System | Direction | Use |
|---|---|---|
| Acumatica customers | In | KP class, credit hold, contacts |
| Acumatica SO / lines | In | Order cycles, FOL volume, fill rate |
| Acumatica inventory | In | Stock before promise |
| Acumatica AR | In (if available) | Aging, statements |
| Acumatica prices | Out (Phase 3) | PCR write-back staging |
| OrderWatch notifications | Out | Tasks, holds, FOL SLA |
| Daily report | Out | Optional KP field section for Exec |

---

## 8. UX sketch — KP Home (web)

Aligned with existing OrderWatch cards + tables (not a greenfield Stripe rebuild, but can borrow spacing/status patterns from HorecaOS PRD).

```
KP Home
├── Filters: date range · rep (HOD+) · customer search
├── KPI row: Visits today · Adherence % · Due orders · FOL pending · AR 60+
├── My tasks (prompts)
├── Today’s PJP (map optional later)
└── Shortcuts → Fill rate (KP) · Backorders · Customers · FOL
```

**Mobile later:** PWA-first for check-in; Flutter only if offline queue is mandatory.

---

## 9. Permissions implementation checklist

1. Seed permissions `kp.view`, `kp.field.execute`, …
2. Attach to roles:
   - Administrator → all
   - Executive → view + approve + export
   - Users with `org_level in (c_suite, executive)` → same as Executive for KP
   - KP department users → view + field + prompts; HOD gets approve below threshold
3. `hidden_menus_by_department`: only **show** KP menus when `department.slug === 'kp'` **or** executive roles — invert current “hide admin only” pattern with an **allowlist** for KP routes
4. Middleware on all `api/kp/*` routes
5. Every list query: `customer_class LIKE 'KP%'` **or** explicit customer assignment union for consultants
6. Feature tests: CS agent / MT user **403**; KP rep scoped; Admin full

---

## 10. Risks & decisions

| Topic | Risk | Recommendation |
|---|---|---|
| AR aging source | No AR endpoint → blocked Phase 2 payments | Confirm Acumatica AR inquiry in week 1 of Phase 2 |
| GPS fraud | Fake check-ins | Phase 1: geofence + accuracy; Phase 3: dwell + speed checks |
| FOL vs Acumatica assets | Asset module may not exist | OrderWatch owns asset register; Acumatica owns sales volume |
| Mobile offline | Web-only check-in fails in field | Pilot web; budget Flutter if pilot fails offline |
| Scope creep (full HorecaOS) | 36-week platform vs OrderWatch slice | **Stay on this roadmap**; multi-tenant SaaS out of scope |
| RAAS undefined | P3 with no process map | Workshop before Phase 4 |
| Duplicate CRM vs mailbox | Confusion | Interactions = structured KP CRM; Mailbox stays CS email ops |

### Open questions (need KP sign-off)

1. Who is KP HOD and list of pilot consultants / rep codes?  
2. Geofence default radius (e.g. 150 m)?  
3. Minimum meetings/day still **4**?  
4. PCR approval thresholds (KES) per level?  
5. FOL minimum monthly commitment currency and SKU families?  
6. Is account statement from Acumatica printable today?  
7. Should C-suite see **all sectors** on KP home or KP-only widgets on executive dashboard?

---

## 11. Success metrics (6 months)

| Metric | Target |
|---|---|
| Meetings / rep / day (verified) | ≥ 4.0 |
| Route adherence (planned visits completed) | ≥ 85% |
| Order cycle prompt response (actioned in 48h) | ≥ 70% |
| FOL requests with full audit trail | 100% |
| AR DSO (credit KP customers) | −20% vs baseline |
| Unauthorized access test failures | 0 |

---

## 12. Suggested first implementation PR stack

| PR | Scope |
|---|---|
| **PR-KP-0** | Permissions, menus, empty `/app/kp`, scope middleware, tests |
| **PR-KP-1** | Sites + visits + geofence check-in API/UI |
| **PR-KP-2** | Interactions + questionnaire + follow-up tasks |
| **PR-KP-3** | PJP generation + daily adherence report |
| **PR-KP-4** | Order-cycle task generator + KP home KPIs |
| **PR-KP-5** | FOL request intake + HOD list |
| **PR-KP-6** | Payment aging (if AR available) + hold alerts |
| **PR-KP-7** | PCR + FOL lifecycle + Acumatica hooks |

---

## 13. Document map

| File | Role |
|---|---|
| `kp/kp-enabler.md` | **This roadmap** — OrderWatch inclusion plan |
| `kp/fol-requests.md` | **FOL PRD** — process, guardrails, AC, reporting, install calendar, tech manager multi-role |
| `kp/fol/*.png` | Current Zoho FOL form + notification evidence |
| `kp/HorecaOS_PRD_v1.0.md` | Deep product detail / mobile / multi-tenant vision |
| `kp/KP Workflows and Requirements (1).docx` | Workflow narrative + flowchart references |
| `kp-project.png` | Commercial prioritization (P1/P2/P3) |
| `docs/fill-rate-user-guide.md` | Existing KP vs CS definition |
| `backend/config/departments.php` | KP department + class prefix |

---

## 14. Summary

**Do this next:** Phase 0 + Phase 1 — permissions, KP home, geofenced visits, interaction CRM, order-cycle prompts, FOL intake.

**Who sees it:** Administrators, Executives, C-suite, and KP department users only — with **API-enforced** KP data scope for the KP team.

**Reuse:** Orders, fill rate (segment KP), backorders, inventory, sales consultants, org tree, notifications, Acumatica sync.

**Defer:** Full Flutter offline app, RAAS, AI scorecards, CSI, Outlook CRM, multi-tenant HorecaOS — until Phase 3–4 after field P0 is proven.

---

*Owner: Product + Engineering · Reviewers: KP HOD, Sales Ops, Finance (AR), IT (Acumatica)*
