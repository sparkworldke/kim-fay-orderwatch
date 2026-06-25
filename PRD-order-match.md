# PRD — Order Match
### AI-Powered Email-to-Sales-Order Matching Feature

**Version:** 1.0.0 | **Status:** Draft | **Date:** 2026-06-23

---

## 1. Overview & Problem Statement

### Context

The platform already authenticates with Microsoft Outlook via OAuth. Customer purchase orders arrive across multiple folders, sent by different users, in varied formats:

- Subject line contains the PO number directly (e.g. PO-2026-00847)
- Subject line has no PO reference — the number is in an attachment (PDF, image, scan)
- Multiple emails reference the same PO from different contacts or forwarded threads

Currently, mapping these emails to the correct Acumatica Sales Order requires manual lookup against CustomerPONbr. This is slow, error-prone, and creates backorder blind spots.

### What Order Match does

- Syncs selected Outlook folders on schedule or on-demand (per-folder, per-date-range)
- Extracts PO numbers from email subjects, bodies, and attachments via OCR where needed
- Uses AI (Claude) to predict the best matching Acumatica Sales Order for each email
- Detects duplicate PO references across emails and flags them
- Surfaces all predictions in a review queue — admin accepts or rejects with one click
- Writes confirmed matches to a tamper-evident match log

---

## 2. Goals & Non-Goals

### Goals

- Allow admin to register any Outlook folder and sync by date range with one click
- Extract PO numbers from subjects, bodies, and attachments automatically
- Match extracted POs to Acumatica CustomerPONbr with confidence scoring
- Flag duplicate PO numbers across separate emails
- Admin must Accept each match — no auto-write without confirmation
- Maintain a tamper-evident match log for audit

### Non-Goals (v1.0)

- Matching emails to invoices or shipments (SO only)
- Auto-replying to customers from the platform
- Bi-directional sync (writing back to Outlook)
- Multi-company Acumatica tenants
- Training a custom model — uses Claude API via prompt engineering only

---

## 3. User Roles

| Role | Permissions |
|---|---|
| Admin | Register folders, trigger syncs, view full match queue, accept/reject matches, view audit log |
| Ops Manager | View match queue (read-only), view audit log |
| Developer | Configure env vars, manage OCR provider keys, access raw API logs |

---

## 4. Feature Architecture

> Three extraction paths run per email → AI scores candidates → Admin accepts/rejects. No writes to Acumatica without explicit admin confirmation.

| Layer | Description |
|---|---|
| Outlook Folder Sync | OAuth-authenticated folder fetch by date range via Microsoft Graph API |
| Email Ingestion | Store email metadata; queue attachments for OCR |
| Subject/Body Extractor | Regex patterns + AI prompt for PO extraction from text |
| Attachment OCR | PDF/image → text via Azure/Google Vision → regex + AI extraction |
| AI Match Engine | Claude API scores email vs candidate SOs; returns confidence + reasoning |
| Duplicate Detector | Normalised PO deduplication within batch and against match_log history |
| Match Review Queue | Accordion UI grouped by main account; Accept/Reject per email |
| Match Log | Append-only audit table; written only on admin Accept |

---

## 5. Outlook Folder Sync

### 5a. Folder Registration

Admin registers folders via settings panel. Each folder stores: `folderId`, `folderName`, `syncEnabled`, `lastSyncedAt`, `autoSyncCron`.

### 5b. On-Demand Sync by Date

Admin clicks "Sync now" on any registered folder. A date-range picker opens (default: last 7 days). Endpoint:

```
POST /v1/order-match/folders/{folderId}/sync
Body: { "from": "2026-06-01", "to": "2026-06-23" }
```

### 5c. Scheduled Auto-Sync

Folders with `autoSyncCron` set run automatically. Default: weekdays at 06:00 EAT (Africa/Nairobi). Uses the same node-cron scheduler as email notifications.

---

## 6. PO Extraction Pipeline

