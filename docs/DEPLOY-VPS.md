# OrderWatch Backend — VPS Deploy Guide

Deploy the Laravel API (`backend/`) on a Linux VPS (Ubuntu 22.04 / 24.04 recommended).

**Frontend (already on Cloudflare):** `https://orderwatch.fayshop.co.ke`  
**API base URL (example):** `https://api.orderwatch.fayshop.co.ke/api`  
(Use your real API hostname wherever this guide shows placeholders.)

> **Updating an existing production server?** Use the shorter guide:  
> [`BACKEND-DEPLOY-UPDATE.md`](./BACKEND-DEPLOY-UPDATE.md) (migrations, crons, wrangler frontend, smoke tests).

---

## 1. Server requirements

| Component | Version / notes |
|-----------|-----------------|
| OS | Ubuntu 22.04+ or Debian 12+ |
| PHP | **8.3** with extensions below |
| Composer | 2.x |
| MySQL / MariaDB | MySQL 8 or MariaDB 10.6+ |
| Web server | Nginx (recommended) or Apache |
| SSL | Let’s Encrypt (certbot) |
| RAM | 2 GB minimum (4 GB better for Acumatica/email sync) |

### PHP extensions

```bash
sudo apt update
sudo apt install -y \
  nginx mysql-server certbot python3-certbot-nginx \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml \
  php8.3-curl php8.3-zip php8.3-bcmath php8.3-gd php8.3-intl \
  php8.3-tokenizer php8.3-fileinfo php8.3-opcache \
  unzip git curl
```

Install Composer:

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

---

## 2. Create app user and directories

```bash
sudo adduser --disabled-password --gecos "" orderwatch
sudo mkdir -p /var/www/orderwatch
sudo chown orderwatch:orderwatch /var/www/orderwatch
```

Deploy path used in this guide:

```text
/var/www/orderwatch/backend
```

---

## 3. Upload the backend code

### Option A — Git (recommended)

```bash
sudo -u orderwatch -i
cd /var/www/orderwatch
git clone <YOUR_REPO_URL> repo
# If the monorepo contains frontend + backend:
ln -s /var/www/orderwatch/repo/backend /var/www/orderwatch/backend
# Or copy only backend:
# rsync -a repo/backend/ /var/www/orderwatch/backend/
```

### Option B — rsync / scp from your machine

From your local project root:

```bash
rsync -avz --exclude vendor --exclude node_modules --exclude storage/logs \
  --exclude .env \
  ./backend/ user@YOUR_VPS_IP:/var/www/orderwatch/backend/
```

Then on the VPS:

```bash
sudo chown -R orderwatch:orderwatch /var/www/orderwatch/backend
```

---

## 4. MySQL database

```bash
sudo mysql
```

```sql
CREATE DATABASE kimfay_orderwatch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'orderwatch'@'localhost' IDENTIFIED BY 'STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON kimfay_orderwatch.* TO 'orderwatch'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

---

## 5. Environment file

```bash
cd /var/www/orderwatch/backend
cp .env.example .env
nano .env
```

### Production `.env` template

Replace every `CHANGE_ME` value. Do **not** commit this file.

```env
APP_NAME="OrderWatch"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://api.orderwatch.fayshop.co.ke

# Must match the Cloudflare-hosted frontend
FRONTEND_URL=https://orderwatch.fayshop.co.ke

CRON_TIMEZONE=Africa/Nairobi

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file
BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kimfay_orderwatch
DB_USERNAME=orderwatch
DB_PASSWORD=STRONG_PASSWORD_HERE

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database

MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.office365.com
MAIL_PORT=587
MAIL_USERNAME=CHANGE_ME
MAIL_PASSWORD=CHANGE_ME
MAIL_FROM_ADDRESS="noreply@fayshop.co.ke"
MAIL_FROM_NAME="${APP_NAME}"
MAIL_EHLO_DOMAIN=fayshop.co.ke

