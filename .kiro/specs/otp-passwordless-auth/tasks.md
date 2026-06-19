# Implementation Tasks — OTP Passwordless Authentication

## Tasks

- [x] 1. Backend: Database migrations & models
  - Create `create_otps_table` migration with columns: id, user_id (nullable FK), email (indexed), otp_hash, expires_at, attempts (default 0)
  - Create `create_sign_in_logs_table` migration with columns: id, user_id (nullable FK), email_hash, ip_address, user_agent, login_mode, status, created_at
  - Create `add_phone_number_to_users_table` migration adding nullable `phone_number` varchar(20) to users
  - Create `App\Models\Otp` Eloquent model with fillable, casts, and `belongsTo(User)`
  - Create `App\Models\SignInLog` Eloquent model with fillable and `belongsTo(User)`
  - Update `App\Models\User` to add `phone_number` to fillable, add `hasMany(Otp)` and `hasMany(SignInLog)` relationships
  - Run `php artisan migrate` to apply all new migrations
  - _Requirements: 1, 2, 5, 6_

- [x] 2. Backend: OTP mail & service
  - Create `App\Mail\OtpMail` mailable that accepts the plaintext OTP and user name, renders a clean HTML email with the 6-digit code and 15-minute expiry notice
  - Create `App\Services\OtpService` with methods: `generate()` returns zero-padded 6-digit string using `random_int`, `hash(string $otp)` returns `Hash::make($otp)`, `verify(string $otp, string $hash)` returns `Hash::check($otp, $hash)`
  - _Requirements: 1, 7_

- [x] 3. Backend: OtpController (request + verify endpoints)
  - Create `App\Http\Controllers\Api\OtpController` with two public methods:
    - `request(Request $request)`: validate email, delete existing OTP row for email, generate+hash OTP, persist Otp row (expires_at = now+15min), dispatch OtpMail, return HTTP 200 with generic message; on mail failure return 503 without persisting; log audit entry with SHA-256(email)
    - `verify(Request $request)`: validate email+otp+login_mode(+password if mode=otp-and-password), find Otp row, check expiry, check hash (increment attempts on fail; delete+429 at 5 attempts), check password if needed, delete Otp row, revoke existing tokens, create Sanctum token (expires_at=now+8h), record SignInLog, return 200 with token+user; log audit entry
  - Update `routes/api.php` to add `POST /api/auth/otp/request` and `POST /api/auth/otp/verify` with rate limiting middleware
  - _Requirements: 1, 2, 7, 8_

- [x] 4. Backend: Rate limiting middleware
  - Register two named rate limiters in `App\Providers\AppServiceProvider` (or `RouteServiceProvider`):
    - `otp-request`: 5 requests per 10 minutes keyed by `ip:email`
    - `otp-verify`: 10 requests per 15 minutes keyed by `ip:email`
  - Apply `throttle:otp-request` to `POST /api/auth/otp/request` and `throttle:otp-verify` to `POST /api/auth/otp/verify` in `routes/api.php`
  - _Requirements: 7_

- [x] 5. Backend: ProfileController
  - Create `App\Http\Controllers\Api\ProfileController` with:
    - `show(Request $request)`: return id, name, email, role, phone_number, updated_at
    - `update(Request $request)`: validate name (2–100 chars) and phone_number (E.164 regex `^\+[1-9]\d{6,14}$`), persist to users table, return updated profile
    - `signInLogs(Request $request)`: return own SignInLog records paginated 20, fields: id, created_at, ip_address, user_agent, login_mode, status
  - Create `App\Http\Controllers\Api\AdminController` with:
    - `userSignInLogs(Request $request, User $user)`: admin-only; return SignInLog records for given user paginated 20
  - Add `GET /api/profile`, `PATCH /api/profile`, `GET /api/profile/sign-in-logs`, and `GET /api/admin/users/{user}/sign-in-logs` to `routes/api.php`
  - _Requirements: 5, 6_

