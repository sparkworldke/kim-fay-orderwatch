# Product Requirements Document
## Outlook Email Synchronization Module
### Chunk-Based, Rate-Limit-Aware Email Sync for Laravel / PHP

---

| Field | Value |
|---|---|
| **Version** | 1.2.0 |
| **Status** | Draft — Pending Engineering Review |
| **Platform** | Laravel 10+ / PHP 8.2+ / Microsoft Graph API v1.0 |
| **Last Updated** | June 2026 |
| **Classification** | Confidential — Internal Use Only |

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Problem Statement](#2-problem-statement)
3. [Goals & Non-Goals](#3-goals--non-goals)
4. [Target Database Schema](#4-target-database-schema)
5. [Microsoft Graph API Guardrails](#5-microsoft-graph-api-guardrails)
6. [Chunked Synchronization Architecture](#6-chunked-synchronization-architecture)
7. [Individual Email Insertion Logic](#7-individual-email-insertion-logic)
8. [Field Mapping — Graph API → emails Table](#8-field-mapping--graph-api--emails-table)
9. [Load Balancing & Rate Limiting Compliance](#9-load-balancing--rate-limiting-compliance)
10. [Error Resilience & State Management](#10-error-resilience--state-management)
11. [Laravel Implementation Architecture](#11-laravel-implementation-architecture)
12. [Database State Tables](#12-database-state-tables)
13. [Admin Dashboard Requirements](#13-admin-dashboard-requirements)
14. [Validation & Testing Requirements](#14-validation--testing-requirements)
15. [Risk Register](#15-risk-register)
16. [Appendix A — Guardrail Checklist](#appendix-a--engineer-guardrail-checklist)
17. [Appendix B — Full INSERT Reference](#appendix-b--full-insert-reference)

---

## 1. Executive Summary

This PRD defines requirements for a production-grade, chunk-based Outlook email synchronization module that integrates with Microsoft Graph API inside a Laravel PHP application — **without relying on Laravel's built-in queue infrastructure**.

The module fetches emails from Outlook in small batches (10–15 per API call), then inserts each email **individually** into the local `emails` table, one row at a time, before advancing to the next email. This per-row insertion strategy ensures:

- Every email is either fully committed or cleanly skipped on retry — no partial batch commits
- PO extraction fields (`extracted_po_number`, `po_extraction_method`, `po_extraction_confidence`) can be populated inline during the insert pass without a second scan
- Duplicate protection via `message_id` unique constraint is enforced at the database level on every single insert

> **⚠ CRITICAL API USAGE GUARDRAIL**
>
> This module is **prohibited** from making unbounded, continuous, or burst requests to Microsoft Graph API. All sync operations MUST be batched, throttled, and governed by the rate-limit guardrails in Section 5. Violation may result in tenant-level API suspension by Microsoft.

---

## 2. Problem Statement

The application requires the ability to pull email data from users' Outlook/Exchange Online mailboxes and persist it locally for PO matching, reporting, and audit. Naïve full-fetch approaches introduce the following risks:

| Risk | Impact |
|---|---|
| HTTP 429 Rate Limit Exceeded | Sync blocked for minutes to hours; potential tenant throttle escalation |
| HTTP 403 Anti-Abuse Block | Microsoft flags the app; possible app-level suspension |
| Memory / CPU Saturation | Loading thousands of emails into PHP memory crashes the web process |
| Duplicate Records on Retry | Non-idempotent bulk inserts pollute the `emails` table |
| PO Fields Left NULL | Inserting without extraction means a costly second-pass scan later |

---

## 3. Goals & Non-Goals

### 3.1 Goals

- Fetch emails from Microsoft Graph API in chunks of **10–15 per API call**
- Insert each fetched email **individually (one row per INSERT)** into the `emails` table
- Record every per-email outcome in both the database audit tables and the dedicated rotated sync log file
- Populate all `emails` columns — including PO extraction fields — at insert time
- Enforce **upsert on `message_id`** to guarantee idempotency across retries
- Implement randomized inter-batch delays (**3–7 seconds**) between consecutive API calls
- Persist sync checkpoint state (last processed `message_id`, delta token) for resumability
- Honor Microsoft Graph API rate limit response headers; back off gracefully on HTTP 429
- Schedule sync via **cron every 15 minutes** — never continuous polling
- Implement a **global database sync lock** to prevent concurrent overlapping syncs
- Cap retry attempts at **5**; escalate failed emails to a manual review queue
- Perform **incremental (delta) sync** — only fetch new or changed emails since last run
- Expose sync progress metrics in the admin dashboard

### 3.2 Non-Goals

- This module does **NOT** use Laravel Queues, Horizon, or Redis-backed job queues
- This module does **NOT** send emails — outbound mail is out of scope
- This module does **NOT** implement real-time push notifications via webhooks (v1)
- Calendar or contacts sync is out of scope for v1.0
- Bulk batch INSERT of multiple rows in a single statement — each email gets its own INSERT

---

## 4. Target Database Schema

The module writes exclusively to the `emails` table with the following schema. **All column names, types, and constraints must be treated as a hard contract** — the sync engine maps Graph API fields to these columns at insert time.

```sql
CREATE TABLE `emails` (
    `id`                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mailbox_account_id`        BIGINT UNSIGNED NOT NULL,
    -- Microsoft Graph message ID — globally unique per mailbox
    -- UNIQUE constraint is the primary duplicate-prevention mechanism
    `message_id`                VARCHAR(512) NOT NULL,
    `subject`                   VARCHAR(998) NULL,
    `from_email`                VARCHAR(320) NULL,
    `from_name`                 VARCHAR(255) NULL,
    -- JSON array of recipient objects: [{"email":"a@b.com","name":"Alice"}, ...]
    `to_recipients`             JSON NULL,
    -- Plain-text preview, max ~255 chars from Graph bodyPreview field
    `body_preview`              TEXT NULL,
    `is_read`                   TINYINT(1) NOT NULL DEFAULT 0,
    -- Original received timestamp from Outlook (UTC)
    `received_at`               TIMESTAMP NULL,
    -- Graph folder displayName: "Inbox", "Sent Items", "Drafts", etc.
    `folder`                    VARCHAR(100) NULL,
    -- PO extraction fields — populated inline at insert time
    `extracted_po_number`       VARCHAR(100) NULL,
    `po_extraction_method`      VARCHAR(50)  NULL,   -- e.g. "regex", "nlp", "manual"
    `po_extraction_confidence`  DECIMAL(5,4) NULL,   -- 0.0000 to 1.0000
    -- FK to orders table — NULL until PO is matched post-insert
    `matched_order_id`          BIGINT UNSIGNED NULL,
    `has_attachments`           TINYINT(1) NOT NULL DEFAULT 0,
    -- Flag: has the PO extraction step been run for this email?
    `po_extraction_attempted`   TINYINT(1) NOT NULL DEFAULT 0,
    `created_at`                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uq_message_id`  (`message_id`),
    INDEX `idx_mailbox_folder`  (`mailbox_account_id`, `folder`),
    INDEX `idx_received_at`     (`received_at`),
    INDEX `idx_matched_order`   (`matched_order_id`),
    INDEX `idx_po_attempted`    (`po_extraction_attempted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

> **⚠ GUARDRAIL — Schema is a Hard Contract**
>
> Do not alter column names, types, or the `UNIQUE KEY` on `message_id` without a corresponding migration AND a full re-test of the sync engine's duplicate-detection logic.

---

## 5. Microsoft Graph API Guardrails

> **These guardrails are MANDATORY.** No implementation decision may override them. All engineers must review this section before writing any sync code.

### 5.1 Published Microsoft Rate Limits

> **Reference:** [Microsoft Graph Throttling Guidance](https://learn.microsoft.com/en-us/graph/throttling)

- Per-app limit: **10,000 requests / 10 minutes** per tenant
- Per-user limit: **120 requests / minute** (Exchange Online)
- `Retry-After` header **MUST** be respected on any 429 response

### 5.2 Hard Request Budget Per Cron Run

The sync engine operates within these **conservative thresholds** — set well below Microsoft's limits to provide a safety buffer.

| Parameter | Hard Limit | Rationale |
|---|---|---|
| Batch size (emails per API call) | **10–15** | Keeps individual response payload small |
| Inter-batch delay | **3–7 sec (randomized)** | Mimics organic traffic; avoids burst detection |
| Max API calls per cron run | **40 calls** | 40 × 15 = 600 emails max per 15-min window |
| Cron schedule | **Every 15 minutes** | Distributes load; never continuous polling |
| Max concurrent sync processes | **1 (global lock)** | Prevents thundering herd from cron overlap |
| Backoff base on HTTP 429 | **Retry-After header (min 60 sec)** | Microsoft-mandated; use header value |
| Max consecutive backoff retries | **5** | Beyond 5, email flagged for manual review |
| Max backoff ceiling | **15 minutes** | Prevents indefinite exponential growth |

> **⚠ GUARDRAIL — Never Exceed the 40-Call Cap**
>
> The 40-call-per-run ceiling is a **hard limit, not a guideline**. If the budget is exhausted mid-sync, the engine MUST persist the checkpoint and terminate cleanly. The next cron run resumes from the saved checkpoint. Exceeding 40 calls risks triggering tenant-level throttling that affects ALL application users.

### 5.3 Exponential Backoff Algorithm

When the API returns HTTP 429, apply this formula:

```
delay = min(retry_after_ms × 2^attempt + rand(0, 1000), 900_000)

Where:
  retry_after_ms  = Retry-After header value in ms (minimum 60,000ms)
  attempt         = current retry index (0-based)
  rand(0, 1000)   = cryptographically random jitter in ms
  900,000 ms      = 15-minute maximum backoff ceiling
```

```php
// PHP implementation
$retryAfterSec = (int) $response->getHeader('Retry-After')[0] ?? 60;
$retryAfterMs  = max($retryAfterSec * 1000, 60000);
$jitter        = random_int(0, 1000);
$delayMs       = min($retryAfterMs * pow(2, $attempt) + $jitter, 900000);

usleep($delayMs * 1000); // usleep takes microseconds
```

> **⚠ GUARDRAIL — Never Use `rand()` or `mt_rand()` for Jitter**
>
> Use `random_int()` only. Predictable jitter defeats its purpose and can recreate synchronized retry storms.

### 5.4 Server Resource Limits

| Resource | Hard Limit | Enforcement |
|---|---|---|
| PHP memory per sync run | **128 MB** | Set via `ini_set` inside Artisan command |
| Wall-clock time per cron run | **12 minutes** | Internal timer check before each batch |
| DB connections during sync | **1 persistent** | Do not open additional connections per batch |
| Emails held in memory at once | **1 (current insert)** | Unset batch array immediately after processing |

> **⚠ GUARDRAIL — One Email in Memory at a Time**
>
> After fetching a batch of 10–15 from the API, loop through them and INSERT + unset one at a time. Never hold the full batch array in memory while inserting. This is the primary mechanism for keeping memory flat across large mailboxes.

---

## 6. Chunked Synchronization Architecture

### 6.1 Sync Lifecycle (State Machine)

```
┌─────────────────────────────────────────┐
│  Cron triggers every 15 minutes         │
└────────────────┬────────────────────────┘
                 ▼
┌────────────────────────────────────────────────────┐
│  Acquire global DB lock                            │
│  ── Lock exists & not stale?  ──► Exit cleanly     │
│  ── Lock stale (> 20 min)?    ──► Steal & continue │
└────────────────┬───────────────────────────────────┘
                 ▼
┌────────────────────────────────────────────────────┐
│  Load sync checkpoint                              │
│   • last_message_id  (resume position)             │
│   • last_sync_token  (Graph delta link)            │
│   • current_batch_index                            │
└────────────────┬───────────────────────────────────┘
                 ▼
┌────────────────────────────────────────────────────┐
│  OUTER LOOP: while api_calls_made < 40             │
│                                                    │
│   1. GET /messages/delta?$top=15&$deltaToken=...   │
│   2. On 429 ──► exponential backoff, retry (max 5) │
│   3. On success ──► INNER LOOP over each email:    │
│       a. Extract & map all fields                  │
│       b. Run PO extraction inline                  │
│       c. INSERT one row into `emails`              │
│       d. Log result to email_sync_batch_logs       │
│       e. unset($email); gc_collect_cycles()        │
│   4. Save checkpoint (last message_id + delta link)│
│   5. Check wall-clock: if > 12 min ──► break       │
│   6. sleep(random_int(3000, 7000) ms)              │
│   7. api_calls_made++                              │
│                                                    │
└────────────────┬───────────────────────────────────┘
                 ▼
┌────────────────────────────────────────────────────┐
│  Release global lock                               │
│  Write run summary to email_sync_jobs              │
└────────────────────────────────────────────────────┘
```

### 6.2 Incremental Sync (Delta Query)

The engine **MUST** use Microsoft Graph Delta Query to avoid re-fetching already-synced emails.

```
# First run (no delta token stored):
GET /v1.0/me/mailFolders/inbox/messages/delta
    ?$select=id,subject,from,toRecipients,bodyPreview,isRead,
             receivedDateTime,parentFolderId,hasAttachments
    &$top=15

# Subsequent runs (delta token stored in email_sync_jobs.last_sync_token):
GET /v1.0/me/mailFolders/inbox/messages/delta
    ?$deltaToken={last_sync_token}
    &$top=15
```

**Delta token persistence rules:**
- Store the `@odata.nextLink` token after every successful page response
- Store the `@odata.deltaLink` token when the final page (empty `value[]`) is reached
- Write both tokens to `email_sync_jobs` **inside the same DB transaction as the checkpoint update**
- If the sync is interrupted, the previous page token remains valid for resume — no emails are skipped

---

## 7. Individual Email Insertion Logic

> This section is the core behavioral specification. The sync engine inserts **one email at a time** — never a bulk multi-row INSERT.

### 7.1 Per-Email Processing Pipeline

For every email object returned in an API batch page, execute these steps in order:

```
For each $email in $apiResponse['value']:
  │
  ├─ 1. CHECK DUPLICATE
  │      SELECT COUNT(*) FROM emails WHERE message_id = :message_id
  │      If exists AND is_read status unchanged → SKIP (log as 'skipped')
  │      If exists AND is_read changed → UPDATE is_read only (log as 'updated')
  │
  ├─ 2. MAP FIELDS
  │      Map Graph API response fields → emails table columns
  │      (See Section 8 for full mapping table)
  │
  ├─ 3. RUN PO EXTRACTION (inline, before insert)
  │      $result = PoExtractor::extract($email['subject'], $email['bodyPreview'])
  │      Sets: extracted_po_number, po_extraction_method,
  │            po_extraction_confidence, po_extraction_attempted = 1
  │
  ├─ 4. INSERT (single row)
  │      INSERT INTO emails (...) VALUES (...)
  │      ON DUPLICATE KEY UPDATE
  │        is_read             = VALUES(is_read),
  │        po_extraction_attempted = VALUES(po_extraction_attempted),
  │        extracted_po_number = VALUES(extracted_po_number),
  │        updated_at          = NOW()
  │
  ├─ 5. LOG RESULT
  │      Write to email_sync_batch_logs (success/failed/skipped)
  │
  ├─ 6. FREE MEMORY
  │      unset($email);
  │      (gc_collect_cycles() every 50 emails)
  │
  └─ 7. UPDATE CHECKPOINT
         email_sync_jobs.last_message_id = $email['id']
```

### 7.2 Upsert Strategy

The `ON DUPLICATE KEY UPDATE` clause on `message_id` is the **sole mechanism** for preventing duplicates. It must:

- Update `is_read` to reflect the current read status from Graph (emails can be marked read externally)
- Update PO extraction fields if extraction was not previously attempted
- **Never overwrite** `matched_order_id` if it has already been set (a matched PO must not be unlinked by a sync)
- **Never update** `received_at` — the original timestamp is immutable

```sql
INSERT INTO `emails` (
    `mailbox_account_id`, `message_id`, `subject`, `from_email`, `from_name`,
    `to_recipients`, `body_preview`, `is_read`, `received_at`, `folder`,
    `extracted_po_number`, `po_extraction_method`, `po_extraction_confidence`,
    `matched_order_id`, `has_attachments`, `po_extraction_attempted`,
    `created_at`, `updated_at`
) VALUES (
    :mailbox_account_id, :message_id, :subject, :from_email, :from_name,
    :to_recipients, :body_preview, :is_read, :received_at, :folder,
    :extracted_po_number, :po_extraction_method, :po_extraction_confidence,
    NULL, :has_attachments, :po_extraction_attempted,
    NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
    `is_read`                   = VALUES(`is_read`),
    `has_attachments`           = VALUES(`has_attachments`),
    -- Only update PO fields if extraction has not yet been run
    `extracted_po_number`       = IF(`po_extraction_attempted` = 0, VALUES(`extracted_po_number`), `extracted_po_number`),
    `po_extraction_method`      = IF(`po_extraction_attempted` = 0, VALUES(`po_extraction_method`), `po_extraction_method`),
    `po_extraction_confidence`  = IF(`po_extraction_attempted` = 0, VALUES(`po_extraction_confidence`), `po_extraction_confidence`),
    `po_extraction_attempted`   = IF(`po_extraction_attempted` = 0, VALUES(`po_extraction_attempted`), `po_extraction_attempted`),
    -- matched_order_id is NEVER overwritten by sync
    `updated_at`                = NOW();
```

### 7.3 PO Extraction (Inline)

PO extraction runs **before** each INSERT so that `extracted_po_number` is populated on the first write. A second-pass scan is not required.

```php
// Extraction runs against subject + bodyPreview (not full body — keeps memory flat)
$extraction = PoExtractor::extract(
    subject:     $email['subject'] ?? '',
    bodyPreview: $email['bodyPreview'] ?? ''
);

// $extraction returns:
// [
//   'po_number'   => 'PO-2024-00123' | null,
//   'method'      => 'regex' | 'nlp' | null,
//   'confidence'  => 0.9500 | null,
//   'attempted'   => true,
// ]
```

**Extraction rules:**
- If no PO number is found: set `extracted_po_number = NULL`, `po_extraction_confidence = NULL`, `po_extraction_attempted = 1`
- If a PO number is found with confidence < 0.6: insert the value but flag for human review in the admin dashboard
- `po_extraction_attempted` is always set to `1` after running — even if extraction finds nothing
- `matched_order_id` is always `NULL` at insert time — order matching is a separate process triggered post-insert

---

## 8. Field Mapping — Graph API → `emails` Table

| `emails` Column | Graph API Source Field | Transformation |
|---|---|---|
| `id` | — | Auto-increment; not set by sync |
| `mailbox_account_id` | Context (from sync job config) | Direct assignment from sync job |
| `message_id` | `id` | Direct — Graph message ID string |
| `subject` | `subject` | `mb_substr($val, 0, 998)` — enforce column length |
| `from_email` | `from.emailAddress.address` | Lowercase; `mb_substr($val, 0, 320)` |
| `from_name` | `from.emailAddress.name` | `mb_substr($val, 0, 255)` |
| `to_recipients` | `toRecipients[]` | Map to JSON: `[{"email":"...","name":"..."}]` |
| `body_preview` | `bodyPreview` | Direct — Graph already limits to ~255 chars |
| `is_read` | `isRead` | Cast bool to `TINYINT`: `(int) $val` |
| `received_at` | `receivedDateTime` | Parse ISO 8601 → `Y-m-d H:i:s` UTC |
| `folder` | `parentFolderId` → resolved name | Resolve via folder name lookup or pass raw |
| `extracted_po_number` | — | Set by `PoExtractor::extract()` inline |
| `po_extraction_method` | — | Set by `PoExtractor::extract()` inline |
| `po_extraction_confidence` | — | Set by `PoExtractor::extract()` inline |
| `matched_order_id` | — | Always `NULL` at insert time |
| `has_attachments` | `hasAttachments` | Cast bool to `TINYINT`: `(int) $val` |
| `po_extraction_attempted` | — | Always `1` after `PoExtractor` runs |
| `created_at` | — | `NOW()` at insert time |
| `updated_at` | — | `NOW()` at insert time |

### 8.1 Field Transformation Rules

**`to_recipients` JSON structure:**
```json
[
  { "email": "buyer@company.com", "name": "John Buyer" },
  { "email": "cc@company.com",    "name": "CC Person"  }
]
```

**`received_at` parsing:**
```php
// Graph returns ISO 8601 UTC: "2024-06-15T09:23:11Z"
$receivedAt = Carbon::parse($email['receivedDateTime'])
    ->setTimezone('UTC')
    ->format('Y-m-d H:i:s');
```

**`subject` null safety:**
```php
// Some system-generated emails have no subject
$subject = isset($email['subject']) && $email['subject'] !== ''
    ? mb_substr($email['subject'], 0, 998)
    : null;
```

**`folder` resolution:**
```php
// Option A — use parentFolderId and resolve via a pre-loaded folder map
$folder = $this->folderMap[$email['parentFolderId']] ?? $email['parentFolderId'];

// Option B — pass $select=parentFolderDisplayName in Graph request (if available)
// Prefer Option A for v1.0 — folder map is fetched once per sync run and cached locally
```

---

## 9. Load Balancing & Rate Limiting Compliance

### 9.1 Response Header Monitoring

The Guzzle middleware must inspect **every** Graph API response for these headers:

| Header | Action |
|---|---|
| `Retry-After` | On HTTP 429: sleep for this many seconds + jitter, then retry |
| `X-RateLimit-Remaining` | If value < 10: insert a 2-second pause before next batch call |
| `X-RateLimit-Reset` | Log reset timestamp; use to estimate when to resume after throttle |

```php
// GuzzleMiddleware — fires on every response
public function handleResponse(ResponseInterface $response): ResponseInterface
{
    $remaining = (int) ($response->getHeader('X-RateLimit-Remaining')[0] ?? 999);

    if ($response->getStatusCode() === 429) {
        $retryAfter = (int) ($response->getHeader('Retry-After')[0] ?? 60);
        Log::channel('sync_throttle')->warning('Graph API 429', [
            'retry_after' => $retryAfter,
            'url'         => (string) $request->getUri(),
        ]);
        $this->backoff->sleep($retryAfter, $this->attempt);
        throw new RateLimitException($retryAfter); // Caller catches and retries
    }

    if ($remaining < 10) {
        Log::channel('sync_throttle')->info('Rate limit low — inserting 2s pause', [
            'remaining' => $remaining,
        ]);
        usleep(2_000_000 + random_int(0, 500_000));
    }

    return $response;
}
```

### 9.2 Cron Schedule Configuration

```php
// app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('email:sync-outlook')
             ->everyFifteenMinutes()
             ->withoutOverlapping(10)          // Laravel mutex, 10-min max lock
             ->runInBackground()
             ->appendOutputTo(storage_path('logs/email-sync.log'))
             ->onFailure(function () {
                 Log::channel('slack')->critical('email:sync-outlook command failed');
             });
}

// System crontab (single entry):
// * * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

> **⚠ GUARDRAIL — Off-Peak Initial Sync**
>
> For the **first full sync** of a large mailbox (> 5,000 emails), schedule the initial run to start between **02:00–06:00 local server time** to avoid overlapping with peak tenant usage. Set `next_run_at` in `email_sync_jobs` to enforce this delay.

---

## 10. Error Resilience & State Management

### 10.1 Retry Decision Matrix

| HTTP Code | Retry? | Max Retries | Delay Strategy | Action |
|---|---|---|---|---|
| `429` | YES | 5 | Retry-After + exponential jitter | Log throttle event; apply backoff |
| `503` | YES | 3 | 30s + jitter | Soft throttle; standard retry |
| `401` | YES | 1 | Immediate | Refresh OAuth token; retry once only |
| `403` | NO | 0 | — | Flag email as permanently failed; alert admin |
| `500` | YES | 3 | 10s + jitter | Server error; standard retry |
| Network timeout | YES | 3 | 5s + jitter | Retry same email; update checkpoint on success |
| Other 4xx | NO | 0 | — | Log and skip; do not retry client errors |

### 10.2 Failed Email Escalation

When a single email exhausts its retry budget (5 retries):

1. Write a row to `email_sync_failed_items` with the Graph `message_id` and last error
2. Set `email_sync_batch_logs.status = 'failed'` for that email
3. Continue to the next email — **do not halt the entire sync run**
4. The failed email will appear in the admin dashboard manual review queue

### 10.3 Global Sync Lock

```sql
-- Lock is stored in email_sync_jobs
-- Acquired at run start, released at run end (even on exception via finally{})
UPDATE email_sync_jobs
   SET locked_at  = NOW(),
       locked_by  = :process_id   -- e.g. gethostname() . ':' . getmypid()
 WHERE id         = :sync_job_id
   AND (locked_at IS NULL OR locked_at < DATE_SUB(NOW(), INTERVAL 20 MINUTE));

-- If 0 rows affected → lock is held by another process → exit cleanly
```

> **⚠ GUARDRAIL — Always Release the Lock**
>
> The lock release MUST run inside a `finally {}` block so it executes even if an uncaught exception terminates the sync. A stale lock left for > 20 minutes will be auto-stolen by the next cron run, but this should be treated as a bug, not an expected flow.

### 10.4 Dual-Destination Audit Logging

Every sync run and every Graph message MUST produce matching database and file-log telemetry. The database is the queryable audit source; the daily file is an operational fallback when database logging is unavailable.

- Create the run-level database record before emitting `sync_started`; use its ID as `sync_run_id` in every later event.
- Emit `sync_started`, `email_created`, `email_updated`, `email_skipped`, `email_deleted`, `email_failed`, `sync_completed`, and `sync_failed` to the dedicated `mailbox_sync` channel.
- Persist each email mutation and its `mailbox_sync_item_logs` row in the same per-email transaction.
- On a per-email exception, roll back only that email, retry it up to 5 times, record the exhausted failure, and continue with the next message.
- If database audit logging fails, still emit the corresponding file event.
- File context is limited to correlation IDs, outcome, reason, attempts, duration, and exception class. Never log access/refresh tokens, subjects, bodies, previews, recipients, or raw Graph payloads.
- Rotate `storage/logs/mailbox-sync-*.log` daily and retain 30 days.

> **Batch fetch versus individual import:** Graph responses may contain 10–15 messages, but persistence is strictly sequential. Each message completes or rolls back its own transaction before the next message begins.

---

## 11. Laravel Implementation Architecture

### 11.1 Artisan Command

```
php artisan email:sync-outlook [options]

Options:
  --mailbox-id=ID     Sync a specific mailbox account (default: all eligible)
  --dry-run           Log what would be inserted without writing to DB or calling API
  --force             Bypass the global lock (use only for manual recovery)
  --max-calls=N       Override per-run call cap (cannot exceed 40; default: 40)
  --folder=NAME       Sync a specific folder only (default: inbox)
```

### 11.2 Component Map

| Class | Responsibility |
|---|---|
| `SyncOutlookEmailsCommand` | Artisan entry point; orchestrates the full sync lifecycle |
| `OutlookGraphClient` | Guzzle-based HTTP client with rate-limit middleware wired in |
| `EmailSyncStateManager` | Reads/writes sync checkpoint to `email_sync_jobs` |
| `EmailInserter` | Executes the single-row upsert for each email |
| `PoExtractor` | Runs PO number extraction against subject + bodyPreview |
| `GraphFieldMapper` | Transforms Graph API response fields to `emails` column values |
| `RateLimitMiddleware` | Guzzle middleware; inspects headers, enforces throttle delays |
| `BackoffStrategy` | Encapsulates exponential backoff with jitter |
| `SyncLockManager` | Acquires and releases the global DB sync lock |
| `SyncProgressReporter` | Writes per-email telemetry to `email_sync_batch_logs` |
| `FailedItemEscalator` | Moves emails exceeding 5 retries to `email_sync_failed_items` |
| `FolderNameResolver` | Fetches folder map once per run; resolves `parentFolderId` to name |

### 11.3 PHP Resource Configuration

```php
// Inside SyncOutlookEmailsCommand::handle()
ini_set('memory_limit', '128M');
set_time_limit(0);  // Wall-clock budget managed internally, not by PHP timeout

DB::disableQueryLog();  // Prevent query log from accumulating during long sync
```

### 11.4 Memory Management Pattern

```php
foreach ($apiResponse['value'] as $emailData) {
    try {
        // Map fields
        $mapped = $this->mapper->map($emailData, $mailboxAccountId);

        // Extract PO inline
        $mapped = $this->poExtractor->enrich($mapped);

        // Insert one row
        $this->inserter->upsert($mapped);

        // Log success
        $this->reporter->logSuccess($syncJobId, $emailData['id']);

    } catch (Throwable $e) {
        $this->reporter->logFailure($syncJobId, $emailData['id'], $e);
        $this->handleEmailError($emailData, $e, $attempt);
    } finally {
        // CRITICAL: release memory before next iteration
        unset($emailData, $mapped);
    }

    // Periodic GC — every 50 emails
    if (++$this->emailCount % 50 === 0) {
        gc_collect_cycles();
    }
}
```

---

## 12. Database State Tables

### 12.1 `email_sync_jobs`

Primary sync state table — one row per active or historical sync operation per mailbox.

```sql
CREATE TABLE `email_sync_jobs` (
    `id`                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mailbox_account_id`    BIGINT UNSIGNED NOT NULL,
    `status`                ENUM('pending','running','paused','completed','failed') DEFAULT 'pending',
    `last_sync_token`       VARCHAR(2048) NULL,   -- Graph @odata.deltaLink token
    `last_message_id`       VARCHAR(512)  NULL,   -- Last successfully inserted Graph message ID
    `total_processed`       INT UNSIGNED DEFAULT 0,
    `total_failed`          INT UNSIGNED DEFAULT 0,
    `total_skipped`         INT UNSIGNED DEFAULT 0,
    `current_batch_index`   INT UNSIGNED DEFAULT 0,
    `api_calls_this_run`    TINYINT UNSIGNED DEFAULT 0,  -- Reset each cron run; cap at 40
    `locked_at`             TIMESTAMP NULL,
    `locked_by`             VARCHAR(128)  NULL,
    `next_run_at`           TIMESTAMP NULL,
    `completed_at`          TIMESTAMP NULL,
    `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_mailbox_status` (`mailbox_account_id`, `status`),
    INDEX `idx_next_run`       (`next_run_at`, `status`)
);
```

### 12.2 `mailbox_sync_item_logs`

Per-email audit log linked to the existing run-level `mailbox_sync_logs` record.

```sql
CREATE TABLE `mailbox_sync_item_logs` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mailbox_sync_log_id` BIGINT UNSIGNED NOT NULL,
    `message_id`    VARCHAR(512) NULL,
    `outcome`       VARCHAR(20) NOT NULL, -- created|updated|skipped|deleted|failed
    `reason`        VARCHAR(100) NULL,
    `attempts`      TINYINT UNSIGNED DEFAULT 1,
    `error_message` TEXT NULL,
    `duration_ms`   INT UNSIGNED DEFAULT 0,
    `processed_at`  TIMESTAMP NOT NULL,
    `created_at`    TIMESTAMP NULL,
    `updated_at`    TIMESTAMP NULL,
    INDEX `idx_sync_outcome` (`mailbox_sync_log_id`, `outcome`),
    INDEX `idx_message`   (`message_id`)
);
```

### 12.3 `email_sync_failed_items`

Escalation table for emails that have exhausted their 5-retry budget.

```sql
CREATE TABLE `email_sync_failed_items` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `sync_job_id`   BIGINT UNSIGNED NOT NULL,
    `message_id`    VARCHAR(512) NOT NULL,
    `last_error`    TEXT NULL,
    `raw_payload`   JSON NULL,   -- Store the Graph API response for manual replay
    `flagged_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `resolved_at`   TIMESTAMP NULL,
    `resolved_by`   VARCHAR(128) NULL
);
```

---

## 13. Admin Dashboard Requirements

The admin dashboard reads exclusively from **local database tables** — zero additional API calls.

### 13.1 Sync Overview Panel

- Total emails synced (all-time) across all mailboxes
- Per-mailbox sync status: Pending / Running / Paused / Completed / Failed
- Last successful sync timestamp and email count for that run
- Current progress: emails inserted this run / estimated total
- Next scheduled run time (`next_run_at`)

### 13.2 Throttle Health Indicators

- Count of HTTP 429 responses in the last 24 hours — **alert badge if > 10**
- Count of HTTP 403 responses in the last 24 hours — **critical alert if > 0**
- Average inter-batch delay (alert if consistently < 3,000 ms — indicates misconfiguration)
- Total cumulative backoff time in the last 24 hours

### 13.3 PO Extraction Dashboard

- Count of emails with `po_extraction_attempted = 0` (not yet processed)
- Count of emails with `extracted_po_number IS NOT NULL` (PO found)
- Count of emails where `po_extraction_confidence < 0.6` (low-confidence — needs review)
- Count of emails with `matched_order_id IS NOT NULL` (successfully matched to order)

### 13.4 Failed Items Review Queue

- List of all `email_sync_failed_items` with `resolved_at IS NULL`
- Columns: `message_id`, `last_error`, `flagged_at`, `raw_payload` (collapsible)
- Actions: **Manual Retry** (resets retry counter; re-queues the message_id for next sync run), **Dismiss** (marks as resolved without retry)
- Export to CSV

---

## 14. Validation & Testing Requirements

### 14.1 Unit Tests

- `BackoffStrategy`: verify delay formula stays within bounds across all 5 retry attempts
- `SyncLockManager`: verify acquisition, contention detection, and stale lock (> 20 min) steal behavior
- `EmailInserter`: verify upsert does not overwrite `matched_order_id` when already set
- `EmailInserter`: verify `po_extraction_attempted` is not downgraded from 1 to 0 on upsert
- `GraphFieldMapper`: verify `received_at` parses correctly across DST boundary dates
- `GraphFieldMapper`: verify `to_recipients` encodes to valid JSON with correct structure
- `RateLimitMiddleware`: mock 429 response; verify `Retry-After` header is read and delay applied

### 14.2 Integration Tests

- Mock Microsoft Graph API using `Http::fake()` or WireMock
- Assert that a mailbox with 35 emails is fully inserted across 3 API calls (15 + 15 + 5)
- Simulate interrupted sync at email 17 of 35; assert resume inserts emails 18–35 with no duplicates
- Simulate 3 consecutive 429 responses; assert backoff delay compounds correctly
- Simulate 6 consecutive failures on one email; assert it is written to `email_sync_failed_items` and sync continues to the next email
- Assert that re-running sync on fully-synced mailbox produces 0 new inserts (all skipped via upsert)

### 14.3 Load & Anti-Abuse Testing

- Run sync against a test tenant with 5,000 emails; assert zero HTTP 403 responses
- Confirm HTTP 429 responses during steady-state operation: **< 2 per hour**
- Monitor PHP process RSS memory across a 5,000-email run: **must remain below 128 MB**
- Confirm CPU utilization does not exceed **30% average** during active sync
- Interrupt the process mid-run with `kill -9`; confirm next cron run resumes correctly from checkpoint

### 14.4 Acceptance Criteria

| Criterion | Target | Test Method |
|---|---|---|
| HTTP 403 responses (steady-state) | 0 | Response code monitoring |
| HTTP 429 responses (steady-state) | < 2 / hour | Response code monitoring |
| Duplicate emails after interrupted sync + resume | 0 | DB row count comparison |
| `matched_order_id` overwritten by sync on upsert | Never | Unit test + DB assertion |
| PHP memory consumption (5,000-email run) | < 128 MB RSS | Memory profiling |
| CPU utilization during active sync | < 30% avg | Server monitoring |
| Sync resume accuracy (correct message_id start point) | 100% | State inspection post-interrupt |
| `po_extraction_attempted` set on every inserted row | 100% | DB column audit |
| Admin dashboard data staleness | < 1 minute | UI refresh validation |

---

## 15. Risk Register

| Risk | Impact | Likelihood | Mitigation |
|---|---|---|---|
| Microsoft API policy change (rate limits tightened) | HIGH | LOW | Parameterize all limits via `config/email_sync.php`; monitor MS Graph changelog |
| OAuth token expiry mid-sync (401 cascade) | MEDIUM | MEDIUM | Proactive token refresh 5 min before expiry; retry once on 401 |
| DB lock left stale after server crash | MEDIUM | LOW | Auto-steal locks older than 20 min; manual force-release via admin UI |
| Large mailbox (50k+ emails) causes initial sync to span many days | MEDIUM | MEDIUM | Show estimated completion time in dashboard; allow admin to trigger off-peak run |
| PO extraction regex causes excessive CPU on long email bodies | LOW | MEDIUM | Extraction runs against `bodyPreview` only (255 chars max) — not the full body |
| `email_sync_failed_items` grows unbounded | LOW | LOW | Alert when count > 100; auto-archive resolved items older than 90 days |

---

## Appendix A — Engineer Guardrail Checklist

> Complete before every pull request that touches the sync module.

| ☐ | Guardrail |
|---|---|
| ☐ | Batch size is between 10 and 15 — never a hard-coded larger value |
| ☐ | Inter-batch sleep uses `random_int(3000, 7000)` ms — not a fixed constant |
| ☐ | Max API calls per run is checked before every batch call (cap: 40) |
| ☐ | Global DB lock is acquired in a transaction before sync starts |
| ☐ | Global DB lock is released in a `finally {}` block — runs even on exception |
| ☐ | Delta token is saved to DB **inside the same transaction** as the checkpoint |
| ☐ | 429 backoff uses `Retry-After` header value — not a hardcoded sleep |
| ☐ | Jitter uses `random_int()` — not `rand()` or `mt_rand()` |
| ☐ | Each email is inserted with a **single-row INSERT ... ON DUPLICATE KEY UPDATE** |
| ☐ | Every email has one database outcome and one matching `mailbox_sync` file event sharing `sync_run_id` |
| ☐ | Per-email transactions roll back independently; an exhausted failure does not stop later messages |
| ☐ | File logs contain no tokens, subjects, bodies, previews, recipients, or raw Graph payloads |
| ☐ | `matched_order_id` is `NULL` in all VALUES() — never overwritten by sync |
| ☐ | `po_extraction_attempted = 1` is set on every INSERT (extraction ran inline) |
| ☐ | `unset($emailData)` is called after every email in the processing loop |
| ☐ | `gc_collect_cycles()` is called every 50 emails |
| ☐ | Wall-clock budget check runs before each batch (terminate at 12 minutes) |
| ☐ | PHP memory limit is set to `128M` at command startup via `ini_set` |
| ☐ | All throttle events (429, X-RateLimit-Remaining < 10) are written to `sync_throttle` log |
| ☐ | Admin dashboard reads zero API calls — local DB only |
| ☐ | No sync logic exists in web request handlers — Artisan command only |

---

## Appendix B — Full INSERT Reference

The complete INSERT statement the sync engine executes for each individual email:

```sql
INSERT INTO `emails` (
    `mailbox_account_id`,
    `message_id`,
    `subject`,
    `from_email`,
    `from_name`,
    `to_recipients`,
    `body_preview`,
    `is_read`,
    `received_at`,
    `folder`,
    `extracted_po_number`,
    `po_extraction_method`,
    `po_extraction_confidence`,
    `matched_order_id`,
    `has_attachments`,
    `po_extraction_attempted`,
    `created_at`,
    `updated_at`
)
VALUES (
    :mailbox_account_id,        -- INT:    FK to mailbox_accounts
    :message_id,                -- STRING: Graph API message ID (unique)
    :subject,                   -- STRING: mb_substr($val, 0, 998) | NULL
    :from_email,                -- STRING: lowercase sender address | NULL
    :from_name,                 -- STRING: sender display name | NULL
    :to_recipients,             -- JSON:   [{"email":"...","name":"..."}] | NULL
    :body_preview,              -- STRING: Graph bodyPreview (~255 chars) | NULL
    :is_read,                   -- INT:    0 or 1
    :received_at,               -- TIMESTAMP: UTC, parsed from ISO 8601
    :folder,                    -- STRING: resolved folder name | NULL
    :extracted_po_number,       -- STRING: PO number from PoExtractor | NULL
    :po_extraction_method,      -- STRING: 'regex'|'nlp'|NULL
    :po_extraction_confidence,  -- DECIMAL(5,4): 0.0000–1.0000 | NULL
    NULL,                       -- matched_order_id: ALWAYS NULL at insert
    :has_attachments,           -- INT:    0 or 1
    1,                          -- po_extraction_attempted: ALWAYS 1 (ran inline)
    NOW(),                      -- created_at
    NOW()                       -- updated_at
)
ON DUPLICATE KEY UPDATE
    `is_read`                   = VALUES(`is_read`),
    `has_attachments`           = VALUES(`has_attachments`),
    `extracted_po_number`       = IF(`po_extraction_attempted` = 0,
                                     VALUES(`extracted_po_number`),
                                     `extracted_po_number`),
    `po_extraction_method`      = IF(`po_extraction_attempted` = 0,
                                     VALUES(`po_extraction_method`),
                                     `po_extraction_method`),
    `po_extraction_confidence`  = IF(`po_extraction_attempted` = 0,
                                     VALUES(`po_extraction_confidence`),
                                     `po_extraction_confidence`),
    `po_extraction_attempted`   = IF(`po_extraction_attempted` = 0,
                                     1,
                                     `po_extraction_attempted`),
    -- matched_order_id is INTENTIONALLY excluded — never overwritten by sync
    `updated_at`                = NOW();
```

---

*End of Document — Outlook Email Sync Module PRD v1.1.0*
*Classification: Confidential — Internal Use Only*
