<?php

namespace App\Services\Admin;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaProductCategory;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\Concerns\InteractsWithAcumaticaSyncRun;
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
        $filtersMeta = $this->filterMeta($warehouseId, $itemClass, $minQty);

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

        $filtersMeta = ['mode' => 'stocks_only'] + $this->filterMeta($warehouseId, $itemClass, $minQty);

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

        do {
            $this->touchSyncRun($run);
            $page = $this->client->fetchActiveInventoryItems($skip, self::PAGE_SIZE, warehouseId: $warehouseId, itemClass: $itemClass);
            foreach ($page as $raw) {
                $this->touchSyncRun($run);

                if (! $this->isActiveInventoryItem($raw)) {
                    continue;
                }

                // Skip records with a missing or empty InventoryID and log a warning (Req 4.1)
                if (! $this->str($raw['InventoryID'] ?? null)) {
                    $recordIndex = $skip + $total + $failed + $skippedUnknown + $skippedLowQty;
                    StructuredLogger::write('warning', 'acumatica', 'inventory_sync_skipped_missing_id', [
                        'sync_run_id'  => $run->id,
                        'record_index' => $recordIndex,
                        'raw_fragment' => json_encode(array_intersect_key($raw, array_flip(['InventoryID', 'Description', 'ItemClass', 'DefaultWarehouseID']))),
                    ]);
                    continue;
                }

                // Apply minimum quantity filter before counting the record
                if ($minQty !== null && $this->extractQtyOnHand($raw, $warehouseId) < $minQty) {
                    $skippedLowQty++;
                    continue;
                }

                $total++;
                try {
                    if ($stocksOnly) {
                        $result = $this->updateStocksOnly($raw, $run->id, $warehouseId);
                        if ($result === 'skipped') {
                            $skippedUnknown++;
                            continue;
                        }
                        if ($result === 'zero_qty') {
                            $zeroQtyCount++;
                        }
                    } else {
                        $this->upsertItem($raw, $run->id, $warehouseId);
                    }
                    $success++;
                    usleep(100_000);
                } catch (Throwable $e) {
                    $failed++;
                    $this->recordDeadLetter($run->id, $raw, $e);
                }
            }
            $skip += count($page);
            usleep(500_000);
        } while (count($page) === self::PAGE_SIZE);

        $filters = array_merge($run->filters ?? [], [
            'skipped_unknown' => $skippedUnknown,
            'skipped_low_qty' => $skippedLowQty,
            'zero_qty_count'  => $zeroQtyCount,
        ]);

        if ($stocksOnly && $success > 0 && $zeroQtyCount === $success) {
            $filters['warning'] = 'All updated items still show zero qty — Acumatica may not expose QtyOnHand on this endpoint.';
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

    /** @return 'updated'|'zero_qty'|'skipped' */
    private function updateStocksOnly(array $raw, int $runId, ?string $warehouseId = null): string
    {
        $inventoryId = $this->str($raw['InventoryID'] ?? null);

        if (! $inventoryId) {
            throw new \InvalidArgumentException('Stock item missing InventoryID');
        }

        $existing = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
        if (! $existing) {
            return 'skipped';
        }

        $qtyOnHand    = $this->extractQtyOnHand($raw, $warehouseId);
        $qtyAvailable = $this->extractQtyAvailable($raw, $warehouseId);
        $uom          = $this->extractUom($raw);
        $previousQty  = (float) $existing->qty_on_hand;

        $existing->update([
            'default_uom'          => $uom ?? $existing->default_uom,
            'default_warehouse_id' => $warehouseId !== null
                ? strtoupper(trim($warehouseId))
                : (($this->str($raw['DefaultWarehouseID'] ?? null) !== null)
                    ? strtoupper(trim($this->str($raw['DefaultWarehouseID'] ?? null)))
                    : $existing->default_warehouse_id),
            'qty_on_hand'          => $qtyOnHand,
            'qty_available'        => $qtyAvailable,
            'product_category_id'  => $this->resolveCategoryId($this->str($raw['ItemClass'] ?? null)) ?? $existing->product_category_id,
            'sync_run_id'          => $runId,
            'synced_at'            => now(),
            'raw_payload'          => json_encode($raw),
        ]);

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

        return $qtyOnHand <= 0 ? 'zero_qty' : 'updated';
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
        $previousQty = $existing ? (float) $existing->qty_on_hand : null;

        $itemClass   = $this->str($raw['ItemClass'] ?? null);
        $description = $this->str($raw['Description'] ?? null);
        $brandInfo   = $this->brandClassifier->classify($description, $inventoryId);

        $rawWarehouse      = $warehouseId ?? $this->str($raw['DefaultWarehouseID'] ?? null);
        $normalizedWarehouse = $rawWarehouse !== null ? strtoupper(trim($rawWarehouse)) : null;

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
                'brand'                 => $brandInfo['brand'],
                'product_type'          => $brandInfo['product_type'],
                'product_category_id'   => $this->resolveCategoryId($itemClass),
                'default_uom'           => $this->extractUom($raw),
                'valuation_method'      => $this->str($raw['ValuationMethod'] ?? null),
                'is_stock_item'         => (bool) (AcumaticaClient::val($raw['IsStockItem'] ?? null) ?? true),
                'sales_price'           => (float) ($this->str($raw['SalesPrice'] ?? $raw['DefaultPrice'] ?? null) ?? 0),
                'default_warehouse_id'  => $normalizedWarehouse,
                'item_status'           => $this->str($raw['ItemStatus'] ?? null),
                'last_cost'             => $rawLastCost !== null ? (float) $rawLastCost : null,
                'average_cost'          => $rawAverageCost !== null ? (float) $rawAverageCost : null,
                'last_modified_at'      => $lastModifiedAt,
                'qty_on_hand'           => $qtyOnHand,
                'qty_available'         => $qtyAvailable,
                'sync_run_id'           => $runId,
                'synced_at'             => now(),
                'raw_payload'           => json_encode($raw),
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
            $sum = $this->sumWarehouseQty($raw[$detailKey] ?? null, $warehouseId);
            if ($sum > 0) {
                return $sum;
            }
        }

        return 0;
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
            $available = $this->sumWarehouseQty($raw[$detailKey] ?? null, $warehouseId, ['QtyAvailable', 'QtyOnHand']);
            if ($available > 0) {
                return $available;
            }
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
