# Requirements Document

## Introduction

The Admin Connectors Module is a production-ready administration layer for the Kim-Fay OrderWatch application. It replaces all dummy/seeded datasets in `src/lib/demo-data.ts` with live API-connected data flows, and delivers seven integrated sub-systems: Outlook email connectivity, AI provider API management (OpenAI and Anthropic), Acumatica ERP integration monitoring, CRON job scheduling, audit logging, roles & permissions management, and a notification rules engine.

The module is built on the existing Laravel 13 (PHP 8.3) backend with Laravel Sanctum authentication, and consumed by the existing React 19 / TanStack Router / Vite TypeScript frontend. All sensitive credentials (API keys, OAuth tokens) must be encrypted at rest. Access to all administration sub-systems is gated by role-based permission checks enforced on both the backend and frontend.

---

## Glossary

- **Admin_Module**: The aggregate of all seven sub-systems described in this document.
- **Outlook_Connector**: The sub-system responsible for Microsoft OAuth 2.0 flows and email reading via Microsoft Graph API.
- **AI_Connector**: The sub-system managing OpenAI and Anthropic API key configuration and health checks.
- **Acumatica_Connector**: The sub-system monitoring Acumatica ERP connection health, metadata retrieval, and sync status.
- **Cron_Scheduler**: The sub-system providing admin UI and backend logic for creating, editing, and running scheduled background jobs.
- **Audit_Logger**: The immutable, searchable append-only log system capturing all significant administrative actions.
- **Permission_Manager**: The sub-system governing role definitions, custom permissions, and user-role assignments.
- **Notification_Engine**: The rule-based engine that evaluates business conditions and dispatches notifications via configured channels.
- **Super_Admin**: A user with the role "Administrator" whose `is_super_admin` flag is `true`; the only tier permitted to perform high-risk actions.
- **Admin**: A user whose role is "Administrator" but without the Super_Admin flag; can manage most settings but not high-risk actions.
- **Encryption_Service**: The Laravel service wrapping AES-256-CBC (via `encrypt()`/`decrypt()`) used for all credential storage.
- **Credential**: Any secret value including OAuth tokens, API keys, and client secrets.
- **EARS**: Easy Approach to Requirements Syntax — the pattern set used to write all acceptance criteria.
- **Microsoft_Graph**: The Microsoft REST API used to read mailbox data after OAuth consent.
- **Cron_Expression**: A string in standard 5-field UNIX cron syntax (`* * * * *`) defining a schedule.
- **Sanctum_Token**: A Laravel Sanctum personal access token used for API authentication.
- **Demo_Data**: The static seeded dataset in `src/lib/demo-data.ts` that this module replaces with live API data.

---

## Requirements

---

### Requirement 1: Outlook Connection — OAuth 2.0 Account Linking

**User Story:** As an Administrator, I want to connect one or more Microsoft 365 mailboxes via OAuth 2.0, so that the system can read order-related emails in real time without storing plain-text passwords.

#### Acceptance Criteria

1. WHEN an Administrator initiates a "Connect Mailbox" action, THE Outlook_Connector SHALL redirect the user to the Microsoft identity platform OAuth 2.0 authorization endpoint with the required scopes (`Mail.Read`, `offline_access`).
2. WHEN Microsoft returns an authorization code to the callback URL, THE Outlook_Connector SHALL exchange the code for an access token and refresh token within 10 seconds.
3. THE Encryption_Service SHALL encrypt the access token and refresh token using AES-256-CBC before persisting them to the database; plain-text tokens SHALL NOT be written to any log or column.
4. WHEN an access token has expired, THE Outlook_Connector SHALL use the stored refresh token to obtain a new access token without requiring user interaction.
5. IF the refresh token exchange fails, THEN THE Outlook_Connector SHALL mark the mailbox status as `reconnect_required`; the status update SHALL be persisted even if the subsequent error log write fails.
6. THE Outlook_Connector SHALL support a minimum of 10 concurrently connected mailbox accounts, each associated with a unique email address and independent token storage row.
7. WHEN an Administrator explicitly disconnects a mailbox using the disconnect action in the admin UI, THE Outlook_Connector SHALL revoke the Microsoft OAuth token via the Microsoft Graph revocation endpoint and delete the stored credentials from the database; credential deletion SHALL NOT occur in any other scenario.
8. THE Admin_Module SHALL display each connected mailbox with its email address, connection status (`connected`, `reconnect_required`, `disconnected`), and the timestamp of the last successful sync.

---

### Requirement 2: Outlook Email Reading and Incremental Sync

