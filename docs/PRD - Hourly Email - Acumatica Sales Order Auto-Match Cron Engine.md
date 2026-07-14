# PRD: Hourly Email ↔ Acumatica Sales Order Auto-Match Cron Engine

## 1. Feature Name

**Hourly Email ↔ Acumatica Sales Order Auto-Match Cron Engine**

---

## 2. Product

**Kim-Fay OrderWatch**
Internal order monitoring, email ingestion, Acumatica reconciliation, and revenue protection dashboard.

---

## 3. Objective

Build a scheduled background automation inside OrderWatch that runs **every 1 hour** to:

1. check configured Outlook mailbox folders for new or updated order-related emails
2. check Acumatica for new or updated **Sales Orders**
3. automatically attempt to match emails to Acumatica Sales Orders using AI + deterministic matching logic
4. store match results, discrepancies, and audit logs
5. expose cron job configuration, execution history, and status inside an **Admin → Cron Jobs** tab

The system should reduce manual reconciliation effort by continuously scanning for new emails and Sales Orders, then automatically linking them where possible.

---

## 4. Problem Statement

OrderWatch currently relies on manual review or one-off sync operations to compare emails and Sales Orders. This creates delays in identifying unmatched orders, missed captures, and discrepancies between customer emails and Acumatica Sales Orders.

The business needs an automated background process that regularly:

* pulls new order emails from Outlook
* pulls new or updated Sales Orders from Acumatica
* tries to match them automatically
* flags discrepancies or unmatched items
* logs every run and exposes the cron configuration in Admin

Without this, teams may miss:

* newly arrived customer POs in email
* Sales Orders created in Acumatica after email arrival
* emails that should have matched but were not reviewed in time
* discrepancies between what was emailed and what was captured

---

## 5. Goals

## Primary Goals

* Run an **hourly cron job** that checks both email and Acumatica Sales Orders
* Automatically match email records to Acumatica Sales Orders using AI + rules
* Save all matches, unmatched records, and discrepancies
* Provide an Admin UI to manage the cron job configuration and URL
* Maintain full logs for each cron run and each matching decision

## Secondary Goals

* Reduce manual PO/email/order reconciliation work
* speed up visibility of unmatched or risky orders
* provide a repeatable operational sync pipeline
* create a foundation for more advanced automation such as escalations and SLA alerts

---

## 6. Non-Goals

This phase will not:

* automatically update Acumatica Sales Orders
* auto-approve all AI matches without review
* create Outlook rules automatically
* process every mailbox in real time
* replace manual exception review workflows entirely
* include OCR if document parsing is handled elsewhere

---

## 7. Users / Roles

Primary users:

* Administrator
* Customer Service Manager
* Sales Operations

Secondary users:

* Customer Service Agent
* Executive (view-only monitoring)

---

# 8. High-Level Feature Summary

The feature introduces a **scheduled OrderWatch cron engine** that runs every 1 hour.

Each run should:

1. load active cron configuration
2. pull new or updated emails from configured mailbox folders
3. pull new or updated Acumatica Sales Orders
4. normalize and stage both datasets
5. attempt to match emails to Sales Orders
6. classify results as matched, matched with discrepancies, needs review, or not matched
7. save logs and execution metrics
8. show run status in Admin

---

# 9. Core Functional Requirements

## 9.1 Hourly Cron Schedule

OrderWatch must support a cron-driven automation that runs **every 1 hour**.

### Default schedule

* **Every 1 hour**

### Examples

* `0 * * * *`
* or equivalent scheduler configuration in Laravel

The schedule must be configurable later if needed, but the first release should support a standard hourly run.

---

## 9.2 Cron Scope

Each cron execution must check **both**:

### A. Outlook Email Data

The cron job should pull new or updated emails from:

* Inbox if enabled
* configured PO/customer folders
* trusted folders such as Naivas POs, Carrefour POs, etc.
* only folders enabled for sync in OrderWatch

### B. Acumatica Sales Orders

The cron job should pull:

* new Sales Orders since last successful sync
* updated Sales Orders since last successful sync
* optionally recent Sales Orders within a lookback window to catch delayed matches

---

## 9.3 Matching Objective

The cron job must try to automatically match **email records** with **Acumatica Sales Orders**.

The match should determine whether an incoming email or PO-related message corresponds to an Acumatica Sales Order already in the system or newly synced during the same run.

---

# 10. Matching Inputs

## 10.1 Email-side Data

The system should use, where available:

* email message id
* internet message id
* mailbox id
* folder id / folder name
* sender name / sender email
* subject
* email body
* attachment filenames
* parsed attachment content
* detected PO number
* customer / account inference
* received date
* thread metadata

## 10.2 Acumatica Sales Order Data

The system should use, where available:

* Sales Order number
* customer name / account code
* customer PO number
* order date
* requested delivery date
* order total
* line items
* quantities
* item descriptions
* branch / warehouse
* status
* last modified timestamp

---

# 11. Matching Logic Requirements

## 11.1 Primary Matching Logic

The auto-match engine should first try deterministic matching using high-confidence identifiers such as:

