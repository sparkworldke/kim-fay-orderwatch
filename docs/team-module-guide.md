# Team & Org Module — Guide

**What this module is, how it works, and what you need to do to roll it out.**

Related docs: [`team.md`](team.md) (full PRD), [`team-management.md`](team-management.md) (original requirements).

---

## What this module does

OrderWatch’s team module controls **who can see what** across customers, orders, fill rate, backorders, inventory, customer feed, and business optimization. It combines:

| Layer | What it controls |
|-------|------------------|
| **App role** (`Administrator`, `Sales Consultant`, etc.) | Menus and actions (permissions) |
| **Org level** | Reporting tree and data visibility rules |
| **Department / team** | Function (MT, GT, KP, Partner Brands, Finance, Dispatch, …) |
| **Sector scope** | GT, MT, KP, or ALL |
| **Customer assignments** | Which outlets/customers a consultant or KAM sees |
| **Brand assignments** | Which partner brands Brand Ops users manage |
| **Consultant flag** | Links users to sales orders |

**Rule of thumb:** Permissions = what you can *do*. Org scope = what data you can *see*.

---

## Org structure

```
executive
  └── c_suite
        └── hod
              ├── sales         (consultants, KAMs, regional managers)
              ├── brandsops     (partner brand operators)
              └── operations    (CS, Finance, Marketing, Procurement, Production, Store, Dispatch)
```

### Who sees what (summary)

| Org level | Data visibility |
|-----------|-----------------|
| **Executive / C-Suite** | Everything — all sectors, manufactured + trading |
| **Operations** | Same as executive/C-suite (org-wide). Use for Finance, Dispatch, Customer Service, HR, Production, Stores, etc. |
| **HOD** | Their sector portfolio (e.g. MT only) **plus** all reportees’ data in the reporting tree |
| **Sales / Consultant** | Only assigned customers (or customers derived from their rep code on SOs) within their sector |
| **Brand Ops** | Partner-brand trading data for assigned brands (not tied to specific customers/outlets) |
| **Gap** | Nothing until an admin completes setup (`deny_all`) |

### Example personas (from HR match)

| Person | Email | Org setup |
|--------|-------|-----------|
| Vignesh (CCO) | `cco@kimfay.com` | `c_suite`, sector ALL, reports to CEO |
| Purity (MT HOD) | `moderntrade@kimfay.com` | `hod`, MT, department MT/Consumer Sales |
| Muthoni (Partner Brands) | `partnerbrands@kimfay.com` | `hod`, Partner Brands, product type = trading |
| Susan (KP HOD) | `susan@kimfay.com` | `hod`, KP, Professional Sales |
| Jane (KAM example) | `jane.kac@kimfay.com` | `sales`, consultant, MT — attach Carrefour customers |
| Steve (GT HOD) | `salesstrategy@kimfay.com` | `hod`, GT — set up manually if not in email list |
| Finance / Dispatch / HR | various | `operations` — org-wide data |

---

## Where to manage it in the app

**Primary page:** `/app/team` (Team Members)

Administrators can:

1. **Import staff** from the HR/email match file (top panel)
2. **Create** new users (welcome email sent automatically)
3. **Edit** org chart, teams, sectors, brands, customer backfill
4. **View sessions** per user
5. **Activate** imported users after review

**Administration tab** also has a team section with overlapping controls.

---

## What you need to do — rollout checklist

### Phase 1 — One-time setup

- [ ] Run migrations:
  ```bash
  cd backend
  php artisan migrate
  ```
- [ ] Seed departments (if fresh DB):
  ```bash
  php artisan db:seed --class=DepartmentSeeder
  ```

### Phase 2 — Import staff from HR match

1. Confirm match file exists: `agent-tools/staff_email_match.json` (or `docs/data/staff_email_match.xlsx`)
2. **Preview first** (no writes):
   ```bash
   php artisan team:import-staff --dry-run
   ```
   Or in the UI: **Team Members → Import Staff → Preview import**
3. Review counts (expect ~80 high-confidence matches, ~58 gaps)
4. **Run import**:
   ```bash
   php artisan team:import-staff --preserve-manual
   ```
   Or UI: **Run import**

**Important:** Imported users are created **inactive**. They do not get a welcome email from import. You must activate each user and send welcome email when ready.

