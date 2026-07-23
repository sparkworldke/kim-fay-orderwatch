# Roles & Permissions

Reference for **app roles** in Kim-Fay OrderWatch / KP CRM.  
**Permissions** control what users can *do*. **Org scope** (department, sector, assignments) controls what data they can *see* — see [`team-module-guide.md`](team-module-guide.md).

| Layer | Controls |
|-------|----------|
| **App role** | Menus, actions, workflow approval eligibility |
| **Permissions** | Fine-grained capability slugs on the role |
| **Department** | Extra hidden menus (e.g. production hides mailbox) |
| **Org level / assignments** | Which customers, brands, and reports appear in data |

**Source of truth (code):**

| Area | File |
|------|------|
| Role list + default permissions | `backend/database/seeders/RolesPermissionsSeeder.php` |
| Capability API (menus, mask revenue) | `backend/app/Services/Team/UserCapabilitiesService.php` |
| Department-based menu hides | `backend/config/departments.php` |
| Frontend nav gates | `src/lib/nav-permissions.ts` |
| Sidebar groups | `src/components/app-sidebar.tsx` |
| UI matrix | `/app/roles` (Administrators) |

Re-seed defaults (careful in production — overwrites role–permission links):

```bash
cd backend
php artisan db:seed --class=RolesPermissionsSeeder
```

---

## System roles

| Role | Typical use |
|------|-------------|
| **Administrator** | Full system access; config, team, roles, sync, impersonation |
| **Customer Service Manager** | CS leadership; consultants, assignments, FOL invoice, PCR approve, sales mgmt |
| **Customer Service Agent** | Day-to-day CS; orders resolve, FOL invoice, limited PCR view |
| **Sales Operations** | Ops support; FOL invoice/report, PCR margin + ERP apply, customer assignment |
| **Sales Consultant** | Field / KAM; FOL request, create PCR, sales prompts, DTC quotes |
| **Executive** | Leadership oversight; FOL report, PCR approve (incl. escalated), sales mgmt, full revenue |
| **HOD** | Head of department (app role); sales mgmt + DTC; often paired with org level `hod` |
| **Technician Manager** | FOL install scheduling/management |
| **Technician** | FOL install execution + calendar |

> **Note:** Frontend `Role` type in `src/lib/auth.ts` lists Administrator, CS Manager/Agent, Sales Operations, Sales Consultant, Executive, Technician Manager, Technician. **HOD** exists in the backend seeder and DB; treat it as a first-class app role when assigning users.

---

## Permission catalogue

Grouped by domain (slugs as stored in `permissions.name`).

### Admin & platform

| Slug | Meaning |
|------|---------|
| `admin.view` | Open administration areas (view) |
| `admin.api_keys` | API key management |
| `admin.cron_jobs` | Cron job management |
| `mailboxes.connect` / `disconnect` / `view` | Outlook mailbox ops |
| `acumatica.view` / `config` / `validate` | ERP connection |
| `ai.view` / `keys` / `regenerate` | AI settings |
| `audit.view` / `export` | Audit log |
| `roles.view` / `roles.manage` | Roles UI |
| `permissions.manage` | Edit permission grants |
| `notifications.view` / `manage` | Notification rules |

### Orders & customers

| `orders.view` / `assign` / `resolve` / `escalate` |
| `customers.manage` |
| `customers.assign.view` / `manage` / `manage_all` / `export` |
| `consultants.view` / `manage` |
| `reports.export` |
| `email-import.manage` / `approve` / `create-wildcards` |

### KP CRM — FOL

| Slug | Meaning |
|------|---------|
| `kp.fol.view` | View FOL requests |
| `kp.fol.request` | Create / submit FOL |
| `kp.fol.approve` | Approve FOL stages |
| `kp.fol.invoice` | Invoice handoff |
| `kp.fol.report` | FOL reporting |
| `kp.fol.install.manage` | Manage installs (tech manager) |
| `kp.fol.install.execute` | Execute installs (technician) |

### KP CRM — Price change (PCR)

| Slug | Meaning |
|------|---------|
| `pricing.pcr.view` | View price change requests |
| `pricing.pcr.create` | Create PCR |
| `pricing.pcr.approve` | Stage approve |
| `pricing.pcr.approve_escalated` | Final / escalated approve |
| `pricing.pcr.view_margin` | See margin figures |
| `pricing.pcr.apply_erp` | Mark applied in Acumatica |
| `pricing.pcr.config` | PCR configuration |

