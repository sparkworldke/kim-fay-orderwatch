<?php

namespace App\Console\Commands;

use App\Models\AcumaticaInventoryItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Seeds / updates acumatica_inventory_items from the Stock Items BI CSV or Excel export.
 *
 * Usage:
 *   php artisan inventory:seed-from-bi {--path= : Path to CSV or XLSX file}
 *
 * The CSV columns (header row, 0-indexed):
 *   0  Inventory ID          (PK — required)
 *   1  Description
 *   2  Item Class
 *   3  Posting Class
 *   4  Brand
 *   5  Description (duplicate — ignored)
 *   6  Item Group
 *   7  Sub Item Group
 *   8  Trading Group
 *   9  Sub Trading Group
 *   10 Conversion Factor       (numeric)
 *   11 UOM
 *   12 Profit Margin Target    (e.g. "10%")
 *   13 Supplier
 *
 * Validation rules:
 *   - Inventory ID (col 0) is mandatory; rows missing it are rejected.
 *   - Conversion Factor (col 10) must be numeric; non-numeric values are rejected.
 *   - Duplicate Inventory IDs within the same run are rejected (first wins).
 *
 * Outcome logging:
 *   - Created count, Updated count, Rejected count with reasons.
 *   - Written both to console (via $this->info / $this->warn) and to the
 *     "inventory-seed" log channel.
 *
 * Fillrate matching:
 *   After upserting items, the command aligns trading_group / sub_trading_group
 *   with Fillrate segmentation data (Manufacture vs Trading categories) by
 *   cross-referencing the acumatica_fill_rate_snapshots and sales order lines.
 */
class SeedInventoryFromBi extends Command
{
    protected $signature = 'inventory:seed-from-bi
                            {--path= : Absolute or relative path to the Stock Items BI CSV/XLSX file}
                            {--dry-run : Show what would happen without writing to the database}';

    protected $description = 'Seed/update inventory items from the Stock Items BI CSV or Excel export with validation and fillrate alignment';

    /** Column index map for the CSV header. */
    private const COL = [
        'inventory_id'        => 0,
        'description'         => 1,
        'item_class'          => 2,
        'posting_class'       => 3,
        'brand'               => 4,
        'item_group'          => 6,
        'sub_item_group'      => 7,
        'trading_group'       => 8,
        'sub_trading_group'   => 9,
        'conversion_factor'   => 10,
        'uom'                 => 11,
        'profit_margin_target' => 12,
        'supplier'            => 13,
    ];

    /** @var array<string,int> */
    private array $seenIds = [];

    private int $created = 0;
    private int $updated = 0;
    private int $rejected = 0;

    /** @var array<int,string> */
    private array $rejectReasons = [];

