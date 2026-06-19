# Design Document — Admin Connectors Module

## Overview

The Admin Connectors Module replaces all static `demo-data.ts` datasets with live API-driven data flows and delivers seven production-ready sub-systems on top of the existing Laravel 13 (PHP 8.3) + React 19 / TanStack Router / Vite TypeScript stack.

The backend is a JSON API secured with Laravel Sanctum Bearer tokens. The frontend uses TanStack Query (`@tanstack/react-query`) for server-state management, Zod for client-side validation, and existing shadcn/ui primitives. All sensitive credentials are encrypted at rest via Laravel's `encrypt()`/`decrypt()` helpers (AES-256-CBC + HMAC-SHA-256).

---

## Architecture

### High-Level Layers

```
┌─────────────────────────────────────────────────────────────┐
│  React Frontend  (TanStack Router + TanStack Query + Zod)   │
│  src/routes/app.administration.tsx  + sub-panels            │
└───────────────────────┬─────────────────────────────────────┘
                        │ HTTPS / Sanctum Bearer Token
┌───────────────────────▼─────────────────────────────────────┐
│  Laravel 13 API  (routes/api.php)                           │
│  App\Http\Controllers\Api\Admin\*                           │
│  App\Http\Middleware\RequiresPermission                     │
└───────────────────────┬─────────────────────────────────────┘
                        │
┌───────────────────────▼─────────────────────────────────────┐
│  Service Layer  (app/Services/Admin/*)                      │
│  OutlookService · AiConnectorService · AcumaticaService     │
│  CronSchedulerService · AuditLogger · EncryptionService     │
│  NotificationEngine · PermissionManager                     │
└───────────────────────┬─────────────────────────────────────┘
                        │
┌───────────────────────▼─────────────────────────────────────┐
│  Database  (SQLite dev / MySQL prod)  +  Queue (database)   │
└─────────────────────────────────────────────────────────────┘
```

### New Package Dependencies

**Composer (backend):**
```
microsoft/microsoft-graph          ^2.0   — Microsoft Graph SDK
league/oauth2-client               ^2.7   — OAuth 2.0 abstract client
openai-php/client                  ^0.10  — OpenAI PHP SDK
anthropic-sdk-php/anthropic-php    ^0.2   — Anthropic PHP SDK
```
*(All locked to exact minor versions in composer.lock)*

**npm (frontend):** No new packages required. Zod, TanStack Query, and shadcn/ui are already present.

---

## Database Schema

### New Tables

#### `mailbox_accounts`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | auto-increment |
| email | VARCHAR(255) | unique, the mailbox address |
| display_name | VARCHAR(255) | nullable |
| access_token_encrypted | TEXT | AES-256-CBC via encrypt() |
| refresh_token_encrypted | TEXT | AES-256-CBC via encrypt() |
| token_expires_at | TIMESTAMP | nullable |
| status | ENUM('connected','reconnect_required','disconnected') | default connected |
| last_synced_at | TIMESTAMP | nullable |
| delta_token | TEXT | nullable, Microsoft Graph delta link |
| created_at / updated_at | TIMESTAMPS | |

#### `mailbox_sync_logs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| mailbox_account_id | FK → mailbox_accounts | |
| started_at | TIMESTAMP | |
| ended_at | TIMESTAMP | nullable |
| emails_fetched | INT | default 0 |
| status | ENUM('running','completed','failed') | |
| error_message | TEXT | nullable |

#### `ai_api_keys`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| provider | ENUM('openai','anthropic') | unique per provider |
| key_encrypted | TEXT | AES-256-CBC |
| created_by | FK → users | nullable |
| last_used_at | TIMESTAMP | nullable |
| health_status | ENUM('healthy','rate_limited','error') | default healthy |
| created_at / updated_at | TIMESTAMPS | |

