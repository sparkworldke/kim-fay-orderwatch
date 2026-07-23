# PRD: Site-wide Clickable Customers & DTC Price List Queue + Letterhead PDF

**Status:** Draft  
**Date:** 2026-07-23  
**Product:** Kim-Fay OrderWatch  
**Module scope:**
1. **Site-wide UX** — make customer name / customer ID clickable wherever they appear  
2. **DTC / Calltronix** — queue Excel price-list uploads; export price list as branded PDF  

**Owner:** [fill in]  
**Requested by:** [fill in]  
**Primary consumers:** Sales Ops, KP / Calltronix sales, Customer Service, Warehouse (customer drill-down)

---

## 1. Executive summary

Users see customer names and Acumatica IDs in many tables (backorders, fill rate, orders, DTC quotes/SOs, dashboards) but often cannot jump to the customer record without searching. Separately, DTC price-list Excel imports currently run **inline in the HTTP request**, which is fragile for large files, and there is **no customer-facing PDF export** with Kim-Fay letterhead for sharing the DTC price list.

This PRD defines:

| Workstream | Outcome |
|---|---|
| **A. Clickable customers** | Shared UI helper: customer **name** and/or **ID** link to the canonical customer deep-link, with permission and “missing ID” safe fallbacks. |
| **B. DTC price-list upload queue** | Excel/CSV price uploads are accepted, stored, processed asynchronously (queue/job), with status UI. |
| **C. DTC price-list PDF** | Downloadable PDF of the filtered (or full) DTC price list on Kim-Fay letterhead with official contact block. |

---

## 2. Current state (ground truth)

### 2.1 Customer navigation

- Canonical customer deep-link already exists:  
  **`/app/customer-orders/$customerId`**  
  (branch / SO detail nested under the same tree).  
- Alternate: `/app/customer-brands/$customerId` for brand rollups.  
- Many surfaces render `customer_name` / `customer_acumatica_id` as plain text (e.g. DTC quotes table uses `{x.customer_name}` with no `Link`).  
- No shared `CustomerLink` component; each screen invents its own pattern or none.

### 2.2 DTC price list

| Capability | Status |
|---|---|
| Product seed from inventory (`prices/sync-products`) | ✅ Implemented |
| Price refresh from Acumatica | ✅ Implemented |
| Excel import `POST …/prices/import-excel` via `DtcPriceExcelImportService` | ✅ Synchronous request (timeout-sensitive) |
| `DtcSyncLog` for import runs | ✅ Partial (log row; not a full job queue UI) |
| Background / queued import | ❌ Not implemented |
| PDF export with letterhead | ❌ Not implemented |

Import expects Acumatica-style columns (`Inventory ID`, `Price`, UOM, tax, warehouse, dates, etc.) and matches on `inventory_id` → `dtc_price_list`.

### 2.3 Company identity (letterhead / footer — source of truth for this PRD)

Use the following **exact** legal and contact block on all customer-facing DTC PDF exports unless Admin overrides later:

```
Kim-Fay East Africa Limited
Maasai Road, Off Mombasa Road, Behind Libra House, Kenya

Call:     +254709892000
WhatsApp: +254777777047
Email:    customercare@kimfay.com
```

Logo asset: prefer existing OrderWatch brand asset (`public/kim-fay-logo.png` / app logo) unless Marketing supplies a higher-res letterhead logo.

---

## 3. Goals

1. **One-click customer context** — from any operational list, open the customer’s OrderWatch home (`customer-orders`) without copy-paste search.  
2. **Reliable large price-list uploads** — accept Excel/CSV, process off-request, surface progress/errors.  
3. **Professional price-list PDF** — branded letterhead + contacts for email/print to DTCACCOUNT customers and Calltronix partners.  
4. **Consistency** — one link component, one company contact config, no one-off implementations per page.

### Non-goals

- Replacing Acumatica as system of record for prices (OrderWatch remains a mirror + DTC workflow layer).  
- Live Acumatica calls per PDF row (use snapshot `dtc_price_list`).  
- Making **every** free-text string that *might* be a customer name clickable (only rows with a known `customer_acumatica_id` or validated local customer key).  
- Redesigning the full DTC quote → SO conversion flow.  
- Multi-tenant multi-brand letterheads (single Kim-Fay East Africa entity for v1).

---

## 4. Workstream A — Site-wide clickable customer name / ID

### 4.1 Functional requirements

