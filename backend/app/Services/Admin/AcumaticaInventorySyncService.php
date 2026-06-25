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
            $run = $this->syncStockItemsPaged($run);
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

    private function syncStockItemsPaged(AcumaticaSyncLog $run): AcumaticaSyncLog
    {
        $skip    = 0;
        $total   = 0;
        $success = 0;
        $failed  = 0;

        do {
            $page = $this->client->fetchActiveInventoryItems($skip);
            foreach ($page as $raw) {
                if (! $this->isActiveInventoryItem($raw)) {
                    continue;
                }

                $total++;
                try {
                    $this->upsertItem($raw, $run->id);
                    $success++;
                    usleep(100_000);
                } catch (Throwable $e) {
                    $failed++;
                    $this->recordDeadLetter($run->id, $raw, $e);
                }
            }
            $skip += 100;
            usleep(500_000);
        } while (count($page) === 100);

        $run->update([
            'ended_at'      => now(),
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
        ]);

        return $run;
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
                'default_uom'           => $this->str($raw['DefaultUOM'] ?? $raw['BaseUOM'] ?? null),
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