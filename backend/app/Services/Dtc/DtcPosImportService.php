<?php

namespace App\Services\Dtc;

use App\Models\DtcSyncLog;
use App\Models\User;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use Throwable;

/**
 * Imports POS sales orders (SO) for the Calltronix POS customer into local sales-order tables.
 */
class DtcPosImportService
{
    public function __construct(
        private readonly AcumaticaSalesOrderSyncService $salesOrders,
    ) {}

    /**
     * @return array{ok:bool,customer_id:string,date_from:string,date_to:string,fetched:int,processed:int,failed:int,total_amount:float,status:string}
     */
    public function import(string $dateFrom, string $dateTo, User $actor): array
    {
        $customerId = DtcQuoteService::POS_ORDER_CUSTOMER_ID;
        $log = DtcSyncLog::create([
            'sync_type' => 'pos_orders',
            'status' => 'running',
            'triggered_by' => $actor->id,
            'started_at' => now(),
            'metadata' => [
                'customer_id' => $customerId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);

        try {
            $run = $this->salesOrders->syncForCustomers(
                [$customerId],
                $actor->id,
                $dateFrom,
                $dateTo,
            );

            $processed = (int) $run->success_count;
            $failed = (int) $run->failed_count;
            $fetched = (int) $run->record_count;
            $totalAmount = (float) \App\Models\AcumaticaSalesOrder::query()
                ->where('order_type', 'SO')
                ->where('customer_acumatica_id', $customerId)
                ->whereDate('order_date', '>=', $dateFrom)
                ->whereDate('order_date', '<=', $dateTo)
                ->sum('order_total');

            $status = $run->status === 'failed' ? 'failed' : 'success';
            $log->update([
                'status' => $status,
                'records_processed' => $processed,
                'error_message' => $run->error_message,
                'finished_at' => now(),
                'metadata' => array_merge($log->metadata ?? [], [
                    'acumatica_sync_log_id' => $run->id,
                    'fetched' => $fetched,
                    'processed' => $processed,
                    'failed' => $failed,
                    'total_amount' => round($totalAmount, 2),
                ]),
            ]);

            return [
                'ok' => $status === 'success',
                'customer_id' => $customerId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'fetched' => $fetched,
                'processed' => $processed,
                'failed' => $failed,
                'total_amount' => round($totalAmount, 2),
                'status' => $status,
            ];
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);
            throw $e;
        }
    }
}
