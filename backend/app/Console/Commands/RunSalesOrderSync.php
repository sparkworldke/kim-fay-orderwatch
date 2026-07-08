<?php

namespace App\Console\Commands;

use App\Models\AcumaticaSyncLog;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;

class RunSalesOrderSync extends Command
{
    protected $signature = 'orderwatch:sales-orders-sync {--source=scheduler} {--user-id=}';

    protected $description = 'Synchronize Acumatica sales orders into the local database (no queue worker)';

    public function handle(CronExecutionService $cron, AcumaticaSalesOrderSyncService $salesOrders): int
    {
        $job = CronJob::salesOrderSync();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $salesOrders, $job, (string) $this->option('source'), $userId),
            (string) $this->option('source'),
            $userId,
            90 * 60,     // 90 min — allows the 2-hour alternating schedule
            3 * 60 * 60, // lock TTL 3h (full sync can take a while)
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, AcumaticaSalesOrderSyncService $salesOrders, CronJob $job, string $source, ?int $userId): array
    {
        $started  = hrtime(true);
        $settings = $job->settings ?? [];
        $tz       = (string) config('cron.timezone', config('app.timezone'));
        $nowLocal = now($tz);

        $deepScanHour         = (int) ($settings['deep_scan_hour']         ?? 16);
        $deepScanLookbackDays = (int) ($settings['deep_scan_lookback_days'] ?? 3);
        $lookbackHours        = (int) ($settings['lookback_hours']          ?? 2);

        if ($nowLocal->hour === $deepScanHour) {
            // 4 PM deep scan: pull last N days to catch late Acumatica postings
            $dateFrom = $nowLocal->copy()->subDays(max(1, $deepScanLookbackDays))->toDateString();
            $syncMode = "deep_scan_{$deepScanLookbackDays}d";
        } else {
            // Normal run: rolling window of the last N hours
            $dateFrom = $nowLocal->copy()->subHours(max(1, $lookbackHours))->toDateString();
            $syncMode = "rolling_{$lookbackHours}h";
        }

        $dateTo = $nowLocal->toDateString();

        $sync = $salesOrders->syncDateRange($dateFrom, $dateTo, $userId, $source, $run->id);

        $failed  = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status  = $failed ? 'failed' : ($partial ? 'partial' : 'success');

        $label = $syncMode === "deep_scan_{$deepScanLookbackDays}d"
            ? "deep scan ({$deepScanLookbackDays} days)"
            : "rolling ({$lookbackHours}h)";

        return [
            'status'                 => $status,
            'output'                 => "Sales order sync completed [{$label}]." . ($partial ? ' Some records failed.' : ($failed ? ' Failed.' : '')),
            'sales_orders_checked'   => (int) $sync->record_count,
            'sales_orders_processed' => (int) $sync->success_count,
            'error_count'            => (int) $sync->failed_count + ($failed ? 1 : 0),
            'error_summary'          => $sync->error_message ? $this->sanitize($sync->error_message) : null,
            'step_status' => [
                'sales_order_sync' => [
                    'status'      => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics'     => [
                        'sync_mode'              => $syncMode,
                        'date_from'              => $dateFrom,
                        'date_to'                => $dateTo,
                        'sales_orders_checked'   => (int) $sync->record_count,
                        'sales_orders_processed' => (int) $sync->success_count,
                        'failed_records'         => (int) $sync->failed_count,
                        'status_updates'         => (int) ($sync->filters['status_updates'] ?? 0),
                    ],
                    'errors' => $sync->error_message ? [$this->sanitize($sync->error_message)] : [],
                ],
            ],
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
