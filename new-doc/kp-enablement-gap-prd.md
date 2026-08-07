# KP Enablement — Gap PRD (Commercial Requirements × Built)

**Product:** Kim-Fay Sight · KP Operations  
**Source requirements:** `KP TECH ENABLEMENT COMMERCIAL REQUIREMENTS & PRIORITIZATION.xlsx`  
**Intent:** Zoho-like **KP enablement** for consultants — only what commercial asked for, mapped to what is already in Sight  
**Status:** Gap analysis draft (not a full CRM rebuild)  
**Date:** 2026-08-07  
**Related:** `kp-dashboard-prd.md` · `kp-view.md` · `fol-user-flow-so-link.md`  

---

## 1. Executive snapshot

Commercial wants a **KP field CRM + sales process layer** (visits, interactions, prompts, FOL, price, incentives).  
Sight already has a **KP Operations** shell: portfolio, CRM lists, meetings, FOL, price-change workflow, commissions, dormant / items-not-ordered.

| Status | Count (of 27 sheet rows) | Meaning |
|--------|--------------------------|---------|
| **Done** | ~6 | Usable in production for the intent |
| **Partial** | ~10 | Core path exists; missing depth, prompts, or automation |
| **Pending** | ~11 | Not built (or only distant related feature) |

**North star (this PRD):** Close **Priority 1 partials** first so consultants get a daily loop (who to see → log interaction → order/FOL follow-up → SO), not a Zoho clone of every Priority 3.

---

## 2. What KP Operations already ships

| Area | Route / module | Role today |
|------|----------------|------------|
| **My Portfolio** | `/app/accounts` | Book, KPIs, buying/dormant signals, backorders, priorities (sales language) |
| **Accounts / classes** | Portfolio + Contract Cleaners | KP customer book by class |
| **Dormant** | `/app/kp/dormant` | Idle accounts, contact attempts, Calltronix handoff |
| **Items not ordered** | `/app/kp/items-not-ordered` | Cycle-break / not billed signals by SKU–customer |
| **Meetings** | `/app/kp/meetings` | Plan/complete visits, purpose, B2B fields, follow-up date, actions, adherence metrics |
| **Calendar** | `/app/kp/calendar` | Local + optional Outlook calendar read |
| **FOL** | `/app/kp/fol/*` | Request → approval → SO create/link → install calendar |
| **Price change** | `/app/price-change-requests` | Multi-stage PCR, margin snapshot, counter, ERP apply path |
| **Commissions** | `/app/kp/commissions` | Statement calc / review (rules-based) |
| **Contacts** | Customer contacts card | Primary contacts on account |

This is **not** full Zoho CRM (no geo-fence visits, no universal interaction log, no account-opening tracker, no competitor whitespot engine).

---

## 3. Requirements matrix (source sheet)

**Priority:** 1 = must · 2 = important · 3 = later  
**Status:** Done · Partial · Pending  

### 3.1 Team management

| # | Requirement (sheet) | P | Status | Today | Gap |
|---|---------------------|---|--------|-------|-----|
| T1 | Geo-fenced client visitations | 1 | **Pending** | Meetings have location text + mode (virtual/physical); **no GPS fence** | Mobile check-in with lat/long, fence radius, proof of visit |
| T2 | Route planning + adherence report — management daily summary | 1 | **Partial** | Meetings: planned vs completed, adherence %, purpose split; **no route sequence** | Daily PJP/route plan, ordered stops, management daily digest email/dashboard |
| T3 | Time spent — transit vs at client | 3 | **Pending** | Meeting start/end only | Check-in/out timestamps + travel vs on-site split |

### 3.2 Client management (CRM)

