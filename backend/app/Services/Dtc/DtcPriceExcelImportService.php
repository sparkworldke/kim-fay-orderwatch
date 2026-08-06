<?php

namespace App\Services\Dtc;

use App\Models\AcumaticaInventoryItem;
use App\Models\DtcPrice;
use App\Models\DtcPriceList;
use App\Models\DtcSyncLog;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Import DTCACCOUNT prices from the Acumatica "DTC Sales Prices" Excel/CSV export.
 *
 * Expected columns (header row, flexible names):
 * Inventory ID, Description, UOM, Price, Tax Category, Tax, Price Code, Price Type,
 * Warehouse, Currency, Effective Date, Expiration Date, Break Qty
 *
 * Match key: Inventory ID → dtc_price_list.inventory_id
 */
class DtcPriceExcelImportService
{
    /**
     * @return array{
     *   ok:bool,
     *   rows_read:int,
     *   updated:int,
     *   created:int,
     *   skipped:int,
     *   unmatched:int,
     *   errors:list<string>
     * }
     */
    public function import(UploadedFile $file, ?User $actor = null, ?DtcSyncLog $existingLog = null): array
    {
        $log = $existingLog ?? DtcSyncLog::create([
            'sync_type' => 'price_excel_import',
            'status' => 'running',
            'triggered_by' => $actor?->id,
            'started_at' => now(),
            'metadata' => [
                'filename' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
            ],
        ]);

        if ($existingLog) {
            $log->update(['status' => 'running', 'started_at' => now(), 'finished_at' => null, 'error_message' => null]);
        }

        try {
            $path = $file->getRealPath() ?: $file->getPathname();
            $rows = $this->loadRows($path, (string) $file->getClientOriginalExtension());
            if ($rows === []) {
                throw new \RuntimeException('No data rows found in the file. Check the header row includes Inventory ID and Price.');
            }

            $updated = 0;
            $created = 0;
            $skipped = 0;
            $unmatched = 0;
            $errors = [];
            $now = now();

            // Prefetch inventory brands for denormalized filter fields.
            $inventoryIds = collect($rows)->pluck('inventory_id')->filter()->unique()->values()->all();
            $inventoryMeta = AcumaticaInventoryItem::query()
                ->whereIn('inventory_id', $inventoryIds)
                ->get(['inventory_id', 'description', 'brand', 'product_type', 'default_uom', 'default_warehouse_id', 'item_status', 'qty_available', 'qty_on_hand', 'sales_price'])
                ->keyBy('inventory_id');

            DB::transaction(function () use (
                $rows,
                $inventoryMeta,
                &$updated,
                &$created,
                &$skipped,
                &$unmatched,
                &$errors,
                $now,
            ) {
                foreach ($rows as $i => $row) {
                    $line = $i + 2; // header is line 1
                    $inventory = $row['inventory_id'];
                    $price = $row['price'];

                    if ($inventory === '' || $price === null || $price <= 0) {
                        $skipped++;
                        continue;
                    }

                    $item = $inventoryMeta->get($inventory);
                    $listRow = DtcPriceList::firstOrNew(['inventory_id' => $inventory]);
                    $isNew = ! $listRow->exists;

                    $listRow->fill([
                        'description' => $row['description'] ?: ($item?->description ?? $listRow->description),
                        'uom' => $row['uom'] ?: ($item?->default_uom ?: ($listRow->uom ?: 'PIECE')),
                        'dtc_price' => $price,
                        'break_qty' => $row['break_qty'],
                        'currency_id' => $row['currency'] ?: ($listRow->currency_id ?: 'KES'),
                        'price_code' => $row['price_code'] ?: 'DTCACCOUNT',
                        'price_type' => $row['price_type'] ?: $listRow->price_type,
                        'taxation' => $row['taxation'] ?? $listRow->taxation,
                        'tax' => $row['tax'] ?? $listRow->tax,
                        'brand' => $item?->brand ?: $listRow->brand,
                        'product_type' => $item?->product_type ?: $listRow->product_type,
                        'default_warehouse_id' => $row['warehouse']
                            ?: ($item?->default_warehouse_id ?: $listRow->default_warehouse_id),
                        'item_status' => $item?->item_status ?? $listRow->item_status,
                        'qty_available' => $item?->qty_available ?? $listRow->qty_available,
                        'qty_on_hand' => $item?->qty_on_hand ?? $listRow->qty_on_hand,
                        'default_price' => $listRow->default_price
                            ?? (($item && (float) $item->sales_price > 0) ? $item->sales_price : null),
                        'in_catalog' => $item !== null ? true : (bool) $listRow->in_catalog,
                        'effective_date' => $row['effective_date'] ?? $listRow->effective_date,
                        'expiration_date' => $row['expiration_date'] ?? $listRow->expiration_date,
                        'price_synced_at' => $now,
                        'synced_at' => $now,
                        'source' => 'excel',
                    ]);

                    if ($item === null) {
                        $unmatched++;
                        $listRow->in_catalog = false;
                    }

                    try {
                        $listRow->save();
                    } catch (Throwable $e) {
                        $errors[] = "Line {$line} ({$inventory}): ".$e->getMessage();
                        $skipped++;
                        continue;
                    }

                    // Dual-write legacy dtc_prices for quote pricing.
                    DtcPrice::updateOrCreate(
                        [
                            'inventory_id' => $inventory,
                            'uom' => $listRow->uom ?: 'PIECE',
                            'effective_date' => $listRow->effective_date,
                            'break_qty' => (float) ($listRow->break_qty ?? 0),
                        ],
                        [
                            'description' => $listRow->description,
                            'price_code' => $listRow->price_code ?: 'DTCACCOUNT',
                            'price' => $price,
                            'currency_id' => $listRow->currency_id ?: 'KES',
                            'expiration_date' => $listRow->expiration_date,
                            'synced_at' => $now,
                        ],
                    );

                    $isNew ? $created++ : $updated++;
                }
            });

            $log->update([
                'status' => $errors === [] ? 'completed' : 'partial',
                'records_processed' => $updated + $created,
                'progress' => [
                    'rows_read' => count($rows), 'processed' => $updated + $created,
                    'updated' => $updated, 'created' => $created, 'skipped' => $skipped,
                    'unmatched' => $unmatched, 'errors' => array_slice($errors, 0, 20),
                ],
                'finished_at' => now(),
                'error_message' => $errors === [] ? null : implode("\n", array_slice($errors, 0, 20)),
                'metadata' => array_merge($log->metadata ?? [], [
                    'rows_read' => count($rows),
                    'updated' => $updated,
                    'created' => $created,
                    'skipped' => $skipped,
                    'unmatched' => $unmatched,
                    'error_count' => count($errors),
                ]),
            ]);

            return [
                'ok' => true,
                'rows_read' => count($rows),
                'updated' => $updated,
                'created' => $created,
                'skipped' => $skipped,
                'unmatched' => $unmatched,
                'errors' => array_slice($errors, 0, 20),
            ];
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            throw $e;
        }
    }