### Phase 3 — Fix gaps manually

Open gaps appear in:

- UI: **Import Staff → Open gaps** table
- DB table: `staff_import_gaps`

Typical gaps:

- Interns and shared mailboxes (`orders@`, `dispatchclerk*`)
- Name mismatches between email display name and HR record
- Staff with no OrderWatch email (STC warehouse staff — expected)

For each gap user you want in the system:

1. **Create** or **edit** user manually on `/app/team`
2. Set org level, department, reports-to
3. Mark gap resolved in admin (or ignore if not an app user)

### Phase 4 — Build the reporting tree

For each HOD and reportee, set **Reports to** in the edit dialog:

```
Chairman (P300) [executive]
└── CEO (P301) [executive]
    └── Vignesh / CCO (P320) [c_suite]
        ├── Purity — MT HOD
        ├── Susan — KP HOD
        ├── Muthoni — Partner Brands HOD
        ├── Steve — GT HOD
        ├── Beatrice — Customer Service HOD
        ├── Vincent — Dispatch HOD
        └── … sales / brandsops reportees under their HOD
```

Operations staff (Finance, HR, Dispatch, etc.) should use **Org level = Operations** so they see everything without being in the sales tree.

### Phase 5 — Consultants & KAMs (customer attachment)

For each sales consultant or KAM:

1. Edit user → enable **Consultant designation**
2. Set **Rep code** and/or **Employee number** (matches Acumatica `sales_consultant_rep_code`)
3. Click **Backfill from SOs** to pull customer list from synced sales orders
4. Verify they only see their customers in Orders / Customer Feed / Fill Rate

CLI alternative:

```bash
php artisan team:backfill-customers user@kimfay.com
php artisan team:backfill-customers --all-consultants
```

### Phase 6 — Brand Ops (partner brands)

For Brand Ops users (e.g. Unilever operator):

1. Set **Org level = Brand Operations**
2. Set department = **Partner Brands**
3. Set **Product type scope = Trading**
4. In edit dialog → **Partner brand assignments** → check brands → **Save brands**

### Phase 7 — Activate users & send welcome

For each imported/inactive user:

1. Review org config on `/app/team`
2. Click **Reactivate** (or toggle status)
3. Click **Resend** welcome email (sends OTP login instructions)

### Phase 8 — Verify (smoke test)

Log in as sample users and confirm:

| User type | Should see | Should NOT see |
|-----------|------------|----------------|
| Operations (Dispatch) | All customers/orders | — |
| MT HOD (Purity) | MT data + reportees | GT, KP |
| KP consultant | Assigned KP customers only | Other KP customers |
| Brand Ops (Adan/Unilever) | Unilever trading metrics | Unassigned brands (when brand filter applied) |
| Gap / unconfigured | Nothing | Any operational data |

---

## UI field reference (Edit / Create user)

| Field | Purpose |
|-------|---------|
| **Primary department** | Main team/function |
| **Additional teams** | Multi-department membership (checkboxes) |
| **Org level** | Executive, C-Suite, HOD, Sales, Brand Ops, Operations, Gap |
| **Reports to** | Manager in org chart |
| **Department role** | Member / HOD / Executive (legacy flag, works with org level) |
| **Sector scope** | GT, MT, KP, ALL |
| **Product type scope** | Manufactured, Trading, or Both |
| **Consultant designation** | Enables SO attachment + customer scoping |
| **Partner brand assignments** | Shown for Brand Ops / Partner Brands HOD |
| **Backfill from SOs** | Shown for consultants — derives customer list |

**Auto rules:**

- `operations`, `executive`, `c_suite` → `data_scope_mode = org_wide`
- `gap` → `data_scope_mode = deny_all` (sees nothing until fixed)
- `sales`, `hod`, `brandsops` → `data_scope_mode = scoped`

---

## CLI commands

| Command | Purpose |
|---------|---------|
| `php artisan team:import-staff --dry-run` | Preview HR import |
| `php artisan team:import-staff --preserve-manual` | Import without overwriting admin-edited users |
| `php artisan team:import-staff --path=...` | Custom xlsx/json path |
| `php artisan team:import-staff --min-confidence=medium` | Lower match threshold |
| `php artisan team:backfill-customers {email}` | Attach customers from SOs for one user |
| `php artisan team:backfill-customers --all-consultants` | Backfill all active consultants |

