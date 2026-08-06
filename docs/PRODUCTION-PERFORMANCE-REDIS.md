# Production Dashboard Performance and Redis

This runbook deploys the production summary tables, activates local-only Redis
on the Hestia VPS, warms production caches, and provides validation and rollback.

## 1. Deploy and build summaries

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend

/usr/bin/php8.3 artisan migrate --force
/usr/bin/php8.3 artisan production:summaries-refresh --from=2023-01-01
```

The backfill processes one month at a time. Keep the existing dashboard online
while it runs. Normal five-minute scheduler runs refresh the current month,
the rolling seven-day correction window, and today's stock snapshot.

Make sure the existing Hestia cron continues running Laravel's scheduler:

```cron
* * * * * /usr/bin/php8.3 /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/artisan schedule:run >> /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/cron.log 2>&1
```

## 2. Install Redis and the PHP extension

```bash
sudo apt update
sudo apt install -y redis-server php8.3-redis

sudo systemctl enable redis-server
sudo systemctl start redis-server
sudo systemctl status redis-server --no-pager

redis-cli ping
php8.3 -m | grep -i redis
```

`redis-cli ping` must return:

```text
PONG
```

If the website uses a PHP-FPM version other than 8.3, install the matching
`phpX.Y-redis` package and restart that FPM service as well.

## 3. Secure and limit Redis

Edit `/etc/redis/redis.conf`:

```conf
bind 127.0.0.1 ::1
protected-mode yes
supervised systemd
maxmemory 256mb
maxmemory-policy allkeys-lru
```

Use `128mb` instead when total VPS memory is below 2 GB. Do not create a Hestia
firewall rule for port 6379 and do not bind Redis to a public address.

```bash
sudo systemctl restart redis-server
sudo systemctl restart php8.3-fpm
redis-cli CONFIG GET maxmemory
redis-cli CONFIG GET maxmemory-policy
```

## 4. Configure Laravel

Edit:

`/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/.env`

```dotenv
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_PREFIX=kimfay_orderwatch_database_
CACHE_PREFIX=kimfay_orderwatch_cache_
CACHE_STORE=redis

# Keep queues database-backed for the first rollout.
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=1200
```

Activate the configuration:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend

/usr/bin/php8.3 artisan optimize:clear
/usr/bin/php8.3 artisan config:cache
/usr/bin/php8.3 artisan cache:clear
/usr/bin/php8.3 artisan about
```

## 5. Validate and warm the cache

```bash
/usr/bin/php8.3 artisan tinker --execute="cache()->put('redis-health-check', 'ok', 60); dump(cache()->get('redis-health-check'));"
redis-cli -n 1 DBSIZE
redis-cli INFO memory
redis-cli INFO stats

/usr/bin/php8.3 artisan production:summaries-refresh --recent
```

The Tinker command must output `"ok"`. Then log in and request:

```text
/api/operations/production/version
/api/operations/production/reference
/api/operations/production/summary?ownership=manufactured
/api/operations/production/inventory?ownership=manufactured&per_page=75&page=1
```

The inventory request must return only one page. It must not issue a raw
multi-year sales aggregation.

## 6. Monitoring

```bash
redis-cli INFO memory
redis-cli INFO stats
redis-cli INFO keyspace
redis-cli SLOWLOG GET 20

cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend
/usr/bin/php8.3 artisan queue:failed
tail -f storage/logs/laravel.log
```

Track:

- `used_memory` versus `maxmemory`.
- `keyspace_hits` and `keyspace_misses`.
- Evictions; frequent evictions indicate the memory cap is too low or keys are too broad.
- Production endpoint latency and HTTP 504 counts.
- Last stock and sales refresh timestamps from the version endpoint.

## 7. Optional Redis queues

Do not switch queues until a supervised worker is configured. When ready, use:

```dotenv
QUEUE_CONNECTION=redis
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=1200
```

Example Supervisor program:

```ini
[program:kimfay-orderwatch-worker]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php8.3 /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/artisan queue:work redis --sleep=2 --tries=2 --timeout=1100
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=kimfaydev
numprocs=1
redirect_stderr=true
stdout_logfile=/home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend/storage/logs/queue-worker.log
stopwaitsecs=1200
```

After adding it:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status
```

## 8. Rollback

Change `.env` back to:

```dotenv
CACHE_STORE=database
QUEUE_CONNECTION=database
```

Then:

```bash
cd /home/kimfaydev/web/datacontroller.fayshop.co.ke/public_html/backend
/usr/bin/php8.3 artisan optimize:clear
/usr/bin/php8.3 artisan config:cache
sudo systemctl stop redis-server
```

Stopping Redis is optional once Laravel no longer uses it. Do not roll back the
summary-table migration merely to disable Redis; the summary tables are also
the primary protection against expensive raw-history queries.

## 9. Shared dashboard response caches

The Redis rollout also caches the largest non-production JSON reads after
authentication and data-scope enforcement:

| Domain | Endpoints | TTL |
|---|---|---:|
| Orders | list and statistics | 60 seconds |
| Order filters and SO reason audit | filter options and audit calculations | 5 minutes |
| Inventory | summary and paginated rows | 60 seconds |
| Backorders | summary, rows, analytics, resolved, SKU and account breakdowns | 2 minutes |
| Fill rate | summary, rows, SKU and out-of-stock breakdowns | 2 minutes |
| Business optimization | status and dashboard calculations | 1–2 minutes |
| Products not delivered | SKU, outlet and SO calculations | 2 minutes |
| Customer analytics and KP accounts | customer feed, insights and account lists | 2–5 minutes |
| Sales portfolio | summary, orders, backorders and items not ordered | 2–5 minutes |
| Shared references | brand filters and reason taxonomy | 1 hour |

Exports, file downloads, mutations and non-200 responses are never cached.
Every key contains the authenticated user ID, normalized query filters and the
domain generation. This prevents sales-consultant or departmental data scopes
from leaking between users.

Successful Acumatica order, inventory, backorder and fill-rate syncs bump their
affected domain generations. Manual order workflow changes, consultant
assignments and backorder-reason edits do the same. Old Redis entries then
become unreachable and expire under the configured LRU memory policy.

Confirm caching through the endpoint response header:

```text
X-Dashboard-Cache: MISS
X-Dashboard-Cache: HIT
```

The first identical request should report `MISS`; the next request from the
same user with the same filters should report `HIT`.

Inspect domain generations without blocking production Redis:

```bash
redis-cli -n 1 --scan --pattern '*domain-cache:generation:*'
```

Do not use `KEYS` on production Redis because it can block the server while
scanning a large keyspace.