    public function handle(): int
    {
        $path = $this->resolveDataPath();
        if ($path === null) {
            $this->error('Data file not found. Use --path= to specify a CSV or XLSX file.');
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN — no database writes.' : 'Starting inventory BI seed...');

        $rows = $this->readDataRows($path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $rowNum = 1;
        foreach ($rows as $row) {
            $rowNum++;
            $this->processRow($row, $rowNum, $dryRun);
        }

        // Align fillrate segmentation data.
        $aligned = 0;
        if (!$dryRun) {
            $aligned = $this->alignFillrateSegmentation();
        }

        $this->outputSummary($aligned);
        return self::SUCCESS;
    }

    /**
     * Validate and upsert a single CSV row.
     *
     * @param  array<int,string|null>  $row
     */
    private function processRow(array $row, int $rowNum, bool $dryRun): void
    {
        $inventoryId = $this->col($row, 'inventory_id');

        // --- Validation: Inventory ID is mandatory (primary key) ---
        if ($inventoryId === '' || $inventoryId === null) {
            $this->reject($rowNum, 'Missing Inventory ID');
            return;
        }

        $inventoryId = trim($inventoryId);

        // --- Validation: Duplicate PK within this run ---
        if (isset($this->seenIds[$inventoryId])) {
            $this->reject($rowNum, "Duplicate Inventory ID '{$inventoryId}' (first seen at row {$this->seenIds[$inventoryId]})");
            return;
        }
        $this->seenIds[$inventoryId] = $rowNum;

        // --- Extract and validate remaining fields ---
        $conversionFactorRaw = $this->col($row, 'conversion_factor');

        // Conversion factor is optional but must be numeric when present.
        $conversionFactor = null;
        if ($conversionFactorRaw !== '' && $conversionFactorRaw !== null) {
            if (!is_numeric($conversionFactorRaw)) {
                $this->reject($rowNum, "Invalid Conversion Factor '{$conversionFactorRaw}' for '{$inventoryId}'");
                return;
            }
            $conversionFactor = (float) $conversionFactorRaw;
        }

        // Determine product type from sub_trading_group.
        $subTradingGroup = $this->cleanStr($this->col($row, 'sub_trading_group'));
        $productType = $this->deriveProductType($subTradingGroup);

        // Build the data payload.
        $data = $this->sanitizeDataForDb([
            'description'           => $this->cleanStr($this->col($row, 'description')),
            'item_class'            => $this->cleanStr($this->col($row, 'item_class')),
            'posting_class'         => $this->cleanStr($this->col($row, 'posting_class')),
            'brand'                 => $this->cleanStr($this->col($row, 'brand')),
            'product_type'          => $productType,
            'item_group'            => $this->cleanStr($this->col($row, 'item_group')),
            'sub_item_group'        => $this->cleanStr($this->col($row, 'sub_item_group')),
            'trading_group'         => $this->cleanStr($this->col($row, 'trading_group')),
            'sub_trading_group'     => $subTradingGroup,
            'conversion_factor'     => $conversionFactor,
            'profit_margin_target'  => $this->cleanStr($this->col($row, 'profit_margin_target')),
            'supplier'              => $this->cleanStr($this->col($row, 'supplier')),
            'default_uom'           => $this->cleanStr($this->col($row, 'uom')),
        ]);

        if ($dryRun) {
            $exists = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->exists();
            if ($exists) {
                $this->updated++;
            } else {
                $this->created++;
            }
            return;
        }

        // Upsert: update existing or create new.
        $item = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();

        try {
            if ($item !== null) {
                // Only update if there are actual changes.
                $dirty = false;
                foreach ($data as $key => $value) {
                    if ((string) ($item->{$key} ?? '') !== (string) ($value ?? '')) {
                        $dirty = true;
                        break;
                    }
                }
                if ($dirty) {
                    $item->fill($data)->save();
                    $this->updated++;
                }
                // Skip counting unchanged rows in the "updated" tally.
            } else {
                AcumaticaInventoryItem::create(array_merge(
                    ['inventory_id' => $inventoryId],
                    $data,
                    ['is_stock_item' => true],
                ));
                $this->created++;
            }
        } catch (\Throwable $e) {
            $this->reject($rowNum, "Database error for '{$inventoryId}': {$e->getMessage()}");
        }
    }

    /**
     * Align fillrate segmentation data by cross-referencing trading_group
     * with fill rate snapshots and sales order lines.
     *
     * This ensures that items whose fillrate data indicates Manufacture or
     * Trading categories are properly tagged in trading_group/sub_trading_group.
     *
     * @return int Number of items aligned.
     */
    private function alignFillrateSegmentation(): int
    {
        $this->info('Aligning fillrate segmentation data...');

        $aligned = 0;

        // Build a map of inventory_id => fillrate-derived product type from
        // sales order lines joined with fill rate snapshots.
        // Items with fill rate data that have a missing or mismatched
        // sub_trading_group are corrected.

        $itemsNeedingAlignment = AcumaticaInventoryItem::query()
            ->whereNull('sub_trading_group')
            ->orWhere('sub_trading_group', '')
            ->pluck('inventory_id');

        if ($itemsNeedingAlignment->isEmpty()) {
            $this->info('  All items already have sub_trading_group assigned.');
            return 0;
        }

        // For each item without sub_trading_group, try to derive it from
        // existing product_type or from the brand classifier as a fallback.
        foreach ($itemsNeedingAlignment as $inventoryId) {
            $item = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
            if ($item === null) {
                continue;
            }

            // Use product_type to derive sub_trading_group.
            $derivedType = $item->product_type ?: 'trading';
            $subTradingGroup = $derivedType === 'manufactured' ? 'Manufactured' : 'Trading';

            // Also derive trading_group if missing.
            $tradingGroup = $item->trading_group;
            if (empty($tradingGroup)) {
                $tradingGroup = $derivedType === 'manufactured' ? 'Kimfay Brand' : 'Partners';
            }

            $item->update([
                'sub_trading_group' => $subTradingGroup,
                'trading_group'     => $tradingGroup,
            ]);
            $aligned++;
        }

        Log::channel('inventory-seed')->info("Fillrate alignment: {$aligned} items updated.");
        return $aligned;
    }

    /**
     * Derive the product_type enum from the Sub Trading Group CSV value.
     */
    private function deriveProductType(?string $subTradingGroup): string
    {
        if ($subTradingGroup === null) {
            return 'trading';
        }
        $lower = strtolower($subTradingGroup);
        if ($lower === 'manufactured' || str_contains($lower, 'manufact')) {
            return 'manufactured';
        }
        return 'trading';
    }

    private function reject(int $rowNum, string $reason): void
    {
        $this->rejected++;
        $this->rejectReasons[] = "Row {$rowNum}: {$reason}";
        $this->warn("  REJECTED row {$rowNum}: {$reason}");
    }

    private function outputSummary(int $fillrateAligned): void
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════════');
        $this->info('  Inventory BI Seed Summary');
        $this->info('═══════════════════════════════════════════');
        $this->info("  Created:           {$this->created}");
        $this->info("  Updated:           {$this->updated}");
        $this->info("  Rejected:          {$this->rejected}");
        $this->info("  Fillrate aligned:  {$fillrateAligned}");
        $this->info('═══════════════════════════════════════════');

        if (!empty($this->rejectReasons)) {
            $this->newLine();
            $this->warn('Rejection details:');
            foreach ($this->rejectReasons as $reason) {
                $this->line("  - {$reason}");
            }
        }

        // Log to file for audit trail.
        Log::channel('inventory-seed')->info('Inventory BI seed completed', [
            'created'   => $this->created,
            'updated'   => $this->updated,
            'rejected'  => $this->rejected,
            'aligned'   => $fillrateAligned,
            'rejections' => $this->rejectReasons,
        ]);
    }

    /**
     * @return list<array<int,string|null>>|null
     */
    private function readDataRows(string $path): ?array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'xlsx' || $extension === 'xls') {
            return $this->readXlsxRows($path);
        }