**User Story:** As an Administrator, I want the system to read emails from connected mailboxes including all folders and attachments, so that order-relevant content is ingested continuously.

#### Acceptance Criteria

1. WHEN an incremental sync is triggered, THE Outlook_Connector SHALL use the Microsoft Graph `$deltaToken` mechanism to fetch only emails that have arrived or changed since the previous sync.
2. THE Outlook_Connector SHALL traverse all mail folders visible to the authenticated account, including subfolders, not only the Inbox.
3. WHEN an email contains one or more attachments, THE Outlook_Connector SHALL attempt to download each attachment; attachments that are successfully downloaded SHALL be parsed regardless of file size; attachments that fail to download due to a size limit enforcement by the remote server or a client-side 25 MB read cap SHALL be skipped and the skip event SHALL be logged.
4. IF the Microsoft Graph API returns an HTTP 429 (Too Many Requests) response, THEN THE Outlook_Connector SHALL pause the current sync, wait for the duration specified in the `Retry-After` response header, and then resume.
5. IF the Microsoft Graph API returns an HTTP 5xx response during a sync, THEN THE Outlook_Connector SHALL retry the failed request up to 3 times using exponential backoff starting at 2 seconds before logging a sync failure.
6. THE Outlook_Connector SHALL record a structured sync log entry after each incremental sync cycle, capturing: mailbox address, sync start time, sync end time, number of emails fetched, and any errors encountered.
7. WHEN a sync cycle completes for any mailbox with the `sync_cycle_completed` flag explicitly set to `true`, THE Audit_Logger SHALL append an immutable audit entry containing actor (`system`), action (`mailbox_sync_completed`), and the mailbox address as the affected resource.

---

### Requirement 3: OpenAI and Anthropic API Key Management

**User Story:** As an Administrator, I want to configure and manage API keys for OpenAI and Anthropic in one place, so that AI features always use valid, encrypted credentials.

#### Acceptance Criteria

1. THE AI_Connector SHALL read the default OpenAI API key from the `OPENAI_API_KEY` environment variable and the default Anthropic API key from the `ANTHROPIC_API_KEY` environment variable at application boot.
2. WHEN an Administrator creates or updates an AI provider API key via the admin UI, THE Encryption_Service SHALL encrypt the key value before storing it in the `ai_api_keys` database table; the plain-text key value SHALL NOT appear in any log or HTTP response body.
3. WHERE a user-stored API key exists for a given provider, THE AI_Connector SHALL prefer the user-stored encrypted key over the environment variable default.
4. WHEN the AI_Connector needs to invoke an external AI provider, THE AI_Connector SHALL mask the API key in all log output so that only the first 7 characters and the suffix `…[masked]` are visible.
5. IF an AI provider API call returns an HTTP 429 (rate limit) response, THEN THE AI_Connector SHALL apply exponential backoff starting at 1 second, doubling up to a maximum wait of 32 seconds, retrying up to 5 times before marking the request as failed.
6. IF an AI provider API call fails after all retries, THEN THE AI_Connector SHALL return a structured error response to the caller and SHALL NOT throw an unhandled exception.
7. THE Admin_Module SHALL display, for each AI provider, the key source (`environment` or `database`), the masked key preview (first 7 characters + `…[masked]`), the last successful call timestamp, and the current health status (`healthy`, `rate_limited`, `error`).
8. WHEN an Administrator deletes a stored AI provider API key, THE AI_Connector SHALL purge the database record, after which the connector SHALL fall back to the environment variable default.

---

### Requirement 4: Acumatica Integration Monitoring

**User Story:** As an Administrator, I want to view real-time Acumatica connection health, metadata, and sync statuses, so that I can diagnose ERP integration issues without accessing Acumatica directly.

#### Acceptance Criteria

1. THE Acumatica_Connector SHALL expose an admin API endpoint that returns current connection health, including: HTTP response time (ms) to the configured Acumatica base URL, authentication status, and the timestamp of the most recent successful API call.
2. WHEN an Administrator triggers a "Validate Credentials" action, THE Acumatica_Connector SHALL attempt to authenticate against the Acumatica REST API using the stored credentials; 15 seconds is the maximum allowed timeout for the authentication attempt, after which THE Acumatica_Connector SHALL return a failure result with a human-readable timeout message.
3. THE Acumatica_Connector SHALL retrieve and cache the following Acumatica metadata for display: tenant name, endpoint version, active company count, and last schema refresh date.
4. WHEN an Acumatica sync job completes, THE Acumatica_Connector SHALL record: sync type (Sales Orders or Customers), job start time, job end time, record count processed, and any error messages; zero-record sync completions SHALL be recorded in the same way as non-zero syncs.
5. THE Admin_Module SHALL display the last 20 Acumatica sync history entries in reverse chronological order.
6. IF the Acumatica credential validation fails, THEN THE Acumatica_Connector SHALL log a structured error entry with action type `acumatica_auth_failure` and the error reason.
7. WHEN an Administrator changes any Acumatica configuration field (base URL, tenant, username), THE Audit_Logger SHALL append an immutable audit entry capturing the field name, old masked value, new masked value, actor user ID, and timestamp.

