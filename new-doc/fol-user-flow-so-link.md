# FOL User Flow + Acumatica SO Create & Link — PRD

**Product:** Kim-Fay Sight · KP FOL  
**Status:** Decision draft  
**Scope:** Document **how FOL works today**, and the **Create SO in Acumatica** + **Link SO to FOL** options  
**Code anchors:** `FolRequestService` · `FolAcumaticaSalesOrderService` · `config/fol.php` · `/app/kp/fol/*`

---

## 1. Goal

1. One clear **user flow** for FOL from draft → install/fulfil.  
2. **Option to create a Sales Order in Acumatica** from the FOL (customer + lines).  
3. **Link that SO (or an existing SO) to the FOL** so everyone sees the same order number, status, and history.

---

## 2. What FOL is

**FOL** = Free of Liability / field installation request for **KP Professional** customers: consumables / equipment lines, multi-stage approval, then warehouse/invoicing and technician install.

**Not** a generic sales quote UI — it is a controlled workflow that may end in a **zero-price (or configured) Acumatica SO**.

---

## 3. Roles in the flow

| Role | Typical actions |
|---|---|
| **Sales consultant (IC)** | Create draft, pick customer (attached book only), lines, submit |
| **Stage approvers (HOD / configured stages)** | Approve or reject with comment |
| **Final approver (CCO / COO stage)** | Final approve → triggers auto SO create (if enabled) |
| **Invoicing / CS / Sales Ops** | Manual **link SO**, PO match, ready-for-invoice queue |
| **Technician manager** | Assign technician |
| **Technician** | Install / calendar allocations |
| **Admin** | Break-glass all stages (if `allow_admin_on_all_stages`) |

Notifications N1–N6 go to stage recipients / consultant / invoicing (redirected in testing mode).

---

## 4. End-to-end user flow (as built)

```text
┌─────────────┐
│ 1. DRAFT    │  Consultant creates FOL for KP customer
│             │  + lines (inventory) + optional attachments
│             │  + consumables metrics (system SO / manual override)
└──────┬──────┘
       │ Submit
       ▼
┌─────────────┐
│ 2. SUBMITTED│  status=submitted · first approval stage
│             │  Email N1 → stage approvers
└──────┬──────┘
       │ Approve (+ comment)          │ Reject (+ comment)
       ▼                              ▼
┌─────────────┐                 ┌──────────┐
│ 3. IN       │  more stages    │ REJECTED │  Email N6 consultant
│  APPROVAL   │  as configured  └──────────┘
└──────┬──────┘  Email N2 consultant + N3 next stage
       │ Last stage approve (CCO/COO)
       ▼
┌──────────────────┐
│ 4. READY FOR     │  status=ready_for_invoicing
│    INVOICING     │
└────────┬─────────┘
         │
         │ 4a. AUTO: Create SO in Acumatica (if enabled)
         │     FolAcumaticaSalesOrderService::createAndLink
         │     → fol_so_links + linked_so_order_nbrs
         │     Email N4 consultant · N5 invoicing (with SO # if ok)
         │
         │ 4b. If auto fails / skipped / already linked:
         │     FOL still approved; SO can be linked later
         │
         ▼
┌──────────────────┐
│ 5. SO LINKED     │  Manual link by SO # or PO match
│  (optional path) │  status → so_linked when linked from ready
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ 6. INVOICED /    │  Business process outside pure SO create
│    FULFILLED     │  + technician assign → install complete
└──────────────────┘
```

### 4.1 Step detail

| Step | Actor | System behaviour |
|---|---|---|
| **Create draft** | Consultant | Customer must be in FOL portfolio scope (manual/attach rules). Lines required later. |
| **Submit** | Consultant | Validates lines; optional attachment rule; sets first `current_stage_key`; N1. |
| **Stage approve** | Approver for stage | Comment required; moves to next stage or final. |
| **Stage reject** | Approver | Terminal `rejected`; N6. |
| **Final approve** | Last stage (CCO/COO) | `ready_for_invoicing` + **createAndLink SO** (config). |
| **Auto SO** | System | PUT SalesOrder to Acumatica; customer = FOL customer; lines = FOL lines; `CustomerOrder` = FOL public ref; price often zero. Logs in `fol_so_create_logs`. |
| **Manual link SO** | Invoice permission | Enter existing SO #; must match FOL customer; creates `fol_so_links` (`link_type=invoice`). |
| **PO match** | Invoice / consultant | Match `customer_order` PO on Acumatica SO for same customer (`link_type=po_match`). |
| **Retry SO** | Cron | FOLs final-approved without SO number; limited batch (`so_retry_limit`). |
| **Technician** | Install manage | Assign tech; calendar open/resolved work. |