#### `acumatica_configs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| base_url | VARCHAR(500) | |
| tenant | VARCHAR(255) | |
| username | VARCHAR(255) | |
| password_encrypted | TEXT | AES-256-CBC |
| client_id_encrypted | TEXT | nullable |
| client_secret_encrypted | TEXT | nullable |
| endpoint_version | VARCHAR(50) | nullable, cached metadata |
| last_validated_at | TIMESTAMP | nullable |
| health_status | ENUM('connected','error','unchecked') | default unchecked |
| created_at / updated_at | TIMESTAMPS | |

#### `acumatica_sync_logs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| sync_type | ENUM('sales_orders','customers') | |
| started_at | TIMESTAMP | |
| ended_at | TIMESTAMP | nullable |
| record_count | INT | default 0 |
| status | ENUM('running','completed','failed') | |
| error_message | TEXT | nullable |

#### `cron_jobs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR(255) | unique |
| description | TEXT | nullable |
| cron_expression | VARCHAR(100) | validated 5-field UNIX cron |
| command | VARCHAR(1000) | Artisan command or class |
| status | ENUM('active','paused') | default active |
| last_run_at | TIMESTAMP | nullable |
| last_run_status | ENUM('success','failure') | nullable |
| next_run_at | TIMESTAMP | nullable, calculated server-side |
| created_at / updated_at | TIMESTAMPS | |

#### `cron_run_logs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| cron_job_id | FK → cron_jobs | cascade delete |
| scheduled_at | TIMESTAMP | |
| started_at | TIMESTAMP | |
| ended_at | TIMESTAMP | nullable |
| status | ENUM('success','failure') | |
| output | TEXT | first 500 chars of stdout/stderr |

#### `audit_logs`
| Column | Type | Notes |
|---|---|---|
| id | CHAR(36) PK | UUID v4 |
| timestamp | TIMESTAMP(6) | UTC, microsecond precision; no `updated_at` column |
| actor_user_id | FK → users | nullable (system events) |
| actor_ip | VARCHAR(45) | nullable |
| action_type | VARCHAR(100) | enum-like string e.g. `mailbox_connected` |
| resource_type | VARCHAR(100) | e.g. `mailbox_account` |
| resource_id | VARCHAR(255) | nullable |
| changes | JSON | before/after values, credentials masked |

*No `updated_at` column — append-only guaranteed by absence of update routes.*

#### `roles`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR(100) | unique |
| description | TEXT | nullable |
| is_system | BOOLEAN | default false; system roles cannot be deleted |
| created_at / updated_at | TIMESTAMPS | |

#### `permissions`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| name | VARCHAR(100) | unique, dot-notation e.g. `admin.api_keys` |
| description | TEXT | nullable |
| created_at / updated_at | TIMESTAMPS | |

#### `role_permissions` (pivot)
| Column | Type |
|---|---|
| role_id | FK → roles |
| permission_id | FK → permissions |

#### `user_roles`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| user_id | FK → users | unique (one role per user) |
| role_id | FK → roles | |
| assigned_by | FK → users | nullable |
| created_at / updated_at | TIMESTAMPS | |

> `users` table gains one new column: `is_super_admin BOOLEAN DEFAULT false`.

#### `notification_rules`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| rule_key | VARCHAR(50) | unique: R1, R2, R3, R4 |
| label | VARCHAR(255) | human-readable name |
| channels | JSON | array: `["email","in_app"]` |
| is_enabled | BOOLEAN | default true |
| last_evaluated_at | TIMESTAMP | nullable |
| last_triggered_at | TIMESTAMP | nullable |
| created_at / updated_at | TIMESTAMPS | |

#### `notification_dispatch_logs`
| Column | Type | Notes |
|---|---|---|
| id | BIGINT PK | |
| rule_id | FK → notification_rules | |
| evaluated_at | TIMESTAMP | |
| channel | ENUM('email','in_app') | |
| recipient_user_id | FK → users | |
| delivery_status | ENUM('queued','delivered','failed') | |
| created_at / updated_at | TIMESTAMPS | |

---

## Backend Implementation

### Directory Structure

