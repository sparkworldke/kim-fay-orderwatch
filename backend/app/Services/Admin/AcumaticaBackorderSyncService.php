<?php

namespace App\Services\Admin;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\Concerns\InteractsWithAcumaticaSyncRun;
use App\Services\Operations\SalesOrderReasonCatalog;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AcumaticaBackorderSyncService
{
    use InteractsWithAcumaticaSyncRun;

    /** @var array{reason_codes_imported:int,reason_notes_imported:int,missing_reason_codes:int,invalid_reason_codes:int,sample_missing_order_nbrs:list<string>} */
    private array $reasonValidationSummary = [
        'reason_codes_imported' => 0,
        'reason_notes_imported' => 0,
        'missing_reason_codes' => 0,
        'invalid_reason_codes' => 0,
        'sample_missing_order_nbrs' => [],
    ];

    public function __construct(
        private readonly AcumaticaClient $client,
        private readonly SalesOrderReasonCatalog $reasonCatalog,
    ) {
    }

    public function run(
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
        ?int $cronRunLogId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['backorders'],
            'A backorder sync is already running. Wait for it to finish or stop it first.',
        );

        $run = $this->createSyncRun([
            'sync_type'            => 'backorders',
            'cron_run_log_id'      => $cronRunLogId,
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => array_filter([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]),
        ]);

        StructuredLogger::write('info', 'acumatica', 'backorder_sync_started', [
            'sync_run_id' => $run->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        try {
            $isDateLimited = $dateFrom && $dateTo;
            $orders = $isDateLimited
                ? $this->client->fetchAllOpenSalesOrdersForBackordersByDateRange($dateFrom, $dateTo, fn () => $this->touchSyncRun($run))
                : $this->client->fetchAllOpenSalesOrdersForBackorders(fn () => $this->touchSyncRun($run));
            $run    = $this->processOrders($orders, $run, ! $isDateLimited);
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'heartbeat_at'  => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'backorder_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    private function processOrders(array $orders, AcumaticaSyncLog $run, bool $pruneStaleLines = true): AcumaticaSyncLog
    {
        $this->reasonValidationSummary = [
            'reason_codes_imported' => 0,
            'reason_notes_imported' => 0,
            'missing_reason_codes' => 0,
            'invalid_reason_codes' => 0,
            'sample_missing_order_nbrs' => [],
        ];
        $seenKeys    = [];
        $success     = 0;
        $failed      = 0;
        $linesSynced = 0;

        foreach ($orders as $raw) {
            $this->touchSyncRun($run);

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

        if ($pruneStaleLines && ! empty($seenKeys)) {
            $this->pruneStaleLines($seenKeys);
        }

        $run->update([
            'ended_at'      => now(),
            'heartbeat_at'  => now(),
            'status'        => $failed > 0 && $linesSynced === 0 ? 'failed' : 'completed',
            'record_count'  => count($orders),
            'success_count' => $linesSynced > 0 ? $linesSynced : $success,
            'failed_count'  => $failed,
            'filters'       => array_merge($run->filters ?? [], $this->reasonValidationSummary),
            'error_message' => $linesSynced === 0 && $failed === 0 && count($orders) > 0
                ? 'Fetched '.count($orders).' open orders but no lines matched backorder criteria (check OpenQty in Acumatica Details).'
                : null,
        ]);

        StructuredLogger::write('info', 'acumatica', 'backorder_sync_completed', [
            'sync_run_id' => $run->id,
            'total' => count($orders),
            'success' => $success,
            'failed' => $failed,
            'reason_validation' => $this->reasonValidationSummary,
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
            $this->recordReasonValidation($orderNbr, $inventoryId, $mapped);

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
            'shipped_qty'      => $mapped['shipped_qty'],
            'qty_on_shipments' => $mapped['qty_on_shipments'],
            'open_qty'         => $openQty,
            'unit_price'      => $unitPrice,
            'revenue_at_risk' => $revenueAtRisk,
            'warehouse_id'    => $mapped['warehouse_id'],
            'reason_code'     => $this->normalizeStoredReasonCode($mapped['reason_code']),
            'reason_notes'    => $mapped['reason_notes'],
        ]);

        if (Schema::hasColumn('acumatica_backorder_lines', 'uom')) {
            $attrs['uom'] = $mapped['uom'];
        }

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

    /** @param array<string, mixed> $mapped */
    private function recordReasonValidation(string $orderNbr, string $inventoryId, array $mapped): void
    {
        $reasonCode = is_string($mapped['reason_code'] ?? null) ? trim((string) $mapped['reason_code']) : null;
        $reasonNotes = is_string($mapped['reason_notes'] ?? null) ? trim((string) $mapped['reason_notes']) : null;

        if ($reasonCode !== null && $reasonCode !== '') {
            $this->reasonValidationSummary['reason_codes_imported']++;
        }

        if ($reasonNotes !== null && $reasonNotes !== '') {
            $this->reasonValidationSummary['reason_notes_imported']++;
        }

        if ($reasonCode === null || $reasonCode === '') {
            $this->reasonValidationSummary['missing_reason_codes']++;
            $sampleKey = "{$orderNbr}|{$inventoryId}";
            if (count($this->reasonValidationSummary['sample_missing_order_nbrs']) < 10
                && ! in_array($sampleKey, $this->reasonValidationSummary['sample_missing_order_nbrs'], true)) {
                $this->reasonValidationSummary['sample_missing_order_nbrs'][] = $sampleKey;
            }

            StructuredLogger::write('warning', 'acumatica', 'backorder_reason_code_missing', [
                'order_nbr' => $orderNbr,
                'inventory_id' => $inventoryId,
            ]);

            return;
        }

        if (strlen($reasonCode) > 80) {
            $this->reasonValidationSummary['invalid_reason_codes']++;
            throw new \RuntimeException("Backorder reason code exceeds 80 characters for {$orderNbr}/{$inventoryId}");
        }

        $classified = $this->reasonCatalog->classify(SalesOrderReasonCatalog::PARENT_BACKORDER, $reasonCode);
        if ($classified['issue'] === SalesOrderReasonCatalog::ISSUE_UNCLASSIFIED) {
            $this->reasonValidationSummary['invalid_reason_codes']++;
            StructuredLogger::write('warning', 'acumatica', 'backorder_reason_code_unclassified', [
                'order_nbr' => $orderNbr,
                'inventory_id' => $inventoryId,
                'reason_code' => $reasonCode,
            ]);
        }
    }

    private function normalizeStoredReasonCode(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        return $this->reasonCatalog->resolveSubReason($raw) ?? trim($raw);
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
