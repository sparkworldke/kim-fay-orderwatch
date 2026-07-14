# FOL Requests — Product Spec, Guardrails & Acceptance Criteria

**Product:** OrderWatch · Kimfay Professional (KP)  
**Module:** Free On Loan (FOL) Requisitions  
**Status:** Ready for design / engineering break-down  
**Owner:** Product (KP Enabler)  
**Last updated:** 10 Jul 2026 · **Rev:** Admin FOL super-user + **dynamic Admin FOL Settings UI** (stages/mail/attachments)  

**Evidence & artefacts**

| Artefact | Path |
|---|---|
| Current form (Zoho) | `kp/fol/fol-form.png` |
| HOD awaiting approval | `kp/fol/1-notification-hod.png` |
| CCO / COO awaiting approval | `kp/fol/2-notification-cco.png` |
| HOD approved → consultant | `kp/fol/hod-aproval-to-consultant-approval.png` |
| Final approved → consultant | `kp/fol/approval from cco to sales-consultant.png` |
| Approved for invoicing → CS | `kp/fol/approved-for-invoiceing to customer care.png` |
| Rejection → consultant | `kp/fol/rejected-fol.png` |
| Roadmap context | `kp/kp-enabler.md` · `kp/HorecaOS_PRD_v1.0.md` |

**Replace today:** Zoho Forms FOL requisition + email-only approvals.  
**Target:** First-class OrderWatch workflow, scoped to KP customers, **tight to Sales Orders (SO)** and inventory, future-proof for asset lifecycle and Acumatica write-back.

---

## 1. Problem statement

KP consultants currently raise FOL (dispenser / batteries / maintenance / replacement) requests in **Zoho Forms**. Approvals are email-driven (HOD → CCO/COO → Customer Care for invoicing). Pain points:

1. **No portfolio guardrail** — customer search not enforced against the consultant’s OrderWatch/Acumatica portfolio.  
2. **Manual consumables maths** — last-6-month sales/volumes typed by hand; easy to game or mistype.  
3. **No SO link** — after approval, invoicing and fulfilment are disconnected from OrderWatch SO/fill-rate.  
4. **External sender** — emails from `notifications@zohoforms.com` trigger security cautions.  
5. **Weak audit** — comments/approvals live in email threads, not an immutable store.  
6. **Static product list** — not driven by inventory FOL eligibility.

---

## 2. Goals & non-goals

### 2.1 Goals (MVP)

| # | Goal | Measure |
|---|---|---|
| G1 | Consultant can raise a FOL request **only** for customers in their portfolio | 0 cross-portfolio creates in tests |
| G2 | Product lines are FOL-eligible inventory SKUs with **cart-style** multi-line add | ≥1 line required; multi-line supported |
| G3 | Consumable purchase history is **auto-calculated from SO** (last 6 months), overridable only with reason | Default fields system-filled |
| G4 | Prior FOL issues (qty previously issued + last issue date) auto-filled from FOL history | Matches prior approved issues |
| G5 | Configurable multi-step approval (default **HOD → CCO/COO**) with **required comments** | 100% decisions have comment |
| G6 | Full notification set (submit, stage approve, final approve, reject, invoice channel) | Parity with current Zoho set |
| G7 | Email from **`kp@fayshop.co.ke`**, display name **“FOL KP Approvals”** (admin setting) | Configurable; not Zoho |
| G8 | Post-approval **SO matching**: request can link to SO(s) for invoicing/fulfililment status | Status derived from SO |
| G9 | Immutable audit trail for submit / approve / reject / comment / attachment | Queryable by request id |
| G10 | **FOL reporting**: monthly issued + anomaly checks + installation load | Dashboard + export |
| G11 | **Installation workflow**: consultant requests install → calendar slot → **Technician Manager** approves → tech job card | Full install cycle tracked |
| G12 | **Completed job cards must attach FOL + SO** before status = completed | 0 completes without both links |

### 2.2 Non-goals (MVP)

- Full asset register / serial tracking / recall (Phase FOL-5 lifecycle).  
- Acumatica **write** of FOL assets (read SO + inventory only in MVP).  
- Customer self-service portal.  
- Mobile offline FOL form (web responsive first; tech job-card photo upload ok on web/PWA).  
- Replacing Customer Care invoicing process in Acumatica — we **notify and link**, they still invoice.  
- GPS route optimisation for technicians (v2).

---

## 3. Personas & access

| Persona | OrderWatch identity | Can |
|---|---|---|
| **Sales Consultant (KP)** | Role Sales Consultant / `is_consultant` + dept KP / rep portfolio | Create, edit draft, view own requests, receive emails |
| **HOD (KP)** | `org_level = hod` (or configured FOL stage 1 approver) | Approve/reject stage 1 with **required comment** |
| **CCO / COO / Final approver** | Configured stage 2 role(s) or named users | Approve/reject final with **required comment** |
| **Customer Care** | CS role / named recipients | Receive “approved for invoicing”; open request + docs |
| **Sales Operations** | Sales Ops role / named recipients | CC on final approval / invoicing notice |
| **Administrator / Super admin** | `role=Administrator` or `is_super_admin` | **Full FOL super-user (incl. testing)** — see §3.2 |
| **Executive / C-suite** | org_level executive / c_suite | Read-all KP FOL; reporting; optional approve if in chain |
| **Technician Manager** | **Secondary / multi-role** on any KP user (see §3.1) | Approve/reject install calendar allocations; assign technicians; oversee job cards |
| **Technician** | Secondary role or tagged user | View assigned calendar slots; complete job cards |

### 3.1 Multi-role: Technician Manager (and Technician)

OrderWatch already supports **multiple roles** via `user_roles` (primary `users.role` remains for menu defaults; capability = union of all assigned roles / flags).

| Capability flag | Meaning |
|---|---|
| `kp.fol.view` | View FOL module |
| `kp.fol.request` | Create FOL requisitions |
| `kp.fol.approve` | Requisition approval stages |
| `kp.fol.report` | Monthly issued + anomaly reports |
| `kp.fol.install.request` | Request installation / propose calendar slots |
| `kp.fol.install.manage` | **Technician Manager** — approve calendar, assign tech, close disputes |
| `kp.fol.install.execute` | **Technician** — accept slot, attach job card, complete install |

**Product rule:** *Anyone in KP (or Admin) can be given Technician Manager* as an **additional role**, without replacing their primary role (e.g. Sales Consultant + Technician Manager, HOD + Technician Manager). Multiple users may hold `kp.fol.install.manage` concurrently; calendar approval routes to **any** active tech manager (or configured pool / round-robin later).

Admin UI: Team member edit → multi-select **additional roles / capability packs**: `Technician Manager`, `Technician`.

### 3.2 Administrator = full FOL super-user (testing + ops)

For **testing and operational override**, any user with `role = Administrator` **or** `is_super_admin = true` has **all FOL options** without needing HOD/CCO/Tech Manager personas.

| Capability | Admin can? | Implementation note |
|---|:---:|---|
| **Create** FOL draft + submit | **Yes** | All `kp.fol.*` permissions via `hasPermission()` |
| **View all** requests | **Yes** | `canReadAll()` |
| **Approve / reject as HOD** (stage 1) | **Yes** | `stageAllowsUser()` always true for Admin |
| **Approve / reject as CCO/COO** (stage 2) | **Yes** | Same stage bypass |
| **Assign technician** | **Yes** | Explicit `isAdministrator()` check |
| **List technicians** | **Yes** | Same |
| **Link SO / PO match / invoice path** | **Yes** | `kp.fol.invoice` + admin |
| **Install manage** (calendar / job oversight) | **Yes** | Via full permission set |
| **Configure** stages, mail, FOL SKUs | **Yes** | Admin settings |

