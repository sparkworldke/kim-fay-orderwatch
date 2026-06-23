# Authentication and Outlook synchronization fixes

## Authentication

- Protected `/app` document requests now pass through TanStack Start request middleware before SSR. The middleware reads the mirrored session token, validates it against Laravel's protected `auth/me` endpoint, and redirects missing, revoked, or expired sessions to `/auth` without rendering the application shell.
- Client-side navigation also validates the token in the `/app` route `beforeLoad` hook. A stale session is cleared before redirecting.
- Login mirrors the Sanctum bearer token to a secure, same-site cookie so server middleware can validate it. Logout and every API `401` clear local storage and the cookie together.
- If the authentication API itself is unavailable, protected HTML is not rendered; the server returns a non-cacheable `503` response.

## Outlook OAuth and synchronization

- The OAuth callback now verifies the Microsoft Graph `/me` response before persisting an account.
- Mailbox email resolution supports `mail`, `userPrincipalName`, `otherMails`, and standard access-token identity claims. Empty email identities are rejected rather than saved.
- Connected accounts continue to use `updateOrCreate` keyed by normalized email, preserving the existing mailbox record and replacing its tokens after reconnection.
- Missing or undecryptable stored access/refresh tokens now produce a clear reconnect instruction and mark the mailbox as errored.
- **Check OAuth** now uses `POST` as an explicit diagnostic action. The backend retains `GET` compatibility for older deployed clients.
- Diagnostic health now includes connected mailbox token failures in `overall_ok`, and the frontend surfaces structured API errors instead of the ambiguous `Request failed` text.
- OAuth callback exceptions are logged with corrected, consistent context while the browser receives a safe actionable message.

## Verification

- Frontend: `npm run build` and `npm run lint`.
- Backend: `php artisan test`, including the OAuth diagnostic POST feature test and Outlook service unit tests.
- Deployment smoke test: sign in, refresh a protected URL directly, revoke/log out and revisit it, reconnect an existing Outlook mailbox, confirm its email is shown, run **Check OAuth**, and perform a manual sync.