### 4.2 Status map (simplified)

| Status | Meaning |
|---|---|
| `draft` | Editable by owner |
| `submitted` / `in_approval` | In stage pipeline |
| `rejected` | Stopped |
| `ready_for_invoicing` | Fully approved; SO create attempted |
| `so_linked` | At least one SO linked (manual path from ready) |
| `invoiced` / `fulfilled` | Downstream commercial / install complete |

---

## 5. Create SO on Acumatica

### 5.1 As-is (already implemented)

| Item | Behaviour |
|---|---|
| **When** | On **final stage approve** (after DB commit) |
| **Toggle** | `FOL_CREATE_SO_ON_FINAL_APPROVAL` / `config('fol.create_so_on_final_approval')` default **true** |
| **Service** | `FolAcumaticaSalesOrderService::createAndLink` → `AcumaticaClient::createSalesOrder` |
| **Required payload (non-negotiable)** | See **§5.0** — Customer ID + each line Inventory ID + Order Qty |
| **Also sent** | Order type `SO`, optional zero unit price, description, customer order / external ref = FOL public ref, optional warehouse |
| **After create** | Pull SO from Acumatica; persist link; notify consultant + invoicing |
| **Idempotent** | If FOL already has SO number / link → `already_linked`, no duplicate create |
| **Failure** | FOL stays approved; error logged; email may omit SO #; cron can retry |

### 5.0 Reminder — what must be pushed on Create SO

When creating the Acumatica Sales Order from a FOL, **only these are mandatory on the PUT**:

| Field | Source on FOL | Acumatica field |
|---|---|---|
| **Customer ID** | `fol_requests.customer_acumatica_id` | `CustomerID` |
| **Inventory ID** (per line) | `fol_request_lines.inventory_id` | `Details[].InventoryID` |
| **Quantity** (per line) | `fol_request_lines.quantity` (must be &gt; 0) | `Details[].OrderQty` |

**Rules:**

1. Do **not** create an SO if customer ID is blank.  
2. Do **not** create an SO if there are no lines with a valid inventory ID and qty &gt; 0.  
3. Skip invalid lines (blank inventory or qty ≤ 0); if **no** valid lines remain → fail create.  
4. Unit price may be zero (FOL policy); warehouse/description optional.  
5. FOL public ref is sent as `CustomerOrder` / `ExternalRef` for traceability — **not** a substitute for customer or inventory.

**Code path today:**

```text
createAndLink($fol)
  → buildLines($fol)     // inventory_id + qty (+ price/warehouse)
  → createSalesOrder(
        customerId: fol.customer_acumatica_id,
        lines: [{ inventory_id, qty, ... }],
        ...
     )
  → PUT SalesOrder {
        CustomerID: { value: "CUSTxxxxx" },
        Details: [
          { InventoryID: { value: "SKU..." }, OrderQty: { value: n } },
          ...
        ]
     }
  → link OrderNbr back to FOL
```

### 5.2 Product options to expose (decisions)

| Option | Decision |
|---|---|
| **A. Auto on final approve** | **Keep as default ON** (ops can set env false) |
| **B. Explicit UI toggle on final approve** | **P1** — “Create Acumatica SO now” checkbox default ON when config enabled |
| **C. Manual button anytime after ready** | **P1** — “Create SO in Acumatica” if no link yet (same service, source=`manual_ui`) |
| **D. Disable auto globally** | Admin/env only — already exists |

**V1 ship message:** Document and surface status of auto-create; add **manual Create SO** + clear SO link panel if not already obvious in UI.

### 5.3 Create SO — rules

- FOL must have ≥1 line.  
- Customer must exist in Acumatica.  
- Warehouse default from config if required by endpoint.  
- Zero price default for FOL commercial policy (configurable).  
- Do not create second SO if `fol_so_links` already has a number.  
- Always write audit event: `so_auto_created` / `so_auto_create_failed` / skip reasons.

---

## 6. Link SO to FOL

### 6.1 As-is link types

| Path | link_type | Who | Rule |
|---|---|---|---|
| Auto after CCO | `auto_cco_approve` | System | Created SO number stored on FOL + `fol_so_links` |
| Manual SO number | `invoice` | `kp.fol.invoice` | SO must exist locally (synced) and **same customer** |
| PO match | `po_match` | Invoice / consultant / accessible | Match customer PO → one SO; multi-match forces pick SO # |