**Customer still must be KP** (`customer_class` starts with `KP`). Admin is not bound to a sales portfolio for create (org-wide customer scope).

**Notifications for Admin E2E testing**

| When Admin… | Mail templates | Admin receives |
|---|---|---|
| Creates + submits | N1 (HOD pending) | **Yes** — `Administrator` is on HOD stage `role_names` |
| Approves HOD | N2 (to owner), N3 (CCO pending) | **N2** if Admin is submitter; **N3** — Admin on CCO stage |
| Approves CCO (final) | N4 (owner), N5 (invoicing) | **N4** if submitter; **N5** — Administrators included in invoicing recipient set |
| Rejects | N6 (owner) | **Yes** if Admin is submitter |

> **Four-eyes note:** Admin may approve **both** stages alone. That is **intentional for testing** and break-glass ops. Production policy can later require distinct actors per stage (optional hard guardrail, not default).

**E2E test script (Admin)**

1. Create FOL for a KP customer → add FOL-eligible lines → attach file → Submit.  
2. Open pending approval → Approve as HOD (comment required).  
3. Approve as CCO/COO (comment required) → status `ready_for_invoicing`.  
4. Assign Technician from technician list.  
5. Confirm emails/events: N1 → N2/N3 → N4/N5 (and technician_assigned event).

### 3.3 Dynamic configuration (Admin-editable — no code deploy)

All FOL runtime behaviour that used to be hard-coded or env-only is **editable in Administration → FOL Settings**:

| Setting | Storage | Editable |
|---|---|---|
| Mail from address / name | `system_settings` (`fol.*`) | Yes |
| Attachment MIME + max size | `system_settings` | Yes |
| Invoicing notification roles (N5) | `system_settings` | Yes |
| CC watcher emails (all FOL mails) | `system_settings` | Yes |
| Require attachment on submit | `system_settings` | Yes |
| Consumables lookback months | `system_settings` | Yes |
| Duplicate open FOL policy | `system_settings` | Yes |
| **Admin can approve any stage** | `system_settings` | Yes (default on for testing) |
| **Approval chain stages** (key, name, order, roles, users, SLA, active) | `fol_approval_stages` | Yes — add/remove/reorder stages |

**API**

- `GET /api/admin/fol/settings`  
- `PUT /api/admin/fol/settings`  
- `PUT /api/admin/fol/stages`  

**Defaults** still live in `config/fol.php` / env for first boot; Admin overrides win at runtime via `FolSettingsService`.

**Visibility rule (hard):**

- Consultant → **own** requests only (submitted_by = self **or** customer in portfolio); own install requests.  
- HOD → requests in their **subtree / KP dept** (org scope).  
- Final approver, Admin, Executive, C-suite → **all KP FOL** (read) + full reports.  
- **Administrator** → **all actions** in §3.2 (create, multi-stage approve, assign tech, invoice tools).  
- Customer Care / Sales Ops → requests in status `ready_for_invoicing`+ (and history they were notified on).  
- Technician Manager → all install calendar items in KP; job cards.  
- Technician → only **assigned** jobs/slots.

---

## 4. Domain model (tight to SO & inventory)

### 4.1 Core entities

```
fol_requests
  id, public_ref (FOL-2026-000123)
  customer_acumatica_id, customer_name (snapshot)
  sales_consultant_user_id, sales_consultant_email (snapshot)
  request_origin (enum)
  requestor_first_name, requestor_last_name, requestor_phone, requestor_email
  issue_types (json flags)  -- new_dispenser | fol_batteries | maintenance_parts | replacement
  reason_text
  installation_required (bool)
  installation_location
  customer_has_submitted_po (bool)
  consumables_last_purchase_date     -- default from SO
  consumables_sales_6m_kes           -- default from SO
  consumables_volume_6m              -- default from SO
  consumables_metrics_source (system_so | manual_override)
  consumables_override_reason        -- required if manual
  debt_explanation                   -- required
  status (see §5)
  current_stage_key                  -- e.g. hod | cco | done
  linked_so_order_nbrs (json)        -- after match / CS attaches
  linked_so_status_summary           -- derived
  submitted_at, decided_at
  created_by, updated_by
  snapshots: form_json (immutable on submit)

fol_request_lines
  fol_request_id
  line_no
  inventory_id                       -- FK path to acumatica_inventory_items
  product_description (snapshot)
  qty_requested
  qty_previously_issued              -- system default
  date_last_issue                    -- system default
  previous_source (prior_fol | so | manual)
  commitment_sku_ids (json, optional) -- consumables tied to this FOL asset for volume tracking

fol_request_attachments
  fol_request_id, path, original_name, mime, size, uploaded_by, created_at

fol_request_events                   -- append-only audit
  fol_request_id, event_type, actor_user_id, comment, payload_json, created_at

fol_approval_stages                  -- config
  key, name, sort_order, is_active
  assignee_mode (role | user_list | manager_of_submitter)
  role_names[] / user_ids[]
  require_comment (true)
  sla_hours

fol_approval_actions
  fol_request_id, stage_key, actor_user_id
  decision (approved | rejected)
  comment (required)
  decided_at

inventory FOL flag (existing table extension)
  acumatica_inventory_items.is_fol_eligible (bool)
  acumatica_inventory_items.fol_category (dispenser | battery | part | other)
  optional: fol_consumable_links (dispenser_sku → consumable_sku[])
```

### 4.2 SO integration contracts (future-proof)

| Concept | Rule |
|---|---|
| **Customer key** | Always `customer_acumatica_id` (same as SO / customers module) |
| **SKU key** | Always `inventory_id` (same as inventory / SO lines) |
| **Rep key** | `sales_consultant_user_id` + `rep_code` snapshot for SO join |
| **Consumables 6m** | Sum SO lines (`salesOrdersOnly`) for customer where `inventory_id` ∈ consumable set linked to FOL product **or** product_type/posting rules for KP consumables; date window = rolling 6 calendar months EAT |
| **Qty previously issued** | Sum of `qty_requested` on prior FOL lines for same customer+inventory where request status ∈ {`approved`,`ready_for_invoicing`,`invoiced`,`fulfilled`} **plus** optional SO lines tagged FOL (see below) |
| **SO match** | After approval, request may be linked to one or more `acumatica_order_nbr` (manual by CS/Ops **or** auto when SO lines match customer + SKU + open window) |
| **Derived SO status** | From linked SO(s): `open` / `partial` / `completed` / `cancelled` using existing order status fields |
| **Idempotency** | Event log + unique `(customer, inventory, open request)` soft-lock prevents duplicate open FOL for same SKU (configurable) |

**Future-proof tags (don’t implement all now, reserve columns):**

- `fol_requests.acumatica_shipment_nbr`  
- `fol_request_lines.asset_serial`  
- `fol_request_lines.volume_commitment_monthly`  
- `fol_so_links` junction: `fol_request_id`, `sales_order_id`, `link_type` (`invoice` \| `consumable_evidence` \| `auto_match`), `matched_at`, `matched_by`

### 4.3 Installation & calendar entities