* Acumatica **Customer PO Number**
* Acumatica **Sales Order reference fields** where mapped
* PO number detected in:

  * email subject
  * email body
  * attachment filename
  * attachment content
* customer/account mapping from folder or sender

## 11.2 Secondary AI Matching Logic

If a deterministic match is not enough, the system should use AI-assisted matching to compare:

* customer identity
* order references
* item lines
* quantities
* delivery dates
* email context
* folder/rule context
* order timing proximity

## 11.3 Matching Result Categories

Each attempted match should be classified as:

* **Matched**
* **Matched with Discrepancies**
* **Possible Match / Needs Review**
* **Not Matched**
* **No Sales Order Found**
* **No Email Found**

---

# 12. Cron Run Workflow

## 12.1 End-to-End Cron Flow

For each hourly run:

### Step 1: Load Cron Configuration

* load active cron job record
* confirm job is enabled
* load mailbox sync settings
* load enabled mailbox folders
* load Acumatica sync settings
* load lookback window and matching settings

### Step 2: Pull Email Updates

* fetch new/updated emails since last successful email sync checkpoint
* save raw email metadata
* parse PO references and key fields
* mark emails for matching

### Step 3: Pull Acumatica Sales Order Updates

* fetch new/updated Sales Orders since last successful Acumatica sync checkpoint
* normalize order data
* store or refresh staging records

### Step 4: Build Candidate Match Set

* identify emails and Sales Orders eligible for matching
* prioritize exact PO/reference matches
* group by customer/folder/date proximity where relevant

### Step 5: Run Matching Engine

* run deterministic rules first
* run AI comparison where needed
* calculate confidence score
* store comparison details and result status

### Step 6: Save Results

* save match records
* save discrepancy details
* save unmatched items
* update email / Sales Order linkages if applicable

### Step 7: Save Cron Run Logs

* save execution status
* save counts and timings
* save any failures or partial failures

---

# 13. Admin UI Requirement: Cron Jobs Tab

## 13.1 New Admin Tab

Add a new tab in Admin called:

# **Cron Jobs**

This page must allow administrators to:

* view configured cron jobs
* see cron URL / command reference
* enable or disable cron jobs
* view run frequency
* view last run / next expected run
* test run manually
* see run history and failures

---

## 13.2 Cron Job Record Fields

Each cron job configuration should include:

* Job Name
* Job Key / Slug
* Description
* Is Enabled
* Frequency
* Cron Expression
* Trigger Type (`scheduler`, `URL`, `queue`, `manual`)
* Cron URL / Endpoint
* Last Run At
* Last Success At
* Last Failure At
* Last Run Status
* Last Duration
* Next Expected Run
* Notes

---

## 13.3 Cron URL Requirement

The Admin Cron Jobs tab must expose the **Cron Job Application URL** used to trigger the hourly process where relevant.

Example fields:

* **Cron URL**
* **Copy URL**
* **Regenerate secure token** (if using signed endpoint)
* **Last called at**
* **Allowed trigger type**

Example usage:

* cPanel cron calls a URL
* external cron service calls a secure endpoint
* internal scheduler references the job config

Example display:

| Job Name                       | Frequency | Cron URL                                       | Status |
| ------------------------------ | --------- | ---------------------------------------------- | ------ |
| Email ↔ Sales Order Auto Match | Hourly    | `/cron/email-sales-order-auto-match?token=...` | Active |

---

# 14. Admin UI Sections for Cron Jobs

## 14.1 Cron Job List

Display:

* Job Name
* Frequency
* Enabled
* Last Run
* Last Status
* Duration
* Trigger Type
* Actions

Actions:

* View
* Edit
* Run Now
* Enable/Disable
* View Logs

## 14.2 Cron Job Detail View

Display:

* job metadata
* cron URL
* cron expression
* linked mailbox configuration
* linked Acumatica configuration
* matching settings
* last 20 runs
* recent failures
* result counts

## 14.3 Run History

Display:

* Run Started At
* Run Ended At
* Duration
* Emails Checked
* Sales Orders Checked
* Matches Created
* Needs Review Count
* Unmatched Count
* Failures
* Triggered By

---

# 15. Cron Job Configuration Options

The cron job must support the following configurable options:

## Email Sync Settings

* mailbox to use
* folders to include
* maximum emails per run
* email lookback window
* whether to include already-reviewed unmatched emails

## Acumatica Sync Settings

* company / tenant config
* Sales Order sync lookback window
* maximum Sales Orders per run
* include updated orders only vs full recent window

## Matching Settings

* deterministic match enabled
* AI matching enabled
* confidence threshold
* auto-flag discrepancies
* auto-mark matched if confidence exceeds threshold
* require manual review below threshold

---

# 16. Logging Requirements

## 16.1 Cron Run Log

Each cron execution must save:

* cron_run_id
* cron_job_id
* job_name
* triggered_at
* completed_at
* duration_ms
* status (`success`, `failed`, `partial`, `running`)
* trigger_source (`scheduler`, `url`, `manual`, `queue`)
* emails_checked
* emails_processed
* sales_orders_checked
* sales_orders_processed
* matches_created
* matched_with_discrepancies_count
* needs_review_count
* unmatched_count
* skipped_count
* error_count
* notes / error summary

