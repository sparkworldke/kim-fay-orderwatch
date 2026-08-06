<?php

namespace App\Services\Admin;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reconstructs completed-order delivery from released invoice lines.
 *
 * QtyOnShipments remains useful while an order is active, but it is allocation
 * state and cannot prove that a completed line was delivered.
 */
class CompletedOrderInvoiceReconciliationService
{
    public function __construct(private readonly AcumaticaClient $client)
    {
    }

    /**
     * @param  list<array<string, mixed>>  $orders
     * @return array{orders_reconciled:int,lines_reconciled:int,shortfall_lines:int,unavailable:bool,error:?string}
     */
    public function reconcile(array $orders, int $runId, ?callable $onProgress = null): array
    {
        $completed = array_values(array_filter($orders, fn ($raw) => is_array($raw) && $this->isCompleted($raw)));
        $orderNbrs = array_values(array_filter(array_map(
            fn (array $raw) => $this->string($raw['OrderNbr'] ?? null),
            $completed,
        )));

        if ($orderNbrs === []) {
            return ['orders_reconciled' => 0, 'lines_reconciled' => 0, 'shortfall_lines' => 0, 'unavailable' => false, 'error' => null];
        }

        try {
            $invoices = $this->client->fetchSalesInvoicesForSalesOrders($orderNbrs, $onProgress);
        } catch (Throwable $e) {
            $this->markUnavailable($orderNbrs);

            return [
                'orders_reconciled' => 0,
                'lines_reconciled' => 0,
                'shortfall_lines' => 0,
                'unavailable' => true,
                'error' => $e->getMessage(),
            ];
        }

        [$byLine, $byInventory] = $this->invoiceQuantities($invoices);
        $stats = ['orders_reconciled' => 0, 'lines_reconciled' => 0, 'shortfall_lines' => 0, 'unavailable' => false, 'error' => null];

        foreach ($completed as $raw) {
            $orderNbr = $this->string($raw['OrderNbr'] ?? null);
            if (! $orderNbr) {
                continue;
            }

            $localOrder = AcumaticaSalesOrder::query()->where('acumatica_order_nbr', $orderNbr)->first();
            $rawLines = $raw['DocumentDetails'] ?? $raw['Details'] ?? [];
            $seenInventory = [];
            $totalOrdered = 0.0;
            $totalInvoiced = 0.0;
            $shortfallValue = 0.0;
            $shortfallCount = 0;

            foreach (is_array($rawLines) ? $rawLines : [] as $lineRaw) {
                if (! is_array($lineRaw)) {
                    continue;
                }

                $mapped = SalesOrderLineFulfillmentDeriver::mapFromRaw($lineRaw);
                $inventoryId = $mapped['inventory_id'];
                $lineNbr = $mapped['line_nbr'];
                $ordered = max((float) $mapped['order_qty'], 0);
                if (! $inventoryId || $ordered <= 0) {
                    continue;
                }

                $exactKey = $orderNbr.'|'.($lineNbr ?? '');
                $inventoryKey = $orderNbr.'|'.$inventoryId;
                $invoiceQty = array_key_exists($exactKey, $byLine)
                    ? $byLine[$exactKey]
                    : ($byInventory[$inventoryKey] ?? 0.0);

                // Inventory fallback is an aggregate and must only be consumed once.
                unset($byInventory[$inventoryKey]);
                $delivered = min(max((float) $invoiceQty, 0), $ordered);
                $cancelled = min(max((float) $mapped['cancelled_qty'], 0), $ordered);
                $missing = max($ordered - $delivered - $cancelled, 0);
                $unitPrice = max((float) $mapped['unit_price'], 0);

                $totalOrdered += $ordered;
                $totalInvoiced += $delivered;
                $shortfallValue += $missing * $unitPrice;
                $stats['lines_reconciled']++;
                $seenInventory[] = $inventoryId;

                if ($localOrder) {
                    AcumaticaSalesOrderLine::query()
                        ->where('sales_order_id', $localOrder->id)
                        ->where('inventory_id', $inventoryId)
                        ->update([
                            'invoiced_qty' => $delivered,
                            'invoice_reconciliation_status' => 'reconciled',
                            'fulfillment_source' => 'released_invoice_lines',
                            'fill_rate_pct' => round($delivered / $ordered * 100, 2),
                            'fulfillment_status' => $missing <= 0
                                ? 'Completed — Fully Delivered'
                                : ($delivered > 0 ? 'Completed — Partially Delivered' : 'Completed — Not Delivered'),
                        ]);
                }

                if ($missing <= 0) {
                    AcumaticaBackorderLine::query()
                        ->where('order_nbr', $orderNbr)
                        ->where('inventory_id', $inventoryId)
                        ->where('shortfall_kind', 'completed_shortfall')
                        ->delete();
                    continue;
                }

                AcumaticaBackorderLine::updateOrCreate(
                    ['order_nbr' => $orderNbr, 'inventory_id' => $inventoryId],
                    [
                        'customer_acumatica_id' => $this->string($raw['CustomerID'] ?? null),
                        'customer_name' => $this->string($raw['CustomerName'] ?? null),
                        'order_qty' => $ordered,
                        'shipped_qty' => (float) $mapped['shipped_qty'],
                        'qty_on_shipments' => (float) $mapped['qty_on_shipments'],
                        'invoiced_qty' => $delivered,
                        'open_qty' => $missing,
                        'cancelled_qty' => $cancelled,
                        'backorder_qty' => $missing,
                        'shortfall_kind' => 'completed_shortfall',
                        'order_status' => $this->string($raw['Status'] ?? null) ?? 'Completed',
                        'invoice_reconciliation_status' => 'reconciled',
                        'fulfillment_source' => 'released_invoice_lines',
                        'fulfillment_status' => $delivered > 0
                            ? 'Completed — Partially Delivered'
                            : 'Completed — Not Delivered',
                        'unit_price' => $unitPrice,
                        'revenue_at_risk' => round($missing * $unitPrice, 2),
                        'warehouse_id' => $mapped['warehouse_id'],
                        'uom' => $mapped['uom'],
                        'currency_id' => $this->string($raw['CurrencyID'] ?? $raw['CuryID'] ?? null),
                        'sync_run_id' => $runId,
                        'synced_at' => now(),
                    ],
                );
                $shortfallCount++;
                $stats['shortfall_lines']++;
            }

            AcumaticaBackorderLine::query()
                ->where('order_nbr', $orderNbr)
                ->where('shortfall_kind', 'completed_shortfall')
                ->when($seenInventory !== [], fn ($query) => $query->whereNotIn('inventory_id', array_unique($seenInventory)))
                ->delete();

            AcumaticaFillRateSnapshot::updateOrCreate(
                ['order_nbr' => $orderNbr],
                [
                    'sales_order_id' => $localOrder?->id,
                    'customer_acumatica_id' => $this->string($raw['CustomerID'] ?? null),
                    'status' => $this->string($raw['Status'] ?? null),
                    'total_ordered_qty' => $totalOrdered,
                    'total_shipped_qty' => $totalInvoiced,
                    'fill_rate_pct' => $totalOrdered > 0 ? round($totalInvoiced / $totalOrdered * 100, 2) : null,
                    'fill_rate_status' => $totalOrdered <= 0 ? 'na' : ($totalInvoiced >= $totalOrdered ? 'good' : 'critical'),
                    'revenue_not_shipped' => round($shortfallValue, 2),
                    'out_of_stock_line_count' => $shortfallCount,
                    'sync_run_id' => $runId,
                    'computed_at' => now(),
                ],
            );

            $stats['orders_reconciled']++;
        }

        return $stats;
    }

