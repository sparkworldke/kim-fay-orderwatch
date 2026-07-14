<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\SalesManagement\SalesManagementPromptService;
use Illuminate\Console\Command;

class GenerateSalesManagementPrompts extends Command
{
    protected $signature = 'orderwatch:sales-management-prompts
        {--period= : Month period for month-gap prompts, format YYYY-MM}
        {--force : Generate even when sales order sync freshness guard is stale}
        {--actor= : Optional user id to stamp as generator; defaults to first Administrator}';

    protected $description = 'Generate Sales Management prompts for order cycles and month-close gaps.';

    public function handle(SalesManagementPromptService $service): int
    {
        $actor = $this->option('actor')
            ? User::query()->find((int) $this->option('actor'))
            : User::query()->where('role', 'Administrator')->orWhere('is_super_admin', true)->first();

        if (! $actor) {
            $this->error('No Administrator user was found to run prompt generation.');
            return self::FAILURE;
        }

        $result = $service->generate($actor, $this->option('period') ?: null, (bool) $this->option('force'));
        $this->info(json_encode($result, JSON_PRETTY_PRINT));

        return ($result['stale_blocked'] ?? false) ? self::FAILURE : self::SUCCESS;
    }
}
