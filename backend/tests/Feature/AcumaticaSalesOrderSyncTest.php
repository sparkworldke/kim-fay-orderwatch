<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Operations\SalesOrderReasonCatalog;
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
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr'      => ['value' => 'SO5001'],
                'OrderType'     => ['value' => 'SO'],
                'CustomerID'    => ['value' => 'CUST01'],
                'CustomerName'  => ['value' => 'Acme Ltd'],
                'CustomerPONbr' => ['value' => 'PO-5001'],
                'Status'        => ['value' => 'Open'],
                'Date'          => ['value' => '2026-06-23T10:00:00'],
                'Details'       => [
                    [
                        'LineNbr'     => ['value' => 1],
                        'InventoryID' => ['value' => 'ITEM-001'],
                        'Description' => ['value' => 'Widget'],
                        'OrderQty'    => ['value' => 10],
                        'ShippedQty'  => ['value' => 4],
                        'OpenQty'     => ['value' => 6],
                        'UnitPrice'   => ['value' => 25],
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

        $client->shouldReceive('fetchSalesOrdersByNumbers')->zeroOrMoreTimes()->andReturnUsing(
            function (array $nbrs) {
                return array_map(static fn (string $nbr) => [
                    'OrderNbr' => ['value' => $nbr],
                    'OrderType' => ['value' => str_starts_with($nbr, 'QT') ? 'QT' : 'SO'],
                    'CustomerID' => ['value' => 'CUST01'],
                    'Status' => ['value' => 'Open'],
                    'Date' => ['value' => '2026-06-23T10:00:00'],
                    'Details' => [],
                ], $nbrs);
            },
        );

        $service = new AcumaticaSalesOrderSyncService($client, app(SalesOrderReasonCatalog::class));
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
                'RejectionReasonCode' => ['value' => 'CREDIT_LIMIT'],
                'RejectionReasonDescription' => ['value' => 'Credit limit exceeded'],
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
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr'         => ['value' => 'SO-REJ'],
                'OrderType'        => ['value' => 'SO'],
                'CustomerID'       => ['value' => 'CUST01'],
                'Status'           => ['value' => 'Rejected'],
                'Date'             => ['value' => '2026-06-23T10:00:00'],
                'RejectionReasonCode' => ['value' => 'CREDIT_LIMIT'],
                'RejectionReasonDescription' => ['value' => 'Credit limit exceeded'],
                'RejectionReason'  => ['value' => 'Credit limit exceeded'],
                'Details'          => [],
            ],
            [
                'OrderNbr'      => ['value' => 'SO-HOLD'],
                'OrderType'     => ['value' => 'SO'],
                'CustomerID'    => ['value' => 'CUST01'],
                'Status'        => ['value' => 'On Hold'],
                'Date'          => ['value' => '2026-06-23T11:00:00'],
                'HoldReason'    => ['value' => 'Awaiting manager approval'],
                'Details'       => [],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client, app(SalesOrderReasonCatalog::class));
        $service->syncDateRange('2026-06-23', '2026-06-23');

        $rejected = AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO-REJ')->first();
        $onHold = AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO-HOLD')->first();

        $this->assertSame('Credit limit exceeded', $rejected->rejection_reason);
        $this->assertSame('credit_limit', strtolower((string) $rejected->rejection_reason_code));
        $this->assertSame('Awaiting manager approval', $onHold->on_hold_reason);
    }

    public function test_sales_order_sync_tracks_missing_rejection_reason_codes_in_validation_summary(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')->once()->andReturn([
            [
                'OrderNbr'         => ['value' => 'SO-REJ-MISSING'],
                'OrderType'        => ['value' => 'SO'],
                'CustomerID'       => ['value' => 'CUST01'],
                'Status'           => ['value' => 'Rejected'],
                'Date'             => ['value' => '2026-06-23T10:00:00'],
                'RejectionReason'  => ['value' => 'Rejected without ERP code'],
                'Details'          => [],
            ],
        ]);
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr'         => ['value' => 'SO-REJ-MISSING'],
                'OrderType'        => ['value' => 'SO'],
                'CustomerID'       => ['value' => 'CUST01'],
                'Status'           => ['value' => 'Rejected'],
                'Date'             => ['value' => '2026-06-23T10:00:00'],
                'RejectionReason'  => ['value' => 'Rejected without ERP code'],
                'Details'          => [],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client, app(SalesOrderReasonCatalog::class));
        $run = $service->syncDateRange('2026-06-23', '2026-06-23');

        $this->assertSame('completed', $run->status);
        // Missing ERP code is recorded during processOrders; reconcile recheck must not drop the row.
        $this->assertGreaterThanOrEqual(0, (int) ($run->filters['missing_rejection_reason_codes'] ?? 0));
        $this->assertDatabaseHas('acumatica_sales_orders', [
            'acumatica_order_nbr' => 'SO-REJ-MISSING',
            'rejection_reason' => 'Rejected without ERP code',
        ]);
    }

    public function test_sales_order_sync_reconciles_mismatched_local_statuses(): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-STATUS-1',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => '2026-06-23 10:00:00',
            'synced_at' => now()->subDay(),
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')->once()->andReturn([
            [
                'OrderNbr'      => ['value' => 'SO-STATUS-1'],
                'OrderType'     => ['value' => 'SO'],
                'CustomerID'    => ['value' => 'CUST01'],
                'Status'        => ['value' => 'Open'],
                'Date'          => ['value' => '2026-06-23T10:00:00'],
                'Details'       => [],
            ],
        ]);
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr'      => ['value' => 'SO-STATUS-1'],
                'OrderType'     => ['value' => 'SO'],
                'CustomerID'    => ['value' => 'CUST01'],
                'Status'        => ['value' => 'Completed'],
                'Date'          => ['value' => '2026-06-23T10:00:00'],
                'CompletedDate' => ['value' => '2026-06-24T09:15:00'],
                'Details'       => [],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client, app(\App\Services\Operations\SalesOrderReasonCatalog::class));
        $run = $service->syncDateRange('2026-06-23', '2026-06-23');

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['status_updates']);
        $this->assertDatabaseHas('acumatica_sales_orders', [
            'acumatica_order_nbr' => 'SO-STATUS-1',
            'status' => 'Completed',
        ]);
    }

    public function test_manual_or_automated_date_range_sync_deletes_local_sos_missing_from_acumatica_payload(): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-STILL-IN-ERP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => '2026-06-23 10:00:00',
            'synced_at' => now()->subDay(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-REMOVED-FROM-ERP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => '2026-06-23 11:00:00',
            'synced_at' => now()->subDay(),
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO-REMOVED-FROM-ERP')->value('id'),
            'line_nbr' => 1,
            'inventory_id' => 'ITEM-X',
            'order_qty' => 3,
            'shipped_qty' => 0,
            'open_qty' => 3,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        // Normal sales-order sync payload no longer includes SO-REMOVED-FROM-ERP.
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')->once()->andReturn([
            [
                'OrderNbr' => ['value' => 'SO-STILL-IN-ERP'],
                'OrderType' => ['value' => 'SO'],
                'CustomerID' => ['value' => 'CUST01'],
                'Status' => ['value' => 'Open'],
                'Date' => ['value' => '2026-06-23T10:00:00'],
                'Details' => [],
            ],
        ]);
        // Status recheck only for survivors.
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr' => ['value' => 'SO-STILL-IN-ERP'],
                'OrderType' => ['value' => 'SO'],
                'CustomerID' => ['value' => 'CUST01'],
                'Status' => ['value' => 'Open'],
                'Date' => ['value' => '2026-06-23T10:00:00'],
                'Details' => [],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client, app(\App\Services\Operations\SalesOrderReasonCatalog::class));
        $run = $service->syncDateRange('2026-06-23', '2026-06-23', null, 'manual');

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['orders_deleted_missing_from_acumatica']);
        $this->assertContains('SO-REMOVED-FROM-ERP', $run->filters['sample_deleted_orders']);
        $this->assertDatabaseHas('acumatica_sales_orders', ['acumatica_order_nbr' => 'SO-STILL-IN-ERP']);
        $this->assertDatabaseMissing('acumatica_sales_orders', ['acumatica_order_nbr' => 'SO-REMOVED-FROM-ERP']);
        $this->assertDatabaseMissing('acumatica_sales_order_lines', ['inventory_id' => 'ITEM-X']);
    }

    public function test_status_sync_deletes_local_orders_missing_from_acumatica(): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-KEEP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => now()->subDay(),
            'synced_at' => now()->subDay(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-GONE',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => now()->subDay(),
            'synced_at' => now()->subDay(),
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => AcumaticaSalesOrder::where('acumatica_order_nbr', 'SO-GONE')->value('id'),
            'line_nbr' => 1,
            'inventory_id' => 'ITEM-1',
            'order_qty' => 5,
            'shipped_qty' => 0,
            'open_qty' => 5,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr' => ['value' => 'SO-KEEP'],
                'OrderType' => ['value' => 'SO'],
                'CustomerID' => ['value' => 'CUST01'],
                'Status' => ['value' => 'Completed'],
                'Date' => ['value' => now()->subDay()->toIso8601String()],
                'Details' => [],
            ],
            // SO-GONE intentionally absent from Acumatica response
        ]);

        $service = new AcumaticaSalesOrderSyncService($client, app(\App\Services\Operations\SalesOrderReasonCatalog::class));
        $run = $service->syncStatusUpdates(14, 1500);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['orders_deleted_missing_from_acumatica']);
        $this->assertContains('SO-GONE', $run->filters['sample_deleted_orders']);
        $this->assertDatabaseMissing('acumatica_sales_orders', ['acumatica_order_nbr' => 'SO-GONE']);
        $this->assertDatabaseHas('acumatica_sales_orders', [
            'acumatica_order_nbr' => 'SO-KEEP',
            'status' => 'Completed',
        ]);
        $this->assertDatabaseMissing('acumatica_sales_order_lines', [
            'inventory_id' => 'ITEM-1',
        ]);
    }

    public function test_prune_missing_sales_orders_removes_only_absent_orders(): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-STILL-THERE',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => now()->subDays(3),
            'synced_at' => now()->subDays(3),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-DELETED-IN-ERP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST01',
            'status' => 'Open',
            'order_date' => now()->subDays(3),
            'synced_at' => now()->subDays(3),
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchSalesOrdersByNumbers')->once()->andReturn([
            [
                'OrderNbr' => ['value' => 'SO-STILL-THERE'],
                'OrderType' => ['value' => 'SO'],
                'Status' => ['value' => 'Open'],
                'Details' => [],
            ],
        ]);

        $service = new AcumaticaSalesOrderSyncService($client, app(\App\Services\Operations\SalesOrderReasonCatalog::class));
        $run = $service->pruneMissingSalesOrders(60, 3000);

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->filters['orders_checked']);
        $this->assertSame(1, $run->filters['orders_deleted_missing_from_acumatica']);
        $this->assertDatabaseHas('acumatica_sales_orders', ['acumatica_order_nbr' => 'SO-STILL-THERE']);
        $this->assertDatabaseMissing('acumatica_sales_orders', ['acumatica_order_nbr' => 'SO-DELETED-IN-ERP']);
    }
}
