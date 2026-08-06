<?php

namespace App\Console\Commands;

use App\Models\AcumaticaSyncLog;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunSalesOrderSync extends Command
{
    protected $signature = 'orderwatch:sales-orders-sync
                            {--source=manual : Trigger source (manual|cli|scheduler). Use manual/cli for ops so min-interval is not applied}
                            {--user-id=}
                            {--date-from= : Override lookback; start date (Y-m-d)}
                            {--date-to= : Override lookback; end date (Y-m-d)}
                            {--updates-only : Only recheck existing local SOs in the date window (no full import)}
                            {--max-orders=5000 : Cap for --updates-only mode}
                            {--force : Break a stale cron lock and bypass min-interval (after Ctrl+C / killed sync)}
                            {--skip-backorders : Do not chain backorder processing after a full SO sync}
                            {--with-backorders : Force chain backorders even for updates-only (default: full sync only)}';

    protected $description = 'Sync Acumatica sales orders, then (by default on full import) chain backorder processing. Use --force for a manual catch-up when the schedule was missed.';

    public function handle(
        CronExecutionService $cron,
        AcumaticaSalesOrderSyncService $salesOrders,
        AcumaticaBackorderSyncService $backorders,
    ): int
    {
        $job = CronJob::salesOrderSync();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $force = (bool) $this->option('force');
        $source = trim((string) $this->option('source')) ?: 'manual';
        // Date-range ops runs are always intentional — never treat as scheduler spam.
        if ($this->option('date-from') || $this->option('date-to')) {
            if ($source === 'scheduler') {
                $source = 'manual';
            }
            $force = true;
        }
        if ($force) {
            $this->warn('Force mode: releasing stale lock / bypassing min-interval for job_key='.$job->job_key);
        }

        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $salesOrders, $backorders, $job, $source, $userId),
            $source,
            $userId,
            100 * 60,    // ~100 min anti-spam for scheduler (job is every 2h; manual/cli/force bypass)
            3 * 60 * 60, // lock TTL 3h (full sync can take a while)
            $force,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        if ($run->status === 'skipped' && filled($run->error_summary)) {
            $this->warn((string) $run->error_summary);
        }
        if (filled($run->output) && $run->status !== 'skipped') {
            $this->line((string) $run->output);
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(
        CronRunLog $run,
        AcumaticaSalesOrderSyncService $salesOrders,
        AcumaticaBackorderSyncService $backorders,
        CronJob $job,
        string $source,
        ?int $userId,
    ): array
    {
        $started  = hrtime(true);
        $settings = $job->settings ?? [];
        $tz       = (string) config('cron.timezone', config('app.timezone'));
        $nowLocal = now($tz);

        // Prefer explicit CLI dates; else rolling lookback from job settings.
        $cliFrom = trim((string) ($this->option('date-from') ?? ''));
        $cliTo   = trim((string) ($this->option('date-to') ?? ''));
        $lookbackDays = (int) ($settings['lookback_days']
            ?? $settings['deep_scan_lookback_days']
            ?? $settings['sales_order_lookback_days']
            ?? 7);
        $lookbackDays = max(1, min(90, $lookbackDays));

        if ($cliFrom !== '' && $cliTo !== '') {
            $dateFrom = $cliFrom;
            $dateTo   = $cliTo;
            $syncMode = 'cli_date_range';
            $label    = "{$dateFrom} → {$dateTo}";
        } else {
            $dateFrom = $nowLocal->copy()->subDays($lookbackDays)->toDateString();
            $dateTo   = $nowLocal->toDateString();
            $syncMode = "rolling_{$lookbackDays}d";
            $label    = "last {$lookbackDays} days";
        }

        // Cron setting sync_mode=updates_only OR CLI --updates-only: status check only.
        $updatesOnly = (bool) $this->option('updates-only')
            || in_array((string) ($settings['sync_mode'] ?? ''), ['updates_only', 'status_only'], true);

        if ($updatesOnly) {
            $maxOrders = (int) ($this->option('max-orders')
                ?: ($settings['status_sync_max_orders'] ?? 5000));
            $sync = $salesOrders->syncStatusUpdatesForDateRange(
                $dateFrom,
                $dateTo,
                $maxOrders,
                $userId,
                $source,
                $run->id,
            );
            $syncMode = $syncMode.'_updates_only';
        } else {
            $sync = $salesOrders->syncDateRange($dateFrom, $dateTo, $userId, $source, $run->id);
        }

        $failed  = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status  = $failed ? 'failed' : ($partial ? 'partial' : 'success');

        $deleted = (int) ($sync->filters['orders_deleted_missing_from_acumatica'] ?? 0);
        $statusUpdates = (int) ($sync->filters['status_updates'] ?? 0);
        $checked = (int) ($sync->filters['status_comparison_count'] ?? $sync->record_count);
        $modeLabel = $updatesOnly ? 'updates-only' : 'full import';
        $output = "Sales order sync completed [{$label}, {$modeLabel}]";
        if ($updatesOnly) {
            $output .= "; checked {$checked}";
        }
        if ($deleted > 0) {
            $output .= "; deleted {$deleted} local SO(s) missing from Acumatica";
        }
        if ($statusUpdates > 0) {
            $output .= "; {$statusUpdates} status update(s)";
        }
        $output .= $partial ? '. Some records failed.' : ($failed ? '. Failed.' : '.');

        $steps = [
            'sales_order_sync' => [
                'status'      => $status,
                'duration_ms' => $this->milliseconds($started),
                'metrics'     => [
                    'sync_mode'              => $syncMode,
                    'updates_only'           => $updatesOnly,
                    'lookback_days'          => $lookbackDays,
                    'date_from'              => $dateFrom,
                    'date_to'                => $dateTo,
                    'sales_orders_checked'   => $checked,
                    'sales_orders_processed' => $updatesOnly
                        ? ($statusUpdates + $deleted)
                        : (int) $sync->success_count,
                    'failed_records'         => (int) $sync->failed_count,
                    'status_updates'         => $statusUpdates,
                    'orders_deleted_missing_from_acumatica' => $deleted,
                    'sample_deleted_orders'  => $sync->filters['sample_deleted_orders'] ?? [],
                ],
                'errors' => $sync->error_message ? [$this->sanitize($sync->error_message)] : [],
            ],
        ];

        $backorderError = null;
        // Chain backorder processing after sales-order sync so SO + backorders share one
        // force/manual command. Runs for scheduler, manual, and CLI — not only scheduler.
        // Default: full import only. Opt out with --skip-backorders; force-include with
        // --with-backorders (even on updates-only). Partial SO success still chains —
        // real order data may have been imported.
        $wantBackorders = ! (bool) $this->option('skip-backorders')
            && (
                (bool) $this->option('with-backorders')
                || ! $updatesOnly
            );
        $force = (bool) $this->option('force')
            || $cliFrom !== ''
            || $cliTo !== '';

        if ($wantBackorders && in_array($status, ['success', 'partial'], true)) {
            $backorderStarted = hrtime(true);
            $lockName = 'acumatica-backorders-sync';
            $lockTtl = 2 * 60 * 60;
            if ($force) {
                // Stale dedicated lock after Ctrl+C / killed process would block catch-up.
                try {
                    Cache::lock($lockName, $lockTtl)->forceRelease();
                } catch (Throwable) {
                    // Best-effort; continue to try acquiring.
                }
            }
            $lock = Cache::lock($lockName, $lockTtl);
            if (! $lock->get()) {
                $steps['backorders'] = [
                    'status' => 'skipped',
                    'duration_ms' => 0,
                    'metrics' => [],
                    'errors' => ['Backorder sync skipped because another Backorder run holds the dedicated lock. Re-run with --force after confirming no other process is syncing.'],
                ];
                $output .= ' Backorder sync skipped: another run is active (use --force if stale).';
            } else {
                try {
                    $boTrigger = match (true) {
                        $source === 'scheduler' => 'scheduled_after_sales_orders',
                        default => 'manual_after_sales_orders',
                    };
                    // Same date window as the SO import (CLI override or rolling lookback).
                    $backorderSync = $backorders->run(
                        $userId,
                        $boTrigger,
                        $run->id,
                        $dateFrom,
                        $dateTo,
                    );
                    $backorderStatus = $backorderSync->status === 'completed'
                        ? 'success'
                        : ($backorderSync->status === 'stopped' ? 'skipped' : 'failed');
                    $steps['backorders'] = [
                        'status' => $backorderStatus,
                        'duration_ms' => $this->milliseconds($backorderStarted),
                        'metrics' => [
                            'records_checked' => (int) $backorderSync->record_count,
                            'records_processed' => (int) $backorderSync->success_count,
                            'failed_records' => (int) $backorderSync->failed_count,
                            'date_from' => $dateFrom,
                            'date_to' => $dateTo,
                            'trigger' => $boTrigger,
                        ],
                        'errors' => $backorderSync->error_message ? [$this->sanitize($backorderSync->error_message)] : [],
                    ];
                    if ($backorderStatus === 'failed') {
                        $status = 'partial';
                        $backorderError = $backorderSync->error_message ?: 'Backorder sync failed after sales-order sync.';
                    }
                    $output .= " Backorders: {$backorderSync->success_count} processed.";
                } catch (Throwable $exception) {
                    $status = 'partial';
                    $backorderError = $this->sanitize($exception->getMessage());
                    $steps['backorders'] = [
                        'status' => 'failed',
                        'duration_ms' => $this->milliseconds($backorderStarted),
                        'metrics' => [],
                        'errors' => [$backorderError],
                    ];
                    $output .= ' Backorder sync failed: '.$backorderError;
                } finally {
                    try {
                        $lock->release();
                    } catch (Throwable) {
                        // Lock may already have been force-released.
                    }
                }
            }
        } elseif ((bool) $this->option('skip-backorders')) {
            $output .= ' Backorders skipped (--skip-backorders).';
        }

        return [
            'status'                 => $status,
            'output'                 => $output,
            'sales_orders_checked'   => $checked,
            'sales_orders_processed' => $updatesOnly
                ? ($statusUpdates + $deleted)
                : (int) $sync->success_count,
            'error_count'            => (int) $sync->failed_count + ($failed ? 1 : 0) + ($backorderError ? 1 : 0),
            'error_summary'          => $backorderError ?: ($sync->error_message ? $this->sanitize($sync->error_message) : null),
            'step_status' => $steps,
            'metadata' => [
                'acumatica_sync_log_id' => $sync->id,
                'acumatica_sync_type'   => $sync->sync_type,
            ],
        ];
    }

    private function milliseconds(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(token|secret|password|credential)([=: ]+)[^\s&]+/i', '$1$2[REDACTED]', $message);
        return mb_substr((string) $message, 0, 500);
    }
}