# Acumatica
ACUMATICA_BASE_URL=https://kimfay.acumatica.com
ACUMATICA_TOKEN_URL=https://kimfay.acumatica.com/identity/connect/token
ACUMATICA_CLIENT_ID=CHANGE_ME
ACUMATICA_CLIENT_SECRET=CHANGE_ME
ACUMATICA_USERNAME=CHANGE_ME
ACUMATICA_PASSWORD=CHANGE_ME
ACUMATICA_ENDPOINT=IpayV2
ACUMATICA_VERSION=22.200.001
ACUMATICA_TENANT="Kim-Fay Limited"

# Microsoft / Outlook Graph
MICROSOFT_CLIENT_ID=CHANGE_ME
MICROSOFT_CLIENT_SECRET=CHANGE_ME
MICROSOFT_TENANT_ID=common
MICROSOFT_REDIRECT_URI="${APP_URL}/api/admin/mailboxes/oauth/callback"
MICROSOFT_GRAPH_USER_AGENT="OrderWatch/1.0 (Kim-Fay OrderWatch; +https://orderwatch.fayshop.co.ke)"
MICROSOFT_FRONTEND_URL=https://orderwatch.fayshop.co.ke

# AI (optional)
OPENAI_API_KEY=
ANTHROPIC_API_KEY=
```

Generate app key and install dependencies:

```bash
cd /var/www/orderwatch/backend
composer install --no-dev --optimize-autoloader
php artisan key:generate
```

---

## 6. Storage permissions and first migrate

```bash
cd /var/www/orderwatch/backend

sudo chown -R orderwatch:www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 664 {} \;

php artisan migrate --force
php artisan db:seed --force   # first deploy only — creates roles + default admins

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Default seeded users (change passwords immediately):

| Email | Password | Role |
|-------|----------|------|
| admin@fayshop.co.ke | password | Administrator |
| csm@fayshop.co.ke | password | Customer Service Manager |

---

## 7. Nginx site config

Create `/etc/nginx/sites-available/orderwatch-api`:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name api.orderwatch.fayshop.co.ke;

    root /var/www/orderwatch/backend/public;
    index index.php;

    client_max_body_size 32M;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
        fastcgi_send_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    access_log /var/log/nginx/orderwatch-api.access.log;
    error_log  /var/log/nginx/orderwatch-api.error.log;
}
```

Enable and reload:

```bash
sudo ln -sf /etc/nginx/sites-available/orderwatch-api /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

### SSL (Let’s Encrypt)

Point DNS `A` record for `api.orderwatch.fayshop.co.ke` to the VPS public IP, then:

```bash
sudo certbot --nginx -d api.orderwatch.fayshop.co.ke
```

---

## 8. PHP-FPM tuning (recommended)

Edit `/etc/php/8.3/fpm/pool.d/www.conf` or a dedicated pool:

```ini
pm = dynamic
pm.max_children = 20
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 8
```

In `/etc/php/8.3/fpm/php.ini` (and CLI if needed):

```ini
memory_limit = 512M
upload_max_filesize = 32M
post_max_size = 32M
max_execution_time = 300
```

```bash
sudo systemctl restart php8.3-fpm
```

---

## 9. Cron scheduler (required)

OrderWatch crons (Acumatica sync, email match, fill-rate, daily report, etc.) run via Laravel’s scheduler. **Without this, no scheduled jobs run.**

```bash
sudo crontab -u orderwatch -e
```

Add:

```cron
* * * * * cd /var/www/orderwatch/backend && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Verify PHP path:

```bash
which php
```

List registered schedule:

```bash
cd /var/www/orderwatch/backend && php artisan schedule:list
```

---

## 10. Queue worker (optional)

Most mailbox/match jobs use `dispatchSync` today. A worker is still useful if you enqueue jobs later or clear the `jobs` table:

```bash
sudo nano /etc/systemd/system/orderwatch-queue.service
```

```ini
[Unit]
Description=OrderWatch Laravel Queue Worker
After=network.target mysql.service