Three paths run in parallel per email. Results are merged; highest-confidence extraction becomes `canonicalPO`.

| Path | Source | Method | Confidence ceiling |
|---|---|---|---|
| A | Email subject | Regex patterns (PO-XXXX, P.O.-XXXX, Order XXXX) | 1.0 |
| B | Email body (first 2,000 chars) | AI extraction prompt (Claude) | 0.97 |
| C | Attachments | OCR (PDF/image) then regex + AI | 0.90 |

### Supported attachment types for OCR

| Type | Handler |
|---|---|
| PDF | Convert page 1 to image → OCR provider |
| PNG / JPEG / TIFF | Direct OCR provider call |
| DOCX / XLSX | Extract text via mammoth / SheetJS — no OCR needed |
| Unsupported | Log and skip — do not error the email |

> OCR is asynchronous. Sync response sets `extraction_status = "ocr_pending"`. Completion happens in a background worker.

---

## 7. AI Match Engine

### 7a. Candidate Fetch

For each email with a `canonicalPO`, attempt exact CustomerPONbr match first. On no result, fetch the 20 most recent open SOs for the resolved customer as fuzzy candidates.

### 7b. Confidence Thresholds

| Confidence | Label | UI Treatment |
|---|---|---|
| ≥ 0.95 | High | Green badge — top of queue |
| 0.75 – 0.94 | Medium | Amber badge — caution note shown |
| < 0.75 | Low | Red badge — collapsed, requires expansion |
| 0 / no match | No match | Grey — shown in unmatched section |

### 7c. Auto-Match Eligibility

> An email is eligible for auto-match when: (1) PO extraction confidence ≥ 0.95, (2) AI match confidence ≥ 0.95, AND (3) exact CustomerPONbr string match. Auto-match = pre-filled queue entry only. No write occurs until admin clicks Accept.

### 7d. Customer ID Resolution

Email sender domain is mapped to Acumatica CustomerID via a configurable JSON file (`/config/customer-domains.json`). This narrows the candidate fetch to that customer's SOs only.

---

## 8. Duplicate PO Detection

After extraction, normalised PO strings are checked for duplicates within the sync batch and against previously accepted matches in `match_log`.

| Scenario | Flag | Action required |
|---|---|---|
| Same PO, same batch, different senders | `duplicate` | Admin nominates canonical email before any accept |
| Same PO already matched in prior sync | `previously_matched` | Warning shown; admin confirms intent |
| Same PO, different CustomerID | `PO_CUSTOMER_MISMATCH` | Blocks match; escalation required |

> Never auto-accept when a duplicate flag is present, regardless of confidence score.

---

## 9. Admin Match Review UI

### Queue layout

Main accounts are grouped as accordion rows, sorted by total revenue at risk. Clicking a row expands sub-accounts, then individual emails with their top prediction and action buttons.

### Action buttons

| Action | Outcome | DB write |
|---|---|---|
| Accept | Confirms match | `match_log`: status=accepted, accepted_by, accepted_at |
| Reject | Dismisses prediction | `match_log`: status=rejected, rejection_reason |
| Mark as Duplicate | Links to canonical email | `match_log`: status=duplicate_acknowledged |
| View email | Opens full email in side panel | None |
| Re-run match | Fresh AI score | Updates `match_predictions` row |

> A confirmation modal is required before accepting any match with AI confidence < 0.75. The modal must state the confidence and match basis.

---

## 10. Auto-Match with Admin Accept

High-confidence matches (extraction ≥ 0.95, AI ≥ 0.95, exact PO string match) are labelled AUTO-MATCH in the queue. They appear pre-highlighted but require the same Accept click as any other match. No Acumatica write occurs without explicit admin action.

When admin clicks Accept, the system: (1) updates `match_log`, (2) optionally writes a note to the Acumatica SO activity log (env var controlled, default off in v1), (3) emits a `match.accepted` event for downstream notifications.

---

## 11. Integration with Existing System

### New API routes