| # | Requirement | Priority |
|---|---|---|
| FR-A1 | Introduce a shared `CustomerLink` (or equivalent) component used across app tables/cards. | P0 |
| FR-A2 | When `customer_acumatica_id` is present, **name** is a link to `/app/customer-orders/{customer_acumatica_id}`. | P0 |
| FR-A3 | When name is missing, show **customer ID** as the clickable label. | P0 |
| FR-A4 | Optional `showId` prop: display `Name · ID` with either or both clickable (same destination). | P1 |
| FR-A5 | If ID is missing/null/empty: render plain text only (no dead link, no router error). | P0 |
| FR-A6 | Respect auth/nav: if user lacks access to customer-orders (permission / scope), render plain text or link only when destination is allowed. | P0 |
| FR-A7 | Keyboard accessible (`<a>`/`Link`), underline or primary colour on hover, `title` with full name+ID. | P1 |
| FR-A8 | Prefer in-app navigation (`@tanstack/react-router` `Link`); open in new tab only on modifier-click (browser default). | P1 |

### 4.2 Surfaces to audit and update (minimum set)

Apply `CustomerLink` wherever both name/ID appear as list/detail labels:

| Area | Examples |
|---|---|
| Operations | Backorders list & SKU accordion lines; fill-rate tables; business optimization |
| Orders | Orders list, orders-by-date, order detail headers |
| Customer orders / branches | Parent already on customer route — link still OK for child accounts / ship-to |
| DTC | Quotes, sales orders, customers tabs |
| KP | Accounts, dormant, items-not-ordered, commissions statements if customer shown |
| FOL | Request list/detail customer fields |
| Mailbox / order-match | Matched customer chips |
| Dashboard / reports | Top-account widgets, daily-report related UI if in-app |
| Price change / PCR | Customer fields if present |
| Admin / data exports | Optional (links less critical in admin grids) |

**Inventory / product-only screens** without a customer context are out of scope.

### 4.3 Destination rules

| Context | Default destination |
|---|---|
| Standard B2B customer | `/app/customer-orders/$customerId` |
| Explicit brand-report entry (customer brands page only) | Keep existing `/app/customer-brands/$customerId` |
| DTC POS fixed customer (`CUST101470` etc.) | Still link to customer-orders for that ID if it exists in catalog; if not, plain text |
| Branch child | Prefer parent customer ID if API already exposes it; else branch ID on branch route if available |

Do **not** invent a second global customer profile page for v1.

### 4.4 Implementation notes

- Single module e.g. `src/components/customer-link.tsx` + thin helper `customerOrdersPath(id: string)`.  
- Backend: no new endpoints required if list payloads already include `customer_acumatica_id`. Where only name is returned, prefer a small API enrichment over client-side search.  
- Scope: users with portfolio scope only see links for customers in scope; out-of-scope IDs should not 403 the whole page — link may 403 on navigation (acceptable) or be suppressed if backend already scopes lists.

### 4.5 Acceptance criteria

- [ ] Shared component used on ≥ core surfaces listed in §4.2 (backorders, orders, DTC quotes, fill-rate).  
- [ ] Click name or ID lands on customer-orders for that Acumatica ID.  
- [ ] Rows without ID never produce a broken route.  
- [ ] No regression in table layout (truncate/tooltip for long names).  
- [ ] Typecheck/build pass; smoke test as Sales Ops + scoped sales consultant roles.

---

## 5. Workstream B — DTC price-list upload queue

### 5.1 Problem

`POST /api/kp/dtc-calltronix/prices/import-excel` runs `DtcPriceExcelImportService::import()` **inside the request**. Large “DTC Sales Prices” exports risk gateway timeouts (504), partial UI feedback, and blocked workers.

### 5.2 Functional requirements

| # | Requirement | Priority |
|---|---|---|
| FR-B1 | User uploads Excel/CSV via Price List UI (same column contract as today). | P0 |
| FR-B2 | API **accepts file immediately**, stores it, creates a queue job / work item, returns `job_id` + `status=queued`. | P0 |
| FR-B3 | Background worker processes import using existing mapping rules (`Inventory ID` → `dtc_price_list`). | P0 |
| FR-B4 | Persist run status: `queued` → `running` → `completed` \| `failed` \| `partial`, with counts (`updated`, `created`, `skipped`, `unmatched`, `rows_read`, `errors[]`). | P0 |
| FR-B5 | UI shows **upload queue / last runs**: status, filename, actor, started/finished, summary toast when complete (poll or refresh). | P0 |
| FR-B6 | Only one **active** price Excel import at a time (reject or queue behind; document choice — **recommend queue FIFO, one concurrent**). | P1 |
| FR-B7 | Permission: same as today (`can_import_prices` / existing DTC price import gate). | P0 |
| FR-B8 | Retain file briefly (e.g. 7 days) for support reprocess; not permanent product data store. | P2 |
| FR-B9 | Failed runs keep prior price list intact (transactional per-row or whole-import as today; never wipe catalog on failure). | P0 |

