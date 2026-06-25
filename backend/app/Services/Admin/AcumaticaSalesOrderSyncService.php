<?php

namespace App\Services\Admin;

use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaSyncLog;
use Throwable;

class AcumaticaSalesOrderSyncService
{
    public function __construct(
        private readonly AcumaticaClient $client,
    ) {
    }

    // -------------------------------------------------------------------------
    // Date-range sync
    // -------------------------------------------------------------------------

    public function syncDateRange(string $dateFrom, string $dateTo, ?int $triggeredByUserId = null, string $triggerType = 'manual', ?int $cronRunLogId = null): AcumaticaSyncLog
    {
        $run = AcumaticaSyncLog::create([
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
            $orders = $this->client->fetchAllSalesOrdersByDateRange($dateFrom, $dateTo);
            $run    = $this->processOrders($orders, $run);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
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

    public function syncCreditNotesAndMore(string $dateFrom, string $dateTo, ?int $triggeredByUserId = null): AcumaticaSyncLog
    {
        $run = AcumaticaSyncLog::create([
            'sync_type'            => 'credit_notes_and_more',
            'started_at'           => now(),
            'status'               => 'running',
            'record_count'         => 0,
            'success_count'        => 0,
            'failed_count'         => 0,
            'trigger_type'         => 'manual',
            'triggered_by_user_id' => $triggeredByUserId,
            'filters'              => ['date_from' => $dateFrom, 'date_to' => $dateTo],
        ]);

        try {
            $orders = $this->client->fetchAllCreditNotesAndMoreByDateRange($dateFrom, $dateTo);
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

    // -------------------------------------------------------------------------
    // Selective customer sync
    // -------------------------------------------------------------------------

    public function syncForCustomers(array $customerAcumaticaIds, ?int $triggeredByUserId = null): AcumaticaSyncLog
    {
        $run = AcumaticaSyncLog::create([
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
                $customerOrders = $this->client->fetchAllSalesOrdersForCustomer($customerId);
                $orders         = array_merge($orders, $customerOrders);
            }

            $run = $this->processOrders($orders, $run);
        } catch (Throwable $e) {
            $run->update([
                'ended_at'      => now(),
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
        $total   = count($orders);
        $success = 0;
        $failed  = 0;

        foreach ($orders as $raw) {
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
            'status'        => $failed === $total && $total > 0 ? 'failed' : 'completed',
            'record_count'  => $total,
            'success_count' => $success,
            'failed_count'  => $failed,
        ]);

        StructuredLogger::write('info', 'acumatica', 'sales_order_sync_completed', [
            'sync_run_id' => $run->id,
            'total'       => $total,
            'success'     => $success,
            'failed'      => $failed,
        ]);

        return $run;
    }

    private function upsertOrder(array $raw, int $runId): void
    {
        $orderNbr = $this->str($raw['OrderNbr'] ?? null);

        if (! $orderNbr) {
            throw new \InvalidArgumentException('Sales order record missing OrderNbr');
        }

        $status       = $this->str($raw['Status'] ?? null);
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
            'approved_at'            => $approvedAt,
            'approved_by_id'         => $this->str($raw['ApprovedByID'] ?? null),
            'shipped_at'             => $shippedAt,
            'completed_at'           => $completedAt,
            'sync_run_id'            => $runId,
            'synced_at'              => now(),
            'raw_payload'            => $raw,
        ];

        if ($rejectionReason = $this->extractRejectionReason($raw, $status)) {
            $orderData['rejection_reason'] = $rejectionReason;
        }

        if ($holdReason = $this->extractOnHoldReason($raw, $status)) {
            $orderData['on_hold_reason'] = $holdReason;
        }

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
                'shipped_qty'        => $mapped['shipped_qty'],
                'open_qty'           => $mapped['open_qty'],
                'cancelled_qty'      => $mapped['cancelled_qty'],
                'qty_at_approval'    => $mapped['qty_at_approval'],
                'backorder_qty'      => $mapped['backorder_qty'],
                'fill_rate_pct'      => $mapped['fill_rate_pct'],
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
    private function extractRejectionReason(array $raw, ?string $status): ?string
    {
        $normalized = strtolower(trim((string) $status));
        if (! in_array($normalized, ['rejected', 'cancelled', 'canceled'], true)) {
            return null;
        }

        return $this->firstWorkflowReason($raw, [
            'RejectionReason',
            'RejectReason',
            'RejectedReason',
            'ReasonCode',
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
}
