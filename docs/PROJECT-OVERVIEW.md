# Kim-Fay OrderWatch — Project Overview

**Completed vs pending features** (from `docs/` + live app structure)  
**As of:** 10 Jul 2026

---

## What OrderWatch is

Internal **operations intelligence** platform for Kim-Fay. It connects:

| System | Role |
|--------|------|
| **Acumatica ERP** | Sales orders, customers, inventory, zones, fill rate, backorders |
| **Microsoft Outlook (Graph)** | Customer PO email ingestion |
| **AI (Claude / OpenAI)** | PO extraction, match suggestions, daily briefing, AI Intelligence |
| **Cloudflare Worker** | Frontend hosting (`orderwatch.fayshop.co.ke`) |
| **Laravel API (VPS)** | Backend, cron jobs, sync engine |

**Goal:** Replace manual Excel chasing with scheduled syncs, match queues, fulfillment risk views, and a daily management email (Tue–Sat 07:00 EAT).

---

## Architecture at a glance

```
Outlook ──► Mailbox sync ──► Order Match ──► Accept/Reject ──► Match log
                                      │
Acumatica ──► SO / Inventory /        │
              Customers / Zones       ▼
         ──► Fill Rate · Backorders · Dashboard · Daily email
         ──► Credit notes (QT/RC/CM/PL)
```

| Layer | Tech | Status |
|-------|------|--------|
| Frontend | TanStack Start / React → Cloudflare Worker | **Live** at orderwatch.fayshop.co.ke |
| Backend | Laravel 13 + Sanctum + MySQL | **Deployable** (see `DEPLOY-VPS.md`) |
| Scheduler | `php artisan schedule:run` every minute | **Required** for all crons |

---

## Module status (by nav group)

### Overview

| Module | Route | Status | Notes |
|--------|-------|--------|-------|
| **Dashboard** | `/app` | ✅ Complete | KPIs, trends, fill-rate movement, entity links |
| **Orders** | `/app/orders` | ✅ Complete | SO list/search; SO-type only on main list |
| **Business Optimization** | `/app/business-optimization` | ✅ Complete | Fill rate + backorders + SLA + revenue at risk |
| **AI Intelligence** | `/app/ai-intelligence` | ✅ Complete | AI briefings for date ranges |
| **Customer Feed** | `/app/customer-feed` | ✅ Complete | Per-account orders, emails, fill rate |

### Operations

| Module | Route | Status | Notes |
|--------|-------|--------|-------|
| **Credit Notes & More** | `/app/credit-notes-more` | ✅ Largely complete | QT / RC / CM / PL sync + filters + stat cards (per requirements) |
| **Inventory** | `/app/inventory` | ✅ Complete | Warehouses, pagination, BI seed, product classification, `?sku=` deep link |
| **Backorders** | `/app/backorders` | ✅ Core complete | List, reasons, product listing; **advanced charts still requested** in older specs |
| **Fill Rate** | `/app/fill-rate` | ✅ Complete | Sync, KP/CS, Mfg/Trading, Excel export, zone filter, reasons |
| **Zones** | `/app/zones` | ✅ Complete | Master list, SLA badges, customer sheet, fill-rate deep links |
| **Customers** | `/app/customers` | ✅ Complete | Acumatica master + detail / branches / documents |
| **Sales Consultants** | `/app/sales-consultants` | ✅ Complete | Search, portfolio, rev. lost, branch accordions |

### Workflow

| Module | Route | Status | Notes |
|--------|-------|--------|-------|
| **Order Match** | `/app/order-match` | ✅ Core complete | AI match queue, accept/reject, audit; PO PDF normalizers for major chains |
| **Mailbox** | `/app/mailbox` | ✅ Core complete | Folder sync, filters, attachments; accordion counters / collapse defaults |

### System

| Module | Route | Status | Notes |
|--------|-------|--------|-------|
| **Administration** | `/app/administration` | ✅ Complete | Acumatica, cron, notifications, mailboxes, AI keys, audit |
| **Team Members** | `/app/team` | ✅ Feature complete | Org chart, import, brands, customers — **ops rollout pending** |
| **Roles & Permissions** | `/app/roles` | ✅ Complete | RBAC |
| **Sales Order Imports** | `/app/so-imports` | ✅ Present | Manual/CSV import path |
| **Profile** | `/app/profile` | ✅ Complete | Account / password |

### Cross-cutting (not always a menu item)

