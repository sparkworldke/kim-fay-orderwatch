# What’s done now (≈1 min)

**Product:** OrderWatch (Kim-Fay)  
**Last updated:** 12 Jul 2026  

Quick status of the four major enablement features: customer matching, price change, FOL, and admin impersonation.

---

## 1. Customer matching

- Assign customers to consultants (manual + portfolio scope)
- **Match from Sales Orders** (preview → apply)
- **Match from customer endpoint** (preview → apply)
- **Excel / batch upload** of assignments (preview → apply)
- Assignment **sources + batch history**
- Site signals: portfolio / activity context for consultants (dormant / recurring where wired)

---

## 2. Price change (PCR)

- Consultant **creates PCR** (customer + SKU + proposed price + reason)
- **Current selling price** system-filled; **base/margin hidden** from consultants
- **Multi-stage approval** (HOD → senior) with comments
- **Pending ERP** queue → mark applied in Acumatica
- List, dashboard KPIs, duplicate warning
- Permissions + admin stages/settings API
- *Verified working* (tables migrated, consultant POST fixed)

**Detail:** `docs/price-change-request-status.md` · PRD: `kp/pricing/price-change.md`

---

## 3. FOL (Free On Loan)

- Create / submit FOL for portfolio customers
- Multi-stage **approve/reject** (HOD → CCO, admin-configurable)
- SO / PO link, attachments, notifications
- **Admin FOL Settings** (stages, mail, attachments)
- **Technician assign** on FOL
- **Technician calendar**: allocations, accounts, open vs **resolved** counts, mark resolved
- FOL list tabs for techs: My Allocations / Resolved by me

**Detail:** `docs/fol-technician-calendar.md` · PRD: `kp/fol-requests.md`

---

## 4. Impersonation (Admin only)

- **Login as** any active user (search by name/role — e.g. CCO, Beatrice, Shirleen)
- Team Members **Login as** button
- Amber banner + **Return to admin**
- 4h token, audited start/stop
- *No password switch needed for testing*

**Detail:** `docs/admin-impersonation.md`

---

## Entry points

| Feature | Who | Main entry |
|--------|-----|------------|
| Customer matching | Admin / CS Manager | Admin → Team / user assignments |
| Price change | Consultant → Approver → Ops | **Price Changes** (`/app/price-change-requests`) |
| FOL | Consultant → Approvers → Tech | **KP FOL** / **FOL Calendar** |
| Impersonation | Administrator | **Admin → Impersonation** |

---

## Related docs

| Doc | Topic |
|-----|--------|
| `docs/features-implemented-2026-07.md` | July 2026 feature index |
| `docs/admin-impersonation.md` | Impersonation implementation |
| `docs/fol-technician-calendar.md` | Technician calendar |
| `docs/price-change-request-status.md` | PCR verification status |
| `kp/customer-matching/cutomer-upload.md` | Customer matching PRD |
| `kp/pricing/price-change.md` | PCR PRD |
| `kp/fol-requests.md` | FOL PRD |
