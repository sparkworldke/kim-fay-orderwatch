# OrderWatch Custom Domain Setup

Connect `orderwatch.fayshop.co.ke` (currently managed in cPanel) to the Cloudflare Worker `orderwatchkimfay` so users visit your branded domain instead of `https://orderwatchkimfay.nairobidental.workers.dev`.

---

## Architecture

| Component | Host | Role |
|-----------|------|------|
| Frontend (React / TanStack Start) | `orderwatch.fayshop.co.ke` | Cloudflare Worker `orderwatchkimfay` |
| API (Laravel) | `https://dating.sparkworld.co.ke/backend/public/api` | Separate server — not on cPanel for this subdomain |

The Worker serves the UI only. API calls go directly to the Laravel backend configured in `VITE_API_BASE_URL`.

```mermaid
flowchart LR
  User -->|orderwatch.fayshop.co.ke| CF[Cloudflare DNS - Proxied]
  CF --> Worker[orderwatchkimfay Worker]
  Worker -->|API calls| Laravel[dating.sparkworld.co.ke/backend]
```

---

## Why cPanel CNAME Does Not Work

You **cannot** point a cPanel DNS record at `orderwatchkimfay.nairobidental.workers.dev` and get a proper custom domain.

| Approach | Result |
|----------|--------|
| CNAME `orderwatch` → `*.workers.dev` in cPanel | Does not work for Worker Custom Domains |
| DNS-only (grey cloud) record | Cloudflare cannot attach the Worker or issue SSL |
| cPanel redirect to workers.dev URL | Users see the workers.dev hostname — not a real custom domain |

**Requirement:** The zone `fayshop.co.ke` must be an **active Cloudflare zone** in the same account as the Worker, with a **proxied** (orange cloud) DNS record for `orderwatch`.

---

## Recommended Setup

### Step 1 — Add `fayshop.co.ke` to Cloudflare

