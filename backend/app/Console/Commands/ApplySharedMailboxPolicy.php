<?php

namespace App\Console\Commands;

use App\Services\Team\SharedMailboxPolicy;
use Illuminate\Console\Command;

class ApplySharedMailboxPolicy extends Command
{
    protected $signature = 'team:apply-shared-mailbox-policy';

    protected $description = 'Mark shared/service mailboxes as inactive with deny_all scope';

    public function handle(SharedMailboxPolicy $policy): int
    {
        $result = $policy->applyToAllUsers();
        $this->info("Updated {$result['updated']} shared mailbox account(s).");

        return Command::SUCCESS;
    }
}