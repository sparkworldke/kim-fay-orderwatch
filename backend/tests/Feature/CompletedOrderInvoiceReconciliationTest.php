<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\CompletedOrderInvoiceReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CompletedOrderInvoiceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_order_uses_released_invoice_lines_and_persists_shortfalls(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO361526',
            'order_type' => 'SO',
            'status' => 'Completed',
            'order_date' => '2026-07-06',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_nbr' => 1,
            'inventory_id' => 'A',
            'order_qty' => 10,
            'qty_on_shipments' => 10,
            'unit_price' => 100,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_nbr' => 2,
            'inventory_id' => 'B',
            'order_qty' => 30,
            'qty_on_shipments' => 30,
            'unit_price' => 200,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchSalesInvoicesForSalesOrders')
            ->once()
            ->with(['SO361526'], Mockery::any())
            ->andReturn([[
                'Released' => ['value' => true],
                'Voided' => ['value' => false],
                'Details' => [
                    ['OrderNbr' => ['value' => 'SO361526'], 'OrderLineNbr' => ['value' => 1], 'InventoryID' => ['value' => 'A'], 'Qty' => ['value' => 8]],
                    ['OrderNbr' => ['value' => 'SO361526'], 'OrderLineNbr' => ['value' => 2], 'InventoryID' => ['value' => 'B'], 'Qty' => ['value' => 4]],
                    ['OrderNbr' => ['value' => 'SO361526'], 'OrderLineNbr' => ['value' => 2], 'InventoryID' => ['value' => 'B'], 'Qty' => ['value' => 4]],
                ],
            ]]);

        $raw = [[
            'OrderNbr' => ['value' => 'SO361526'],
            'Status' => ['value' => 'Completed'],
            'Details' => [
                ['LineNbr' => ['value' => 1], 'InventoryID' => ['value' => 'A'], 'OrderQty' => ['value' => 10], 'QtyOnShipments' => ['value' => 10], 'UnitPrice' => ['value' => 100]],
                ['LineNbr' => ['value' => 2], 'InventoryID' => ['value' => 'B'], 'OrderQty' => ['value' => 30], 'QtyOnShipments' => ['value' => 30], 'UnitPrice' => ['value' => 200]],
            ],
        ]];

        $stats = (new CompletedOrderInvoiceReconciliationService($client))->reconcile($raw, 77);

        $this->assertFalse($stats['unavailable']);
        $this->assertSame(2, $stats['shortfall_lines']);
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO361526',
            'inventory_id' => 'A',
            'invoiced_qty' => 8,
            'open_qty' => 2,
            'shortfall_kind' => 'completed_shortfall',
            'revenue_at_risk' => 200,
        ]);
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO361526',
            'inventory_id' => 'B',
            'invoiced_qty' => 8,
            'open_qty' => 22,
            'revenue_at_risk' => 4400,
        ]);
        $snapshot = AcumaticaFillRateSnapshot::where('order_nbr', 'SO361526')->firstOrFail();
        $this->assertSame(40.0, (float) $snapshot->total_ordered_qty);
        $this->assertSame(16.0, (float) $snapshot->total_shipped_qty);
        $this->assertSame(40.0, (float) $snapshot->fill_rate_pct);
    }

    public function test_invoice_qty_is_capped_and_cancelled_qty_is_not_a_shortfall(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchSalesInvoicesForSalesOrders')->andReturn([[
            'Released' => ['value' => true],
            'Details' => [
                ['OrderNbr' => ['value' => 'SO-CAP'], 'InventoryID' => ['value' => 'SKU'], 'Qty' => ['value' => 99]],
            ],
        ]]);

        $raw = [[
            'OrderNbr' => ['value' => 'SO-CAP'],
            'Status' => ['value' => 'Completed'],
            'Details' => [[
                'InventoryID' => ['value' => 'SKU'],
                'OrderQty' => ['value' => 10],
                'CancelledQty' => ['value' => 2],
                'QtyOnShipments' => ['value' => 10],
                'UnitPrice' => ['value' => 50],
            ]],
        ]];

        (new CompletedOrderInvoiceReconciliationService($client))->reconcile($raw, 1);

        $this->assertDatabaseMissing('acumatica_backorder_lines', ['order_nbr' => 'SO-CAP']);
        $this->assertSame(100.0, (float) AcumaticaFillRateSnapshot::where('order_nbr', 'SO-CAP')->value('fill_rate_pct'));
    }

    public function test_invoice_api_failure_marks_local_completed_lines_unavailable(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-REVIEW',
            'order_type' => 'SO',
            'status' => 'Completed',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU',
            'order_qty' => 5,
            'qty_on_shipments' => 5,
            'fill_rate_pct' => 100,
            'unit_price' => 10,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchSalesInvoicesForSalesOrders')->andThrow(new \RuntimeException('SalesInvoice unavailable'));

        $stats = (new CompletedOrderInvoiceReconciliationService($client))->reconcile([[
            'OrderNbr' => ['value' => 'SO-REVIEW'],
            'Status' => ['value' => 'Completed'],
        ]], 1);

        $this->assertTrue($stats['unavailable']);
        $line = AcumaticaSalesOrderLine::firstOrFail();
        $this->assertNull($line->fill_rate_pct);
        $this->assertSame('unavailable', $line->invoice_reconciliation_status);
    }
}