---

### Requirement 5: CRON Jobs Settings Interface

**User Story:** As an Administrator, I want to create, schedule, pause, and delete background cron jobs via a UI, so that scheduled operations are visible and controllable without SSH access.

#### Acceptance Criteria

1. THE Cron_Scheduler SHALL expose admin API endpoints for creating, reading, updating, and deleting cron job definitions stored in the `cron_jobs` database table.
2. WHEN an Administrator creates or updates a cron job, THE Cron_Scheduler SHALL validate the provided Cron_Expression against the 5-field UNIX cron syntax; IF the expression is invalid, THEN THE Cron_Scheduler SHALL return a 422 response with a field-level validation error message.
3. WHEN a cron job executes, THE Cron_Scheduler SHALL record a run log entry containing: job ID, job name, scheduled time, actual start time, actual end time, exit status (`success` or `failure`), and the first 500 characters of any output or error message.
4. WHILE a cron job is in `paused` status, THE Cron_Scheduler SHALL skip all scheduled executions for that job without deleting its definition.
5. IF a cron job execution fails (non-zero exit or exception), THEN THE Cron_Scheduler SHALL create a structured failure alert entry and trigger the Notification_Engine failure alerting channel for that job.
6. THE Admin_Module SHALL display, for each cron job: name, Cron_Expression, next scheduled run (calculated server-side), last run status, and last run timestamp.
7. THE Admin_Module SHALL display the last 50 run log entries for each individual cron job, filterable by status (`success`, `failure`).
8. THE Cron_Scheduler SHALL prevent creation of duplicate cron job names within the same tenant; IF a duplicate name is submitted, THEN THE Cron_Scheduler SHALL return a 422 validation error.

---

### Requirement 6: Audit Logs System

**User Story:** As an Administrator, I want every significant administrative action recorded in an immutable, searchable audit log, so that I can demonstrate compliance and investigate incidents.

#### Acceptance Criteria

1. THE Audit_Logger SHALL record an audit entry for every create, update, and delete operation performed against: mailbox credentials, AI provider keys, Acumatica configuration, cron job definitions, roles, permission assignments, and notification rules.
2. EACH audit entry SHALL contain: entry ID (UUID), timestamp (UTC, microsecond precision), actor user ID, actor IP address, action type (from a defined enum), affected resource type, affected resource ID, and a JSON `changes` field capturing before/after values.
3. THE Audit_Logger SHALL guarantee append-only semantics: no API endpoint or internal service SHALL expose an update or delete operation on audit entries.
4. THE Admin_Module SHALL provide an audit log search interface supporting simultaneous filtering by: date range (start date, end date), actor user ID, action type, and affected resource type.
5. WHEN more than one filter parameter is applied, THE Admin_Module SHALL return only audit entries that satisfy all applied filters (AND logic).
6. THE Admin_Module SHALL paginate audit log results at 50 entries per page and display total result count.
7. WHEN an Administrator requests an audit log export, THE Audit_Logger SHALL generate a CSV file containing all entries matching the current filter state, with columns matching the entry fields defined in AC2, and return it as a downloadable file attachment within 30 seconds for datasets up to 10,000 entries.
8. THE Encryption_Service SHALL ensure that credential values referenced in audit `changes` fields are masked (first 4 characters + `…[masked]`) before the entry is persisted.

---

### Requirement 7: Roles and Permissions Management

**User Story:** As a Super_Admin, I want to create, modify, and assign custom roles with fine-grained permissions, so that each user tier accesses only the features appropriate to their responsibility.

#### Acceptance Criteria

