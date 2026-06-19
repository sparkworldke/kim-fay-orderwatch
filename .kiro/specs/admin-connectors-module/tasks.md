# Implementation Tasks — Admin Connectors Module

## Task Dependency Graph

```
T1 (Foundations) → T2 (Encryption) → T3 (Permissions) → T4 (Audit Logger)
T4 → T5 (Acumatica) → T6 (Outlook) → T7 (AI Connectors)
T4 → T8 (Cron Jobs) → T9 (Notification Engine)
T3 → T10 (Roles UI) → T11 (Permissions Matrix UI)
T5 → T12 (Acumatica UI) → T13 (Admin Health Endpoint)
T6 → T14 (Mailboxes UI)
T7 → T15 (AI Connectors UI)
T8 → T16 (Cron Jobs UI)
T9 → T17 (Notification Rules UI)
T4 → T18 (Audit Logs UI)
T13 → T19 (Demo Data Migration) → T20 (E2E Tests)
```

---

## Phase 1 — Backend Foundations

### Task 1 — Database Migrations and Models
**Depends on:** nothing  
**Requirements covered:** R1, R2, R3, R4, R5, R6, R7, R8, R9

- [x] Create migration `2026_07_01_000001_create_mailbox_accounts_table.php`
  - Columns: `id` BIGINT PK, `email` VARCHAR(255) UNIQUE, `display_name` VARCHAR(255) nullable, `access_token_encrypted` TEXT, `refresh_token_encrypted` TEXT, `token_expires_at` TIMESTAMP nullable, `status` ENUM(`connected`,`reconnect_required`,`disconnected`) DEFAULT `connected`, `last_synced_at` TIMESTAMP nullable, `delta_token` TEXT nullable, timestamps
- [x] Create migration `2026_07_01_000002_create_mailbox_sync_logs_table.php`
  - Columns: `id`, `mailbox_account_id` FK→mailbox_accounts, `started_at`, `ended_at` nullable, `emails_fetched` INT DEFAULT 0, `status` ENUM(`running`,`completed`,`failed`), `error_message` TEXT nullable
- [x] Create migration `2026_07_01_000003_create_ai_api_keys_table.php`
  - Columns: `id`, `provider` ENUM(`openai`,`anthropic`) UNIQUE, `key_encrypted` TEXT, `created_by` FK→users nullable, `last_used_at` TIMESTAMP nullable, `health_status` ENUM(`healthy`,`rate_limited`,`error`) DEFAULT `healthy`, timestamps
- [x] Create migration `2026_07_01_000004_create_acumatica_configs_table.php`
  - Columns: `id`, `base_url` VARCHAR(500) DEFAULT `https://kimfay.acumatica.com`, `endpoint` VARCHAR(100) DEFAULT `IpayV2`, `version` VARCHAR(50) DEFAULT `22.200.001`, `tenant` VARCHAR(255) DEFAULT `Kim-Fay Limited`, `grant_type` VARCHAR(50) DEFAULT `password`, `scope` VARCHAR(50) DEFAULT `api`, `username` VARCHAR(255), `password_encrypted` TEXT, `client_id_encrypted` TEXT nullable, `client_secret_encrypted` TEXT nullable, `token_url` VARCHAR(500) DEFAULT `https://kimfay.acumatica.com/identity/connect/token`, `endpoint_version` VARCHAR(50) nullable, `last_validated_at` TIMESTAMP nullable, `health_status` ENUM(`connected`,`error`,`unchecked`) DEFAULT `unchecked`, timestamps
- [x] Create migration `2026_07_01_000005_create_acumatica_sync_logs_table.php`
  - Columns: `id`, `sync_type` ENUM(`sales_orders`,`customers`), `started_at`, `ended_at` nullable, `record_count` INT DEFAULT 0, `status` ENUM(`running`,`completed`,`failed`), `error_message` TEXT nullable
- [x] Create migration `2026_07_01_000006_create_cron_jobs_table.php`
  - Columns: `id`, `name` VARCHAR(255) UNIQUE, `description` TEXT nullable, `cron_expression` VARCHAR(100), `command` VARCHAR(1000), `status` ENUM(`active`,`paused`) DEFAULT `active`, `last_run_at` TIMESTAMP nullable, `last_run_status` ENUM(`success`,`failure`) nullable, `next_run_at` TIMESTAMP nullable, timestamps
- [x] Create migration `2026_07_01_000007_create_cron_run_logs_table.php`
  - Columns: `id`, `cron_job_id` FK→cron_jobs CASCADE DELETE, `scheduled_at`, `started_at`, `ended_at` nullable, `status` ENUM(`success`,`failure`), `output` TEXT nullable (first 500 chars)
- [x] Create migration `2026_07_01_000008_create_audit_logs_table.php`
  - Columns: `id` CHAR(36) PK UUID, `timestamp` TIMESTAMP(6), `actor_user_id` FK→users nullable, `actor_ip` VARCHAR(45) nullable, `action_type` VARCHAR(100), `resource_type` VARCHAR(100), `resource_id` VARCHAR(255) nullable, `changes` JSON nullable
  - NO `updated_at` column (append-only)
