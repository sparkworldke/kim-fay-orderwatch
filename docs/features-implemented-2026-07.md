# Features implemented — July 2026

**Product:** OrderWatch (Kim-Fay)  
**Date:** 12 Jul 2026  

Index of feature documentation for work delivered in the recent implementation pass.

---

## Documents

| Feature | Doc | UI entry |
|---------|-----|----------|
| **Admin Impersonation** | [admin-impersonation.md](./admin-impersonation.md) | Administration → Impersonation · Team → Login as |
| **FOL Technician Calendar** | [fol-technician-calendar.md](./fol-technician-calendar.md) | Workflow → FOL Calendar · KP FOL → My Allocations |

---

## Quick summary

### Admin Impersonation

- **Why:** Test as CCO / Beatrice / Shirleen / any user without re-login.  
- **Who:** Administrators only.  
- **How:** Sanctum token on target with `impersonator:{adminId}`; amber banner to return.  
- **Tests:** `backend/tests/Feature/ImpersonationTest.php`

### FOL Technician Calendar

- **Why:** Technicians see allocated accounts, calendar load, and resolved counts.  
- **Who:** Technician (`kp.fol.install.execute`), Technician Manager (`kp.fol.install.manage`).  
- **How:** FOLs with `assigned_technician_user_id`; resolve → `fulfilled`.  
- **Tests:** `backend/tests/Feature/FolTechnicianCalendarTest.php`

---

## Product PRDs (source requirements)

| Area | Path |
|------|------|
| FOL / install calendar (full product) | `kp/fol-requests.md` |
| KP enablement roadmap | `kp/kp-enabler.md` |
| Customer matching | `kp/customer-matching/cutomer-upload.md` |
| Price change | `kp/pricing/price-change.md` |

---

## Suggested reading order for support / QA

1. [admin-impersonation.md](./admin-impersonation.md) — switch into technician or approver.  
2. [fol-technician-calendar.md](./fol-technician-calendar.md) — assign FOL → calendar → mark resolved.  
3. `kp/fol-requests.md` — full FOL approval + future install job-card scope.
