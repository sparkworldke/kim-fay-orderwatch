<?php

namespace App\Jobs;

use App\Models\MailboxAccount;
use App\Models\MailboxSyncLog;
use App\Services\Email\OutlookEmailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Hard ceiling per sync run — large mailboxes can be slow. */
    public int $timeout = 300;

    /** Retry transient mailbox failures with exponential backoff. */
    public int $tries = 3;

    public function backoff(): array
    {
        return [60, 180];
    }

    public function __construct(
        public readonly int $mailboxAccountId,
        public readonly ?int $emailFilterId = null,
        public readonly ?int $cronRunLogId = null,
        public readonly ?string $syncFrom = null,
        public readonly ?string $syncTo = null,
    ) {}

    public function handle(OutlookEmailService $outlook): void
    {
        $account = MailboxAccount::findOrFail($this->mailboxAccountId);

        try {
            $log = MailboxSyncLog::create([
                'mailbox_account_id' => $account->id,
                'cron_run_log_id' => $this->cronRunLogId,
                'email_filter_id' => $this->emailFilterId,
                'sync_from' => $this->syncFrom,
                'sync_to' => $this->syncTo,
                'started_at' => now(),
                'status' => 'running',
            ]);
        } catch (Throwable $exception) {
            Log::channel('mailbox_sync')->error('sync_failed', [
                'mailbox_id' => $account->id,
                'reason' => 'run_log_database_failure',
                'exception_class' => $exception::class,
            ]);

            throw $exception;
        }

        $context = [
            'sync_run_id' => $log->id,
            'mailbox_id' => $account->id,
            'email_filter_id' => $this->emailFilterId,
            'queued_at' => now()->toISOString(),
        ];

        Log::channel('mailbox_sync')->info('sync_started', $context);

        try {
            $count = $outlook->syncEmails($account, $this->emailFilterId, $log, $this->syncFrom, $this->syncTo);

            $durationMs = (int) ($log->started_at->diffInMilliseconds(now()));

            $log->update([
                'status' => 'completed',
                'emails_fetched' => $count,
                'ended_at' => now(),
            ]);

            $log->refresh();

            Log::channel('mailbox_sync')->info('sync_completed', array_merge($context, [
                'emails_fetched' => $count,
                'emails_created' => $log->emails_created,
                'emails_updated' => $log->emails_updated,
                'emails_skipped' => $log->emails_skipped,
                'emails_deleted' => $log->emails_deleted,
                'emails_failed' => $log->emails_failed,
                'duration_ms' => $durationMs,
            ]));

            // Reset the rolling error counter on success
            Cache::forget("mailbox_sync_errors:{$account->id}");
        } catch (Throwable $exception) {
            $durationMs = (int) ($log->started_at->diffInMilliseconds(now()));

            $log->update([
                'status' => 'failed',
                'error_message' => $exception->getMessage(),
                'ended_at' => now(),
            ]);

            $account->update(['status' => 'error']);

            // Increment the rolling 24-hour error counter for this mailbox
            $errorKey = "mailbox_sync_errors:{$account->id}";
            $errorCount = (int) Cache::get($errorKey, 0) + 1;
            Cache::put($errorKey, $errorCount, now()->addDay());

            Log::channel('mailbox_sync')->error('sync_failed', array_merge($context, [
                'exception_class' => $exception::class,
                'duration_ms' => $durationMs,
                'error_count_24h' => $errorCount,
            ]));

            throw $exception;
        }
    }
}