- [x] Create migration `2026_07_01_000009_create_roles_permissions_tables.php`
  - `roles`: `id`, `name` VARCHAR(100) UNIQUE, `description` TEXT nullable, `is_system` BOOLEAN DEFAULT false, timestamps
  - `permissions`: `id`, `name` VARCHAR(100) UNIQUE, `description` TEXT nullable, timestamps
  - `role_permissions` pivot: `role_id` FK, `permission_id` FK
  - `user_roles`: `id`, `user_id` FK UNIQUE, `role_id` FK, `assigned_by` FK nullable, timestamps
- [x] Create migration `2026_07_01_000010_add_is_super_admin_to_users_table.php`
  - Add `is_super_admin` BOOLEAN DEFAULT false to `users` table
  - Add `is_account_manager` BOOLEAN DEFAULT false to `users` table
- [x] Create migration `2026_07_01_000011_create_notification_rules_table.php`
  - `notification_rules`: `id`, `rule_key` VARCHAR(50) UNIQUE, `label` VARCHAR(255), `channels` JSON, `is_enabled` BOOLEAN DEFAULT true, `last_evaluated_at` nullable, `last_triggered_at` nullable, timestamps
  - `notification_dispatch_logs`: `id`, `rule_id` FK, `evaluated_at`, `channel` ENUM(`email`,`in_app`), `recipient_user_id` FK, `delivery_status` ENUM(`queued`,`delivered`,`failed`), timestamps
- [-] Create Eloquent models: `MailboxAccount`, `MailboxSyncLog`, `AiApiKey`, `AcumaticaConfig`, `AcumaticaSyncLog`, `CronJob`, `CronRunLog`, `AuditLog`, `Role`, `Permission`, `UserRole`, `NotificationRule`, `NotificationDispatchLog`
  - Each model must declare `$fillable`, casts, and relationships
  - `AuditLog` must override `getUpdatedAtColumn()` to return `null`
- [-] Create seeder `RolesPermissionsSeeder.php`
  - Seeds five default roles: Administrator, Customer Service Manager, Customer Service Agent, Sales Operations, Executive (all `is_system=true`)
  - Seeds all permission slugs listed in design.md
  - Seeds four default notification rules: R1 (critical orders pending), R2 (SLA breach), R3 (revenue at risk), R4 (AI cycle complete)
- [-] Register seeder in `DatabaseSeeder.php`
- [~] Run `php artisan migrate` and verify SQLite schema


---

### Task 2 — EncryptionService
**Depends on:** T1  
**Requirements covered:** R9

- [~] Create `app/Services/Admin/EncryptionService.php`
  - `encrypt(string $plaintext): string` — wraps Laravel `encrypt()` (AES-256-CBC)
  - `decrypt(string $ciphertext): ?string` — catches `DecryptException`, writes structured log entry with `action_type=credential_decryption_failure`, returns null
  - `mask(string $value, int $visibleChars = 7): string` — first N chars + `…[masked]`
  - `maskCredential(string $value): string` — 4 chars visible (for audit `changes` field)
- [~] Register as singleton in `AppServiceProvider`
- [~] Write unit test `EncryptionServiceTest.php`
  - Test: encrypt→decrypt round-trip returns original value
  - Test: decrypt with corrupted ciphertext returns null (no exception thrown)
  - Test: mask returns correct format for keys shorter and longer than `$visibleChars`

---

### Task 3 — Permission Middleware and Manager
**Depends on:** T1, T2  
**Requirements covered:** R7

- [~] Create `app/Services/Admin/PermissionManager.php`
  - `getUserPermissions(int $userId): array` — reads `user_roles` + `role_permissions`, returns slug array; caches in Laravel Cache keyed by `permissions.user.{id}`
  - `hasPermission(int $userId, string $slug): bool`
  - `isSuperAdmin(int $userId): bool` — checks `users.is_super_admin`
  - `invalidateUserCache(int $userId): void`
  - `invalidateRoleCache(int $roleId): void` — clears cache for all users holding that role
- [~] Create `app/Http/Middleware/RequiresPermission.php`
  - Reads route name to resolve required permission slug from a static map
  - Delegates to `PermissionManager::hasPermission()`
  - Returns 403 JSON `{"message":"Forbidden","required":"<slug>"}` on failure
  - Super-admin-only routes additionally check `isSuperAdmin()`
- [~] Register middleware alias `requires.permission` in `bootstrap/app.php`
- [~] Write unit test `PermissionManagerTest.php`
  - Test: user with correct role+permission returns `hasPermission=true`
  - Test: user without role returns `hasPermission=false`
  - Test: super-admin check correctly reads `is_super_admin` flag
  - Test: cache invalidation on role permission change

---

### Task 4 — AuditLogger Service and StructuredLogger
**Depends on:** T1  
**Requirements covered:** R6, R12

- [~] Create `app/Services/Admin/AuditLogger.php`
  - `log(string $actionType, string $resourceType, string|int|null $resourceId, array $changes = [], ?int $actorUserId = null, ?string $actorIp = null): void`
  - Generates UUID v4 for `id`, uses `now()->format('Y-m-d H:i:s.u')` for microsecond timestamp
  - Auto-masks credential values in `$changes` via `EncryptionService::maskCredential()`
  - Wraps entire DB insert in try/catch — on failure writes to file log, never throws
- [~] Create `app/Services/Admin/StructuredLogger.php`
  - `static write(string $level, string $service, string $event, array $context = [], ?int $userId = null, ?string $ip = null): void`
  - Writes JSON object `{timestamp, level, service, event, user_id, ip_address, context}` to Laravel `daily` channel
