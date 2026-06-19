# Design Document — OTP Passwordless Authentication

## Overview

Replaces the client-side `inferRole` + `localStorage` session with a server-verified OTP flow backed by Laravel 13 + Sanctum. Users enter their email, receive a 6-digit OTP by email, optionally supply their password (toggle), and receive a Sanctum Bearer token. A Profile page exposes personal-info editing and a paginated sign-in history log.

---

## Architecture

```
Frontend (TanStack Start / React / Vite)
  └─ src/routes/auth.tsx          ← login page (email → OTP → token)
  └─ src/routes/app.profile.tsx   ← profile dashboard
  └─ src/lib/auth.ts              ← session helpers (token + user in localStorage)
  └─ src/lib/api.ts               ← apiFetch (auto-attaches token, handles 401)

Backend (Laravel 13 + Sanctum, MySQL)
  └─ routes/api.php
  └─ app/Http/Controllers/Api/
       OtpController.php          ← request + verify endpoints
       ProfileController.php      ← GET/PATCH profile, sign-in logs
       AuthController.php         ← logout + me (updated)
  └─ app/Models/
       Otp.php                    ← otps table
       SignInLog.php              ← sign_in_logs table
       User.php                   ← + phone_number, role
  └─ app/Mail/OtpMail.php
  └─ app/Console/Commands/PruneExpiredOtps.php
  └─ database/migrations/
       create_otps_table
       create_sign_in_logs_table
       add_phone_number_to_users_table
  └─ tests/
       Unit/OtpServiceTest.php
       Feature/OtpAuthTest.php
       Feature/ProfileTest.php
```

---

## Database Schema

### `otps` table
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | auto-increment |
| user_id | bigint FK → users.id | nullable (timing-safe: may be null) |
| email | varchar(255) | indexed |
| otp_hash | varchar(255) | bcrypt hash of plaintext OTP |
| expires_at | timestamp | now + 15 min |
| attempts | tinyint unsigned | default 0, max 5 |
| created_at | timestamp | |

### `sign_in_logs` table
| Column | Type | Notes |
|--------|------|-------|
| id | bigint PK | |
| user_id | bigint FK → users.id | nullable (failed unknown-email attempts) |
| email_hash | varchar(64) | SHA-256 of email — never plaintext |
| ip_address | varchar(45) | IPv4/IPv6 |
| user_agent | text | |
| login_mode | varchar(20) | `otp-only` or `otp-and-password` |
| status | varchar(10) | `success` or `failure` |
| created_at | timestamp | |

### `users` table additions
- `phone_number` varchar(20) nullable
- `role` varchar(50) default 'Administrator' (already added)

---

## API Endpoints

### Public

| Method | Path | Rate Limit | Description |
|--------|------|-----------|-------------|
| POST | `/api/auth/otp/request` | 5/10 min per IP+email | Generate & email OTP |
| POST | `/api/auth/otp/verify` | 10/15 min per IP+email | Verify OTP → token |
| POST | `/api/auth/logout` | — | Revoke token |

### Protected (`auth:sanctum`)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/auth/me` | Current user |
| GET | `/api/profile` | Profile data |
| PATCH | `/api/profile` | Update name / phone |
| GET | `/api/profile/sign-in-logs` | Own logs, paginated 20 |
| GET | `/api/admin/users/{user}/sign-in-logs` | Admin only |

---

## OTP Flow

```
1. POST /api/auth/otp/request { email }
   ├─ Rate-limit check (IP + email, 5/10min)
   ├─ Lookup user by email
   ├─ Delete any existing unexpired OTP for this email
   ├─ Generate: str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT)
   ├─ Hash:     Hash::make($otp)          ← bcrypt, plaintext never leaves memory
   ├─ Persist:  otps row with expires_at = now()+15min
   ├─ Dispatch: OtpMail::class (queued)
   └─ Return:   HTTP 200 { message: "If this email is registered, an OTP has been sent." }

2. POST /api/auth/otp/verify { email, otp, login_mode, password? }
   ├─ Rate-limit check (IP + email, 10/15min)
   ├─ Find otps row by email
   ├─ Check expiry → 422 "OTP has expired"
   ├─ Hash::check($otp, $otpRecord->otp_hash)
   │   ├─ Fail → increment attempts; if ≥5 → delete row + 429
   │   └─ Pass → continue
   ├─ If login_mode=otp-and-password → Hash::check($password, $user->password)
   │   └─ Fail → 422 "Invalid credentials" (no attempt consumed)
   ├─ Delete OTP row
   ├─ Revoke all existing tokens for user
   ├─ Create token: expires_at = now()+8h
   ├─ Record SignInLog (success)
   └─ Return: HTTP 200 { token, user: { id, name, email, role } }
```