| Method | Route | Description |
|---|---|---|
| POST | `/api/order-match/folders` | Register a folder |
| GET | `/api/order-match/folders` | List registered folders |
| POST | `/api/order-match/folders/:id/sync` | Trigger sync (body: { from, to }) |
| GET | `/api/order-match/queue` | Get match review queue |
| POST | `/api/order-match/matches/:id/accept` | Admin accept |
| POST | `/api/order-match/matches/:id/reject` | Admin reject |
| GET | `/api/order-match/audit-log` | Paginated audit log |

### New database tables

| Table | Purpose |
|---|---|
| `outlook_folders` | Registered folders and their sync config |
| `email_messages` | All ingested emails with extraction status and canonicalPO |
| `match_predictions` | AI-scored candidates per email (one row per candidate) |
| `match_log` | Append-only audit of all admin accept/reject/duplicate actions |

---

## 12. Email Notification Updates

Two new triggers are added to the existing notification system:

| Trigger | Recipients | Subject |
|---|---|---|
| Queue has > 20 pending items | Admin | `[Order Match] Review queue: {N} emails awaiting match` |
| Duplicate PO detected | Ops Manager | `[Order Match] Duplicate PO detected: {poNumber} — {N} emails` |

---

## 13. API Contract

### POST /api/order-match/folders/:id/sync

Request body: `{ "from": "2026-06-01", "to": "2026-06-23" }`

Response: `syncId`, `folderName`, `emailsFound`, `emailsQueued`, `status: "processing"`, `estimatedCompletionSeconds`

### GET /api/order-match/queue

Query params: `status` (pending|accepted|rejected|all), `accountId`, `page`, `pageSize`.

Response: paginated `groups` array keyed by `mainAccount`, each with `subAccounts[]` and `emails[]` including `topPrediction` confidence and `matchStatus`.

### POST /api/order-match/matches/:id/accept

No request body required. Identity from auth session. Response: `matchLogId`, `orderNbr`, `status: "accepted"`, `acceptedBy`, `acceptedAt`.

---

## 14. Data Models

### MatchStatus enum

| Value | Meaning |
|---|---|
| `pending` | Awaiting admin review |
| `accepted` | Admin confirmed match |
| `rejected` | Admin dismissed |
| `duplicate_acknowledged` | Flagged as duplicate; canonical nominated |
| `auto_matched` | High-confidence auto-prediction; still awaiting admin Accept click |

### ExtractionStatus enum

| Value | Meaning |
|---|---|
| `pending` | Not yet processed |
| `found` | PO extracted (any source) |
| `not_found` | No PO detected after all three paths |
| `ocr_pending` | Attachment queued for OCR, not yet complete |
| `ocr_failed` | OCR attempted and failed |
| `ocr_low_confidence` | OCR provider confidence < 0.6; not used as canonicalPO |
| `conflict` | All three paths returned different PO numbers; manual review required |

### MatchType enum

| Value | Meaning |
|---|---|
| `exact` | CustomerPONbr exact string match |
| `fuzzy` | Partial string match (Levenshtein distance ≤ 2) |
| `semantic` | AI inferred from context; no PO string match |
| `no_match` | AI found no viable candidate |

---

## 15. Guardrails

### Sync & Ingestion

| # | Rule |
|---|---|
| 1 | Always scope folder syncs to a date range. Maximum 90 days per sync. Never issue unbounded fetch. |
| 2 | Paginate Graph API calls using `@odata.nextLink`. Do not assume all emails fit in one page. |
| 3 | Use `INSERT ... ON CONFLICT DO NOTHING` on `graph_message_id`. Re-syncing must not create duplicates. |
| 4 | On Graph API 429, back off for the Retry-After duration before retrying. |
| 5 | Only process attachments with supported contentType. Log and skip unsupported types. |
| 6 | OCR is async. Never block sync response waiting for OCR. Set `extraction_status = "ocr_pending"`. |

### PO Extraction

