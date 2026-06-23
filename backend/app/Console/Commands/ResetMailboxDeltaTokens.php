<?php

namespace App\Console\Commands;

use App\Models\MailboxAccount;
use Illuminate\Console\Command;

class ResetMailboxDeltaTokens extends Command
{
    protected $signature = 'mailbox:reset-delta
                            {--days=1 : Only pull emails from the last N days on next sync}';

    protected $description = 'Clear stored delta tokens so the next sync re-fetches with the 1-day default window instead of the full mailbox history';

    public function handle(): int
    {
        $days     = (int) $this->option('days');
        $accounts = MailboxAccount::all();

        if ($accounts->isEmpty()) {
            $this->info('No mailbox accounts found.');
            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            $account->update([
                'delta_token'    => null,
                'last_synced_at' => null,
                'status'         => 'connected',
                'sync_from_date' => now()->subDays(max(1, $days))->toDateString(),
            ]);
            $account->folders()->update(['delta_token' => null, 'last_synced_at' => null, 'last_sync_error' => null]);
            $this->line("  Reset: {$account->email}");
        }

        $this->info("Delta tokens cleared for {$accounts->count()} account(s). Next sync will fetch the last {$days} days.");
        $this->comment('Run php artisan mailbox:sync-all (or trigger Sync from the UI) to start the fresh import.');

        return Command::SUCCESS;
    }
}
