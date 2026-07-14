<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailboxJob;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\MailboxAccount;
use App\Models\MailboxSyncLog;
use App\Services\Cron\CronExecutionService;
use App\Services\Email\OutlookEmailService;
use Illuminate\Console\Command;
use Throwable;

class RunEmailSync extends Command
{
    protected $signature = 'orderwatch:email-sync {--source=scheduler} {--user-id=}';

    protected $description = 'Synchronize Outlook mailboxes into the local database (no queue worker)';

    public function handle(CronExecutionService $cron, OutlookEmailService $outlook): int
    {
        $job = CronJob::emailSync();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $outlook),
            (string) $this->option('source'),
            $userId,
            90 * 60, // 90 min ceiling — same-day window should finish well under this
            150 * 60, // 2.5 h lock — leave headroom under the 3-hour schedule
        );

        $this->info("Cron run {$run->id}: {$run->status}");

        if ($run->error_summary) {
            $this->line($run->error_summary);
        }

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, OutlookEmailService $outlook): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $started = hrtime(true);
        $errors = [];

        $accounts = MailboxAccount::whereIn('status', ['connected', 'error'])->get();
        foreach ($accounts as $account) {
            try {
                (new SyncMailboxJob($account->id, null, $run->id))->handle($outlook);
            } catch (Throwable $exception) {
                $errors[] = 'Mailbox '.$account->id.': '.$this->sanitize($exception->getMessage());
            }
        }

        $mailLogs = MailboxSyncLog::where('cron_run_log_id', $run->id)->get();
        $completedLogs = $mailLogs->where('status', 'completed');
        $allFailed = $accounts->isNotEmpty()
            && $mailLogs->isNotEmpty()
            && $completedLogs->isEmpty();
        $partial = $errors !== []
            || $mailLogs->sum('emails_failed') > 0
            || ($completedLogs->isNotEmpty() && $completedLogs->count() < $accounts->count());

        $status = $allFailed ? 'failed' : ($partial ? 'partial' : 'success');
        $output = $status === 'success' ? 'Email sync completed.' : ($status === 'partial' ? 'Email sync completed with partial failures.' : 'Email sync failed.');

        return [
            'status' => $status,
            'output' => $output,
            'emails_checked' => (int) $mailLogs->sum('emails_fetched'),
            'emails_processed' => (int) ($mailLogs->sum('emails_created') + $mailLogs->sum('emails_updated')),
            'skipped_count' => (int) $mailLogs->sum('emails_skipped'),
            'error_count' => count($errors) + (int) $mailLogs->sum('emails_failed'),
            'error_summary' => $errors ? implode("\n", array_values(array_unique($errors))) : null,
            'step_status' => [
                'email_sync' => [
                    'status' => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics' => [
                        'mailboxes' => $accounts->count(),
                        'mailbox_logs' => $mailLogs->count(),
                        'emails_checked' => (int) $mailLogs->sum('emails_fetched'),
                        'emails_processed' => (int) ($mailLogs->sum('emails_created') + $mailLogs->sum('emails_updated')),
                        'emails_failed' => (int) $mailLogs->sum('emails_failed'),
                    ],
                    'errors' => $errors,
                ],
            ],
            'metadata' => [
                'mailbox_sync_log_ids' => $mailLogs->pluck('id')->all(),
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
