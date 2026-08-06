<?php

namespace App\Console\Commands;

use App\Services\Dtc\DtcPriceSyncService;
use Illuminate\Console\Command;

class SyncDtcPrices extends Command
{
    protected $signature = 'orderwatch:sync-dtc-prices
        {--source=manual}
        {--products-only : Only sync products from local inventory into dtc_price_list}
        {--prices-only : Only pull DTCACCOUNT prices (PUT SalesPricesInquiry)}';

    protected $description = 'Sync DTC price list: products from inventory, then DTCACCOUNT prices via PUT SalesPricesInquiry';

    public function handle(DtcPriceSyncService $service): int
    {
        if ($this->option('products-only')) {
            $result = $service->syncProducts();
            $this->info("Synced {$result['products']} products into dtc_price_list.");

            return self::SUCCESS;
        }

        if ($this->option('prices-only')) {
            $result = $service->syncPrices();
            $this->info("DTCACCOUNT prices: {$result['processed']} saved · {$result['matched']} matched · source={$result['price_source']}");

            return self::SUCCESS;
        }

        $result = $service->sync();
        $this->info(
            "Products: {$result['products']} · Prices: {$result['processed']} saved · ".
            "{$result['matched']} matched · {$result['unmatched']} unmatched · source={$result['price_source']}"
        );

        return self::SUCCESS;
    }
}
