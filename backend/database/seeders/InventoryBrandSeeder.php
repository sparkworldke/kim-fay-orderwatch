<?php

namespace Database\Seeders;

use App\Models\AcumaticaInventoryItem;
use App\Services\Admin\ProductBrandClassifier;
use Illuminate\Database\Seeder;

/**
 * Apply brand + product_type on existing inventory from Products-With Brands.csv
 * and the official lists in brands.md.
 *
 * Usage:
 *   php artisan db:seed --class=InventoryBrandSeeder
 *
 * CSV columns used: CODE (inventory_id), BRAND, NAME (optional Cosy Poa refine).
 */
class InventoryBrandSeeder extends Seeder
{
    /** Partner / trading brands (brands.md). */
    public const PARTNER_BRANDS = [
        'Airoma',
        'Aptamil',
        'Bio Oil',
        'Cow & Gate',
        'Dabur',
        'Dermoviva',
        'Dove',
        'Dove Baby',
        'Duracell',
        'Fem',
        'Hobby',
        'Huggies',
        'Kotex',
        'Lux',
        'Miswak',
        'Ors',
        'Rexona',
        'Vatika',
    ];

    /** Manufactured / Kimfay brands (brands.md). */
    public const MANUFACTURED_BRANDS = [
        'Cosy',
        'Cosy Poa',
        'Fay',
        'Kleenex',
        'Sifa',
        'Tishu Poa',
        'Ultra Clean',
        'Kimfay',
    ];

    /**
     * CSV / free-text brand → canonical brands.md name.
     *
     * @var array<string, string>
     */
    private const BRAND_ALIASES = [
        'bio oil' => 'Bio Oil',
        'bio-oil' => 'Bio Oil',
        'biooil' => 'Bio Oil',
        'ors' => 'Ors',
        'kleenex' => 'Kleenex',
        'kleneex' => 'Kleenex',
        'kleeneex' => 'Kleenex',
        'cow & gate' => 'Cow & Gate',
        'cow and gate' => 'Cow & Gate',
        'cow&gate' => 'Cow & Gate',
        'ultra clean' => 'Ultra Clean',
        'ultraclean' => 'Ultra Clean',
        'tishu poa' => 'Tishu Poa',
        'tissue poa' => 'Tishu Poa',
        'cosy poa' => 'Cosy Poa',
        'kim-fay' => 'Kimfay',
        'kim fay' => 'Kimfay',
        'kimfay' => 'Kimfay',
    ];

    public function run(): void
    {
        $csvPath = $this->resolveCsvPath();
        if ($csvPath === null) {
            $this->command?->error('Products CSV not found. Place products-with-brands.csv under database/seeders/data/ or repo root Products-With Brands.csv');

            return;
        }

        $this->command?->info('Loading brands from: '.$csvPath);
        $map = $this->loadCsvBrandMap($csvPath);
        $this->command?->info('CSV brand rows: '.count($map));

        $classifier = app(ProductBrandClassifier::class);
        $updated = 0;
        $unchanged = 0;
        $missingInDb = 0;
        $fallbackUpdated = 0;
        $byBrand = [];

        // 1) Exact CODE → BRAND from CSV
        $csvCodes = array_keys($map);
        $existing = AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $csvCodes)
            ->get(['id', 'inventory_id', 'brand', 'product_type', 'description'])
            ->keyBy('inventory_id');

        foreach ($map as $inventoryId => $meta) {
            $item = $existing->get($inventoryId);
            if ($item === null) {
                $missingInDb++;

                continue;
            }

            $brand = $this->canonicalBrand($meta['brand'], $meta['name'] ?? $item->description);
            $productType = $this->productTypeForBrand($brand);

            if ((string) $item->brand === $brand && (string) $item->product_type === $productType) {
                $unchanged++;

                continue;
            }

            $item->forceFill([
                'brand' => $brand,
                'product_type' => $productType,
            ])->save();
            $updated++;
            $byBrand[$brand] = ($byBrand[$brand] ?? 0) + 1;
        }

        // 2) Remaining inventory: prefix/description classifier when brand empty or unclassified
        $classifierTouched = AcumaticaInventoryItem::query()
            ->where(function ($q) use ($csvCodes) {
                $q->whereNull('brand')
                    ->orWhere('brand', '')
                    ->orWhereRaw('LOWER(TRIM(brand)) = ?', ['unclassified']);
                if ($csvCodes !== []) {
                    $q->orWhereNotIn('inventory_id', $csvCodes);
                }
            })
            ->get(['id', 'inventory_id', 'brand', 'product_type', 'description']);