- [~] Write unit test `AuditLoggerTest.php`
  - Test: log() inserts row with correct UUID format
  - Test: credential values in `changes` array are masked (not raw)
  - Test: log() does not throw when DB is unavailable (exception swallowed)


---

## Phase 2 — Acumatica Integration

### Task 5 — AcumaticaService (Real API Integration)
**Depends on:** T2, T4  
**Requirements covered:** R4

The Acumatica instance is at `https://kimfay.acumatica.com`. Authentication uses the OAuth 2.0 **password grant** flow against `/identity/connect/token`. Sales orders are fetched from the `IpayV2` endpoint at version `22.200.001` with `$expand=CustomerDetails,DocumentDetails,PaymentDetails`.

- [~] Create `app/Services/Admin/AcumaticaService.php`

  **Authentication — `authenticate(): string`**
  - POST to `https://kimfay.acumatica.com/identity/connect/token` with form body:
    ```
    grant_type=password
    client_id={client_id_encrypted decrypted}
    client_secret={client_secret_encrypted decrypted}
    username={username}
    password={password_encrypted decrypted}
    scope=api
    ```
  - Stores returned `access_token` in memory for the request lifecycle (not persisted)
  - HTTP timeout: 15 seconds; on timeout return failure with message `"Acumatica authentication timed out after 15s"`
  - On HTTP error or network failure: log via `StructuredLogger` at `error` level, `service=acumatica`, `event=auth_failure`

  **`validateCredentials(): array`**
  - Calls `authenticate()`; records `response_ms` via `microtime(true)`
  - Returns `['success' => bool, 'message' => string, 'response_ms' => int]`
  - On failure logs `action_type=acumatica_auth_failure` via `AuditLogger`

  **`fetchSalesOrders(array $filters = []): array`**
  - GET `https://kimfay.acumatica.com/entity/IpayV2/22.200.001/SalesOrder/`
  - Query params: `$expand=CustomerDetails,DocumentDetails,PaymentDetails`
  - Optional filter: `$filter=OrderNbr eq '{orderNbr}'` or `$filter=CustomerID eq '{customerId}'`
  - Requires Bearer token from `authenticate()`
  - Returns parsed array of sales order objects
  - On 401: retry `authenticate()` once, then fail
  - On 429: respect `Retry-After` header, retry once
  - On 5xx: retry up to 3 times with exponential backoff (2s, 4s, 8s)

  **`getHealth(): array`**
  - Attempts `authenticate()` and measures response time
  - Returns `['status' => string, 'response_ms' => int, 'last_successful_call_at' => string|null]`

  **`fetchMetadata(): void`**
  - After successful auth, caches tenant name, endpoint version (`22.200.001`), and last schema refresh into `acumatica_configs`

  **`syncSalesOrders(): void`**
  - Calls `fetchSalesOrders()` with no filters (full sync)
  - Records `AcumaticaSyncLog` entry with `sync_type=sales_orders`
  - Maps Acumatica `SalesOrder` fields to internal `orders` schema
  - Calls `AuditLogger::log()` with `action_type=acumatica_sync_completed`

- [~] Store Acumatica credentials (client_id, client_secret, password) via `EncryptionService` in `acumatica_configs`; seed default values from `.env` vars `ACUMATICA_CLIENT_ID`, `ACUMATICA_CLIENT_SECRET`, `ACUMATICA_USERNAME`, `ACUMATICA_PASSWORD`
- [~] Add env vars to `backend/.env.example`: `ACUMATICA_BASE_URL`, `ACUMATICA_TOKEN_URL`, `ACUMATICA_CLIENT_ID`, `ACUMATICA_CLIENT_SECRET`, `ACUMATICA_USERNAME`, `ACUMATICA_PASSWORD`, `ACUMATICA_ENDPOINT`, `ACUMATICA_VERSION`
- [~] Write unit test `AcumaticaServiceTest.php` (mock HTTP client)
  - Test: successful auth returns access token
  - Test: auth timeout returns failure result (not exception)
  - Test: `fetchSalesOrders()` builds correct URL with `$expand` parameter
  - Test: 429 response triggers Retry-After wait then retry
  - Test: 3 consecutive 5xx responses logs failure and returns empty array

---

### Task 6 — Acumatica API Controller and Routes
**Depends on:** T3, T5  
**Requirements covered:** R4, R10

- [~] Create `app/Http/Controllers/Api/Admin/AcumaticaController.php`
  - `index()` — returns current config (credentials masked) + health status + last 20 sync logs
  - `update(StoreAcumaticaConfigRequest $request)` — updates config, encrypts credentials, calls `AuditLogger::log()` for each changed field with old/new masked values
  - `validate()` — calls `AcumaticaService::validateCredentials()`, returns result
  - `syncOrders()` — manually triggers `AcumaticaService::syncSalesOrders()`
- [~] Create `app/Http/Requests/Admin/StoreAcumaticaConfigRequest.php`
  - Validates: `base_url` url, `tenant` required string, `username` required string, `password` required string (only required on create), `client_id` nullable string, `client_secret` nullable string
- [~] Register routes in `routes/api.php`:
  ```
  GET    /admin/acumatica         → AcumaticaController@index
  PUT    /admin/acumatica         → AcumaticaController@update
  POST   /admin/acumatica/validate → AcumaticaController@validate
  POST   /admin/acumatica/sync    → AcumaticaController@syncOrders
  ```