---

## 16.2 Match-Level Logs

For every email ↔ Sales Order match attempt, save:

* cron_run_id
* mailbox_id
* email_message_id
* sales_order_id / sales_order_number
* customer_id / customer name
* detected_po_number
* match_status
* confidence_score
* deterministic_match_used yes/no
* ai_match_used yes/no
* discrepancy_summary
* created_at

---

## 16.3 Failure Logs

If the cron run fails or partially fails, log:

* API failure source
* mailbox sync failure
* Acumatica sync failure
* AI matching failure
* timeout / rate limit errors
* malformed email / malformed order data
* token/authentication failures

---

# 17. Suggested Database Tables

## `cron_jobs`

Stores cron configuration.

Fields:

* id
* job_key
* name
* description
* is_enabled
* frequency_label
* cron_expression
* trigger_type
* cron_url
* secure_token nullable
* last_run_at
* last_success_at
* last_failure_at
* last_status
* last_duration_ms
* next_expected_run_at
* settings_json
* created_at
* updated_at

---

## `cron_job_runs`

Stores every cron execution.

Fields:

* id
* cron_job_id
* status
* trigger_source
* started_at
* completed_at
* duration_ms
* emails_checked
* emails_processed
* sales_orders_checked
* sales_orders_processed
* matches_created
* matched_with_discrepancies_count
* needs_review_count
* unmatched_count
* skipped_count
* error_count
* error_summary
* metadata_json
* created_at
* updated_at

---

## `email_sales_order_matches`

Stores match results created by the cron engine.

Fields:

* id
* cron_run_id
* mailbox_id
* email_id
* sales_order_id
* sales_order_number
* customer_id
* detected_po_number
* match_status
* confidence_score
* deterministic_match_used
* ai_match_used
* discrepancy_summary
* match_context_json
* reviewed_at nullable
* reviewed_by nullable
* created_at
* updated_at

---

# 18. Backend Services

## `HourlyAutoMatchCronService`

Responsible for:

* running the full hourly job
* coordinating email sync + Acumatica sync + matching
* creating cron run logs

## `CronJobExecutionService`

Responsible for:

* loading cron job configuration
* checking if job is enabled
* creating run records
* handling success/failure state

## `AcumaticaSalesOrderSyncService`

Responsible for:

* fetching new/updated Sales Orders from Acumatica
* normalizing order data
* storing staging / synced order data

## `MailboxEmailSyncService`

Responsible for:

* fetching emails from enabled folders
* parsing metadata and PO references
* storing staged email records

## `EmailSalesOrderMatchingService`

Responsible for:

* building candidate matches
* running deterministic matching
* running AI matching
* storing match results and discrepancies

---

# 19. API / Admin Requirements

Suggested endpoints:

## Cron Jobs

* `GET /api/admin/cron-jobs`
* `GET /api/admin/cron-jobs/{id}`
* `PATCH /api/admin/cron-jobs/{id}`
* `POST /api/admin/cron-jobs/{id}/run`
* `GET /api/admin/cron-jobs/{id}/runs`

## Match Results

* `GET /api/email-sales-order-matches`
* `GET /api/email-sales-order-matches/{id}`

---

# 20. Security Requirements

If the cron job is triggered via URL, the system must support a secure trigger mechanism such as:

* signed token
* secret query parameter
* IP restriction if needed
* authenticated internal job call where applicable

The cron URL should not be a public unauthenticated endpoint.

---

# 21. Example Hourly Workflow

### 08:00 AM Cron Run

1. Cron job starts
2. Reads mailbox folders: Inbox, Naivas POs, Carrefour POs
3. Pulls 24 new emails since last run
4. Pulls 11 new/updated Acumatica Sales Orders
5. Finds PO number matches for 8 records
6. AI compares remaining ambiguous records
7. Produces:

   * 10 matched
   * 3 matched with discrepancies
   * 5 needs review
   * 6 unmatched
8. Saves run log and match logs
9. Updates Admin Cron Jobs run history

---

# 22. Acceptance Criteria

The feature is complete when:

* an hourly cron job can be configured and enabled
* the cron job can check both emails and Acumatica Sales Orders
* the cron job can auto-match emails to Sales Orders
* the system saves match results and discrepancies
* the Admin panel contains a **Cron Jobs** tab
* admins can view the cron URL / cron configuration
* admins can run the job manually
* each cron run is fully logged
* failures are visible in run history
* matched / unmatched / review counts are stored and visible

---

# 23. Final Requirement Summary

OrderWatch must include a **Hourly Email ↔ Acumatica Sales Order Auto-Match Cron Engine** that runs every 1 hour, checks both Outlook emails and Acumatica Sales Orders, attempts to auto-match them using deterministic and AI-assisted logic, stores results and logs, and exposes full cron configuration and run history inside an **Admin → Cron Jobs** tab, including the cron URL used by the cron job application.