        foreach ($classifierTouched as $item) {
            // Skip rows already set correctly from CSV
            if (isset($map[$item->inventory_id])) {
                continue;
            }

            $resolved = $classifier->resolveBrand(
                $item->brand,
                $item->inventory_id,
                $item->description,
            );
            if ($resolved === null || $resolved === '') {
                continue;
            }

            $brand = $this->canonicalBrand($resolved, $item->description);
            $productType = $this->productTypeForBrand($brand);

            if ((string) $item->brand === $brand && (string) $item->product_type === $productType) {
                continue;
            }

            $item->forceFill([
                'brand' => $brand,
                'product_type' => $productType,
            ])->save();
            $fallbackUpdated++;
            $byBrand[$brand] = ($byBrand[$brand] ?? 0) + 1;
        }

        ksort($byBrand);
        $this->command?->info("Updated from CSV: {$updated}");
        $this->command?->info("Already correct: {$unchanged}");
        $this->command?->info("CSV codes not in DB: {$missingInDb}");
        $this->command?->info("Updated via prefix/classifier fallback: {$fallbackUpdated}");
        $this->command?->info('Brand tallies (updated rows):');
        foreach ($byBrand as $brand => $count) {
            $this->command?->line("  {$brand}: {$count}");
        }

        // Snapshot counts after seed
        $partnerCount = AcumaticaInventoryItem::query()
            ->whereIn('brand', self::PARTNER_BRANDS)
            ->count();
        $mfgCount = AcumaticaInventoryItem::query()
            ->whereIn('brand', self::MANUFACTURED_BRANDS)
            ->count();
        $this->command?->info("Inventory now with partner brands: {$partnerCount}");
        $this->command?->info("Inventory now with manufactured brands: {$mfgCount}");
    }

    private function resolveCsvPath(): ?string
    {
        $override = env('INVENTORY_BRAND_CSV_PATH');
        if (is_string($override) && $override !== '' && is_file($override)) {
            return $override;
        }

        $candidates = [
            database_path('seeders/data/products-with-brands.csv'),
            base_path('database/seeders/data/products-with-brands.csv'),
            base_path('../Products-With Brands.csv'),
            dirname(base_path()).DIRECTORY_SEPARATOR.'Products-With Brands.csv',
        ];

        foreach ($candidates as $path) {
            if (is_string($path) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, array{brand: string, name: string}>
     */
    private function loadCsvBrandMap(string $path): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            return [];
        }

        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [];
        }
        $headers = array_map(static fn ($h) => strtoupper(trim((string) $h)), $headers);

        $map = [];
        $headerCount = count($headers);
        while (($row = fgetcsv($handle)) !== false) {
            if (! is_array($row) || count(array_filter($row, static fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            if (count($row) < $headerCount) {
                $row = array_pad($row, $headerCount, '');
            } elseif (count($row) > $headerCount) {
                $row = array_slice($row, 0, $headerCount);
            }
            $data = array_combine($headers, $row);
            if (! is_array($data)) {
                continue;
            }

            $code = strtoupper(trim((string) ($data['CODE'] ?? '')));
            $brand = trim((string) ($data['BRAND'] ?? ''));
            $name = trim((string) ($data['NAME'] ?? ''));
            if ($code === '' || $code === 'CODE' || $brand === '') {
                continue;
            }

            // First row wins for duplicate codes
            if (! isset($map[$code])) {
                $map[$code] = ['brand' => $brand, 'name' => $name];
            }
        }
        fclose($handle);

        return $map;
    }

    private function canonicalBrand(string $rawBrand, ?string $productName = null): string
    {
        $raw = trim($rawBrand);
        $lower = strtolower($raw);

        if (isset(self::BRAND_ALIASES[$lower])) {
            $raw = self::BRAND_ALIASES[$lower];
            $lower = strtolower($raw);
        }

        // Cosy Poa is often labeled only as Cosy in price lists — use product name.
        $name = strtolower((string) $productName);
        if (($lower === 'cosy' || $lower === 'cosy poa') && str_contains($name, 'cosy poa')) {
            return 'Cosy Poa';
        }

        foreach (array_merge(self::PARTNER_BRANDS, self::MANUFACTURED_BRANDS) as $canonical) {
            if (strcasecmp($canonical, $raw) === 0) {
                return $canonical;
            }
        }

        // Title-case leftover brands (e.g. Kuraflex)
        return $raw;
    }

    private function productTypeForBrand(string $brand): string
    {
        foreach (self::MANUFACTURED_BRANDS as $mfg) {
            if (strcasecmp($mfg, $brand) === 0) {
                return 'manufactured';
            }
        }
        foreach (self::PARTNER_BRANDS as $partner) {
            if (strcasecmp($partner, $brand) === 0) {
                return 'trading';
            }
        }

        // Extra CSV brands not on brands.md — treat as trading partners by default
        return 'trading';
    }
}
