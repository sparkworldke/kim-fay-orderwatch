# Admin Impersonation — Implementation Guide

**Product:** OrderWatch (Kim-Fay)  
**Module:** Administration · Auth  
**Status:** Implemented  
**Last updated:** 12 Jul 2026  

---

## 1. Purpose

Administrators can temporarily act as another active user (e.g. CCO, Beatrice, Shirleen, a Sales Consultant) **without logging out and signing in with a different password**. Used for support, UAT, and FOL / role-path testing.

---

## 2. Who can use it

| Actor | Can start | Can stop |
|--------|-----------|----------|
| **Administrator** (`role = Administrator`) | Yes | Yes (return to admin) |
| Super admin flag (`is_super_admin`) | Yes (controller check) | Yes |
| All other roles | No (403) | N/A |

Stop works while the token is the **impersonated** user (not under `admin.only`).

---

## 3. User experience

### 3.1 Start

1. Sign in as Administrator.  
2. Open **Administration → Impersonation**.  
3. Search by name, email, role, or rep code (shortcuts: Find CCO / Beatrice / Shirleen / Sales Consultant / Technician).  
4. Click **Login as**.  
5. Session switches to the target user; app reloads to `/app` with their menus and permissions.

**Also:** **Team Members** table has a **Login as** button (active users only, not self).

### 3.2 While impersonating

- Amber **banner**: “Viewing as {name} ({role}) · switched by {admin}”.  
- Header badge **Impersonating**; avatar tinted amber.  
- User menu shows real admin and **Return to admin**.

### 3.3 Stop

- Banner **Return to admin**, or user menu **Return to admin**.  
- Fresh admin token is issued; navigates to **Administration**.

Session duration for impersonation token: **4 hours**.

---

## 4. API

| Method | Path | Middleware | Description |
|--------|------|------------|-------------|
| `GET` | `/api/admin/impersonate/candidates?q=` | `auth:sanctum` + `admin.only` | Active users (limit 50), search optional |
| `POST` | `/api/admin/impersonate` | `auth:sanctum` + `admin.only` | Body: `{ "user_id": number }` → new target token |
| `POST` | `/api/auth/impersonate/stop` | `auth:sanctum` (not admin.only) | End impersonation → fresh admin token |
| `GET` | `/api/auth/me` | `auth:sanctum` | Includes `impersonation.active` + `impersonator` |

### 4.1 Start response (shape)

```json
{
  "token": "<plainTextToken>",
  "user": { "id", "name", "email", "role", "..." },
  "capabilities": { },
  "impersonation": {
    "active": true,
    "impersonator": { "id", "name", "email", "role" },
    "expires_in_hours": 4
  }
}
```

### 4.2 Token model

Sanctum personal access token on the **target** user:

- Name: `impersonation`  
- Abilities: `impersonated`, `impersonator:{adminUserId}`  
- Expiry: 4 hours  

Stop reads `impersonator:{id}`, deletes the impersonation token, issues a new admin `api-token` (8 hours).

### 4.3 View-only middleware

`POST api/auth/impersonate/stop` is allow-listed in `ViewOnlyUnlessPrivileged` so non-CS roles can return to admin while impersonating.

---

## 5. Audit

| Action | Resource | Notes |
|--------|----------|--------|
| `impersonation_started` | `user` / target id | admin id/email, target id/email/role |
| `impersonation_stopped` | `user` / was-user id | admin id/email, was_user id/email |

---

## 6. Frontend files

| File | Role |
|------|------|
| `src/lib/auth.ts` | Session `is_impersonating`, `impersonator`; `applyAuthResponse`, `syncSessionFromMe` |
| `src/hooks/admin/useImpersonation.ts` | Candidates, start, stop; clears React Query on switch |
| `src/components/impersonation-banner.tsx` | Sticky amber banner |
| `src/components/app-header.tsx` | Badge + Return to admin menu item |
| `src/routes/app.tsx` | Syncs `auth/me` impersonation flags on load |
| `src/routes/app.administration.tsx` | Impersonation tab + Team **Login as** |
| `src/lib/nav-permissions.ts` | Tab perm `impersonation` admin-only |

---

## 7. Backend files

| File | Role |
|------|------|
| `backend/app/Http/Controllers/Api/Admin/ImpersonationController.php` | candidates / start / stop |
| `backend/app/Http/Controllers/Api/AuthController.php` | `me` → impersonation payload |
| `backend/routes/api.php` | Route registration |
| `backend/app/Http/Middleware/ViewOnlyUnlessPrivileged.php` | stop exception |
| `backend/tests/Feature/ImpersonationTest.php` | Start/stop + candidates admin-only |

---

## 8. Guardrails

| Rule | Behaviour |
|------|-----------|
| Admin only start | 403 otherwise |
| Cannot nest impersonation | 422 if already impersonating |
| Cannot impersonate inactive user | 422 |
| Cannot impersonate self | 422 |
| Stop without ability | 422 Not currently impersonating |
| Original admin inactive / demoted | 403; impersonation token deleted |

---

## 9. How to test

1. Login as Administrator.  
2. Administration → Impersonation → **Login as** a consultant or CCO.  
3. Confirm menus/data match that role.  
4. **Return to admin** → Administration again.  
5. Optional: `php artisan test --filter=ImpersonationTest` (backend).

---

## 10. Related

- Team / roles: `docs/team-module-guide.md`  
- FOL testing as HOD/CCO/tech without multi-login: use this feature with `docs/fol-technician-calendar.md`
