<?php

namespace App\Services\Imports;

use App\Models\AcumaticaInventoryItem;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImportLog;
use App\Models\TradingGroup;
use App\Services\Admin\ProductBrandClassifier;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class ProductCsvImportService
{
    public function __construct(private readonly ProductBrandClassifier $classifier)
    {
    }

    /** Fixed positions are intentional: the supplied file contains two Description headers. */
    private const COLUMNS = [
        'inventory_id', 'name', 'item_class', 'posting_class', 'brand',
        'source_description', 'item_group', 'sub_item_group', 'portfolio_group',
        'trading_group', 'conversion_factor', 'uom', 'profit_margin_target', 'supplier',
    ];

    public function import(string $path, ?ProductImportLog $log = null, ?int $actorId = null): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Product import file was not found: {$path}");
        }

        $rows = $this->readRows($path);
        $total = count($rows);
        $counts = ['total_rows' => $total, 'processed_rows' => 0, 'created_count' => 0,
            'updated_count' => 0, 'skipped_count' => 0, 'unmatched_count' => 0, 'error_count' => 0];
        $errors = [];
        $seen = [];

        $log?->update(['status' => 'running', 'total_rows' => $total, 'started_at' => now()]);

        foreach (array_chunk($rows, 250, true) as $chunk) {
            DB::transaction(function () use ($chunk, &$counts, &$errors, &$seen, $actorId): void {
                foreach ($chunk as $line => $cells) {
                    try {
                        $row = $this->normalizeRow($cells);
                        if ($row['inventory_id'] === '') {
                            throw new RuntimeException('Inventory ID is blank.');
                        }
                        if (isset($seen[$row['inventory_id']])) {
                            throw new RuntimeException("Duplicate Inventory ID; first seen on row {$seen[$row['inventory_id']]}.");
                        }
                        $seen[$row['inventory_id']] = $line;

                        $inventory = AcumaticaInventoryItem::query()
                            ->whereRaw('UPPER(inventory_id) = ?', [$row['inventory_id']])->first();
                        if (! $inventory) {
                            $counts['unmatched_count']++;
                            $errors[] = ['row' => $line, 'inventory_id' => $row['inventory_id'], 'type' => 'unmatched',
                                'message' => 'Inventory ID is not present in synced Acumatica inventory.'];
                            continue;
                        }

                        $existing = Product::query()->where('inventory_id', $row['inventory_id'])->first();
                        if ($existing?->import_locked) {
                            $counts['skipped_count']++;
                            continue;
                        }

                        $brand = $this->brandWithOwnership(
                            $row['brand'],
                            $row['inventory_id'],
                            $row['name'] ?? $row['source_description'],
                        );
                        $category = $this->category($row['item_group']);
                        $subCategory = $this->category($row['sub_item_group'], $category?->id);
                        $tradingGroup = $this->taxonomy(TradingGroup::class, $row['trading_group']);

                        $ownership = $brand?->ownership
                            ?? $this->classifier->ownershipFromBrand(
                                $row['brand'],
                                $row['inventory_id'],
                                $row['name'] ?? $row['source_description'],
                            );

                        Product::query()->updateOrCreate(
                            ['inventory_id' => $row['inventory_id']],
                            [
                                'acumatica_inventory_item_id' => $inventory->id,
                                'name' => $row['name'], 'item_class' => $row['item_class'],
                                'posting_class' => $row['posting_class'], 'brand_id' => $brand?->id,
                                'category_id' => $category?->id, 'sub_category_id' => $subCategory?->id,
                                'category_path' => $row['source_description'],
                                'source_description' => $row['source_description'],
                                'portfolio_group' => $row['portfolio_group'],
                                'trading_group_id' => $tradingGroup?->id,
                                'ownership' => $ownership,
                                'conversion_factor' => $row['conversion_factor'], 'uom' => $row['uom'],
                                'profit_margin_target' => $row['profit_margin_target'],
                                'supplier' => $row['supplier'], 'source' => 'csv_import',
                                'last_imported_at' => now(), 'updated_by' => $actorId,
                            ],
                        );

                        // Keep inventory denormalized fields in sync for Production Intel filters.
                        $inventory->fill(array_filter([
                            'brand' => $brand?->name ?? $row['brand'],
                            'product_type' => $ownership
                                ? $this->classifier->productTypeFromOwnership($ownership)
                                : null,
                            'item_group' => $row['item_group'],
                            'sub_item_group' => $row['sub_item_group'],
                            'trading_group' => $row['trading_group'],
                            'conversion_factor' => $row['conversion_factor'],
                            'profit_margin_target' => $row['profit_margin_target'],
                            'supplier' => $row['supplier'],
                            'default_uom' => $row['uom'],
                        ], static fn ($v) => $v !== null && $v !== ''))->save();

                        $existing ? $counts['updated_count']++ : $counts['created_count']++;
                    } catch (Throwable $e) {
                        $counts['error_count']++;
                        $errors[] = ['row' => $line, 'inventory_id' => $cells[0] ?? null,
                            'type' => 'parse', 'message' => $e->getMessage()];
                    } finally {
                        $counts['processed_rows']++;
                    }
                }
            });
            $log?->update($counts + ['errors' => array_slice($errors, 0, 1000)]);
        }

        $log?->update($counts + ['errors' => array_slice($errors, 0, 1000), 'status' => 'completed', 'finished_at' => now()]);
        return $counts + ['errors' => $errors];
    }

    /** @return array<int,array<int,mixed>> keyed by source row number */
    private function readRows(string $path): array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['xlsx', 'xls'], true)) {
            $raw = IOFactory::load($path)->getActiveSheet()->toArray(null, false, false, false);
        } else {
            $handle = fopen($path, 'rb');
            if (! $handle) throw new RuntimeException('Unable to open the import file.');
            $raw = [];
            while (($cells = fgetcsv($handle)) !== false) $raw[] = $cells;
            fclose($handle);
        }
        if ($raw === []) return [];
        array_shift($raw);
        $out = [];
        foreach ($raw as $index => $cells) {
            if (trim(implode('', array_map('strval', $cells))) !== '') $out[$index + 2] = $cells;
        }
        return $out;
    }

    private function normalizeRow(array $cells): array
    {
        $row = [];
        foreach (self::COLUMNS as $index => $key) $row[$key] = $this->text($cells[$index] ?? null);
        $row['inventory_id'] = strtoupper($row['inventory_id'] ?? '');
        $row['conversion_factor'] = $this->number($row['conversion_factor']);
        $row['profit_margin_target'] = $this->percentage($row['profit_margin_target']);
        return $row;
    }

    private function text(mixed $value): ?string
    {
        $value = trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) ($value ?? '')));
        return $value === '' ? null : preg_replace('/\s+/u', ' ', $value);
    }

    private function number(?string $value): ?float
    {
        if ($value === null) return null;
        $clean = str_replace([',', ' '], '', $value);
        return is_numeric($clean) ? (float) $clean : null;
    }

    private function percentage(?string $value): ?float
    {
        if ($value === null) return null;
        $number = $this->number(str_replace('%', '', $value));
        return $number === null ? null : ($number > 1 ? $number / 100 : $number);
    }

    private function taxonomy(string $model, ?string $name): mixed
    {
        if ($name === null) return null;
        return $model::query()->firstOrCreate(['name' => $name], ['source' => 'csv_import', 'is_active' => true]);
    }

    private function brandWithOwnership(?string $name, ?string $inventoryId, ?string $description): ?Brand
    {
        if ($name === null || trim($name) === '') {
            $classified = $this->classifier->classify($description, $inventoryId);
            $name = $classified['brand'] ?? null;
        }
        if ($name === null || trim($name) === '') {
            return null;
        }

        $canonical = $this->classifier->normalizeKimfayBrand($name)
            ?? $this->classifier->normalizePartnerBrand($name)
            ?? $name;

        $ownership = $this->classifier->ownershipFromBrand($canonical, $inventoryId, $description);

        $brand = Brand::query()->firstOrCreate(
            ['name' => $canonical],
            ['source' => 'csv_import', 'is_active' => true, 'ownership' => $ownership],
        );

        // Fill ownership when missing; do not overwrite manual brand ownership.
        if ($brand->ownership === null && $ownership !== null) {
            $brand->update(['ownership' => $ownership]);
        }

        return $brand->fresh() ?? $brand;
    }

    private function category(?string $name, ?int $parentId = null): ?Category
    {
        if ($name === null) return null;
        return Category::query()->firstOrCreate(
            ['name' => $name, 'parent_id' => $parentId],
            ['source' => 'csv_import', 'is_active' => true],
        );
    }
}
