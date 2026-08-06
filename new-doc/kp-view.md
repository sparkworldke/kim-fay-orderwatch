# KP View — Issues & Correction Flow

**Product:** Kim-Fay Sight / OrderWatch  
**Audience:** Product + engineering  
**Related:** `kp-dashboard-prd.md` (My Portfolio UX), portfolio attribution (`CustomerAttributionService`, `OrgScopeService`, FOL scoping)  
**Status:** Action plan  

---

## Problems (what users see today)

| # | Issue | Who is hurt | Symptom |
|---|---|---|---|
| **A** | Segment not locked to KP for HoD | HoD KP (e.g. Susan) | On portfolio / ops pages, filter is not defaulted to **KP**; totals mix other channels (MT/GT/etc.) or show company-wide noise |
| **B** | Numbers not segment-true | Same | KPI cards, tables, dormant lists show counts outside KP even when the role is KP HoD |
| **C** | FOL sees wrong customers | KP sales consultants / team on FOL | User can pick or see customers not in **their** attached book |
| **D** | Employee no. vs customer ownership is unclear | Admins + consultants | Confusion between Acumatica rep / employee number and **manual** portfolio attach — “why is this customer on my book?” |

---

## Desired end state

1. **HoD KP (Susan)**  
   - Every KP-facing page defaults segment/channel filter to **KP**.  
   - All KPIs and lists for that session are **KP-only** (unless she deliberately changes filter, if allowed).  
   - Non-KP access she already has (broader company views) stays available **outside** the KP portfolio path — do not break existing non-KP breadth.

2. **FOL team members**  
   - Customer pickers and FOL lists only show customers **manually attached** to that user (servicing / primary assignment).  
   - No silent expansion via rep-code-only or “everyone in KP”.

3. **Source of truth tags**  
   - Anywhere ownership is shown (account, assignment admin, FOL customer chip), show a clear tag:  
     - **Acumatica** — from ERP rep / SO history / auto map  
     - **Manual** — admin or portfolio import attach  
     - **Employee match** — proposed via employee number / rep code (not yet approved attach)  
   - Reconfirm employee numbers against the customer’s assigned rep so conflicts are visible, not guessed.

---

## Correction flow (do in this order)

```text
 1. Identity clean-up          (employee no. + rep codes)
 2. Portfolio attach rules     (manual vs Acumatica)
 3. FOL customer scope         (attached only)
 4. HoD KP segment defaults    (KP filter + KP-only numbers)
 5. UI tags + admin recon tools
 6. Verify with real users
```

### Step 1 — Identity clean-up (employee number)

**Goal:** One employee → one active user; no ambiguous rep aliases.

| Action | Detail |
|---|---|
| 1.1 | Export active KP users: `name`, `email`, `employee_number`, `rep_code`, role, manager |
| 1.2 | Export Acumatica sales-person / SO rep codes used on KP customers |
| 1.3 | Match employee_number → user; flag duplicates and empties |
| 1.4 | Align `users.rep_code` / `user_acumatica_rep_mappings` with Acumatica (one primary code per person) |
| 1.5 | Document conflicts (same code on two users, employee no. mismatch) for admin fix **before** bulk re-attach |

**Done when:** identity resolution does not return multi-user conflicts for KP reps used in production.

### Step 2 — Portfolio attach: what counts as “my customer”

**Rule of record for FOL + mapped-only consultants:**

| Source | Tag | May widen FOL / My Portfolio? |
|---|---|---|
| Manual admin attach / portfolio CSV import | **Manual** | **Yes** — this is the book |
| Acumatica SO history / auto-proposed map | **Acumatica** | Propose only; promote to Manual after admin approve (or import) |
| Employee number / rep code match alone | **Employee match** | **No** — show as suggestion, not scope |

**Actions:**

| Action | Detail |
|---|---|
| 2.1 | Audit `user_customer_assignments` for KP users: `source`, `is_manual_override`, `assignment_type` |
| 2.2 | List customers where only Acumatica/rep match exists and **no** manual attach — these leak into scope if not gated |
| 2.3 | Confirm mapped-only consultants use **effective mapped set only** (existing §7.3 intent) |
| 2.4 | For each KP rep: reconfirm employee number ↔ customers currently on their book; fix wrong attaches |

**Done when:** every customer on a consultant’s FOL/portfolio has a Manual (or approved) assignment row; proposals are tagged separately.

### Step 3 — FOL: only attached customers

**Goal:** Each FOL user only sees/selects their book.

| Action | Detail |
|---|---|
| 3.1 | Customer search on FOL create/edit → `DataScope::scopedCustomerAcumaticaIds` / mapped attach only |
| 3.2 | FOL list views for consultants → same scope (already partially via `sales_consultant_user_id` + customer scope; close any “all KP” loophole) |
| 3.3 | Block submit if `customer_acumatica_id` not in user’s attached set (server-side 403/422) |
| 3.4 | Approvers / CCO / admin keep broader read for workflow; technicians stay on assigned FOLs |