- [~] All routes guarded by `auth:sanctum` + `requires.permission:acumatica.view` (index) / `acumatica.config` (update) / `acumatica.validate` (validate/sync)


---

## Phase 3 — Outlook Connector

### Task 7 — OutlookService and OAuth Flow
**Depends on:** T2, T4  
**Requirements covered:** R1, R2

- [~] Add Composer dependencies to `backend/composer.json`:
  ```json
  "league/oauth2-client": "^2.7",
  "microsoft/microsoft-graph": "^2.0"
  ```
- [~] Create `app/Services/Admin/OutlookService.php`

  **`getAuthorizationUrl(string $state): string`**
  - Builds Microsoft OAuth2 authorization URL with scopes `Mail.Read offline_access`
  - Reads `MICROSOFT_CLIENT_ID`, `MICROSOFT_REDIRECT_URI` from env

  **`exchangeCode(string $code): array`**
  - POST to `https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token`
  - HTTP timeout: 10 seconds
  - Returns `['access_token', 'refresh_token', 'expires_in']`
  - Stores tokens via `EncryptionService::encrypt()` into `mailbox_accounts`

  **`refreshToken(MailboxAccount $account): void`**
  - Uses stored `refresh_token_encrypted` to obtain new access token
  - On failure: sets `status = reconnect_required`, persists, logs `event=token_refresh_failed`

  **`syncMailbox(MailboxAccount $account): void`**
  - Creates `MailboxSyncLog` with `status=running`
  - Calls Graph `/me/mailFolders` to enumerate all folders including subfolders
  - For each folder: GET `/me/mailFolders/{id}/messages/delta?$deltatoken={delta_token}`
  - On 429: reads `Retry-After` header, sleeps, resumes
  - On 5xx: exponential retry (2s, 4s, 8s), max 3 retries
  - For each email with attachments: GET `/me/messages/{id}/attachments`; skip attachments > 25 MB and log skip event
  - Updates `MailboxSyncLog` with `status=completed`, `emails_fetched`, `ended_at`
  - Stores new `delta_token` from Graph response link header
  - Calls `AuditLogger::log()` with `action_type=mailbox_sync_completed`

  **`disconnectMailbox(MailboxAccount $account): void`**
  - Calls Microsoft Graph token revocation endpoint
  - Deletes `mailbox_accounts` row (credentials deleted only here)
  - Calls `AuditLogger::log()` with `action_type=mailbox_disconnected`

- [~] Add env vars to `backend/.env.example`: `MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`, `MICROSOFT_REDIRECT_URI`, `MICROSOFT_TENANT_ID`

---

### Task 8 — Mailbox Controller and Routes
**Depends on:** T3, T7  
**Requirements covered:** R1, R2, R10

- [~] Create `app/Http/Controllers/Api/Admin/MailboxController.php`
  - `index()` — returns all `mailbox_accounts` with masked status fields
  - `oauthRedirect()` — returns authorization URL for frontend to redirect to
  - `oauthCallback(Request $request)` — exchanges code, creates/updates `mailbox_accounts` row, returns new mailbox record
  - `destroy(int $id)` — calls `OutlookService::disconnectMailbox()`
  - `sync(int $id)` — dispatches `SyncMailboxJob` to queue
- [~] Register routes:
  ```
  GET    /admin/mailboxes                    → MailboxController@index
  POST   /admin/mailboxes/oauth/redirect     → MailboxController@oauthRedirect
  GET    /admin/mailboxes/oauth/callback     → MailboxController@oauthCallback
  DELETE /admin/mailboxes/{id}               → MailboxController@destroy
  POST   /admin/mailboxes/{id}/sync          → MailboxController@sync
  ```
- [~] Create queued job `app/Jobs/SyncMailboxJob.php` that calls `OutlookService::syncMailbox()`

---

## Phase 4 — AI Connectors

### Task 9 — AiConnectorService and Controller
**Depends on:** T2, T4  
**Requirements covered:** R3, R10

- [~] Create `app/Services/Admin/AiConnectorService.php`

  **`getKeyForProvider(string $provider): string`**
  - Checks `ai_api_keys` for DB record; decrypts via `EncryptionService::decrypt()`
  - Falls back to `env('OPENAI_API_KEY')` or `env('ANTHROPIC_API_KEY')`

  **`callWithRetry(string $provider, callable $fn): mixed`**
  - Wraps `$fn` in try/catch; on HTTP 429: exponential backoff 1s→2s→4s→8s→16s→32s, max 5 retries
  - On final failure: returns `['error' => true, 'message' => '...']`, never throws

  **`maskKey(string $key): string`**
  - Returns first 7 chars + `…[masked]`

  **`getProviderStatus(): array`**
  - Returns array per provider: `['source', 'masked_preview', 'last_used_at', 'health_status']`

- [~] Create `app/Http/Controllers/Api/Admin/AiConnectorController.php`
  - `index()` — returns provider status (no raw keys in response)
  - `store(StoreAiKeyRequest $request)` — encrypts key, upserts `ai_api_keys`, logs to `AuditLogger`
  - `destroy(int $id)` — deletes DB record (falls back to env var), logs to `AuditLogger`