Fields: `linked_so_order_nbrs[]`, `linked_so_status_summary`, `soLinks[]` on present API.

### 6.2 Required UX (all roles that handle FOL)

On FOL detail, always show a **Sales order** panel:

| State | UI |
|---|---|
| No SO | Status: “Not linked” · buttons: **Create SO in Acumatica** (if allowed) · **Link existing SO** · **Match by PO** |
| Create in progress / failed | Show last error from create log · **Retry create** |
| Linked | SO number(s) as links to order detail · status · total if known · **Add another SO** (rare) |

### 6.3 Link rules (no bleed)

- SO customer **must equal** FOL customer.  
- Only SO order type (not CR/IN noise).  
- Scoped users only link SO they can access via DataScope.  
- Linking does not invent ERP data — SO must exist in Acumatica/Sight mirror (create path writes to Acumatica first).

---

## 7. Target flow (product picture)

```text
Consultant submits FOL
    → HOD stages approve…
    → CCO final approve
         ├─ [x] Create SO in Acumatica   ← default when config on
         │      success → auto-link SO-###### to FOL
         │      fail    → FOL approved; show Retry + Link existing
         └─ Notify sales + invoicing with SO # when present

Invoicing / CS (if no SO yet)
    → Create SO  OR  Link SO #  OR  Match PO
    → FOL shows linked SO everywhere (list, email, detail)
```

---

## 8. Permissions

| Action | Permission / gate |
|---|---|
| Create draft / submit | Consultant owner (+ admin) |
| Approve / reject stage | `kp.fol.approve` + stage allow-list |
| Create SO (auto) | System on final approve |
| Create SO (manual button) | Final-approved statuses + `kp.fol.invoice` or admin |
| Link SO / PO match | `kp.fol.invoice` (PO match also consultant for own FOL) |
| Assign technician | `kp.fol.install.manage` |
| View FOL | FOL list scope (own / stage / all) |

---

## 9. Implementation checklist

| ID | Item | Status in code | Product next |
|---|---|---|---|
| F1 | Document full stage flow | This PRD | Publish to ops |
| F2 | Auto create SO on final approve | ✅ `createAndLink` | Keep ON by default |
| F3 | Persist link on create | ✅ `fol_so_links` | Ensure UI always shows |
| F4 | Manual link SO # | ✅ `linkSalesOrder` | Surface buttons clearly |
| F5 | PO match link | ✅ `matchPurchaseOrder` | Same panel |
| F6 | Cron retry create | ✅ retry job | Monitor failures |
| F7 | Manual “Create SO” button when missing | Partial / confirm UI | **Add if missing** |
| F8 | Final-approve checkbox “Create SO” | Not confirmed in UI | **P1 optional** |
| F9 | SO status refresh on FOL | Partial via pull | Refresh link status on open |
| F10 | Testing mode email | ✅ | Production: testing mode off |

---

## 10. Acceptance

- [ ] Ops can follow the step diagram without code.  
- [ ] Final approve with config ON creates Acumatica SO and **links it** to the FOL.  
- [ ] Linked SO number visible on FOL detail and in approval emails when successful.  
- [ ] If create fails, FOL is still approved and user can **Retry create** or **Link existing SO**.  
- [ ] Manual link rejects SO for a different customer.  
- [ ] PO match links one SO or returns candidates.  
- [ ] No second auto-SO when already linked.  
- [ ] Consultant only sees FOLs/customers in scope.

---

## 11. Explicit non-goals

- Editing Acumatica SO lines from FOL after create (change order in ERP).  
- Multi-company / non-KP FOL.  
- Replacing full invoicing ERP screens.  
- Auto-ship or auto-invoice on link.

---

## 12. Related

| Doc / code | Use |
|---|---|
| `config/fol.php` | Create-SO flags, order type, zero price, retry |
| `FolAcumaticaSalesOrderService` | Create + link |
| `FolRequestService::decide / linkSalesOrder / matchPurchaseOrder` | Flow |
| `roles-access.md` | KP FOL permissions |
| Email testing | `FOL_MAIL_TESTING_*` |

---

## 13. Decision summary

| # | Decision |
|---|---|
| 1 | FOL flow is multi-stage approve → **ready_for_invoicing** → install/invoice. |
| 2 | **Create SO in Acumatica** remains automatic on final approve (config). |
| 3 | **Link SO to FOL** always: auto on create; manual SO # and PO match as fallback. |
| 4 | UI must make Create / Link / Retry obvious in one **Sales order** panel. |
| 5 | Failure of SO create must not roll back FOL approval. |
