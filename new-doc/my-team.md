# My Team — Decision PRD (tight)

**Product:** Kim-Fay Sight  
**Feature:** My Team / team portfolio (HOD & manager)  
**Status:** Decision draft  
**Primary surface today:** My Portfolio → toggle **My team** (`/app/accounts` team mode; also SI “My Team”)  
**Screenshot:** `hodMy-Portfolio-Kim-Fay-Sight-08-06-2026_12_38_PM.png` (impersonating Purity Nduku Kioko)

---

## 1. One-sentence goal

A manager opens **My Team** and in **&lt; 5 seconds** knows **who is off-track, why it matters (KES / customers), and the next action** — without opening each rep’s full book.

---

## 2. What the screenshot shows (baseline)

| UI element | Observed |
|---|---|
| Mode | **My team** (vs My book) |
| Scope badge | Team Portfolio · Rep P496 |
| Story | 1514 buying · 1341 dormant · revenue MTD · **no target** · huge backorder line count |
| KPI row | Customers, Actively buying, Dormant, Revenue MTD, Target vs pace (not set), My orders, Backorders, Fill rate/predicted |
| Lists | Top customers · Early warning (at risk) |
| Tabs | Accounts · Orders · Backorders · Items not ordered |
| **Rep list** | Expandable rows: name · emp/rep code · accounts · active · dormant · revenue MTD · **No target set · 19% time gone** |

**Impersonation:** Admin viewing as Purity (shown as Customer Service Agent) — hierarchy/role must still match real HOD of **Modern Trade**, not a random CSA tree.

---

## 3. Problems to fix (from live UI)

| # | Issue | Decision impact |
|---|---|---|
| P1 | **No targets** on team or reps → “Target vs pace” useless | Targets are P0 for My Team value |
| P2 | **Fill rate / predicted** mixed into sales portfolio | Move off primary sales KPI row (align portfolio PRD) |
| P3 | Team KPIs are **sums only** — no who is red/amber | Need reportee status column + sort |
| P4 | Every row says **No target · 19% time gone** — noise | Hide pace % until target exists; show “Assign target” once |
| P5 | **Org tree risk** — wrong manager sees wrong team (CSV GT→Purity historically) | Scope = `reports_to` tree + channel rules (MT vs GT) |
| P6 | Dense table on mobile | Cards per rep (see `mobile-phone.md`) |
| P7 | No recommended **management action** per rep | Add one next action string |

---

## 4. Users & scope (decide once)

| Viewer | Sees |
|---|---|
| **HOD / manager** | Direct + configurable indirect reportees under them |
| **CCO / Executive** | Drill into a manager then their team (or full commercial tree) |
| **Rep** | Not My Team; only **My book** |
| **Impersonation** | Same as target user; banner already present |

**Tree source of truth:** `users.reports_to_user_id` (+ department).  
**Channel guards (commercial):**

| Head | Team channel |
|---|---|
| Purity (MT) | MT1 / MT2 only — **not** GT |
| Steve Ananda (`XGT001`, salesstrategy@) | **GT only** |
| Susan (KP) | KP portfolio tree |
| Anne (Partner Brands) | Brand-scoped team (later) |

---

## 5. V1 scope (ship) vs later

### V1 — Sales / commercial My Team only

Build on **current My Portfolio team accordion**.

| In V1 | Out of V1 |
|---|---|
| Period + compare (already) | Cross-department KPI config engine |
| Team summary KPIs (sales) | Full “management actions” CRM module |
| Reportee table/cards with status | Production/HR/Finance templates |
| Sort by revenue, dormant, status | Forecast pipeline engine |
| Expand rep → mini book (accounts KPIs already) | Coaching workflow + audits |
| Link: assign target, open dormant, backorders | Weighted multi-KPI score |
| Empty target as **exception**, not fake pace | Executive company page (see `executive-view.md`) |

### Later (reuse layout)

Same shell for CS / Production / etc. via KPI templates — **not** required to launch commercial My Team.

---

## 6. Decisions (closed)