1. THE Permission_Manager SHALL maintain a `roles` table and a `permissions` table with a many-to-many `role_permissions` pivot; roles and permissions SHALL be defined in the database, not hard-coded in application logic.
2. WHEN an Administrator assigns a user to a role, THE Permission_Manager SHALL store the assignment in a `user_roles` table; a user MAY hold exactly one role at a time.
3. THE Permission_Manager SHALL enforce permission checks on every protected API route via a Laravel middleware that reads the authenticated user's role permissions; a 403 response SHALL be returned for any missing permission.
4. WHERE the Super_Admin feature is enabled for a user, THE Permission_Manager SHALL permit that user to modify role definitions, edit permissions, reassign other users' roles, and manage API keys; these actions SHALL be blocked for all non-Super_Admin users.
5. WHEN a Super_Admin creates a new role, THE Permission_Manager SHALL require a unique role name and at least one assigned permission; IF either constraint is violated, THEN THE Permission_Manager SHALL return a 422 validation error.
6. WHEN a Super_Admin modifies role permissions, THE Permission_Manager SHALL immediately invalidate any cached permission sets for all users currently holding that role.
7. THE Admin_Module SHALL display a role–permission matrix table showing all roles as columns and all permissions as rows, with a toggle cell indicating the grant state for each intersection.
8. WHEN an Administrator attempts to delete a role that has one or more assigned users, THE Permission_Manager SHALL return a 409 conflict error with a message listing the number of affected users; the deletion SHALL NOT proceed until all users are reassigned.
9. THE Permission_Manager SHALL seed the following default roles on first migration: Administrator, Customer Service Manager, Customer Service Agent, Sales Operations, Executive — matching the existing `role` values in the `users` table.

---

### Requirement 8: Notification Rules Engine

**User Story:** As an Administrator, I want configurable notification rules that automatically alert the right recipients through the right channels when specific business conditions occur, so that critical events never go unnoticed.

#### Acceptance Criteria

1. THE Notification_Engine SHALL evaluate the following four built-in rule conditions on a schedule not exceeding 5 minutes:
   - **Rule R1**: One or more orders have been in a non-`Matched` status for more than 2 hours.
   - **Rule R2**: One or more customers have a delivery time that has passed the agreed SLA target.
   - **Rule R3**: The aggregate order value of all non-`Matched` orders for the current calendar day exceeds KES 5,000,000.
   - **Rule R4**: An AI insight processing cycle has completed.

2. WHEN Rule R1 evaluates to true, THE Notification_Engine SHALL dispatch a notification via both the email channel and the in-app channel to all users holding the "Customer Service Manager" or "Administrator" role.

3. WHEN Rule R2 evaluates to true, THE Notification_Engine SHALL dispatch a notification via the email channel only to the account manager assigned to the breached customer; IF no account manager is assigned, THEN THE Notification_Engine SHALL send to all "Customer Service Manager" role holders instead.

4. WHEN Rule R3 evaluates to true, THE Notification_Engine SHALL dispatch a notification via the email channel only to all users whose role is "Sales Operations" or whose `is_account_manager` flag is `true`.

5. WHEN Rule R4 evaluates to true, THE Notification_Engine SHALL dispatch a notification via the in-app channel only to all authenticated users currently holding any role.

6. THE Notification_Engine SHALL record a notification dispatch log entry for each dispatched notification, capturing: rule ID, evaluation timestamp, channel, recipient user ID, and delivery status (`queued`, `delivered`, `failed`).

7. WHEN a notification email fails to deliver after 3 queued attempts, THE Notification_Engine SHALL update the dispatch log entry status to `failed` and create an in-app system alert for an Administrator.

8. THE Admin_Module SHALL display all four built-in notification rules with their enabled/disabled toggle, last evaluation time, and last trigger time.

9. WHEN an Administrator toggles a notification rule on or off, THE Notification_Engine SHALL immediately apply the change; the state change SHALL be recorded in the Audit_Logger.

10. THE Notification_Engine SHALL enforce a minimum re-trigger interval of 30 minutes per rule per recipient to prevent notification flooding; if a rule condition is continuously true, a new notification SHALL NOT be dispatched to the same recipient within 30 minutes of the previous dispatch.

---

### Requirement 9: Cross-Cutting — Credential Encryption Standards

**User Story:** As a system owner, I want all sensitive credentials encrypted at rest using a consistent standard, so that a database compromise does not expose usable secrets.

#### Acceptance Criteria

