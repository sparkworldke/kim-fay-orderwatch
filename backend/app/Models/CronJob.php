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
        self::salesOrderPruneMissing();
        self::inventorySync();
        self::ensureWarehouseInventorySyncs();
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
        $job = self::firstOrCreate(
            ['job_key' => 'email-sync-3h'],
            [
                'name' => 'Email Synchronization',
                'description' => 'Outlook same-day mailbox sync every 3 hours. Only emails received today since the last check watermark — not full mailbox history.',
                'is_enabled' => true,
                'frequency_label' => 'Every 3 Hours',
                'cron_expression' => '0 */3 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:email-sync',
                'status' => 'active',
                'next_run_at' => now()->addHours(3)->startOfHour(),
                'settings' => [],
            ],
        );

        // Keep existing rows aligned with the same-day / 3-hour product requirement.
        $job->fill([
            'name' => 'Email Synchronization',
            'description' => 'Outlook same-day mailbox sync every 3 hours. Only emails received today since the last check watermark — not full mailbox history.',
            'frequency_label' => 'Every 3 Hours',
            'cron_expression' => '0 */3 * * *',
            'command' => 'php artisan orderwatch:email-sync',
            'trigger_type' => 'scheduler',
        ])->save();

        return $job->fresh() ?? $job;
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
        $job = self::firstOrCreate(
            ['job_key' => 'sales-order-status-sync'],
            [
                'name' => 'Sales Order Status Updates',
                'description' => 'Lightweight status sync for SO workflow. Also deletes local SOs that no longer exist in Acumatica.',
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

        $job->fill([
            'description' => 'Lightweight status sync for SO workflow. Also deletes local SOs that no longer exist in Acumatica.',
            'command' => 'php artisan orderwatch:sales-order-status-sync',
        ])->save();

        return $job->fresh() ?? $job;
    }

    public static function salesOrderPruneMissing(): self
    {
        $job = self::firstOrCreate(
            ['job_key' => 'sales-order-prune-missing'],
            [
                'name' => 'Sales Order Prune Missing',
                'description' => 'Deletes local sales orders that were removed or are no longer found in Acumatica (wider lookback than status sync).',
                'is_enabled' => true,
                'frequency_label' => 'Every 6 Hours',
                'cron_expression' => '30 1,7,13,19 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:sales-order-prune-missing',
                'status' => 'active',
                'next_run_at' => now()->addHours(6),
                'settings' => [
                    'prune_lookback_days' => 60,
                    'prune_max_orders' => 3000,
                ],
            ],
        );

        $job->fill([
            'name' => 'Sales Order Prune Missing',
            'description' => 'Deletes local sales orders that were removed or are no longer found in Acumatica (wider lookback than status sync).',
            'frequency_label' => 'Every 6 Hours',
            'cron_expression' => '30 1,7,13,19 * * *',
            'command' => 'php artisan orderwatch:sales-order-prune-missing',
            'trigger_type' => 'scheduler',
        ])->save();

        return $job->fresh() ?? $job;
    }

    public static function inventorySync(): self
    {
        // Legacy all-warehouse job — kept for manual/full runs; auto schedule is paused.
        // Prefer per-warehouse jobs from ensureWarehouseInventorySyncs().
        $job = self::firstOrCreate(
            ['job_key' => 'inventory-sync-5h'],
            [
                'name' => 'Inventory Synchronization (All Warehouses)',
                'description' => 'Legacy all-warehouse inventory sync. Prefer per-warehouse jobs (inventory-sync-*). Use manual/full when needed.',
                'is_enabled' => false,
                'frequency_label' => 'Manual / paused',
                'cron_expression' => '0 8,12 * * *',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:inventory-sync --job-key=inventory-sync-5h',
                'status' => 'paused',
                'next_run_at' => null,
                'settings' => [
                    'warehouse_id' => null,
                    'item_class'   => null,
                    'min_qty'      => null,
                ],
            ],
        );

        $job->fill([
            'name' => 'Inventory Synchronization (All Warehouses)',
            'description' => 'Legacy all-warehouse inventory sync. Prefer per-warehouse jobs (inventory-sync-*). Use manual/full when needed.',
            'is_enabled' => false,
            'status' => 'paused',
            'frequency_label' => 'Manual / paused',
            'command' => 'php artisan orderwatch:inventory-sync --job-key=inventory-sync-5h',
        ])->save();

        return $job->fresh() ?? $job;
    }

    /**
     * @return list<string>
     */
    public static function inventoryWarehouses(): array
    {
        $warehouses = config('inventory.warehouses', [
            'DTC', 'FGS', 'FGS2', 'FGS2 RETURNS', 'MSA', 'EXPORT', 'PRMS', 'RMS1', 'TRMS',
        ]);

        return array_values(array_filter(array_map(
            static fn ($w) => strtoupper(trim((string) $w)),
            is_array($warehouses) ? $warehouses : [],
        )));
    }

    public static function inventoryWarehouseLabel(string $warehouseId): string
    {
        $id = strtoupper(trim($warehouseId));
        $labels = config('inventory.warehouse_labels', []);

        return is_array($labels) && isset($labels[$id])
            ? (string) $labels[$id]
            : $id;
    }

    public static function inventoryWarehouseJobKey(string $warehouseId): string
    {
        $slug = strtolower(trim($warehouseId));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        return 'inventory-sync-'.$slug;
    }

    public static function ensureWarehouseInventorySyncs(): void
    {
        $activeKeys = [];
        foreach (self::inventoryWarehouses() as $index => $warehouse) {
            $job = self::inventoryWarehouseSync($warehouse, $index);
            $activeKeys[] = $job->job_key;
        }

        // Pause cron rows for warehouses removed from config (keep history).
        self::query()
            ->where('job_key', 'like', 'inventory-sync-%')
            ->where('job_key', '!=', 'inventory-sync-5h')
            ->whereNotIn('job_key', $activeKeys)
            ->update([
                'is_enabled' => false,
                'status' => 'paused',
                'frequency_label' => 'Paused (not in warehouse list)',
            ]);
    }

    public static function inventoryWarehouseSync(string $warehouse, int $index = 0): self
    {
        $warehouse = strtoupper(trim($warehouse));
        $label = self::inventoryWarehouseLabel($warehouse);
        $jobKey = self::inventoryWarehouseJobKey($warehouse);
        $times = self::warehouseStockSyncCronExpressions($index);
        $labels = self::warehouseStockSyncTimeLabels($index);
        $cronExpressions = array_column($times, 'cron');
        $primaryCron = $cronExpressions[0] ?? '30 8 * * *';
        $frequency = 'Twice daily: '.implode(' & ', $labels).' EAT';
        // Quote warehouse for CLI when it contains spaces (e.g. FGS2 RETURNS).
        $warehouseArg = str_contains($warehouse, ' ') ? '"'.$warehouse.'"' : $warehouse;

        $job = self::firstOrCreate(
            ['job_key' => $jobKey],
            [
                'name' => "Inventory Stock Sync — {$label}",
                'description' => "Updates SKU stock positions for warehouse {$label} ({$warehouse}) from Acumatica. Morning and midday waves, staggered 30 minutes after the previous warehouse.",
                'is_enabled' => true,
                'frequency_label' => $frequency,
                'cron_expression' => $primaryCron,
                'trigger_type' => 'scheduler',
                'command' => "php artisan orderwatch:inventory-sync --job-key={$jobKey} --warehouse={$warehouseArg}",
                'status' => 'active',
                'next_run_at' => now()->addHour(),
                'settings' => [
                    'warehouse_id' => $warehouse,
                    'warehouse_label' => $label,
                    'item_class' => null,
                    'min_qty' => null,
                    'stocks_only' => true,
                    'cron_expressions' => $cronExpressions,
                    'schedule_times' => $labels,
                    'stagger_index' => $index,
                ],
            ],
        );

        $job->fill([
            'name' => "Inventory Stock Sync — {$label}",
            'description' => "Updates SKU stock positions for warehouse {$label} ({$warehouse}) from Acumatica. Morning and midday waves, staggered 30 minutes after the previous warehouse.",
            'is_enabled' => true,
            'status' => 'active',
            'frequency_label' => $frequency,
            'cron_expression' => $primaryCron,
            'trigger_type' => 'scheduler',
            'command' => "php artisan orderwatch:inventory-sync --job-key={$jobKey} --warehouse={$warehouseArg}",
            'settings' => array_merge($job->settings ?? [], [
                'warehouse_id' => $warehouse,
                'warehouse_label' => $label,
                'stocks_only' => true,
                'cron_expressions' => $cronExpressions,
                'schedule_times' => $labels,
                'stagger_index' => $index,
            ]),
        ])->save();

        return $job->fresh() ?? $job;
    }

    /**
     * @return list<array{cron: string, label: string, total_minutes: int}>
     */
    public static function warehouseStockSyncCronExpressions(int $index): array
    {
        $morningStart = self::parseHhMm((string) config('inventory.stock_sync.morning_start', '08:30'));
        $middayStart = self::parseHhMm((string) config('inventory.stock_sync.midday_start', '12:00'));
        $stagger = max(0, (int) config('inventory.stock_sync.stagger_minutes', 30));
        $offset = $index * $stagger;

        $slots = [$morningStart + $offset, $middayStart + $offset];
        $result = [];
        foreach ($slots as $totalMinutes) {
            $totalMinutes = $totalMinutes % (24 * 60);
            $hour = intdiv($totalMinutes, 60);
            $minute = $totalMinutes % 60;
            $result[] = [
                'cron' => sprintf('%d %d * * *', $minute, $hour),
                'label' => sprintf('%02d:%02d', $hour, $minute),
                'total_minutes' => $totalMinutes,
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public static function warehouseStockSyncTimeLabels(int $index): array
    {
        return array_map(
            static fn (array $slot) => $slot['label'],
            self::warehouseStockSyncCronExpressions($index),
        );
    }

    private static function parseHhMm(string $value): int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
            return 8 * 60 + 30;
        }

        $hour = max(0, min(23, (int) $m[1]));
        $minute = max(0, min(59, (int) $m[2]));

        return ($hour * 60) + $minute;
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