```
backend/app/
├── Http/
│   ├── Controllers/Api/Admin/
│   │   ├── MailboxController.php
│   │   ├── AiConnectorController.php
│   │   ├── AcumaticaController.php
│   │   ├── CronJobController.php
│   │   ├── AuditLogController.php
│   │   ├── RoleController.php
│   │   ├── PermissionController.php
│   │   ├── NotificationRuleController.php
│   │   └── HealthController.php
│   ├── Middleware/
│   │   └── RequiresPermission.php
│   └── Requests/Admin/
│       ├── StoreMailboxRequest.php
│       ├── StoreAiKeyRequest.php
│       ├── StoreAcumaticaConfigRequest.php
│       ├── StoreCronJobRequest.php
│       ├── StoreRoleRequest.php
│       └── StoreNotificationRuleRequest.php
├── Models/
│   ├── MailboxAccount.php
│   ├── MailboxSyncLog.php
│   ├── AiApiKey.php
│   ├── AcumaticaConfig.php
│   ├── AcumaticaSyncLog.php
│   ├── CronJob.php
│   ├── CronRunLog.php
│   ├── AuditLog.php
│   ├── Role.php
│   ├── Permission.php
│   ├── UserRole.php
│   ├── NotificationRule.php
│   └── NotificationDispatchLog.php
└── Services/Admin/
    ├── OutlookService.php
    ├── AiConnectorService.php
    ├── AcumaticaService.php
    ├── CronSchedulerService.php
    ├── AuditLogger.php
    ├── EncryptionService.php
    ├── PermissionManager.php
    └── NotificationEngine.php
```

### API Routes (`routes/api.php` additions)

All routes are prefixed `/admin` and guarded by `auth:sanctum` + `RequiresPermission` middleware.

```
GET    /admin/health                             → HealthController@index
GET    /admin/logs                               → AuditLogController@structured

# Mailboxes
GET    /admin/mailboxes                          → MailboxController@index
POST   /admin/mailboxes/oauth/redirect           → MailboxController@oauthRedirect
GET    /admin/mailboxes/oauth/callback           → MailboxController@oauthCallback
DELETE /admin/mailboxes/{id}                     → MailboxController@destroy
POST   /admin/mailboxes/{id}/sync                → MailboxController@sync

# AI Connectors
GET    /admin/ai-keys                            → AiConnectorController@index
POST   /admin/ai-keys                            → AiConnectorController@store
DELETE /admin/ai-keys/{id}                       → AiConnectorController@destroy

# Acumatica
GET    /admin/acumatica                          → AcumaticaController@index
PUT    /admin/acumatica                          → AcumaticaController@update
POST   /admin/acumatica/validate                 → AcumaticaController@validate

# CRON Jobs
GET    /admin/cron-jobs                          → CronJobController@index
POST   /admin/cron-jobs                          → CronJobController@store
PUT    /admin/cron-jobs/{id}                     → CronJobController@update
DELETE /admin/cron-jobs/{id}                     → CronJobController@destroy
GET    /admin/cron-jobs/{id}/logs                → CronJobController@logs

# Audit Logs
GET    /admin/audit-logs                         → AuditLogController@index
GET    /admin/audit-logs/export                  → AuditLogController@export

# Roles & Permissions
GET    /admin/roles                              → RoleController@index
POST   /admin/roles                              → RoleController@store
PUT    /admin/roles/{id}                         → RoleController@update
DELETE /admin/roles/{id}                         → RoleController@destroy
GET    /admin/permissions                        → PermissionController@index
POST   /admin/roles/{id}/permissions             → RoleController@syncPermissions
POST   /admin/users/{id}/role                    → RoleController@assignUserRole

# Notification Rules
GET    /admin/notification-rules                 → NotificationRuleController@index
PUT    /admin/notification-rules/{id}            → NotificationRuleController@update
```

### Middleware: `RequiresPermission`

```php
// Resolves the permission slug from a route name map, then checks:
// 1. User has an active role in user_roles
// 2. That role has the required permission in role_permissions
// 3. Super-admin actions additionally check users.is_super_admin === true
// Returns 403 JSON on failure.
```