```
fol_install_requests
  id, public_ref (INST-2026-000045)
  fol_request_id                 -- optional link to approved FOL (preferred)
  fol_request_line_id            -- which dispenser/line
  customer_acumatica_id
  requested_by_user_id           -- consultant
  install_type (new | maintenance | replacement | other)
  preferred_windows (json)       -- consultant preferences
  location_text, contact_name, contact_phone
  notes
  status: draft | pending_manager | scheduled | in_progress | completed | cancelled | rejected
  approved_by_user_id            -- technician manager
  assigned_technician_user_id
  scheduled_start_at, scheduled_end_at   -- EAT
  completed_at
  created_at, updated_at

fol_install_job_cards
  id, fol_install_request_id
  technician_user_id
  status: open | in_progress | completed | failed
  work_summary, parts_used (json), customer_signoff_name
  started_at, completed_at
  -- attachments via fol_install_job_card_files

fol_install_job_card_files
  job_card_id, path, original_name, mime, size_bytes, uploaded_by, created_at
  -- location captured in background on upload (photo or PDF)
  latitude, longitude, accuracy_m, altitude_m (nullable)
  location_captured_at, location_source (device_gps | exif | manual | unavailable)
  location_consent_ok (bool), client_platform (web|ios|android|pwa)
  raw_geo_json (optional diagnostics; strip before client exposure if sensitive)

fol_anomaly_rules (config)
  key, name, severity (info|warn|critical), is_active, params_json

fol_anomaly_flags
  id, fol_request_id, rule_key, severity, message, metrics_json
  detected_at, acknowledged_by, acknowledged_at, status (open|acked|dismissed)
```

**Calendar model:** install requests with `scheduled_start_at`/`end_at` are calendar events. Conflict detection per technician (no overlapping slots). Open calendar view for consultants (read busy/free by tech or pool) without exposing other customers’ notes beyond necessary.

---

## 5. Lifecycle & status machine

```
draft
  → submitted                 (consultant submits; stage 1 opens)
  → in_approval               (any non-final stage pending)
  → rejected                  (any stage rejects; terminal unless reopen)
  → approved_final            (last stage approved)
  → ready_for_invoicing       (notify Customer Care + Sales Ops)
  → so_linked                 (at least one SO linked)
  → invoiced / fulfilled      (derived from SO status; may be manual toggle until auto)
  → cancelled                 (submitter/admin before final approval only)
```

**Transitions (guarded):**

| From | To | Who | Required |
|---|---|---|---|
| draft | submitted | Consultant (owner) | Valid form + ≥1 line + ≥1 attachment |
| submitted / in_approval | in_approval | Stage N approve | **Comment required** |
| * | rejected | Stage N reject | **Comment required** (reason) |
| last stage approve | approved_final → ready_for_invoicing | System | Fan-out notifications |
| ready_for_invoicing | so_linked | CS / Ops / auto-match | Valid SO nbr for customer |
| * | cancelled | Owner or Admin | Only if not past final approval |

No silent status change without `fol_request_events` row.

### 5.1 Installation request status machine

```
draft → pending_manager → scheduled → in_progress → completed
                      ↘ rejected
                      ↘ cancelled
```

| From | To | Who | Required |
|---|---|---|---|
| draft | pending_manager | Consultant | FOL line approved (or Admin exception); preferred window; location; contact |
| pending_manager | scheduled | **Technician Manager** | Approve; assign technician; set final start/end (no clash) |
| pending_manager | rejected | Technician Manager | Comment required |
| scheduled | in_progress | Technician | Start job card |
| in_progress | completed | Technician | Job card complete + ≥1 evidence photo (configurable) + **FOL attached** + **SO attached** (GR-87, GR-88) |
| scheduled / pending_manager | cancelled | Consultant (owner) or Tech Manager / Admin | Reason |

---

## 6. Capture form (MVP field catalogue)

Mirror current Zoho form; improve with system defaults.

### 6.1 Header

| Field | Required | Source / rule |
|---|---|---|
| Account (customer) | Yes | Search **portfolio only** (consultant); Admin/HOD may elevate for reassign |
| Request origin | Yes | Enum: `sales_consultant_visit` \| `customer_call` \| `email` \| `other` (+ free text if other) |
| Sales consultant | Yes | Default logged-in user; snapshot name/email |
| Sales consultant email | Yes | From user profile |
| Requestor first / last name | Yes | Customer-side contact |
| Requestor phone | Yes | E.164 preferred |
| Requestor email | Yes | Valid email |

### 6.2 Product lines (cart)

| Field | Required | Source / rule |
|---|---|---|
| Product (SKU) | Yes | Only `is_fol_eligible = true` inventory |
| Product quantity | Yes | Integer ≥ 1 |
| Quantity previously issued | Yes | **System default**; editable with flag |
| Date of last issue | Cond. | System default; null if never issued |

- Dynamic add/remove lines (shopping cart).  
- Show inline **last 6m consumable sales for linked consumables** per line (SO-driven).  
- Block submit if any SKU not FOL-eligible.

### 6.3 Issue & site

| Field | Required | Rule |
|---|---|---|
| Issue type(s) | Yes | Multi-select: New dispenser · FOL batteries · Maintenance (parts) · Replacement |
| Reason for request | Yes | Min length 20 chars |
| Installation required | Yes | Boolean |
| Location of install/maint/replace | Yes if installation or site work | Free text |
| Customer already submitted PO? | Yes | Boolean |

### 6.4 Consumables (account level, SO-backed)

| Field | Required | Rule |
|---|---|---|
| Date of last consumables purchased | Yes | Default = max(SO line date) for consumables set |
| Last 6 month consumables sales (KES) | Yes | Default = SUM(line value) SO 6m |
| Last 6 month consumable volumes (units) | Yes | Default = SUM(shipped or order qty) SO 6m |
| Override | No | If user edits system values → must provide `consumables_override_reason` |
| Debt explanation if overdue | Yes | Free text (e.g. “Account okay”) |

### 6.5 Attachments

| Rule | Detail |
|---|---|
| Required | ≥ 1 file |
| Allowed | PDF, XLSX, XLS, CSV, JPG, PNG (configurable) |
| Max size | e.g. 15 MB / file, 50 MB total |
| Purpose | Contract, pivot of historical sales, photos, PO scan |

---

## 7. Approval process (default chain)

Default stages (configurable; **support N approvers**, not hard-coded to 2):

| Order | Stage key | Default assignee | Comment |
|---|---|---|---|
| 1 | `hod` | KP HOD / manager of consultant | Required on approve **and** reject |
| 2 | `cco` | CCO / COO / named final approver(s) | Required; sees HOD comment + full preview |

**Admin setting:** “Approval chain” — ordered list of stages; each stage: role and/or user list; optional “all must approve” vs “any one”.

### 7.1 Happy path

```
Consultant submits
  → Notify Stage 1 (HOD) + in-app task
  → HOD opens preview link, comments, Approves
  → Notify Consultant (HOD approved; channelled to next)
  → Notify Stage 2 (CCO) with HOD comment + lines + SO metrics
  → CCO comments, Approves
  → Status = ready_for_invoicing
  → Notify Consultant (fully approved → CS for invoicing)
  → Notify Customer Care + Sales Ops (invoicing pack)
  → CS links SO / creates invoice SO in Acumatica
  → OrderWatch matches SO → status so_linked / invoiced
```

### 7.2 Rejection path