| Feature | Status | Notes |
|---------|--------|-------|
| **Manufactured vs Trading** | ✅ Complete | Classification layer (inventory + fill rate + team scope) — no standalone page |
| **Entity links (site-wide)** | ✅ Complete | Customer / SO / SKU / consultant / date → deep pages |
| **Daily management email** | ✅ Complete | Tue–Sat 07:00; SLA section hidden by request |
| **Notification rules + recipients** | ✅ Complete | Email + role recipients (admin) |
| **Cron jobs UI + scheduler** | ✅ Complete | Admin tab + system crontab required |
| **Auth (OTP / Sanctum)** | ✅ Complete | Login, sessions, welcome emails |
| **Frontend deploy (Wrangler)** | ✅ Working | Worker + custom domain |
| **Backend VPS deploy guide** | ✅ Documented | Production host still operator-dependent |

---

## Automation (cron) — implemented

| Job | Schedule (EAT) | Default |
|-----|----------------|---------|
| Sales order sync | Every 2h | On |
| Email sync | Every 2h (odd hours) | On |
| Order matching | Every 3h | On |
| SO status sync | Every 30 min | On |
| Inventory sync | 08:00 + 12:00 | On |
| Fill rate | 00:01 + 12:30 | On |
| Backorders | 00:30 daily | On |
| Sync monitor alerts | Every minute | On |
| Daily management report | Tue–Sat 07:00 | On |
| System health | 06:00 daily | On |
| Hourly auto-match pipeline | Hourly | **Off** (paused) |

Source: `docs/cron-jobs-guide.md`.

---

## Completed in depth (high-value workstreams)

### 1. Fill Rate — production-ready

- Acumatica → snapshots (`QtyOnShipments` / demand formula)
- UI: filters, KP/CS, manufactured/trading, zone, SLA, product sheet
- Multi-sheet Excel export (admin/manager)
- Reason taxonomy (33 hierarchical reasons) + capture report
- Nightly + noon crons  
**Docs:** `fill-rate-user-guide.md`, `fillrate-manufactured-teams-status.md`

### 2. Manufactured product — classification layer

- `product_type` + brand / posting class / sub trading group / supplier
- BI seeder: `inventory:seed-from-bi` → `docs/data/Stock Items BI(Data).csv`
- `ProductListingCell` across inventory, fill rate, backorders, customer products  
**Not a separate menu module** — by design.

### 3. Teams / org scoping — feature complete

- Org levels, reports-to, sectors, product-type scope
- Staff import + gaps, brand/customer assignments, backfill from SOs
- Scope enforcement on ops data  
**Docs:** `team-module-guide.md`, `team.md`  
**Pending is operational:** activate users, wire tree, fix gaps (see below).

### 4. Order Match + Mailbox

- Outlook OAuth folders, PO extract (subject/body/PDF), AI suggest, accept/reject, audit
- Customer-specific PO normalizers (Naivas, Chandarana, Quickmart, Carrefour, etc.)
- Email filter groups / import guardrails documented and largely built

### 5. Inventory + Zones + Consultants

- Warehouse summary, pagination, deep links
- Shipping zones with SLA rules (24h metro / 48–72h regional)
- Consultant portfolio with fill rate & revenue lost

### 6. Platform hygiene

- Site-wide clickable entity links
- RBAC + capabilities / hidden menus
- Daily email template adjustments
- TypeScript build clean (as of Jul 8 session notes)
- Docs consolidated under `docs/`

---

## Pending / incomplete / deferred

Grouped by type: **product gaps**, **ops rollout**, **explicitly deferred**, **stakeholder decisions**.

### A. Product / engineering still open

| Item | Source | Priority |
|------|--------|----------|
| **Separate Tally ERP connector** | Fill-rate docs | Confirm only — current design uses Acumatica as Tally path |
| **Fill-rate % formula alignment** | PRD vs code | PRD often states value-weighted %; code uses **qty** rollup (value = revenue not shipped) |
| **Full TTD / zone SLA product** as PRD §3 | Fill-rate PRD | Partial via badges + zones; not a separate TTD module |
| **Backorder advanced charts** (trends, lead-time, category distribution) | `fixes-now.md` | Requested; core list/reasons exist |
| **Order rejection reason mandatory UI** | `fixes-now.md` | May partially exist via SO reasons — verify UX |
| **Stop-sync button per module** + false “sync running” message | `fixes-now.md` | Ops/admin sync UX |
| **Order status comprehensive reconcile** | `fixes-now.md` | Status sync exists; full compare/fix pass may need validation |
| **Account `verified_at` / onboarding polish** | `fixes-now.md` | Account creation edge cases |
| **Excel backup upload** (fill rate / backorders files) | `future-excel-backup-contract.md` | **Deferred** — Acumatica remains sole source of truth |
| **WhatsApp / Telegram bot** (Baileys, OTP, order queries) | `WhatsAppBot.md` | **Not built** — requirements only |
| **Dual classifier drift** (BI `product_type` vs prefix rules) | Status doc | Prefer single source; re-seed BI when sparse |
| **Hourly auto-match job** | Cron guide | Implemented but **disabled by default** |

