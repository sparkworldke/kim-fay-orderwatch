<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;

class RunSalesOrderPruneMissing extends Command
{
    protected $signature = 'orderwatch:sales-order-prune-missing {--source=scheduler} {--user-id=}';

    protected $description = 'Delete local sales orders that no longer exist in Acumatica';

    public function handle(CronExecutionService $cron, AcumaticaSalesOrderSyncService $salesOrders): int
    {
        $job = CronJob::salesOrderPruneMissing();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $salesOrders, $job, (string) $this->option('source'), $userId),
            (string) $this->option('source'),
            $userId,
            null,
            45 * 60,
        );

        $this->info("Cron run {$run->id}: {$run->status}");

        if ($run->error_summary) {
            $this->line($run->error_summary);
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(
        CronRunLog $run,
        AcumaticaSalesOrderSyncService $salesOrders,
        CronJob $job,
        string $source,
        ?int $userId,
    ): array {
        $started = hrtime(true);
        $lookbackDays = (int) ($job->settings['prune_lookback_days'] ?? 60);
        $maxOrders = (int) ($job->settings['prune_max_orders'] ?? 3000);

        $sync = $salesOrders->pruneMissingSalesOrders($lookbackDays, $maxOrders, $userId, $source, $run->id);

        $failed = $sync->status === 'failed';
        $deleted = (int) ($sync->filters['orders_deleted_missing_from_acumatica'] ?? 0);
        $checked = (int) ($sync->filters['orders_checked'] ?? $sync->record_count);
        $status = $failed ? 'failed' : 'success';

        return [
            'status' => $status,
            'output' => $failed
                ? 'Sales order prune failed.'
                : "Sales order prune completed. Checked {$checked}, deleted {$deleted} missing from Acumatica.",
            'sales_orders_checked' => $checked,
            'sales_orders_processed' => $deleted,
            'error_count' => $failed ? 1 : 0,
            'error_summary' => $sync->error_message ? $this->sanitize($sync->error_message) : null,
            'step_status' => [
                'prune_missing' => [
                    'status' => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics' => [
                        'lookback_days' => $lookbackDays,
                        'max_orders' => $maxOrders,
                        'orders_checked' => $checked,
                        'orders_deleted_missing_from_acumatica' => $deleted,
                        'sample_deleted_orders' => $sync->filters['sample_deleted_orders'] ?? [],
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