### KP CRM — Sales management & DTC

| Slug | Meaning |
|------|---------|
| `sales.management.view` / `resolve` / `manage` / `config` | Sales prompts / order cycle |
| `dtc.view` | DTC/DTB Calltronix module |
| `dtc.quotes.create` / `dtc.quotes.convert` | Quotes → sales orders |

---

## Default permissions by role

From `RolesPermissionsSeeder` (final sync per role). **Administrator** receives **all** permission slugs.

### Customer Service Manager

Base view pack (`admin.view`, `mailboxes.view`, `acumatica.view`, `ai.view`, `audit.view`, `roles.view`, `notifications.view`, `orders.view`) plus:

- `consultants.view`, `consultants.manage`
- `kp.fol.view`, `kp.fol.invoice`
- `pricing.pcr.view`, `pricing.pcr.approve`, `pricing.pcr.view_margin`
- `sales.management.view`, `resolve`, `manage`
- `customers.assign.view`, `manage`, `export`
- `dtc.view`, `dtc.quotes.create`, `dtc.quotes.convert`

### Customer Service Agent

- `orders.view`, `orders.resolve`
- `kp.fol.view`, `kp.fol.invoice`
- `pricing.pcr.view`

### Sales Operations

Base view pack plus:

- `kp.fol.view`, `kp.fol.invoice`, `kp.fol.report`
- `pricing.pcr.view`, `pricing.pcr.view_margin`, `pricing.pcr.apply_erp`
- `customers.assign.view`, `manage`, `export`
- `dtc.view`, `dtc.quotes.create`, `dtc.quotes.convert`

### Sales Consultant

- `orders.view`
- `kp.fol.view`, `kp.fol.request`
- `pricing.pcr.view`, `pricing.pcr.create`
- `sales.management.view`, `sales.management.resolve`
- `dtc.view`, `dtc.quotes.create`, `dtc.quotes.convert`

### Executive

Base view pack plus:

- `kp.fol.view`, `kp.fol.report`
- `pricing.pcr.view`, `pricing.pcr.approve`, `pricing.pcr.approve_escalated`, `pricing.pcr.view_margin`
- `sales.management.view`, `resolve`, `manage`
- `dtc.view`, `dtc.quotes.create`, `dtc.quotes.convert`

### HOD

Base view pack plus:

- `consultants.view`
- `sales.management.view`, `resolve`, `manage`
- `dtc.view`, `dtc.quotes.create`, `dtc.quotes.convert`

### Technician Manager

- `kp.fol.view`, `kp.fol.install.manage`

### Technician

- `kp.fol.view`, `kp.fol.install.execute`

---

## Menu visibility

### Sidebar groups (UI)

1. **OrderWatch** — dashboard, orders, inventory, backorders, fill rate, customers, etc.  
2. **KP CRM** — accounts, FOL, PCR, sales mgmt, DTC/Calltronix, etc.  
3. **Administration** — admin settings, order match, mailbox, team, roles, SO imports, profile  

### Role-based hidden menus (API)

From `UserCapabilitiesService`:

| Role | Extra hidden menu slugs |
|------|-------------------------|
| Customer Service Agent | `administration`, `roles`, `team` |
| Sales Consultant | `administration`, `roles`, `team`, `mailbox`, `order-match` |

Administrators / super-admins: no role-based hide; full permission set.

### Frontend nav rules (`nav-permissions.ts`)

| Area | Who |
|------|-----|
| Most OrderWatch URLs | Any authenticated role (unless hidden by capabilities) |
| `/app/administration`, `/app/roles` | **Administrator only** |
| `/app/team` | Administrator or **Customer Service Manager** |
| `/app/mailbox`, `/app/order-match` | Administrator or **CS Manager / CS Agent** |
| `/app/sales-consultants` | Admin, CS Manager, Executive, Sales Operations, Sales Consultant |
| KP FOL / PCR / Sales Mgmt / DTC items | Permission-gated in sidebar (`kp.fol.*`, `pricing.pcr.*`, etc.) |
| FOL Settings | **Administrator** only |
| FOL Calendar | FOL view + install manage **or** execute |

### Department-based hidden menus

From `config/departments.php` → `hidden_menus_by_department` (merged with role hides):

