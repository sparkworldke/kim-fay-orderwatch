# Requirements Document

## Introduction

This feature adds OTP-based passwordless authentication to the Kim-Fay OrderWatch platform. It replaces the current client-side `inferRole` + `localStorage` session mechanism with a server-verified flow: a registered user enters their email, receives a 6-digit OTP by email, submits the OTP, and receives a Sanctum Bearer token on success. Optionally, users may also authenticate with OTP + password combined. After login, users land on a profile dashboard where they can manage personal information (including phone number) and review their sign-in history. All OTP, session, and audit data is handled server-side on the existing Laravel 13 + Sanctum backend with MySQL storage.

---

## Glossary

- **Auth_Service**: The Laravel 13 + Sanctum backend responsible for all authentication logic.
- **OTP**: A one-time passcode — a randomly generated 6-digit numeric code, valid for a single verification attempt within its expiry window.
- **OTP_Store**: The encrypted database table (`otps`) that persists hashed OTP values, associated user, expiry timestamp, and attempt count.
- **Email_Service**: The configured Laravel mail driver responsible for delivering OTP emails to users.
- **Session_Token**: A Laravel Sanctum personal access token issued to the client upon successful authentication.
- **Verification_Endpoint**: The `POST /api/auth/otp/verify` route that accepts an OTP submission and issues a Session_Token on success.
- **OTP_Endpoint**: The `POST /api/auth/otp/request` route that generates and dispatches an OTP.
- **Login_Mode**: The authentication mode selected by the user — either `otp-only` or `otp-and-password`.
- **Profile_Dashboard**: The authenticated frontend page where users manage personal information and view their sign-in history.
- **Sign_In_Log**: A server-side audit record capturing timestamp, IP address, user agent, login mode used, and success/failure status for each authentication attempt.
- **Sign_In_Log_Store**: The database table (`sign_in_logs`) that persists Sign_In_Log records.
- **Phone_Number**: An international E.164-format phone number (e.g., `+254712345678`) optionally stored on the user profile.
- **Rate_Limiter**: The Laravel throttle middleware applied to authentication endpoints to restrict request frequency per IP and per email.
- **Brute_Force_Lockout**: A temporary block applied after exceeding the maximum number of consecutive failed OTP verification attempts.

---

## Requirements

### Requirement 1: OTP Request

**User Story:** As a registered user, I want to request a login OTP sent to my email, so that I can authenticate without storing a password in my browser.

#### Acceptance Criteria

1. WHEN a POST request is received at `POST /api/auth/otp/request` with a valid registered email, THE Auth_Service SHALL generate a cryptographically random 6-digit numeric OTP, store a bcrypt hash of the OTP in the OTP_Store with a 15-minute expiry timestamp, and dispatch the OTP to the provided email via the Email_Service.
2. WHEN a POST request is received at `POST /api/auth/otp/request` with an email that does not match any registered user, THE Auth_Service SHALL return an HTTP 422 response with the message "If this email is registered, an OTP has been sent." (timing-safe response).
3. WHEN the Email_Service fails to deliver the OTP email, THE Auth_Service SHALL return an HTTP 503 response and SHALL NOT persist the OTP in the OTP_Store.
4. THE Auth_Service SHALL invalidate any previously issued, unexpired OTP for the same email before generating a new one.
5. WHEN a POST request is received at `POST /api/auth/otp/request` and the requesting IP or email has exceeded 5 requests within a 10-minute window, THE Rate_Limiter SHALL return an HTTP 429 response with a `Retry-After` header indicating when the limit resets.
6. THE Auth_Service SHALL never log or return the plaintext OTP value in any server log, API response, or error message.

---

### Requirement 2: OTP Verification

**User Story:** As a registered user, I want to submit my OTP to complete login, so that I receive a session token to access the application.

#### Acceptance Criteria

