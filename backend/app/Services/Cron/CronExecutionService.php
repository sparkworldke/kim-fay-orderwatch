<?php

namespace App\Services\Cron;

use App\Models\CronJob;
use App\Models\CronRunLog;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class CronExecutionService
{
    /**
     * @param  callable(CronRunLog): array{
     *   status: 'success'|'partial'|'failed',
     *   output?: string|null,
     *   step_status?: array<string, mixed>|null,
     *   metadata?: array<string, mixed>|null,
     *   emails_checked?: int|null,
     *   emails_processed?: int|null,
     *   sales_orders_checked?: int|null,
     *   sales_orders_processed?: int|null,
     *   matches_created?: int|null,
     *   matched_with_discrepancies_count?: int|null,
     *   needs_review_count?: int|null,
     *   unmatched_count?: int|null,
     *   skipped_count?: int|null,
     *   error_count?: int|null,
     *   error_summary?: string|null
     * } $callback
     */
    public function run(
        CronJob $job,
        callable $callback,
        string $triggerSource = 'scheduler',
        ?int $userId = null,
        ?int $minIntervalSeconds = null,
        int $lockTtlSeconds = 3300,
    ): CronRunLog {
        if (! $job->is_enabled || $job->status === 'paused') {
            return $this->skippedRun($job, $triggerSource, $userId, 'Cron job is disabled.');
        }

        if ($minIntervalSeconds !== null && $job->last_success_at !== null && $triggerSource !== 'manual') {
            if ($job->last_success_at->isAfter(now()->subSeconds($minIntervalSeconds))) {
                return $this->skippedRun(
                    $job,
                    $triggerSource,
                    $userId,
                    'Cron job skipped due to minimum interval guard.',
                );
            }
        }

        /** @var Lock $lock */
        $lock = Cache::lock('cron-job:' . $job->job_key, $lockTtlSeconds);
        if (! $lock->get()) {
            return $this->skippedRun($job, $triggerSource, $userId, 'A previous run is still active.');
        }

        $run = CronRunLog::create([
            'cron_job_id' => $job->id,
            'triggered_by_user_id' => $userId,
            'scheduled_at' => now(),
            'started_at' => now(),
            'status' => 'running',
            'trigger_source' => $triggerSource,
            'step_status' => [],
        ]);

        try {
            $result = $callback($run);
            $status = $result['status'] ?? 'failed';

            $run->update(array_filter([
                'ended_at' => now(),
                'duration_ms' => abs((int) $run->started_at->diffInMilliseconds(now())),
                'status' => $status,
                'output' => $result['output'] ?? null,
                'step_status' => $result['step_status'] ?? null,
                'metadata' => $result['metadata'] ?? null,
                'emails_checked' => $result['emails_checked'] ?? null,
                'emails_processed' => $result['emails_processed'] ?? null,
                'sales_orders_checked' => $result['sales_orders_checked'] ?? null,
                'sales_orders_processed' => $result['sales_orders_processed'] ?? null,
                'matches_created' => $result['matches_created'] ?? null,
                'matched_with_discrepancies_count' => $result['matched_with_discrepancies_count'] ?? null,
                'needs_review_count' => $result['needs_review_count'] ?? null,
                'unmatched_count' => $result['unmatched_count'] ?? null,
                'skipped_count' => $result['skipped_count'] ?? null,
                'error_count' => $result['error_count'] ?? null,
                'error_summary' => $result['error_summary'] ?? null,
            ], fn ($v) => $v !== null));

            $this->updateJob($job, $run->fresh());
        } catch (Throwable $exception) {
            $run->update([
                'ended_at' => now(),
                'duration_ms' => abs((int) $run->started_at->diffInMilliseconds(now())),
                'status' => 'failed',
                'error_count' => 1,
                'error_summary' => $this->sanitize($exception->getMessage()),
                'output' => 'Cron run failed.',
            ]);

            $this->updateJob($job, $run->fresh());
        } finally {
            $lock->release();
        }

        return $run->fresh();
    }

    private function skippedRun(CronJob $job, string $source, ?int $userId, string $reason): CronRunLog
    {
        $run = CronRunLog::create([
            'cron_job_id' => $job->id,
            'triggered_by_user_id' => $userId,
            'scheduled_at' => now(),
            'started_at' => now(),
            'ended_at' => now(),
            'status' => 'skipped',
            'trigger_source' => $source,
            'duration_ms' => 0,
            'skipped_count' => 1,
            'step_status' => [],
            'error_summary' => $reason,
            'output' => $reason,
        ]);

        $this->updateJob($job, $run);

        return $run->fresh();
    }

    private function updateJob(CronJob $job, CronRunLog $run): void
    {
        $updates = [
            'last_run_at' => $run->started_at,
            'last_run_status' => $run->status,
            'last_duration_ms' => $run->duration_ms,
        ];

        if ($run->status === 'success') {
            $updates['last_success_at'] = $run->ended_at;
        }

        if (in_array($run->status, ['partial', 'failed'], true)) {
            $updates['last_failure_at'] = $run->ended_at;
        }

        $job->update($updates);
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(token|secret|password|credential)([=: ]+)[^\s&]+/i', '$1$2[REDACTED]', $message);
        return mb_substr((string) $message, 0, 1000);
    }
}

