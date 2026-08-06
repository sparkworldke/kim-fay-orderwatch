<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class RunBackorderProcessing extends Command
{
    protected $signature = 'orderwatch:backorders-process
                            {--source=manual : Trigger source (manual|cli|scheduler). Prefer manual/cli so min-interval is not applied}
                            {--user-id=}
                            {--date-from= : Optional start date (Y-m-d) for the backorder window}
                            {--date-to= : Optional end date (Y-m-d) for the backorder window}
                            {--force : Break a stale cron lock and bypass min-interval (after Ctrl+C / killed sync)}';

    protected $description = 'Process Acumatica-derived backorders. Shares --force / manual semantics with orderwatch:sales-orders-sync. Prefer chaining via sales-orders-sync for full catch-up.';

    public function handle(CronExecutionService $cron, AcumaticaBackorderSyncService $backorders): int
    {
        $job = CronJob::backorderProcessing();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $force = (bool) $this->option('force');
        $source = trim((string) $this->option('source')) ?: 'manual';

        // Date-range ops runs are always intentional.
        if ($this->option('date-from') || $this->option('date-to')) {
            if ($source === 'scheduler') {
                $source = 'manual';
            }
            $force = true;
        }

        if ($force) {
            $this->warn('Force mode: releasing stale lock / bypassing min-interval for job_key='.$job->job_key);
            // Dedicated Acumatica lock used by chained SO→backorder and import-backorders.
            try {
                Cache::lock('acumatica-backorders-sync', 2 * 60 * 60)->forceRelease();
            } catch (Throwable) {
                // Best-effort.
            }
        }

        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $backorders, $source, $userId),
            $source,
            $userId,
            23 * 60 * 60, // scheduler anti-spam only (manual/cli/force bypass)
            8 * 60 * 60,
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

    private function perform(CronRunLog $run, AcumaticaBackorderSyncService $backorders, string $source, ?int $userId): array
    {
        $started = hrtime(true);
        $dateFrom = trim((string) ($this->option('date-from') ?? ''));
        $dateTo = trim((string) ($this->option('date-to') ?? ''));

        $sync = $backorders->run(
            $userId,
            $source,
            $run->id,
            $dateFrom !== '' ? $dateFrom : null,
            $dateTo !== '' ? $dateTo : null,
        );

        $failed = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status = $failed ? 'failed' : ($partial ? 'partial' : 'success');

        $label = ($dateFrom !== '' && $dateTo !== '')
            ? "{$dateFrom} → {$dateTo}"
            : 'default window';

        $output = $status === 'success'
            ? "Backorder processing completed [{$label}]; {$sync->success_count} processed."
            : ($status === 'partial'
                ? "Backorder processing completed with partial failures [{$label}]."
                : "Backorder processing failed [{$label}].");

        return [
            'status' => $status,
            'output' => $output,
            'error_count' => (int) $sync->failed_count + ($failed ? 1 : 0),
            'error_summary' => $sync->error_message ? $this->sanitize($sync->error_message) : null,
            'step_status' => [
                'backorders' => [
                    'status' => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics' => [
                        'records_checked'   => (int) $sync->record_count,
                        'records_processed' => (int) $sync->success_count,
                        'failed_records'    => (int) $sync->failed_count,
                        'date_from'         => $dateFrom !== '' ? $dateFrom : null,
                        'date_to'           => $dateTo !== '' ? $dateTo : null,
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