---

## Session Storage (Frontend)

```
localStorage keys:
  kf_token    → Sanctum Bearer token (string)
  kf_session  → { id, name, email, role, loggedInAt } (JSON)
```

`apiFetch` auto-reads `kf_token` and sends `Authorization: Bearer <token>`.
On 401 response: clear both keys, redirect to `/auth`.

---

## Login Page UI

```
[Step 1 — Email]
  ┌─ Toggle: (●) OTP only  ○ OTP + Password ─┐
  │  Work email input                          │
  │  [Send verification code →]                │
  └───────────────────────────────────────────┘

[Step 2 — OTP (otp-only mode)]
  ┌─ 6-digit OTP input ──────────────────────┐
  │  Timer MM:SS        [Resend]              │
  │  [Verify & continue]                      │
  └───────────────────────────────────────────┘

[Step 2 — OTP (otp+password mode)]
  ┌─ 6-digit OTP input ──────────────────────┐
  │  Password input                           │
  │  Timer MM:SS        [Resend]              │
  │  [Verify & continue]                      │
  └───────────────────────────────────────────┘
```

Toggle is locked (disabled) after OTP is dispatched. Switching mode pre-dispatch resets OTP state.

---

## Profile Page UI

```
/app/profile
  ┌─ Personal Information ──────────────────────────────┐
  │  Name (editable)                                     │
  │  Email (read-only)                                   │
  │  Phone number  (E.164 input, e.g. +254712345678)    │
  │  Role (read-only)                                    │
  │  [Save changes]                                      │
  └──────────────────────────────────────────────────────┘
  ┌─ Sign-in History ───────────────────────────────────┐
  │  Date/Time | IP Address | Device | Mode | Status    │
  │  (paginated, newest first)                          │
  └──────────────────────────────────────────────────────┘
```

---

## Security Controls

- OTP hashed with `bcrypt` (12 rounds) — plaintext exists only during `Mail::send()`
- Audit log stores `hash('sha256', $email)` not plaintext email
- Rate limiting via Laravel's `RateLimiter` facade, keyed `ip:email`
- Token expiry: 8 hours, enforced by Sanctum `expiration` config
- Scheduled command `otp:prune` runs every 15 minutes to delete expired OTP rows
- HTTPS enforced in non-local environments via `AppServiceProvider`

---

## Testing Plan

### Unit tests (`tests/Unit/OtpServiceTest.php`)
- OTP always exactly 6 digits (000000–999999)
- Hash round-trip: `Hash::check(otp, Hash::make(otp))` is always true
- Property-based: 100 random 6-digit strings all pass hash round-trip

### Feature tests (`tests/Feature/OtpAuthTest.php`)
- `otp-only` happy path → 200 + token
- `otp-and-password` happy path → 200 + token
- Wrong OTP → 422
- Expired OTP → 422
- 5 bad attempts → 429 lockout
- Wrong password in `otp-and-password` mode → 422, attempt counter unchanged
- Accessing protected route without token → 401
- Rate limit exceeded on request endpoint → 429
- Rate limit exceeded on verify endpoint → 429

### Feature tests (`tests/Feature/ProfileTest.php`)
- GET /api/profile returns correct fields
- PATCH with valid E.164 phone → 200
- PATCH with invalid phone → 422
- PATCH with valid name → 200
- PATCH with name < 2 chars → 422
- GET /api/profile/sign-in-logs returns own logs only
- Non-admin accessing another user's logs → 403
- Admin accessing any user's logs → 200