1. THE Encryption_Service SHALL use Laravel's `encrypt()` helper (AES-256-CBC, HMAC-SHA-256 authentication) for all Credential values stored in the database; no other encryption algorithm SHALL be used for credential columns.
2. THE Encryption_Service SHALL store encrypted credentials in `TEXT` or `LONGTEXT` database columns tagged with a `_encrypted` suffix convention in their column name to distinguish them from plain-text columns.
3. IF decryption of a stored credential fails (corrupted ciphertext or mismatched key), THEN THE Encryption_Service SHALL log a structured error with action type `credential_decryption_failure` and return a null value to the caller rather than propagating an exception.
4. THE Encryption_Service SHALL never log, serialize, or return a decrypted Credential value in any HTTP response body; all API responses referencing credentials SHALL return only masked previews.

---

### Requirement 10: Cross-Cutting — Validation and Error Handling

**User Story:** As a developer and system operator, I want all user-submitted configuration inputs validated on both client and server, and all third-party API failures handled gracefully, so that invalid data is caught early and production errors are diagnosable.

#### Acceptance Criteria

1. THE Admin_Module SHALL validate all user-submitted configuration forms on the client side using Zod schema validation before any API request is made; validation errors SHALL be displayed inline at the field level.
2. THE Admin_Module SHALL validate all incoming request payloads on the server side using Laravel Form Request classes; IF server-side validation fails, THE Admin_Module SHALL return a 422 response with a `errors` map keyed by field name.
3. WHEN a third-party API call (Microsoft Graph, OpenAI, Anthropic, Acumatica) fails with any network error or HTTP error status — including retryable statuses — THE Admin_Module SHALL log a structured error entry containing: service name, HTTP status code, error message, request URL (sanitized of credentials), and timestamp; logging SHALL occur on every failure regardless of whether the request will be retried.
4. WHEN the frontend receives any API error response, THE Admin_Module SHALL display a contextual toast notification to the user describing the failure in plain language without exposing internal stack traces or credential values.
5. THE Admin_Module SHALL expose a `/api/admin/health` endpoint that returns the current connectivity status of all configured external services (Outlook OAuth, OpenAI, Anthropic, Acumatica) as a JSON object; this endpoint SHALL be accessible to Administrator role users only.
6. IF a database query within any Admin_Module service throws an exception, THEN THE Admin_Module SHALL catch the exception, log it with full stack trace to the Laravel `daily` log channel, and return a 500 response with a generic `"An internal error occurred"` message.

---

### Requirement 11: Cross-Cutting — Demo Data Migration

**User Story:** As a developer, I want all pages currently consuming `src/lib/demo-data.ts` to be migrated to live backend API calls, so that the application reflects real operational data.

#### Acceptance Criteria

1. THE Admin_Module SHALL provide API endpoints replacing every data export currently in `src/lib/demo-data.ts`: orders list, trend data, KPI metrics, activity feed, escalations, AI recommendations, notifications, and audit logs.
2. WHEN the frontend loads any page that previously consumed Demo_Data, THE Admin_Module SHALL fetch the corresponding data from the backend API using the existing `apiFetch` client in `src/lib/api.ts`.
3. THE Admin_Module SHALL return paginated responses for all list endpoints (orders, customers, audit logs, cron run logs, notifications) with a consistent envelope: `{ data: [...], meta: { current_page, last_page, per_page, total } }`.
4. WHILE a live API request is in flight, THE Admin_Module SHALL display skeleton loading states in the frontend using the existing shadcn/ui `Skeleton` component; Demo_Data SHALL NOT be shown as a fallback once migration is complete.
5. IF a live API endpoint returns an error, THEN THE Admin_Module SHALL immediately replace any active skeleton loading state with an empty-state component displaying a human-readable error message and a retry button; Demo_Data SHALL NOT be shown as a fallback once migration is complete.

---

### Requirement 12: Cross-Cutting — Structured Logging and Alerting

**User Story:** As a system operator, I want all connection failures and significant system events logged in a structured, queryable format, so that issues are diagnosable from logs alone.

#### Acceptance Criteria

1. THE Admin_Module SHALL write all structured log entries as JSON objects to the Laravel `daily` log channel; each entry SHALL include at minimum: `timestamp`, `level`, `service`, `event`, `user_id` (nullable), `ip_address` (nullable), and `context` (arbitrary key-value pairs).
2. WHEN any external service connection fails (Outlook OAuth, Microsoft Graph, OpenAI, Anthropic, Acumatica), THE Admin_Module SHALL write a log entry at `error` level with the service name and sanitized error details.
3. WHEN any external service connection succeeds after a previous failure, THE Admin_Module SHALL write a log entry at `info` level with the service name and the duration of the outage in seconds.
4. THE Admin_Module SHALL expose a `/api/admin/logs` endpoint returning the 100 most recent structured log entries for Administrator role users, sortable by timestamp and filterable by `level` and `service`.

