<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;

class RunSalesOrderStatusSync extends Command
{
    protected $signature = 'orderwatch:sales-order-status-sync {--source=scheduler} {--user-id=}';

    protected $description = 'Update sales order statuses from Acumatica and delete local SOs missing from Acumatica';

    public function handle(CronExecutionService $cron, AcumaticaSalesOrderSyncService $salesOrders): int
    {
        $job = CronJob::salesOrderStatusSync();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $salesOrders, $job, (string) $this->option('source'), $userId),
            (string) $this->option('source'),
            $userId,
            null,
            20 * 60,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, AcumaticaSalesOrderSyncService $salesOrders, CronJob $job, string $source, ?int $userId): array
    {
        $started = hrtime(true);
        $lookbackDays = (int) (($job->settings['status_sync_lookback_days'] ?? 14));
        $maxOrders = (int) (($job->settings['status_sync_max_orders'] ?? 1500));

        $sync = $salesOrders->syncStatusUpdates($lookbackDays, $maxOrders, $userId, $source, $run->id);

        $failed = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status = $failed ? 'failed' : ($partial ? 'partial' : 'success');
        $deleted = (int) ($sync->filters['orders_deleted_missing_from_acumatica'] ?? 0);
        $updated = (int) ($sync->filters['status_updates'] ?? 0);

        return [
            'status' => $status,
            'output' => $status === 'success'
                ? "Sales order status update completed ({$updated} updated, {$deleted} deleted missing from Acumatica)."
                : ($status === 'partial'
                    ? 'Sales order status update completed with partial failures.'
                    : 'Sales order status update failed.'),
            'sales_orders_checked' => (int) ($sync->filters['status_comparison_count'] ?? $sync->record_count),
            'sales_orders_processed' => $updated + $deleted,
            'error_count' => ($failed ? 1 : 0) + (int) $sync->failed_count,
            'error_summary' => $sync->error_message ? $this->sanitize($sync->error_message) : null,
            'step_status' => [
                'status_updates' => [
                    'status' => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics' => [
                        'lookback_days' => $lookbackDays,
                        'max_orders' => $maxOrders,
                        'status_comparison_count' => (int) ($sync->filters['status_comparison_count'] ?? 0),
                        'status_updates' => $updated,
                        'orders_deleted_missing_from_acumatica' => $deleted,
                        'sample_deleted_orders' => $sync->filters['sample_deleted_orders'] ?? [],
                        'source_lookups' => (int) ($sync->filters['source_lookups'] ?? 0),
                    ],
                    'errors' => $sync->error_message ? [$this->sanitize($sync->error_message)] : [],
                ],
            ],
            'metadata' => [
                'acumatica_sync_log_id' => $sync->id,
                'acumatica_sync_type' => $sync->sync_type,
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