| # | Requirement (sheet) | P | Status | Today | Gap |
|---|---------------------|---|--------|-------|-----|
| C1 | Log interaction type — Phone / Email / Meeting | 1 | **Partial** | **Meetings** full; dormant **contact attempts**; contacts card — **no unified interaction log** | One timeline: Phone · Email · Meeting · Visit with type required |
| C2 | Log reason for interaction | 1 | **Partial** | Meeting **purpose** + outcome; dormant **outcome** | Required reason catalog on all interaction types |
| C3 | Guided questionnaire for interaction details | 1 | **Partial** | Meeting **B2B details** questionnaire (stakeholder, need, competitor, commitment…) | Extend templates by purpose/type; force complete on close |
| C4 | Scorecarding interaction — AI enabled | 3 | **Pending** | — | AI quality score on notes/questionnaire (later) |
| C5 | Prompt outreach based on aging | 2 | **Partial** | Portfolio **at risk / dormant** + dormant list + priorities | Configurable aging rules + push/in-app prompts (not only list) |
| C6 | Follow-up prompting on agreed timelines with customer | 1 | **Partial** | Meeting **follow_up_date** required (or no-follow-up reason); calendar | Daily “due follow-ups” queue + reminders |
| C7 | Account opening process tracking (company-wide) | 2 | **Pending** | Customer master exists; **no open-to-active workflow** | Pipeline stages, owners, SLA for new accounts |
| C8 | Price review request and management | 2 | **Done** (core) | Price Change Requests: stages, margin snapshot, counter, ERP apply | Polish: KP-only defaults, alerts, threshold policy UI |
| C9 | Customer payment aging + prompting | 2 | **Pending** | No AR aging in KP CRM surfaces | Acumatica AR aging feed + consultant prompts |
| C10 | One-click account statement download | 3 | **Pending** | — | Statement PDF/export from ERP or generated |
| C11 | Real-time alerts — accounts put on hold | 2 | **Partial** | On-hold **counts** / status on portfolio & masters | Push/realtime alert when status → On Hold |
| C12 | CSI integration — TAT & fulfilment alerts | 3 | **Pending** | Backorders / fill-rate elsewhere (ops) | CSI-specific TAT rules + consultant alert |
| C13 | Competitor survey + whitespot data | 2 | **Partial** | Meeting B2B field `competitor_presence` free text | Structured survey, product whitespots, searchable library |
| C14 | CRM ↔ Outlook integration | 3 | **Partial** | Calendar **read** Outlook events when mailbox connected | Two-way meetings, email activity log (later) |

### 3.3 Sales management

| # | Requirement (sheet) | P | Status | Today | Gap |
|---|---------------------|---|--------|-------|-----|
| S1 | Prompt follow-up for orders by **order cycles** | 1 | **Partial** | Items not ordered uses **avg interval / overdue**; portfolio priorities | Explicit cycle config per customer/SKU + push prompts |
| S2 | Push CRM: items not billed this month by customer → consultant | 1 | **Done** (core) | **Items Not Ordered** + portfolio items-not-ordered | Optional: “this month only” billing filter + push notify |
| S3 | Flag delta purchase volume vs average | 3 | **Pending** | Trends on FOL consumables / SO history not productized as flag | Volume Δ vs 3/6m avg badge on account |
| S4 | Debt collection prompts | 2 | **Pending** | — | Needs AR aging (C9) then prompts |
| S5 | Incentive calculation automation | 3 | **Partial** | **Commissions** statements + rules + review | Full incentive schemes, targets UX, autopay path |
| S6 | White spot prompting — customer cohorts | 2 | **Pending** | — | Cohort rules + prompts |
| S7 | White spot prompting — product cohorts | 2 | **Pending** | Partial free-text competitor only | Product-gap engine from order history + catalog |

### 3.4 Process management

| # | Requirement (sheet) | P | Status | Today | Gap |
|---|---------------------|---|--------|-------|-----|
| P1 | RAAS process automation | 3 | **Pending** | — | Define RAAS stages with business first |
| P2 | FOL process + Acumatica purchase trends | 1 | **Done** (strong) | FOL workflow, SO create/link, **consumables 3m/6m metrics from system SO** | Harden scope (attached book only per `kp-view.md`); install ops polish |
| P3 | Price review automation with margin + thresholds | 2 | **Partial / near Done** | PCR has **margin_pct / margin_kes snapshots**, approval stages | Configurable **threshold gates** (auto-route by margin band) |

---

## 4. Priority-1 focus (what to finish for “enablement”)

Commercial Priority **1** items only:

| ID | Requirement | Status | Enablement action |
|----|-------------|--------|-------------------|
| T1 | Geo-fenced visits | Pending | **V2** mobile — after interaction model exists |
| T2 | Route plan + daily management summary | Partial | **V1:** daily summary of planned/completed/missed + top overdue follow-ups; route sequence later |
| C1–C3 | Interaction type / reason / questionnaire | Partial | **V1:** unify **Activity log** (Phone/Email/Meeting) reusing meeting purpose + B2B templates |
| C6 | Follow-up prompts | Partial | **V1:** “Follow-ups due today” on My Portfolio + Meetings |
| S1 | Order-cycle prompts | Partial | **V1:** wire Items-not-ordered into Portfolio **Today’s Priorities** + notify |
| S2 | Items not billed → consultant | Done | Keep; minor filters |
| P2 | FOL + purchase trends | Done | Fix portfolio scope bugs; SO link reliability |