```
Any stage Rejects (comment required)
  → Status = rejected
  → Notify Consultant + CC configured stakeholders
  → Request read-only; new request required to retry (or Admin “reopen to draft”)
```

---

## 8. Notifications catalogue (parity + OrderWatch upgrades)

**Mail identity (settings)**

| Setting | Value |
|---|---|
| From address | `kp@fayshop.co.ke` (configurable) |
| From name | `FOL KP Approvals` (configurable) |
| Reply-to | Optional KP ops mailbox |

All messages include: security footer optional (internal domain — drop “external Zoho” warning by sending from Kim-Fay domain), **deep link** to OrderWatch preview (`/app/kp/fol/{id}`), public ref, customer name, line summary table, attachment list or secure download links.

### 8.1 Message matrix

| ID | Trigger | To | CC (default) | Subject pattern | Body must include |
|---|---|---|---|---|---|
| **N1** | Submitted | Stage 1 approver(s) | FOL ops watchers (config) | `A requisition for {Customer} is awaiting your approval` | Submitter, datetime, summary table (Product · Qty · Qty previously issued · Date last issue), reason, debt explanation, attachments, **Approve/Deny deep link** |
| **N2** | Stage 1 approved | Consultant | Stage watchers | `Your requisition has been Approved by {Approver}! — {Customer}` | Full field dump (origin, contacts, lines, issues, consumables metrics, debt), HOD comment, “channelled to {Next Approver}” |
| **N3** | Stage 2 (final) pending | Stage 2 approver(s) | — | `A requisition for {Customer} is awaiting your approval` | Same as N1 **plus prior stage comments** (e.g. “Jermaine’s comment”) |
| **N4** | Final approved | Consultant | HOD, ops | `Hurray! {Customer} requisition has been approved!` | Line table; “passed all levels; channelled to customer care for invoicing”; ops will fast-track |
| **N5** | Final approved (invoicing) | Customer Care (primary) | Consultant, HOD, Sales Ops, configured list | `Approval of {Customer}` / `FOL approved for invoicing — {Customer}` | “Find the approval of [table] for invoicing”; supporting docs; creator name |
| **N6** | Rejected (any stage) | Consultant | HOD, CS ops (config) | `Your Requisition for {Customer} has been denied!` | Line table; **rejection reason** (comment); full snapshot of form fields |

**In-app (OrderWatch notifications / tasks)**  
Mirror N1–N6 for users with accounts (approver inbox on `/app/kp/fol`).

### 8.2 Email content rules

1. Summary table columns **exactly**: Product Type · Product Quantity · Quantity previously issued · Date of last issue.  
2. Always show **system SO metrics** used at submit time (snapshot — do not recompute in email).  
3. Deep links require auth; expired session → login → return URL.  
4. Attachments: prefer secure app links over raw multi-MB attachments when total > threshold; still allow attach for small packs.  
5. No Zoho URLs.

### 8.3 Installation notifications

| ID | Trigger | To | Subject / intent |
|---|---|---|---|
| **N7** | Install requested | All users with `kp.fol.install.manage` | `Installation request for {Customer} awaiting calendar approval` — preferred windows, FOL ref, deep link |
| **N8** | Install approved & scheduled | Consultant + assigned Technician | `Installation scheduled — {Customer} {date/time EAT}` — calendar details, location, job card link |
| **N9** | Install rejected by Tech Manager | Consultant | Reason + FOL/install refs |
| **N10** | Job card completed | Consultant + Tech Manager (+ HOD optional) | Summary + attachments; link to report |
| **N11** | Anomaly critical opened | HOD + Admin + configured watchers | `FOL anomaly: {rule} — {Customer}` |

Same mail identity as FOL approvals (`kp@fayshop.co.ke` / `FOL KP Approvals` or sub-setting `FOL KP Installations`).

---

## 9. FOL reporting

Routes (gated by `kp.fol.report` or Admin/Executive/C-suite/HOD):  
`/app/kp/fol/reports` · export Excel same filters as ops modules.

### 9.1 Monthly issued report

**Definition — “Issued” (configurable primary metric):**

| Mode | Counts when |
|---|---|
| **A — Approved final** (default for commercial) | Status reached `approved_final` / `ready_for_invoicing` in the calendar month (EAT) |
| **B — SO linked / invoiced** (ops) | First SO link or SO completed in month |
| **C — Installed** | Install job card `completed` in month |

UI toggles **Issued basis** = A | B | C; default A. All three available as columns.

**Dimensions & filters**

- Month / date range (EAT)  
- Customer, consultant, HOD subtree  
- SKU / FOL category (dispenser, battery, part)  
- Issue type (new / batteries / maintenance / replacement)  
- Region / location text (if captured)  
- SO linked Y/N; install completed Y/N  

**Outputs**

| View | Content |
|---|---|
| KPI strip | Total FOL requests issued · total units · distinct customers · avg approval cycle days · % with SO link · % installed |
| By product | SKU, units issued, # requests, repeat-issue rate |
| By consultant | Requests, units, approval cycle, rejection rate |
| By customer | Units, last issue date, consumables 6m at submit, open anomalies |
| Trend | 12-month sparkline units + requests |
| Export | XLSX: Summary · Lines · Installations · Anomalies (tabs) |

**Tight to SO:** optional join column `linked_so_order_nbrs`, order totals, and whether SO contains FOL SKU lines.

### 9.2 Anomaly checking

Scheduled job (e.g. hourly + on submit/approve) evaluates **rules**; writes `fol_anomaly_flags`. Surface on request detail, reports, and N11 for critical.

| Rule key | Severity | Logic (defaults — all configurable) |
|---|---|---|
| `high_qty_vs_history` | warn | `qty_requested` > max(prior issued, 1) × N (default **3**) without override note |
| `zero_consumables_new_dispenser` | warn | Issue includes new dispenser **and** last-6m consumables sales = 0 (and not new account flag) |
| `low_consumables_vs_fleet` | warn | Prior FOL dispensers at site ≥ 3 **and** 6m volumes below floor per dispenser |
| `rapid_repeat` | critical | Same customer + SKU re-requested within D days (default **90**) of prior approval |
| `override_metrics` | info | Consumables metrics were manually overridden |
| `debt_flag_keywords` | warn | Debt explanation matches overdue patterns (configurable keywords) or credit hold true |
| `no_so_after_approve` | warn | Days since `ready_for_invoicing` > T (default **7**) and no SO link |
| `install_sla_breach` | warn | Install requested, not scheduled within S days (default **5**) |
| `qty_vs_so_runrate` | warn | Requested batteries/parts ≫ 6m SO run-rate for linked consumables |
| `duplicate_open` | critical | Second open FOL for same customer+SKU while first not terminal |

**Anomaly UX**

- Badge on list rows; filter “Has open anomaly”.  
- Manager can **Acknowledge** or **Dismiss** (comment required for dismiss).  
- Monthly report tab: open vs closed anomalies by rule.  
- Does **not** auto-block submit unless rule `hard_block=true` (admin per rule).

### 9.3 Installation report

- Installs requested / approved / completed per month  
- Average days: FOL approve → install complete  
- Technician utilisation (jobs completed, on-time %)  
- Job cards missing photos  
- Calendar rejection rate  

---

## 10. Installation calendar & job cards

### 10.1 Intent

After FOL is approved (or for maintenance/replacement on existing assets), **consultants request installation**. Capacity is managed on an **open calendar**. Slots are **not confirmed** until a **Technician Manager** approves and assigns a technician. Technicians execute **job cards** with evidence.

