# Email Filter Groups — Implementation Guide
### Laravel + React · Kim-Fay East Africa SO/PO Import

**Version:** 1.0  
**Date:** 2026-06-25  
**Stack:** Laravel 10+, React 18, Inertia.js (or API mode), MySQL

---

## Overview

This guide walks you through implementing the Email Filter Groups feature end-to-end. You will:

1. Create the database tables
2. Build the Laravel models, migrations, and API routes
3. Wire the React component to the real API
4. Connect the email import job to the filter tables
5. Handle the three strategies: domain wildcard, email registry, hybrid

No Outlook rules required. All filtering logic lives in your database and Laravel backend.

---

## Prerequisites

Before starting, confirm:

- [ ] Laravel project is running locally
- [ ] React is set up (Inertia.js or standalone SPA)
- [ ] MySQL database is connected and `.env` is configured
- [ ] Microsoft Graph API credentials are available (for email fetching)
- [ ] `EmailFilterManager.jsx` component is in your project

---

## Step 1 — Database Migrations

Create three migrations in this order.

### 1.1 Customers table

```
php artisan make:migration create_customers_table
```

Add these columns:

```
id                  bigIncrements
customer_code       string(50), unique
customer_name       string(255)
import_strategy     enum: domain | registry | hybrid   default: registry
active              boolean, default: true
notes               text, nullable
timestamps
```

### 1.2 Customer domains table

```
php artisan make:migration create_customer_domains_table
```

Add these columns:

```
id              bigIncrements
customer_id     foreignId → customers.id, cascadeOnDelete
domain          string(255)          e.g.  quickmart.co.ke  (no @ prefix)
active          boolean, default: true
notes           string(500), nullable
timestamps

Index:  domain column
```

### 1.3 Customer branch emails table

```
php artisan make:migration create_customer_branch_emails_table
```

Add these columns:

```
id              bigIncrements
customer_id     foreignId → customers.id, cascadeOnDelete
branch_name     string(255)          e.g.  Chandarana Karen
email_address   string(255), unique
active          boolean, default: true
verified        boolean, default: false
first_seen_at   timestamp, nullable
notes           string(500), nullable
timestamps

Index:  customer_id column
Index:  email_address column
```

### 1.4 Email import log table

```
php artisan make:migration create_email_import_logs_table
```

Add these columns:

```
id                  bigIncrements
email_message_id    string(500), unique       Mail server message ID
sender_address      string(255), nullable
sender_domain       string(255), nullable
subject             string(1000), nullable
received_at         timestamp, nullable
customer_id         foreignId → customers.id, nullable, nullOnDelete
match_method        enum: domain | registry | unmatched
raw_po_extracted    string(255), nullable
canonical_po_key    string(100), nullable
so_number           string(50), nullable
import_status       enum: matched | unmatched | ambiguous | invalid_po | duplicate
flagged             boolean, default: false
flag_reason         string(500), nullable
timestamps

Index:  sender_address
Index:  sender_domain
Index:  import_status
Index:  canonical_po_key
```

### 1.5 Run migrations

```
php artisan migrate
```

---

## Step 2 — Eloquent Models

Create four models.

### 2.1 Customer model

```
php artisan make:model Customer
```

Configure:

- `$fillable`: customer_code, customer_name, import_strategy, active, notes
- `$casts`: active → boolean
- Relationships:
  - `hasMany(CustomerDomain::class)`
  - `hasMany(CustomerBranchEmail::class)`
  - `hasMany(EmailImportLog::class)`
- Scope `scopeActive`: where active = true

### 2.2 CustomerDomain model

```
php artisan make:model CustomerDomain
```

Configure:

- `$fillable`: customer_id, domain, active, notes
- `$casts`: active → boolean
- Relationship: `belongsTo(Customer::class)`

### 2.3 CustomerBranchEmail model

```
php artisan make:model CustomerBranchEmail
```

Configure:

