<?php

namespace App\Jobs;

use App\Models\MailboxAccount;
use App\Models\MailboxSyncLog;
use App\Services\Email\OutlookEmailService;
use Illuminate\Foundation\Bus\Dispatchable;
use Throwable;

class SyncMailboxJob
{
    use Dispatchable;

    public function __construct(
        public readonly int $mailboxAccountId,
        public readonly ?int $emailFilterId = null,
    ) {}

    public function handle(OutlookEmailService $outlook): void
    {
        $account = MailboxAccount::findOrFail($this->mailboxAccountId);

        $log = MailboxSyncLog::create([
            'mailbox_account_id' => $account->id,
            'started_at'         => now(),
            'status'             => 'running',
        ]);

        try {
            $count = $outlook->syncEmails($account, $this->emailFilterId);

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
