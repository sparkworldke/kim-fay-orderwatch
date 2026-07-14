# Price Change Request (PCR) — Product Requirements Document

**Product:** OrderWatch · Kimfay Professional / Sales  
**Module:** Pricing governance — Price Change Request  
**File:** `kp/pricing/price-change.md`  
**Status:** PRD — ready for design / engineering  
**Priority:** P1 (KP tech enablement · Process Management)  
**Owner:** Product  
**Last updated:** 10 Jul 2026  

**Sources**

| Source | Use |
|---|---|
| Stakeholder notes in prior draft | Consultant-led only; hide base price; Acumatica update via notify |
| `kp/HorecaOS_PRD_v1.0.md` §5.4 | Approval chain, margin, audit, ERP staging |
| `kp/kp-enabler.md` | PCR in process automation phase |
| OrderWatch inventory / customers / SO | Base price, price class, portfolio scope |

---

## 1. Problem statement

Price changes for HORECA / KP (and other sales) accounts are requested ad hoc (WhatsApp, email, verbal). There is no single system that:

1. Captures **who** asked for what **SKU** for which **customer**, with justification.  
2. Shows **margin impact** to managers without exposing cost/base price to field sales.  
3. Enforces **approval authority** and SLA.  
4. Leaves an **audit trail** and a clear handoff to **update Acumatica**.  
5. Surfaces request status on **dashboards**.

**Product decision (v1):** There is **no customer-facing portal**. The customer asks the **Sales Consultant**, who submits the PCR in OrderWatch.

---

## 2. Goals & non-goals

### 2.1 Goals

| ID | Goal | Success measure |
|---|---|---|
| G1 | Consultant can submit PCR for **portfolio customers** only | 0 cross-portfolio creates |
| G2 | System resolves **current selling price** and **base/cost** server-side; consultant **never sees base price** | UI + API redaction tests pass |
| G3 | Consultant enters **proposed selling price** + justification; **current price is system-filled** (not free-typed as source of truth) | Current always from pricing engine |
| G4 | Multi-step approval with margin floor + KES authority thresholds | Escalation when over threshold |
| G5 | Approve / reject with **mandatory comments** on reject; SLA alerts | 100% rejects have reason |
| G6 | On final approval: immutable record + **notification to designated employee(s)** to apply price in **Acumatica** (v1) | Ops receives actionable email + deep link |
| G7 | Dashboard visibility: open / overdue / approved / rejected PCR | HOD/Admin/Exec |

### 2.2 Non-goals (v1)

- Customer self-service PCR portal.  
- Automatic Acumatica price write-back without human confirm (v1 = **notify + checklist**; v2 = staging API write-back).  
- Consultant visibility of **base price / unit cost / margin %** (managers only).  
- Full multi-currency PCR UI (KES first; store currency if present on price record).  
- Changing inventory base price itself (customer/sales price only unless Admin path later).

---

## 3. Personas & permissions

| Persona | Can |
|---|---|
| **Sales Consultant** | Create/submit PCR for portfolio customers; see status; see current **selling** price + proposed; **no base/cost/margin** |
| **Line Manager / HOD** | Approve/reject within authority; see margin + base; comment required on reject |
| **Senior Mgmt / CCO / Executive / C-suite** | Escalated approvals; full financials; reporting |
| **Pricing / Acumatica updater** (named role or user list) | Receive “apply in Acumatica” task/email; mark PCR as **applied in ERP** |
| **Administrator** | Configure thresholds, stages, mail, price sources; full audit |
| **Customer Care** | Read-only if configured (optional CC on final) |

**Capability flags (proposed)**

| Permission | Meaning |
|---|---|
| `pricing.pcr.view` | See PCR lists in scope |
| `pricing.pcr.create` | Submit PCR |
| `pricing.pcr.approve` | Act on approval stages (within threshold) |
| `pricing.pcr.approve_escalated` | Above-threshold decisions |
| `pricing.pcr.view_margin` | See base price, cost, margin % |
| `pricing.pcr.apply_erp` | Mark applied / receive ERP task |
| `pricing.pcr.config` | Admin thresholds & stages |

**Visibility:** Consultant → own submissions. Manager → team/subtree. Admin/Exec → all (sector filters apply as elsewhere).

