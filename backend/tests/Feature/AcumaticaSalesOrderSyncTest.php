<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AcumaticaSalesOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_order_sync_upserts_order_lines_and_customer_po(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')->once()->andReturn([
            [
                'OrderNbr'      => ['value' => 'SO5001'],
                'OrderType'     => ['value' => 'SO'],
                'CustomerID'    => ['value' => 'CUST01'],
                'CustomerName'  => ['value' => 'Acme Ltd'],
                'CustomerPONbr' => ['value' => 'PO-5001'],
                'Status'            => ['value' => 'Open'],
                'Date'              => ['value' => '2026-06-23T10:00:00'],
                'ApprovedDateTime'  => ['value' => '2026-06-23T11:30:00+03:00'],
                'ApprovedByID'      => ['value' => 'USR001'],
                'ActualShipDate'    => ['value' => '2026-06-24T08:00:00+03:00'],
                'OrderTotal'    => ['value' => 250],
                'CurrencyID'    => ['value' => 'KES'],
                'DocumentDetails' => [
                    [
                        'LineNbr'     => ['value' => 1],
                        'InventoryID' => ['value' => 'ITEM-001'],
                        'Description' => ['value' => 'Widget'],
                        'OrderQty'    => ['value' => 10],
                        'ShippedQty'  => ['value' => 4],
                        'OpenQty'     => ['value' => 6],
                        'UnitPrice'   => ['value' => 25],
                        'ExtPrice'    => ['value' => 250],
                        'WarehouseID' => ['value' => 'MAIN'],
                        'UOM'         => ['value' => 'EA'],
                    ],
                ],
            ],
            [
                'OrderNbr'   => ['value' => 'QT9001'],
                'OrderType'  => ['value' => 'QT'],
                'CustomerID' => ['value' => 'CUST02'],
                'Status'     => ['value' => 'Open'],
                'Date'       => ['value' => '2026-06-23T11:00:00'],
                'Details'    => [
                    [
                        'InventoryID' => ['value' => 'ITEM-002'],
                        'OrderQty'    => ['value' => 2],
                        'UnitPrice'   => ['value' => 50],
                    ],
                ],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client);
        $run = $service->syncDateRange('2026-06-23', '2026-06-23');

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->success_count);

        $so = AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO5001')->first();
        $this->assertNotNull($so);
        $this->assertSame('SO', $so->order_type);
        $this->assertSame('PO-5001', $so->customer_order);
        $this->assertNotNull($so->approved_at);
        $this->assertSame('USR001', $so->approved_by_id);
        $this->assertNotNull($so->shipped_at);

        $soLines = AcumaticaSalesOrderLine::where('sales_order_id', $so->id)->get();
        $this->assertCount(1, $soLines);
        $this->assertSame('ITEM-001', $soLines[0]->inventory_id);
        $this->assertSame('250.00', $soLines[0]->ext_cost);
        $this->assertSame('Backorders Imported', $soLines[0]->fulfillment_status);
        $this->assertSame('6.0000', $soLines[0]->open_qty);
        $this->assertSame('40.00', $soLines[0]->fill_rate_pct);

        $qt = AcumaticaSalesOrder::where('acumatica_order_nbr', 'QT9001')->first();
        $this->assertNotNull($qt);
        $this->assertSame('QT', $qt->order_type);
        $this->assertCount(1, AcumaticaSalesOrderLine::where('sales_order_id', $qt->id)->get());
    }

    public function test_sales_order_sync_imports_rejection_and_on_hold_reasons(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')->once()->andReturn([
            [
                'OrderNbr'         => ['value' => 'SO-REJ'],
                'OrderType'        => ['value' => 'SO'],
                'CustomerID'       => ['value' => 'CUST01'],
                'Status'           => ['value' => 'Rejected'],
                'Date'             => ['value' => '2026-06-23T10:00:00'],
                'OrderTotal'       => ['value' => 100],
                'RejectionReason'  => ['value' => 'Credit limit exceeded'],
                'Details'          => [],
            ],
            [
                'OrderNbr'      => ['value' => 'SO-HOLD'],
                'OrderType'     => ['value' => 'SO'],
                'CustomerID'    => ['value' => 'CUST01'],
                'Status'        => ['value' => 'On Hold'],
                'Date'          => ['value' => '2026-06-23T11:00:00'],
                'OrderTotal'    => ['value' => 200],
                'HoldReason'    => ['value' => 'Awaiting manager approval'],
                'Details'       => [],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client);
        $service->syncDateRange('2026-06-23', '2026-06-23');

        $rejected = AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO-REJ')->first();
        $onHold = AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO-HOLD')->first();

        $this->assertSame('Credit limit exceeded', $rejected->rejection_reason);
        $this->assertSame('Awaiting manager approval', $onHold->on_hold_reason);
    }
}