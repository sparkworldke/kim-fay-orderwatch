<?php

namespace App\Services\Admin;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaSyncLog;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AcumaticaBackorderSyncService
{
    public function __construct(
        private readonly AcumaticaClient $client,
    ) {
    }

    public function run(?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $run = AcumaticaSyncLog::create([
            'sync_type'            => 'backorders',
            'cron_run_log_id'      => $cronRunLogId,
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
        ]);

        try {
            $orders = $this->client->fetchAllOpenSalesOrdersForBackorders();
            $run    = $this->processOrders($orders, $run);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    private function processOrders(array $orders, AcumaticaSyncLog $run): AcumaticaSyncLog
    {
        $seenKeys    = [];
        $success     = 0;
        $failed      = 0;
        $linesSynced = 0;

        foreach ($orders as $raw) {
            try {
                $keys = $this->upsertBackorderLines($raw, $run->id);
                if ($keys !== []) {
                    $seenKeys = array_merge($seenKeys, $keys);
                    $linesSynced += count($keys);
                    $success++;
                }
            } catch (Throwable $e) {
                $failed++;
                $resourceId = AcumaticaClient::val($raw['OrderNbr'] ?? null) ?? 'unknown';

                $existing = AcumaticaDeadLetter::where('resource_type', 'backorder')
                    ->where('resource_id', $resourceId)
                    ->first();

                if ($existing) {
                    $existing->update([
                        'sync_run_id'   => $run->id,
                        'attempt_count' => $existing->attempt_count + 1,
                        'last_error'    => $e->getMessage(),
                        'raw_payload'   => $raw,
                    ]);
                } else {
                    AcumaticaDeadLetter::create([
                        'sync_run_id'   => $run->id,
                        'resource_type' => 'backorder',
                        'resource_id'   => $resourceId,
                        'attempt_count' => 1,
                        'last_error'    => $e->getMessage(),
                        'raw_payload'   => $raw,
                    ]);
                }
            }
        }

        if (! empty($seenKeys)) {
            $this->pruneStaleLines($seenKeys);
        }

        $run->update([
            'ended_at'      => now(),
            'status'        => $failed > 0 && $linesSynced === 0 ? 'failed' : 'completed',
            'record_count'  => count($orders),
            'success_count' => $linesSynced > 0 ? $linesSynced : $success,
            'failed_count'  => $failed,
            'error_message' => $linesSynced === 0 && $failed === 0 && count($orders) > 0
                ? 'Fetched '.count($orders).' open orders but no lines matched backorder criteria (check OpenQty in Acumatica Details).'
                : null,
        ]);

        return $run;
    }

    /** @return list<string> composite keys synced */
    private function upsertBackorderLines(array $raw, int $runId): array
    {
        $orderNbr = $this->str($raw['OrderNbr'] ?? null);
        if (! $orderNbr) {
            throw new \InvalidArgumentException('Backorder missing OrderNbr');
        }

        $customerId   = $this->str($raw['CustomerID'] ?? null);
        $customerName = $this->str($raw['CustomerName'] ?? null);
        $currencyId   = $this->str($raw['CurrencyID'] ?? $raw['CuryID'] ?? null);
        $scheduled    = $this->date($raw['ScheduledShipmentDate'] ?? null);
        $requestedOn  = $this->date($raw['RequestedOn'] ?? null);

        $lines = $raw['DocumentDetails'] ?? $raw['Details'] ?? [];
        if (! is_array($lines)) {
            $lines = [];
        }

        $keys = [];

        foreach ($lines as $lineRaw) {
            if (! is_array($lineRaw)) {
                continue;
            }

            $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);
            $inventoryId = $mapped['inventory_id'];
            if (! $inventoryId) {
                continue;
            }

            if (! SalesOrderLineFulfillmentDeriver::isBackorderLine(
                $mapped['fulfillment_status'],
                $mapped['open_qty'],
                $mapped['backorder_qty'],
            )) {
                continue;
            }

            $openQty = $mapped['open_qty'] > 0 ? $mapped['open_qty'] : $mapped['backorder_qty'];
            $unitPrice = $mapped['unit_price'];
            $revenueAtRisk = $unitPrice > 0 ? round($openQty * $unitPrice, 2) : 0;

            AcumaticaBackorderLine::updateOrCreate(
                ['order_nbr' => $orderNbr, 'inventory_id' => $inventoryId],
                $this->backorderLineAttributes($mapped, $openQty, $unitPrice, $revenueAtRisk, [
                    'customer_acumatica_id'   => $customerId,
                    'customer_name'           => $customerName,
                    'currency_id'             => $currencyId,
                    'scheduled_shipment_date' => $scheduled,
                    'requested_on'            => $requestedOn,
                    'sync_run_id'             => $runId,
                    'synced_at'               => now(),
                ]),
            );

            $keys[] = "{$orderNbr}|{$inventoryId}";
        }

        return $keys;
    }

    /** @param  list<string>  $activeKeys */
    private function pruneStaleLines(array $activeKeys): void
    {
        $active = collect($activeKeys)->flip();

        AcumaticaBackorderLine::chunkById(200, function ($rows) use ($active) {
            foreach ($rows as $row) {
                $key = "{$row->order_nbr}|{$row->inventory_id}";
                if (! $active->has($key)) {
                    $row->delete();
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $mapped
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function backorderLineAttributes(
        array $mapped,
        float $openQty,
        float $unitPrice,
        float $revenueAtRisk,
        array $extra,
    ): array {
        $attrs = array_merge($extra, [
            'order_qty'       => $mapped['order_qty'],
            'shipped_qty'     => $mapped['shipped_qty'],
            'open_qty'        => $openQty,
            'unit_price'      => $unitPrice,
            'revenue_at_risk' => $revenueAtRisk,
            'warehouse_id'    => $mapped['warehouse_id'],
        ]);

        if (Schema::hasColumn('acumatica_backorder_lines', 'cancelled_qty')) {
            $attrs['cancelled_qty'] = $mapped['cancelled_qty'];
        }
        if (Schema::hasColumn('acumatica_backorder_lines', 'backorder_qty')) {
            $attrs['backorder_qty'] = $mapped['backorder_qty'];
        }
        if (Schema::hasColumn('acumatica_backorder_lines', 'fulfillment_status')) {
            $attrs['fulfillment_status'] = $mapped['fulfillment_status'];
        }
        if (Schema::hasColumn('acumatica_backorder_lines', 'qty_at_approval')) {
            $attrs['qty_at_approval'] = $mapped['qty_at_approval'];
        }

        return $attrs;
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

    private function date(mixed $field): ?string
    {
        $s = $this->str($field);
        if (! $s) {
            return null;
        }
        $ts = strtotime($s);

        return $ts !== false ? date('Y-m-d', $ts) : null;
    }
}