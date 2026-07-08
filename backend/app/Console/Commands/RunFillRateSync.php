<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaFillRateSyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;

class RunFillRateSync extends Command
{
    protected $signature = 'orderwatch:fill-rate-sync {--source=scheduler} {--user-id=} {--variant=nightly}';

    protected $description = 'Synchronize and compute fill-rate snapshots (no queue worker)';

    public function handle(CronExecutionService $cron, AcumaticaFillRateSyncService $fillRate): int
    {
        $job = $this->option('variant') === 'noon' ? CronJob::fillRateNoon() : CronJob::fillRateSync();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $fillRate, $job, (string) $this->option('source'), $userId),
            (string) $this->option('source'),
            $userId,
            20 * 60 * 60,
            8 * 60 * 60,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, AcumaticaFillRateSyncService $fillRate, CronJob $job, string $source, ?int $userId): array
    {
        $started = hrtime(true);
        $days = (int) (($job->settings['fill_rate_lookback_days'] ?? 2));
        $days = max(1, min(30, $days));

        $sync = $fillRate->syncDateRange(
            now()->subDays($days)->toDateString(),
            now()->toDateString(),
            $userId,
            $source,
            $run->id,
        );

        $failed = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status = $failed ? 'failed' : ($partial ? 'partial' : 'success');

        return [
            'status' => $status,
            'output' => $status === 'success' ? 'Fill-rate sync completed.' : ($status === 'partial' ? 'Fill-rate sync completed with partial failures.' : 'Fill-rate sync failed.'),
            'error_count' => (int) $sync->failed_count + ($failed ? 1 : 0),
            'error_summary' => $sync->error_message ? $this->sanitize($sync->error_message) : null,
            'step_status' => [
                'fill_rate' => [
                    'status' => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics' => [
                        'lookback_days' => $days,
                        'orders_checked' => (int) $sync->record_count,
                        'orders_processed' => (int) $sync->success_count,
                        'failed_records' => (int) $sync->failed_count,
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