---

## 4. Pricing data model (OrderWatch)

### 4.1 Price resolution (server-side only)

For `(customer_acumatica_id, inventory_id)` at submit time:

| Value | Source (priority) | Who can see |
|---|---|---|
| **Base price** | Inventory item base/list cost field used for margin (e.g. `last_cost` / `average_cost` / configured base) | Approvers with `view_margin` only |
| **Current selling price** | Customer **price class** / customer-specific price if mapped; else default sales price on inventory; else last SO unit price (config order) | Consultant + approvers |
| **Proposed selling price** | Entered by consultant (required) | Consultant + approvers |
| **Margin % / margin KES** | `f(proposed, base)` computed **server-side** | Approvers only |

**Price class:** If customer is linked to a custom **price class** in OrderWatch/Acumatica sync, current price **must** prefer that class over generic list price. Snapshot class id/name on the PCR at submit.

### 4.2 Core entities

```
price_change_requests
  id, public_ref (PCR-2026-000123)
  customer_acumatica_id, customer_name (snapshot)
  customer_price_class (snapshot, nullable)
  inventory_id, product_description (snapshot)
  current_selling_price          -- system at submit
  proposed_selling_price         -- consultant input
  base_price_snapshot            -- system; NEVER return to consultant API
  margin_pct_snapshot, margin_kes_snapshot  -- server calc; manager APIs only
  currency (default KES)
  justification                  -- required
  effective_date_requested       -- optional consultant; final set on approve
  status: draft | submitted | in_approval | approved | rejected
          | pending_erp_apply | applied_erp | cancelled
  current_stage_key
  submitted_by_user_id
  duplicate_ack_required / duplicate_acked_by  -- 48h same customer+SKU
  acumatica_apply_notified_at
  acumatica_applied_at, acumatica_applied_by
  created_at, updated_at

price_change_approval_stages     -- config (same pattern as FOL)
  key, name, sort_order, threshold_kes_max (nullable = any)
  assignee_mode, role_names[], user_ids[]
  require_comment_on_reject, sla_hours

price_change_approval_actions
  pcr_id, stage_key, actor_user_id
  decision: approved | rejected | escalated
  comment, decided_at
  margin_seen_pct                -- what manager saw

price_change_events              -- append-only audit
  pcr_id, event_type, actor_user_id, payload_json, created_at
```

---

## 5. End-to-end workflow

### 5.1 Happy path

```
1. Customer asks Sales Consultant (offline)
2. Consultant opens PCR form (portfolio customer + FOL-style SKU search)
3. System loads current selling price (+ price class); base hidden
4. Consultant enters proposed price + justification → Submit
5. Stage 1 (Line Manager / HOD): review margin; approve or reject
   - If proposed vs base breaches margin floor → block or force escalate
   - If KES impact above stage threshold → auto-route to senior stage
6. Stage N (Senior): final approve / reject
7. Status → pending_erp_apply
   - Email + in-app to configured “Acumatica price updaters”
   - Include: customer, SKU, new price, effective date, approver, deep link
8. Updater applies in Acumatica → marks PCR applied_erp in OrderWatch
9. Notify consultant (and optional customer email if address known + flag on)
```

### 5.2 Status machine

```
draft → submitted → in_approval → approved → pending_erp_apply → applied_erp
                 ↘ rejected
draft/submitted → cancelled (submitter/admin, before final approve)
```

| Transition | Actor | Required |
|---|---|---|
| draft → submitted | Consultant (owner) | Valid fields; portfolio; active customer |
| in_approval → approved (stage) | Stage assignee | Within threshold; comment optional on approve |
| in_approval → rejected | Stage assignee | **Comment mandatory** |
| last stage approve | System | → `pending_erp_apply` + notify ERP updaters |
| pending_erp_apply → applied_erp | `pricing.pcr.apply_erp` | Optional Acumatica ref / notes |
| * → cancelled | Owner/Admin | Only if not past final approve |

### 5.3 Step table (product)