1. WHEN a POST request is received at `POST /api/auth/otp/verify` with a valid email, a matching unexpired OTP, and `login_mode` set to `otp-only`, THE Auth_Service SHALL delete the OTP from the OTP_Store, revoke all existing Sanctum tokens for the user, issue a new Session_Token, and return an HTTP 200 response containing the Session_Token and the user's `id`, `name`, `email`, and `role`.
2. WHEN a POST request is received at `POST /api/auth/otp/verify` with a valid email, a matching unexpired OTP, `login_mode` set to `otp-and-password`, and a correct password for the user, THE Auth_Service SHALL delete the OTP from the OTP_Store, revoke all existing Sanctum tokens for the user, issue a new Session_Token, and return an HTTP 200 response containing the Session_Token and the user's `id`, `name`, `email`, and `role`.
3. WHEN a POST request is received at `POST /api/auth/otp/verify` with an OTP that does not match the stored hash for the given email, THE Auth_Service SHALL increment the attempt counter in the OTP_Store and return an HTTP 422 response with the message "Invalid OTP."
4. WHEN a POST request is received at `POST /api/auth/otp/verify` with an OTP whose expiry timestamp has passed, THE Auth_Service SHALL delete the OTP from the OTP_Store and return an HTTP 422 response with the message "OTP has expired. Please request a new one."
5. WHEN the attempt counter for an OTP reaches 5 consecutive failed attempts, THE Auth_Service SHALL delete the OTP from the OTP_Store and apply a Brute_Force_Lockout of 15 minutes for that email address, returning an HTTP 429 response.
6. WHEN a POST request is received at `POST /api/auth/otp/verify` with `login_mode` set to `otp-and-password` and an incorrect password, THE Auth_Service SHALL return an HTTP 422 response with the message "Invalid credentials." without consuming an OTP attempt.
7. THE Auth_Service SHALL record a Sign_In_Log entry for every OTP verification attempt, including timestamp, IP address, user agent, login_mode, and success or failure status.

---

### Requirement 3: Login Mode Toggle

**User Story:** As a user on the login page, I want to switch between OTP-only and OTP + password login modes, so that I can choose the level of authentication that suits my preference.

#### Acceptance Criteria

1. THE Login_Page SHALL display a toggle control labelled "OTP only" and "OTP + Password" that is visible before the user submits their email.
2. WHEN the user selects `otp-only` mode and successfully receives an OTP, THE Login_Page SHALL display only the OTP input field and a "Verify" button.
3. WHEN the user selects `otp-and-password` mode and successfully receives an OTP, THE Login_Page SHALL display both the OTP input field and a password input field alongside the "Verify" button.
4. THE Login_Page SHALL transmit the selected `login_mode` value (`otp-only` or `otp-and-password`) as a field in the POST request body to `POST /api/auth/otp/verify`.
5. WHEN the user switches Login_Mode after an OTP has already been dispatched, THE Login_Page SHALL discard the current OTP state and prompt the user to request a new OTP.

---

### Requirement 4: Session Management

**User Story:** As an authenticated user, I want my session to be securely managed by the server, so that my access token cannot be tampered with on the client side.

#### Acceptance Criteria

1. THE Auth_Service SHALL issue Session_Tokens as Sanctum personal access tokens stored server-side in the `personal_access_tokens` table.
2. WHEN a Session_Token is issued, THE Auth_Service SHALL set the token's `expires_at` to 8 hours from the time of issuance.
3. WHEN a request is received with an expired Session_Token on any protected route, THE Auth_Service SHALL return an HTTP 401 response.
4. WHEN a `POST /api/auth/logout` request is received with a valid Session_Token, THE Auth_Service SHALL revoke that token and return an HTTP 200 response.
5. THE Frontend SHALL store the Session_Token in `localStorage` under the key `kf_token` and include it as a `Bearer` token in the `Authorization` header of all subsequent API requests.
6. THE Frontend SHALL clear `kf_token` from `localStorage` and redirect the user to the login page when a 401 response is received from any API call.

---

### Requirement 5: User Profile — Personal Information

**User Story:** As an authenticated user, I want to manage my personal profile information, so that I can keep my contact details current.

#### Acceptance Criteria

1. WHEN an authenticated GET request is received at `GET /api/profile`, THE Auth_Service SHALL return the user's `id`, `name`, `email`, `role`, `phone_number` (nullable), and `updated_at`.
2. WHEN an authenticated PATCH request is received at `PATCH /api/profile` with a `phone_number` field, THE Auth_Service SHALL validate that the value matches the E.164 international format (regex: `^\+[1-9]\d{6,14}$`) and, if valid, persist the value to the `users` table.
3. IF a `phone_number` value in a PATCH request to `PATCH /api/profile` does not match the E.164 format, THEN THE Auth_Service SHALL return an HTTP 422 response with the field-level error "Phone number must be in international format (e.g., +254712345678)."
4. WHEN an authenticated PATCH request is received at `PATCH /api/profile` with a `name` field containing between 2 and 100 characters, THE Auth_Service SHALL persist the updated name to the `users` table.
5. IF a `name` field in a PATCH request to `PATCH /api/profile` contains fewer than 2 or more than 100 characters, THEN THE Auth_Service SHALL return an HTTP 422 response with the field-level error "Name must be between 2 and 100 characters."

---

### Requirement 6: User Profile — Sign-In History

**User Story:** As an authenticated user, I want to view a chronological log of my sign-in attempts, so that I can monitor for unauthorized access to my account.

#### Acceptance Criteria

