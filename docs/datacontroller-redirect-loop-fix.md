# Fix: `datacontroller.fayshop.co.ke` ERR_TOO_MANY_REDIRECTS

## Symptom

Browser:

> This page isn’t working  
> datacontroller.fayshop.co.ke redirected you too many times.  
> `ERR_TOO_MANY_REDIRECTS`

curl:

```text
HTTP/2 301
location: https://datacontroller.fayshop.co.ke/
```

…to the **same URL** forever (redirect loop).

## Root cause (most common)

The hostname is **proxied through Cloudflare** (orange cloud). DNS resolves to Cloudflare IPs (`104.21…`, `172.67…`).

Typical broken setup:

```text
Browser ──HTTPS──► Cloudflare ──HTTP──► origin nginx
                         ▲                    │
                         └── 301 to HTTPS ────┘
```

1. Cloudflare SSL mode is **Flexible** (CF → origin uses plain HTTP).
2. Origin nginx (or Hestia/cPanel/Laravel force-HTTPS) always redirects HTTP → HTTPS.
3. Cloudflare receives that redirect, serves it to the browser for the same HTTPS URL → **infinite loop**.

This is a **server / Cloudflare** issue, not the Sight frontend Worker.

---

## Fix (do this on Cloudflare + origin)

### 1. Cloudflare SSL/TLS mode (do first — often enough)

1. Log in to [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Select zone **`fayshop.co.ke`**
3. **SSL/TLS** → **Overview**
4. Set encryption mode to **Full** (or **Full (strict)** if the origin has a valid cert)

| Mode | CF → origin | Safe with origin HTTPS redirect? |
|------|-------------|----------------------------------|
| Flexible | HTTP | **No** — causes this loop |
| Full | HTTPS (any cert) | Yes |
| Full (strict) | HTTPS (valid cert) | Yes (best) |

Wait 30–60 seconds, hard-refresh (or try private window).

**Test:**

```bash
curl.exe -sS -i --max-redirs 0 "https://datacontroller.fayshop.co.ke/"
```

You should **not** get `301` to the same HTTPS URL. Prefer `200` or a normal Laravel response.

---

### 2. Origin must serve Laravel correctly

On the VPS, the document root for `datacontroller.fayshop.co.ke` should be the Laravel **`public`** folder, e.g.:

```text
/home/.../datacontroller.fayshop.co.ke/public_html/backend/public
# or
/var/www/.../backend/public
```

API path once fixed:

```text
https://datacontroller.fayshop.co.ke/backend/public/api
# or, if public is the web root:
https://datacontroller.fayshop.co.ke/api
```

Laravel `.env` on that server:

```env
APP_URL=https://datacontroller.fayshop.co.ke/backend/public
# or APP_URL=https://datacontroller.fayshop.co.ke  if public is document root
```

Then:

```bash
php artisan config:clear
php artisan config:cache
```

---

### 3. Nginx: do not force HTTPS when Cloudflare already terminates SSL

If you keep **Full / Full (strict)**, a normal HTTPS server block is fine.

If you use a **force-HTTPS** rule, it must not run on requests that Cloudflare already marks as HTTPS.

Example pattern (Hestia / many panels inject this badly):

```nginx
# BAD with Flexible SSL — loops
if ($scheme = http) {
    return 301 https://$host$request_uri;
}
```

With Cloudflare, prefer:

```nginx
# Only redirect if CF says visitor used HTTP
if ($http_x_forwarded_proto = "http") {
    return 301 https://$host$request_uri;
}
```

Or rely on Cloudflare **Always Use HTTPS** and remove origin force-HTTPS entirely when using Full SSL.

Also ensure PHP/Laravel trusts proxies (so `URL::forceScheme` is correct). Laravel often uses `TrustProxies` — leave `*` or Cloudflare ranges as appropriate.

---

### 4. Cloudflare page rules / redirect rules

In **Rules** → **Redirect Rules** / **Page Rules**, check there is **no** rule like:

- Always redirect `datacontroller.fayshop.co.ke` → itself  
- HTTP → HTTPS that conflicts with Flexible  

Temporarily disable custom redirects for this host while testing.

---

### 5. Confirm API works before pointing Sight at it

```bash
curl.exe -sS -i -X POST "https://datacontroller.fayshop.co.ke/backend/public/api/auth/email/check" ^
  -H "Content-Type: application/json" ^
  -H "Accept: application/json" ^
  --data "{\"email\":\"test@example.com\"}"
```

Expected: **HTTP 200** + JSON (not 301).

If only the shorter path works:

```bash
curl.exe -sS -i -X POST "https://datacontroller.fayshop.co.ke/api/auth/email/check" ...
```

use that path as the upstream.

---

### 6. Switch Sight Worker to datacontroller (after step 5 passes)

In monorepo `.env.production`:

```env
VITE_API_BASE_URL=/api
VITE_API_UPSTREAM=https://datacontroller.fayshop.co.ke/backend/public/api
VITE_API_BASE_URL_SSR=https://datacontroller.fayshop.co.ke/backend/public/api
```

(or `/api` without `/backend/public` if that is the working path)

Deploy:

```bash
npm run deploy:production
```

Until then, production Sight correctly uses:

```text
https://dating.sparkworld.co.ke/backend/public/api
```

---

## Quick checklist

- [ ] Cloudflare SSL mode = **Full** or **Full (strict)** (not Flexible)
- [ ] Origin has valid HTTPS if using Full (strict)
- [ ] No self-redirect in nginx / panel “force SSL”
- [ ] Document root = Laravel `public`
- [ ] `curl` to `/api/.../email/check` returns **200 JSON**
- [ ] Then update `.env.production` + `npm run deploy:production`

---

## Why Sight still works

`https://sight.fayshop.co.ke` is the frontend Worker. Login API calls go to the **upstream Laravel host** configured at deploy time. After the redirect-loop incident, production was pointed back at the working host (`dating.sparkworld.co.ke`) so login works while you fix `datacontroller`.