### 10.2 Who does what

```
Consultant                     Technician Manager              Technician
───────────                    ──────────────────              ──────────
Request install ─────────────► Review calendar request
 (link FOL line,               Approve + assign tech ─────────► See slot
  preferred windows,           or Reject + comment              Start job card
  site contact)                                                 Attach photos / parts
                                                                Complete job card ──► Notify consultant
```

### 10.3 Consultant — request installation

**Entry points**

- From FOL request detail when status ≥ `ready_for_invoicing` **and** issue type includes new dispenser / replacement / maintenance (or always, if Admin allows “install-only”).  
- From `/app/kp/fol/installations/new`.

**Fields**

| Field | Required | Notes |
|---|---|---|
| FOL request + line | Yes* | *Optional only with Admin “standalone install” flag |
| Install type | Yes | new \| maintenance \| replacement \| other |
| Preferred windows | Yes | 1..3 ranges (date + AM/PM or exact start/end) |
| Location | Yes | Default from FOL installation_location |
| Site contact name/phone | Yes | Default from FOL requestor |
| Notes / access instructions | No | Gate codes, etc. |
| View open calendar | — | Read-only busy blocks for tech pool (no other customer PII in titles for consultants — show “Busy” only) |

On submit → status `pending_manager` → **N7**.

### 10.4 Open calendar

| View | Audience | Shows |
|---|---|---|
| Team calendar | Tech Manager, Admin, HOD, Exec | All scheduled installs; filter by tech |
| My calendar | Technician | Own assignments |
| Availability (open) | Consultant | Free/busy by day for pool; propose slot that does not clash |

**Rules**

- Working hours config (default 08:00–17:00 EAT Mon–Sat).  
- Slot granularity 30/60 min (config).  
- **No double-booking** same technician (hard).  
- Optional max jobs/day per technician.  
- Timezone always **Africa/Nairobi** display.

### 10.5 Technician Manager approval

- Queue: `/app/kp/fol/installations?status=pending_manager`.  
- Actions: **Approve** (pick technician, confirm/adjust start-end) | **Reject** (comment required) | **Request reschedule** (optional status).  
- On approve → `scheduled` + **N8** to consultant and technician.  
- Multiple Technician Managers: any one may act (first decision wins; concurrent decide → optimistic lock).

### 10.6 Job cards

Created automatically when status becomes `scheduled` (or on tech “Start”).

| Job card field | Required at complete |
|---|---|
| Work summary | Yes |
| Parts used | No (structured list) |
| Customer sign-off name | Yes |
| Photos / files | ≥ 1 (config); **photo (JPG/PNG/WEBP) or PDF** |
| File location (background) | **Captured on upload** — lat/lng/accuracy when device allows (GR-89) |
| **FOL request + line** | **Yes — required to complete (GR-87)** |
| **Sales Order (SO)** | **Yes — required to complete (GR-88)**; inherit from FOL or attach on job |
| Outcome | completed \| failed (+ reason if failed) |

### 10.6.1 Job card uploads — photo or PDF + background location

| Rule | Detail |
|---|---|
| **Allowed types** | Images: `image/jpeg`, `image/png`, `image/webp`. Documents: `application/pdf`. (Config allowlist; max size same as FOL attachments.) |
| **When location is captured** | **On upload**, in the **background** (non-blocking). User is not forced through a separate “pin location” step. |
| **How** | Browser/PWA: `navigator.geolocation.getCurrentPosition` (or watch once) at start of upload, with short timeout. Mobile WebView: same. If image has EXIF GPS, merge as fallback/`exif` source. PDF: device GPS only (no EXIF). |
| **Stored per file** | `latitude`, `longitude`, `accuracy_m`, `location_captured_at`, `location_source`, `location_consent_ok`. |
| **Failure / deny** | If user denies permission or GPS unavailable: still **allow upload**; set `location_source=unavailable`, null coords. Optional config: **require_location_on_complete** — if true, at least one file on the job card must have coords before complete (default **false** for pilot; recommend **true** for production installs). |
| **Consent** | First use: short notice that install evidence may include location for audit (align with field GPS consent in KP enabler). Logged via `location_consent_ok`. |
| **Privacy** | Location only on job-card files; visible to Tech Manager, HOD, Admin, Exec — not to unrelated consultants. |
| **UX** | Upload control: “Add photo or PDF”. Subtle status: “Location saved” / “Location unavailable” under each thumbnail; never block upload spinner on GPS. |

Completed job → only after **FOL + SO** attached; FOL line/install marked installed; feeds **Monthly issued (basis C)** and installation report; **N10**.

### 10.7 Link to FOL + SO (required for completion)

| Link | When required | Rule |
|---|---|---|
| **FOL** | Always for completion | Install/job card must reference `fol_request_id` + `fol_request_line_id` (dispenser/line being installed). Standalone installs (if ever enabled) still cannot complete without a FOL created and linked first. |
| **SO** | Always for completion | At least one Sales Order must be attached: either inherited from `fol_so_links` / FOL request linked SOs, **or** explicitly set on the install/job card (`acumatica_order_nbr` / `sales_order_id`). SO **customer_acumatica_id** must equal FOL customer. Prefer SO lines that include the FOL SKU (warn if not). |

**Completion gate (hard):**

```
complete_job_card allowed IFF
  fol_request_id IS NOT NULL
  AND fol_request_line_id IS NOT NULL
  AND resolved_so_link_count >= 1
  AND every linked SO.customer_acumatica_id = fol.customer_acumatica_id
  AND evidence files >= min
  AND work_summary + customer_signoff present
```

If FOL is approved but **not yet SO-linked**, technician/CS/Ops must **link SO before** job card can move to `completed` (UI: “Link SO to complete”). No silent complete without both attachments — **GR-87 / GR-88**.

- Job card UI shows FOL ref + SO nbr(s) read-only once linked.  
- Future: completion can trigger asset serial capture (FOL-7).

---

## 11. Guardrails (non-negotiable)

### 11.1 Portfolio & identity

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-01 | Consultant can only select customers in their **scoped portfolio** | API 403 if customer not in scope |
| GR-02 | Customer must be **KP segment** (`customer_class` starts with `KP`) unless Admin override | Validation error |
| GR-03 | Submitter identity = authenticated user (consultant email not free-typed for security) | Snapshot from session |
| GR-04 | Impersonation forbidden | No alternate consultant_id unless Admin |
| GR-05 | Technician Manager is **additive multi-role**; primary role unchanged | `user_roles` / capability packs |

### 11.2 Catalogue & cart

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-10 | Only `is_fol_eligible` SKUs on lines | Server validates inventory flag |
| GR-11 | ≥ 1 line; qty ≥ 1 integer | Validation |
| GR-12 | Duplicate open request for same customer+SKU blocked (config: soft warn vs hard block) | Unique partial index / check |
| GR-13 | Product description snapshotted at submit | Immutable after submit |

### 11.3 SO-backed metrics

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-20 | Last-6m consumables sales/volume **default from SO** | Service: `FolConsumablesMetrics` |
| GR-21 | Manual override requires reason (≥ 10 chars) | Validation |
| GR-22 | Metrics snapshot frozen at submit | Stored on request row |
| GR-23 | Prior issued qty/date from FOL history (+ optional SO FOL tags) | Service at line add |
| GR-24 | Use `salesOrdersOnly` (exclude non-SO noise) | Same scope as ops modules |