- `$fillable`: customer_id, branch_name, email_address, active, verified, first_seen_at, notes
- `$casts`: active → boolean, verified → boolean, first_seen_at → datetime
- Relationship: `belongsTo(Customer::class)`

### 2.4 EmailImportLog model

```
php artisan make:model EmailImportLog
```

Configure:

- `$fillable`: all columns listed in Step 1.4
- `$casts`: received_at → datetime, flagged → boolean
- Relationship: `belongsTo(Customer::class)`

---

## Step 3 — API Routes

Add to `routes/api.php`:

```
Route group:  prefix = api/email-filters,  middleware = auth:sanctum

GET    /customers                      → CustomerGroupController@index
POST   /customers                      → CustomerGroupController@store
PUT    /customers/{id}                 → CustomerGroupController@update
DELETE /customers/{id}                 → CustomerGroupController@destroy

POST   /customers/{id}/domains         → CustomerGroupController@addDomain
DELETE /customers/{id}/domains/{domainId} → CustomerGroupController@removeDomain

POST   /customers/{id}/emails          → CustomerGroupController@addEmail
PUT    /customers/{id}/emails/{emailId}   → CustomerGroupController@updateEmail
DELETE /customers/{id}/emails/{emailId}   → CustomerGroupController@removeEmail

GET    /import-log                     → EmailImportLogController@index
GET    /import-log/unmatched           → EmailImportLogController@unmatched
POST   /import-log/{id}/assign         → EmailImportLogController@assignToCustomer
```

---

## Step 4 — Controllers

### 4.1 CustomerGroupController

```
php artisan make:controller Api/CustomerGroupController
```

**index method:**
- Load all customers with `domains` and `emails` relationships
- Return as JSON collection
- Support optional `?strategy=` and `?search=` query filters

**store method:**
- Validate: customer_code (unique), customer_name, import_strategy
- Create customer record
- Return the new customer with relationships

**update method:**
- Validate same fields
- Update customer record
- If strategy changes from `registry` to `domain`, do NOT delete existing emails — keep for hybrid fallback
- Return updated customer

**destroy method:**
- Soft check: warn if customer has matched import logs in the last 30 days
- Delete customer (cascades to domains and emails via foreign key)

**addDomain method:**
- Validate domain format: must not contain `@`, must contain `.`
- Strip leading `@` if user accidentally includes it
- Check for duplicate domain across all customers — warn but allow
- Create `CustomerDomain` record
- Return updated domains list

**removeDomain method:**
- Delete the domain record
- Return updated domains list

**addEmail method:**
- Validate: branch_name required, email_address must be valid email format
- Lowercase the email address before saving
- Check for duplicate across all customers — if found, return 422 with message "This address is already registered under {other customer name}"
- Set `first_seen_at` = now if not provided
- Create `CustomerBranchEmail` record
- Return updated emails list

**updateEmail method:**
- Allow updating: branch_name, active, verified, notes
- Do NOT allow changing email_address after creation (treat as immutable — create a new entry instead)
- Return updated email entry

**removeEmail method:**
- Delete the email record
- Return success

### 4.2 EmailImportLogController

```
php artisan make:controller Api/EmailImportLogController
```

**index method:**
- Paginate import logs (25 per page)
- Support filters: `?status=`, `?customer_id=`, `?date_from=`, `?date_to=`, `?flagged=1`
- Eager load `customer` relationship
- Return paginated JSON

**unmatched method:**
- Return only logs where `import_status = unmatched` or `customer_id IS NULL`
- Ordered by received_at descending
- Include `sender_address`, `subject`, `received_at` for display

**assignToCustomer method:**
- Accept: `customer_id`, `branch_name` (optional)
- Find the log by ID
- Set `customer_id` on the log
- If `branch_name` is provided, create a new `CustomerBranchEmail` entry with the log's `sender_address`
- Re-run PO extraction on the log's subject against the assigned customer's strategy
- Update `canonical_po_key`, `import_status`, and `flagged` fields
- Return updated log