1. Log in to [Cloudflare Dashboard](https://dash.cloudflare.com) (account: `sparkworldke@gmail.com` — same account as Worker `orderwatchkimfay`).
2. Click **Add a site** → enter `fayshop.co.ke`.
3. Select the **Free** plan.
4. Cloudflare scans and imports existing DNS records from cPanel.

> You are moving **DNS control** to Cloudflare. cPanel can still host email, other subdomains, and other sites — you recreate those records in Cloudflare DNS.

---

### Step 2 — Change nameservers at your registrar

At your domain registrar (or wherever `fayshop.co.ke` nameservers are set), replace cPanel nameservers with the pair Cloudflare provides, for example:

```
ada.ns.cloudflare.com
bob.ns.cloudflare.com
```

Cloudflare shows the exact nameservers on the zone overview page after you add the site.

**Propagation:** 15 minutes to 48 hours.

---

### Step 3 — Recreate essential DNS in Cloudflare

Go to **Cloudflare → fayshop.co.ke → DNS → Records**.

Keep records your other services need:

| Purpose | Type | Name | Value | Proxy |
|---------|------|------|-------|-------|
| Main cPanel site | A | `@` or `www` | cPanel server IP | DNS only or Proxied |
| Email (if used) | MX | `@` | mail server hostname | — |

**Delete** any existing `orderwatch` A or CNAME record imported from cPanel. The Worker Custom Domain step creates the correct record automatically.

---

### Step 4 — Attach the domain to your Worker

#### Option A — Cloudflare Dashboard (easiest)

1. **Workers & Pages** → open `orderwatchkimfay`.
2. **Settings** → **Domains & Routes** → **Add** → **Custom Domain**.
3. Enter: `orderwatch.fayshop.co.ke`
4. Click **Add Custom Domain**.

Cloudflare automatically:

- Creates the proxied DNS record
- Issues an SSL certificate
- Routes all paths on that hostname to your Worker

#### Option B — Wrangler config (persists across deploys)

Add to `wrangler.jsonc` in the project root:

```jsonc
{
  "$schema": "node_modules/wrangler/config-schema.json",
  "name": "orderwatchkimfay",
  "compatibility_date": "2026-06-01",
  "compatibility_flags": ["nodejs_compat"],
  "main": "dist/server/server.js",
  "routes": [
    {
      "pattern": "orderwatch.fayshop.co.ke",
      "custom_domain": true
    }
  ],
  "assets": {
    "binding": "ASSETS",
    "directory": "dist/client"
  }
}
```

Then deploy:

```bash
npm run build
npx wrangler deploy
```

---

## Post-Setup Checklist

- [ ] `fayshop.co.ke` is an active zone in your Cloudflare account
- [ ] Nameservers point to Cloudflare (not cPanel)
- [ ] `orderwatch` DNS record is **Proxied** (orange cloud)
- [ ] Custom Domain `orderwatch.fayshop.co.ke` appears under Worker **Domains & Routes**
- [ ] https://orderwatch.fayshop.co.ke loads the app
- [ ] SSL certificate shows as active (Cloudflare handles this)

### Backend / app config

1. **CORS** — Add `https://orderwatch.fayshop.co.ke` to `backend/config/cors.php` allowed origins if not already present.

2. **Frontend API URL** — Production build uses `VITE_API_BASE_URL` from `.env`:
   ```env
   VITE_API_BASE_URL=https://dating.sparkworld.co.ke/backend/public/api
   ```
   Rebuild and redeploy after any change:
   ```bash
   npm run build
   npx wrangler deploy
   ```

3. **Azure OAuth (Outlook mailbox connect)** — If used, register this redirect URI in Azure AD → App registrations → Authentication:
   ```
   https://orderwatch.fayshop.co.ke/api/admin/mailboxes/oauth/callback
   ```
   > **Note:** The Laravel API currently lives on `dating.sparkworld.co.ke`. OAuth callbacks must match where the API actually runs. If the callback is on the Laravel server, use:
   ```
   https://dating.sparkworld.co.ke/backend/public/api/admin/mailboxes/oauth/callback
   ```
   The URI in Azure AD and `MICROSOFT_REDIRECT_URI` in backend `.env` must be **character-for-character identical**.

---

## If You Cannot Change Nameservers

If `fayshop.co.ke` must remain 100% on cPanel DNS, Worker Custom Domains are not supported for that hostname.

**Workarounds (not recommended for production):**

| Option | Trade-off |
|--------|-----------|
| cPanel redirect to workers.dev | Users may see workers.dev URL; poor UX |
| Keep workers.dev as primary URL | No branded domain |

The production-grade path is **Cloudflare DNS for `fayshop.co.ke` + Worker Custom Domain**.

---

## Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Custom Domain add fails | Zone not on Cloudflare | Complete Steps 1–2 |
| Custom Domain add fails | Existing CNAME on `orderwatch` | Delete conflicting record in Cloudflare DNS |
| SSL certificate pending | DNS not propagated | Wait; ensure record is Proxied |
| App loads but API errors | CORS | Add custom domain to `config/cors.php` |
| OAuth `AADSTS500113` | Redirect URI mismatch | Align Azure AD URI with backend `.env` |

---

## Reference

| Setting | Value |
|---------|-------|
| Worker name | `orderwatchkimfay` |
| Workers.dev URL | `https://orderwatchkimfay.nairobidental.workers.dev` |
| Target custom domain | `https://orderwatch.fayshop.co.ke` |
| Cloudflare account | `sparkworldke@gmail.com` |
| Production API | `https://dating.sparkworld.co.ke/backend/public/api` |
| Deploy commands | `npm run build` then `npx wrangler deploy` |

---

## Related docs in this repo

- `cloudflare-custom-domain-setup.md` — earlier Workers custom domain notes
- `azure-ad-redirect-uri-fix.md` — Outlook OAuth redirect URI setup