- [~] Create `app/Http/Requests/Admin/StoreAiKeyRequest.php`
  - Validates: `provider` ENUM `openai|anthropic`, `key` string min:20
- [ ] Register routes:
  ```
  GET    /admin/ai-keys       → AiConnectorController@index
  POST   /admin/ai-keys       → AiConnectorController@store
  DELETE /admin/ai-keys/{id}  → AiConnectorController@destroy
  ```
- [~] Add env vars to `backend/.env.example`: `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`
- [~] Write unit test: verify that `callWithRetry()` does not throw on final failure


---

## Phase 5 — Cron Jobs and Roles

### Task 10 — CronSchedulerService and Controller
**Depends on:** T4  
**Requirements covered:** R5

- [~] Create `app/Services/Admin/CronSchedulerService.php`
  - `validateExpression(string $expr): bool` — regex for 5-field UNIX cron; throw `ValidationException` on failure
  - `calculateNextRun(string $expr): Carbon` — use `dragonmantank/cron-expression` library
  - `recordRunLog(CronJob $job, string $status, string $output): void` — trims output to 500 chars
- [~] Add `dragonmantank/cron-expression` to `composer.json`
- [~] Update `routes/console.php` (or `app/Console/Kernel.php`) to dynamically read `active` cron_jobs rows and register each with `$schedule->call(fn)->cron($job->cron_expression)`
- [~] Create `app/Http/Controllers/Api/Admin/CronJobController.php`
  - `index()` — all cron jobs with `next_run_at` calculated server-side
  - `store(StoreCronJobRequest $request)` — validates expression, saves, logs to AuditLogger
  - `update(StoreCronJobRequest $request, int $id)` — validates, updates, logs
  - `destroy(int $id)` — deletes, logs
  - `logs(int $id, Request $request)` — last 50 run logs, filterable by `status`
  - `pause(int $id)` / `resume(int $id)` — toggle status field
- [~] Create `app/Http/Requests/Admin/StoreCronJobRequest.php`
  - Validates: `name` unique in cron_jobs (except on update), `cron_expression` via `CronSchedulerService::validateExpression()`, `command` required string
- [ ] Register routes:
  ```
  GET    /admin/cron-jobs          → CronJobController@index
  POST   /admin/cron-jobs          → CronJobController@store
  PUT    /admin/cron-jobs/{id}     → CronJobController@update
  DELETE /admin/cron-jobs/{id}     → CronJobController@destroy
  GET    /admin/cron-jobs/{id}/logs → CronJobController@logs
  POST   /admin/cron-jobs/{id}/pause  → CronJobController@pause
  POST   /admin/cron-jobs/{id}/resume → CronJobController@resume
  ```

---

### Task 11 — Roles and Permissions Controller
**Depends on:** T3, T4  
**Requirements covered:** R7

- [~] Create `app/Http/Controllers/Api/Admin/RoleController.php`
  - `index()` — all roles with user count and permission list
  - `store(StoreRoleRequest $request)` — Super_Admin only; requires unique name + ≥1 permission
  - `update(StoreRoleRequest $request, int $id)` — Super_Admin only; invalidates permission cache for role
  - `destroy(int $id)` — checks `user_roles` count; returns 409 if users assigned; blocks `is_system=true` roles
  - `syncPermissions(Request $request, int $id)` — replaces role's permission set, invalidates cache
  - `assignUserRole(Request $request, int $userId)` — assigns/replaces user's single role
- [~] Create `app/Http/Controllers/Api/Admin/PermissionController.php`
  - `index()` — returns all permissions (for matrix render)
- [~] Create `app/Http/Requests/Admin/StoreRoleRequest.php`
  - Validates: `name` unique, `permission_ids` array min:1
- [ ] Register routes:
  ```
  GET    /admin/roles                   → RoleController@index
  POST   /admin/roles                   → RoleController@store          [super_admin]
  PUT    /admin/roles/{id}              → RoleController@update         [super_admin]
  DELETE /admin/roles/{id}              → RoleController@destroy        [super_admin]
  GET    /admin/permissions             → PermissionController@index
  POST   /admin/roles/{id}/permissions  → RoleController@syncPermissions [super_admin]
  POST   /admin/users/{id}/role         → RoleController@assignUserRole
  ```

---

### Task 12 — Audit Log Controller
**Depends on:** T4  
**Requirements covered:** R6

- [~] Create `app/Http/Controllers/Api/Admin/AuditLogController.php`
  - `index(Request $request)` — filters: `start_date`, `end_date`, `actor_user_id`, `action_type`, `resource_type`; all filters AND logic; paginate 50/page
  - `export(Request $request)` — same filters; generates CSV with columns matching schema; returns `Content-Disposition: attachment` within 30s for ≤10,000 rows
  - `structured(Request $request)` — reads daily log file, returns 100 most recent JSON lines, filterable by `level` and `service`
- [ ] Register routes:
  ```
  GET  /admin/audit-logs          → AuditLogController@index
  GET  /admin/audit-logs/export   → AuditLogController@export
  GET  /admin/logs                → AuditLogController@structured
  ```


---

## Phase 6 — Notification Engine

### Task 13 — NotificationEngine and Evaluation Command
**Depends on:** T4  
**Requirements covered:** R8