### B. Operational rollout (code done, go-live incomplete)

| Item | Notes |
|------|-------|
| **Team staff activation** | Import creates **inactive** users; welcome emails after review |
| **Org tree wiring** | Reports-to for HODs/reportees; seed tree command available |
| **Import gaps** | Interns, shared mailboxes, name mismatches |
| **Consultant customer lists** | Backfill from SOs + explicit Carrefour/etc. lists where needed |
| **Brand Ops brand assignments** | Assign Unilever etc. partner brands |
| **Backend production harden** | VPS deploy, SSL, crontab, secrets (Acumatica, Microsoft, mail) |
| **Azure OAuth redirect URIs** | Must match production `APP_URL` |
| **Frontend API base URL** | Point Worker at production Laravel API |

### C. Stakeholder decisions still open

| Question | Context |
|----------|---------|
| Steve GT HOD email | Team rollout |
| Shared mailbox policy | Deactivate vs deny_all service accounts |
| Jane / Carrefour | Explicit customers vs SO backfill only |
| Regional managers | Full sector like HOD vs subtree only |
| Revenue masking defaults | Which org levels hide KES |
| Direct Tally API needed? | Or stay on Acumatica fill-rate path only |

### D. Out of scope / non-goals (documented)

- Auto-reply to customers from OrderWatch  
- Writing back to Outlook or multi-tenant Acumatica  
- Order Match against invoices/shipments (SO only for matching)  
- Force-push / rewrite git history (Lovable-connected repo)

---

## Roles (as shipped)

| Role | Typical access |
|------|----------------|
| Administrator | Full system |
| Customer Service Manager | Ops + matching + limited admin |
| Customer Service Agent | Day-to-day mailbox / match workflow |
| Sales Operations | Fill rate, backorders, zones, dashboards |
| Sales Consultant | Own portfolio (rep-scoped) |
| Executive | Read-focused overview / AI / optimization |

Org **data scope** (team module) further restricts by sector, product type, customers, brands.

---

## Integrations checklist

| Integration | Status |
|-------------|--------|
| Acumatica IpayV2 (SO, inventory, customers, zones fallback) | ✅ Built |
| Microsoft Graph mailbox OAuth | ✅ Built |
| AI providers (Claude / OpenAI keys in admin) | ✅ Built |
| SMTP / daily report mail | ✅ Built (config-dependent) |
| Cloudflare Pages/Workers frontend | ✅ Deployed |
| WhatsApp / Telegram | ❌ Not started |
| Excel file upload as data source | ❌ Deferred |
| Separate Tally ERP | ❌ Not built (Acumatica used) |

---

## Recommended priority order (next work)

1. **Production backend** — VPS + cron + secrets + API URL on frontend  
2. **Team go-live** — import → tree → activate → smoke-test personas  
3. **Close fixes-now sync UX** — stop button, status reconcile, grammar/false positives  
4. **Backorder charts** if leadership still wants the visual pack  
5. **Confirm Tally / formula / revenue masking** with stakeholders  
6. **WhatsApp bot** only if product priority (large net-new workstream)  
7. **Excel backup upload** only if Acumatica outage resilience is funded  

---

## Key doc index

| Doc | Use |
|-----|-----|
| `orderwatch-management-brief.md` | Non-technical system overview |
| `orderWatch-modules.md` | Session completion log (Jul 8) |
| `fillrate-manufactured-teams-status.md` | Fill rate · manufactured · teams deep dive |
| `fill-rate-user-guide.md` | Fill rate ops guide |
| `team-module-guide.md` | Team rollout checklist |
| `cron-jobs-guide.md` | Scheduler reference |
| `DEPLOY-VPS.md` | Backend deploy |
| `new-features-and-sync.md` | Zones + recent sync notes |
| `future-excel-backup-contract.md` | Deferred Excel import contract |
| `WhatsAppBot.md` | Future chatbot requirements |
| `fixes-now.md` | Outstanding critical fix list |
| `PRD-order-match.md` / `Outlook_Email_Sync_PRD_v1.1.md` | Match / mailbox PRDs |
| `Order-Fill-Rate-Backorder-Dashboard-PRD.md` | Fill rate / backorder PRD |

---

## One-line summary

**OrderWatch’s core ops platform is largely built and frontend-live:** Acumatica + Outlook + match + fill rate + inventory + teams + daily email.  
**What remains is mostly production hardening, team activation, a short list of UX/sync polish items, and net-new ideas (WhatsApp bot, Excel backup) that are documented but not implemented.**