| Step | Actor | Action | System guardrail |
|---|---|---|---|
| 1 | Customer | Requests change **via Sales Consultant** (no customer system) | Active account only; credit-hold configurable block |
| 2 | Sales Consultant | Submits PCR | Portfolio only; SKU required; **proposed price** + justification; **current** system-filled; **base price hidden**; price class respected |
| 3 | Line Manager / HOD | Reviews | Sees margin/base; margin floor alert; cannot approve below floor without escalate path |
| 4 | Senior Mgmt | Approves / rejects escalations | Authority thresholds server-side; SLA 24h escalate alert |
| 5 | System + Pricing ops | Handoff to Acumatica | Immutable audit; email/task to updater; mark applied when done |
| 6 | System | Notifications | Consultant + updaters (+ customer optional); reject reason mandatory |

---

## 6. Consultant form (UX)

### 6.1 Fields

| Field | Required | Notes |
|---|---|---|
| Customer | Yes | Portfolio typeahead only |
| SKU / inventory | Yes | Active stock items |
| Current selling price | Yes (display) | **Read-only**, system |
| Price class | Display | If attached |
| Proposed selling price | Yes | Consultant entry; &gt; 0 |
| Justification | Yes | Min 20 characters |
| Effective date requested | No | Date ≥ today |
| Attachments | No | Quote, email screenshot |

### 6.2 Explicitly **not** shown to consultant

- Base price / unit cost  
- Margin % or margin KES  
- Competitor cost fields  
- Other customers’ prices  

API must strip these fields for users without `pricing.pcr.view_margin` (not only hide in CSS).

### 6.3 Manager review UI

- Full consultant snapshot  
- Base, current, proposed, margin %, margin KES, floor status  
- Threshold band (within / escalate)  
- Duplicate warning if same customer+SKU in 48h  
- Approve / Reject / (Escalate if manual)  
- Comment (required on reject)

### 6.4 Dashboards

| Widget | Audience |
|---|---|
| My open PCRs | Consultant |
| Pending my approval | Managers |
| Overdue SLA | HOD / Admin |
| Pending Acumatica apply | Pricing updaters |
| Approved / rejected MTD | Exec |

---

## 7. Notifications

**From (config):** e.g. `pricing@…` or shared OrderWatch ops identity — **not** external form tools.

| ID | Trigger | To | Must include |
|---|---|---|---|
| **P1** | Submitted | Stage 1 approvers | Customer, SKU, current → proposed (no base to wrong roles), justification, deep link |
| **P2** | Stage approved (not final) | Consultant + next stage | Status, comment |
| **P3** | Final approved | Consultant + **ERP updaters** | New price, effective date, approver name, **instruction to update Acumatica**, deep link |
| **P4** | Rejected | Consultant | **Rejection reason** |
| **P5** | Marked applied_erp | Consultant (+ optional CC) | Confirmation |
| **P6** | SLA breach | Stage assignees + HOD | Hours open, link |

In-app tasks mirror P1/P3/P6.

---

## 8. Guardrails (non-negotiable)

### 8.1 Access & portfolio

| ID | Guardrail |
|---|---|
| GR-P01 | Only authenticated consultants with `pricing.pcr.create` may submit |
| GR-P02 | Customer must be in submitter **portfolio** (same L4 scope as FOL/customers) |
| GR-P03 | Customer must be **active** (and not credit-hold if `block_on_hold=true`) |
| GR-P04 | No customer portal entry points |

### 8.2 Price integrity & redaction

| ID | Guardrail |
|---|---|
| **GR-P10** | **Base price never returned** on consultant-facing APIs or emails |
| **GR-P11** | **Current selling price** is system-resolved at submit and **snapshotted** (not trusted from client as authority) |
| **GR-P12** | Proposed price required, numeric, &gt; 0; max ceiling config optional |
| **GR-P13** | Margin calculated **only server-side** from snapshot base + proposed |
| **GR-P14** | Price class used when customer has one; class id snapshotted |

### 8.3 Approval

| ID | Guardrail |
|---|---|
| GR-P20 | Authority thresholds (KES discount or absolute) stored **server-side only** |
| GR-P21 | Below margin floor → cannot final-approve at line-manager stage without escalation path |
| GR-P22 | Above stage max KES impact → auto-escalate |
| GR-P23 | Reject requires non-empty reason |
| GR-P24 | Only stage assignees may decide (403 otherwise) |
| GR-P25 | SLA timer per stage (default 24h) → P6 alert |
| GR-P26 | MFA for approvers — **product requirement** for production; enforce when platform MFA ready (flag) |