**V1 success:** Consultant opens Sight and gets **priorities + due follow-ups + items not ordered + FOL/meetings**, logs **Phone/Meeting** with reason, without leaving KP Operations.

---

## 5. Target product shape (not full Zoho)

```text
KP Operations (enablement loop)
├── My Portfolio          ← pulse + priorities + due follow-ups
├── Activities (new/thin) ← Phone | Email | Meeting | Visit  [unify C1–C3, C6]
├── Meetings / Calendar   ← keep; feed Activities
├── Dormant / Items NO    ← aging + cycle prompts [C5, S1, S2]
├── FOL                   ← process [P2]
├── Price changes         ← [C8, P3]
└── Commissions           ← [S5] later polish
```

**Explicit non-goals for next 1–2 sprints:** full geo-fence mobile app, AR statement PDF, CSI integration, RAAS, AI scorecards, full competitor BI — unless commercial re-prioritizes.

---

## 6. Suggested build slices

| Slice | Delivers | Sheet IDs | Effort sense |
|-------|----------|-----------|--------------|
| **A — Activity spine** | Unified log Phone/Email/Meeting; reason required; link customer; list on account | C1, C2, C3 | M |
| **B — Follow-up queue** | Due follow-ups (meeting + activity) on Portfolio home | C6, C5 | S–M |
| **C — Sales prompts** | Items-not-ordered + cycle overdue into Priorities; optional email/in-app | S1, S2 | S–M |
| **D — Management daily** | HOD summary: visits planned/done, missed, open FOLs, overdue follow-ups | T2 | M |
| **E — PCR thresholds** | Margin band → stage rules | P3 | S |
| **F — Geo / route (later)** | Check-in fence + ordered route | T1, T2, T3 | L |
| **G — Money prompts (later)** | AR aging, hold alerts realtime, debt prompts | C9, C11, S4 | L (ERP) |
| **H — Whitespace (later)** | Structured competitor + cohort prompts | C13, S6, S7 | L |

---

## 7. Acceptance (enablement V1)

- [ ] Every Priority **1** item is **Done** or has an explicit **V2** ticket (T1 geo = V2 OK).  
- [ ] Consultant can log **Phone** and **Meeting** with **type + reason** on a portfolio customer.  
- [ ] **Follow-ups due today** visible without opening Meetings first.  
- [ ] **Items not ordered / cycle break** appears in daily priorities for the book.  
- [ ] FOL still shows **purchase trend metrics** from Acumatica SO; create/link SO works.  
- [ ] Price change still supports margin snapshot + approval.  
- [ ] HoD can open a **daily adherence / activity summary** (even if route order is not geo yet).  
- [ ] No requirement marked Done unless a KP user can complete the job in UI without admin SQL.

---

## 8. Open questions (for commercial)

| # | Question | Why it blocks |
|---|----------|---------------|
| Q1 | Is **Meeting** the only field visit for now, or is **geo check-in** mandatory for P1? | T1 vs T2 sequencing |
| Q2 | **Order cycle** = Items-not-ordered logic, or fixed days per customer class? | S1 rules |
| Q3 | **RAAS** process owner and stages? | P1 cannot start without definition |
| Q4 | AR aging: live Acumatica API or nightly file? | C9, S4 |
| Q5 | “Zoho-like” = activities + prompts + FOL, or also quotes/pipeline? | Scope control |

---

## 9. One-page scoreboard

| Pillar | Done | Partial | Pending |
|--------|------|---------|---------|
| Team management | 0 | 1 (T2) | 2 (T1, T3) |
| Client CRM | 1 (C8) | 6 | 5 |
| Sales management | 1 (S2) | 2 (S1, S5) | 4 |
| Process | 1 (P2) | 1 (P3) | 1 (P1) |

**Read:** Strongest on **FOL + price review + items not ordered**. Weakest on **field geo, AR/debt, whitespace, account opening**. Fastest commercial win = **Activity spine + follow-up queue + portfolio prompts** (Slices A–C).

---

## 10. Source trace

| Artifact | Use |
|----------|-----|
| Excel Sheet1 | 27 requirements, pillars, priority 1–3 |
| KP Operations sidebar | Live nav: Portfolio, CRM children, FOL, PCR, Commissions |
| Code anchors | `useKpCrm`, `KpMeetingsController`, `KpItemsNotOrderedController`, `KpDormantCustomersController`, `Fol*`, `PriceChangeRequest*`, `Commission*`, `SalesPortfolioService` |
