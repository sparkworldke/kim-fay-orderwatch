# Fix: AADSTS500113 — No Reply Address Registered

**Error:** `AADSTS500113: No reply address is registered for the application`

This fires when the `redirect_uri` your app sends during OAuth does not match
any URI registered in the Azure AD app registration.

---

## Root Cause

The app is deployed on two production domains but neither callback URL is
registered in Azure AD. Azure rejects the OAuth flow immediately.

---

## Fix 1 — Azure Portal

1. Go to [https://portal.azure.com](https://portal.azure.com)
2. Navigate to **Azure Active Directory → App registrations**
3. Find the app by Client ID: `c540337c-c4ff-42b3-a33c-27f65d7a4ad7`
4. Click **Authentication** in the left sidebar
5. Under **Web → Redirect URIs**, add:

```
https://orderwatch.fayshop.co.ke/api/admin/mailboxes/oauth/callback
https://orderwatchkimfay.workers.dev/api/admin/mailboxes/oauth/callback
```

For local development, also add:

```
http://localhost:8000/api/admin/mailboxes/oauth/callback
```

6. Click **Save**

> Azure propagates the change within ~1 minute. No redeploy needed.

---

## Fix 2 — backend `.env`

The current `.env` has `APP_URL` pointing to a different site entirely, which
causes `MICROSOFT_REDIRECT_URI` (which inherits from `APP_URL`) to send the
wrong callback URL to Azure.

**Before (wrong):**
```env
APP_URL=https://dating.sparkworld.co.ke/backend/public
MICROSOFT_REDIRECT_URI="${APP_URL}/api/admin/mailboxes/oauth/callback"
```

**After (correct):**
```env
APP_URL=https://orderwatch.fayshop.co.ke
MICROSOFT_REDIRECT_URI=https://orderwatch.fayshop.co.ke/api/admin/mailboxes/oauth/callback
```

Set `MICROSOFT_REDIRECT_URI` explicitly (not via `${APP_URL}`) to avoid
ambiguity if `APP_URL` ever changes for another reason.

---

## Golden Rule

> The URI in `MICROSOFT_REDIRECT_URI` and the URI registered in Azure AD must
> be **character-for-character identical** — including protocol, path, and
> trailing slashes. One character off = `AADSTS500113`.

---

## Reference

| Setting | Value |
|---|---|
| Azure AD Tenant ID | `ea1ad84c-dc94-4d67-b527-05536f446962` |
| App Client ID | `c540337c-c4ff-42b3-a33c-27f65d7a4ad7` |
| Callback path | `/api/admin/mailboxes/oauth/callback` |
| Production domain | `https://orderwatch.fayshop.co.ke` |
| Cloudflare Worker | `https://orderwatchkimfay.workers.dev` |