- [~] Create `app/Services/Admin/NotificationEngine.php`

  **`evaluate(): void`**
  - **Rule R1** — Query orders with `status != 'Matched'` AND `created_at < now() - 2 hours`; dispatch email + in-app to all users with role `Customer Service Manager` or `Administrator`
  - **Rule R2** — Query orders where `delivery_time < now()` (SLA breached); dispatch email-only to assigned account manager (`is_account_manager=true`); fallback to all Customer Service Managers if unassigned
  - **Rule R3** — Sum `order_value` of non-Matched orders for today where `delivery_time` has passed; if > 5,000,000 KES dispatch email-only to `Sales Operations` role + all `is_account_manager=true` users
  - **Rule R4** — Check `notification_dispatch_logs` for recent AI cycle completion event; dispatch in-app only to all users with any role
  - For each rule: check `is_enabled=true` before evaluating
  - Enforce 30-min cooldown per rule per recipient: skip dispatch if `notification_dispatch_logs` has `evaluated_at > now() - 30 min` for same `rule_id` + `recipient_user_id`
  - On each dispatch: create `notification_dispatch_logs` row with `delivery_status=queued`
  - On email failure after 3 attempts: update status to `failed`, create in-app alert for Administrator

- [~] Create `app/Notifications/CriticalOrderNotification.php` (email + database channels)
- [~] Create `app/Notifications/SlaBreachNotification.php` (email channel)
- [~] Create `app/Notifications/RevenueAtRiskNotification.php` (email channel)
- [~] Create `app/Notifications/AiCycleCompleteNotification.php` (database channel)
- [~] Create Artisan command `app/Console/Commands/EvaluateNotifications.php` that calls `NotificationEngine::evaluate()`
- [~] Register command in `routes/console.php` with `->everyFiveMinutes()`
- [~] Create `app/Http/Controllers/Api/Admin/NotificationRuleController.php`
  - `index()` — all four rules with `is_enabled`, `last_evaluated_at`, `last_triggered_at`
  - `update(int $id, Request $request)` — toggles `is_enabled`, calls `AuditLogger::log()`
- [ ] Register routes:
  ```
  GET  /admin/notification-rules      → NotificationRuleController@index
  PUT  /admin/notification-rules/{id} → NotificationRuleController@update
  ```

---

### Task 14 — Health Endpoint
**Depends on:** T5, T7, T9  
**Requirements covered:** R10

- [~] Create `app/Http/Controllers/Api/Admin/HealthController.php`
  - `index()` — returns JSON with connectivity status for: `outlook_oauth`, `openai`, `anthropic`, `acumatica`
  - Each key returns `{'status': 'connected|error|unchecked', 'last_checked_at': ...}`
  - Route: `GET /admin/health` guarded by `admin.view` permission
- [~] Add `created_at` / `updated_at` columns to verify migration ran:
  ```
  GET /admin/health → {"outlook_oauth":{"status":"unchecked"},...}
  ```


---

## Phase 7 — Frontend Implementation

### Task 15 — Shared Hooks, Schemas, and Types
**Depends on:** T6, T8, T9, T11, T12, T13  
**Requirements covered:** R10, R11

- [~] Create `src/lib/admin-schemas.ts` with Zod schemas:
  - `acumaticaSchema` — `base_url` url, `tenant` min:1, `username` min:1, `password` min:1, `client_id` optional string, `client_secret` optional string
  - `cronJobSchema` — `name` min:1 max:255, `cron_expression` matching 5-field UNIX regex, `command` min:1, `description` optional
  - `aiKeySchema` — `provider` enum(`openai`,`anthropic`), `key` min:20
  - `roleSchema` — `name` min:1 max:100, `permission_ids` array min:1
  - `mailboxConnectSchema` — callback validation
- [~] Create TypeScript types in `src/types/admin.ts`:
  - `MailboxAccount`, `AiApiKey`, `AcumaticaConfig`, `AcumaticaSyncLog`, `CronJob`, `CronRunLog`, `AuditLogEntry`, `Role`, `Permission`, `NotificationRule`

---

### Task 16 — TanStack Query Hooks
**Depends on:** T15  
**Requirements covered:** R11

- [~] Create `src/hooks/admin/useMailboxes.ts` — `useMailboxes()`, `useConnectMailbox()`, `useDisconnectMailbox()`, `useSyncMailbox()`
- [~] Create `src/hooks/admin/useAcumatica.ts` — `useAcumaticaConfig()`, `useUpdateAcumaticaConfig()`, `useValidateAcumatica()`, `useAcumaticaSyncNow()`
- [~] Create `src/hooks/admin/useAiKeys.ts` — `useAiKeys()`, `useSaveAiKey()`, `useDeleteAiKey()`
- [~] Create `src/hooks/admin/useCronJobs.ts` — `useCronJobs()`, `useCreateCronJob()`, `useUpdateCronJob()`, `useDeleteCronJob()`, `useCronJobLogs()`, `usePauseCronJob()`, `useResumeCronJob()`
- [~] Create `src/hooks/admin/useAuditLogs.ts` — `useAuditLogs(filters)`, `useExportAuditLogs(filters)`
- [~] Create `src/hooks/admin/useRoles.ts` — `useRoles()`, `useCreateRole()`, `useUpdateRole()`, `useDeleteRole()`, `useSyncPermissions()`, `useAssignUserRole()`
- [~] Create `src/hooks/admin/useNotificationRules.ts` — `useNotificationRules()`, `useToggleNotificationRule()`
- [~] Each hook: `onError: (err: ApiError) => toast.error(err.message)`, `onSuccess: () => queryClient.invalidateQueries(...)`

