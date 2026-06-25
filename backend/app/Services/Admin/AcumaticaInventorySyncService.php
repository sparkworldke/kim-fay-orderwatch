<?php

namespace App\Services\Admin;

use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSyncLog;
use Throwable;

class AcumaticaInventorySyncService
{
    public function __construct(
        private readonly AcumaticaClient $client,
        private readonly InventoryRunRatePredictor $predictor,
    ) {
    }

    public function run(?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $this->assertNoConcurrentInventorySync();

        $run = AcumaticaSyncLog::create([
            'sync_type'            => 'inventory',
            'cron_run_log_id'      => $cronRunLogId,
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        StructuredLogger::write('info', 'acumatica', 'inventory_sync_started', [
            'sync_run_id' => $run->id,
        ]);

        try {
            $run = $this->syncStockItemsPaged($run, stocksOnly: false);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
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

    public function runStocksOnly(?int $triggeredByUserId = null, string $triggerType = 'manual'): AcumaticaSyncLog
    {
        $this->assertNoConcurrentInventorySync();

        $run = AcumaticaSyncLog::create([
            'sync_type'            => 'inventory_stocks',
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => ['mode' => 'stocks_only'],
        ]);

        StructuredLogger::write('info', 'acumatica', 'inventory_stocks_sync_started', [
            'sync_run_id' => $run->id,
        ]);

        try {
            $run = $this->syncStockItemsPaged($run, stocksOnly: true);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
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

    private function assertNoConcurrentInventorySync(): void
    {
        if (AcumaticaSyncLog::query()
            ->whereIn('sync_type', ['inventory', 'inventory_stocks'])
            ->where('status', 'running')
            ->exists()) {
            throw new \RuntimeException('An inventory sync is already running. Wait for it to finish.');
        }
    }

    private function syncStockItemsPaged(AcumaticaSyncLog $run, bool $stocksOnly = false): AcumaticaSyncLog
    {
        $skip           = 0;
        $total          = 0;
        $success        = 0;
        $failed         = 0;
        $skippedUnknown = 0;
        $zeroQtyCount   = 0;

        do {
            $page = $this->client->fetchActiveInventoryItems($skip);
            foreach ($page as $raw) {
                if (! $this->isActiveInventoryItem($raw)) {
                    continue;
                }

                $total++;
                try {
                    if ($stocksOnly) {
                        $result = $this->updateStocksOnly($raw, $run->id);
                        if ($result === 'skipped') {
                            $skippedUnknown++;
                            continue;
                        }
                        if ($result === 'zero_qty') {
                            $zeroQtyCount++;
                        }
                    } else {
                        $this->upsertItem($raw, $run->id);
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
        } while (count($page) === 100);

        $filters = array_merge($run->filters ?? [], [
            'skipped_unknown' => $skippedUnknown,
            'zero_qty_count'  => $zeroQtyCount,
        ]);

        if ($stocksOnly && $success > 0 && $zeroQtyCount === $success) {
            $filters['warning'] = 'All updated items still show zero qty — Acumatica may not expose QtyOnHand on this endpoint.';
        }

        $run->update([
            'ended_at'      => now(),
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
            'filters'       => $filters,
        ]);

        return $run;
    }

    /** @return 'updated'|'zero_qty'|'skipped' */
    private function updateStocksOnly(array $raw, int $runId): string
    {
        $inventoryId = $this->str($raw['InventoryID'] ?? null);

        if (! $inventoryId) {
            throw new \InvalidArgumentException('Stock item missing InventoryID');
        }

        $existing = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
        if (! $existing) {
            return 'skipped';
        }

        $qtyOnHand    = $this->extractQtyOnHand($raw);
        $qtyAvailable = $this->extractQtyAvailable($raw);
        $uom          = $this->extractUom($raw);
        $previousQty  = (float) $existing->qty_on_hand;

        $existing->update([
            'default_uom'          => $uom ?? $existing->default_uom,
            'default_warehouse_id' => $this->str($raw['DefaultWarehouseID'] ?? null) ?? $existing->default_warehouse_id,
            'qty_on_hand'          => $qtyOnHand,
            'qty_available'        => $qtyAvailable,
            'sync_run_id'          => $runId,
            'synced_at'            => now(),
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

        $existing = AcumaticaDeadLetter::where('resource_type', 'inventory_item')
            ->where('resource_id', $resourceId)
            ->first();

        if ($existing) {
            $existing->update([
                'sync_run_id'   => $runId,
                'attempt_count' => $existing->attempt_count + 1,
                'last_error'    => $e->getMessage(),
                'raw_payload'   => $raw,
            ]);
        } else {
            AcumaticaDeadLetter::create([
                'sync_run_id'   => $runId,
                'resource_type' => 'inventory_item',
                'resource_id'   => $resourceId,
                'attempt_count' => 1,
                'last_error'    => $e->getMessage(),
                'raw_payload'   => $raw,
            ]);
        }
    }

    private function upsertItem(array $raw, int $runId): void
    {
        $inventoryId = $this->str($raw['InventoryID'] ?? null);

        if (! $inventoryId) {
            throw new \InvalidArgumentException('Stock item missing InventoryID');
        }

        $qtyOnHand    = $this->extractQtyOnHand($raw);
        $qtyAvailable = $this->extractQtyAvailable($raw);

        $existing = AcumaticaInventoryItem::where('inventory_id', $inventoryId)->first();
        $previousQty = $existing ? (float) $existing->qty_on_hand : null;

        $item = AcumaticaInventoryItem::updateOrCreate(
            ['inventory_id' => $inventoryId],
            [
                'description'           => $this->str($raw['Description'] ?? null),
                'item_class'            => $this->str($raw['ItemClass'] ?? null),
                'default_uom'           => $this->extractUom($raw),
                'valuation_method'      => $this->str($raw['ValuationMethod'] ?? null),
                'is_stock_item'         => (bool) (AcumaticaClient::val($raw['IsStockItem'] ?? null) ?? true),
                'sales_price'           => (float) ($this->str($raw['SalesPrice'] ?? $raw['DefaultPrice'] ?? null) ?? 0),
                'default_warehouse_id'  => $this->str($raw['DefaultWarehouseID'] ?? null),
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

    private function extractQtyOnHand(array $raw): float
    {
        foreach (['QtyOnHand', 'TotalQtyOnHand', 'QtyOnHandTotal', 'QtyOnHandSummary'] as $field) {
            $v = $this->str($raw[$field] ?? null);
            if ($v !== null) {
                return (float) $v;
            }
        }

        foreach (['WarehouseDetails', 'ItemWarehouseDetails', 'InventoryItemWarehouseDetails'] as $detailKey) {
            $sum = $this->sumWarehouseQty($raw[$detailKey] ?? null);
            if ($sum > 0) {
                return $sum;
            }
        }

        return 0;
    }

    private function sumWarehouseQty(mixed $warehouseDetails): float
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
            $sum += (float) ($this->str(
                $row['QtyOnHand']
                    ?? $row['QtyAvailable']
                    ?? $row['QtyOnHandSummary']
                    ?? null
            ) ?? 0);
        }

        return $sum;
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

    private function extractQtyAvailable(array $raw): ?float
    {
        foreach (['QtyAvailable', 'TotalQtyAvailable'] as $field) {
            $v = $this->str($raw[$field] ?? null);
            if ($v !== null) {
                return (float) $v;
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
}