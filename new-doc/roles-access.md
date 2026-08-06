# Roles & Access — Decision PRD (tight)

**Product:** Kim-Fay Sight  
**Status:** Decision draft  
**Purpose:** One model for **who sees what**, how the org tree attaches, and how channels/brands stay isolated (**no bleed**).  
**Related:** `executive-view.md` · `my-team.md` · `GT-implemetation.md` · `kp-view.md` · `UserCapabilitiesService` / `DataScope` / `OrgScopeService`

---

## 1. Principle (no bleed)

Every data row is visible only if **all** gates pass:

```text
1. Auth + active user
2. Menu / capability (what pages appear)
3. Org scope (self · subtree · org-wide)
4. Channel / segment (MT · GT · KP · Ecom · DTC…)
5. Brand scope (Partner Brands only — Dove, Lux, Rexona…)
6. Explicit assignment (portfolio / mapped-only) when required
```

**Bleed** = seeing another channel’s customers, another HOD’s reportees, or brands not assigned.  
**Fail closed** when org_level = `gap` or scope empty.

---

## 2. Org attachment model (how people hang together)

```text
Company
 └── Executive / C-suite          (org-wide)
      └── HOD                     (department + subtree)
           └── Manager / Regional (subtree under them)
                └── IC / Rep      (self + assigned book only)
```

| Field | Use |
|---|---|
| `reports_to_user_id` | Hard parent link — drives My Team & rollups |
| `org_level` | `executive` · `c_suite` · `hod` · `manager` · `member` · `gap` |
| `department_role` | `executive` · `hod` · `manager` · `member` |
| `department_id` / pivot | Primary department (KP, GT, MT, partner_brands, production…) |
| `role` (RBAC) | Administrator, Executive, Sales Consultant, CS Agent… |
| Assignments | `user_customer_assignments`, brand assignments, rep_code |

**Rule:** One primary manager. No dual HOD parents. Channel exceptions (e.g. GT → Steve not Purity) override bad CSV.

---

## 3. Role ladder (decision table)

### 3.1 Executive / C-suite / Admin

| Persona | Examples | Data scope | Menus (viable) | Default home |
|---|---|---|---|---|
| **Executive / C-suite** | CEO Rajdeep, Hartaj, CCO Vignesh, COO Miraj, Divya (C1144 / djumani@), CFO | **Org-wide** all channels | Executive View, OrderWatch, SI all channels, Production (read), KP/GT as needed | **`/app/executive`** |
| **Super Admin / Administrator** | Super admin, commercialtechlead@ | Org-wide + admin | All + Administration | Admin or Executive |
| **Platform ops** | commercialtechlead@ | Org-wide | Full commercial + alerts | Executive or Dashboard |

**Do not:** Put sales consultants on Executive home.  
**Do:** Mask nothing for Executive/Admin (unless policy says otherwise).

### 3.2 HOD

| Persona | Examples | Data scope | Menus | Default home |
|---|---|---|---|---|
| **HOD commercial** | Susan (KP), Purity (MT), Steve XGT001 (GT), Anne (Partner Brands) | **Subtree** of reportees + own book · **own channel/brand only** | My Team, domain module, limited OrderWatch | Domain (KP portfolio / MT / GT / Partner) |
| **HOD ops** | Production / Procurement / Fleet / Stores | Ops data (inventory, BO, fill) · not full customer books of all sales | Production, Backorders, Fill rate, Inventory | Production / Backorders |

**Bleed guard:** Purity **MT only** · Steve **GT only** · Susan **KP only** · Anne **assigned brands only**.

### 3.3 Reportee to HOD (individual contributor)

| Persona | Scope | Menus | Default home |
|---|---|---|---|
| **Sales IC / Consultant** | **Self** + assigned customers / rep_code book | My Portfolio (book only), orders scoped, FOL if KP | My Portfolio / accounts |
| **No My Team toggle** | Unless `has_reportees` | — | — |

### 3.4 Reportee to HOD **with reportees** (mid-manager)

| Persona | Examples | Scope | Menus |
|---|---|---|---|
| **Area / Territory manager** | GT ASMs, MT seniors with juniors | Self + **direct (and nested) reportees** | My Team (subtree only), My book |
| **Not full department** | Cannot see peer managers’ trees under same HOD unless org says so | Subtree only | — |

Same UI as My Team; membership = descendants of `reports_to`, not whole department.

### 3.5 Regional managers