### 5.3 Suggested data model

**Option (preferred):** extend `dtc_sync_logs` (already used for `price_excel_import`) with:

- `status` enum extended for `queued`  
- `storage_path`, `original_filename`, `queue_job_id`  
- `progress` JSON: `{ rows_read, processed, updated, created, … }`  
- `finished_at`, `error_message`

**Job:** `ProcessDtcPriceExcelImportJob` (Laravel queue) dispatched from controller.

**CLI (ops):** `php artisan dtc:process-price-import {logId?}` for SSH re-run if needed.

### 5.4 API shape (illustrative)

| Method | Path | Behaviour |
|---|---|---|
| `POST` | `kp/dtc-calltronix/prices/import-excel` | Multipart upload → store → dispatch → `202` + log payload |
| `GET` | `kp/dtc-calltronix/prices/import-jobs` | Recent import runs (paginated) |
| `GET` | `kp/dtc-calltronix/prices/import-jobs/{id}` | Single run detail + errors sample |

Keep legacy synchronous mode behind a feature flag **only if** needed for small files; default path is queued.

### 5.5 UI (Price List page)

- **Upload** button → file picker → “Queued for import” toast.  
- **Recent imports** panel: status badge, filename, counts, relative time.  
- Disable re-upload while a job is `queued`/`running` **or** allow queue with position (prefer simple single-active + message).  
- On `completed`, invalidate price list query (React Query).

### 5.6 Acceptance criteria

- [ ] 5k+ row Excel can be uploaded without HTTP timeout; job completes in background.  
- [ ] UI reflects terminal status and counts matching DB.  
- [ ] Failed job does not delete existing prices.  
- [ ] Unauthorized users cannot enqueue.  
- [ ] Feature tests cover queue accept + job success/failure paths.

---

## 6. Workstream C — DTC price-list PDF with letterhead

### 6.1 Functional requirements

| # | Requirement | Priority |
|---|---|---|
| FR-C1 | **Download PDF** action on DTC Price List page. | P0 |
| FR-C2 | PDF content = current price list (respect active filters: brand, search, in-stock, etc., **or** full list with explicit toggle — default: **current filters**). | P0 |
| FR-C3 | Header / letterhead: Kim-Fay logo + legal name. | P0 |
| FR-C4 | Contact block exactly as §2.3 (Call, WhatsApp, Email, address). | P0 |
| FR-C5 | Body table columns (minimum): Inventory ID, Description, UOM, DTC price (KES), optional Tax category / Effective date if present. | P0 |
| FR-C6 | Footer: page numbers, generated timestamp (`Africa/Nairobi`), “Prices subject to change” disclaimer. | P1 |
| FR-C7 | Filename e.g. `Kim-Fay-DTC-Price-List-YYYY-MM-DD.pdf`. | P1 |
| FR-C8 | Permission: users who can view price list can download PDF; import permission not required. | P0 |
| FR-C9 | Server-generated PDF (Dompdf / Snappy / similar already in stack preference) for consistent letterhead — not browser `window.print` only. | P0 |

### 6.2 Layout sketch

```
┌──────────────────────────────────────────────────────────┐
│ [LOGO]   Kim-Fay East Africa Limited                     │
│          Maasai Road, Off Mombasa Road,                  │
│          Behind Libra House, Kenya                       │
│          Call: +254709892000  ·  WhatsApp: +254777777047 │
│          customercare@kimfay.com                         │
├──────────────────────────────────────────────────────────┤
│ DTC ACCOUNT PRICE LIST                                   │
│ Generated: 23 Jul 2026 14:30 EAT · Filters: …            │
├──────────────────────────────────────────────────────────┤
│ Inventory ID | Description | UOM | Price (KES) | …       │
│ … rows …                                                 │
├──────────────────────────────────────────────────────────┤
│ Prices subject to change without notice. · Page 1 of N   │
└──────────────────────────────────────────────────────────┘
```

### 6.3 Company config

Centralise letterhead fields in config (e.g. `config/company.php` or `config/dtc.php`):

```php
'legal_name' => 'Kim-Fay East Africa Limited',
'address_lines' => [
    'Maasai Road, Off Mombasa Road, Behind Libra House, Kenya',
],
'phone' => '+254709892000',
'whatsapp' => '+254777777047',
'email' => 'customercare@kimfay.com',
'logo_path' => public_path('kim-fay-logo.png'),
```