| # | Rule |
|---|---|
| 1 | Never send more than 2,000 characters of email body to AI. Trim at the last complete sentence. |
| 2 | Sanitise extracted PO numbers before any Acumatica query. Strip chars outside `[A-Z0-9-]`, max 30 chars. |
| 3 | Never store raw attachment bytes in the database. Store only OCR text and attachment reference ID. |
| 4 | If all three paths return different PO numbers, set `extraction_status = "conflict"` and flag for manual review. |
| 5 | OCR confidence below 0.6 must not be used as canonicalPO. Store in `raw_extractions` only. |

### AI Match

| # | Rule |
|---|---|
| 1 | Never write a match to Acumatica without explicit admin Accept. Auto-match = pre-filled queue entry only. |
| 2 | Cap AI candidate input at 20 SOs per prompt. Filter by customer and date first. |
| 3 | Always validate AI response is valid JSON. Fall back to `matchType = "no_match"` on parse failure. |
| 4 | High confidence requires BOTH AI ≥ 0.95 AND exact CustomerPONbr match. AI alone is capped at medium. |
| 5 | Log every AI prompt and response (truncated to 500 chars) in `ai_call_log` with model, tokens, latency. |
| 6 | Do not retry failed AI calls more than twice. On third failure, set `no_match` and add warning flag. |

### Admin Review & Match Log

| # | Rule |
|---|---|
| 1 | `match_log` is append-only. Never UPDATE or DELETE rows. Use a new "reversed" row to undo. |
| 2 | Every Accept and Reject must record `accepted_by` from auth session. No anonymous writes. |
| 3 | Accepted `match_log` row must have non-null `email_id`, `prediction_id`, and `order_nbr`. Reject insert otherwise. |
| 4 | Duplicate emails must not be accepted until canonical email is nominated. Return 409 if attempted out of order. |
| 5 | Show confirmation modal before accepting matches with AI confidence < 0.75. |
| 6 | Audit log is read-only in UI. Provide CSV export only. |

### Duplicate Detection

| # | Rule |
|---|---|
| 1 | Normalise PO before comparison: upper(), trim(), strip whitespace. "PO 847" == "PO847" == "po847". |
| 2 | Check new batch against `match_log`. A previously accepted PO is "previously_matched", not just a same-batch duplicate. |
| 3 | Never auto-accept when a duplicate flag is present, regardless of confidence. |
| 4 | Same PO with different CustomerID is `PO_CUSTOMER_MISMATCH` — blocks matching, requires escalation. |

### Security & Data

| # | Rule |
|---|---|
| 1 | Outlook OAuth access token must never be logged or stored in plaintext. Store encrypted refresh token only. |
| 2 | Email body must not be returned in queue API response. Only `bodyPreview` (255 chars max) in list views. |
| 3 | OCR text is PII. Purge `ocr_extracted_text` from database after 90 days via scheduled cleanup job. |
| 4 | `/api/order-match/*` routes require authentication on every call. No public endpoints in v1. |
| 5 | Rate-limit AI match endpoint to 60 calls/minute. Burst queues emails rather than failing them. |

---

## 16. Open Questions & Decisions

| # | Question | Owner | Target |
|---|---|---|---|
| 1 | OCR provider: Azure Computer Vision vs Google Vision — confirm cost in KES | DevOps | Week 1 |
| 2 | Should Accept write a note to Acumatica SO activity log by default? (currently opt-in) | Ops | Week 1 |
| 3 | Customer domain map: config file vs UI-editable admin table? | Product | Week 2 |
| 4 | `email_messages` retention: 90 days proposed — confirm with legal/compliance | Legal | Week 2 |
| 5 | PO_CUSTOMER_MISMATCH: block matching entirely or warning-only mode? | Product + Ops | Week 2 |
| 6 | Should Ops Manager be able to accept matches or view-only? | Product | Week 1 |

---

*PRD v1.0.0 — Order Match | Acumatica Integration Platform | June 2026*