### 11.4 Approvals

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-30 | Approve/reject **requires comment** | API 422 if empty |
| GR-31 | Only assigned stage actors can decide | 403 otherwise |
| GR-32 | Cannot skip stages | Engine enforces order |
| GR-33 | Chain is **configurable** (1..N stages; multi-user) | Admin UI |
| GR-34 | Decision is immutable; corrections = new event, not edit | Append-only |
| GR-35 | SLA: optional escalate if stage open > `sla_hours` | Cron + notify |

### 11.5 Attachments & data

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-40 | ≥ 1 attachment on submit | Validation |
| GR-41 | MIME/size allowlist | Upload service |
| GR-42 | Virus scan hook (future) | Interface reserved |
| GR-43 | Debt explanation required even if “Account okay” | Validation |

### 11.6 Post-approval / SO match

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-50 | Only `ready_for_invoicing`+ can attach SO | Status check |
| GR-51 | Linked SO must belong to **same customer_acumatica_id** | Validation |
| GR-52 | Prefer SO lines that include FOL SKUs (warn if not) | Soft warn |
| GR-53 | Status `invoiced`/`fulfilled` derived from SO when linked | Read model |
| GR-54 | Auto-match job optional: open SO within N days of approval matching SKU+customer | Feature flag |

### 11.7 Notifications & security

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-60 | From address/name from FOL mail settings | Config |
| GR-61 | No PII to wrong consultant | Recipient resolution from request + stage |
| GR-62 | Deep links authorize viewer scope | Middleware |
| GR-63 | All emails logged (to, cc, template id, request id) | `fol_request_events` + mail log |

### 11.8 Reporting & anomalies

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-70 | Monthly issued uses **EAT** calendar months | Timezone config |
| GR-71 | Report export respects viewer scope (consultant ≠ all-KP) | Same as list APIs |
| GR-72 | Anomaly dismiss requires comment | Validation |
| GR-73 | Critical anomalies never auto-delete | Soft status only |

### 11.9 Installation calendar

| ID | Guardrail | Enforcement |
|---|---|---|
| GR-80 | Install request only if FOL line eligible (approved path) unless Admin standalone | Status check |
| GR-81 | Only **Technician Manager** (`kp.fol.install.manage`) can approve calendar | 403 otherwise |
| GR-82 | Approve requires assigned technician + non-overlapping slot | Validation |
| GR-83 | Consultant open calendar shows **busy/free only**, not other customers’ names | DTO strip |
| GR-84 | Technician sees only own jobs | Scope |
| GR-85 | Job card complete requires evidence files (config min count); types **photo or PDF** | Validation + MIME allowlist |
| GR-86 | Cancel after `in_progress` only by Tech Manager/Admin | Status rules |
| **GR-87** | **Completed install job card MUST be attached to a FOL request (and line)** | Hard block complete if `fol_request_id` / `fol_request_line_id` null |
| **GR-88** | **Completed install job card MUST be attached to at least one Sales Order (SO)** | Hard block complete unless linked SO exists on FOL **or** job card / install has `sales_order_id` / `acumatica_order_nbr`; SO customer must match FOL customer |
| **GR-89** | **On job-card file upload (photo or PDF), capture and save location in the background** | Non-blocking geolocation (or EXIF GPS for images); persist lat/lng/accuracy/source on `fol_install_job_card_files`; upload succeeds even if location unavailable unless `require_location_on_complete` |

---

## 12. UX requirements (web)

### 12.1 Create / edit (`/app/kp/fol/new`)

1. Customer typeahead (portfolio). On select → show customer class, credit hints if available, last SO date.  
2. Cart for FOL SKUs with live “previously issued” + consumable 6m chip.  
3. Consumables block prefilled; “Use system figures” badge; edit → force override reason.  
4. Attachment dropzone.  
5. Review step (read-only summary) → Submit.

### 12.2 Approver preview (`/app/kp/fol/{id}`)

1. Header: status, stage, SLA, **anomaly badges**.  
2. Full form snapshot + line table.  
3. **SO metrics panel** (frozen) + link “View customer SOs”.  
4. Prior FOL history for customer.  
5. Attachment gallery.  
6. Comment box + Approve / Reject (destructive).  
7. Timeline of events.  
8. Actions: **Request installation** (if eligible).

### 12.3 Lists

| View | Audience |
|---|---|
| My requests | Consultant |
| Pending my approval | Approvers |
| Ready for invoicing | Customer Care / Sales Ops |
| All KP FOL | Admin / Executive / C-suite / HOD |
| Anomalies | HOD / Admin / Exec |
| Installations | Consultant / Tech Manager / Technician |

Filters: status, customer, consultant, date, SKU, SO linked Y/N, has anomaly, install status.

### 12.4 Reports (`/app/kp/fol/reports`)

Tabs: **Monthly issued** · **Anomalies** · **Installations** — filters, KPI cards, tables, Excel export.

### 12.5 Installation calendar (`/app/kp/fol/calendar`)

- Week/month toggle, EAT.  
- Tech Manager: drag-adjust on approve (optional v1.1); list + calendar dual view.  
- Consultant: request drawer + free/busy.  
- Technician: “My jobs” + job card form.

---

## 13. Acceptance criteria (PM)

Format: **Given / When / Then**. Engineering should map each AC to automated tests where marked **[Auto]**.

### 13.1 Access & portfolio

| ID | Acceptance criteria |
|---|---|
| AC-01 | **Given** a KP consultant with portfolio {A}, **when** they search customers for a new FOL, **then** only customers in portfolio (and KP class) appear. **[Auto]** |
| AC-02 | **Given** consultant portfolio excludes customer B, **when** API create uses B, **then** response is 403/422 and no row is stored. **[Auto]** |
| AC-03 | **Given** MT/GT user without `kp.view`, **when** they open `/app/kp/fol`, **then** access denied. **[Auto]** |
| AC-04 | **Given** Admin/Executive/C-suite, **when** they open FOL list, **then** all KP FOL requests are visible (read). **[Auto]** |
| AC-05 | **Given** a KP Sales Consultant user, **when** Admin assigns additional role **Technician Manager**, **then** user retains consultant FOL create rights **and** gains install approval queue. **[Auto]** |
| AC-06 | **Given** user without `kp.fol.install.manage`, **when** they approve an install slot, **then** 403. **[Auto]** |

### 13.2 Form & inventory

| ID | Acceptance criteria |
|---|---|
| AC-10 | **Given** inventory item with `is_fol_eligible=false`, **when** added to cart, **then** UI and API reject it. **[Auto]** |
| AC-11 | **Given** valid header + 2 FOL lines + attachment + required fields, **when** submit, **then** status=`submitted`, stage=`hod`, event logged. **[Auto]** |
| AC-12 | **Given** missing debt explanation or zero lines or no attachment, **when** submit, **then** 422 with field errors. **[Auto]** |
| AC-13 | **Given** multi-line cart, **when** remove a line before submit, **then** only remaining lines persist. **[Auto]** |

### 13.3 SO-backed metrics

