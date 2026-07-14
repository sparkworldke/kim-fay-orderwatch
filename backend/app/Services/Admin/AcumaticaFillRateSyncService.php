<?php

namespace App\Services\Admin;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\Concerns\InteractsWithAcumaticaSyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Syncs Acumatica sales order data into the OrderWatch fill-rate module (Tally).
 * Partial-delivery reasons are parsed from SO payloads and line fulfillment data.
 */
class AcumaticaFillRateSyncService
{
    use InteractsWithAcumaticaSyncRun;

    /** @var array<string, int|list<string>> */
    private array $guardrailSummary = [
        'orders_computed'              => 0,
        'orders_computed_na'           => 0,
        'lines_out_of_stock'           => 0,
        'lines_partial_shortage'       => 0,
        'lines_shipped_qty_fallback'   => 0,
        'lines_with_acumatica_reason'  => 0,
        'lines_with_reason_notes'      => 0,
        'lines_missing_price'          => 0,
        'orders_incomplete_delivery'   => 0,
    ];

    public function __construct(
        private readonly AcumaticaClient $client,
        private readonly FillRateCalculator $calculator,
    ) {
    }

    public function syncDateRange(string $dateFrom, string $dateTo, ?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['fill_rate'],
            'A fill-rate sync is already running. Wait for it to finish or stop it first.',
        );

        $run = $this->createSyncRun([
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
            $orders = $this->client->fetchOrdersForFillRate($dateFrom, $dateTo, fn () => $this->touchSyncRun($run));
            $run    = $this->processOrders($orders, $run);
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'heartbeat_at'  => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    private function processOrders(array $orders, AcumaticaSyncLog $run): AcumaticaSyncLog
    {
        $this->guardrailSummary = [
            'orders_computed'              => 0,
            'orders_computed_na'           => 0,
            'lines_out_of_stock'           => 0,
            'lines_partial_shortage'       => 0,
            'lines_shipped_qty_fallback'   => 0,
            'lines_with_acumatica_reason'  => 0,
            'lines_with_reason_notes'      => 0,
            'lines_missing_price'          => 0,
            'orders_incomplete_delivery'   => 0,
        ];

        $total   = count($orders);
        $success = 0;
        $failed  = 0;

        foreach ($orders as $raw) {
            $this->touchSyncRun($run);

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
            'heartbeat_at'  => now(),
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
            'filters'       => array_merge($run->filters ?? [], $this->guardrailSummary),
        ]);

        $this->logSyncSummary($run, $success, $failed);

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
            $this->recordLineGuardrails($mapped);
            $linePayloads[] = [
                'inventory_id'      => $mapped['inventory_id'],
                'order_qty'         => $mapped['order_qty'],
                'shipped_qty'       => $mapped['shipped_qty'],
                'qty_on_shipments'  => $mapped['qty_on_shipments'],
                'qty_at_approval'   => $mapped['qty_at_approval'],
                'unit_price'        => $mapped['unit_price'],
                'unfilled_reason_code' => $mapped['unfilled_reason_code'],
            ];
        }

        $computed = $this->calculator->compute($status, $linePayloads);
        if ($computed['fill_rate_status'] === 'na') {
            $this->guardrailSummary['orders_computed_na']++;
        } else {
            $this->guardrailSummary['orders_computed']++;

            if (($computed['fill_rate_pct'] ?? 0) < 100.0) {
                $this->guardrailSummary['orders_incomplete_delivery']++;
            }
        }

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
                'revenue_not_shipped'     => $computed['revenue_not_shipped'],
                'out_of_stock_line_count' => $computed['out_of_stock_line_count'],
                'currency_id'             => $currencyId,
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
                    'shipped_qty'          => $mapped['shipped_qty'],
                    'qty_on_shipments'     => $mapped['qty_on_shipments'],
                    'open_qty'             => $mapped['open_qty'],
                    'cancelled_qty'        => $mapped['cancelled_qty'],
                    'qty_at_approval'      => $mapped['qty_at_approval'],
                    'backorder_qty'        => $mapped['backorder_qty'],
                    'fill_rate_pct'        => $mapped['fill_rate_pct'],
                    'unfilled_reason_code' => $mapped['unfilled_reason_code'],
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

    /** @param array<string, mixed> $mapped */
    private function recordLineGuardrails(array $mapped): void
    {
        if (($mapped['qty_on_shipments_source'] ?? '') === 'shipped_qty_fallback') {
            $this->guardrailSummary['lines_shipped_qty_fallback']++;
        }

        if (($mapped['reason_code'] ?? null) !== null && trim((string) $mapped['reason_code']) !== '') {
            $this->guardrailSummary['lines_with_acumatica_reason']++;
        }

        $reasonNotes = trim((string) ($mapped['reason_notes'] ?? ''));
        if ($reasonNotes !== '') {
            $this->guardrailSummary['lines_with_reason_notes']++;
        }

        if (($mapped['unit_price'] ?? 0) <= 0) {
            $this->guardrailSummary['lines_missing_price']++;
        }

        $reason = $mapped['unfilled_reason_code'] ?? null;
        if ($reason === SalesOrderLineFulfillmentDeriver::UNFILLED_REASON_OUT_OF_STOCK
            && ($mapped['qty_on_shipments'] ?? 0) <= 0) {
            $this->guardrailSummary['lines_out_of_stock']++;
        } elseif ($reason === SalesOrderLineFulfillmentDeriver::UNFILLED_REASON_PARTIAL_SHORTAGE) {
            $this->guardrailSummary['lines_partial_shortage']++;
        }
    }

    /**
     * Write a structured summary log entry for the completed sync run.
     *
     * @param  AcumaticaSyncLog  $run
     * @param  int  $success
     * @param  int  $failed
     * @return void
     */
    private function logSyncSummary(AcumaticaSyncLog $run, int $success, int $failed): void
    {
        $filters = $run->filters ?? [];

        Log::info('Fill-rate sync completed', [
            'sync_run_id'          => $run->id,
            'status'               => $run->status,
            'trigger_type'         => $run->trigger_type,
            'date_from'            => $filters['date_from'] ?? null,
            'date_to'              => $filters['date_to'] ?? null,
            'total_orders'         => $run->record_count,
            'success_count'        => $success,
            'failed_count'         => $failed,
            'orders_computed'      => $this->guardrailSummary['orders_computed'],
            'orders_computed_na'   => $this->guardrailSummary['orders_computed_na'],
            'lines_out_of_stock'   => $this->guardrailSummary['lines_out_of_stock'],
            'lines_partial_shortage' => $this->guardrailSummary['lines_partial_shortage'],
            'lines_shipped_qty_fallback' => $this->guardrailSummary['lines_shipped_qty_fallback'],
            'lines_with_acumatica_reason' => $this->guardrailSummary['lines_with_acumatica_reason'],
            'lines_with_reason_notes'    => $this->guardrailSummary['lines_with_reason_notes'],
            'lines_missing_price'        => $this->guardrailSummary['lines_missing_price'],
            'orders_incomplete_delivery' => $this->guardrailSummary['orders_incomplete_delivery'],
        ]);
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
