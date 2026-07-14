<?php

namespace App\Console\Commands;

use App\Services\Team\OrgTreeSeedService;
use Illuminate\Console\Command;

class SeedOrgTree extends Command
{
    protected $signature = 'team:seed-org-tree {--dry-run : Preview without saving}';

    protected $description = 'Apply CEO → CCO → HOD reporting tree from config/org_tree.php';

    public function handle(OrgTreeSeedService $service): int
    {
        $result = $service->seed((bool) $this->option('dry-run'));

        $this->info('Linked: ' . $result['linked']);
        if ($result['missing'] !== []) {
            $this->warn('Missing users or managers:');
            foreach ($result['missing'] as $line) {
                $this->line('  - ' . $line);
            }
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');
        }

        return Command::SUCCESS;
    }
}