**Done when:** a consultant cannot open or submit FOL for a customer not on their attach list.

### Step 4 — HoD KP (Susan): segment = KP, numbers = KP only

**Goal:** Default and constrain KP portfolio views to the KP segment.

| Action | Detail |
|---|---|
| 4.1 | Detect HoD KP / privileged KP overlay user (existing `KpReportingHierarchyService` / Susan path) |
| 4.2 | **UI:** on My Portfolio, dormant, KP CRM, team rollups — default channel/segment control to **KP** and persist for session |
| 4.3 | **API:** portfolio summary, accounts table, dormant, backorders for this role — filter customers/orders to KP channel (e.g. customer class / channel classification starting with KP) **unless** explicit non-KP filter is allowed and chosen |
| 4.4 | Team rollup for HoD = union of reportees’ **KP** books only (not MT/GT bleed) |
| 4.5 | Do not remove Susan’s broader non-KP access on company-wide admin pages; only gate **KP portfolio product surfaces** |

**Done when:** as Susan, opening My Portfolio / KP dormant shows KP-only counts that match a manual KP customer filter.

### Step 5 — Tags in the UI (remove confusion)

Show source everywhere ownership is displayed:

| Tag | Meaning | Colour (suggestion) |
|---|---|---|
| **Manual** | Attached by admin/import | Blue / solid |
| **Acumatica** | From ERP rep / SO attribution | Grey / outline |
| **Employee match** | Suggested via employee no. / rep code | Amber — not in scope until promoted |

**Places:**

- My Portfolio / accounts table (hover or badge on owner)  
- Admin → customer assignment / team portfolio  
- FOL customer header  
- Optional: identity reconcile screen listing conflicts  

**Copy example:**  
`Owner: Jane (Manual) · Acumatica rep: KP12 · Employee #: 1042`

### Step 6 — Verify

| Check | Pass criteria |
|---|---|
| Consultant A FOL picker | Only A’s Manual customers |
| Consultant B cannot open A’s customer FOL | 404/denied |
| Susan My Portfolio KPIs | Match KP-only export for her team |
| Segment filter | Defaults KP; changing away is intentional (if allowed) |
| Assignment badges | Manual vs Acumatica vs Employee match correct on sample of 10 accounts |
| Employee conflict list | Empty or ticketed for admin |

---

## Flow diagram (ownership)

```text
Acumatica SO / rep code / employee #
            │
            ▼
   [Employee match] ──suggest──► Admin reconcile
            │                         │
            │                         ▼
            │                  [Manual attach]
            │                         │
            └──── Acumatica tag ──────┤
                                      ▼
                         Consultant FOL + Portfolio scope
                         (Manual only for hard gate)
```

---

## Page / product impact map

| Surface | Fix |
|---|---|
| My Portfolio (all KP pages with filters) | Default segment **KP**; KPIs scoped to KP for HoD |
| Dormant / at-risk | Same segment + portfolio scope |
| FOL new + list | Attached customers only for consultants |
| Team / assignment admin | Source tags + employee/rep reconcile tools |
| Account detail | Tags + days inactive / buying status (see `kp-dashboard-prd.md`) |

---

## Out of scope (this doc)

- Full My Portfolio UX redesign (see `kp-dashboard-prd.md`)  
- Changing commission maths  
- Non-KP channel product ownership (MT/GT partners) except ensuring they do not pollute KP HoD numbers  

---

## Implementation notes (code anchors)

| Concern | Existing code |
|---|---|
| Customer scope | `DataScope`, `OrgScopeService::applyCustomerScope` |
| Susan / KP overlay | `KpReportingHierarchyService` (“Susan keeps broad non-KP; KP slice narrowed”) |
| Mapped-only book | `CustomerAttributionService::isMappedOnlyConsultant`, `directCustomerIds` |
| Assignments | `UserCustomerAssignment` (`source`, `is_manual_override`) |
| FOL list scope | `FolRequestService` + `DataScope::scopedCustomerAcumaticaIds` |
| Channel KP | `SalesChannelClassificationService` (class prefix `KP`) |

---

## Acceptance checklist

- [ ] HoD KP opens any KP portfolio page → segment **KP** selected; numbers are KP-only.  
- [ ] FOL consultant sees only manually attached customers in search and submit.  
- [ ] Employee number, Acumatica rep, and Manual attach are visible as tags (no silent merge).  
- [ ] Identity conflicts reported, not auto-resolved.  
- [ ] Sample reconcile of 1 team (rep + HoD) signed off by ops.  