---

## Step 5 — Email Import Job

### 5.1 Create the job

```
php artisan make:job ImportEmailsJob
```

### 5.2 Job logic — high level

The job runs on a schedule (every 15–30 minutes via Laravel scheduler). It:

1. Connects to your Microsoft 365 mailbox via Graph API
2. Fetches emails received since the last successful run (store the cursor timestamp)
3. For each email, runs the filter and matching pipeline
4. Writes to `email_import_logs`

### 5.3 Identify customer from sender

For each incoming email with `sender_address`:

```
Step A — Exact email match
  Query customer_branch_emails where email_address = sender_address AND active = true
  If found → customer identified, match_method = 'registry'

Step B — Domain match (if Step A found nothing)
  Extract domain from sender_address (everything after @)
  Query customer_domains where domain = extracted_domain AND active = true
  If found → customer identified, match_method = 'domain'

Step C — No match
  customer_id = null, match_method = 'unmatched', flagged = true
  flag_reason = 'Sender not in any customer registry or domain list'
```

### 5.4 Extract PO from subject

After customer is identified:

```
If customer strategy = 'domain' and customer_code starts with 'CARREFOUR'
  → Use Carrefour extractor (C4 branch token logic)

If customer strategy = 'domain' and customer_code starts with 'NAIVAS'
  → Use general extractor

Otherwise
  → Use general extractor (strip P/PO prefix, extract digits, strip leading zeros)

Store raw_po_extracted and canonical_po_key
If canonical_po_key is null → flagged = true, flag_reason = 'No PO number found in subject'
```

See `po_so_matching_requirements.md` and `carrefour_po_matching.md` for full extractor specs.

### 5.5 Match to SO in Acumatica

```
If canonical_po_key is not null
  → Query Acumatica SalesOrder endpoint
     filter: CustomerID = customer.customer_code, normalised CustomerRefNbr = canonical_po_key
  
  If 1 match found  → so_number = match, import_status = 'matched'
  If 2+ matches     → import_status = 'ambiguous', flagged = true
  If 0 matches      → import_status = 'unmatched', flagged = true
```

### 5.6 Guardrails to implement inside the job

```
G1  Deduplicate by email_message_id before any processing
G2  Skip emails where subject length < 5 characters
G3  Skip auto-replies: check subject for 'out of office', 'auto-reply', 'automatic reply',
    'delivery status', 'undeliverable', 'noreply', 'do not reply'
G4  Skip sender addresses in a blocked list (store in config/email_import.php)
G5  Cap batch size at 200 emails per run to avoid timeout
G6  Log every email regardless of match result — never silently discard
G7  If Graph API returns 429 (throttled), back off 60 seconds and retry once
G8  Store last successful fetch cursor in cache or a settings table
```

### 5.7 Schedule the job

In `app/Console/Kernel.php`:

```
$schedule->job(new ImportEmailsJob)->everyFifteenMinutes();
```

---

## Step 6 — Wire React Component to API

Open `EmailFilterManager.jsx` and make these changes:

### 6.1 Replace mock data with API fetch

```
On component mount:
  GET /api/email-filters/customers
  Set customers state with response data
  Handle loading and error states
```

### 6.2 Replace local state updates with API calls

For each user action in the component:

| Action | API call |
|---|---|
| Create customer group | POST /api/email-filters/customers |
| Update strategy or toggle active | PUT /api/email-filters/customers/{id} |
| Delete customer | DELETE /api/email-filters/customers/{id} |
| Add domain | POST /api/email-filters/customers/{id}/domains |
| Remove domain | DELETE /api/email-filters/customers/{id}/domains/{domainId} |
| Add branch email | POST /api/email-filters/customers/{id}/emails |
| Verify / unverify email | PUT /api/email-filters/customers/{id}/emails/{emailId} |
| Remove branch email | DELETE /api/email-filters/customers/{id}/emails/{emailId} |