| Decision | Spec |
|---|---|
| **What** | Manager with `org_level ≈ manager` + **region tag(s)** (Coast, Rift, Lake…) |
| **Scope** | Union of: (a) reportees in tree, (b) customers/outlets tagged to their region(s), (c) never other regions |
| **Attach** | Region on user + region on customer/outlet; optional region on assignment |
| **Viable first** | **GT** (Steve’s tree) — highest need · MT later if needed |
| **Bleed** | Region filter is **additive constraint**, not a way to open all GT |

### 3.6 Partner Brands (brand segmentation UI)

| Decision | Spec |
|---|---|
| **HOD** | Anne (partner_brands) — all partner brands or configured set |
| **TM / lead** | Assigned **brand list and/or trading group** (Dove, Lux, Rexona, Huggies…) |
| **UI** | Brand multi-select + group chips; defaults = **allowed brands only** (cannot pick unassigned) |
| **Data** | Inventory, sales, backorders, production partner views filtered by `BrandAssignmentScope` |
| **No bleed** | Dove owner never sees Lux-only lines unless dual-assigned |

### 3.7 Customer Care

| Role | Viable access | Hide |
|---|---|---|
| **CS Manager** | OrderWatch (orders, customers, mailbox, order-match), team tools as permitted | Full admin unless granted |
| **CS Agent** | OrderWatch + customer service flows; **mask revenue** if policy | Administration, roles, My Team (unless manager) |
| **Scope** | Often wider order visibility for service; still no payroll/admin; portfolio attach if used for FOL |

### 3.8 Production / Supply / Stores / Dispatch

| Role | Viable | Hide / limit |
|---|---|---|
| **Production / planning** | Production & stock, inventory, MSI (if can_manage), backorders, fill-rate | Mailbox, order-match, SO-imports, admin (per config) |
| **Stores / dispatch** | Inventory, zones, backorders (release), limited orders | Customer portfolio, SI channels, admin |
| **Procurement** | Backorders (trading), inventory cover | Full KP CRM / FOL |

### 3.9 Calltronix / DTC-DTB role

| Decision | Spec |
|---|---|
| **Menus** | **DTC/DTB** (Quotes, Sales Orders, Price List, Customers) **+ OrderWatch** (Dashboard, Orders, Customers as needed) |
| **Not default** | Full KP FOL/CRM, Partner Brands, Production admin, GT SFA unless dual-role |
| **Scope** | DTC/DTB customers and documents only for commercial data; OrderWatch may be operational (all SO or DTC-linked — **decide: DTC-linked SO preferred**) |
| **Capability** | `dtc.view` / channel `dtc_dtb` + OrderWatch menu pack |

**Closed decision:** Calltronix users get **DTC/DTB module + OrderWatch core**. No automatic MT/GT/KP portfolio.

---

## 4. Channel packs (menu bundles)

| Pack | Includes | Typical roles |
|---|---|---|
| **Executive** | Executive View + broad read | C-suite, Divya, Hartaj, CEO, commercialtechlead |
| **OrderWatch core** | Dashboard, Orders, Customers, Not delivered, Credit notes, AI (optional) | CS, Calltronix, leadership |
| **SI Portfolio** | My Portfolio / My Team | Sales with book / reportees |
| **MT** | MT1/MT2 SI | Purity tree |
| **GT** | GT Revenue & Orders, SFA | Steve tree |
| **KP** | KP CRM, FOL, commissions as permitted | Susan tree + KP ICs |
| **DTC/DTB** | Calltronix routes | Calltronix role |
| **Partner Brands** | Production partners + brand-filtered sales/stock | Anne + brand TMs |
| **Production ops** | Production, Inventory, BO, Fill rate | Production/stores |
| **Admin** | Administration, roles, adoption | Super admin / user managers only |

User = **one primary pack** + optional second (e.g. Calltronix = DTC + OrderWatch).  
Never auto-enable all packs.

---

## 5. Attachment rules (who owns what data)

| Attachment | Used for | Gate |
|---|---|---|
| **Customer → user** (servicing/manual) | Portfolio, FOL, My Team rollup | Required for mapped-only consultants |
| **Rep code → user** | SO matching, identity | Alias table; does not override manual attach for FOL |
| **Brand → user** | Partner Brands | BrandAssignmentScope |
| **Region → user** | Regional managers | Region ∩ tree |
| **Channel on customer** | MT/GT/KP/Ecom/DTC | Classification + overrides |
| **reports_to** | Hierarchy | Single parent |

