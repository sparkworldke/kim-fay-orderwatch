# Cloudflare Workers Custom Domain Setup

## Problem

`orderwatch.fayshop.co.ke` → CNAME → `orderwatchkimfay.nairobidental.workers.dev`

This doesn't work because you **cannot CNAME to a `*.workers.dev` URL** and use it as a Custom Domain in the Workers dashboard. Cloudflare Custom Domains require the domain to be **proxied through Cloudflare** (orange-clouded).

---

## Solution Options

### Option 1: Add Custom Domain via Workers Dashboard (Recommended)

> Requires `fayshop.co.ke` to be an active zone in your Cloudflare account.

1. Go to **Cloudflare Dashboard** → **Workers & Pages**
2. Open your Worker (`orderwatchkimfay`)
3. Go to **Settings** → **Triggers**
4. Under **Custom Domains**, click **Add Custom Domain**
5. Enter `orderwatch.fayshop.co.ke`
6. Click **Add Custom Domain**

Cloudflare will automatically create the DNS record and SSL certificate.

---

### Option 2: Manual DNS + Custom Domain

1. In **Cloudflare DNS** for `fayshop.co.ke`, add:
   | Type | Name         | IPv4        | Proxy Status |
   |------|--------------|-------------|--------------|
   | A    | orderwatch   | 192.0.2.1   | Proxied ☁️   |

   > The IP is a placeholder — any IP works since traffic is proxied.

2. Then go to **Workers & Pages** → your Worker → **Settings** → **Triggers** → **Custom Domains**
3. Add `orderwatch.fayshop.co.ke`

---

### Option 3: Worker Route (Alternative to Custom Domain)

1. Add the proxied A record as in Option 2 above
2. Go to your Worker → **Settings** → **Triggers** → **Routes**
3. Click **Add Route**
4. Enter route: `orderwatch.fayshop.co.ke/*`
5. Select zone: `fayshop.co.ke`
6. Save

---

## Why the CNAME Didn't Work

| Reason | Detail |
|--------|--------|
| `*.workers.dev` is not routable via CNAME for custom domains | Cloudflare needs traffic to enter their network through a proxied DNS record on a zone you own |
| Custom Domains require zone ownership | The domain must be an active Cloudflare zone in your account |
| Grey-cloud (DNS only) records won't work | The DNS record **must** be orange-clouded (proxied) |

---

## Quick Checklist

- [ ] `fayshop.co.ke` is an active zone in your Cloudflare account
- [ ] DNS record for `orderwatch` is **Proxied** (orange cloud, not grey)
- [ ] Custom Domain added in Worker **Triggers** tab
- [ ] SSL certificate issued (Cloudflare handles this automatically)