PDF service reads config only — no hard-coded strings in multiple views.

### 6.4 API

| Method | Path | Behaviour |
|---|---|---|
| `GET` | `kp/dtc-calltronix/prices/export.pdf` | Same filter query params as price list JSON; stream `application/pdf` |

Optional Excel export is **out of scope** unless already present.

### 6.5 Acceptance criteria

- [ ] PDF downloads with correct letterhead and **exact** contact details from §2.3.  
- [ ] Prices match on-screen filtered list for the same query.  
- [ ] Multi-page lists paginate cleanly; logo not clipped.  
- [ ] No sensitive admin fields (cost, margins) on customer PDF.  
- [ ] Feature test asserts PDF content-type and non-empty body; unit/snapshot test for contact block presence if practical.

---

## 7. Permissions & security

| Action | Gate |
|---|---|
| Click customer link | Existing page access + customer scope (destination enforces) |
| Upload price list (queue) | Existing `can_import_prices` / DTC import permission |
| View import job history | Same as import or broader DTC view (product decision: **importers + admins**) |
| Download PDF | DTC price list view permission (`dtc.view` or equivalent) |

- Uploaded files stored outside public web root; signed internal path only.  
- Virus scanning not required for v1 (internal staff upload); document residual risk.  
- Rate-limit upload endpoint (e.g. N per hour per user).

---

## 8. Analytics / observability

- Log import job IDs in structured logs (`dtc`, `price_excel_import`).  
- Optional: count PDF downloads in existing analytics only if low-cost; not required for MVP.  
- Surface last successful import timestamp on Price List header (already partially via meta).

---

## 9. Test plan

### 9.1 Clickable customers

- Link present on backorders, orders, DTC quotes with valid ID.  
- No link when ID null.  
- Scoped consultant cannot use link to escape scope (destination 403 or empty — document behaviour).  
- Keyboard focus and screen-reader name.

### 9.2 Upload queue

- Small file: queued → completed; counts match sync import.  
- Large file: HTTP returns quickly (`202`); job finishes later.  
- Corrupt file: `failed` with readable error; catalog unchanged.  
- Concurrent second upload: rejected or queued per FR-B6.

### 9.3 PDF

- Empty filter vs brand filter row counts.  
- Contact strings match PRD §2.3 byte-for-byte (allow normalise of whitespace only).  
- Currency formatting KES; no NaN prices.

### 9.4 Regression

- Existing synchronous import tests updated for queue.  
- DTC quote create still reads prices from `dtc_price_list`.  
- Frontend typecheck + backend feature suite green.

---

## 10. Rollout plan

| Phase | Deliverable | Depends |
|---|---|---|
| **P0a** | `CustomerLink` + wire top 4 surfaces (backorders, orders, DTC, fill-rate) | None |
| **P0b** | Company config + PDF export endpoint + UI button | None (parallel) |
| **P1** | Remaining site-wide CustomerLink audit | P0a |
| **P1** | Queued Excel import + job history UI | Existing import service |
| **P2** | File retention cleanup, import reprocess CLI, polish | P1 |

Deploy notes: run migrations for any new job columns; ensure queue worker (`queue:work` / Horizon / cron) is running on VPS for imports.

---

## 11. Open questions

1. PDF default: **filtered list** vs always full catalog? (PRD default: filtered.)  
2. Should customer **ID** always show next to name site-wide, or only when name is ambiguous?  
3. For DTC quotes where the commercial customer differs from POS `CUST101470`, which ID should the link use — quoted customer or POS? (**Recommend: quoted customer**.)  
4. Queue driver on production: database vs Redis — align with existing OrderWatch deploy.  
5. Is WhatsApp label “Whatsapp” or “WhatsApp” on PDF? (**PRD uses WhatsApp.**)

---

## 12. Success metrics

- ≥ 80% of customer-bearing tables use `CustomerLink` within one release.  
- Price Excel imports of typical production size complete without user-facing 504.  
- Calltronix/KP users send PDF price lists externally without retyping letterhead contacts.

---

## 13. Appendix — Contact block (copy-paste for design/QA)

**Legal name:** Kim-Fay East Africa Limited  

**Address:** Maasai Road, Off Mombasa Road, Behind Libra House, Kenya  

**Call:** +254709892000  

**WhatsApp:** +254777777047  

**Email:** customercare@kimfay.com  

---

## 14. Implementation update (2026-07-23)