    /**
     * @return list<array{
     *   inventory_id:string,
     *   description:?string,
     *   uom:?string,
     *   price:?float,
     *   taxation:?string,
     *   tax:?string,
     *   price_code:?string,
     *   price_type:?string,
     *   warehouse:?string,
     *   currency:?string,
     *   effective_date:?string,
     *   expiration_date:?string,
     *   break_qty:?float
     * }>
     */
    private function loadRows(string $path, string $extension): array
    {
        $ext = strtolower($extension);
        if (in_array($ext, ['csv', 'txt'], true)) {
            return $this->parseMatrix($this->csvToMatrix($path));
        }

        $spreadsheet = IOFactory::load($path);
        $matrix = $spreadsheet->getActiveSheet()->toArray(null, true, true, false);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        return $this->parseMatrix($matrix);
    }

    /** @return list<list<mixed>> */
    private function csvToMatrix(string $path): array
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open CSV file.');
        }

        $matrix = [];
        while (($row = fgetcsv($fh)) !== false) {
            $matrix[] = $row;
        }
        fclose($fh);

        return $matrix;
    }

    /**
     * @param  list<list<mixed>>  $matrix
     * @return list<array<string, mixed>>
     */
    private function parseMatrix(array $matrix): array
    {
        if ($matrix === []) {
            return [];
        }

        $headerIndex = null;
        $map = [];
        foreach ($matrix as $idx => $row) {
            $candidate = $this->headerMap($row);
            if (isset($candidate['inventory_id']) && (isset($candidate['price']) || isset($candidate['dtc_price']))) {
                $headerIndex = $idx;
                $map = $candidate;
                break;
            }
        }

        if ($headerIndex === null) {
            throw new \RuntimeException(
                'Could not find a header row with Inventory ID and Price. '.
                'Expected columns like the Acumatica DTC Sales Prices export.'
            );
        }

        $out = [];
        for ($i = $headerIndex + 1; $i < count($matrix); $i++) {
            $row = $matrix[$i];
            if (! is_array($row)) {
                continue;
            }
            $inventory = trim((string) ($row[$map['inventory_id']] ?? ''));
            if ($inventory === '' || strcasecmp($inventory, 'Inventory ID') === 0) {
                continue;
            }

            $priceCol = $map['price'] ?? $map['dtc_price'] ?? null;
            $priceRaw = $priceCol !== null ? ($row[$priceCol] ?? null) : null;

            $out[] = [
                'inventory_id' => $inventory,
                'description' => $this->cell($row, $map['description'] ?? null),
                'uom' => $this->cell($row, $map['uom'] ?? null),
                'price' => $this->parsePrice($priceRaw),
                'taxation' => $this->cell($row, $map['taxation'] ?? $map['tax_category'] ?? null),
                'tax' => $this->cell($row, $map['tax'] ?? null),
                'price_code' => $this->cell($row, $map['price_code'] ?? null) ?: 'DTCACCOUNT',
                'price_type' => $this->cell($row, $map['price_type'] ?? null),
                'warehouse' => $this->cell($row, $map['warehouse'] ?? null),
                'currency' => $this->cell($row, $map['currency'] ?? null) ?: 'KES',
                'effective_date' => $this->parseDate($this->cell($row, $map['effective_date'] ?? null)),
                'expiration_date' => $this->parseDate($this->cell($row, $map['expiration_date'] ?? null)),
                'break_qty' => $this->parsePrice($this->cell($row, $map['break_qty'] ?? null)),
            ];
        }

        return $out;
    }

    /**
     * @param  list<mixed>  $headerRow
     * @return array<string, int>
     */
    private function headerMap(array $headerRow): array
    {
        $map = [];
        foreach ($headerRow as $i => $raw) {
            $key = $this->normalizeHeader((string) $raw);
            if ($key === '') {
                continue;
            }

            $field = match (true) {
                in_array($key, ['inventoryid', 'inventory_id', 'sku', 'itemid', 'item'], true) => 'inventory_id',
                in_array($key, ['description', 'desc', 'product', 'itemdescription'], true) => 'description',
                in_array($key, ['uom', 'salesuom', 'unit'], true) => 'uom',
                in_array($key, ['price', 'dtcprice', 'dtc_price', 'unitprice', 'salesprice'], true) => 'price',
                in_array($key, ['taxcategory', 'tax_category', 'taxation'], true) => 'taxation',
                $key === 'tax' => 'tax',
                in_array($key, ['pricecode', 'price_code'], true) => 'price_code',
                in_array($key, ['pricetype', 'price_type'], true) => 'price_type',
                in_array($key, ['warehouse', 'warehouseid', 'defaultwarehouseid'], true) => 'warehouse',
                in_array($key, ['currency', 'currencyid'], true) => 'currency',
                in_array($key, ['effectivedate', 'effective_date', 'effective'], true) => 'effective_date',
                in_array($key, ['expirationdate', 'expiration_date', 'expiry'], true) => 'expiration_date',
                in_array($key, ['breakqty', 'break_qty', 'breakquantity'], true) => 'break_qty',
                default => null,
            };

            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = (int) $i;
            }
        }

        return $map;
    }

    private function normalizeHeader(string $value): string
    {
        $v = strtolower(trim($value));
        $v = str_replace(["\u{00A0}", "\t"], ' ', $v);
        $v = preg_replace('/[^a-z0-9]+/', '', $v) ?? '';

        return $v;
    }

    /** @param  list<mixed>  $row */
    private function cell(array $row, ?int $index): ?string
    {
        if ($index === null) {
            return null;
        }
        $v = $row[$index] ?? null;
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }

    private function parsePrice(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        // Excel may export "2,362.50000"
        $s = trim((string) $value);
        $s = str_replace([',', ' '], '', $s);
        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }

    private function parseDate(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $value = trim($value);
        try {
            // Support dd/mm/yyyy from Acumatica export and ISO.
            if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $m)) {
                return Carbon::createFromDate((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            }

            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