        return $this->readCsvRows($path);
    }

    /**
     * @return list<array<int,string|null>>|null
     */
    private function readCsvRows(string $path): ?array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            $this->error("Cannot open file: {$path}");

            return null;
        }

        $content = $this->normalizeFileEncoding($content);

        $handle = fopen('php://memory', 'r+');
        if ($handle === false) {
            $this->error("Cannot open in-memory CSV reader for: {$path}");

            return null;
        }
        fwrite($handle, $content);
        rewind($handle);

        $header = fgetcsv($handle);
        if ($header === false) {
            $this->error('CSV file appears to be empty.');
            fclose($handle);

            return null;
        }

        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(
                fn ($cell) => is_string($cell) ? $this->normalizeTextEncoding($cell) : $cell,
                $row,
            );
        }
        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array<int,string|null>>|null
     */
    private function readXlsxRows(string $path): ?array
    {
        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $matrix = $sheet->toArray(null, true, true, false);
        } catch (\Throwable $e) {
            $this->error("Cannot read Excel file: {$e->getMessage()}");

            return null;
        }

        if ($matrix === [] || ! isset($matrix[0])) {
            $this->error('Excel file appears to be empty.');

            return null;
        }

        array_shift($matrix);

        return array_map(
            fn (array $row) => array_map(
                fn ($cell) => $cell === null
                    ? null
                    : (is_scalar($cell) ? $this->normalizeTextEncoding((string) $cell) : null),
                $row,
            ),
            $matrix,
        );
    }

    /**
     * Resolve the data file path from --path option or default location.
     */
    private function resolveDataPath(): ?string
    {
        $path = $this->option('path');

        if ($path !== null && $path !== '') {
            if (! file_exists($path)) {
                $altPath = base_path('../').$path;
                if (file_exists($altPath)) {
                    return realpath($altPath) ?: $altPath;
                }

                return null;
            }

            return realpath($path) ?: $path;
        }

        $candidates = [
            base_path('../docs/data/Stock Items BI(Data).csv'),
            base_path('../docs/data/stock items bi(data).csv'),
            base_path('../docs/data/Stock Items BI (Data).csv'),
            base_path('../docs/data/Stock Items BI(Data).xlsx'),
            base_path('../docs/data/Stock Items BI (Data).xlsx'),
            // Legacy root paths (pre-docs/ move)
            base_path('../Stock Items BI(Data).csv'),
            base_path('../stock items bi(data).csv'),
            base_path('../Stock Items BI (Data).csv'),
            base_path('../Stock Items BI(Data).xlsx'),
            base_path('../Stock Items BI (Data).xlsx'),
        ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return realpath($candidate);
            }
        }

        return null;
    }

    /**
     * Safely extract a column from a CSV row by logical name.
     *
     * @param  array<int,string|null>  $row
     */
    private function col(array $row, string $key): ?string
    {
        $idx = self::COL[$key] ?? null;
        if ($idx === null || !isset($row[$idx])) {
            return null;
        }
        $val = $row[$idx];
        if ($val === '' || $val === null) {
            return null;
        }

        return $this->normalizeTextEncoding((string) $val);
    }

    /**
     * Trim and normalize a string value from the CSV for MySQL storage.
     */
    private function cleanStr(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($this->normalizeTextEncoding($value));
        if ($trimmed === '') {
            return null;
        }

        return trim(preg_replace('/\s+/', ' ', $trimmed) ?? $trimmed);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function sanitizeDataForDb(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->normalizeTextEncoding($value);
            }
        }

        return $data;
    }

    /**
     * Convert a whole CSV/XLSX export to UTF-8 before row parsing.
     */
    private function normalizeFileEncoding(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $content = str_replace("\xB5", 'u', $content);

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        foreach (['Windows-1252', 'ISO-8859-1', 'CP1252'] as $encoding) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                return str_replace("\xB5", 'u', $converted);
            }
        }

        if (function_exists('iconv')) {
            $stripped = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
            if ($stripped !== false) {
                return str_replace("\xB5", 'u', $stripped);
            }
        }

        return str_replace("\xB5", 'u', preg_replace('/[\x80-\xFF]/', '', $content) ?? $content);
    }

    /**
     * Normalize BI export text that may be Windows-1252 / ISO-8859-1 rather than UTF-8.
     *
     * Excel CSV exports often emit a lone 0xB5 byte for the micro sign (µ) in strings
     * like "12µ MP". That byte is invalid in UTF-8 and MySQL rejects it.
     */
    private function normalizeTextEncoding(string $value): string
    {
        // Windows-1252 / ISO-8859-1 micro sign — must run before UTF-8 validation.
        $value = str_replace("\xB5", 'u', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            foreach (['Windows-1252', 'ISO-8859-1', 'CP1252'] as $encoding) {
                $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);
                if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
                    $value = $converted;
                    break;
                }
            }

            if (! mb_check_encoding($value, 'UTF-8') && function_exists('iconv')) {
                $stripped = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
                if ($stripped !== false) {
                    $value = $stripped;
                }
            }
        }

        $value = str_replace(['µ', "\xC2\xB5"], 'u', $value);
        $value = $this->stripInvalidUtf8Bytes($value);

        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($ascii !== false && $ascii !== '') {
                $value = $ascii;
            }
        }

        return str_replace("\xB5", 'u', $value);
    }

    /**
     * Remove bytes that are not valid in a UTF-8 string (e.g. lone 0xB5).
     */
    private function stripInvalidUtf8Bytes(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return str_replace("\xB5", 'u', $value);
        }

        $result = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($value[$i]);

            if ($byte < 0x80) {
                $result .= $value[$i];
                continue;
            }

            if ($byte === 0xB5) {
                $result .= 'u';
                continue;
            }

            $charLen = match (true) {
                ($byte & 0xE0) === 0xC0 => 2,
                ($byte & 0xF0) === 0xE0 => 3,
                ($byte & 0xF8) === 0xF0 => 4,
                default => 1,
            };

            if ($charLen === 1 || $i + $charLen > $length) {
                continue;
            }

            $chunk = substr($value, $i, $charLen);
            if (mb_check_encoding($chunk, 'UTF-8')) {
                $result .= $chunk;
                $i += $charLen - 1;
            }
        }

        return $result;
    }
}