1. WHEN an authenticated GET request is received at `GET /api/profile/sign-in-logs`, THE Auth_Service SHALL return only the Sign_In_Log records belonging to the authenticated user, ordered by `created_at` descending, paginated at 20 records per page.
2. THE Auth_Service SHALL include the following fields in each Sign_In_Log record returned: `id`, `created_at`, `ip_address`, `user_agent`, `login_mode`, and `status` (`success` or `failure`).
3. WHEN a user whose `role` is not `Administrator` attempts to access sign-in logs for a different user ID via any route, THE Auth_Service SHALL return an HTTP 403 response.
4. WHERE the `Administrator` role is active, THE Auth_Service SHALL provide access to sign-in logs for any user via `GET /api/admin/users/{user_id}/sign-in-logs`, paginated at 20 records per page.
5. THE Sign_In_Log_Store SHALL never persist plaintext OTP values, plaintext passwords, or full Session_Token strings in any Sign_In_Log record.

---

### Requirement 7: Security Controls

**User Story:** As a platform operator, I want all authentication endpoints protected by layered security controls, so that the platform is resilient to credential-stuffing, brute-force, and eavesdropping attacks.

#### Acceptance Criteria

1. THE Auth_Service SHALL apply Rate_Limiter middleware to `POST /api/auth/otp/request` at a maximum of 5 requests per 10 minutes per unique combination of IP address and email.
2. THE Auth_Service SHALL apply Rate_Limiter middleware to `POST /api/auth/otp/verify` at a maximum of 10 requests per 15 minutes per unique combination of IP address and email.
3. THE Auth_Service SHALL enforce TLS for all API communications in non-local environments by redirecting HTTP requests to HTTPS.
4. THE OTP_Store SHALL store OTP values as bcrypt hashes; the plaintext OTP SHALL exist in server memory only for the duration of the dispatch call.
5. WHEN an OTP record has been successfully verified, THE Auth_Service SHALL immediately delete the OTP record from the OTP_Store.
6. WHEN an OTP record's expiry timestamp is reached without successful verification, THE Auth_Service SHALL delete the OTP record from the OTP_Store during the next access attempt or via a scheduled cleanup job running every 15 minutes.
7. THE Auth_Service SHALL not include user-enumeration information in error responses for `POST /api/auth/otp/request` — all non-rate-limit responses SHALL return HTTP 200 or HTTP 422 with the same generic message regardless of whether the email is registered.

---

### Requirement 8: Audit Logging

**User Story:** As a platform operator, I want server-side audit logs for all authentication events, so that I can investigate security incidents without exposing sensitive data.

#### Acceptance Criteria

1. THE Auth_Service SHALL write a structured log entry to the application log channel for every call to `POST /api/auth/otp/request`, including: timestamp, hashed email (SHA-256), IP address, and outcome (`otp_dispatched` or `email_not_found` or `rate_limited`).
2. THE Auth_Service SHALL write a structured log entry for every call to `POST /api/auth/otp/verify`, including: timestamp, hashed email (SHA-256), IP address, login_mode, and outcome (`verified`, `invalid_otp`, `expired_otp`, `locked_out`, or `invalid_password`).
3. THE Auth_Service SHALL never include plaintext email addresses, plaintext OTP values, or plaintext passwords in any log entry.
4. WHEN the `LOG_LEVEL` environment variable is set to `debug`, THE Auth_Service SHALL include additional diagnostic context (OTP attempt count, token ID) in log entries while still omitting plaintext sensitive values.

---

### Requirement 9: Testing Coverage

**User Story:** As a developer, I want comprehensive automated tests for the OTP authentication system, so that regressions are caught before deployment.

#### Acceptance Criteria

1. THE Test_Suite SHALL include unit tests for OTP generation, verifying that generated values are always exactly 6 decimal digits (`000000`–`999999`).
2. THE Test_Suite SHALL include unit tests for OTP hashing and verification, verifying the round-trip property: for any valid OTP value, `Hash::check(otp, Hash::make(otp))` returns `true`.
3. THE Test_Suite SHALL include integration tests for the full OTP request → verify flow, covering: successful `otp-only` login, successful `otp-and-password` login, expired OTP rejection, invalid OTP rejection, and Brute_Force_Lockout activation.
4. THE Test_Suite SHALL include integration tests verifying that a user cannot access protected routes without a valid Session_Token.
5. THE Test_Suite SHALL include integration tests for the profile endpoints covering: retrieval of profile data, valid phone number update, invalid phone number rejection (non-E.164), and sign-in log retrieval scoped to the authenticated user.
6. THE Test_Suite SHALL include a property-based test asserting that for any randomly generated 6-digit OTP string, the OTP_Store round-trip (hash → verify) returns `true`.
7. THE Test_Suite SHALL include integration tests verifying rate limiting returns HTTP 429 after the threshold is exceeded on both OTP endpoints.
