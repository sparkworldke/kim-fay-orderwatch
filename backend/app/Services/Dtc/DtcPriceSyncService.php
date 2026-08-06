<?php

namespace App\Services\Dtc;

use App\Models\AcumaticaInventoryItem;
use App\Models\DtcPrice;
use App\Models\DtcPriceList;
use App\Models\DtcSyncLog;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * DTC Price List sync — two-step design from the WooCommerce Acumatica plugin:
 *
 * 1) Sync products from local inventory (DefaultWarehouseID in DTC, FGS)
 * 2) PUT SalesPricesInquiry → filter PriceCode=DTCACCOUNT → match InventoryID → set dtc_price
 *
 * Also dual-writes to legacy `dtc_prices` so quote pricing (pricedLines) keeps working.
 */
class DtcPriceSyncService
{
    /** @deprecated use CATALOG_WAREHOUSES */
    public const CATALOG_WAREHOUSE = 'FGS';

    /** Default product-sync warehouses (matches Price List multi-select defaults). */
    public const CATALOG_WAREHOUSES = ['DTC', 'FGS'];

    public function __construct(private readonly AcumaticaClient $client) {}

    /**
     * Full sync: products then prices.
     *
     * @return array{ok:bool,products:int,processed:int,matched:int,unmatched:int,skipped:int,price_source:string}
     */
    public function sync(?User $actor = null): array
    {
        $products = $this->syncProducts($actor);
        $prices = $this->syncPrices($actor);

        return [
            'ok' => true,
            'products' => $products['products'],
            'processed' => $prices['processed'],
            'matched' => $prices['matched'],
            'unmatched' => $prices['unmatched'],
            'skipped' => $prices['skipped'],
            'price_source' => $prices['price_source'],
        ];
    }

