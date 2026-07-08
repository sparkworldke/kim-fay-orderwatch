<?php

namespace App\Console\Commands;

use App\Services\Admin\AcumaticaCustomerSyncService;
use Illuminate\Console\Command;

class SyncAcumaticaCustomers extends Command
{
    protected $signature = 'acumatica:sync-customers';

    protected $description = 'Sync Acumatica customers (including ShippingZoneID) to local acumatica_customers table';

    public function __construct(private readonly AcumaticaCustomerSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $run = $this->syncService->run();

        if ($run->status === 'failed') {
            $this->error($run->error_message ?? 'Customer sync failed.');

            return Command::FAILURE;
        }

        $this->info("Customer sync complete: {$run->success_count}/{$run->record_count} synced, {$run->failed_count} failed.");

        return Command::SUCCESS;
    }
}