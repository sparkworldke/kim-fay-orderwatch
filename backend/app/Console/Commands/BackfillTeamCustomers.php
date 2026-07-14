<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Team\CustomerAssignmentService;
use Illuminate\Console\Command;

class BackfillTeamCustomers extends Command
{
    protected $signature = 'team:backfill-customers
                            {user? : User ID or email}
                            {--all-consultants : Backfill all consultant users}';

    protected $description = 'Derive customer assignments from synced sales orders';

    public function handle(CustomerAssignmentService $service): int
    {
        $users = $this->resolveUsers();

        if ($users->isEmpty()) {
            $this->error('No matching users found.');

            return Command::FAILURE;
        }

        $totalAdded = 0;

        foreach ($users as $user) {
            $result = $service->backfillFromSalesOrders($user);
            $totalAdded += $result['added'];
            $this->line("{$user->email}: +{$result['added']} new, {$result['total']} total customers");
        }

        $this->info("Done. {$totalAdded} new assignment(s) across {$users->count()} user(s).");

        return Command::SUCCESS;
    }

    private function resolveUsers()
    {
        if ($this->option('all-consultants')) {
            return User::query()
                ->where('is_consultant', true)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        $identifier = $this->argument('user');
        if ($identifier === null) {
            return collect();
        }

        $user = is_numeric($identifier)
            ? User::query()->find((int) $identifier)
            : User::query()->where('email', strtolower($identifier))->first();

        return $user ? collect([$user]) : collect();
    }
}