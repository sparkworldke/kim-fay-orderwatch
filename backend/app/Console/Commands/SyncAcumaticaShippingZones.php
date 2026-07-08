<?php

namespace App\Console\Commands;

use App\Services\Admin\AcumaticaShippingZoneSyncService;
use Illuminate\Console\Command;

class SyncAcumaticaShippingZones extends Command
{
    protected $signature = 'acumatica:sync-shipping-zones
                            {--from-customers : Build zones from Customer.ShippingZoneID when Zone entity is unavailable}';

    protected $description = 'Sync Acumatica shipping zones to local acumatica_shipping_zones table';

    public function __construct(private readonly AcumaticaShippingZoneSyncService $syncService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $fromCustomers = (bool) $this->option('from-customers');

        $run = $this->syncService->run(
            fromCustomersOnly: $fromCustomers,
            allowCustomerFallback: ! $fromCustomers,
        );

        if ($run->status === 'failed') {
            $this->error($run->error_message ?? 'Shipping zone sync failed.');

            return Command::FAILURE;
        }

        $source = is_array($run->filters) ? ($run->filters['source'] ?? 'master') : 'master';

        if ($run->error_message) {
            $this->warn($run->error_message);
        }

        $this->info("Shipping zone sync complete ({$source}): {$run->success_count}/{$run->record_count} synced, {$run->failed_count} failed.");

        return Command::SUCCESS;
    }
}