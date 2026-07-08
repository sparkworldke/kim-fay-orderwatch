<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;

class RunBackorderProcessing extends Command
{
    protected $signature = 'orderwatch:backorders-process {--source=scheduler} {--user-id=}';

    protected $description = 'Validate and process backorders (Acumatica-derived) on a daily schedule (no queue worker)';

    public function handle(CronExecutionService $cron, AcumaticaBackorderSyncService $backorders): int
    {
        $job = CronJob::backorderProcessing();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $backorders, (string) $this->option('source'), $userId),
            (string) $this->option('source'),
            $userId,
            23 * 60 * 60,
            8 * 60 * 60,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, AcumaticaBackorderSyncService $backorders, string $source, ?int $userId): array
    {
        $started = hrtime(true);
        $sync = $backorders->run($userId, $source, $run->id);

        $failed = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status = $failed ? 'failed' : ($partial ? 'partial' : 'success');

        return [
            'status' => $status,
            'output' => $status === 'success' ? 'Backorder processing completed.' : ($status === 'partial' ? 'Backorder processing completed with partial failures.' : 'Backorder processing failed.'),
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