### 8.4 Duplicates & audit

| ID | Guardrail |
|---|---|
| GR-P30 | Same customer + SKU submitted within **48h** → flag; manager must acknowledge before approve |
| GR-P31 | Append-only `price_change_events` for submit/approve/reject/notify/apply |
| GR-P32 | Snapshots immutable after submit (no edit of prices without new PCR) |

### 8.5 Acumatica handoff (v1)

| ID | Guardrail |
|---|---|
| **GR-P40** | Final approve does **not** silently invent ERP success — status `pending_erp_apply` until human confirms |
| **GR-P41** | ERP updater notification includes employee/approver identity and payload for Acumatica entry |
| GR-P42 | Only `pricing.pcr.apply_erp` may set `applied_erp` |
| GR-P43 | v2: staging buffer + 5-minute validation window before API commit (reserved) |

---

## 9. Acceptance criteria (Given / When / Then)

### 9.1 Submit (consultant)

| ID | Acceptance criteria |
|---|---|
| AC-01 | **Given** a Sales Consultant with portfolio customer C, **when** they open PCR customer search, **then** only portfolio customers appear. **[Auto]** |
| AC-02 | **Given** customer outside portfolio, **when** API create uses that id, **then** 403/422 and no PCR row. **[Auto]** |
| AC-03 | **Given** valid customer + SKU, **when** form loads, **then** current selling price is populated read-only from server. **[Auto]** |
| AC-04 | **Given** consultant session, **when** PCR detail/create API returns, **then** response JSON has **no** `base_price`, `margin_pct`, or `margin_kes` fields. **[Auto]** |
| AC-05 | **Given** missing justification or proposed price ≤ 0, **when** submit, **then** 422. **[Auto]** |
| AC-06 | **Given** customer with price class P, **when** current price resolved, **then** resolution uses class P (or documented fallback) and class is snapshotted. **[Auto]** |
| AC-07 | **Given** inactive/blocked customer and `block_on_hold`, **when** submit, **then** 422. **[Auto]** |

### 9.2 Approval & margin

| ID | Acceptance criteria |
|---|---|
| AC-10 | **Given** manager with `view_margin`, **when** they open PCR, **then** they see base, proposed, margin %. **[Auto]** |
| AC-11 | **Given** proposed price breaches margin floor, **when** line manager tries final approve without escalate rights, **then** 422 or forced escalate. **[Auto]** |
| AC-12 | **Given** KES impact above stage threshold, **when** submitted/approved at stage 1, **then** next assignee is escalated senior stage. **[Auto]** |
| AC-13 | **Given** reject without comment, **when** POST decision, **then** 422. **[Auto]** |
| AC-14 | **Given** non-assignee, **when** POST approve, **then** 403. **[Auto]** |
| AC-15 | **Given** final approve, **when** completed, **then** status=`pending_erp_apply` and event logged. **[Auto]** |

### 9.3 Notifications & ERP handoff

| ID | Acceptance criteria |
|---|---|
| AC-20 | **Given** submit, **when** successful, **then** P1 notification sent to stage 1 assignees. **[Auto]** |
| AC-21 | **Given** final approve, **when** completed, **then** P3 goes to ERP updater list with new price, effective date, approver name, deep link. **[Auto]** |
| AC-22 | **Given** reject with reason R, **when** completed, **then** consultant notification body contains R. **[Auto]** |
| AC-23 | **Given** user without `apply_erp`, **when** mark applied, **then** 403. **[Auto]** |
| AC-24 | **Given** updater marks applied, **when** done, **then** status=`applied_erp`, timestamps set, consultant notified (P5). **[Auto]** |

### 9.4 Duplicates, SLA, dashboard

| ID | Acceptance criteria |
|---|---|
| AC-30 | **Given** PCR for customer+SKU approved path started &lt; 48h ago still open/recent, **when** new submit same pair, **then** duplicate flag set. **[Auto]** |
| AC-31 | **Given** duplicate flag, **when** manager approves without acknowledge, **then** 422. **[Auto]** |
| AC-32 | **Given** stage open &gt; sla_hours, **when** SLA job runs, **then** P6 alert fired once per breach window. **[Auto]** |
| AC-33 | **Given** HOD dashboard, **when** loaded, **then** counts for pending approval and pending ERP apply reflect scoped PCRs. |