---

## API endpoints (for integrations)

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/api/admin/team/import-staff` | Run import from UI |
| GET | `/api/admin/team/import-gaps` | List open gaps |
| PUT | `/api/admin/users/{id}/brand-assignments` | Sync partner brands |
| POST | `/api/admin/users/{id}/backfill-customers` | Backfill customers from SOs |
| GET | `/api/admin/brand-options` | List partner brands from inventory |

---

## Data scoping — how leaks are prevented

Visibility is built from stacked rules (fail-closed for `gap` users):

1. **Org-wide** — executive, c-suite, operations skip sector/customer filters
2. **Sector** — GT / MT / KP via `customer_class` prefix
3. **Product type** — manufactured vs trading (partner brands)
4. **Customer assignment** — explicit list or rep-code SO linkage
5. **Brand assignment** — partner brand filter for Brand Ops
6. **Subtree** — HODs union their reportees’ scopes

Unconfigured users (`gap`) see **no operational data** until an admin completes setup.

---

## Files & artifacts

| File | Purpose |
|------|---------|
| `team-module-guide.md` | This guide |
| `team.md` | Full PRD / technical spec |
| `agent-tools/staff_email_match.json` | Machine-readable HR match |
| `docs/data/staff_email_match.xlsx` | Spreadsheet match export |
| `docs/data/staff_email_gaps.xlsx` | Unmatched emails/staff |
| `agent-tools/match_staff_emails.py` | Re-run matcher after HR updates |

Re-run matching after HR spreadsheet updates:

```bash
python agent-tools/match_staff_emails.py
php artisan team:import-staff --dry-run
```

---

## Day-one vs optional (PRD scope)

### Already shipped — enough for day-one rollout

- [x] Org chart fields (org level, reports-to, sectors, product type)
- [x] Multi-department / team membership (`department_user` pivot)
- [x] Operations = org-wide visibility (same as executive/C-suite)
- [x] HOD subtree visibility (reportees’ data)
- [x] Staff import from HR match (CLI + `/app/team` UI)
- [x] Import gaps table + gaps list in UI
- [x] Customer backfill from SOs (CLI + consultant edit dialog)
- [x] Brand assignment UI for Brand Ops (save partner brands)
- [x] Consultant / rep-code scoping
- [x] Session history per user

### Optional items (now implemented)

- [x] **Customer assignment picker** — search customers in edit dialog + save (`CustomerAssignmentFields`)
- [x] **Gap resolution** — create user, link to existing user, or ignore (`StaffImportPanel`)
- [x] **Brand scope enforcement** — inventory, fill rate, backorders via `BrandAssignmentScope`
- [x] **Org tree seed** — `php artisan team:seed-org-tree` or UI **Seed org tree (CCO → HODs)**
- [x] **Shared mailbox policy** — `php artisan team:apply-shared-mailbox-policy` + auto on import

```bash
php artisan team:seed-org-tree
php artisan team:apply-shared-mailbox-policy
```

---

## Quick troubleshooting

| Problem | Fix |
|---------|-----|
| User sees no data | Check org level is not `gap`; set department + sector; activate account |
| Consultant sees too much | Ensure consultant flag on; run backfill; verify rep code matches SOs |
| Operations user sees too little | Set org level to **Operations** (not Sales) |
| HOD missing reportee data | Set **Reports to** on reportees; confirm HOD org level |
| Import skipped user | User has manual org edits (`--preserve-manual`); edit directly or remove audit |
| Brand Ops sees no brands | Inventory sync needed; brands come from trading SKUs |

---

## Support contacts for decisions

Confirm with stakeholders:

1. **Steve (GT HOD)** — canonical email (`salesstrategy@kimfay.com`?)
2. **Shared mailboxes** — deactivate or map as `deny_all` service accounts?
3. **Jane / Carrefour** — explicit customer list vs SO backfill only?
4. **Regional managers** — full sector like HOD or reportee subtree only?
5. **Revenue masking** — which org levels mask financials by default?

---

*Last updated: July 2026 — reflects implemented PR8–PR14 team/org work.*