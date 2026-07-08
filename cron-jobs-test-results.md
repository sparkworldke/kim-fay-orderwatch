# OrderWatch — Manual Cron Test Results

**Test date:** 28 June 2026  
**Environment:** Local (`backend/`)  
**Trigger:** All scheduled commands run manually with `--source=manual`  
**Total runtime:** ~24 minutes

---

## Summary

| Result | Count |
|--------|-------|
| ✅ Success | 10 |
| ⏭️ Skipped (expected) | 1 |
| ❌ Failed | 1 |
| ⚠️ Partial (data OK, log issue) | 1 |

**Overall:** The OrderWatch cron pipeline is working. All core data-sync jobs completed successfully.

---

## Successful jobs

| Command | Cron run ID | Status | Duration | Output |
|---------|-------------|--------|----------|--------|
| `otp:prune` | — | ✅ Success | 2s | Pruned 0 expired OTP record(s) |
| `orderwatch:evaluate-order-match-notifications` | — | ✅ Success | 2s | Notification rules R5/R6 skipped (disabled in config) |
| `orderwatch:send-daily-report-fixed` | 1 | ✅ Success | 14s | Daily report completed (delivery: sent) |
| `orderwatch:sync-monitor` | 2 | ✅ Success | 1s | No sync events detected |
| `orderwatch:email-sync` | 3 | ✅ Success | 1s | Email sync completed |
| `orderwatch:order-matching` | 4 | ✅ Success | 1s | Order matching completed |
| `orderwatch:sales-order-status-sync` | 6 | ✅ Success | 6m | Sales order status update completed |
| `orderwatch:inventory-sync` | 7 | ✅ Success | 5m | Inventory sync completed |
| `orderwatch:backorders-process` | 8 | ✅ Success | 5m | Backorder processing completed |
| `orderwatch:fill-rate-sync` | 9 | ✅ Success | 31s | Fill-rate sync completed |

---

## Skipped (expected)

| Command | Cron run ID | Status | Reason |
|---------|-------------|--------|--------|
| `orderwatch:hourly-auto-match` | 10 | ⏭️ Skipped | Job is **disabled by default** (`is_enabled = false`). Enable in Administration → Cron Jobs to test. |

---

## Issues found

### 1. `acumatica:sync-categories` — Failed

```
Category sync failed: Acumatica GET CustomerClass failed: 403 Forbidden
```

- **Impact:** Customer category sync only. Other Acumatica jobs ran fine.
- **Action:** Check Acumatica API permissions for the `CustomerClass` endpoint.

### 2. `orderwatch:sales-orders-sync` — Partial

| Field | Value |
|-------|-------|
| Cron run ID | 5 |
| Cron log status | `running` (stuck — not finalized) |
| Acumatica sync log ID | 9 |
| Acumatica sync status | `completed` |
| Records synced | **1,502 / 1,502** |
| Lookback window | 2026-06-21 → 2026-06-28 |

- **Impact:** Data synced successfully. Only the `cron_run_logs` row was not finalized (likely a process timeout after sync completed).
- **Action:** Optional — manually update cron run 5 to `success`, or re-run after the 3-hour minimum interval.

---

## Acumatica sync highlights

**Sales orders (sync log #9)**

- 1,502 orders processed
- 0 failures
- 4 rejection reason notes imported
- 47 orders missing rejection reason codes (logged in filters)

**Other syncs**

- Sales order status updates — completed
- Inventory — completed
- Backorders — completed
- Fill rate — completed

---

## How to reproduce

From `backend/`:

```bash
php artisan otp:prune
php artisan orderwatch:evaluate-order-match-notifications
php artisan orderwatch:send-daily-report-fixed --source=manual
php artisan orderwatch:sync-monitor --source=manual
php artisan orderwatch:email-sync --source=manual
php artisan orderwatch:order-matching --source=manual
php artisan orderwatch:sales-orders-sync --source=manual
php artisan orderwatch:sales-order-status-sync --source=manual
php artisan orderwatch:inventory-sync --source=manual
php artisan orderwatch:backorders-process --source=manual
php artisan orderwatch:fill-rate-sync --source=manual
php artisan orderwatch:hourly-auto-match --source=manual
```

Verify results:

```bash
php artisan tinker --execute="echo json_encode(\App\Models\CronRunLog::with('cronJob:id,job_key,name')->orderByDesc('id')->limit(15)->get(['id','cron_job_id','status','trigger_source','duration_ms','output','error_summary'])->toArray(), JSON_PRETTY_PRINT);"
```

Or in SQL:

```sql
SELECT cj.job_key, crl.id, crl.status, crl.trigger_source,
       crl.duration_ms, crl.output, crl.error_summary
FROM cron_run_logs crl
JOIN cron_jobs cj ON cj.id = crl.cron_job_id
ORDER BY crl.id DESC
LIMIT 15;
```

---

## Conclusion

✅ **Cron jobs are working.** Email sync, order matching, sales order status, inventory, backorders, fill rate, sync monitor, and daily report all completed successfully.

**Follow-up items (non-blocking):**

1. Fix Acumatica `CustomerClass` 403 for category sync
2. Finalize stuck cron run log #5 for sales-order sync (data already synced)
3. Enable hourly auto-match if the combined pipeline is needed

---

*See also: [cron-jobs-guide.md](./cron-jobs-guide.md) for setup and ongoing operations.*