---

### Task 17 — Admin Panel Components
**Depends on:** T16  
**Requirements covered:** R1–R8, R11

- [~] Create `src/components/admin/MailboxesPanel.tsx`
  - Lists each connected mailbox by email address with status badge and last sync time
  - "Connect Mailbox" button → calls `/admin/mailboxes/oauth/redirect`, opens OAuth popup
  - Per-mailbox: Sync Now button, Disconnect button (with confirm dialog)
  - Skeleton loading state matching panel grid
  - Empty state with retry button on error

- [~] Create `src/components/admin/AcumaticaPanel.tsx`
  - Shows current config: Base URL (`https://kimfay.acumatica.com`), Endpoint (`IpayV2`), Version (`22.200.001`), Tenant (`Kim-Fay Limited`)
  - Editable form using `acumaticaSchema` Zod validation
  - "Validate Credentials" button → calls POST `/admin/acumatica/validate`, shows response_ms
  - "Sync Now" button → calls POST `/admin/acumatica/sync`
  - Last 20 sync history entries table with status badges
  - Health status indicator (connected / error / unchecked)

- [~] Create `src/components/admin/AiConnectorsPanel.tsx`
  - Per-provider card (OpenAI, Anthropic): source badge, masked key preview, last-used timestamp, health status
  - "Update Key" form using `aiKeySchema` with masked input field
  - Delete key button (returns to env-var fallback)
  - Skeleton + empty state

- [~] Create `src/components/admin/CronJobsPanel.tsx`
  - Table of cron jobs: name, expression, next run, last run status, status badge
  - Create/Edit form using `cronJobSchema` with inline cron expression validation hint
  - Pause / Resume / Delete actions per row
  - Expandable run log drawer (last 50 entries, filter by success/failure)

- [~] Create `src/components/admin/AuditLogsPanel.tsx`
  - Filter bar: date range picker, actor user select, action type select, resource type select
  - Results table paginated at 50/page with total count
  - Export CSV button → triggers file download via `/admin/audit-logs/export`

- [~] Create `src/components/admin/RolesPanel.tsx`
  - Table of roles with user count
  - Create role form (Super_Admin only) using `roleSchema`
  - Delete role button — shows 409 warning with affected user count
  - Assign role to user form

- [~] Create `src/components/admin/PermissionsMatrix.tsx`
  - Full role × permission matrix table
  - Toggle cells (Super_Admin only can toggle)
  - Calls `POST /admin/roles/{id}/permissions` on toggle

- [~] Create `src/components/admin/NotificationRulesPanel.tsx`
  - Lists all 4 rules with enabled/disabled Switch
  - Shows last evaluated and last triggered timestamps
  - Toggle calls `PUT /admin/notification-rules/{id}`, shows toast

---

### Task 18 — Refactor app.administration.tsx
**Depends on:** T17  
**Requirements covered:** R11

- [~] Remove `AUDIT_LOGS` import from `demo-data.ts`
- [~] Remove hardcoded `ROLES`, `PERMS` arrays
- [~] Replace each `TabsContent` with corresponding panel component: `<MailboxesPanel />`, `<AcumaticaPanel />`, `<AiConnectorsPanel />`, `<CronJobsPanel />`, `<AuditLogsPanel />`, `<RolesPanel />`, `<PermissionsMatrix />`, `<NotificationRulesPanel />`
- [~] Add `<AI Connectors>` tab trigger (rename `openai` to `ai-connectors` to cover both OpenAI + Anthropic)
- [~] Wrap entire admin page in a permission check — redirect non-admins to dashboard
- [~] Remove unused `Button` import that is currently flagged as a lint warning


---

## Phase 8 — Demo Data Migration

### Task 19 — Migrate All Routes Off demo-data.ts
**Depends on:** T18  
**Requirements covered:** R11

For each frontend route that currently imports from `demo-data.ts`, wire it to the live API. The existing `apiFetch` client in `src/lib/api.ts` must be used for all calls.

- [~] `app.index.tsx` (dashboard) — replace `getKpis()`, `TREND`, `ACTIVITY`, `ESCALATIONS`, `AI_RECOMMENDATIONS` with:
  - `GET /dashboard/kpis` → KPI metrics
  - `GET /dashboard/trend?days=14` → 14-day trend data
  - `GET /dashboard/activity` → activity feed
  - `GET /orders?status=Escalated&per_page=5` → escalations
  - `GET /ai-insights/recommendations` → AI recommendations

- [~] `app.orders.tsx` — replace `ORDERS` with `GET /orders` (paginated, filterable)
  - Backend `OrderController@index` must accept: `status`, `customer`, `priority`, `sla_status`, `search`, `per_page`, `page` query params
  - Response envelope: `{data: [...], meta: {current_page, last_page, per_page, total}}`

- [~] `app.customers.tsx` — replace `CUSTOMERS` with `GET /customers` (paginated)

- [~] `app.notifications.tsx` — replace `NOTIFICATIONS` with `GET /notifications` (paginated)
  - Mark-as-read: `PATCH /notifications/{id}/read`

- [~] `app.ai-insights.tsx` — replace `AI_RECOMMENDATIONS` with `GET /ai-insights`

- [~] `app.reports.tsx` — replace any demo data with `GET /reports/summary`

