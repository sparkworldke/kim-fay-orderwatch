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
use Throwable;

class SyncMailboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $mailboxAccountId) {}

    public function handle(OutlookEmailService $outlook): void
    {
        $account = MailboxAccount::findOrFail($this->mailboxAccountId);

        $log = MailboxSyncLog::create([
            'mailbox_account_id' => $account->id,
            'started_at'         => now(),
            'status'             => 'running',
        ]);

        try {
            $count = $outlook->syncEmails($account);

            $log->update([
                'status'         => 'completed',
                'emails_fetched' => $count,
                'ended_at'       => now(),
            ]);
        } catch (Throwable $exception) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $exception->getMessage(),
                'ended_at'      => now(),
            ]);

            $account->update(['status' => 'error']);

            throw $exception;
        }
    }
}