| ID | Acceptance criteria |
|---|---|
| AC-20 | **Given** customer has SO consumable lines in last 6 months totalling X KES / Y units, **when** consultant selects customer, **then** consumables fields default to X/Y and last purchase date. **[Auto]** |
| AC-21 | **Given** system defaults shown, **when** consultant edits sales figure without override reason, **then** submit fails. **[Auto]** |
| AC-22 | **Given** prior approved FOL for same customer+SKU qty 5 on date D, **when** new line for SKU added, **then** qty previously issued defaults to ≥5 and last issue ≈ D. **[Auto]** |
| AC-23 | **Given** submit succeeded, **when** later SO data changes, **then** stored consumables snapshot on the request does **not** change. **[Auto]** |

### 13.4 Approvals

| ID | Acceptance criteria |
|---|---|
| AC-30 | **Given** pending HOD stage, **when** HOD approves without comment, **then** 422. **[Auto]** |
| AC-31 | **Given** pending HOD stage, **when** HOD approves with comment, **then** N2 email to consultant and N3 to CCO; stage advances. **[Auto]** |
| AC-32 | **Given** pending CCO stage, **when** CCO rejects with reason, **then** status=`rejected`, N6 to consultant, no invoicing mail. **[Auto]** |
| AC-33 | **Given** final approval, **when** last stage approves, **then** status=`ready_for_invoicing` and N4+N5 sent. **[Auto]** |
| AC-34 | **Given** config with 3 stages, **when** request runs, **then** engine visits all 3 in order. **[Auto]** |
| AC-35 | **Given** user not in stage assignees, **when** they POST decision, **then** 403. **[Auto]** |

### 13.5 Notifications

| ID | Acceptance criteria |
|---|---|
| AC-40 | **Given** FOL mail settings, **when** any FOL email sends, **then** From is `kp@fayshop.co.ke` and name is `FOL KP Approvals` (or configured overrides). **[Auto]** |
| AC-41 | **Given** N1 send, **when** HOD opens mail, **then** subject/body contain customer name, line table (4 columns), reason, debt, and working deep link path `/app/kp/fol/{id}`. |
| AC-42 | **Given** HOD approval, **when** N2 sends, **then** body states channelled to next approver and includes HOD comment. |
| AC-43 | **Given** final approval, **when** N5 sends, **then** Customer Care is To; consultant and configured ops are CC; subject indicates invoicing. |
| AC-44 | **Given** rejection, **when** N6 sends, **then** denial reason equals approver comment. **[Auto]** |
| AC-45 | **Given** install request submitted, **when** N7 sends, **then** all Technician Managers receive it. **[Auto]** |
| AC-46 | **Given** install approved, **when** N8 sends, **then** consultant and assigned technician are recipients with schedule in EAT. **[Auto]** |

### 13.6 SO matching (post-approval)

| ID | Acceptance criteria |
|---|---|
| AC-50 | **Given** request `ready_for_invoicing`, **when** CS links SO for same customer, **then** link stored and status ≥ `so_linked`. **[Auto]** |
| AC-51 | **Given** SO for **different** customer, **when** CS attempts link, **then** 422. **[Auto]** |
| AC-52 | **Given** linked SO later completed in OrderWatch sync, **when** request is viewed, **then** derived fulfilment status reflects SO completed (read model). **[Auto]** |
| AC-53 | **Given** auto-match enabled, **when** new SO appears with matching customer+FOL SKU within N days of approval, **then** system proposes or attaches link with `link_type=auto_match` and event log. **[Auto]** |

### 13.7 Audit & immutability

| ID | Acceptance criteria |
|---|---|
| AC-60 | **Given** any status transition, **when** completed, **then** exactly one append-only event exists with actor + timestamp. **[Auto]** |
| AC-61 | **Given** submitted request, **when** consultant PATCHes line qty, **then** rejected (immutable) unless status=`draft`. **[Auto]** |
| AC-62 | **Given** Admin opens audit, **when** filtering by request id, **then** full timeline of submit/approve/reject/email/so_link is visible. |

### 13.8 Non-functional

| ID | Acceptance criteria |
|---|---|
| AC-70 | Preview page p95 &lt; 2.5s for request with 10 attachments metadata (files lazy). |
| AC-71 | File upload rejects disallowed MIME and oversize. **[Auto]** |
| AC-72 | All FOL APIs require auth + KP capability. **[Auto]** |

### 13.9 Monthly issued reporting

| ID | Acceptance criteria |
|---|---|
| AC-80 | **Given** 3 FOL requests final-approved in July EAT and 1 in June, **when** user runs Monthly issued for July basis=A, **then** count = 3 and units sum matches line qtys. **[Auto]** |
| AC-81 | **Given** basis=C (installed), **when** only 1 of those has completed job card in July, **then** issued units reflect that install only. **[Auto]** |
| AC-82 | **Given** consultant viewer, **when** they open monthly report, **then** only their portfolio/requests appear. **[Auto]** |
| AC-83 | **Given** HOD/Exec, **when** export Excel, **then** file contains Summary + Lines tabs and respects filters. |

### 13.10 Anomaly checking

| ID | Acceptance criteria |
|---|---|
| AC-90 | **Given** rule `rapid_repeat` (90 days) and prior approval 30 days ago same SKU, **when** new request submitted, **then** critical anomaly flag is created. **[Auto]** |
| AC-91 | **Given** new dispenser + zero 6m consumables SO, **when** submitted, **then** `zero_consumables_new_dispenser` warn flag exists. **[Auto]** |
| AC-92 | **Given** open anomaly, **when** manager dismisses without comment, **then** 422. **[Auto]** |
| AC-93 | **Given** critical anomaly, **when** detected, **then** N11 notified to configured watchers. **[Auto]** |
| AC-94 | **Given** hard_block rule enabled, **when** condition met at submit, **then** submit rejected with rule message. **[Auto]** |

### 13.11 Installation calendar & job cards

| ID | Acceptance criteria |
|---|---|
| AC-100 | **Given** FOL ready_for_invoicing with installation required, **when** consultant submits install request with preferred windows, **then** status=`pending_manager` and N7 fires. **[Auto]** |
| AC-101 | **Given** pending install, **when** Technician Manager approves with technician T and slot overlapping T’s existing job, **then** 422 conflict. **[Auto]** |
| AC-102 | **Given** pending install, **when** Technician Manager approves valid slot for T, **then** status=`scheduled`, N8 to consultant + T. **[Auto]** |
| AC-103 | **Given** scheduled job, **when** technician completes job card without files (min=1), **then** 422. **[Auto]** |
| AC-103b | **Given** job card, **when** technician uploads a **PDF** or **photo**, **then** both MIME types are accepted per allowlist. **[Auto]** |
| AC-103c | **Given** upload with device GPS available, **when** file upload completes, **then** file row has non-null lat/lng and `location_source` in (`device_gps`,`exif`) without blocking upload. **[Auto]** |
| AC-103d | **Given** user denies geolocation, **when** they upload a photo/PDF, **then** file still stores; `location_source=unavailable`. **[Auto]** |
| AC-103e | **Given** `require_location_on_complete=true` and all files lack coords, **when** complete job card, **then** 422. **[Auto]** |
| AC-104 | **Given** valid job card complete **with FOL + SO linked** (+ evidence), **when** submitted, **then** install=`completed`, N10 sent, monthly basis=C counts it. **[Auto]** |
| AC-105 | **Given** consultant open calendar, **when** another customer has a slot, **then** UI shows Busy without that customer’s name. **[Auto]** |
| AC-106 | **Given** two Technician Managers, **when** either approves first, **then** second approve attempt fails as already scheduled. **[Auto]** |

### 13.12 Administrator super-user (testing)

