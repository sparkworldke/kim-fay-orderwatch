<?php

namespace App\Services\Admin;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\Concerns\InteractsWithAcumaticaSyncRun;
use App\Services\Operations\SalesOrderReasonCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class AcumaticaSalesOrderSyncService
{
    use InteractsWithAcumaticaSyncRun;

    /** @var array{rejection_reason_codes_imported:int,rejection_reason_notes_imported:int,missing_rejection_reason_codes:int,invalid_rejection_reason_codes:int,sample_missing_rejection_orders:list<string>} */
    private array $reasonValidationSummary = [
        'rejection_reason_codes_imported' => 0,
        'rejection_reason_notes_imported' => 0,
        'missing_rejection_reason_codes' => 0,
        'invalid_rejection_reason_codes' => 0,
        'sample_missing_rejection_orders' => [],
    ];

    public function __construct(
        private readonly AcumaticaClient $client,
        private readonly SalesOrderReasonCatalog $reasonCatalog,
    ) {
    }

    // -------------------------------------------------------------------------
    // Date-range sync
    // -------------------------------------------------------------------------

    public function syncDateRange(string $dateFrom, string $dateTo, ?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['sales_orders', 'customer_orders', 'credit_notes_and_more', 'sales_order_status_updates', 'sales_order_prune_missing'],
            'An order sync is already running. Wait for it to finish or stop it first.',
        );

        $run = $this->createSyncRun([
            'sync_type'            => 'sales_orders',
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

        StructuredLogger::write('info', 'acumatica', 'sales_order_sync_started', [
            'sync_run_id' => $run->id,
            'date_from'   => $dateFrom,
            'date_to'     => $dateTo,
        ]);

        try {
            $orders = $this->client->fetchAllSalesOrdersByDateRange($dateFrom, $dateTo, fn () => $this->touchSyncRun($run));
            $run    = $this->processOrders($orders, $run);
            // After import (manual or scheduled): recheck local SOs in range and delete any
            // no longer returned by Acumatica, then refresh statuses for those that remain.
            $run    = $this->reconcileStatuses(
                $run,
                fn (Builder $query) => $query
                    ->where('order_type', 'SO')
                    ->whereBetween('order_date', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']),
                $this->orderNumbersFromPayload($orders),
                'missing_from_acumatica_sales_order_sync',
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

            StructuredLogger::write('error', 'acumatica', 'sales_order_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * @param  list<string>|null  $documentTypes
     */
    public function syncCreditNotesAndMore(string $dateFrom, string $dateTo, ?int $triggeredByUserId = null, ?array $documentTypes = null): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['sales_orders', 'customer_orders', 'credit_notes_and_more'],
            'An order sync is already running. Wait for it to finish or stop it first.',
        );

        $run = $this->createSyncRun([
            'sync_type'            => 'credit_notes_and_more',
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => 'manual',
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => array_filter([
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'document_types' => $documentTypes,
            ]),
        ]);

        try {
            $orders = $this->client->fetchAllCreditNotesAndMoreByDateRange($dateFrom, $dateTo, fn () => $this->touchSyncRun($run), $documentTypes);
            $run    = $this->processOrders($orders, $run);
            $run    = $this->reconcileStatuses(
                $run,
                fn (Builder $query) => $query
                    ->whereIn('order_type', $documentTypes ?: ['QT', 'RC', 'CM', 'PL'])
                    ->whereBetween('order_date', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']),
                $this->orderNumbersFromPayload($orders),
                'missing_from_acumatica_credit_notes_sync',
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
        }

        return $run->fresh();
    }

    public function syncStatusUpdates(int $lookbackDays = 14, int $maxOrders = 1500, ?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['sales_orders', 'customer_orders', 'credit_notes_and_more', 'sales_order_status_updates', 'sales_order_prune_missing'],
            'A sales order sync is already running. Wait for it to finish or stop it first.',
        );

        $lookbackDays = max(1, min(90, $lookbackDays));
        $maxOrders = max(50, min(5000, $maxOrders));

        $run = $this->createSyncRun([
            'sync_type' => 'sales_order_status_updates',
            'cron_run_log_id' => $cronRunLogId,
            'started_at' => now(),
            'status' => 'running',
            'record_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'trigger_type' => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters' => ['lookback_days' => $lookbackDays, 'max_orders' => $maxOrders],
        ]);

        try {
            $localOrders = AcumaticaSalesOrder::query()
                ->where('order_type', 'SO')
                ->where('order_date', '>=', now()->subDays($lookbackDays))
                ->orderByDesc('order_date')
                ->limit($maxOrders)
                ->get(['id', 'acumatica_order_nbr', 'status']);

            if ($localOrders->isEmpty()) {
                $run->update([
                    'ended_at' => now(),
                    'heartbeat_at' => now(),
                    'status' => 'completed',
                    'record_count' => 0,
                    'success_count' => 0,
                    'failed_count' => 0,
                    'filters' => array_merge($run->filters ?? [], [
                        'status_comparison_count' => 0,
                        'status_updates' => 0,
                    ]),
                ]);

                return $run->fresh();
            }

            $sourceLookups = 0;
            $statusUpdates = 0;
            $deletedCount = 0;
            $deletedSamples = [];
            $comparisonCount = $localOrders->count();

            foreach ($localOrders->chunk(100) as $chunk) {
                $this->touchSyncRun($run);

                $sourceOrders = $this->client->fetchSalesOrdersByNumbers(
                    $chunk->pluck('acumatica_order_nbr')->all(),
                    fn () => $this->touchSyncRun($run),
                );

                $sourceLookups++;
                $sourceByOrderNbr = collect($sourceOrders)
                    ->filter(fn (mixed $raw) => is_array($raw))
                    ->mapWithKeys(function (array $raw) {
                        $orderNbr = $this->str($raw['OrderNbr'] ?? null);
                        return $orderNbr ? [$orderNbr => $raw] : [];
                    });

                foreach ($chunk as $localOrder) {
                    $this->touchSyncRun($run);
                    $raw = $sourceByOrderNbr->get($localOrder->acumatica_order_nbr);
                    if (! is_array($raw)) {
                        // Order no longer exists in Acumatica — remove local copy.
                        $this->purgeLocalSalesOrder($localOrder, $run->id, 'missing_from_acumatica_status_sync');
                        $deletedCount++;
                        if (count($deletedSamples) < 20) {
                            $deletedSamples[] = $localOrder->acumatica_order_nbr;
                        }
                        continue;
                    }

                    $sourceStatus = $this->str($raw['Status'] ?? null);
                    if ($this->normalizeStatus($localOrder->status) === $this->normalizeStatus($sourceStatus)) {
                        continue;
                    }

                    $this->updateOrderStatusOnly($localOrder, $raw, $run->id);
                    $statusUpdates++;
                }
            }

            $run->update([
                'ended_at' => now(),
                'heartbeat_at' => now(),
                'status' => 'completed',
                'record_count' => $comparisonCount,
                'success_count' => max(0, $comparisonCount - $statusUpdates - $deletedCount),
                'failed_count' => 0,
                'filters' => array_merge($run->filters ?? [], $this->reasonValidationSummary, [
                    'status_comparison_count' => $comparisonCount,
                    'status_updates' => $statusUpdates,
                    'orders_deleted_missing_from_acumatica' => $deletedCount,
                    'sample_deleted_orders' => $deletedSamples,
                    'source_lookups' => $sourceLookups,
                ]),
            ]);
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at' => now(),
                'heartbeat_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    /**
     * Dedicated prune pass: verify local SO rows still exist in Acumatica and delete those that do not.
     */
    public function pruneMissingSalesOrders(
        int $lookbackDays = 60,
        int $maxOrders = 3000,
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
        ?int $cronRunLogId = null,
    ): AcumaticaSyncLog {
        $this->assertNoActiveSync(
            ['sales_orders', 'customer_orders', 'credit_notes_and_more', 'sales_order_status_updates', 'sales_order_prune_missing'],
            'A sales order sync is already running. Wait for it to finish or stop it first.',
        );

        $lookbackDays = max(1, min(180, $lookbackDays));
        $maxOrders = max(50, min(10000, $maxOrders));

        $run = $this->createSyncRun([
            'sync_type' => 'sales_order_prune_missing',
            'cron_run_log_id' => $cronRunLogId,
            'started_at' => now(),
            'status' => 'running',
            'record_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'trigger_type' => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters' => ['lookback_days' => $lookbackDays, 'max_orders' => $maxOrders],
        ]);

        try {
            $localOrders = AcumaticaSalesOrder::query()
                ->where('order_type', 'SO')
                ->where('order_date', '>=', now()->subDays($lookbackDays))
                ->orderByDesc('order_date')
                ->limit($maxOrders)
                ->get(['id', 'acumatica_order_nbr', 'status']);

            $checked = $localOrders->count();
            $deletedCount = 0;
            $deletedSamples = [];
            $sourceLookups = 0;

            foreach ($localOrders->chunk(100) as $chunk) {
                $this->touchSyncRun($run);

                $sourceOrders = $this->client->fetchSalesOrdersByNumbers(
                    $chunk->pluck('acumatica_order_nbr')->all(),
                    fn () => $this->touchSyncRun($run),
                );
                $sourceLookups++;

                $sourceByOrderNbr = collect($sourceOrders)
                    ->filter(fn (mixed $raw) => is_array($raw))
                    ->mapWithKeys(function (array $raw) {
                        $orderNbr = $this->str($raw['OrderNbr'] ?? null);

                        return $orderNbr ? [$orderNbr => $raw] : [];
                    });

                foreach ($chunk as $localOrder) {
                    $this->touchSyncRun($run);
                    if ($sourceByOrderNbr->has($localOrder->acumatica_order_nbr)) {
                        continue;
                    }

                    $this->purgeLocalSalesOrder($localOrder, $run->id, 'missing_from_acumatica_prune');
                    $deletedCount++;
                    if (count($deletedSamples) < 30) {
                        $deletedSamples[] = $localOrder->acumatica_order_nbr;
                    }
                }
            }

            $run->update([
                'ended_at' => now(),
                'heartbeat_at' => now(),
                'status' => 'completed',
                'record_count' => $checked,
                'success_count' => $checked - $deletedCount,
                'failed_count' => 0,
                'filters' => array_merge($run->filters ?? [], [
                    'orders_checked' => $checked,
                    'orders_deleted_missing_from_acumatica' => $deletedCount,
                    'sample_deleted_orders' => $deletedSamples,
                    'source_lookups' => $sourceLookups,
                ]),
            ]);

            StructuredLogger::write('info', 'acumatica', 'sales_order_prune_missing_completed', [
                'sync_run_id' => $run->id,
                'checked' => $checked,
                'deleted' => $deletedCount,
                'sample_deleted_orders' => $deletedSamples,
            ]);
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at' => now(),
                'heartbeat_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            StructuredLogger::write('error', 'acumatica', 'sales_order_prune_missing_failed', [
                'sync_run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    public function syncStatusUpdatesForDateRange(
        string $dateFrom,
        string $dateTo,
        int $maxOrders = 5000,
        ?int $triggeredByUserId = null,
        string $triggerType = 'manual',
        ?int $cronRunLogId = null,
    ): AcumaticaSyncLog {
        $this->assertNoActiveSync(
            ['sales_orders', 'customer_orders', 'credit_notes_and_more', 'sales_order_status_updates', 'sales_order_prune_missing'],
            'A sales order sync is already running. Wait for it to finish or stop it first.',
        );

        $maxOrders = max(50, min(5000, $maxOrders));

        $run = $this->createSyncRun([
            'sync_type' => 'sales_order_status_updates',
            'cron_run_log_id' => $cronRunLogId,
            'started_at' => now(),
            'status' => 'running',
            'record_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
            'trigger_type' => $triggerType,
            'triggered_by_user_id' => $triggeredByUserId,
            'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'max_orders' => $maxOrders],
        ]);

        try {
            $localOrders = AcumaticaSalesOrder::query()
                ->where('order_type', 'SO')
                ->whereBetween('order_date', [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'])
                ->orderByDesc('order_date')
                ->limit($maxOrders)
                ->get(['id', 'acumatica_order_nbr', 'status']);

            if ($localOrders->isEmpty()) {
                $run->update([
                    'ended_at' => now(),
                    'heartbeat_at' => now(),
                    'status' => 'completed',
                    'record_count' => 0,
                    'success_count' => 0,
                    'failed_count' => 0,
                    'filters' => array_merge($run->filters ?? [], [
                        'status_comparison_count' => 0,
                        'status_updates' => 0,
                    ]),
                ]);

                return $run->fresh();
            }

            $sourceLookups = 0;
            $statusUpdates = 0;
            $deletedCount = 0;
            $deletedSamples = [];
            $comparisonCount = $localOrders->count();

            foreach ($localOrders->chunk(100) as $chunk) {
                $this->touchSyncRun($run);

                $sourceOrders = $this->client->fetchSalesOrdersByNumbers(
                    $chunk->pluck('acumatica_order_nbr')->all(),
                    fn () => $this->touchSyncRun($run),
                );

                $sourceLookups++;
                $sourceByOrderNbr = collect($sourceOrders)
                    ->filter(fn (mixed $raw) => is_array($raw))
                    ->mapWithKeys(function (array $raw) {
                        $orderNbr = $this->str($raw['OrderNbr'] ?? null);
                        return $orderNbr ? [$orderNbr => $raw] : [];
                    });

                foreach ($chunk as $localOrder) {
                    $this->touchSyncRun($run);
                    $raw = $sourceByOrderNbr->get($localOrder->acumatica_order_nbr);
                    if (! is_array($raw)) {
                        $this->purgeLocalSalesOrder($localOrder, $run->id, 'missing_from_acumatica_status_sync');
                        $deletedCount++;
                        if (count($deletedSamples) < 20) {
                            $deletedSamples[] = $localOrder->acumatica_order_nbr;
                        }
                        continue;
                    }

                    $sourceStatus = $this->str($raw['Status'] ?? null);
                    if ($this->normalizeStatus($localOrder->status) === $this->normalizeStatus($sourceStatus)) {
                        continue;
                    }

                    $this->updateOrderStatusOnly($localOrder, $raw, $run->id);
                    $statusUpdates++;
                }
            }

            $run->update([
                'ended_at' => now(),
                'heartbeat_at' => now(),
                'status' => 'completed',
                'record_count' => $comparisonCount,
                'success_count' => max(0, $comparisonCount - $statusUpdates - $deletedCount),
                'failed_count' => 0,
                'filters' => array_merge($run->filters ?? [], $this->reasonValidationSummary, [
                    'status_comparison_count' => $comparisonCount,
                    'status_updates' => $statusUpdates,
                    'orders_deleted_missing_from_acumatica' => $deletedCount,
                    'sample_deleted_orders' => $deletedSamples,
                    'source_lookups' => $sourceLookups,
                ]),
            ]);
        } catch (AcumaticaSyncStoppedException $e) {
            $run = $this->stopSyncRun($run, $e->getMessage());
        } catch (Throwable $e) {
            $run->update([
                'ended_at' => now(),
                'heartbeat_at' => now(),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    // -------------------------------------------------------------------------
    // Selective customer sync
    // -------------------------------------------------------------------------

    public function syncForCustomers(array $customerAcumaticaIds, ?int $triggeredByUserId = null): AcumaticaSyncLog
    {
        $this->assertNoActiveSync(
            ['sales_orders', 'customer_orders', 'credit_notes_and_more'],
            'An order sync is already running. Wait for it to finish or stop it first.',
        );

        $run = $this->createSyncRun([
            'sync_type'            => 'customer_orders',
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => 'manual',
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => ['customer_ids' => $customerAcumaticaIds],
        ]);

        StructuredLogger::write('info', 'acumatica', 'customer_order_sync_started', [
            'sync_run_id'  => $run->id,
            'customer_ids' => $customerAcumaticaIds,
        ]);

        try {
            $orders = [];
            foreach ($customerAcumaticaIds as $customerId) {
                $this->touchSyncRun($run);
                $customerOrders = $this->client->fetchAllSalesOrdersForCustomer($customerId, fn () => $this->touchSyncRun($run));
                $orders         = array_merge($orders, $customerOrders);
            }

            $run = $this->processOrders($orders, $run);
            // Customer SO pull is SO-only from Acumatica — purge local SO rows for those
            // customers that are no longer present after the recheck.
            $run = $this->reconcileStatuses(
                $run,
                fn (Builder $query) => $query
                    ->where('order_type', 'SO')
                    ->whereIn('customer_acumatica_id', $customerAcumaticaIds),
                $this->orderNumbersFromPayload($orders),
                'missing_from_acumatica_customer_order_sync',
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

            StructuredLogger::write('error', 'acumatica', 'customer_order_sync_failed', [
                'sync_run_id' => $run->id,
                'error'       => $e->getMessage(),
            ]);
        }

        return $run->fresh();
    }

    // -------------------------------------------------------------------------
    // Shared processing
    // -------------------------------------------------------------------------

    private function processOrders(array $orders, AcumaticaSyncLog $run): AcumaticaSyncLog
    {
        $this->reasonValidationSummary = [
            'rejection_reason_codes_imported' => 0,
            'rejection_reason_notes_imported' => 0,
            'missing_rejection_reason_codes' => 0,
            'invalid_rejection_reason_codes' => 0,
            'sample_missing_rejection_orders' => [],
        ];
        $total   = count($orders);
        $success = 0;
        $failed  = 0;

        foreach ($orders as $raw) {
            $this->touchSyncRun($run);

            try {
                $this->upsertOrder($raw, $run->id);
                $success++;
            } catch (Throwable $e) {
                $failed++;
                $resourceId = AcumaticaClient::val($raw['OrderNbr'] ?? null) ?? 'unknown';

                $existing = AcumaticaDeadLetter::where('resource_type', 'sales_order')
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
                        'resource_type' => 'sales_order',
                        'resource_id'   => $resourceId,
                        'attempt_count' => 1,
                        'last_error'    => $e->getMessage(),
                        'raw_payload'   => $raw,
                    ]);
                }

                StructuredLogger::write('error', 'acumatica', 'sales_order_sync_record_failed', [
                    'sync_run_id' => $run->id,
                    'order_nbr'   => $resourceId,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        $run->update([
            'ended_at'      => now(),
            'heartbeat_at'  => now(),
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
            'filters'       => array_merge($run->filters ?? [], $this->reasonValidationSummary),
        ]);

        StructuredLogger::write('info', 'acumatica', 'sales_order_sync_completed', [
            'sync_run_id' => $run->id,
            'total'       => $total,
            'success'     => $success,
            'failed'      => $failed,
            'reason_validation' => $this->reasonValidationSummary,
        ]);

        return $run;
    }

    /**
     * Recheck local orders against Acumatica and:
     * 1) delete local rows that no longer exist (missing from payload and/or number lookup)
     * 2) refresh status for remaining rows
     *
     * Used by automated cron and manual admin sync after every import.
     *
     * @param  list<string>|null  $presentOrderNbrs  Order numbers returned by the current Acumatica fetch
     *                                                (complete set for the sync scope when provided)
     */
    private function reconcileStatuses(
        AcumaticaSyncLog $run,
        callable $scope,
        ?array $presentOrderNbrs = null,
        string $deleteReason = 'missing_from_acumatica_reconcile',
    ): AcumaticaSyncLog {
        $localOrders = AcumaticaSalesOrder::query()
            ->tap($scope)
            ->get(['id', 'acumatica_order_nbr', 'status']);

        if ($localOrders->isEmpty()) {
            $run->update([
                'filters' => array_merge($run->filters ?? [], $this->reasonValidationSummary, [
                    'status_comparison_count' => 0,
                    'status_updates' => 0,
                    'orders_deleted_missing_from_acumatica' => 0,
                    'sample_deleted_orders' => [],
                ]),
            ]);

            return $run->fresh();
        }

        $statusUpdates = 0;
        $deletedCount = 0;
        $deletedSamples = [];
        $presentSet = null;

        // Phase 1 — when the current sync payload is a complete scope snapshot, purge
        // local orders that Acumatica no longer returned (no extra API call needed).
        if ($presentOrderNbrs !== null) {
            $presentSet = array_fill_keys($presentOrderNbrs, true);
            foreach ($localOrders as $localOrder) {
                $this->touchSyncRun($run);
                $nbr = (string) $localOrder->acumatica_order_nbr;
                if ($nbr === '' || isset($presentSet[$nbr])) {
                    continue;
                }

                $this->purgeLocalSalesOrder($localOrder, $run->id, $deleteReason);
                $deletedCount++;
                if (count($deletedSamples) < 20) {
                    $deletedSamples[] = $nbr;
                }
            }

            // Reload survivors for status recheck.
            $localOrders = AcumaticaSalesOrder::query()
                ->tap($scope)
                ->get(['id', 'acumatica_order_nbr', 'status']);
        }

        if ($localOrders->isEmpty()) {
            $run->update([
                'filters' => array_merge($run->filters ?? [], $this->reasonValidationSummary, [
                    'status_comparison_count' => 0,
                    'status_updates' => 0,
                    'orders_deleted_missing_from_acumatica' => $deletedCount,
                    'sample_deleted_orders' => $deletedSamples,
                ]),
            ]);

            return $run->fresh();
        }

        // Phase 2 — recheck remaining orders by number (catches deletes missed by payload
        // scope + refreshes status for workflow progression).
        $sourceOrders = $this->client->fetchSalesOrdersByNumbers(
            $localOrders->pluck('acumatica_order_nbr')->all(),
            fn () => $this->touchSyncRun($run),
        );

        $sourceByOrderNbr = collect($sourceOrders)
            ->filter(fn (mixed $raw) => is_array($raw))
            ->mapWithKeys(function (array $raw) {
                $orderNbr = $this->str($raw['OrderNbr'] ?? null);

                return $orderNbr ? [$orderNbr => $raw] : [];
            });

        foreach ($localOrders as $localOrder) {
            $this->touchSyncRun($run);

            $sourceOrder = $sourceByOrderNbr->get($localOrder->acumatica_order_nbr);
            if (! is_array($sourceOrder)) {
                $this->purgeLocalSalesOrder($localOrder, $run->id, $deleteReason.'_recheck');
                $deletedCount++;
                if (count($deletedSamples) < 20) {
                    $deletedSamples[] = $localOrder->acumatica_order_nbr;
                }
                continue;
            }

            $sourceStatus = $this->str($sourceOrder['Status'] ?? null);

            if ($this->normalizeStatus($localOrder->status) === $this->normalizeStatus($sourceStatus)) {
                continue;
            }

            $this->upsertOrder($sourceOrder, $run->id);
            $statusUpdates++;
        }

        $run->update([
            'filters' => array_merge($run->filters ?? [], $this->reasonValidationSummary, [
                'status_comparison_count' => $localOrders->count(),
                'status_updates'          => $statusUpdates,
                'orders_deleted_missing_from_acumatica' => $deletedCount,
                'sample_deleted_orders' => $deletedSamples,
            ]),
        ]);

        StructuredLogger::write('info', 'acumatica', 'sales_order_reconcile_completed', [
            'sync_run_id' => $run->id,
            'compared' => $localOrders->count(),
            'status_updates' => $statusUpdates,
            'deleted' => $deletedCount,
            'sample_deleted_orders' => $deletedSamples,
            'delete_reason' => $deleteReason,
        ]);

        return $run->fresh();
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return list<string>
     */
    private function orderNumbersFromPayload(array $orders): array
    {
        $nbrs = [];
        foreach ($orders as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $nbr = $this->str($raw['OrderNbr'] ?? null);
            if ($nbr !== null && $nbr !== '') {
                $nbrs[$nbr] = true;
            }
        }

        return array_keys($nbrs);
    }

    /**
     * Remove a local SO that no longer exists in Acumatica, plus dependent ops rows.
     */
    private function purgeLocalSalesOrder(AcumaticaSalesOrder $order, ?int $runId, string $reason): void
    {
        $orderNbr = (string) $order->acumatica_order_nbr;
        $orderId = (int) $order->id;

        DB::transaction(function () use ($order, $orderNbr, $orderId): void {
            AcumaticaBackorderLine::query()->where('order_nbr', $orderNbr)->delete();
            AcumaticaFillRateSnapshot::query()
                ->where('order_nbr', $orderNbr)
                ->orWhere('sales_order_id', $orderId)
                ->delete();

            // Lines cascade via FK; emails.matched_order_id is nullOnDelete.
            AcumaticaSalesOrderLine::query()->where('sales_order_id', $orderId)->delete();
            $order->delete();
        });

        StructuredLogger::write('info', 'acumatica', 'sales_order_deleted_missing_from_acumatica', [
            'sync_run_id' => $runId,
            'order_nbr' => $orderNbr,
            'order_id' => $orderId,
            'reason' => $reason,
        ]);
    }

    private function updateOrderStatusOnly(AcumaticaSalesOrder $order, array $raw, int $runId): void
    {
        $status = $this->str($raw['Status'] ?? null);

        $lastModified = $this->datetime($raw['LastModifiedDateTime'] ?? $raw['LastModified'] ?? null);

        $approvedAt = $this->datetime(
            $raw['ApprovedDateTime'] ??
            $raw['LastApprovalDate'] ??
            $raw['ApprovalDate'] ??
            null
        );

        $shippedAt = $this->datetime(
            $raw['ActualShipDate'] ??
            $raw['ShippedDate'] ??
            null
        );

        $completedAt = $this->datetime(
            $raw['CompletedDate'] ??
            $raw['CompletedDateTime'] ??
            $raw['InvoiceDate'] ??
            null
        );

        $rejectionReasonCode = $this->extractRejectionReasonCode($raw, $status);
        $rejectionReason = $this->extractRejectionReason($raw, $status);
        $holdReason = $this->extractOnHoldReason($raw, $status);
        $workflow = $this->resolveWorkflowReason($raw, $status, $rejectionReasonCode, $rejectionReason, $holdReason);

        $this->recordRejectionReasonValidation($order->acumatica_order_nbr, $status, $workflow['sub_reason_code'] ?? $rejectionReasonCode, $rejectionReason);

        $updates = [
            'status' => $status,
            'last_modified_at' => $lastModified,
            'approved_at' => $approvedAt,
            'shipped_at' => $shippedAt,
            'completed_at' => $completedAt,
            'sync_run_id' => $runId,
            'synced_at' => now(),
            'raw_payload' => $raw,
        ];

        if ($rejectionReasonCode !== null) {
            $updates['rejection_reason_code'] = $rejectionReasonCode;
        }

        if ($rejectionReason !== null) {
            $updates['rejection_reason'] = $rejectionReason;
        }

        if ($holdReason !== null) {
            $updates['on_hold_reason'] = $holdReason;
        }

        $updates = array_merge($updates, $this->workflowReasonAttributes($workflow));

        $order->update($updates);
    }

    private function upsertOrder(array $raw, int $runId): void
    {
        $orderNbr = $this->str($raw['OrderNbr'] ?? null);

        if (! $orderNbr) {
            throw new \InvalidArgumentException('Sales order record missing OrderNbr');
        }

        $status       = $this->str($raw['Status'] ?? null);
        $lastModified = $this->datetime($raw['LastModifiedDateTime'] ?? $raw['LastModified'] ?? null);
        $rejectionReasonCode = $this->extractRejectionReasonCode($raw, $status);
        $rejectionReason = $this->extractRejectionReason($raw, $status);
        $holdReason = $this->extractOnHoldReason($raw, $status);
        $workflow = $this->resolveWorkflowReason($raw, $status, $rejectionReasonCode, $rejectionReason, $holdReason);

        $approvedAt = $this->datetime(
            $raw['ApprovedDateTime'] ??
            $raw['LastApprovalDate'] ??
            $raw['ApprovalDate'] ??
            null
        );

        $shippedAt = $this->datetime(
            $raw['ActualShipDate'] ??
            $raw['ShippedDate'] ??
            null
        );

        $completedAt = $this->datetime(
            $raw['CompletedDate'] ??
            $raw['CompletedDateTime'] ??
            $raw['InvoiceDate'] ??
            null
        );

        $orderData = [
            'order_type'             => AcumaticaSalesOrder::inferOrderType($orderNbr, $this->str($raw['OrderType'] ?? null)),
            'customer_acumatica_id'  => $this->str($raw['CustomerID'] ?? null),
            'customer_name'          => $this->str($raw['CustomerName'] ?? null),
            'customer_order'         => $this->customerOrder($raw),
            'location_id'            => $this->str($raw['LocationID'] ?? null),
            'status'                 => $status,
            'order_date'             => $this->datetime($raw['Date'] ?? $raw['CreatedDate'] ?? null),
            'last_modified_at'       => $lastModified,
            'ship_date'              => $this->datetime($raw['ShipDate'] ?? null),
            'requested_on'           => $this->datetime($raw['RequestedOn'] ?? null),
            'order_total'            => (float) ($this->str($raw['OrderTotal'] ?? null) ?? 0),
            'currency_id'            => $this->str($raw['CurrencyID'] ?? $raw['CuryID'] ?? null),
            'sales_consultant_rep_code' => $this->salespersonRepCode($raw),
            'approved_at'            => $approvedAt,
            'approved_by_id'         => $this->str($raw['ApprovedByID'] ?? null),
            'shipped_at'             => $shippedAt,
            'completed_at'           => $completedAt,
            'rejection_reason_code'  => null,
            'rejection_reason'       => null,
            'on_hold_reason'         => null,
            'sync_run_id'            => $runId,
            'synced_at'              => now(),
            'raw_payload'            => $raw,
        ];

        $this->recordRejectionReasonValidation($orderNbr, $status, $workflow['sub_reason_code'] ?? $rejectionReasonCode, $rejectionReason);

        if ($workflow['sub_reason_code'] !== null) {
            $orderData['rejection_reason_code'] = $workflow['sub_reason_code'];
        } elseif ($rejectionReasonCode !== null) {
            $orderData['rejection_reason_code'] = $rejectionReasonCode;
        }

        if ($rejectionReason !== null) {
            $orderData['rejection_reason'] = $rejectionReason;
        }

        if ($holdReason !== null) {
            $orderData['on_hold_reason'] = $holdReason;
        }

        $orderData = array_merge($orderData, $this->workflowReasonAttributes($workflow));

        $order = AcumaticaSalesOrder::updateOrCreate(
            ['acumatica_order_nbr' => $orderNbr],
            $orderData,
        );

        // Re-sync line items: delete and replace to avoid stale lines
        $order->lines()->delete();

        $lines = $raw['DocumentDetails'] ?? $raw['Details'] ?? [];
        if (! is_array($lines)) {
            $lines = [];
        }

        foreach ($lines as $lineRaw) {
            if (! is_array($lineRaw)) {
                continue;
            }

            $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);
            if (! $mapped['inventory_id']) {
                continue;
            }

            AcumaticaSalesOrderLine::create([
                'sales_order_id'     => $order->id,
                'line_nbr'           => $mapped['line_nbr'],
                'inventory_id'       => $mapped['inventory_id'],
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
                'warehouse_id'       => $mapped['warehouse_id'],
                'uom'                => $mapped['uom'],
                'unit_price'         => $mapped['unit_price'],
                'ext_cost'           => $mapped['ext_cost'],
                'discount_amount'    => $mapped['discount_amount'],
                'discount_code'      => $mapped['discount_code'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $raw */
    private function customerOrder(array $raw): ?string
    {
        foreach (['CustomerOrder', 'CustomerOrderNbr', 'CustomerPONbr'] as $field) {
            $value = $this->str($raw[$field] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * SalespersonID lives on each line item, not the SO header — take the
     * first non-empty value found across the order's Details.
     */
    public function salespersonRepCode(array $raw): ?string
    {
        $lines = $raw['DocumentDetails'] ?? $raw['Details'] ?? [];
        if (! is_array($lines)) {
            return null;
        }

        foreach ($lines as $lineRaw) {
            if (! is_array($lineRaw)) {
                continue;
            }

            $repCode = $this->str($lineRaw['SalespersonID'] ?? null);
            if ($repCode !== null) {
                return strtoupper(trim($repCode));
            }
        }

        return null;
    }

    /**
     * Extract a scalar string from an Acumatica field value.
     * Guards against the field itself or its inner 'value' being an array.
     */
    private function str(mixed $field): ?string
    {
        $v = AcumaticaClient::val($field);
        if ($v === null || $v === '') return null;
        if (is_array($v)) return null;
        return (string) $v;
    }

    private function date(mixed $field): ?string
    {
        $s = $this->str($field);
        if (! $s) return null;
        $ts = strtotime($s);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /** @param  array<string, mixed>  $raw */
    private function extractRejectionReasonCode(array $raw, ?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));
        if (! in_array($normalized, ['rejected', 'cancelled', 'canceled'], true)) {
            return null;
        }

        $fromRejectedReasons = $this->firstRejectedReasonFromPayload($raw);
        if ($fromRejectedReasons !== null) {
            return $fromRejectedReasons;
        }

        $fromLines = $this->firstLineReasonFromPayload($raw);
        if ($fromLines !== null) {
            return $fromLines;
        }

        return $this->firstWorkflowReason($raw, [
            'RejectionReasonCode',
            'RejectReasonCode',
            'RejectedReasonCode',
            'ReasonCode',
            'ReasonID',
            'ReasonCD',
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{parent_reason_code: ?string, sub_reason_code: ?string, workflow_reason_label: ?string, issue: string}
     */
    private function resolveWorkflowReason(
        array $raw,
        ?string $status,
        ?string $rejectionReasonCode,
        ?string $rejectionReason,
        ?string $holdReason,
    ): array {
        $parent = $this->reasonCatalog->parentForStatus($status);
        if ($parent === null) {
            return [
                'parent_reason_code' => null,
                'sub_reason_code' => null,
                'workflow_reason_label' => null,
                'issue' => SalesOrderReasonCatalog::ISSUE_MISSING,
            ];
        }

        $rawReason = $rejectionReasonCode
            ?? $this->firstRejectedReasonFromPayload($raw)
            ?? $this->firstLineReasonFromPayload($raw)
            ?? $holdReason
            ?? $rejectionReason;

        $classified = $this->reasonCatalog->classify($parent, $rawReason);

        return [
            'parent_reason_code' => $classified['parent_reason_code'],
            'sub_reason_code' => $classified['sub_reason_code'],
            'workflow_reason_label' => $classified['hierarchical_label'],
            'issue' => $classified['issue'],
        ];
    }

    /**
     * @param  array{parent_reason_code: ?string, sub_reason_code: ?string, workflow_reason_label: ?string}  $workflow
     * @return array<string, ?string>
     */
    private function workflowReasonAttributes(array $workflow): array
    {
        if ($workflow['parent_reason_code'] === null) {
            return [
                'workflow_parent_reason' => null,
                'workflow_sub_reason_code' => null,
                'workflow_reason_label' => null,
            ];
        }

        return [
            'workflow_parent_reason' => $workflow['parent_reason_code'],
            'workflow_sub_reason_code' => $workflow['sub_reason_code'],
            'workflow_reason_label' => $workflow['workflow_reason_label'],
        ];
    }

    /** @param  array<string, mixed>  $raw */
    private function firstRejectedReasonFromPayload(array $raw): ?string
    {
        $rejected = $raw['RejectedReasons'] ?? null;
        if (! is_array($rejected) || $rejected === []) {
            return null;
        }

        foreach ($rejected as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (['ReasonCode', 'ReasonID', 'Reason', 'Description'] as $field) {
                $value = $this->str($entry[$field] ?? null);
                if ($value !== null && trim($value) !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $raw */
    private function firstLineReasonFromPayload(array $raw): ?string
    {
        $details = $raw['Details'] ?? null;
        if (! is_array($details)) {
            return null;
        }

        foreach ($details as $line) {
            if (! is_array($line)) {
                continue;
            }
            $value = $this->str($line['ReasonCode'] ?? null);
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $raw */
    private function extractRejectionReason(array $raw, ?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));
        if (! in_array($normalized, ['rejected', 'cancelled', 'canceled'], true)) {
            return null;
        }

        return $this->firstWorkflowReason($raw, [
            'RejectionReasonDescription',
            'RejectionReason',
            'RejectReason',
            'RejectedReason',
            'Reason',
            'Note',
            'Description',
        ]);
    }

    /** @param  array<string, mixed>  $raw */
    private function extractOnHoldReason(array $raw, ?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));
        if (! in_array($normalized, ['on hold', 'credit hold', 'hold'], true)) {
            return null;
        }

        return $this->firstWorkflowReason($raw, [
            'HoldReason',
            'OnHoldReason',
            'CreditHoldReason',
            'CreditHoldReasonDescription',
            'ReasonCode',
            'Reason',
            'Note',
            'Description',
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $fields
     */
    private function firstWorkflowReason(array $raw, array $fields): ?string
    {
        foreach ($fields as $field) {
            $value = $this->str($raw[$field] ?? null);
            if ($value !== null && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function recordRejectionReasonValidation(
        string $orderNbr,
        ?string $status,
        ?string $reasonCode,
        ?string $reasonText,
    ): void {
        $normalizedStatus = $this->normalizeStatus($status);

        if (! in_array($normalizedStatus, ['rejected', 'cancelled', 'canceled'], true)) {
            return;
        }

        if ($reasonCode !== null && trim($reasonCode) !== '') {
            $this->reasonValidationSummary['rejection_reason_codes_imported']++;
        } else {
            $this->reasonValidationSummary['missing_rejection_reason_codes']++;
            if (count($this->reasonValidationSummary['sample_missing_rejection_orders']) < 10
                && ! in_array($orderNbr, $this->reasonValidationSummary['sample_missing_rejection_orders'], true)) {
                $this->reasonValidationSummary['sample_missing_rejection_orders'][] = $orderNbr;
            }

            StructuredLogger::write('warning', 'acumatica', 'sales_order_rejection_reason_code_missing', [
                'order_nbr' => $orderNbr,
                'status' => $status,
            ]);
        }

        if ($reasonText !== null && trim($reasonText) !== '') {
            $this->reasonValidationSummary['rejection_reason_notes_imported']++;
        }

        if ($reasonCode !== null && strlen($reasonCode) > 80) {
            $this->reasonValidationSummary['invalid_rejection_reason_codes']++;
            throw new \RuntimeException("Rejection reason code exceeds 80 characters for {$orderNbr}");
        }
    }

    private function datetime(mixed $field): ?\DateTime
    {
        $s = $this->str($field);
        if (! $s) return null;
        try {
            return new \DateTime($s);
        } catch (\Exception) {
            return null;
        }
    }

    private function normalizeStatus(?string $status): ?string
    {
        if ($status === null) {
            return null;
        }

        $normalized = strtolower(trim($status));

        return $normalized === '' ? null : $normalized;
    }
}
