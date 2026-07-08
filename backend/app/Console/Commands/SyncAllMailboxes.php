<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailboxJob;
use App\Models\MailboxAccount;
use App\Services\Email\OutlookEmailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SyncAllMailboxes extends Command
{
    protected $signature = 'mailbox:sync-all';

    protected $description = 'Dispatch a sync job for every connected mailbox account';

    public function handle(OutlookEmailService $outlook): int
    {
        $accounts = MailboxAccount::whereIn('status', ['connected', 'error'])->get();

        if ($accounts->isEmpty()) {
            $this->info('No mailbox accounts to sync.');
            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            (new SyncMailboxJob($account->id))->handle($outlook);
            $this->line("  Synced: {$account->email}");
        }

        Log::channel('mailbox_sync')->info('Scheduled sync-all completed', [
            'account_count' => $accounts->count(),
            'ran_at'        => now()->toISOString(),
        ]);

        $this->info("Synced {$accounts->count()} mailbox account(s).");

        return Command::SUCCESS;
    }
}