- [x] 6. Backend: Scheduled OTP pruning command
  - Create `App\Console\Commands\PruneExpiredOtps` artisan command that deletes all Otp rows where `expires_at < now()`
  - Register the command in `routes/console.php` or `app/Console/Kernel.php` to run every 15 minutes via `->everyFifteenMinutes()`
  - _Requirements: 7_

- [-] 7. Backend: Tests
  - Create `tests/Unit/OtpServiceTest.php` with:
    - Test OTP generation always produces exactly 6 decimal digits
    - Test hash round-trip: `OtpService::verify(otp, OtpService::hash(otp))` is true
    - Property-based loop: 100 random 6-digit strings all pass hash round-trip
  - Create `tests/Feature/OtpAuthTest.php` with RefreshDatabase and Mail::fake():
    - `otp-only` happy path → 200 + token
    - `otp-and-password` happy path → 200 + token
    - Wrong OTP → 422
    - Expired OTP → 422
    - 5 consecutive wrong OTPs → 429 lockout
    - Wrong password in `otp-and-password` → 422, attempt counter unchanged
    - Protected route without token → 401
    - Rate limit exceeded on `otp/request` → 429
    - Rate limit exceeded on `otp/verify` → 429
  - Create `tests/Feature/ProfileTest.php` with RefreshDatabase:
    - GET /api/profile returns correct fields
    - PATCH with valid E.164 phone → 200
    - PATCH with invalid phone format → 422
    - PATCH with valid name → 200
    - PATCH with name < 2 chars → 422
    - GET /api/profile/sign-in-logs returns only own logs
    - Non-admin accessing another user's logs via admin route → 403
    - Admin accessing any user's logs → 200
  - Run `php artisan test` and confirm all tests pass
  - _Requirements: 9_

- [x] 8. Frontend: Update auth.ts session model
  - Add `token` field to the `Session` interface in `src/lib/auth.ts`
  - Add `getToken()` / `setToken()` / `clearToken()` helpers that read/write `kf_token` in localStorage
  - Update `apiFetch` in `src/lib/api.ts` to auto-read `kf_token` and attach `Authorization: Bearer` header when no explicit token is passed
  - Add 401 interceptor to `apiFetch` that clears session+token and throws an `ApiError` so callers can redirect to `/auth`
  - _Requirements: 4_

- [x] 9. Frontend: Login page — OTP flow wired to API
  - Rewrite `src/routes/auth.tsx` to:
    - Add a `Switch`/toggle labelled "OTP only" / "OTP + Password" visible at email step; default = `otp-only`; disable toggle after OTP dispatched
    - On email submit: call `POST /api/auth/otp/request`; show toast on success; advance to OTP step
    - On OTP step in `otp-and-password` mode: show a password `<Input>` below the OTP slots
    - On verify submit: call `POST /api/auth/otp/verify` with `{ email, otp, login_mode, password? }`; on 200 store token via `setToken()`, store session via `setSession()`, navigate to `/app`; on error show toast with server message
    - Resend button calls `POST /api/auth/otp/request` again and resets the countdown timer
    - Switching mode after OTP dispatched: clear OTP state and re-enable email step
    - Timer counts down from 900 seconds (15 min to match backend expiry)
  - _Requirements: 1, 2, 3_

- [x] 10. Frontend: Profile page
  - Create `src/routes/app.profile.tsx` file-based route
  - Add "Profile" link in `src/components/app-sidebar.tsx` pointing to `/app/profile`
  - Build the page with two sections:
    - Personal Information card: name (editable text input), email (read-only), phone number (editable, E.164 placeholder), role (read-only badge); Save button calls `PATCH /api/profile`; show field-level validation errors from API
    - Sign-in History card: table with columns Date/Time, IP Address, Device (user_agent truncated), Mode, Status badge; load from `GET /api/profile/sign-in-logs`; paginate with Previous/Next buttons
  - Use `@tanstack/react-query` (`useQuery` / `useMutation`) for all data fetching and mutations
  - _Requirements: 5, 6_
