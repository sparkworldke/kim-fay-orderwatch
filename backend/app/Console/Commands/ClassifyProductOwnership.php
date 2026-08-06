<?php

namespace App\Console\Commands;

use App\Models\AcumaticaInventoryItem;
use App\Models\Brand;
use App\Models\Product;
use App\Services\Admin\ProductBrandClassifier;
use Illuminate\Console\Command;

/**
 * Backfill product + brand ownership and inventory product_type after catalogue seeder/import.
 *
 * Usage:
 *   php artisan orderwatch:classify-product-ownership
 *   php artisan orderwatch:classify-product-ownership --force
 */
class ClassifyProductOwnership extends Command
{
    protected $signature = 'orderwatch:classify-product-ownership
                            {--force : Overwrite existing ownership/product_type values}';

    protected $description = 'Classify products/brands as manufactured or partner (trading) and sync inventory product_type';

    public function handle(ProductBrandClassifier $classifier): int
    {
        $force = (bool) $this->option('force');
        $brandUpdated = 0;
        $productUpdated = 0;
        $inventoryUpdated = 0;

        Brand::query()->orderBy('id')->chunkById(200, function ($brands) use ($classifier, $force, &$brandUpdated) {
            foreach ($brands as $brand) {
                if (! $force && filled($brand->ownership)) {
                    continue;
                }
                $ownership = $classifier->ownershipFromBrand($brand->name);
                if ($ownership === null || $ownership === $brand->ownership) {
                    continue;
                }
                $brand->update(['ownership' => $ownership]);
                $brandUpdated++;
            }
        });

        Product::query()->with('brand')->orderBy('id')->chunkById(200, function ($products) use ($classifier, $force, &$productUpdated) {
            foreach ($products as $product) {
                if (! $force && filled($product->ownership)) {
                    continue;
                }
                $ownership = $this->normalizeOwnership($product->brand?->ownership)
                    ?? $classifier->ownershipFromBrand(
                        $product->brand?->name,
                        $product->inventory_id,
                        $product->name ?? $product->source_description,
                    );
                if ($ownership === null || $ownership === $product->ownership) {
                    continue;
                }
                $product->update(['ownership' => $ownership]);
                $productUpdated++;
            }
        });

        AcumaticaInventoryItem::query()
            ->with(['catalogueProduct.brand', 'catalogueProduct.category', 'catalogueProduct.tradingGroup'])
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($classifier, $force, &$inventoryUpdated) {
                foreach ($items as $item) {
                    $product = $item->catalogueProduct;
                    $ownership = $this->normalizeOwnership($product?->ownership)
                        ?? $this->normalizeOwnership($product?->brand?->ownership)
                        ?? $classifier->ownershipFromBrand(
                            $product?->brand?->name ?? $item->brand,
                            $item->inventory_id,
                            $product?->name ?? $item->description,
                        );

                    if ($ownership === null) {
                        continue;
                    }

                    $productType = $classifier->productTypeFromOwnership($ownership);
                    $dirty = [];

                    if ($force || blank($item->product_type) || $this->normalizeOwnership($item->product_type) === null) {
                        if ($item->product_type !== $productType) {
                            $dirty['product_type'] = $productType;
                        }
                    }

                    $brandName = $product?->brand?->name;
                    if (filled($brandName) && ($force || blank($item->brand))) {
                        $dirty['brand'] = $brandName;
                    }

                    $categoryName = $product?->category?->name;
                    if (filled($categoryName) && ($force || blank($item->item_group))) {
                        $dirty['item_group'] = $categoryName;
                    }

                    $tradingName = $product?->tradingGroup?->name;
                    if (filled($tradingName) && ($force || blank($item->trading_group))) {
                        $dirty['trading_group'] = $tradingName;
                    }

                    if ($dirty === []) {
                        continue;
                    }

                    $item->fill($dirty)->save();
                    $inventoryUpdated++;
                }
            });

        $this->info("Brands updated: {$brandUpdated}");
        $this->info("Products updated: {$productUpdated}");
        $this->info("Inventory rows updated: {$inventoryUpdated}");

        return self::SUCCESS;
    }

    private function normalizeOwnership(mixed $value): ?string
    {
        return match (strtolower(trim((string) ($value ?? '')))) {
            'manufactured' => 'manufactured',
            'partner', 'trading' => 'partner',
            default => null,
        };
    }
}
