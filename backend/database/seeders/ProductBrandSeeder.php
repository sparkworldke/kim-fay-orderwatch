<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use App\Services\Imports\ProductCsvImportService;

class ProductBrandSeeder extends Seeder
{
    public function run(): void
    {
        $path = env(
            'PRODUCT_CATALOG_CSV_PATH',
            storage_path('app/imports/StockItemsBIData.csv'),
        );

        app(ProductCsvImportService::class)->import($path);

        // Ensure manufactured vs partner/trading ownership is set for Production Intel tabs.
        Artisan::call('orderwatch:classify-product-ownership');
        $this->command?->info(trim(Artisan::output()));
    }
}