[Service]
User=orderwatch
Group=www-data
Restart=always
RestartSec=3
WorkingDirectory=/var/www/orderwatch/backend
ExecStart=/usr/bin/php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now orderwatch-queue
sudo systemctl status orderwatch-queue
```

---

## 11. Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

---

## 12. Connect frontend → API

On the Cloudflare Worker / frontend side, set the API base URL to:

```text
https://api.orderwatch.fayshop.co.ke/api
```

Confirm CORS already allows the frontend origin in `config/cors.php`:

- `https://orderwatch.fayshop.co.ke`

If you use another frontend host, add it to `allowed_origins` and redeploy.

Also register the Microsoft OAuth redirect URI in Azure AD:

```text
https://api.orderwatch.fayshop.co.ke/api/admin/mailboxes/oauth/callback
```

---

## 13. Smoke tests

```bash
# Health
curl -sS https://api.orderwatch.fayshop.co.ke/up

# Login
curl -sS -X POST https://api.orderwatch.fayshop.co.ke/api/auth/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"email":"admin@fayshop.co.ke","password":"password"}'
```

Expected: HTTP 200 with `token` and `user`.

Check logs if something fails:

```bash
tail -n 100 /var/www/orderwatch/backend/storage/logs/laravel.log
sudo tail -n 50 /var/log/nginx/orderwatch-api.error.log
```

---

## 14. Update / redeploy script

Save as `/var/www/orderwatch/backend/deploy.sh` (or run the steps manually):

```bash
#!/usr/bin/env bash
set -euo pipefail

cd /var/www/orderwatch/backend

# If using git:
# git pull origin main

composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart 2>/dev/null || true

# Optional: reload PHP-FPM to clear opcache after large deploys
# sudo systemctl reload php8.3-fpm

echo "Deploy complete: $(date -Is)"
```

```bash
chmod +x /var/www/orderwatch/backend/deploy.sh
sudo -u orderwatch /var/www/orderwatch/backend/deploy.sh
```

---

## 15. Checklist

- [ ] PHP 8.3 + extensions installed  
- [ ] MySQL database + user created  
- [ ] Code under `/var/www/orderwatch/backend`  
- [ ] `.env` production values (especially `APP_URL`, `FRONTEND_URL`, DB, Acumatica, Microsoft, mail)  
- [ ] `composer install --no-dev`  
- [ ] `php artisan key:generate`  
- [ ] `storage` / `bootstrap/cache` writable by `www-data`  
- [ ] `migrate` (+ seed on first install)  
- [ ] Nginx root → `public/`  
- [ ] SSL via certbot  
- [ ] Cron: `* * * * * php artisan schedule:run`  
- [ ] Frontend `VITE_API_BASE_URL` / API client points at `/api`  
- [ ] Azure redirect URI updated  
- [ ] Default admin password changed  
- [ ] `APP_DEBUG=false`  

---

## 16. Troubleshooting

| Symptom | Fix |
|---------|-----|
| 500 on all routes | `storage/logs/laravel.log`; check `APP_KEY`, DB credentials |
| 502 Bad Gateway | `sudo systemctl status php8.3-fpm`; check sock path in Nginx |
| CORS errors in browser | Add frontend origin to `config/cors.php`, then `php artisan config:cache` |
| Jobs never run | Confirm crontab for `orderwatch` user; `php artisan schedule:list` |
| OAuth mailbox fail | `MICROSOFT_REDIRECT_URI` must match Azure app registration exactly |
| Timeout on sync | Raise `fastcgi_read_timeout` and PHP `max_execution_time` (already 300 in sample) |
| Permission denied writing logs | `chown -R orderwatch:www-data storage bootstrap/cache` and `chmod` as above |

---

## Quick reference paths

| Item | Path |
|------|------|
| App root | `/var/www/orderwatch/backend` |
| Web root | `/var/www/orderwatch/backend/public` |
| Env | `/var/www/orderwatch/backend/.env` |
| Nginx site | `/etc/nginx/sites-available/orderwatch-api` |
| Laravel log | `/var/www/orderwatch/backend/storage/logs/laravel.log` |
| API health | `GET /up` |
| API prefix | `/api/*` |