### 6.3 Error handling in the component

```
Duplicate email address → show inline error under the address input field
                          Message: "Already registered under {customerName}"

API failure             → show toast notification
                          Do not clear form inputs on failure

Delete confirmation     → show confirm dialog before DELETE calls
```

---

## Step 7 — Dashboard Pages to Build

### 7.1 Filter Groups page  (already built — EmailFilterManager.jsx)

Route: `/email-filters`

### 7.2 Import Log page

Route: `/email-filters/log`

Show a table with columns:

```
Received at · Sender address · Subject (truncated) · Customer
PO extracted · SO matched · Status badge · Flagged indicator
```

Filters: status, customer, date range, flagged only

### 7.3 Unknown Senders queue

Route: `/email-filters/unknown`

Show emails where `customer_id IS NULL` or `import_status = unmatched`.

For each row, show:

```
Sender address · Domain · Subject · Received at
[Assign to customer ▾]  [Mark as spam]
```

The "Assign to customer" dropdown lists all active customers. Selecting one and confirming:
- Calls POST `/api/email-filters/log/{id}/assign`
- Optionally adds the sender to the customer's branch email registry
- Re-runs PO extraction and SO matching on the email

### 7.4 Stats widget (optional, for your existing dashboard home)

Show four numbers:

```
Emails pulled today · Matched · Unmatched · Flagged
```

Call: `GET /api/email-filters/import-log?date_from=today&per_page=1` and use the pagination meta totals.

---

## Step 8 — Seeding Initial Data

After running migrations, seed Carrefour and Naivas as domain customers immediately so emails start matching on first import run.

```
php artisan make:seeder EmailFilterGroupsSeeder
```

In the seeder, create:

```
Carrefour Kenya  · code: CARREFOUR  · strategy: domain  · domain: carrefour.co.ke
Naivas           · code: NAIVAS     · strategy: domain  · domain: naivas.co.ke
Chandarana       · code: CHANDARANA · strategy: registry · (emails to be added via dashboard)
Quickmart        · code: QUICKMART  · strategy: registry · (emails to be added via dashboard)
```

Run:

```
php artisan db:seed --class=EmailFilterGroupsSeeder
```

---

## Step 9 — Config File

Create `config/email_import.php`:

```
batch_size          200         Max emails fetched per job run
min_subject_length  5           Subjects shorter than this are skipped
retry_on_throttle   true        Retry once on Graph API 429
throttle_backoff    60          Seconds to wait before retry
blocked_senders     []          Array of exact addresses to always skip
auto_reply_keywords             Array of subject keywords that indicate auto-reply
cursor_cache_key    'email_import_last_cursor'
```

---

## Step 10 — Testing Checklist

Run through these manually before going live:

```
[ ] Migration runs clean with no errors
[ ] Seeder creates Carrefour and Naivas with domain entries
[ ] POST /api/email-filters/customers creates a new customer
[ ] Adding a duplicate email address returns 422 with correct message
[ ] Changing strategy from registry to domain does not delete email entries
[ ] Import job runs and writes at least one log entry
[ ] Email from carrefour.co.ke domain is matched to CARREFOUR customer
[ ] Email from unregistered address lands in Unknown Senders queue
[ ] Assigning an unknown sender to a customer re-runs PO extraction
[ ] Auto-reply email (subject: "Out of Office") is skipped and not logged
[ ] Duplicate email message ID is not imported twice
[ ] Import log page loads and filters work
[ ] Unknown Senders queue shows only unmatched emails
```

---

## File Reference

| File | Purpose |
|---|---|
| `EmailFilterManager.jsx` | React UI — customer group manager |
| `po_so_matching_requirements.md` | PO normalisation spec (all customers) |
| `carrefour_po_matching.md` | Carrefour C4 extractor spec |
| `email_import_strategy.md` | Strategy selection and guardrails reference |

---

*Kim-Fay East Africa — Operations & Systems Team*