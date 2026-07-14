# FOL Technician Calendar — Implementation Guide

**Product:** OrderWatch · Kimfay Professional (KP)  
**Module:** Free On Loan (FOL) · Install / field allocation  
**Status:** Implemented (MVP on FOL request allocation)  
**Last updated:** 12 Jul 2026  
**PRD context:** `kp/fol-requests.md` §10 Installation calendar & job cards (partial; full `fol_install_*` tables not required for this MVP)

---

## 1. Purpose

A **Technician** account must:

1. See **allocations** assigned to them (FOL installs / field work).  
2. See **accounts** (customers) on those allocations.  
3. See **how many are open** vs **how many they have resolved**.  
4. Mark work **resolved** when done.

Managers can open the same calendar for any technician.

---

## 2. Personas & permissions

| Role / permission | Calendar | Scope |
|-------------------|----------|--------|
| **Technician** (`kp.fol.install.execute` + `kp.fol.view`) | Yes — **FOL Calendar** nav | Own assignments only |
| **Technician Manager** (`kp.fol.install.manage`) | Yes | Own + filter by technician |
| Administrator | Yes (via FOL view + manage/assign) | Can assign techs; resolve if needed |
| Others without install perms | No calendar nav | — |

Seeded in `RolesPermissionsSeeder`:

- Technician → `kp.fol.view`, `kp.fol.install.execute`  
- Technician Manager → `kp.fol.view`, `kp.fol.install.manage`

---

## 3. User experience

### 3.1 Navigation

- Sidebar **Workflow → FOL Calendar** → `/app/kp/fol/calendar`  
- From **KP FOL** list: **Calendar** button (when install execute/manage)  
- List tabs for techs: **My Allocations**, **Resolved by me**

### 3.2 Calendar page (`/app/kp/fol/calendar`)

| Block | Content |
|-------|---------|
| **KPI strip** | Open to resolve · Resolved (all time) · Accounts allocated · This month resolved (+ open this month) |
| **Month grid** | Days with “N open” / “N done” (assignment date, Africa/Nairobi) |
| **Accounts panel** | Per customer: open / resolved / total |
| **Allocation table** | FOL ref, account, location, status, assigned at, **Mark resolved** |

Click a day to filter the table; “Show full month” clears the filter.

### 3.3 Resolve

- Calendar row **Mark resolved**, or FOL detail **Mark resolved**.  
- Sets FOL `status` → `fulfilled`.  
- Event: `technician_resolved`.  
- Allowed for assigned technician (execute) or tech manager; FOL should be in an approved/ready status (managers can override status gate).

### 3.4 Allocation source (MVP model)

Allocations are **FOL requests** with:

- `assigned_technician_user_id`  
- `technician_assigned_at`  
- `installation_location` (optional)  
- Customer: `customer_acumatica_id` / `customer_name`

Managers assign via FOL detail **Technician assignment** (`POST …/technician`).

Calendar day uses:

`technician_assigned_at` → else `decided_at` → else `submitted_at` → else `created_at` (EAT).

---

## 4. API

| Method | Path | Description |
|--------|------|-------------|
| `GET` | `/api/kp/fol/technician/calendar?month=YYYY-MM&technician_user_id=` | Calendar payload (see below) |
| `POST` | `/api/kp/fol/{id}/technician` | Assign technician (manage) |
| `POST` | `/api/kp/fol/{id}/technician/resolve` | Body optional `{ "comment" }` → fulfilled |
| `GET` | `/api/kp/fol?view=my_allocations` | Open assigned FOLs |
| `GET` | `/api/kp/fol?view=my_resolved` | Fulfilled assigned FOLs |
| `GET` | `/api/kp/fol/technicians` | Tech list (manage only) |

### 4.1 Calendar response (shape)