| Department slug | Hidden |
|-----------------|--------|
| `production` | mailbox, order-match, so-imports, administration |
| `stores` | mailbox, order-match, so-imports, administration |
| `dispatch` | mailbox, order-match, administration |
| `marketing` | order-match, administration |
| `mt_consumer_sales`, `gt`, `kp` | administration |

---

## Revenue masking

| Config | Behaviour |
|--------|-----------|
| `departments.mask_revenue_roles` | Includes **Customer Service Agent** |
| Never masked | Administrator, Executive, super-admin |

Other roles see unmasked revenue unless added to that config.

---

## Workflow stage assignees (role names)

Seeded approval stages use **role names**, not permission slugs.

### FOL approvals

| Stage | Roles |
|-------|--------|
| HOD Approval | Administrator, Customer Service Manager, Executive |
| CCO / COO Final | Administrator, Executive |

### Price change (PCR) approvals

| Stage | Roles |
|-------|--------|
| HOD / CSM / Executive | Administrator, Customer Service Manager, Executive |
| Senior (final) | Administrator, Executive |

FOL request creation is permission-based (`kp.fol.request` — typically **Sales Consultant**).  
Install work uses **Technician** / **Technician Manager** permissions.

---

## Executive / org-wide data roles

Separate from menu permissions — used for **data scope**:

```php
// config/departments.php
'executive_roles' => ['Executive', 'Administrator'],
```

These roles get org-wide customer/order visibility regardless of sector assignments.  
HOD subtree and consultant assignment scoping are documented in [`team.md`](team.md).

---

## How roles attach to users

1. Users have a primary string column `users.role` (legacy + display).  
2. Seeder also links `user_roles` → `roles` so permission resolution works via role pivot.  
3. Capabilities endpoint (`GET /api/auth/capabilities`) returns:
   - `permissions[]`
   - `menus[]` / `hidden_menus[]`
   - `mask_revenue`
   - department / org fields  

**Administrator** and `is_super_admin` always resolve to **all** permission slugs.

Secondary “capability packs” (e.g. Technician Manager alongside another primary menu role) can be managed on Team — see team UI copy and [`team-module-guide.md`](team-module-guide.md).

---

## Quick matrix (intent)

| Capability | Admin | CS Mgr | CS Agent | Sales Ops | Consultant | Exec | HOD | Tech Mgr | Tech |
|------------|:-----:|:------:|:--------:|:---------:|:----------:|:----:|:---:|:--------:|:----:|
| Full admin / roles / sync | ✓ | — | — | — | — | — | — | — | — |
| Team members page | ✓ | ✓ | — | — | — | — | — | — | — |
| Mailbox / Order Match | ✓ | ✓ | ✓ | — | — | — | — | — | — |
| Orders view | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | —* | —* |
| FOL request | ✓ | — | — | — | ✓ | — | — | — | — |
| FOL invoice | ✓ | ✓ | ✓ | ✓ | — | — | — | — | — |
| FOL install manage | ✓ | — | — | — | — | — | — | ✓ | — |
| FOL install execute | ✓ | — | — | — | — | — | — | — | ✓ |
| PCR create | ✓ | — | — | — | ✓ | — | — | — | — |
| PCR approve | ✓ | ✓ | — | — | — | ✓ | — | — | — |
| PCR escalated approve | ✓ | — | — | — | — | ✓ | — | — | — |
| PCR apply ERP | ✓ | — | — | ✓ | — | — | — | — | — |
| Sales mgmt | ✓ | ✓ | — | — | view/resolve | ✓ | ✓ | — | — |
| DTC Calltronix | ✓ | ✓ | — | ✓ | ✓ | ✓ | ✓ | — | — |
| Revenue unmasked | ✓ | ✓† | — | ✓† | ✓† | ✓ | ✓† | ✓† | ✓† |

\* Tech roles only get FOL-related perms by default; OrderWatch menus may still show if not hidden by department — data access still follows org scope.  
† Not in `mask_revenue_roles` (only CS Agent is masked by default).

---

## Related docs

- [`team-module-guide.md`](team-module-guide.md) — org chart, data scoping  
- [`team.md`](team.md) — full team/org PRD  
- [`admin-impersonation.md`](admin-impersonation.md) — admin “view as user”  
- [`fol-technician-calendar.md`](fol-technician-calendar.md) — technician FOL calendar  
- [`price-change-request-status.md`](price-change-request-status.md) — PCR status flow  

---

*Generated from seeder and frontend gates. Live grants may differ if edited in DB after seed; check **Administration → Roles** or `/app/roles` for the current matrix.*