**Priority for “my customer” (commercial IC):**  
Manual/servicing assignment ≥ portfolio import ≥ (optional) rep match for display only.

---

## 6. Anti-bleed checklist (must pass)

| Check | Pass criteria |
|---|---|
| HOD My Team | Only descendants under `reports_to` |
| Channel | MT HOD never lists GT-only customers as “team success” |
| Partner brand | API rejects filter brand outside allowed list |
| Regional | Outlet outside region not in list even if same HOD |
| Mapped-only IC | Cannot FOL/search customers not attached |
| CS Agent | No admin; revenue masked if configured |
| Calltronix | No KP dormant / GT SFA unless dual-role |
| Executive | Full read OK; write still permission-gated |
| Impersonation | Banner + target user’s scope only |

---

## 7. Viable vs later (by function)

| Function | Viable now | Later |
|---|---|---|
| Executive / C-suite | Executive View + org-wide | — |
| Commercial HOD + tree | My Team + channel scope | Coaching actions |
| Regional (GT) | Region tags + tree | Full geo GIS |
| Partner Brands | Brand/group UI + scope | Per-SKU owner |
| Customer Care | OrderWatch + mailbox/match | Full CRM |
| Production / stores | BO, inventory, fill, production | Auto PO create |
| Calltronix | DTC/DTB + OrderWatch | Deep ERP writebacks |
| HR / Marketing / Audit | Light read or deny | Dedicated modules |
| Finance | Exec read; no sales edit | Collections pack |

---

## 8. Named identity anchors (from other PRDs)

| Person | Emp # | Email | Role pack |
|---|---|---|---|
| Steve Ananda | **XGT001** | salesstrategy@kimfay.com | GT HOD |
| Divya Jumani | **C1144** | djumani@kimfay.com | Executive |
| commercialtechlead | P415 | commercialtechlead@kimfay.com | Admin/platform + Executive view |
| Purity | P496 | (existing) | MT HOD — not GT |
| Susan | P025 | (existing) | KP HOD |
| Anne | P086 | (existing) | Partner Brands HOD |

---

## 9. Implementation pointers (actionable)

| ID | Action |
|---|---|
| R1 | Document + enforce **single reports_to** for every commercial user |
| R2 | Seed/fix: Purity tree = MT; Steve tree = GT (`XGT001`) |
| R3 | Capability `executive_view` only for Executive set (+ listed emails) |
| R4 | Partner Brands: UI brand multi-select = **allowed brands only** |
| R5 | Calltronix role template: menus = DTC/DTB + OrderWatch core only |
| R6 | CS Agent: keep `mask_revenue`; hide admin/team |
| R7 | Production department: keep hidden_menus (mailbox, order-match, admin) |
| R8 | Regional manager: add region field; apply as filter on GT outlets |
| R9 | Automated tests: OrgScopeLeak / partner brand / DTC menu pack |
| R10 | Admin UI: show effective scope summary (channels, brands, reportee count) |

---

## 10. Acceptance

- [ ] Executive/C-suite/Admin see company-wide without channel pack limits on read.  
- [ ] HOD My Team never includes another HOD’s channel tree.  
- [ ] IC without reportees has no My Team.  
- [ ] Mid-manager sees only their subtree.  
- [ ] Regional manager cannot open other regions’ outlets.  
- [ ] Partner Brands user filtering Dove cannot load Lux-only data.  
- [ ] Calltronix sees **DTC/DTB + OrderWatch**, not full KP/GT/Partner.  
- [ ] CS / Production get viable packs above; no accidental Administration.  
- [ ] Scope empty → empty state, not all data.

---

## 11. One-page summary

| Layer | Scope | Example |
|---|---|---|
| Executive / Admin | Org-wide | CEO, Divya, commercialtechlead |
| HOD | Department tree + channel/brand | Susan KP, Steve GT, Anne brands |
| Regional / mid-manager | Subtree ± region | GT ASM Coast |
| IC / reportee | Self + assignments | KP consultant, brand TM (Dove) |
| Ops / CS | Function pack | Production BO, CS OrderWatch |
| Calltronix | DTC/DTB + OrderWatch | Call centre commercial |

**Attach with:** `reports_to` + department + channel + brand + (optional) region + assignments.  
**Prevent bleed with:** fail-closed scopes and pack-based menus.