### 9.5 Audit & immutability

| ID | Acceptance criteria |
|---|---|
| AC-40 | **Given** submitted PCR, **when** consultant PATCHes proposed price, **then** rejected (create new PCR instead). **[Auto]** |
| AC-41 | **Given** any decision, **when** stored, **then** `price_change_events` has actor + timestamp + decision. **[Auto]** |
| AC-42 | **Given** thresholds in client payload, **when** server evaluates authority, **then** only server config is used (tampered client threshold ignored). **[Auto]** |

---

## 10. API sketch

| Method | Path | Purpose |
|---|---|---|
| POST | `/api/operations/price-change-requests` | Create/submit (consultant) |
| GET | `/api/operations/price-change-requests` | List (scoped) |
| GET | `/api/operations/price-change-requests/{id}` | Detail (redacted by role) |
| GET | `/api/operations/price-change-requests/resolve-price` | `customer_id` + `inventory_id` → current selling (no base for consultant) |
| POST | `/api/operations/price-change-requests/{id}/decisions` | approve/reject + comment |
| POST | `/api/operations/price-change-requests/{id}/acknowledge-duplicate` | Manager ack |
| POST | `/api/operations/price-change-requests/{id}/mark-applied-erp` | Pricing ops |
| GET | `/api/operations/price-change-requests/dashboard` | KPIs |
| Admin | `/api/admin/pricing/pcr-settings` | Thresholds, stages, updater list, mail |

---

## 11. Phased delivery

| Phase | Scope | Exit |
|---|---|---|
| **PCR-0** | Schema, permissions, settings (thresholds, stages, updaters) | Config UI or seed |
| **PCR-1** | Consultant create/submit + price resolve (class + inventory) + redaction | AC-01–07 |
| **PCR-2** | Approval engine + margin floor + escalate + reject reasons | AC-10–15 |
| **PCR-3** | Notifications P1–P6 + pending ERP apply + mark applied | AC-20–24 |
| **PCR-4** | Duplicate 48h, SLA job, dashboards, export | AC-30–33 |
| **PCR-5** | Optional Acumatica API write-back with staging buffer | Separate epic |

---

## 12. Metrics (90 days)

| Metric | Target |
|---|---|
| PCRs with full audit trail | 100% |
| Median submit → final decision | ≤ 24 business hours |
| Rejects without reason | 0 |
| Approved PCRs marked applied in ERP within 2 business days | ≥ 90% |
| Consultant API responses leaking base price | 0 |
| Duplicate same customer+SKU without ack | 0 approvals |

---

## 13. Open questions

1. Exact field for **base price** on inventory (last_cost vs average_cost vs sales_price)?  
2. Margin floor % default (e.g. 15%)?  
3. Authority thresholds in KES by stage (HOD vs CCO)?  
4. Is **customer email** notification in scope for v1 or consultant-only?  
5. Credit hold: hard block submit or warn only?  
6. Multi-line PCR (several SKUs per request) in v1 or one SKU per PCR?  
7. MFA timeline for approvers (GR-P26)?

---

## 14. Summary

| Topic | Product rule |
|---|---|
| Who starts PCR | **Sales Consultant only** (customer asks them; no customer portal) |
| Current price | **System** from price class / inventory / rules |
| Proposed price | **Consultant enters** selling price |
| Base / margin | **Managers only**; never consultant |
| Approval | Configurable stages, thresholds, margin floor, SLA |
| Acumatica (v1) | **Notify designated employees** to update ERP → mark applied |
| Audit | Immutable events + snapshots |
| Acceptance | AC-01–42 above; redaction and portfolio tests mandatory |

**Engineering north star:** server-owned pricing resolution + role-based field redaction + FOL-like approval engine + ERP handoff queue — aligned with OrderWatch portfolio scope and inventory master.

---

*Replaces ad-hoc HorecaOS §5.4 snippet with OrderWatch-specific PCR PRD.*
