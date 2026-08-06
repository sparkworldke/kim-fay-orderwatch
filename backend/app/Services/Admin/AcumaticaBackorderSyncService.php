<?php

namespace App\Services\Admin;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaSyncLog;
use App\Models\BackorderResolution;
use App\Services\Admin\Concerns\InteractsWithAcumaticaSyncRun;
use App\Services\Cache\DomainCache;
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
        private readonly ?CompletedOrderInvoiceReconciliationService $completedReconciliation = null,
    ) {
    }

    /**
     * Create a running sync log without calling Acumatica yet.
     * Use with execute() for HTTP (defer after response) to avoid gateway 504s.
     */
    public function begin(
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
        ?int $cronRunLogId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
    ): AcumaticaSyncLog {
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
                'async' => $triggerType === 'manual',
            ]),
        ]);

        StructuredLogger::write('info', 'acumatica', 'backorder_sync_started', [
            'sync_run_id' => $run->id,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return $run;
    }

    /**
     * Perform the Acumatica pull + upsert into an existing running log.
     *
     * Options (date-range only):
     *   - active_open_fetch (bool, default false): pull only non-terminal open SOs
     *     via openSalesOrdersForBackorders filter (much faster for dashboard refresh).
     *   - skip_completed_recon (bool, default false): skip invoice-based completed
     *     shortfall reconstruction (pair with active_open_fetch for CLI day jobs).
     *   - on_progress (callable|null): optional heartbeat (e.g. CLI output).
     *
     * @param  array{active_open_fetch?: bool, skip_completed_recon?: bool, on_progress?: ?callable}  $options
     */
    public function execute(AcumaticaSyncLog $run, ?string $dateFrom = null, ?string $dateTo = null, array $options = []): AcumaticaSyncLog
    {
        if ($dateFrom === null && is_array($run->filters)) {
            $dateFrom = isset($run->filters['date_from']) ? (string) $run->filters['date_from'] : null;
        }
        if ($dateTo === null && is_array($run->filters)) {
            $dateTo = isset($run->filters['date_to']) ? (string) $run->filters['date_to'] : null;
        }

        // Long Acumatica date ranges easily exceed default PHP / proxy limits.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $activeOpenFetch = (bool) ($options['active_open_fetch'] ?? false);
        $skipCompletedRecon = (bool) ($options['skip_completed_recon'] ?? false);
        $onProgress = $options['on_progress'] ?? null;
        $progress = function (string $message) use ($run, $onProgress): void {
            $this->touchSyncRun($run);
            if (is_callable($onProgress)) {
                $onProgress($message);
            }
        };

        try {
            $isDateLimited = $dateFrom && $dateTo;
            if ($isDateLimited) {
                $progress("Fetching sales orders {$dateFrom} → {$dateTo} (active_open_fetch="
                    .($activeOpenFetch ? 'yes' : 'no').')…');

                if ($activeOpenFetch || $skipCompletedRecon) {
                    // Fast path: only open (non-terminal) SOs — enough for dashboard
                    // active backorder value (open_qty × unit_price).
                    $openOrders = $this->client->fetchAllOpenSalesOrdersForBackordersByDateRange(
                        $dateFrom,
                        $dateTo,
                        function () use ($progress): void {
                            $progress('Still fetching open sales orders from Acumatica…');
                        },
                    );
                    $progress('Fetched '.count($openOrders).' open order(s); upserting active backorder lines…');
                    $run = $this->processOrders($openOrders, $run, false);

                    $activeKeys = [];
                    foreach ($openOrders as $raw) {
                        if (is_array($raw)) {
                            $activeKeys = array_merge($activeKeys, $this->collectBackorderKeysFromPayload($raw));
                        }
                    }
                    $progress('Pruning stale active lines for orders dated in window…');
                    $this->pruneStaleActiveBackordersInDateRange(
                        $dateFrom,
                        $dateTo,
                        $activeKeys,
                        $run->id,
                    );

                    $run->update([
                        'filters' => array_merge($run->filters ?? [], [
                            'date_from' => $dateFrom,
                            'date_to' => $dateTo,
                            'mode' => 'active_open_fetch',
                            'orders_in_range' => count($openOrders),
                            'open_orders_for_active_backorders' => count($openOrders),
                            'active_backorder_keys' => count(array_unique($activeKeys)),
                            'completed_invoice_reconciliation' => $skipCompletedRecon
                                ? ['skipped' => true]
                                : null,
                            'value_formula' => 'open_qty × unit_price',
                        ]),
                    ]);

                    if (! $skipCompletedRecon) {
                        $progress('Fetching all SOs in range for completed-order invoice reconciliation…');
                        $allOrders = $this->client->fetchAllSalesOrdersByDateRange(
                            $dateFrom,
                            $dateTo,
                            function () use ($progress): void {
                                $progress('Still fetching all sales orders for completed recon…');
                            },
                        );
                        $invoiceStats = $this->completedReconciliation()->reconcile(
                            $allOrders,
                            $run->id,
                            fn () => $this->touchSyncRun($run),
                        );
                        $run->update([
                            'filters' => array_merge($run->filters ?? [], [
                                'orders_in_range' => count($allOrders),
                                'completed_invoice_reconciliation' => $invoiceStats,
                            ]),
                            'success_count' => (int) $run->success_count + $invoiceStats['shortfall_lines'],
                            'error_message' => $invoiceStats['unavailable']
                                ? 'Active backorders synced; completed-order invoice reconciliation is unavailable: '.$invoiceStats['error']
                                : $run->error_message,
                        ]);
                    }
                } else {
                    // Full path: one pull of all statuses (active upsert + completed recon).
                    $orders = $this->client->fetchAllSalesOrdersByDateRange(
                        $dateFrom,
                        $dateTo,
                        function () use ($progress): void {
                            $progress('Still fetching sales orders from Acumatica…');
                        },
                    );
                    $openOrders = array_values(array_filter(
                        $orders,
                        fn ($raw) => is_array($raw) && $this->isOpenOrderForActiveBackorders($raw),
                    ));
                    $progress('Fetched '.count($orders).' order(s) ('.count($openOrders).' open); upserting…');
                    $run = $this->processOrders($openOrders, $run, false);

                    $orderNbrsInRange = [];
                    $activeKeys = [];
                    foreach ($orders as $raw) {
                        if (! is_array($raw)) {
                            continue;
                        }
                        $nbr = $this->str($raw['OrderNbr'] ?? null);
                        if ($nbr) {
                            $orderNbrsInRange[] = $nbr;
                        }
                    }
                    foreach ($openOrders as $raw) {
                        $activeKeys = array_merge($activeKeys, $this->collectBackorderKeysFromPayload($raw));
                    }
                    $this->pruneStaleLinesForOrders($orderNbrsInRange, $activeKeys, $run->id);

                    $invoiceStats = $this->completedReconciliation()->reconcile(
                        $orders,
                        $run->id,
                        fn () => $this->touchSyncRun($run),
                    );
                    $run->update([
                        'filters' => array_merge($run->filters ?? [], [
                            'date_from' => $dateFrom,
                            'date_to' => $dateTo,
                            'mode' => 'full_range_with_completed_recon',
                            'orders_in_range' => count($orders),
                            'open_orders_for_active_backorders' => count($openOrders),
                            'active_backorder_keys' => count(array_unique($activeKeys)),
                            'completed_invoice_reconciliation' => $invoiceStats,
                            'value_formula' => 'open_qty × unit_price',
                        ]),
                        'success_count' => (int) $run->success_count + $invoiceStats['shortfall_lines'],
                        'error_message' => $invoiceStats['unavailable']
                            ? 'Active backorders synced; completed-order invoice reconciliation is unavailable: '.$invoiceStats['error']
                            : $run->error_message,
                    ]);
                }
            } else {
                $progress('Fetching all open sales orders (no date filter)…');
                $orders = $this->client->fetchAllOpenSalesOrdersForBackorders(
                    function () use ($progress): void {
                        $progress('Still fetching open sales orders…');
                    },
                );
                $run = $this->processOrders($orders, $run, true);
            }
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

        return $run->fresh() ?? $run;
    }

    /**
     * @param  array{active_open_fetch?: bool, skip_completed_recon?: bool, on_progress?: ?callable}  $options
     */
    public function run(
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
        ?int $cronRunLogId = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        array $options = [],
    ): AcumaticaSyncLog {
        $run = $this->begin($triggeredByUserId, $triggerType, $cronRunLogId, $dateFrom, $dateTo);

        return $this->execute($run, $dateFrom, $dateTo, $options);
    }

    /**
     * Re-import backorder lines for specific OrderNbrs only (no global prune).
     * Also clears stale backorder rows for those orders when they no longer qualify.
     *
     * @param  list<string>  $orderNbrs
     */
    public function runForOrderNumbers(
        array $orderNbrs,
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
    ): AcumaticaSyncLog {
        $orderNbrs = array_values(array_unique(array_filter(array_map(
            static fn ($n) => is_string($n) ? strtoupper(trim($n)) : '',
            $orderNbrs,
        ), static fn (string $n) => $n !== '')));

        $this->assertNoActiveSync(
            ['backorders'],
            'A backorder sync is already running. Wait for it to finish or stop it first.',
        );

        $run = $this->createSyncRun([
            'sync_type'            => 'backorders',
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => ['order_nbrs' => $orderNbrs],
        ]);

        StructuredLogger::write('info', 'acumatica', 'backorder_sync_by_numbers_started', [
            'sync_run_id' => $run->id,
            'order_nbrs'  => $orderNbrs,
        ]);

        try {
            if ($orderNbrs === []) {
                $run->update([
                    'ended_at'      => now(),
                    'heartbeat_at'  => now(),
                    'status'        => 'failed',
                    'error_message' => 'No order numbers provided.',
                ]);

                return $run->fresh();
            }

            $orders = $this->client->fetchSalesOrdersByNumbers($orderNbrs, fn () => $this->touchSyncRun($run));
            $run    = $this->processOrders($orders, $run, false);
            $invoiceStats = $this->completedReconciliation()->reconcile(
                $orders,
                $run->id,
                fn () => $this->touchSyncRun($run),
            );
            $run->update([
                'filters' => array_merge($run->filters ?? [], ['completed_invoice_reconciliation' => $invoiceStats]),
                'success_count' => (int) $run->success_count + $invoiceStats['shortfall_lines'],
            ]);

            // Drop local backorder lines for these SOs that no longer meet backorder criteria.
            $syncedKeys = [];
            foreach ($orders as $raw) {
                if (! is_array($raw)) {
                    continue;
                }
                $syncedKeys = array_merge($syncedKeys, $this->collectBackorderKeysFromPayload($raw));
            }
            $this->pruneStaleLinesForOrders($orderNbrs, $syncedKeys, $run->id);
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
                'heartbeat_at'  => now(),
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'backorder_sync_by_numbers_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * Active backorder upserts only run for non-terminal SO headers.
     * Matches AcumaticaClient::openSalesOrdersForBackordersFilter exclusions.
     */
    private function isOpenOrderForActiveBackorders(array $raw): bool
    {
        $status = strtolower(trim((string) ($this->str($raw['Status'] ?? $raw['OrderStatus'] ?? null) ?? '')));

        return ! in_array($status, ['completed', 'cancelled', 'canceled', 'rejected'], true);
    }

    /** @return list<string> */
    private function collectBackorderKeysFromPayload(array $raw): array
    {
        $orderNbr = $this->str($raw['OrderNbr'] ?? null);
        if (! $orderNbr) {
            return [];
        }

        if (! $this->isOpenOrderForActiveBackorders($raw)) {
            return [];
        }

        $lines = $raw['DocumentDetails'] ?? $raw['Details'] ?? [];
        if (! is_array($lines)) {
            return [];
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
            $keys[] = "{$orderNbr}|{$inventoryId}";
        }

        return $keys;
    }

    /**
     * @param  list<string>  $orderNbrs
     * @param  list<string>  $activeKeys  order_nbr|inventory_id still backordered
     */
    private function pruneStaleLinesForOrders(array $orderNbrs, array $activeKeys, ?int $runId = null): void
    {
        if ($orderNbrs === []) {
            return;
        }

        $active = collect($activeKeys)->flip();

        AcumaticaBackorderLine::query()
            ->whereIn('order_nbr', $orderNbrs)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($active, $runId) {
                foreach ($rows as $row) {
                    $key = "{$row->order_nbr}|{$row->inventory_id}";
                    if (! $active->has($key)) {
                        $this->archiveResolvedLine($row, $runId);
                        $row->delete();
                    }
                }
            });
    }

    /**
     * Drop active backorder rows for SOs dated in the window that are no longer
     * in the live open-keys set (used by the fast active_open_fetch path).
     *
     * @param  list<string>  $activeKeys
     */
    private function pruneStaleActiveBackordersInDateRange(
        string $dateFrom,
        string $dateTo,
        array $activeKeys,
        ?int $runId = null,
    ): void {
        $active = collect($activeKeys)->flip();

        AcumaticaBackorderLine::query()
            ->where(function ($q) {
                $q->where('shortfall_kind', 'active_backorder')
                    ->orWhereNull('shortfall_kind');
            })
            ->whereIn('order_nbr', function ($q) use ($dateFrom, $dateTo) {
                $q->select('acumatica_order_nbr')
                    ->from('acumatica_sales_orders')
                    ->whereDate('order_date', '>=', $dateFrom)
                    ->whereDate('order_date', '<=', $dateTo);
            })
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($active, $runId) {
                foreach ($rows as $row) {
                    $key = "{$row->order_nbr}|{$row->inventory_id}";
                    if (! $active->has($key)) {
                        $this->archiveResolvedLine($row, $runId);
                        $row->delete();
                    }
                }
            });
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
            $this->pruneStaleLines($seenKeys, $run->id);
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

        if ($run->status === 'completed') {
            app(DomainCache::class)->bump(
                DomainCache::BACKORDERS,
                DomainCache::BUSINESS_OPTIMIZATION,
                DomainCache::SALES_PORTFOLIO,
                DomainCache::SALES_INTELLIGENCE,
            );
        }

        return $run;
    }

    /** @return list<string> composite keys synced */
    private function upsertBackorderLines(array $raw, int $runId): array
    {
        $orderNbr = $this->str($raw['OrderNbr'] ?? null);
        if (! $orderNbr) {
            throw new \InvalidArgumentException('Backorder missing OrderNbr');
        }

        // Terminal headers never create active_backorder rows (completed shortfalls
        // come from invoice reconciliation with shortfall_kind=completed_shortfall).
        if (! $this->isOpenOrderForActiveBackorders($raw)) {
            return [];
        }

        $customerId   = $this->str($raw['CustomerID'] ?? null);
        $customerName = $this->str($raw['CustomerName'] ?? null);
        $currencyId   = $this->str($raw['CurrencyID'] ?? $raw['CuryID'] ?? null);
        $orderStatus  = $this->str($raw['Status'] ?? $raw['OrderStatus'] ?? null);
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

            // Revenue at risk = missing/open qty × unit price (never order total / ExtPrice).
            $openQty = $mapped['open_qty'] > 0
                ? $mapped['open_qty']
                : ($mapped['backorder_qty'] > 0 ? $mapped['backorder_qty'] : 0.0);
            $unitPrice = $mapped['unit_price'];
            $revenueAtRisk = SalesOrderLineFulfillmentDeriver::openLineValue($openQty, $unitPrice);
            $this->recordReasonValidation($orderNbr, $inventoryId, $mapped);

            $identity = ['order_nbr' => $orderNbr, 'inventory_id' => $inventoryId];
            $existing = AcumaticaBackorderLine::query()->where($identity)->first();
            $syncedAt = now();
            $extra = [
                    'customer_acumatica_id'   => $customerId,
                    'customer_name'           => $customerName,
                    'currency_id'             => $currencyId,
                    'order_status'            => $orderStatus,
                    'scheduled_shipment_date' => $scheduled,
                    'requested_on'            => $requestedOn,
                    'sync_run_id'             => $runId,
                    'synced_at'               => $syncedAt,
                ];

            if (Schema::hasColumn('acumatica_backorder_lines', 'first_backordered_at')) {
                $extra['first_backordered_at'] = $existing?->first_backordered_at ?? $syncedAt;
                $extra['first_backordered_at_is_backfilled'] = $existing?->first_backordered_at !== null
                    ? (bool) $existing->first_backordered_at_is_backfilled
                    : false;
            }

            AcumaticaBackorderLine::updateOrCreate(
                $identity,
                $this->backorderLineAttributes($mapped, $openQty, $unitPrice, $revenueAtRisk, $extra),
            );

            $keys[] = "{$orderNbr}|{$inventoryId}";
        }

        return $keys;
    }

    /** @param  list<string>  $activeKeys */
    private function pruneStaleLines(array $activeKeys, ?int $runId = null): void
    {
        $active = collect($activeKeys)->flip();

        AcumaticaBackorderLine::query()
            ->where('shortfall_kind', 'active_backorder')
            ->chunkById(200, function ($rows) use ($active, $runId) {
            foreach ($rows as $row) {
                $key = "{$row->order_nbr}|{$row->inventory_id}";
                if (! $active->has($key)) {
                    $this->archiveResolvedLine($row, $runId);
                    $row->delete();
                }
            }
            });
    }

    /**
     * Archive a line that no longer meets backorder criteria before it's deleted, so
     * "when did this clear" and "how long was it open" survive the prune. Without this,
     * resolution history is lost the moment a line ships or its order completes.
     */
    private function archiveResolvedLine(AcumaticaBackorderLine $row, ?int $runId): void
    {
        $resolvedAt = now();
        $firstBackorderedAt = $row->first_backordered_at;
        $daysToResolve = $firstBackorderedAt !== null
            ? max(0, (int) $firstBackorderedAt->copy()->startOfDay()->diffInDays($resolvedAt->copy()->startOfDay()))
            : null;

        BackorderResolution::create([
            'order_nbr' => $row->order_nbr,
            'inventory_id' => $row->inventory_id,
            'customer_acumatica_id' => $row->customer_acumatica_id,
            'customer_name' => $row->customer_name,
            'warehouse_id' => $row->warehouse_id,
            'uom' => $row->uom,
            'currency_id' => $row->currency_id,
            'reason_code' => $row->reason_code,
            'reason_notes' => $row->reason_notes,
            'unit_price' => $row->unit_price,
            'revenue_at_risk' => $row->revenue_at_risk,
            'order_qty' => $row->order_qty,
            'last_open_qty' => $row->open_qty,
            'last_backorder_qty' => $row->backorder_qty,
            'last_fulfillment_status' => $row->fulfillment_status,
            'first_backordered_at' => $firstBackorderedAt,
            'first_backordered_at_is_backfilled' => (bool) $row->first_backordered_at_is_backfilled,
            'resolved_at' => $resolvedAt,
            'days_to_resolve' => $daysToResolve,
            'sync_run_id' => $runId,
        ]);
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
            'invoiced_qty' => null,
            'shortfall_kind' => 'active_backorder',
            // Prefer status passed from SO header ($extra); keep any non-empty value.
            'order_status' => $extra['order_status'] ?? null,
            'invoice_reconciliation_status' => null,
            'fulfillment_source' => 'sales_order_open_qty',
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

    private function completedReconciliation(): CompletedOrderInvoiceReconciliationService
    {
        return $this->completedReconciliation
            ?? new CompletedOrderInvoiceReconciliationService($this->client);
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
