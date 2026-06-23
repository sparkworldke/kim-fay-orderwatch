<?php

namespace App\Services\Cron;

use App\Jobs\SyncMailboxJob;
use App\Models\AcumaticaSyncLog;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\EmailMatchAttempt;
use App\Models\MailboxAccount;
use App\Models\MailboxSyncLog;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Email\OrderMatchingService;
use App\Services\Email\OutlookEmailService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HourlyAutoMatchCronService
{
    public function __construct(
        private readonly OutlookEmailService $outlook,
        private readonly AcumaticaSalesOrderSyncService $salesOrders,
        private readonly OrderMatchingService $matching,
    ) {}

    public function run(CronJob $job, string $triggerSource = 'scheduler', ?int $userId = null): CronRunLog
    {
        if (! $job->is_enabled || $job->status === 'paused') {
            return $this->skippedRun($job, $triggerSource, $userId, 'Cron job is disabled.');
        }

        /** @var Lock $lock */
        $lock = Cache::lock('cron-job:'.$job->job_key, 3300);
        if (! $lock->get()) {
            return $this->skippedRun($job, $triggerSource, $userId, 'A previous hourly run is still active.');
        }

        $run = CronRunLog::create([
            'cron_job_id' => $job->id, 'triggered_by_user_id' => $userId,
            'scheduled_at' => now(), 'started_at' => now(), 'status' => 'running',
            'trigger_source' => $triggerSource, 'step_status' => [],
        ]);
        $settings = array_merge($this->defaults(), $job->settings ?? []);
        $steps = [];
        $errors = [];
        $metadata = [];
        $completedSteps = 0;
        $enabledSteps = 0;

        try {
            if ($settings['email_sync_enabled']) {
                $enabledSteps++;
                $started = hrtime(true);
                $mailboxErrors = [];
                foreach (MailboxAccount::whereIn('status', ['connected', 'error'])->get() as $account) {
                    try {
                        (new SyncMailboxJob($account->id, null, $run->id))->handle($this->outlook);
                    } catch (Throwable $exception) {
                        $mailboxErrors[] = 'Mailbox '.$account->id.': '.$this->sanitize($exception->getMessage());
                    }
                }
                $mailLogs = MailboxSyncLog::where('cron_run_log_id', $run->id)->get();
                $metadata['mailbox_sync_log_ids'] = $mailLogs->pluck('id')->all();
                $partial = $mailboxErrors !== [] || $mailLogs->sum('emails_failed') > 0;
                $steps['email_sync'] = $this->step($partial ? 'partial' : 'success', $started, [
                    'mailboxes' => $mailLogs->count(), 'emails_checked' => (int) $mailLogs->sum('emails_fetched'),
                    'emails_processed' => (int) ($mailLogs->sum('emails_created') + $mailLogs->sum('emails_updated')),
                ], $mailboxErrors);
                if ($partial) $errors = array_merge($errors, $mailboxErrors ?: ['One or more mailbox messages or folders failed.']);
                $completedSteps++;
            } else {
                $steps['email_sync'] = ['status' => 'skipped', 'duration_ms' => 0, 'metrics' => []];
            }

            if ($settings['acumatica_sync_enabled']) {
                $enabledSteps++;
                $started = hrtime(true);
                $days = max(1, min(90, (int) $settings['sales_order_lookback_days']));
                $sync = $this->salesOrders->syncDateRange(
                    now()->subDays($days)->toDateString(), now()->toDateString(), $userId, $triggerSource, $run->id,
                );
                $metadata['acumatica_sync_log_id'] = $sync->id;
                $failed = $sync->status === 'failed';
                $partial = ! $failed && $sync->failed_count > 0;
                $steps['acumatica_sync'] = $this->step($failed ? 'failed' : ($partial ? 'partial' : 'success'), $started, [
                    'sales_orders_checked' => $sync->record_count,
                    'sales_orders_processed' => $sync->success_count,
                    'failed_records' => $sync->failed_count,
                ], $sync->error_message ? [$this->sanitize($sync->error_message)] : []);
                if ($failed || $partial) $errors[] = $sync->error_message ? $this->sanitize($sync->error_message) : "{$sync->failed_count} Acumatica record(s) failed.";
                if (! $failed) $completedSteps++;
            } else {
                $steps['acumatica_sync'] = ['status' => 'skipped', 'duration_ms' => 0, 'metrics' => []];
            }

            if ($settings['matching_enabled']) {
                $enabledSteps++;
                $started = hrtime(true);
                $extraction = $this->matching->runPoExtraction();
                $matchRun = $this->matching->runOrderMatching($userId, $run->id);
                $metadata['order_match_run_id'] = $matchRun->id;
                $failed = $matchRun->status === 'failed';
                $attempts = EmailMatchAttempt::where('cron_run_log_id', $run->id)
                    ->selectRaw('classification, COUNT(*) as total')->groupBy('classification')->pluck('total', 'classification');
                $steps['matching'] = $this->step($failed ? 'failed' : 'success', $started, [
                    'emails_extraction_processed' => $extraction['processed'], 'po_extracted' => $extraction['extracted'],
                    'matches_created' => (int) ($attempts['matched'] ?? 0),
                    'matched_with_discrepancies' => (int) ($attempts['matched_discrepancies'] ?? 0),
                    'needs_review' => (int) ($attempts['needs_review'] ?? 0),
                    'unmatched' => (int) ($attempts['not_matched'] ?? 0),
                ], $matchRun->error_message ? [$this->sanitize($matchRun->error_message)] : []);
                if ($failed) $errors[] = $this->sanitize($matchRun->error_message ?: 'Matching failed.');
                else $completedSteps++;
            } else {
                $steps['matching'] = ['status' => 'skipped', 'duration_ms' => 0, 'metrics' => []];
            }

            $mailLogs = MailboxSyncLog::where('cron_run_log_id', $run->id)->get();
            $acumatica = AcumaticaSyncLog::where('cron_run_log_id', $run->id)->latest('id')->first();
            $attempts = EmailMatchAttempt::where('cron_run_log_id', $run->id)
                ->selectRaw('classification, COUNT(*) as total')->groupBy('classification')->pluck('total', 'classification');
            $status = $errors === [] ? 'success' : ($completedSteps > 0 ? 'partial' : 'failed');
            $duration = abs((int) $run->started_at->diffInMilliseconds(now()));
            $run->update([
                'ended_at' => now(), 'duration_ms' => $duration, 'status' => $status,
                'emails_checked' => (int) $mailLogs->sum('emails_fetched'),
                'emails_processed' => (int) ($mailLogs->sum('emails_created') + $mailLogs->sum('emails_updated')),
                'sales_orders_checked' => (int) ($acumatica?->record_count ?? 0),
                'sales_orders_processed' => (int) ($acumatica?->success_count ?? 0),
                'matches_created' => (int) ($attempts['matched'] ?? 0),
                'matched_with_discrepancies_count' => (int) ($attempts['matched_discrepancies'] ?? 0),
                'needs_review_count' => (int) ($attempts['needs_review'] ?? 0),
                'unmatched_count' => (int) ($attempts['not_matched'] ?? 0),
                'skipped_count' => (int) $mailLogs->sum('emails_skipped'),
                'error_count' => count($errors) + (int) $mailLogs->sum('emails_failed') + (int) ($acumatica?->failed_count ?? 0),
                'step_status' => $steps, 'error_summary' => $errors ? implode("\n", array_unique($errors)) : null,
                'metadata' => $metadata, 'output' => ucfirst($status).' hourly reconciliation run.',
            ]);
            $this->updateJob($job, $run->fresh());
        } catch (Throwable $exception) {
            $message = $this->sanitize($exception->getMessage());
            $run->update([
                'ended_at' => now(), 'duration_ms' => abs((int) $run->started_at->diffInMilliseconds(now())),
                'status' => 'failed', 'error_count' => max(1, count($errors) + 1),
                'step_status' => $steps, 'error_summary' => $message,
                'metadata' => $metadata, 'output' => 'Hourly reconciliation failed.',
            ]);
            $this->updateJob($job, $run->fresh());
        } finally {
            $lock->release();
        }

        return $run->fresh();
    }

    private function skippedRun(CronJob $job, string $source, ?int $userId, string $reason): CronRunLog
    {
        return CronRunLog::create([
            'cron_job_id' => $job->id, 'triggered_by_user_id' => $userId,
            'scheduled_at' => now(), 'started_at' => now(), 'ended_at' => now(),
            'status' => 'skipped', 'trigger_source' => $source, 'duration_ms' => 0,
            'skipped_count' => 1, 'step_status' => [], 'error_summary' => $reason, 'output' => $reason,
        ]);
    }

    private function updateJob(CronJob $job, CronRunLog $run): void
    {
        $updates = [
            'last_run_at' => $run->started_at, 'last_run_status' => $run->status,
            'last_duration_ms' => $run->duration_ms, 'next_run_at' => now()->addHour()->startOfHour(),
        ];
        if ($run->status === 'success') $updates['last_success_at'] = $run->ended_at;
        if (in_array($run->status, ['partial', 'failed'], true)) $updates['last_failure_at'] = $run->ended_at;
        $job->update($updates);
    }

    private function defaults(): array
    {
        return ['email_sync_enabled' => true, 'acumatica_sync_enabled' => true, 'matching_enabled' => true,
            'sales_order_lookback_days' => 7, 'deterministic_auto_link' => true, 'ai_auto_link' => false];
    }

    private function step(string $status, int $started, array $metrics, array $errors = []): array
    {
        return ['status' => $status, 'duration_ms' => $this->milliseconds($started), 'metrics' => $metrics,
            'errors' => array_values(array_filter($errors))];
    }

    private function milliseconds(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(token|secret|password|credential)([=: ]+)[^\s&]+/i', '$1$2[REDACTED]', $message);
        return mb_substr((string) $message, 0, 1000);
    }
}
