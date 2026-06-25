<?php

namespace App\Services\Admin;

use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaSyncLog;
use Throwable;

class AcumaticaFillRateSyncService
{
    public function __construct(
        private readonly AcumaticaClient $client,
        private readonly FillRateCalculator $calculator,
    ) {
    }

    public function syncDateRange(string $dateFrom, string $dateTo, ?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $run = AcumaticaSyncLog::create([
            'sync_type'            => 'fill_rate',
            'cron_run_log_id'      => $cronRunLogId,
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);

        try {
            $orders = $this->client->fetchOrdersForFillRate($dateFrom, $dateTo);
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
        $total   = count($orders);
        $success = 0;
        $failed  = 0;

        foreach ($orders as $raw) {
            try {
                $this->upsertFillRate($raw, $run->id);
                $success++;
            } catch (Throwable $e) {
                $failed++;
                $resourceId = AcumaticaClient::val($raw['OrderNbr'] ?? null) ?? 'unknown';

                AcumaticaDeadLetter::updateOrCreate(
                    ['resource_type' => 'fill_rate', 'resource_id' => $resourceId],
                    [
                        'sync_run_id'   => $run->id,
                        'attempt_count' => 1,
                        'last_error'    => $e->getMessage(),
                        'raw_payload'   => $raw,
                    ],
                );
            }
        }

        $run->update([
            'ended_at'      => now(),
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
        ]);

        return $run;
    }

    private function upsertFillRate(array $raw, int $runId): void
    {
        $orderNbr = $this->str($raw['OrderNbr'] ?? null);
        if (! $orderNbr) {
            throw new \InvalidArgumentException('Order missing OrderNbr');
        }

        $status     = $this->str($raw['Status'] ?? null) ?? '';
        $customerId = $this->str($raw['CustomerID'] ?? null);
        $currencyId = $this->str($raw['CurrencyID'] ?? $raw['CuryID'] ?? null);

        $linesRaw = $raw['DocumentDetails'] ?? $raw['Details'] ?? [];
        if (! is_array($linesRaw)) {
            $linesRaw = [];
        }

        $linePayloads = [];
        foreach ($linesRaw as $lineRaw) {
            if (! is_array($lineRaw)) {
                continue;
            }

            $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);
            $linePayloads[] = [
                'inventory_id'    => $mapped['inventory_id'],
                'order_qty'       => $mapped['order_qty'],
                'qty_at_approval' => $mapped['qty_at_approval'],
                'shipped_qty'     => $mapped['shipped_qty'],
                'unit_price'      => $mapped['unit_price'],
            ];
        }

        $computed = $this->calculator->compute($status, $linePayloads);

        $localOrder = AcumaticaSalesOrder::where('acumatica_order_nbr', $orderNbr)->first();

        if ($localOrder) {
            $this->syncOrderLines($localOrder, $linesRaw);
        }

        AcumaticaFillRateSnapshot::updateOrCreate(
            ['order_nbr' => $orderNbr],
            [
                'sales_order_id'      => $localOrder?->id,
                'customer_acumatica_id' => $customerId,
                'status'              => $status,
                'total_ordered_qty'   => $computed['total_ordered_qty'],
                'total_shipped_qty'   => $computed['total_shipped_qty'],
                'fill_rate_pct'       => $computed['fill_rate_pct'],
                'fill_rate_status'    => $computed['fill_rate_status'],
                'revenue_not_shipped' => $computed['revenue_not_shipped'],
                'currency_id'         => $currencyId,
                'sync_run_id'         => $runId,
                'computed_at'         => now(),
            ],
        );
    }

    /** @param  list<array<string, mixed>>  $linesRaw */
    private function syncOrderLines(AcumaticaSalesOrder $order, array $linesRaw): void
    {
        foreach ($linesRaw as $lineRaw) {
            if (! is_array($lineRaw)) {
                continue;
            }

            $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);
            if (! $mapped['inventory_id']) {
                continue;
            }

            AcumaticaSalesOrderLine::updateOrCreate(
                [
                    'sales_order_id' => $order->id,
                    'inventory_id'   => $mapped['inventory_id'],
                ],
                [
                    'line_nbr'           => $mapped['line_nbr'],
                    'description'        => $mapped['description'],
                    'order_qty'          => $mapped['order_qty'],
                    'shipped_qty'        => $mapped['shipped_qty'],
                    'open_qty'           => $mapped['open_qty'],
                    'cancelled_qty'      => $mapped['cancelled_qty'],
                    'qty_at_approval'    => $mapped['qty_at_approval'],
                    'backorder_qty'      => $mapped['backorder_qty'],
                    'fill_rate_pct'      => $mapped['fill_rate_pct'],
                    'line_type'          => $mapped['line_type'],
                    'completed'          => $mapped['completed'],
                    'fulfillment_status' => $mapped['fulfillment_status'],
                    'unit_price'         => $mapped['unit_price'],
                    'warehouse_id'       => $mapped['warehouse_id'],
                    'uom'                => $mapped['uom'],
                ],
            );
        }
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