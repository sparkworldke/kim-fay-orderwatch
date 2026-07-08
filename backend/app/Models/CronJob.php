<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CronJob extends Model
{
    protected $fillable = [
        'job_key',
        'name',
        'description',
        'is_enabled',
        'cron_expression',
        'frequency_label',
        'trigger_type',
        'command',
        'status',
        'last_run_at',
        'last_success_at',
        'last_failure_at',
        'last_run_status',
        'last_duration_ms',
        'next_run_at',
        'settings',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'next_run_at' => 'datetime',
            'is_enabled' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function runLogs(): HasMany
    {
        return $this->hasMany(CronRunLog::class);
    }

    public static function ensureDefaults(): void
    {
        self::hourlyAutoMatch();
        self::emailSync();
        self::orderMatching();
        self::salesOrderSync();
        self::salesOrderStatusSync();
        self::inventorySync();
        self::backorderProcessing();
        self::fillRateSync();
        self::fillRateNoon();
        self::syncMonitor();
        self::systemHealthCheck();
        self::otpPrune();
        self::orderMatchNotificationEvaluation();
        self::fixedDailyReport();
    }

    public function computedNextRunAt(): ?\DateTimeInterface
    {
        if (! $this->cron_expression) {
            return null;
        }

        try {
            return CronExpression::factory($this->cron_expression)->getNextRunDate(now());
        } catch (\Throwable) {
            return $this->next_run_at;
        }
    }

    public static function hourlyAutoMatch(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'email-sales-order-auto-match'],
            [
                'name' => 'Email ↔ Sales Order Auto Match',
                'description' => 'Hourly Outlook, Acumatica Sales Order, and guarded matching pipeline.',
                'is_enabled' => false, 'frequency_label' => 'Hourly', 'cron_expression' => '0 * * * *',
                'trigger_type' => 'scheduler', 'command' => 'php artisan orderwatch:hourly-auto-match',
                'status' => 'paused', 'next_run_at' => now()->addHour()->startOfHour(),
                'settings' => [
                    'email_sync_enabled' => true, 'acumatica_sync_enabled' => true,
                    'matching_enabled' => true, 'sales_order_lookback_days' => 7,
                    'deterministic_auto_link' => true, 'ai_auto_link' => false,
                ],
            ],
        );
    }

    public static function emailSync(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'email-sync-3h'],
            [
                'name' => 'Email Synchronization',
                'description' => 'Outlook mailbox sync into the dashboard database. Runs at odd hours to alternate with order sync.',
                'is_enabled' => true,
                'frequency_label' => 'Every 2 Hours (offset)',
                'cron_expression' => '0 1,3,5,7,9,11,13,15,17,19,21,23 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:email-sync',
                'status' => 'active',
                'next_run_at' => now()->addHours(2),
                'settings' => [],
            ],
        );
    }

    public static function orderMatching(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'order-matching-3h'],
            [
                'name' => 'Order Matching',
                'description' => 'PO extraction and deterministic email-to-order matching.',
                'is_enabled' => true,
                'frequency_label' => 'Every 3 Hours',
                'cron_expression' => '25 */3 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:order-matching',
                'status' => 'active',
                'next_run_at' => now()->addHours(3),
                'settings' => [],
            ],
        );
    }

    public static function salesOrderSync(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'sales-order-sync-3h'],
            [
                'name' => 'Sales Order Synchronization',
                'description' => 'Acumatica sales order sync. Rolling 2-hour window each run; deep 3-day scan at 4PM.',
                'is_enabled' => true,
                'frequency_label' => 'Every 2 Hours',
                'cron_expression' => '0 */2 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:sales-orders-sync',
                'status' => 'active',
                'next_run_at' => now()->addHours(2),
                'settings' => [
                    'lookback_hours'          => 2,   // normal runs: look back this many hours
                    'deep_scan_hour'          => 16,  // hour (in CRON_TIMEZONE) that triggers deep scan
                    'deep_scan_lookback_days' => 3,   // deep scan: look back this many days
                ],
            ],
        );
    }

    public static function salesOrderStatusSync(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'sales-order-status-sync'],
            [
                'name' => 'Sales Order Status Updates',
                'description' => 'Lightweight status-only sync for SO workflow progression.',
                'is_enabled' => true,
                'frequency_label' => 'Every 30 Minutes',
                'cron_expression' => '12,42 * * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:sales-order-status-sync',
                'status' => 'active',
                'next_run_at' => now()->addMinutes(30),
                'settings' => [
                    'status_sync_lookback_days' => 14,
                    'status_sync_max_orders' => 1500,
                ],
            ],
        );
    }

    public static function inventorySync(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'inventory-sync-5h'],
            [
                'name' => 'Inventory Synchronization',
                'description' => 'Acumatica inventory stock sync to keep on-hand levels current.',
                'is_enabled' => true,
                'frequency_label' => 'Twice Daily (8AM, 12PM)',
                'cron_expression' => '0 8,12 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:inventory-sync',
                'status' => 'active',
                'next_run_at' => now()->addHours(4),
                'settings' => [
                    'warehouse_id' => null,  // e.g. "MAIN" — filters to one Acumatica warehouse
                    'item_class'   => null,  // e.g. "BEVERAGES" — filters to one product category
                    'min_qty'      => null,  // e.g. 1 — skips items below this QtyOnHand
                ],
            ],
        );
    }

    public static function backorderProcessing(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'backorders-daily-4pm'],
            [
                'name' => 'Backorder Processing',
                'description' => 'Daily backorder validation and processing.',
                'is_enabled' => true,
                'frequency_label' => 'Daily at 00:30',
                'cron_expression' => '30 0 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:backorders-process',
                'status' => 'active',
                'next_run_at' => now()->addDay(),
                'settings' => [],
            ],
        );
    }

    public static function fillRateSync(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'fill-rate-nightly'],
            [
                'name' => 'Fill Rate Sync (Midnight)',
                'description' => 'Fill-rate computation at 00:01 from Acumatica order line fulfillment.',
                'is_enabled' => true,
                'frequency_label' => 'Daily at 00:01',
                'cron_expression' => '1 0 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:fill-rate-sync',
                'status' => 'active',
                'next_run_at' => now()->addDay(),
                'settings' => [
                    'fill_rate_lookback_days' => 2,
                ],
            ],
        );
    }

    public static function fillRateNoon(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'fill-rate-noon'],
            [
                'name' => 'Fill Rate Sync (Noon)',
                'description' => 'Midday fill-rate computation at 12:30PM from Acumatica order line fulfillment.',
                'is_enabled' => true,
                'frequency_label' => 'Daily at 12:30PM',
                'cron_expression' => '30 12 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:fill-rate-sync --variant=noon',
                'status' => 'active',
                'next_run_at' => now()->addDay(),
                'settings' => [
                    'fill_rate_lookback_days' => 1,
                ],
            ],
        );
    }

    public static function systemHealthCheck(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'system-health-daily'],
            [
                'name' => 'System Health Check',
                'description' => 'Daily system health report emailed to the tech lead.',
                'is_enabled' => true,
                'frequency_label' => 'Daily at 6AM',
                'cron_expression' => '0 6 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:system-health',
                'status' => 'active',
                'next_run_at' => now()->addDay(),
                'settings' => [
                    'recipient' => 'commercialtechlead@kimfay.com',
                ],
            ],
        );
    }

    public static function syncMonitor(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'sync-monitor-alerts'],
            [
                'name' => 'Sync Monitor Alerts',
                'description' => 'Sends email alerts when new data is synced or when sync guardrails fail.',
                'is_enabled' => true,
                'frequency_label' => 'Every Minute',
                'cron_expression' => '* * * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:sync-monitor',
                'status' => 'active',
                'next_run_at' => now()->addMinute(),
                'settings' => [
                    'last_seen_cron_run_log_id' => 0,
                    'last_seen_email_id' => 0,
                ],
            ],
        );
    }

    public static function otpPrune(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'otp-prune'],
            [
                'name' => 'OTP Prune',
                'description' => 'Prunes expired one-time passwords.',
                'is_enabled' => true,
                'frequency_label' => 'Every 15 Minutes',
                'cron_expression' => '*/15 * * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan otp:prune',
                'status' => 'active',
                'next_run_at' => now()->addMinutes(15),
                'settings' => [],
            ],
        );
    }

    public static function customerCategorySync(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'acumatica-customer-category-sync'],
            [
                'name' => 'Acumatica Customer Category Sync',
                'description' => 'Synchronizes customer categories from Acumatica.',
                'is_enabled' => true,
                'frequency_label' => 'Hourly',
                'cron_expression' => '0 * * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan acumatica:sync-categories',
                'status' => 'active',
                'next_run_at' => now()->addHour()->startOfHour(),
                'settings' => [],
            ],
        );
    }

    public static function orderMatchNotificationEvaluation(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'order-match-notification-evaluation'],
            [
                'name' => 'Order Match Notification Evaluation',
                'description' => 'Evaluates notification rules for order-match backlog and duplicate PO alerts.',
                'is_enabled' => true,
                'frequency_label' => 'Hourly',
                'cron_expression' => '0 * * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:evaluate-order-match-notifications',
                'status' => 'active',
                'next_run_at' => now()->addHour()->startOfHour(),
                'settings' => [],
            ],
        );
    }

    public static function fixedDailyReport(): self
    {
        return self::firstOrCreate(
            ['job_key' => 'daily-report-fixed-scheduler'],
            [
                'name' => 'Daily Report Fixed Scheduler',
                'description' => 'Sends the fixed daily management report from the scheduler.',
                'is_enabled' => true,
                'frequency_label' => 'Tue-Sat at 7AM',
                'cron_expression' => '0 7 * * 2-6',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:send-daily-report-fixed --source=scheduler',
                'status' => 'active',
                'next_run_at' => now()->addDay(),
                'settings' => [],
            ],
        );
    }
}
