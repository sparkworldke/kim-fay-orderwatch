<?php

namespace App\Console\Commands;

use App\Services\Team\CustomerEffectiveAssignmentSnapshotService;
use Illuminate\Console\Command;

class RebuildCustomerAttributionSnapshots extends Command
{
    protected $signature = 'portfolio:rebuild-attribution-snapshots';
    protected $description = 'Rebuild the deterministic customer effective-assignment cache';

    public function handle(CustomerEffectiveAssignmentSnapshotService $service): int
    {
        $stats = $service->rebuild();
        $this->info("Resolved: {$stats['resolved']}; unresolved/ambiguous: {$stats['unresolved']}");

        return self::SUCCESS;
    }
}