    /** @return array{0:array<string,float>,1:array<string,float>} */
    private function invoiceQuantities(array $invoices): array
    {
        $byLine = [];
        $byInventory = [];

        foreach ($invoices as $invoice) {
            if (! is_array($invoice) || ! $this->truthy($invoice['Released'] ?? true) || $this->truthy($invoice['Voided'] ?? false)) {
                continue;
            }
            foreach (($invoice['Details'] ?? $invoice['DocumentDetails'] ?? []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $orderNbr = $this->string($line['OrderNbr'] ?? $line['SalesOrderNbr'] ?? $line['SOOrderNbr'] ?? null);
                $inventoryId = $this->string($line['InventoryID'] ?? null);
                $lineNbr = $this->number($line['OrderLineNbr'] ?? $line['SalesOrderLineNbr'] ?? $line['SOLineNbr'] ?? null);
                $qty = max($this->number($line['Qty'] ?? $line['Quantity'] ?? $line['TranQty'] ?? null), 0);
                if (! $orderNbr || ! $inventoryId || $qty <= 0) {
                    continue;
                }
                if ($lineNbr !== null) {
                    $key = $orderNbr.'|'.$lineNbr;
                    $byLine[$key] = ($byLine[$key] ?? 0) + $qty;
                } else {
                    $key = $orderNbr.'|'.$inventoryId;
                    $byInventory[$key] = ($byInventory[$key] ?? 0) + $qty;
                }
            }
        }

        return [$byLine, $byInventory];
    }

    private function markUnavailable(array $orderNbrs): void
    {
        AcumaticaSalesOrderLine::query()
            ->whereIn('sales_order_id', AcumaticaSalesOrder::query()->whereIn('acumatica_order_nbr', $orderNbrs)->select('id'))
            ->update([
                'invoiced_qty' => null,
                'invoice_reconciliation_status' => 'unavailable',
                'fulfillment_source' => 'released_invoice_lines',
                'fill_rate_pct' => null,
                'fulfillment_status' => 'Completed — Review Required',
            ]);

        AcumaticaFillRateSnapshot::query()
            ->whereIn('order_nbr', $orderNbrs)
            ->update([
                'total_shipped_qty' => 0,
                'fill_rate_pct' => null,
                'fill_rate_status' => 'na',
                'computed_at' => now(),
            ]);
    }

    private function isCompleted(array $raw): bool
    {
        return in_array(strtolower(trim((string) ($this->string($raw['Status'] ?? null) ?? ''))), ['completed', 'closed', 'invoiced'], true);
    }

    private function string(mixed $field): ?string
    {
        $value = AcumaticaClient::val($field);
        return is_scalar($value) && trim((string) $value) !== '' ? strtoupper(trim((string) $value)) : null;
    }

    private function number(mixed $field): ?float
    {
        $value = AcumaticaClient::val($field);
        return is_numeric($value) ? (float) $value : null;
    }

    private function truthy(mixed $field): bool
    {
        return filter_var(AcumaticaClient::val($field), FILTER_VALIDATE_BOOL);
    }
}