    /**
     * Step 1 — seed / refresh dtc_price_list from inventory (DTC + FGS warehouses).
     *
     * @return array{ok:bool,products:int}
     */
    public function syncProducts(?User $actor = null): array
    {
        $warehouses = self::CATALOG_WAREHOUSES;
        $log = DtcSyncLog::create([
            'sync_type' => 'price_list_products',
            'status' => 'running',
            'triggered_by' => $actor?->id,
            'started_at' => now(),
            'metadata' => ['step' => 'products', 'warehouses' => $warehouses],
        ]);

        try {
            $now = now();
            $count = 0;
            $syncedIds = [];

            AcumaticaInventoryItem::query()
                ->where(function ($q) use ($warehouses) {
                    foreach ($warehouses as $i => $wh) {
                        $method = $i === 0 ? 'whereRaw' : 'orWhereRaw';
                        $q->{$method}('UPPER(TRIM(default_warehouse_id)) = ?', [$wh]);
                    }
                })
                ->orderBy('inventory_id')
                ->chunkById(200, function ($items) use (&$count, &$syncedIds, $now) {
                    foreach ($items as $item) {
                        $status = strtolower(trim((string) ($item->item_status ?? '')));
                        if (in_array($status, ['inactive', 'deleted', 'no sales'], true)) {
                            continue;
                        }

                        $wh = strtoupper(trim((string) ($item->default_warehouse_id ?? '')));
                        $defaultPrice = (float) ($item->sales_price ?? 0);
                        $row = DtcPriceList::firstOrNew(['inventory_id' => $item->inventory_id]);
                        $row->fill([
                            'description' => $item->description,
                            'brand' => $item->brand,
                            'product_type' => $item->product_type,
                            'uom' => (string) ($item->default_uom ?: 'PIECE'),
                            'default_price' => $defaultPrice > 0 ? $defaultPrice : null,
                            'currency_id' => $row->currency_id ?: 'KES',
                            'price_code' => 'DTCACCOUNT',
                            'default_warehouse_id' => $wh !== '' ? $wh : $item->default_warehouse_id,
                            'item_status' => $item->item_status,
                            'qty_available' => $item->qty_available,
                            'qty_on_hand' => $item->qty_on_hand,
                            'in_catalog' => true,
                            'product_synced_at' => $now,
                            'synced_at' => $now,
                            'source' => $row->source ?: 'product_sync',
                        ]);
                        // Seed dtc_price from DefaultPrice only when empty (plugin Step 1).
                        if (((float) ($row->dtc_price ?? 0)) <= 0 && $defaultPrice > 0) {
                            $row->dtc_price = $defaultPrice;
                        }
                        $row->save();
                        $syncedIds[] = $item->inventory_id;
                        $count++;
                    }
                });

            // Drop rows no longer in DTC/FGS catalog from in_catalog visibility.
            if ($syncedIds !== []) {
                DtcPriceList::query()
                    ->whereNotIn('inventory_id', $syncedIds)
                    ->where('in_catalog', true)
                    ->update(['in_catalog' => false, 'synced_at' => $now]);
            } else {
                DtcPriceList::query()
                    ->where('in_catalog', true)
                    ->update(['in_catalog' => false, 'synced_at' => $now]);
            }

            $log->update([
                'status' => 'success',
                'records_processed' => $count,
                'finished_at' => now(),
                'metadata' => [
                    'step' => 'products',
                    'products' => $count,
                    'warehouses' => $warehouses,
                ],
            ]);

            return ['ok' => true, 'products' => $count];
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
     * Step 2 — pull DTCACCOUNT prices (PUT inquiry) and match InventoryID.
     *
     * @return array{ok:bool,processed:int,matched:int,unmatched:int,skipped:int,price_source:string,fetched?:int}
     */
    public function syncPrices(?User $actor = null): array
    {
        $log = DtcSyncLog::create([
            'sync_type' => 'prices',
            'status' => 'running',
            'triggered_by' => $actor?->id,
            'started_at' => now(),
            'metadata' => ['price_code' => 'DTCACCOUNT', 'step' => 'prices'],
        ]);

        try {
            // Ensure product rows exist first (idempotent).
            if (DtcPriceList::query()->count() === 0) {
                $this->syncProducts($actor);
            }

            $catalog = DtcPriceList::query()->pluck('uom', 'inventory_id');
            $count = 0;
            $matched = 0;
            $unmatched = 0;
            $skipped = 0;
            $priceSource = 'sales_prices_inquiry_put';

            try {
                $rows = $this->client->fetchDtcPrices();
            } catch (Throwable $e) {
                // Fallback: use StockItem default prices already on product list.
                $priceSource = 'default_price_fallback';
                $fallback = $this->applyDefaultPriceFallback();
                $log->update([
                    'status' => 'success',
                    'records_processed' => $fallback['processed'],
                    'finished_at' => now(),
                    'metadata' => [
                        'price_code' => 'DTCACCOUNT',
                        'step' => 'prices',
                        'price_source' => $priceSource,
                        'inquiry_error' => $e->getMessage(),
                        'matched' => $fallback['processed'],
                        'unmatched' => 0,
                        'skipped' => 0,
                    ],
                    'error_message' => 'SalesPricesInquiry failed; applied DefaultPrice fallback. '.$e->getMessage(),
                ]);

                return [
                    'ok' => true,
                    'processed' => $fallback['processed'],
                    'matched' => $fallback['processed'],
                    'unmatched' => 0,
                    'skipped' => 0,
                    'price_source' => $priceSource,
                ];
            }

            $fetched = count($rows);
            $now = now();
            DB::transaction(function () use ($rows, $catalog, &$count, &$matched, &$unmatched, &$skipped, $now) {
                foreach ($rows as $row) {
                    $inventory = trim((string) AcumaticaClient::scalarVal($row['InventoryID'] ?? null));
                    $price = (float) (AcumaticaClient::val($row['Price'] ?? null) ?? 0);
                    $uom = (string) (AcumaticaClient::scalarVal($row['UOM'] ?? null) ?: 'PIECE');
                    if ($inventory === '' || $price <= 0) {
                        $skipped++;
                        continue;
                    }

                    $listRow = DtcPriceList::where('inventory_id', $inventory)->first();
                    if (! $listRow) {
                        $unmatched++;
                        // Still record for visibility (not in local FGS catalog yet).
                        DtcPriceList::create([
                            'inventory_id' => $inventory,
                            'description' => AcumaticaClient::scalarVal($row['Description'] ?? null),
                            'uom' => $uom,
                            'dtc_price' => $price,
                            'currency_id' => (string) (AcumaticaClient::scalarVal($row['CurrencyID'] ?? null) ?: 'KES'),
                            'price_code' => 'DTCACCOUNT',
                            'in_catalog' => false,
                            'price_synced_at' => $now,
                            'synced_at' => $now,
                        ]);
                        $count++;
                        $this->mirrorLegacyPrice($inventory, $uom, $price, $row, $now);
                        continue;
                    }

                    // Plugin only updates when SalesUOM matches price UOM. Normalize CASE/Cases/etc.
                    // If UOMs differ after normalize, still apply price (log as soft mismatch) so
                    // DTCACCOUNT list is not left empty when StockItem UOM labels differ slightly.
                    $catalogUom = $this->normalizeUom((string) ($listRow->uom ?: $catalog[$inventory] ?? ''));
                    $priceUom = $this->normalizeUom($uom);
                    if ($catalogUom !== '' && $priceUom !== '' && $catalogUom !== $priceUom) {
                        // Prefer price-row UOM (authoritative for DTCACCOUNT sell price).
                        $uom = $uom ?: $listRow->uom;
                    }

                    $listRow->update([
                        'dtc_price' => $price,
                        'uom' => $uom ?: $listRow->uom,
                        'description' => AcumaticaClient::scalarVal($row['Description'] ?? null) ?: $listRow->description,
                        'currency_id' => (string) (AcumaticaClient::scalarVal($row['CurrencyID'] ?? null) ?: $listRow->currency_id ?: 'KES'),
                        'price_code' => 'DTCACCOUNT',
                        'price_synced_at' => $now,
                        'synced_at' => $now,
                    ]);
                    $matched++;
                    $count++;
                    $this->mirrorLegacyPrice($inventory, $uom, $price, $row, $now);
                }
            });

            $log->update([
                'status' => 'success',
                'records_processed' => $count,
                'finished_at' => now(),
                'metadata' => [
                    'price_code' => 'DTCACCOUNT',
                    'step' => 'prices',
                    'price_source' => $priceSource,
                    'fetched_dtcaccount' => $fetched,
                    'matched' => $matched,
                    'unmatched' => $unmatched,
                    'skipped' => $skipped,
                    'endpoint' => 'PUT SalesPricesInquiry?$expand=SalesPriceDetails body={}',
                ],
            ]);

            return [
                'ok' => true,
                'processed' => $count,
                'matched' => $matched,
                'unmatched' => $unmatched,
                'skipped' => $skipped,
                'price_source' => $priceSource,
                'fetched' => $fetched,
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
     * When SalesPricesInquiry is unavailable, use DefaultPrice / sales_price already on product rows.
     *
     * @return array{processed:int}
     */
    private function applyDefaultPriceFallback(): array
    {
        $processed = 0;
        $now = now();
        DtcPriceList::query()
            ->where(function ($q) {
                $q->whereNull('dtc_price')->orWhere('dtc_price', '<=', 0);
            })
            ->whereNotNull('default_price')
            ->where('default_price', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$processed, $now) {
                foreach ($rows as $row) {
                    $row->update([
                        'dtc_price' => $row->default_price,
                        'price_synced_at' => $now,
                        'synced_at' => $now,
                    ]);
                    $this->mirrorLegacyPrice(
                        $row->inventory_id,
                        $row->uom,
                        (float) $row->default_price,
                        [],
                        $now,
                    );
                    $processed++;
                }
            });

        // Mirror any remaining seeded prices into legacy dtc_prices for quote pricing.
        DtcPriceList::query()
            ->where('dtc_price', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($now) {
                foreach ($rows as $row) {
                    $this->mirrorLegacyPrice(
                        $row->inventory_id,
                        (string) ($row->uom ?: 'PIECE'),
                        (float) $row->dtc_price,
                        [],
                        $now,
                    );
                }
            });

        return ['processed' => max($processed, (int) DtcPriceList::where('dtc_price', '>', 0)->count())];
    }

    /** Keep dtc_prices in sync for quote pricedLines(). */
    private function mirrorLegacyPrice(string $inventory, string $uom, float $price, array $row, $now): void
    {
        $effective = $this->date(AcumaticaClient::scalarVal($row['EffectiveDate'] ?? null));
        DtcPrice::updateOrCreate(
            [
                'inventory_id' => $inventory,
                'uom' => $uom ?: 'PIECE',
                'effective_date' => $effective,
                'break_qty' => (float) (AcumaticaClient::val($row['BreakQty'] ?? null) ?? 0),
            ],
            [
                'description' => AcumaticaClient::scalarVal($row['Description'] ?? null),
                'price_code' => 'DTCACCOUNT',
                'price' => $price,
                'currency_id' => (string) (AcumaticaClient::scalarVal($row['CurrencyID'] ?? null) ?: 'KES'),
                'expiration_date' => $this->date(AcumaticaClient::scalarVal($row['ExpirationDate'] ?? null)),
                'acumatica_record_id' => (string) (AcumaticaClient::scalarVal($row['RecordID'] ?? null) ?: ($row['id'] ?? '')),
                'synced_at' => $now,
            ],
        );
    }

    private function date(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /** Normalize UOM labels so CASE / Cases / cases compare equal (plugin UOM check). */
    private function normalizeUom(string $uom): string
    {
        $u = strtoupper(trim($uom));
        if ($u === '') {
            return '';
        }
        // Common Acumatica / stock label variants.
        $aliases = [
            'CASES' => 'CASE',
            'CASE' => 'CASE',
            'PIECES' => 'PIECE',
            'PIECE' => 'PIECE',
            'EA' => 'PIECE',
            'EACH' => 'PIECE',
            'PCS' => 'PIECE',
            'PC' => 'PIECE',
        ];

        return $aliases[$u] ?? $u;
    }
}