| # | Topic | Decision |
|---|---|---|
| D1 | Primary sales metric | **Revenue MTD (ordered value)** + **dormant / at-risk counts** |
| D2 | Target | Monthly target per rep (commissions / target table). No target → status **No target** (actionable), **do not** show misleading “time gone” alone |
| D3 | Pace status | Only if target &gt; 0: Ahead / On track / At risk / Off track (same bands as portfolio PRD) |
| D4 | Primary KPI cards (team) | **4 only:** Revenue MTD · Target progress (team rollup) · Reportees needing attention · Backorder KES at risk |
| D5 | Drop from primary row | Fill rate / predicted (link under secondary or Production) |
| D6 | “Needing attention” | Rep is At risk / Off track **or** dormant share ≥ threshold **or** no target **or** backorder KES above threshold |
| D7 | Default sort | Needing attention first, then revenue desc |
| D8 | Expand row | Keep: accounts / active / dormant / revenue; add last activity + top 3 at-risk accounts link |
| D9 | Org | MT HOD ≠ GT head; fix reporting so Purity’s My Team is MT reps only |
| D10 | Mobile | Rep list = cards; KPIs 2×2 |

---

## 7. Page structure (target)

```text
Header: My Team · period · compare · [My book | My team]
Banner: plain language team story (counts + KES + attention count)
KPI × 4 (D4)
Optional: Top 5 management priorities (only if impact > 0)
Reportee list (table md+ / cards phone)
  → expand or navigate to rep’s My book (scoped)
Tabs optional: keep Accounts/Orders/Backorders at team aggregate OR only on expand
```

### Reportee row (minimum columns)

| Column | Example |
|---|---|
| Reportee | Jane Kirigo · P076 |
| Accounts | 29 · 27 active · 2 dormant |
| Revenue MTD | KES 21.2M |
| Target | 84% / On track **or** No target |
| Attention | Amber/Red reason one-liner |
| Action | `Assign target` · `View dormant` · `Open book` |

---

## 8. Actionable build list

| Priority | Action | Owner hint |
|---|---|---|
| **A1** | Fix **reporting lines** for MT vs GT (Purity vs Steve) so My Team membership is correct | Org / seed |
| **A2** | Ensure every commercial rep has **monthly target** path (UI + data); team rollup when any targets exist | Commissions / targets |
| **A3** | Rep list: **status** + **sort by attention**; kill “19% time gone” without target | Frontend portfolio team mode |
| **A4** | Team KPI strip → **4 cards** (D4); demote fill-rate | Frontend |
| **A5** | Plain-language banner includes **N reportees need attention** | API summary |
| **A6** | Row actions: Assign target · View book · Dormant filter | Frontend |
| **A7** | Mobile cards for reportee list | Frontend |
| **A8** | Permission: only users with reportees see **My team** toggle | Capabilities |
| **A9** | QA: impersonate Purity → only MT tree; Steve → only GT tree | QA |
| **A10** | Later: blockers (stock) vs performance split; management action tracker | Phase 2 |

---

## 9. API / data (minimal)

| Need | Source |
|---|---|
| Team membership | `reports_to_user_id` subtree |
| Revenue MTD / orders | Existing portfolio summary per user / rep_code |
| Dormant / active | Existing portfolio windows |
| Target | Existing target/commissions tables |
| Backorder at risk | Portfolio / backorder scope by customers of team |

Prefer one `GET sales/portfolio/summary?mode=team` (exists) extended with `reportees[]: { status, attention_reason, target_… }`.

---

## 10. Acceptance (V1)

- [ ] HOD opens My Team → sees only **their** org tree (MT ≠ GT).  
- [ ] Within 5s: count of **reportees needing attention** visible.  
- [ ] Each rep row: revenue, accounts mix, target/status or **No target** CTA.  
- [ ] No fill-rate as a primary team sales KPI.  
- [ ] No lone “time gone” without target.  
- [ ] Expand/open book works for a reportee.  
- [ ] Usable on phone (cards).  
- [ ] Impersonation matches target user’s tree.

---

## 11. Explicit non-goals (V1)

- Company-wide executive page (→ `executive-view.md`)  
- Configurable KPI engine for all departments  
- Coaching tickets / full action workflow  
- Predicting fill rate on this page  

---

## 12. Related

| Doc | Link |
|---|---|
| Screenshot | `hodMy-Portfolio-Kim-Fay-Sight-08-06-2026_12_38_PM.png` |
| Portfolio UX | `kp-dashboard-prd.md` |
| Org / GT head | `GT-implemetation.md` (Steve XGT001) |
| Hierarchy fixes | `kp-view.md` |
| Mobile | `mobile-phone.md` |
| Executives | `executive-view.md` |