- The existing shared `CustomerLink` in `src/components/entity-links.tsx` is confirmed on backorders, orders, fill-rate, the dashboard, and business optimization, and is used on DTC quotes, DTC customers, and DTC sales orders (list rows). Missing customer IDs render as plain text through the shared fallback.
- Price Excel/CSV uploads now return `202 Accepted`, store the file on the private local disk, create a queued `dtc_sync_logs` work item, and dispatch `ProcessDtcPriceExcelImportJob` on the `imports` queue. A second active upload receives `409` and uploads are rate-limited.
- The DTC Price List page polls recent import runs and refreshes prices after a completed/partial run. Progress and terminal errors are retained on the sync log.
- `GET kp/dtc-calltronix/prices/export.pdf` generates a server-side Dompdf document from current filters with the configured Kim-Fay legal/contact block, logo, timestamp, disclaimer, and page numbers.
- Deployment requires migrations and a worker that consumes the import queue, for example: `php artisan queue:work --queue=imports,default --tries=1 --timeout=900`. Set `DB_QUEUE_RETRY_AFTER=1200` or higher so a long import is not delivered twice.
- Uploaded source-file cleanup/reprocessing remains P2. Files are not web-accessible.

## 14a. Audit correction (2026-07-23) — §14 overclaimed the KP surfaces

A full pass against every surface in §4.2 found the note above described the *first* batch accurately but implied more coverage than existed. Confirmed gap (matching a live screenshot of the KP Dormant page showing unlinked customer names) and now fixed in this pass:

| Surface | Before | Now |
|---|---|---|
| KP Dormant (`app.kp.dormant.tsx`) | Plain text name/ID | `CustomerLink` |
| KP Accounts (`kp-accounts-table.tsx`) | Plain text name/ID | `CustomerLink` |
| KP FOL list (`app.kp.fol.tsx`) | Plain text customer cell | `CustomerLink` |
| KP FOL detail (`app.kp.fol.$id.tsx`) | Plain text `name · id` | `CustomerLink` |
| PCR list (`app.price-change-requests.tsx`) | Plain text customer cell | `CustomerLink` |
| PCR detail (`app.price-change-requests.$id.tsx`) | Plain text in header, Snapshot tile, and lowest-5-prices sub-table | `CustomerLink` in all three (Snapshot's `value` prop widened from `string` to `ReactNode` to allow it) |
| Items not ordered (`app.kp.items-not-ordered.tsx`) | Working `<Link>` but bypassed the shared component (no consistent styling/`aria-label`) | Switched to `CustomerLink` |

**Still open, deliberately not touched in this pass:**
- **Commissions** (`app.kp.commissions.tsx` / `.$statementId.tsx`) never renders a customer at all — it's rep-scoped, not customer-scoped, so it's out of scope unless a customer field is added later.
- **Order-match** has no customer name/ID render today — nothing to link.

## 14b. Remaining two surfaces closed out (2026-07-23)

- **Mailbox** (`src/routes/app.mailbox.tsx:718-757`, not `EmailImportPanels.tsx` as previously logged — corrected here) — resolved the UX call as **both**: the row's outer element changed from `<button>` to a `<div role="button" tabIndex={0}>` (click or Enter/Space still toggles expand/collapse for the whole row), and the customer name is now a real `CustomerLink` nested inside it — only when `group.group_type === "customer"` and `acumatica_id` is present (domain-only groups, which have no Acumatica match, still render as plain text). This was **not** a safe mechanical swap precisely because a real `<a>` cannot legally nest inside a `<button>`; `CustomerLink`'s built-in `stopPropagation()` on click is what lets the name navigate without also firing the row's toggle now that the wrapper is a non-interactive-by-default `<div>`.
- **DTC quote-detail modal** (`dtc-calltronix-page.tsx:479`) — customer name is now `CustomerLink` (with `showId`), consistent with the rest of the DTC surfaces in this file.

Both fixes verified with `tsc --noEmit` (no new errors introduced).

## 15. Document history

| Version | Date | Notes |
|---|---|---|
| 0.1 | 2026-07-23 | Initial PRD — clickable customers; DTC upload queue; letterhead PDF |
| 0.2 | 2026-07-23 | Audit found §14 overclaimed KP-surface coverage (confirmed by a live screenshot of unlinked KP Dormant rows); fixed Dormant, Accounts, FOL list/detail, PCR list/detail, and Items Not Ordered. Mailbox toggle and DTC quote-detail modal left open pending a UX call. |
| 0.3 | 2026-07-23 | Closed the two remaining open items: Mailbox customer grouping (button → div + nested CustomerLink, decided as "both" link and toggle) and DTC quote-detail modal. Commissions and Order-match confirmed to have no customer field to link — correctly out of scope, no code change. |