| ID | Acceptance criteria |
|---|---|
| AC-120 | **Given** user with `role=Administrator` (or `is_super_admin`), **when** they create + submit a FOL for a KP customer, **then** request is created and status reaches `submitted`/`in_approval`. **[Auto]** |
| AC-121 | **Given** Admin and a request at HOD stage, **when** they approve with comment, **then** stage advances to CCO (or final if single stage). **[Auto]** |
| AC-122 | **Given** Admin and a request at CCO stage, **when** they approve with comment, **then** status becomes `ready_for_invoicing`. **[Auto]** |
| AC-123 | **Given** Admin and an active Technician user, **when** they assign that technician on a FOL, **then** `assigned_technician_user_id` is set and event `technician_assigned` is logged. **[Auto]** |
| AC-124 | **Given** Admin is on stage role lists, **when** submit fires, **then** N1 recipients include at least one Administrator email (when active Admins exist). **[Auto]** |
| AC-107 | **Given** job card with evidence but **no FOL request/line**, **when** technician marks complete, **then** 422 citing GR-87; status stays `in_progress`. **[Auto]** |
| AC-108 | **Given** job card linked to FOL but **no SO** on FOL or install, **when** technician marks complete, **then** 422 citing GR-88; UI prompts “Link SO to complete”. **[Auto]** |
| AC-109 | **Given** SO for a **different** customer than the FOL, **when** attach SO for completion, **then** 422. **[Auto]** |
| AC-110 | **Given** FOL with valid SO link for same customer, **when** technician completes job card with evidence, **then** complete succeeds and job card stores FOL ref + SO nbr. **[Auto]** |

---

## 14. Phased delivery

| Phase | Scope | Exit |
|---|---|---|
| **FOL-0** | Schema, FOL inventory flag admin, permissions (incl. multi-role tech packs), empty list UI | Flags settable; routes gated |
| **FOL-1** | Create/submit form, SO metrics service, attachments, draft | Consultant can submit valid request |
| **FOL-2** | Configurable stages, HOD+CCO decisions, full email matrix N1–N6 | Zoho path can be turned off for pilot |
| **FOL-3** | CS invoicing queue, manual SO link, derived status | CS works only in OrderWatch |
| **FOL-4** | Auto SO match job, duplicate guards, SLA escalation | Match rate reported |
| **FOL-5** | **Monthly issued report + anomaly engine** (rules AC-80–94) | HOD uses report in monthly review |
| **FOL-6** | **Installation calendar + Tech Manager multi-role + job cards** (N7–N10, AC-100–106) | Install cycle live for pilot techs |
| **FOL-7** | Asset serial / volume commitment / recall (HorecaOS lifecycle) | Separate epic |

---

## 15. Admin & settings

| Setting | Description |
|---|---|
| FOL mail from address / name | Default `kp@fayshop.co.ke` / `FOL KP Approvals` |
| Approval chain | Ordered stages, assignees, require_comment, sla_hours |
| Notification CC lists | Per template N1–N11 |
| Duplicate policy | block \| warn |
| Auto-match window (days) | Default 30 |
| Consumable SKU rules | Explicit links and/or class/brand rules |
| Attachment limits | Size/MIME |
| **Monthly issued default basis** | A approved \| B SO \| C installed |
| **Anomaly rules** | Enable, severity, params, hard_block |
| **Working hours / slot size** | Calendar grid |
| **Job card min files** | Default 1 (**photo or PDF**) |
| Job card allowed MIME | jpeg, png, webp, pdf |
| Capture location on job-card upload | Default **on** (background, non-blocking) |
| require_location_on_complete | Default off (pilot); recommend **on** in production |
| **Technician Manager pool** | Users with `kp.fol.install.manage` (assign via multi-role) |
| **Standalone install** | Allow install without FOL request (default off) |

**Team admin:** multi-select additional roles — **Technician Manager**, **Technician** — assignable to any KP (or Admin) user without removing primary role.

---

## 16. Analytics & reporting (summary)

| Report | Audience | Core questions answered |
|---|---|---|
| Monthly issued | HOD, Exec, C-suite, Admin | What was issued this month (units/SKU/rep)? |
| Anomalies | HOD, Admin, Exec | Where are risky or non-compliant FOL patterns? |
| Installations | Tech Manager, HOD, Ops | Are installs scheduled and completed on time? |
| Ops funnel | All of above | Open by stage; SO link lag; approval cycle |

Visible to: Admin, Executive, C-suite, KP HOD (+ Tech Manager for install report). Consultants: own slice only.

---

## 17. Open product questions

1. Exact **CCO vs COO** title for stage 2 in OrderWatch roles?  
2. Hard-block vs warn on **duplicate** open FOL same SKU?  
3. Is **credit hold** a hard block on submit (recommended: yes if Acumatica status available)?  
4. Minimum **trading history** (PRD: ≥ 3 months) — enforce in MVP?  
5. Customer Care primary inbox list (names from N5 sample: expand via settings).  
6. Should final approval create a **zero-price / memo SO** suggestion, or only notify?  
7. Default **Technician Manager** pool — named users at go-live?  
8. Can install be requested **before** invoicing SO exists, or only after `ready_for_invoicing`? (Recommend: after ready_for_invoicing.)  
9. Should anomaly `rapid_repeat` hard-block or warn-only at pilot?

---

## 18. Success metrics (90 days post-go-live)

| Metric | Target |
|---|---|
| Zoho FOL form usage | 0 for pilot team |
| Requests with SO-linked metrics snapshot | 100% |
| Approvals with non-empty comments | 100% |
| Median submit → final approve | ≤ 2 business days |
| Approved requests linked to SO within 7 days | ≥ 80% |
| Cross-portfolio create attempts blocked | 100% |
| Monthly issued report used in KP review | ≥ 1 export or view / month by HOD |
| Open critical anomalies &gt; 14 days | ≤ 5% of flags |
| Install requests approved within 5 business days | ≥ 85% |
| Completed installs with job card evidence | 100% |
| Completed installs attached to FOL **and** SO | **100%** (GR-87, GR-88) |
| Job-card uploads with location attempt logged | 100% of files have `location_source` set (incl. `unavailable`) (GR-89) |

---

## 19. Summary for engineering

**Build:** KP-scoped FOL requisitions with cart of FOL-eligible SKUs, SO-calculated consumable history, multi-stage configurable approvals with required comments, Kim-Fay branded emails (N1–N11), post-approval **SO linking**, **monthly issued + anomaly reporting**, and **installation calendar** with multi-role **Technician Manager** approval and technician **job cards**.

**Guardrails:** portfolio, KP class, FOL SKU flag, SO metrics integrity, immutable audit, stage permissions, SO customer match, calendar conflict prevention, multi-role capability packs, **completed job cards require FOL + SO (GR-87/GR-88)**, **background location on job-card photo/PDF upload (GR-89)**.

**Future-proof:** stable keys (`customer_acumatica_id`, `inventory_id`, `acumatica_order_nbr`), `fol_so_links`, install/job-card tables, anomaly rule engine, approval engine N-stage — ready for volume commitment and recall without rewrite.

---

*References: existing Zoho notification copy in `kp/fol/*.png`; OrderWatch SO model `AcumaticaSalesOrder` / lines; KP scope `departments.class_prefix_map.KP`; multi-role via `user_roles`; mail patterns from `TeamMemberAccountMail` / notification rules.*
