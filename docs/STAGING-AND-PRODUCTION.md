# Kim-Fay Sight — Staging & Production Guide

How to run **two separate environments** for Kim-Fay Sight:

- **Production** — live users at `https://sight.fayshop.co.ke`
- **Staging** — QA / pre-release at `https://staging.sight.fayshop.co.ke`

Legacy hostname `https://orderwatch.fayshop.co.ke` permanently redirects to production Sight.

---

## Table of contents

1. [Quick reference](#1-quick-reference)
2. [Architecture](#2-architecture)
3. [Repo files map](#3-repo-files-map)
4. [Frontend (Cloudflare Workers)](#4-frontend-cloudflare-workers)
5. [Backend (Laravel on VPS)](#5-backend-laravel-on-vps)
6. [Cloudflare DNS & domains](#6-cloudflare-dns--domains)
7. [First-time staging setup](#7-first-time-staging-setup)
8. [Production deploy](#8-production-deploy)
9. [Release workflow](#9-release-workflow)
10. [Local development](#10-local-development)
11. [Checklists](#11-checklists)
12. [Troubleshooting](#12-troubleshooting)
13. [Related docs](#13-related-docs)

---

## 1. Quick reference

| | Production | Staging |
|--|------------|---------|
| **Product name** | Kim-Fay Sight | Kim-Fay Sight (Staging) |
| **Frontend URL** | `https://sight.fayshop.co.ke` | `https://staging.sight.fayshop.co.ke` |
| **Legacy URL** | `orderwatch.fayshop.co.ke` → 301 → Sight | — |
| **Cloudflare Worker** | `orderwatchkimfay` | `sight-staging` |
| **Wrangler config** | `wrangler.jsonc` | `wrangler.staging.jsonc` |
| **Frontend env file** | `.env.production` | `.env.staging` |
| **API host** | `https://datacontroller.fayshop.co.ke/backend/public/api` | staging API host (separate DB) |
| **Backend path (example)** | `/var/www/orderwatch/backend` | `/var/www/sight-staging/backend` |
| **Database (example)** | `kimfay_orderwatch` | `kimfay_sight_staging` |
| **Backend env template** | `backend/.env.production.example` | `backend/.env.staging.example` |
| **Deploy frontend** | `npm run deploy:production` | `npm run deploy:staging` |

### Rules

1. **Different Workers** — never deploy staging with the production Wrangler file (and vice versa).
2. **Different databases** — staging must not use the production MySQL database.
3. **Different `FRONTEND_URL`** on each Laravel `.env`.
4. **Staging cron off** (or heavily limited) — avoid duplicate emails, reports, and Acumatica sync against live systems unless intentional.
5. Always validate on **staging first**, then production.

---

## 2. Architecture

```text
┌─────────────────────────────────────────────────────────────────┐
│  PRODUCTION                                                       │
│                                                                   │
│  Browser                                                          │
│    │                                                              │
│    ▼                                                              │
│  sight.fayshop.co.ke                                              │
│  Worker: orderwatchkimfay  (wrangler.jsonc)                       │
│    ├─ UI + SSR (dist/)                                            │
│    └─ /api/*  ──proxy──►  prod Laravel (VITE_API_UPSTREAM)        │
│                                                                   │
│  orderwatch.fayshop.co.ke  ──301 301──►  sight.fayshop.co.ke    │
└─────────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────────┐
│  STAGING                                                          │
│                                                                   │
│  Browser                                                          │
│    │                                                              │
│    ▼                                                              │
│  staging.sight.fayshop.co.ke                                      │
│  Worker: sight-staging  (wrangler.staging.jsonc)                  │
│    ├─ UI + SSR (dist/)                                            │
│    └─ /api/*  ──proxy──►  staging Laravel (VITE_API_UPSTREAM)     │
└─────────────────────────────────────────────────────────────────┘
```

### How API routing works

| Call site | URL used | Config |
|-----------|----------|--------|
| Browser | same-origin `/api/...` | `.env.*` → `VITE_API_BASE_URL=/api` |
| Worker proxy | absolute Laravel URL | `VITE_API_UPSTREAM` (build-time) |
| SSR auth in Worker | absolute Laravel URL | `VITE_API_BASE_URL_SSR` (build-time) |

Implementation: `src/lib/api-upstream.ts` and `src/server.ts`.

`VITE_*` values are baked in at **build** time. Changing the API host requires a new frontend build + deploy.

---

## 3. Repo files map

| File | Purpose |
|------|---------|
| `.env.production` | Production Vite env (API upstream URLs) |
| `.env.staging` | Staging Vite env (API upstream URLs) |
| `.env.example` | Local/dev template |
| `wrangler.jsonc` | Production Worker + custom domains |
| `wrangler.staging.jsonc` | Staging Worker + custom domain |
| `src/lib/api-upstream.ts` | Resolves Worker `/api` proxy target from env |
| `src/server.ts` | Legacy host 301 + `/api` proxy + SSR entry |
| `backend/config/cors.php` | Allowed browser origins (prod + staging) |
| `backend/.env.production.example` | Production Laravel env template |
| `backend/.env.staging.example` | Staging Laravel env template |
| `package.json` | `build:*` and `deploy:*` scripts |
| `docs/STAGING-AND-PRODUCTION.md` | This guide |

### npm scripts

```bash
npm run build:staging        # vite build --mode staging
npm run build:production     # vite build --mode production
npm run deploy:staging       # build:staging + wrangler deploy -c wrangler.staging.jsonc
npm run deploy:production    # build:production + wrangler deploy -c wrangler.jsonc
npm run dev                  # local only (uses .env / .env.local)
```

---

## 4. Frontend (Cloudflare Workers)

### 4.1 Production env — `.env.production`

```env
# Browser → same-origin /api (Worker proxies upstream)
VITE_API_BASE_URL=/api

# Worker proxy + SSR (Laravel absolute URL, must end with /api)
VITE_API_UPSTREAM=https://datacontroller.fayshop.co.ke/backend/public/api
VITE_API_BASE_URL_SSR=https://datacontroller.fayshop.co.ke/backend/public/api
```

### 4.2 Staging env — `.env.staging`

```env
VITE_API_BASE_URL=/api
VITE_API_UPSTREAM=https://api-staging.sight.fayshop.co.ke/api
VITE_API_BASE_URL_SSR=https://api-staging.sight.fayshop.co.ke/api
```

Temporary path on the same VPS (until a dedicated staging subdomain exists):

```env
VITE_API_UPSTREAM=https://datacontroller.fayshop.co.ke/staging/backend/public/api
VITE_API_BASE_URL_SSR=https://datacontroller.fayshop.co.ke/staging/backend/public/api
```

### 4.3 Deploy frontend

From the **monorepo root** (not the VPS):

```bash
# Log in once per machine
npx wrangler whoami
# npx wrangler login   # if needed

# Staging
npm run deploy:staging

# Production (only after staging is good)
npm run deploy:production
```

Equivalent manual steps:

```bash
npm run build:staging
npx wrangler deploy -c wrangler.staging.jsonc

npm run build:production
npx wrangler deploy -c wrangler.jsonc
```

### 4.4 Worker config summary

**Production** (`wrangler.jsonc`):

- Name: `orderwatchkimfay`
- Domains: `sight.fayshop.co.ke`, `orderwatch.fayshop.co.ke`

**Staging** (`wrangler.staging.jsonc`):

- Name: `sight-staging`
- Domain: `staging.sight.fayshop.co.ke`

First successful `deploy:staging` creates the Worker and attaches the custom domain (Cloudflare issues SSL).

---

## 5. Backend (Laravel on VPS)

### 5.1 Production Laravel `.env` (key fields)

```env
APP_NAME="Kim-Fay Sight"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://datacontroller.fayshop.co.ke/backend/public

FRONTEND_URL=https://sight.fayshop.co.ke

DB_DATABASE=kimfay_orderwatch
# … real secrets, real Acumatica, real SMTP …
```

Template: `backend/.env.production.example`.

### 5.2 Staging Laravel `.env` (key fields)

```env
APP_NAME="Kim-Fay Sight (Staging)"
APP_ENV=staging
APP_DEBUG=true
APP_URL=https://api-staging.sight.fayshop.co.ke

FRONTEND_URL=https://staging.sight.fayshop.co.ke

DB_DATABASE=kimfay_sight_staging
DB_USERNAME=sight_staging
DB_PASSWORD=STRONG_PASSWORD

MAIL_FROM_ADDRESS="noreply-staging@fayshop.co.ke"
# Prefer sandbox Acumatica / non-prod mail when available
```

Template: `backend/.env.staging.example`.

### 5.3 CORS

`backend/config/cors.php` must allow both frontends (already configured in repo):

- `https://sight.fayshop.co.ke`
- `https://staging.sight.fayshop.co.ke`
- `https://orderwatch.fayshop.co.ke` (legacy)

After deploying CORS changes:

```bash
cd /path/to/backend
php artisan config:clear
php artisan config:cache
```

### 5.4 Cron / scheduler

| Env | Recommendation |
|-----|----------------|
| Production | Full crontab / `schedule:run` as documented in `cron-jobs-guide.md` |
| Staging | **Disabled** by default; enable only jobs needed for a specific test |

Staging with full cron can send real emails and hit Acumatica twice.

---

## 6. Cloudflare DNS & domains

Zone: **`fayshop.co.ke`** must be on the **same Cloudflare account** as the Workers.

| Hostname | Type | Points to |
|----------|------|-----------|
| `sight.fayshop.co.ke` | Worker custom domain | Worker `orderwatchkimfay` |
| `orderwatch.fayshop.co.ke` | Worker custom domain | Worker `orderwatchkimfay` (redirects to Sight) |
| `staging.sight.fayshop.co.ke` | Worker custom domain | Worker `sight-staging` |
| `api-staging.sight.fayshop.co.ke` | A / AAAA (or CNAME) | Staging VPS IP; SSL via Nginx + certbot |

Worker custom domains are usually created automatically by Wrangler when `custom_domain: true` is set. API hostnames are created manually in **Cloudflare → DNS**.

---

## 7. First-time staging setup

### Step 1 — MySQL

```sql
CREATE DATABASE kimfay_sight_staging CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sight_staging'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON kimfay_sight_staging.* TO 'sight_staging'@'localhost';
FLUSH PRIVILEGES;
```

### Step 2 — Code on VPS

```bash
sudo mkdir -p /var/www/sight-staging
sudo chown "$USER":www-data /var/www/sight-staging

# From your PC (example rsync) — never overwrite a live .env:
# rsync -avz --exclude vendor --exclude .env ./backend/ user@VPS:/var/www/sight-staging/backend/
```

### Step 3 — Laravel install

```bash
cd /var/www/sight-staging/backend
cp .env.staging.example .env
nano .env   # set DB_*, APP_KEY later, secrets, FRONTEND_URL

composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
```

### Step 4 — Nginx + SSL

Example site config:

```nginx
server {
    listen 80;
    server_name api-staging.sight.fayshop.co.ke;
    root /var/www/sight-staging/backend/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;
    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/api-staging.sight /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo certbot --nginx -d api-staging.sight.fayshop.co.ke
```

### Step 5 — Point frontend at staging API

Edit monorepo `.env.staging` so `VITE_API_UPSTREAM` and `VITE_API_BASE_URL_SSR` match the real staging API URL (must end with `/api`).

### Step 6 — Deploy staging Worker

```bash
npm run deploy:staging
```

### Step 7 — Smoke test

```bash
curl -sI https://api-staging.sight.fayshop.co.ke/api
curl -sI https://staging.sight.fayshop.co.ke/auth
```

Open `https://staging.sight.fayshop.co.ke/auth` and sign in with a staging user.

---

## 8. Production deploy

### Frontend

```bash
# Confirm .env.production VITE_API_UPSTREAM points at prod Laravel
npm run deploy:production
```

### Backend update (existing server)

See [`BACKEND-DEPLOY-UPDATE.md`](./BACKEND-DEPLOY-UPDATE.md). Short version:

```bash
# rsync/git pull backend (do not overwrite .env)
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
sudo systemctl reload php8.3-fpm
```

### Verify production

```bash
curl -sI https://sight.fayshop.co.ke
curl -sI https://orderwatch.fayshop.co.ke   # expect 301 → sight.fayshop.co.ke
```

---

## 9. Release workflow

Recommended order for every release:

| Step | Action | Where |
|------|--------|--------|
| 1 | Merge / tag release candidate | Git |
| 2 | Deploy backend | Staging VPS |
| 3 | Run migrations | Staging |
| 4 | `npm run deploy:staging` | Local → Cloudflare |
| 5 | QA login, critical flows | `staging.sight.fayshop.co.ke` |
| 6 | Deploy backend | Production VPS |
| 7 | Run migrations (`--force`) | Production |
| 8 | `npm run deploy:production` | Local → Cloudflare |
| 9 | Smoke test | `sight.fayshop.co.ke` |
| 10 | Confirm legacy redirect | `orderwatch.fayshop.co.ke` |

Never run experimental migrations on production before staging.

---

## 10. Local development

```bash
npm run dev
```

Uses root `.env` or `.env.local` (not staging/production mode).

Example local API:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_API_BASE_URL_SSR=http://localhost:8000/api
```

Or point at a shared remote API for convenience. Local does not use Wrangler custom domains.

---

## 11. Checklists

### Staging — first deploy

- [ ] MySQL database `kimfay_sight_staging` created
- [ ] Backend at `/var/www/sight-staging/backend` with `.env` from `.env.staging.example`
- [ ] `FRONTEND_URL=https://staging.sight.fayshop.co.ke`
- [ ] Nginx + SSL for `api-staging.sight.fayshop.co.ke`
- [ ] CORS includes staging origin (deploy `config/cors.php`)
- [ ] `.env.staging` `VITE_API_*` matches real staging API
- [ ] `npm run deploy:staging`
- [ ] Login works on `https://staging.sight.fayshop.co.ke/auth`
- [ ] Production crons **not** enabled on staging

### Production — after Sight rename / env split

- [ ] Laravel `FRONTEND_URL=https://sight.fayshop.co.ke`
- [ ] Laravel `APP_NAME="Kim-Fay Sight"`
- [ ] CORS includes `sight.fayshop.co.ke`
- [ ] `.env.production` `VITE_API_UPSTREAM` correct
- [ ] `npm run deploy:production`
- [ ] `https://sight.fayshop.co.ke/auth` loads
- [ ] `https://orderwatch.fayshop.co.ke` redirects to Sight (path preserved)

### Every release

- [ ] Staging backend + frontend tested
- [ ] Production backend migrated
- [ ] Production frontend deployed
- [ ] Smoke test login + one critical page on prod

---

## 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Staging UI loads but API fails (CORS) | Origin missing or config cached | Add origin in `cors.php`; `php artisan config:clear` |
| Staging UI calls wrong API | Built with wrong `.env.staging` | Fix env → `npm run deploy:staging` again |
| `/api` 502 from Worker | `VITE_API_UPSTREAM` wrong or Laravel down | Curl upstream URL; fix env; redeploy Worker |
| Login works on workers.dev but not custom domain | DNS / custom domain not attached | Workers → Triggers → Custom Domains |
| `orderwatch` does not redirect | Old Worker build or domain not on prod Worker | Redeploy prod; ensure both domains on `orderwatchkimfay` |
| Staging sends real customer emails | Full cron / prod mailbox on staging | Disable cron; use staging from-address |
| `wrangler deploy` auth error | Not logged in / wrong account | `npx wrangler login` / check `whoami` |
| 401 after deploy | `FRONTEND_URL` mismatch or token cookie domain | Align `FRONTEND_URL` with browser origin |

### Useful commands

```bash
npx wrangler whoami
npx wrangler deployments list -c wrangler.jsonc
npx wrangler deployments list -c wrangler.staging.jsonc

curl -sI https://sight.fayshop.co.ke
curl -sI https://staging.sight.fayshop.co.ke
curl -sI https://orderwatch.fayshop.co.ke
curl -sI https://api-staging.sight.fayshop.co.ke/api
```

---

## 13. Related docs

| Doc | Topic |
|-----|--------|
| [`BACKEND-DEPLOY-UPDATE.md`](./BACKEND-DEPLOY-UPDATE.md) | Updating an existing production API |
| [`DEPLOY-VPS.md`](./DEPLOY-VPS.md) | First-time Laravel VPS install |
| [`orderwatch-custom-domain-setup.md`](./orderwatch-custom-domain-setup.md) | Custom domain / Cloudflare zone notes |
| [`cloudflare-custom-domain-setup.md`](./cloudflare-custom-domain-setup.md) | Worker custom domain options |
| [`../new name.md`](../new%20name.md) | Product rename: Kim-Fay Sight, Genius AI |

---

*Product: Kim-Fay Sight — see the business clearly. Staging and production share the same codebase; they never share Worker, database, or production secrets.*