```json
{
  "month": "2026-07",
  "technician": { "id": 12, "name": "…", "email": "…" },
  "summary": {
    "allocated_open": 3,
    "resolved": 10,
    "total_assigned": 13,
    "distinct_accounts": 8,
    "resolved_this_month": 2,
    "open_this_month": 1
  },
  "days": [
    {
      "date": "2026-07-10",
      "open": 1,
      "resolved": 0,
      "items": [ /* FolCalendarItem */ ]
    }
  ],
  "accounts": [
    {
      "customer_acumatica_id": "C001",
      "customer_name": "Account Alpha",
      "open": 1,
      "resolved": 2,
      "total": 3
    }
  ],
  "items": [ /* month items */ ]
}
```

### 4.2 Calendar item fields

`id`, `public_ref`, `customer_*`, `status`, `resolve_state` (`open` | `resolved` | `closed`), `installation_required`, `installation_location`, `issue_types`, `lines_count`, `linked_so_order_nbrs`, `technician_assigned_at`, `calendar_date`, `sales_consultant_email`.

### 4.3 Scoping rules

- Technicians: forced to **own** user id; requesting another `technician_user_id` → **403**.  
- Managers: optional `technician_user_id`.  
- List query: techs always include `assigned_technician_user_id = self` in portfolio OR scope.

### 4.4 View-only middleware

`POST/PATCH/PUT` under `api/kp/fol*` allowed for non-privileged roles (consultants submit; techs resolve). Controllers still enforce permissions.

---

## 5. Open vs resolved definitions

| State | Rule |
|-------|------|
| **Open (to resolve)** | Assigned to tech and status **not** in `fulfilled`, `rejected` |
| **Resolved** | Assigned to tech and status = `fulfilled` |
| **Closed (other)** | e.g. `rejected` — shown as closed, not counted as open |

---

## 6. Frontend files

| File | Role |
|------|------|
| `src/routes/app.kp.fol.calendar.tsx` | Calendar UI |
| `src/routes/app.kp.fol.tsx` | My Allocations / Resolved tabs; Calendar button |
| `src/routes/app.kp.fol.$id.tsx` | Assign tech; Mark resolved |
| `src/hooks/useFol.ts` | `useFolTechnicianCalendar`, `useResolveFolTechnician` |
| `src/components/app-sidebar.tsx` | FOL Calendar nav |
| `src/lib/nav-permissions.ts` | `/app/kp/fol/calendar` access |

---

## 7. Backend files

| File | Role |
|------|------|
| `backend/app/Services/Fol/FolRequestService.php` | `listQuery` views; `technicianCalendar`; `resolveByTechnician`; `assignTechnician` |
| `backend/app/Http/Controllers/Api/FolController.php` | `technicianCalendar`, `resolveTechnician` |
| `backend/routes/api.php` | `kp/fol/technician/calendar`, `…/technician/resolve` |
| `backend/app/Http/Middleware/ViewOnlyUnlessPrivileged.php` | FOL write allow-list |
| `backend/tests/Feature/FolTechnicianCalendarTest.php` | Stats, resolve, cross-tech 403 |

DB fields (existing migration): `fol_requests.assigned_technician_user_id`, `technician_assigned_by`, `technician_assigned_at`.

---

## 8. How to test

1. As Admin / Tech Manager: open approved FOL → **Technician assignment**.  
2. Login as that Technician (or Admin **Impersonation**).  
3. Open **FOL Calendar** — account + open count.  
4. **Mark resolved** → Resolved KPI increments.  
5. Backend: `php artisan test --filter=FolTechnicianCalendarTest`.

---

## 9. Out of scope (this MVP) / future

From full PRD (`kp/fol-requests.md`):

- Separate `fol_install_requests` / job-card tables  
- Preferred time windows + conflict detection  
- GPS on job-card photo  
- Hard SO+FOL gate on complete (partially: resolve prefers ready/SO-linked statuses)

---

## 10. Related

- Product PRD: `kp/fol-requests.md`  
- Admin switch user for testing: `docs/admin-impersonation.md`  
- KP roadmap: `kp/kp-enabler.md`
