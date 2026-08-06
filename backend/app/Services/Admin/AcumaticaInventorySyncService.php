<?php

namespace App\Services\Admin;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\InventoryWarehouseBalance;
use App\Models\InventoryWarehouseBalanceSnapshot;
use App\Jobs\RefreshProductionSummariesJob;
use App\Services\Cache\DomainCache;
use App\Models\AcumaticaProductCategory;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\Concerns\InteractsWithAcumaticaSyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

class AcumaticaInventorySyncService
{
    use InteractsWithAcumaticaSyncRun;

    /** Must match the page size actually requested from fetchActiveInventoryItems() below,
     *  since the paging loop uses it to detect the last (short) page. */
    private const PAGE_SIZE = 50;

    /** acumatica_id → local PK cache, populated once per sync run */
    private array $categoryIdCache = [];

    public function __construct(
        private readonly AcumaticaClient $client,
        private readonly InventoryRunRatePredictor $predictor,
        private readonly ProductBrandClassifier $brandClassifier,
    ) {
    }

    /**
     * @param  array{warehouse_id?: string|null, item_class?: string|null, min_qty?: float|null}  $filters
     */
    public function run(?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null, array $filters = []): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['inventory', 'inventory_stocks'],
            'An inventory sync is already running. Wait for it to finish or stop it first.',
        );

        $warehouseId = isset($filters['warehouse_id']) ? (string) $filters['warehouse_id'] : null;
        $itemClass   = isset($filters['item_class']) ? (string) $filters['item_class'] : null;
        $minQty      = isset($filters['min_qty']) ? (float) $filters['min_qty'] : null;
        $filtersMeta = $this->filterMeta($warehouseId, $itemClass, $minQty) + array_filter([
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ]);

        $run = $this->createSyncRun([
            'sync_type'            => 'inventory',
            'cron_run_log_id'      => $cronRunLogId,
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => $filtersMeta,
        ]);

        StructuredLogger::write('info', 'acumatica', 'inventory_sync_started', [
            'sync_run_id' => $run->id,
            'filters'     => $filtersMeta,
        ]);

        $this->syncProductCategories($run->id);

        try {
            $run = $this->syncStockItemsPaged(
                $run,
                stocksOnly: false,
                warehouseId: $warehouseId,
                itemClass: $itemClass,
                minQty: $minQty,
            );
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'heartbeat_at'  => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'inventory_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        if ($run->status === 'completed') {
            app(DomainCache::class)->bump(
                DomainCache::INVENTORY,
                DomainCache::BACKORDERS,
                DomainCache::BUSINESS_OPTIMIZATION,
                DomainCache::REFERENCES,
                DomainCache::NOT_DELIVERED,
                DomainCache::KP_OPERATIONS,
            );
            RefreshProductionSummariesJob::dispatch(now()->subDays(7)->toDateString(), now()->toDateString(), true);
        }
        return $run->fresh();
    }

    /**
     * @param  array{warehouse_id?: string|null, item_class?: string|null, min_qty?: float|null}  $filters
     */
    public function runStocksOnly(
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
        ?int $cronRunLogId = null,
        array $filters = [],
    ): AcumaticaSyncLog {
        $this->assertNoActiveSync(
            ['inventory', 'inventory_stocks'],
            'An inventory sync is already running. Wait for it to finish or stop it first.',
        );

        $warehouseId = isset($filters['warehouse_id']) ? (string) $filters['warehouse_id'] : null;
        $itemClass   = isset($filters['item_class']) ? (string) $filters['item_class'] : null;
        $minQty      = isset($filters['min_qty']) ? (float) $filters['min_qty'] : null;

        $filtersMeta = ['mode' => 'stocks_only'] + $this->filterMeta($warehouseId, $itemClass, $minQty) + array_filter([
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ]);

        // Warn if the requested category does not exist in our local inventory
        if ($itemClass !== null) {
            $knownClasses = AcumaticaInventoryItem::distinct()->pluck('item_class')->filter()->values()->all();
            if (! in_array($itemClass, $knownClasses, true)) {
                $filtersMeta['category_warning'] = "Category '{$itemClass}' not found in local inventory — items may be pulled fresh from Acumatica.";
            }
        }

        $run = $this->createSyncRun([
            'sync_type'            => 'inventory_stocks',
            'cron_run_log_id'      => $cronRunLogId,
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => $filtersMeta,
        ]);

        StructuredLogger::write('info', 'acumatica', 'inventory_stocks_sync_started', [
            'sync_run_id' => $run->id,
            'filters'     => $filtersMeta,
        ]);

        $this->syncProductCategories($run->id);

        try {
            $run = $this->syncStockItemsPaged(
                $run,
                stocksOnly: true,
                warehouseId: $warehouseId,
                itemClass: $itemClass,
                minQty: $minQty,
            );
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'heartbeat_at'  => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'inventory_stocks_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        if ($run->status === 'completed') {
            app(DomainCache::class)->bump(
                DomainCache::INVENTORY,
                DomainCache::BACKORDERS,
                DomainCache::BUSINESS_OPTIMIZATION,
                DomainCache::REFERENCES,
                DomainCache::NOT_DELIVERED,
                DomainCache::KP_OPERATIONS,
            );
            RefreshProductionSummariesJob::dispatch(now()->subDays(7)->toDateString(), now()->toDateString(), true);
        }
        return $run->fresh();
    }

    private function syncStockItemsPaged(
        AcumaticaSyncLog $run,
        bool $stocksOnly = false,
        ?string $warehouseId = null,
        ?string $itemClass = null,
        ?float $minQty = null,
    ): AcumaticaSyncLog {
        $skip           = 0;
        $total          = 0;
        $success        = 0;
        $failed         = 0;
        $skippedUnknown = 0;
        $skippedLowQty  = 0;
        $zeroQtyCount   = 0;
        $mastersCreated = 0;
        $balancesWritten = 0;
        $itemsScanned   = 0;
        $pagesFetched   = 0;
        $targetWarehouse = $warehouseId !== null ? strtoupper(trim($warehouseId)) : null;

        do {
            $this->touchSyncRun($run);

            // Stocks-only: always page the full catalog with WarehouseDetails (all warehouses).
            // Full catalog sync may still use legacy DefaultWarehouse filters.
            if ($stocksOnly && $itemClass === null) {
                $page = $this->client->fetchStockItemsForWarehouseBalances($skip, self::PAGE_SIZE);
            } else {
                $page = $this->client->fetchActiveInventoryItems(
                    $skip,
                    self::PAGE_SIZE,
                    warehouseId: $warehouseId,
                    itemClass: $itemClass,
                    matchDefaultWarehouseOnly: ! $stocksOnly,
                );
            }
            $pagesFetched++;
            $pageCount = is_array($page) ? count($page) : 0;

            foreach ($page as $raw) {
                $this->touchSyncRun($run);
                $itemsScanned++;

                if (! $this->isActiveInventoryItem($raw)) {
                    continue;
                }

                // Skip records with a missing or empty InventoryID and log a warning (Req 4.1)
                if (! $this->str($raw['InventoryID'] ?? null)) {
                    StructuredLogger::write('warning', 'acumatica', 'inventory_sync_skipped_missing_id', [
                        'sync_run_id'  => $run->id,
                        'record_index' => $itemsScanned,
                        'raw_fragment' => json_encode(array_intersect_key($raw, array_flip(['InventoryID', 'Description', 'ItemClass', 'DefaultWarehouseID']))),
                    ]);
                    continue;
                }

                if ($stocksOnly) {
                    $sites = $this->extractWarehouseSiteQtys($raw);
                    if ($targetWarehouse !== null && ! isset($sites[$targetWarehouse])) {
                        continue;
                    }
                    if ($minQty !== null) {
                        $qty = $targetWarehouse !== null
                            ? (float) ($sites[$targetWarehouse]['qty_on_hand'] ?? 0)
                            : $this->extractQtyOnHand($raw, null);
                        if ($qty < $minQty) {
                            $skippedLowQty++;
                            continue;
                        }
                    }
                } elseif ($minQty !== null && $this->extractQtyOnHand($raw, $warehouseId) < $minQty) {
                    $skippedLowQty++;
                    continue;
                }

                $total++;
                try {
                    if ($stocksOnly) {
                        $result = $this->updateStocksOnly($raw, $run->id, $targetWarehouse);
                        if ($result['status'] === 'skipped') {
                            $skippedUnknown++;
                            $total--;
                            continue;
                        }
                        $balancesWritten += $result['balances_written'];
                        if ($result['status'] === 'zero_qty') {
                            $zeroQtyCount++;
                        }
                        if ($result['status'] === 'created_master') {
                            $mastersCreated++;
                        }
                    } else {
                        $this->upsertItem($raw, $run->id, $warehouseId);
                    }
                    $success++;
                    // Full item upserts are heavier; stocks-only is rate-limited at page level only.
                    if (! $stocksOnly) {
                        usleep(100_000);
                    }
                } catch (Throwable $e) {
                    $failed++;
                    try {
                        $this->recordDeadLetter($run->id, $raw, $e);
                    } catch (Throwable $deadLetterError) {
                        // Diagnostics persistence must not abort the warehouse
                        // sync or prevent remaining stock items from processing.
                        Log::warning('Inventory dead-letter persistence failed', [
                            'sync_run_id' => $run->id,
                            'inventory_id' => AcumaticaClient::val($raw['InventoryID'] ?? null),
                            'item_error' => $e->getMessage(),
                            'dead_letter_error' => $deadLetterError->getMessage(),
                        ]);
                    }
                }
            }
            $skip += $pageCount;
            usleep($stocksOnly ? 200_000 : 500_000);
        } while ($pageCount === self::PAGE_SIZE);

        $filters = array_merge($run->filters ?? [], [
            'mode' => $stocksOnly ? 'stocks_only_warehouse_details' : 'full',
            'target_warehouse' => $targetWarehouse,
            'pages_fetched' => $pagesFetched,
            'items_scanned' => $itemsScanned,
            'skipped_unknown' => $skippedUnknown,
            'skipped_low_qty' => $skippedLowQty,
            'zero_qty_count'  => $zeroQtyCount,
            'balances_written' => $balancesWritten,
            'balances_saved' => $success,
            'masters_created' => $mastersCreated,
            'configured_warehouses' => $this->configuredWarehouseIds(),
        ]);

        if ($stocksOnly && $success === 0 && $itemsScanned === 0) {
            $filters['warning'] = 'No stock items returned from Acumatica (empty pages). Check API endpoint / credentials.';
        } elseif ($stocksOnly && $success === 0 && $itemsScanned > 0 && $targetWarehouse !== null) {
            $filters['warning'] = "Scanned {$itemsScanned} SKUs but none had WarehouseDetails for {$targetWarehouse}.";
        } elseif ($stocksOnly && $success > 0 && $zeroQtyCount === $success) {
            $filters['warning'] = 'Balances saved but all qty_on_hand are zero — check QtyOnHand on WarehouseDetails.';
        }

        $run->update([
            'ended_at'      => now(),
            'heartbeat_at'  => now(),
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
            'filters'       => $filters,
        ]);

        return $run;
    }

    /**
     * @return array{status: 'updated'|'zero_qty'|'created_master'|'skipped', balances_written: int}
     */
    private function updateStocksOnly(array $raw, int $runId, ?string $warehouseId = null): array
    {
        $inventoryId = $this->str($raw['InventoryID'] ?? null);

        if (! $inventoryId) {
            throw new \InvalidArgumentException('Stock item missing InventoryID');
        }

        $existing = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
        if (! $existing) {
            $this->upsertItem($raw, $runId, $warehouseId);
            $existing = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
            if (! $existing) {
                return ['status' => 'skipped', 'balances_written' => 0];
            }
            $createdMaster = true;
        } else {
            $createdMaster = false;
        }

        $uom = $this->extractUom($raw);
        $rawLastCost    = $this->str($raw['LastCost'] ?? null);
        $rawAverageCost = $this->str($raw['AverageCost'] ?? null);
        $rawSalesPrice  = $this->str($raw['SalesPrice'] ?? $raw['DefaultPrice'] ?? null);

        $patch = [
            'default_uom'          => $uom ?? $existing->default_uom,
            'product_category_id'  => $this->resolveCategoryId($this->str($raw['ItemClass'] ?? null)) ?? $existing->product_category_id,
            'sync_run_id'          => $runId,
            'synced_at'            => now(),
            'raw_payload'          => json_encode($raw),
        ];
        if ($rawLastCost !== null) {
            $patch['last_cost'] = (float) $rawLastCost;
        }
        if ($rawAverageCost !== null) {
            $patch['average_cost'] = (float) $rawAverageCost;
        }
        if ($rawSalesPrice !== null && (float) $rawSalesPrice > 0) {
            $patch['sales_price'] = (float) $rawSalesPrice;
        }

        $sites = $this->extractWarehouseSiteQtys($raw);
        $configured = $this->configuredWarehouseIds();
        $sitesToWrite = [];

        if ($warehouseId !== null) {
            $wh = strtoupper(trim($warehouseId));
            if (isset($sites[$wh])) {
                $sitesToWrite[$wh] = $sites[$wh];
            }
        } else {
            // All configured warehouses present on the SKU; if none, fall back to header qty on default site.
            foreach ($configured as $wh) {
                if (isset($sites[$wh])) {
                    $sitesToWrite[$wh] = $sites[$wh];
                }
            }
            if ($sitesToWrite === []) {
                $defaultWh = strtoupper(trim((string) ($this->str($raw['DefaultWarehouseID'] ?? null) ?: 'FGS')));
                $sitesToWrite[$defaultWh] = [
                    'qty_on_hand' => $this->extractQtyOnHand($raw, null),
                    'qty_available' => $this->extractQtyAvailable($raw, null),
                ];
            }
        }

        if ($sitesToWrite === []) {
            return ['status' => 'skipped', 'balances_written' => 0];
        }

        // Keep master FGS (or first configured) qty in sync for legacy single-qty columns.
        $primaryWh = isset($sitesToWrite['FGS'])
            ? 'FGS'
            : (string) array_key_first($sitesToWrite);
        $primaryQty = (float) ($sitesToWrite[$primaryWh]['qty_on_hand'] ?? 0);
        if ($primaryWh === 'FGS' || ! isset($existing->qty_on_hand)) {
            $patch['qty_on_hand'] = $primaryQty;
            if (array_key_exists('qty_available', $sitesToWrite[$primaryWh])) {
                $patch['qty_available'] = $sitesToWrite[$primaryWh]['qty_available'];
            }
        }
        $existing->update($patch);

        $written = 0;
        $anyPositive = false;
        foreach ($sitesToWrite as $wh => $qty) {
            $qtyOnHand = (float) ($qty['qty_on_hand'] ?? 0);
            $qtyAvailable = $qty['qty_available'] ?? null;
            if ($qtyOnHand > 0) {
                $anyPositive = true;
            }

            $previousBalance = InventoryWarehouseBalance::query()
                ->where('inventory_id', $inventoryId)
                ->where('warehouse_id', $wh)
                ->first();
            $previousQty = (float) ($previousBalance?->qty_on_hand ?? 0);

            InventoryWarehouseBalance::query()->updateOrCreate(
                ['inventory_id' => $inventoryId, 'warehouse_id' => $wh],
                [
                    'inventory_item_id' => $existing->id,
                    'qty_on_hand' => $qtyOnHand,
                    'qty_available' => $qtyAvailable,
                    'uom' => $uom ?? $existing->default_uom,
                    'sync_run_id' => $runId,
                    'synced_at' => now(),
                    'raw_payload' => json_encode($raw),
                ],
            );

            InventoryWarehouseBalanceSnapshot::query()->updateOrCreate(
                [
                    'inventory_id' => $inventoryId,
                    'warehouse_id' => $wh,
                    'sync_run_id' => $runId,
                ],
                [
                    'inventory_item_id' => $existing->id,
                    'qty_on_hand' => $qtyOnHand,
                    'qty_available' => $qtyAvailable,
                    'uom' => $uom ?? $existing->default_uom,
                    'recorded_at' => now(),
                ],
            );

            // Run-rate log only for the targeted warehouse (or FGS when syncing all).
            if ($warehouseId === null || strcasecmp($wh, $warehouseId) === 0) {
                $prediction = $this->predictor->predict($existing->fresh(), $qtyOnHand, $previousQty);
                AcumaticaInventoryRunRateLog::create([
                    'inventory_item_id'   => $existing->id,
                    'inventory_id'        => $inventoryId,
                    'qty_on_hand'         => $qtyOnHand,
                    'qty_delta'           => $prediction['qty_delta'],
                    'daily_run_rate'      => $prediction['daily_run_rate'],
                    'days_until_stockout' => $prediction['days_until_stockout'],
                    'prediction_status'   => $prediction['prediction_status'],
                    'sync_run_id'         => $runId,
                    'logged_at'           => now(),
                ]);
            }

            $written++;
        }

        if ($createdMaster) {
            return ['status' => 'created_master', 'balances_written' => $written];
        }

        return [
            'status' => $anyPositive ? 'updated' : 'zero_qty',
            'balances_written' => $written,
        ];
    }

    /**
     * @return list<string>
     */
    private function configuredWarehouseIds(): array
    {
        return array_values(array_filter(array_map(
            static fn ($w) => strtoupper(trim((string) $w)),
            config('inventory.warehouses', []),
        )));
    }

    /**
     * Map WarehouseID => qty from StockItem WarehouseDetails.
     *
     * @return array<string, array{qty_on_hand: float, qty_available: float|null}>
     */
    private function extractWarehouseSiteQtys(array $raw): array
    {
        $out = [];
        foreach (['WarehouseDetails', 'ItemWarehouseDetails', 'InventoryItemWarehouseDetails'] as $detailKey) {
            $details = $raw[$detailKey] ?? null;
            if (! is_array($details)) {
                continue;
            }
            $rows = array_is_list($details) ? $details : [$details];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $wh = $this->str($row['WarehouseID'] ?? $row['SiteID'] ?? null);
                if ($wh === null) {
                    continue;
                }
                $wh = strtoupper(trim($wh));
                $onHand = $this->str($row['QtyOnHand'] ?? $row['QtyOnHandSummary'] ?? null);
                $available = $this->str($row['QtyAvailable'] ?? null);
                // Prefer first non-empty detail block; later keys only fill gaps.
                if (! isset($out[$wh])) {
                    $out[$wh] = [
                        'qty_on_hand' => $onHand !== null ? (float) $onHand : 0.0,
                        'qty_available' => $available !== null ? (float) $available : null,
                    ];
                } else {
                    if ($onHand !== null && ($out[$wh]['qty_on_hand'] ?? 0) == 0) {
                        $out[$wh]['qty_on_hand'] = (float) $onHand;
                    }
                    if ($available !== null && $out[$wh]['qty_available'] === null) {
                        $out[$wh]['qty_available'] = (float) $available;
                    }
                }
            }
        }

        return $out;
    }

    private function recordDeadLetter(int $runId, array $raw, Throwable $e): void
    {
        $resourceId = AcumaticaClient::val($raw['InventoryID'] ?? null) ?? 'unknown';
        $payload = json_encode($raw);

        $existing = AcumaticaDeadLetter::where('resource_type', 'inventory_item')
            ->where('resource_id', $resourceId)
            ->first();

        if ($existing) {
            $existing->update([
                'sync_run_id'   => $runId,
                'attempt_count' => $existing->attempt_count + 1,
                'last_error'    => $e->getMessage(),
                'raw_payload'   => $payload,
            ]);
        } else {
            AcumaticaDeadLetter::create([
                'sync_run_id'   => $runId,
                'resource_type' => 'inventory_item',
                'resource_id'   => $resourceId,
                'attempt_count' => 1,
                'last_error'    => $e->getMessage(),
                'raw_payload'   => $payload,
            ]);
        }
    }

    private function upsertItem(array $raw, int $runId, ?string $warehouseId = null): void
    {
        $inventoryId = $this->str($raw['InventoryID'] ?? null);

        if (! $inventoryId) {
            throw new \InvalidArgumentException('Stock item missing InventoryID');
        }

        $qtyOnHand    = $this->extractQtyOnHand($raw, $warehouseId);
        $qtyAvailable = $this->extractQtyAvailable($raw, $warehouseId);

        $existing = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();

        $itemClass   = $this->str($raw['ItemClass'] ?? null);
        $description = $this->str($raw['Description'] ?? null);
        $brandInfo   = $this->brandClassifier->classify($description, $inventoryId);
        // Prefer classifier brand; keep BI/seed brand when classifier has no name
        // (avoids wiping Fay/Sifa/etc. on every Acumatica stock sync).
        $resolvedBrand = $brandInfo['brand'] ?? $existing?->brand;

        $rawWarehouse      = $warehouseId ?? $this->str($raw['DefaultWarehouseID'] ?? null);
        $normalizedWarehouse = strtoupper(trim((string) ($rawWarehouse ?: 'FGS')));
        $previousQty = (float) (InventoryWarehouseBalance::query()
            ->where('inventory_id', $inventoryId)
            ->where('warehouse_id', $normalizedWarehouse)
            ->value('qty_on_hand') ?? 0);

        $rawLastModified   = $this->str($raw['LastModified'] ?? null);
        $lastModifiedAt    = null;
        if ($rawLastModified !== null) {
            try {
                $lastModifiedAt = new \DateTime($rawLastModified);
            } catch (\Exception) {
                $lastModifiedAt = null;
            }
        }

        $rawLastCost    = $this->str($raw['LastCost'] ?? null);
        $rawAverageCost = $this->str($raw['AverageCost'] ?? null);

        $item = AcumaticaInventoryItem::updateOrCreate(
            ['inventory_id' => $inventoryId],
            [
                'description'           => $description,
                'item_class'            => $itemClass,
                'brand'                 => $resolvedBrand,
                'product_type'          => $brandInfo['product_type'],
                'product_category_id'   => $this->resolveCategoryId($itemClass),
                'default_uom'           => $this->extractUom($raw),
                'valuation_method'      => $this->str($raw['ValuationMethod'] ?? null),
                'is_stock_item'         => (bool) (AcumaticaClient::val($raw['IsStockItem'] ?? null) ?? true),
                'sales_price'           => (float) ($this->str($raw['SalesPrice'] ?? $raw['DefaultPrice'] ?? null) ?? 0),
                'default_warehouse_id'  => $existing?->default_warehouse_id ?? $normalizedWarehouse,
                'item_status'           => $this->str($raw['ItemStatus'] ?? null),
                'last_cost'             => $rawLastCost !== null ? (float) $rawLastCost : null,
                'average_cost'          => $rawAverageCost !== null ? (float) $rawAverageCost : null,
                'last_modified_at'      => $lastModifiedAt,
                // Legacy master stock remains the FGS compatibility snapshot only.
                'qty_on_hand'           => $normalizedWarehouse === 'FGS' ? $qtyOnHand : ($existing?->qty_on_hand ?? 0),
                'qty_available'         => $normalizedWarehouse === 'FGS' ? $qtyAvailable : $existing?->qty_available,
                'sync_run_id'           => $runId,
                'synced_at'             => now(),
                'raw_payload'           => json_encode($raw),
            ],
        );

        InventoryWarehouseBalance::query()->updateOrCreate(
            ['inventory_id' => $inventoryId, 'warehouse_id' => $normalizedWarehouse],
            [
                'inventory_item_id' => $item->id,
                'qty_on_hand' => $qtyOnHand,
                'qty_available' => $qtyAvailable,
                'uom' => $this->extractUom($raw),
                'sync_run_id' => $runId,
                'synced_at' => now(),
                'raw_payload' => json_encode($raw),
            ],
        );

        InventoryWarehouseBalanceSnapshot::query()->updateOrCreate(
            [
                'inventory_id' => $inventoryId,
                'warehouse_id' => $normalizedWarehouse,
                'sync_run_id' => $runId,
            ],
            [
                'inventory_item_id' => $item->id,
                'qty_on_hand' => $qtyOnHand,
                'qty_available' => $qtyAvailable,
                'uom' => $this->extractUom($raw),
                'recorded_at' => now(),
            ],
        );

        $prediction = $this->predictor->predict($item, $qtyOnHand, $previousQty);

        AcumaticaInventoryRunRateLog::create([
            'inventory_item_id'   => $item->id,
            'inventory_id'        => $inventoryId,
            'qty_on_hand'         => $qtyOnHand,
            'qty_delta'           => $prediction['qty_delta'],
            'daily_run_rate'      => $prediction['daily_run_rate'],
            'days_until_stockout' => $prediction['days_until_stockout'],
            'prediction_status'   => $prediction['prediction_status'],
            'sync_run_id'         => $runId,
            'logged_at'           => now(),
        ]);
    }

    private function extractQtyOnHand(array $raw, ?string $warehouseId = null): float
    {
        if ($warehouseId === null) {
            foreach (['QtyOnHand', 'TotalQtyOnHand', 'QtyOnHandTotal', 'QtyOnHandSummary'] as $field) {
                $v = $this->str($raw[$field] ?? null);
                if ($v !== null) {
                    return (float) $v;
                }
            }
        }

        foreach (['WarehouseDetails', 'ItemWarehouseDetails', 'InventoryItemWarehouseDetails'] as $detailKey) {
            $sum = $this->sumWarehouseQty($raw[$detailKey] ?? null, $warehouseId, ['QtyOnHand', 'QtyOnHandSummary']);
            // Prefer on-hand; fall back to available when on-hand is empty on the site row.
            if ($sum > 0) {
                return $sum;
            }
            $available = $this->sumWarehouseQty($raw[$detailKey] ?? null, $warehouseId, ['QtyAvailable']);
            if ($available > 0) {
                return $available;
            }
            // Targeted site present with explicit zeros — keep 0 rather than header totals.
            if ($warehouseId !== null && $this->itemHasWarehouseDetail($raw, $warehouseId)) {
                return 0.0;
            }
        }

        return 0;
    }

    private function itemHasWarehouseDetail(array $raw, string $warehouseId): bool
    {
        $target = strtoupper(trim($warehouseId));
        if ($target === '') {
            return false;
        }

        foreach (['WarehouseDetails', 'ItemWarehouseDetails', 'InventoryItemWarehouseDetails'] as $detailKey) {
            $details = $raw[$detailKey] ?? null;
            if (! is_array($details)) {
                continue;
            }
            $rows = array_is_list($details) ? $details : [$details];
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $rowWarehouse = $this->str($row['WarehouseID'] ?? $row['SiteID'] ?? null);
                if ($rowWarehouse !== null && strcasecmp($rowWarehouse, $target) === 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $quantityFields
     */
    private function sumWarehouseQty(
        mixed $warehouseDetails,
        ?string $warehouseId = null,
        array $quantityFields = ['QtyOnHand', 'QtyAvailable', 'QtyOnHandSummary'],
    ): float
    {
        if (! is_array($warehouseDetails)) {
            return 0.0;
        }

        $rows = array_is_list($warehouseDetails) ? $warehouseDetails : [$warehouseDetails];
        $sum = 0.0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ($warehouseId !== null) {
                $rowWarehouse = $this->str($row['WarehouseID'] ?? $row['SiteID'] ?? null);
                if ($rowWarehouse === null || strcasecmp($rowWarehouse, $warehouseId) !== 0) {
                    continue;
                }
            }
            foreach ($quantityFields as $field) {
                $value = $this->str($row[$field] ?? null);
                if ($value !== null) {
                    $sum += (float) $value;
                    break;
                }
            }
        }

        return $sum;
    }

    private function filterMeta(?string $warehouseId, ?string $itemClass, ?float $minQty): array
    {
        return array_filter([
            'warehouse_id' => $warehouseId,
            'item_class'   => $itemClass,
            'min_qty'      => $minQty,
        ], fn ($v) => $v !== null);
    }

    private function extractUom(array $raw): ?string
    {
        foreach (['DefaultUOM', 'BaseUOM', 'SalesUOM', 'PurchaseUOM', 'UOM'] as $field) {
            $v = $this->str($raw[$field] ?? null);
            if ($v !== null) {
                return $v;
            }
        }

        return null;
    }

    private function extractQtyAvailable(array $raw, ?string $warehouseId = null): ?float
    {
        if ($warehouseId === null) {
            foreach (['QtyAvailable', 'TotalQtyAvailable'] as $field) {
                $v = $this->str($raw[$field] ?? null);
                if ($v !== null) {
                    return (float) $v;
                }
            }
        }

        foreach (['WarehouseDetails', 'ItemWarehouseDetails', 'InventoryItemWarehouseDetails'] as $detailKey) {
            $details = $raw[$detailKey] ?? null;
            if (! is_array($details)) {
                continue;
            }
            $rows = array_is_list($details) ? $details : [$details];
            $sum = 0.0;
            $found = false;
            foreach ($rows as $row) {
                if (! is_array($row)) continue;
                if ($warehouseId !== null) {
                    $rowWarehouse = $this->str($row['WarehouseID'] ?? $row['SiteID'] ?? null);
                    if ($rowWarehouse === null || strcasecmp($rowWarehouse, $warehouseId) !== 0) continue;
                }
                $value = $this->str($row['QtyAvailable'] ?? null);
                if ($value !== null) {
                    $sum += (float) $value;
                    $found = true;
                }
            }
            if ($found) return $sum;
        }

        return null;
    }

    private function isActiveInventoryItem(array $raw): bool
    {
        $status = $this->str($raw['ItemStatus'] ?? null);
        if ($status === null) {
            return true;
        }

        return strcasecmp($status, 'Active') === 0;
    }

    private function str(mixed $field): ?string
    {
        $v = AcumaticaClient::val($field);
        if ($v === null || $v === '') {
            return null;
        }
        if (is_array($v)) {
            return null;
        }

        return (string) $v;
    }

    /**
     * Fetch all ItemClass records from Acumatica and upsert them into
     * acumatica_product_categories. Populates $categoryIdCache for the run.
     * Non-blocking: a failure here is logged but does not abort the item sync.
     */
    private function syncProductCategories(int $runId): void
    {
        try {
            $classes = $this->client->fetchAllItemClasses();

            foreach ($classes as $raw) {
                $classId = $this->str($raw['ClassID'] ?? null);
                if ($classId === null) {
                    continue;
                }

                $cat = AcumaticaProductCategory::updateOrCreate(
                    ['acumatica_id' => $classId],
                    [
                        'description' => $this->str($raw['Description'] ?? null),
                        'item_type'   => $this->str($raw['ItemType'] ?? $raw['Type'] ?? null),
                        'default_uom' => $this->str($raw['DefaultUOM'] ?? $raw['BaseUOM'] ?? null),
                        'sync_run_id' => $runId,
                        'synced_at'   => now(),
                    ],
                );

                $this->categoryIdCache[$classId] = $cat->id;
            }

            StructuredLogger::write('info', 'acumatica', 'product_categories_synced', [
                'sync_run_id' => $runId,
                'count'       => count($this->categoryIdCache),
            ]);
        } catch (Throwable $e) {
            StructuredLogger::write('warning', 'acumatica', 'product_categories_sync_failed', [
                'sync_run_id' => $runId,
                'error'       => $e->getMessage(),
            ]);
            // Populate cache from existing DB rows so items still get linked
            $this->categoryIdCache = AcumaticaProductCategory::pluck('id', 'acumatica_id')->all();
        }
    }

    /**
     * Resolve the local PK for a given Acumatica ItemClass ID.
     * Uses the in-memory cache built by syncProductCategories().
     * If the class wasn't returned by the ItemClass endpoint, creates a minimal
     * placeholder row so the FK is always set when the item_class string is known.
     */
    private function resolveCategoryId(?string $itemClass): ?int
    {
        if ($itemClass === null) {
            return null;
        }

        if (array_key_exists($itemClass, $this->categoryIdCache)) {
            return $this->categoryIdCache[$itemClass];
        }

        // Try DB first (may have been created by a previous full sync or API call)
        $existing = AcumaticaProductCategory::where('acumatica_id', $itemClass)->first();

        if ($existing) {
            $this->categoryIdCache[$itemClass] = $existing->id;
            return $existing->id;
        }

        // ItemClass endpoint didn't return this class — create a placeholder so the
        // FK can be set. Description can be filled in when the endpoint is available.
        try {
            $cat = AcumaticaProductCategory::create([
                'acumatica_id' => $itemClass,
                'description'  => null,
                'synced_at'    => now(),
            ]);
            $this->categoryIdCache[$itemClass] = $cat->id;
            return $cat->id;
        } catch (\Throwable) {
            // Race condition — another process may have inserted it first
            $id = AcumaticaProductCategory::where('acumatica_id', $itemClass)->value('id');
            $this->categoryIdCache[$itemClass] = $id;
            return $id;
        }
    }
}