Permission slugs seeded in `permissions` table:
```
admin.view           admin.api_keys        admin.cron_jobs
mailboxes.connect    mailboxes.disconnect  mailboxes.view
acumatica.view       acumatica.config      acumatica.validate
ai.view              ai.keys               ai.regenerate
audit.view           audit.export
roles.view           roles.manage          permissions.manage
notifications.view   notifications.manage
orders.view          orders.assign         orders.resolve
orders.escalate      customers.manage      reports.export
```

---

### Service: `EncryptionService`

Wraps Laravel `encrypt()` / `decrypt()`. All credential writes pass through this service.

```php
class EncryptionService
{
    public function encrypt(string $plaintext): string
    // Returns Laravel serialized ciphertext string

    public function decrypt(string $ciphertext): ?string
    // Returns null (+ structured log entry) on DecryptException

    public function mask(string $value, int $visibleChars = 7): string
    // Returns first $visibleChars chars + "…[masked]"

    public function maskCredential(string $value): string
    // 4 chars visible, used for audit log changes field
}
```

---

### Service: `OutlookService`

**OAuth 2.0 flow** uses `league/oauth2-client` with a Microsoft provider:

1. `getAuthorizationUrl()` — builds redirect URL with scopes `Mail.Read offline_access`.
2. `exchangeCode(string $code): array` — exchanges auth code → tokens within 10 s (HTTP timeout).
3. `refreshToken(MailboxAccount $account): void` — uses stored refresh token; on failure sets `status = reconnect_required`.
4. `syncMailbox(MailboxAccount $account): void` — calls Graph `/me/mailFolders` then `/me/mailFolders/{id}/messages/delta` with `$deltaToken`. Implements 429 back-off (reads `Retry-After` header) and 3× exponential retry on 5xx.

**Token storage:** Access and refresh tokens encrypted before insert via `EncryptionService`. The `delta_token` column stores the opaque Graph delta link (not a credential, stored plain).

---

### Service: `AiConnectorService`

```php
class AiConnectorService
{
    public function getKeyForProvider(string $provider): string
    // Prefers DB record (decrypted) over env var

    public function callWithRetry(string $provider, callable $fn): mixed
    // Exponential backoff: start 1s, max 32s, up to 5 retries on 429
    // Returns structured error on final failure

    public function maskKey(string $key): string
    // First 7 chars + "…[masked]"
}
```

---

### Service: `AcumaticaService`

```php
class AcumaticaService
{
    public function validateCredentials(): array
    // HTTP timeout 15s; returns ['success'=>bool, 'message'=>string, 'response_ms'=>int]

    public function getHealth(): array
    // Returns connection health + last call timestamp

    public function fetchMetadata(): void
    // Caches tenant, endpoint_version, active_company_count into acumatica_configs
}
```

---

### Service: `CronSchedulerService`

```php
class CronSchedulerService
{
    public function validateExpression(string $expr): bool
    // Uses regex for 5-field UNIX cron; throws ValidationException on failure

    public function calculateNextRun(string $expr): Carbon
    // Uses cron-expression parser to compute next execution time

    public function recordRunLog(CronJob $job, string $status, string $output): void
}
```

Laravel's `schedule()` mechanism in `console.php` dynamically reads all `active` `cron_jobs` rows and registers each via `$schedule->call(fn)→cron($job->cron_expression)`.

---

### Service: `AuditLogger`

```php
class AuditLogger
{
    public function log(
        string $actionType,
        string $resourceType,
        string|int|null $resourceId,
        array $changes = [],     // before/after, credentials auto-masked
        ?int $actorUserId = null,
        ?string $actorIp = null,
    ): void
    // Inserts one row into audit_logs with UUID id and UTC microsecond timestamp
    // Never throws — wraps DB::insert in try/catch, logs to file on failure
}
```

---

### Service: `NotificationEngine`

Runs every 5 minutes via a scheduled Artisan command `notifications:evaluate`.