- [~] Add `GET /dashboard/kpis`, `GET /dashboard/trend`, `GET /dashboard/activity` endpoints to `DashboardController.php` (or create `DashboardController`)
  - These endpoints must initially return data synthesized from real `orders` table rows so pages work end-to-end before Acumatica sync populates data

- [~] Implement skeleton loading state (`<Skeleton>`) in every route while API request is in flight
- [~] Implement empty state with retry button on API error — no fallback to demo-data
- [~] After all routes are migrated: remove all imports from `src/lib/demo-data.ts`
- [~] Do NOT delete `demo-data.ts` until all imports are removed and verified (keep as reference until T20 passes)

---

## Phase 9 — Testing and Verification

### Task 20 — End-to-End Tests and Verification
**Depends on:** T19  
**Requirements covered:** R8, R9, R10, R11, R12

**Encryption verification:**
- [~] Write `EncryptionIntegrationTest.php`: verify that `mailbox_accounts.access_token_encrypted` column stores non-plaintext value; verify decrypt returns original
- [~] Verify `ai_api_keys.key_encrypted` stores ciphertext; API response only returns masked preview

**Permission control verification:**
- [~] Write `PermissionMiddlewareTest.php`:
  - Test: non-admin user receives 403 on all `/admin/*` routes
  - Test: admin without `roles.manage` permission receives 403 on role create/delete
  - Test: super_admin can access all routes including role manage
  - Test: user without role receives 403

**Notification engine verification:**
- [~] Write `NotificationEngineTest.php`:
  - Test R1: seed order with `status=Missing`, `created_at = now()-3h`; run `evaluate()`; assert email + in-app dispatch records created for CSM/Admin users
  - Test R2: seed order with `delivery_time = yesterday`; run `evaluate()`; assert email-only dispatch to assigned account manager
  - Test R3: seed orders with total value > 5,000,000 KES non-Matched with past delivery; run `evaluate()`; assert email-only to Sales Ops users
  - Test R4: manually set `last_triggered_at` for AI rule; run `evaluate()`; assert in-app dispatch only
  - Test cooldown: run `evaluate()` twice within 30 min for same rule/recipient; assert only one dispatch log created

**Acumatica API integration verification:**
- [~] Write `AcumaticaServiceTest.php` (using HTTP fake/mock):
  - Test: `authenticate()` POSTs to `https://kimfay.acumatica.com/identity/connect/token` with correct form body (grant_type=password, client_id, client_secret, username, password, scope=api)
  - Test: `fetchSalesOrders()` GETs `https://kimfay.acumatica.com/entity/IpayV2/22.200.001/SalesOrder/?$expand=CustomerDetails,DocumentDetails,PaymentDetails`
  - Test: optional `$filter` param is appended correctly (e.g., `OrderNbr eq 'SO358387'`)
  - Test: auth failure returns structured error, does not throw

**Demo data migration verification:**
- [~] Confirm zero `demo-data.ts` imports remain in any route file after migration (grep check)
- [~] Confirm all pages render skeleton states while API is loading
- [~] Confirm empty state component renders (not blank page) when API returns error

**Cron job validation:**
- [~] Write `CronSchedulerTest.php`:
  - Test: valid expression `0 * * * *` passes validation
  - Test: invalid expression `99 * * *` returns 422
  - Test: duplicate name returns 422
  - Test: paused job skips scheduled execution


---

## Appendix — Environment Variables Reference

These must be added to `backend/.env.example` and configured in `backend/.env` before implementation begins:

```ini
# Acumatica — OAuth2 password grant
ACUMATICA_BASE_URL=https://kimfay.acumatica.com
ACUMATICA_TOKEN_URL=https://kimfay.acumatica.com/identity/connect/token
ACUMATICA_CLIENT_ID=B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited
ACUMATICA_CLIENT_SECRET=
ACUMATICA_USERNAME=ipay
ACUMATICA_PASSWORD=
ACUMATICA_ENDPOINT=IpayV2
ACUMATICA_VERSION=22.200.001

# Microsoft OAuth (Outlook)
MICROSOFT_CLIENT_ID=
MICROSOFT_CLIENT_SECRET=
MICROSOFT_TENANT_ID=common
MICROSOFT_REDIRECT_URI=https://kim-fay-orderwatch.tools/backend/public/api/admin/mailboxes/oauth/callback

# AI Providers
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
```

## Appendix — Acumatica API Contract

**Token Request (POST)**
```
URL:   https://kimfay.acumatica.com/identity/connect/token
Body:  grant_type=password
       client_id=B86BC41A-1183-A796-BD0E-64DB1C8F8103@Kim-Fay Limited
       client_secret={secret}
       username=ipay
       password={password}
       scope=api
```

**Sales Order Fetch (GET)**
```
URL:   https://kimfay.acumatica.com/entity/IpayV2/22.200.001/SalesOrder/
Query: $expand=CustomerDetails,DocumentDetails,PaymentDetails
       $filter=OrderNbr eq 'SO358387'          (optional — by order number)
       $filter=CustomerID eq 'CUST101239'       (optional — by customer)
Auth:  Authorization: Bearer {access_token}
```

All credentials stored in `acumatica_configs` encrypted via `EncryptionService`. The `client_id` string includes the tenant name (`@Kim-Fay Limited`) as part of the value.