```php
class NotificationEngine
{
    public function evaluate(): void
    // Checks R1–R4 conditions, dispatches queued Laravel Notifications
    // Enforces 30-min re-trigger cooldown per rule per recipient
    // Records notification_dispatch_logs entries
}
```

Notification channels used:
- **email** — Laravel `Mail` facade → existing `MAIL_*` config.
- **in_app** — inserts a row into `notifications` table (Laravel database notification channel).

---

## Structured Logging

All services write JSON log entries via a dedicated `StructuredLogger` utility:

```php
class StructuredLogger
{
    public static function write(
        string $level,       // debug|info|warning|error
        string $service,     // 'outlook'|'openai'|'anthropic'|'acumatica'|'cron'|'auth'
        string $event,       // e.g. 'token_refresh_failed'
        array $context = [],
        ?int $userId = null,
        ?string $ip = null,
    ): void
    // Writes to Laravel 'daily' log channel as JSON:
    // { timestamp, level, service, event, user_id, ip_address, context }
}
```

The `/api/admin/logs` endpoint queries the daily log file, parses JSON lines, and returns the 100 most recent entries filterable by `level` and `service`.

---

## Frontend Implementation

### Component Structure

```
src/
├── routes/
│   └── app.administration.tsx        ← refactored to use live API + sub-panels
├── components/admin/
│   ├── MailboxesPanel.tsx
│   ├── AiConnectorsPanel.tsx
│   ├── AcumaticaPanel.tsx
│   ├── CronJobsPanel.tsx
│   ├── AuditLogsPanel.tsx
│   ├── RolesPanel.tsx
│   ├── PermissionsMatrix.tsx
│   └── NotificationRulesPanel.tsx
├── hooks/admin/
│   ├── useMailboxes.ts
│   ├── useAiKeys.ts
│   ├── useAcumatica.ts
│   ├── useCronJobs.ts
│   ├── useAuditLogs.ts
│   ├── useRoles.ts
│   └── useNotificationRules.ts
└── lib/
    └── admin-schemas.ts              ← Zod schemas for all admin forms
```

### TanStack Query Hooks Pattern

Each `useXxx` hook follows this pattern:

```typescript
// Example: useMailboxes.ts
export function useMailboxes() {
  return useQuery({
    queryKey: ['admin', 'mailboxes'],
    queryFn: () => apiFetch<MailboxAccount[]>('/admin/mailboxes'),
  });
}

export function useDisconnectMailbox() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (id: number) => apiFetch(`/admin/mailboxes/${id}`, { method: 'DELETE' }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['admin', 'mailboxes'] });
      toast.success('Mailbox disconnected');
    },
    onError: (err: ApiError) => toast.error(err.message),
  });
}
```

### Zod Validation Schemas (`admin-schemas.ts`)

```typescript
export const cronJobSchema = z.object({
  name: z.string().min(1).max(255),
  description: z.string().optional(),
  cron_expression: z
    .string()
    .regex(/^(\*|[0-9,\-\/]+)\s+(\*|[0-9,\-\/]+)\s+(\*|[0-9,\-\/]+)\s+(\*|[0-9,\-\/]+)\s+(\*|[0-9,\-\/]+)$/, 'Invalid cron expression'),
  command: z.string().min(1),
});

export const aiKeySchema = z.object({
  provider: z.enum(['openai', 'anthropic']),
  key: z.string().min(20),
});

export const acumaticaSchema = z.object({
  base_url: z.string().url(),
  tenant: z.string().min(1),
  username: z.string().min(1),
  password: z.string().min(1),
});

export const roleSchema = z.object({
  name: z.string().min(1).max(100),
  description: z.string().optional(),
  permission_ids: z.array(z.number()).min(1, 'At least one permission required'),
});
```

### Loading and Error States

- Every panel wraps its content in a `<Suspense>` boundary with a `<Skeleton>` fallback matching the panel's grid layout.
- On API error, panels render an `<EmptyState>` component with the `ApiError.message` and a `<Button onClick={() => refetch()}>Retry</Button>`.
- `demo-data.ts` imports are removed from all routes once live endpoints are wired.
