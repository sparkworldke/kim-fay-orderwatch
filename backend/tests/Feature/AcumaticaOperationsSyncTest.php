<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaShippingZone;
use App\Models\AcumaticaSyncLog;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\User;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AcumaticaFillRateSyncService;
use App\Services\Admin\AcumaticaInventorySyncService;
use App\Services\Admin\FillRateCalculator;
use App\Services\Admin\InventoryRunRatePredictor;
use App\Services\Admin\ProductBrandClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AcumaticaOperationsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_sync_upserts_and_logs_run_rate(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null)->andReturn([
            [
                'InventoryID' => ['value' => 'ITEM-001'],
                'Description' => ['value' => 'Widget'],
                'QtyOnHand'   => ['value' => 10],
            ],
            [
                'InventoryID' => ['value' => 'ITEM-001'],
                'Description' => ['value' => 'Widget'],
                'QtyOnHand'   => ['value' => 0],
            ],
        ]);

        $service = $this->inventoryService($client);

        $run = $service->run();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->success_count);

        $item = AcumaticaInventoryItem::where('inventory_id', 'ITEM-001')->first();
        $this->assertNotNull($item);
        $this->assertSame('0.0000', $item->qty_on_hand);

        $logs = AcumaticaInventoryRunRateLog::where('inventory_item_id', $item->id)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('10.0000', $logs[0]->qty_on_hand);
        $this->assertSame('0.0000', $logs[1]->qty_on_hand);
        $this->assertSame('10.0000', $logs[1]->qty_delta);
    }

    public function test_inventory_sync_skips_inactive_items_without_counting_as_failed(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null)->andReturn([
            [
                'InventoryID' => ['value' => 'ITEM-ACTIVE'],
                'Description' => ['value' => 'Active widget'],
                'QtyOnHand'   => ['value' => 25],
                'ItemStatus'  => ['value' => 'Active'],
            ],
            [
                'InventoryID' => ['value' => 'ITEM-INACTIVE'],
                'Description' => ['value' => 'Old widget'],
                'QtyOnHand'   => ['value' => 5],
                'ItemStatus'  => ['value' => 'Inactive'],
            ],
        ]);

        $service = $this->inventoryService($client);
        $run = $service->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->record_count);
        $this->assertSame(1, $run->success_count);
        $this->assertSame(0, $run->failed_count);
        $this->assertDatabaseHas('acumatica_inventory_items', ['inventory_id' => 'ITEM-ACTIVE']);
        $this->assertDatabaseMissing('acumatica_inventory_items', ['inventory_id' => 'ITEM-INACTIVE']);
    }

    public function test_backorder_sync_upserts_by_order_and_inventory(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllOpenSalesOrdersForBackorders')->once()->andReturn([
            [
                'OrderNbr'     => ['value' => 'SO100'],
                'Status'       => ['value' => 'Open'],
                'CustomerID'   => ['value' => 'CUST01'],
                'CustomerName' => ['value' => 'Acme'],
                'CurrencyID'   => ['value' => 'KES'],
                'DocumentDetails' => [
                    [
                        'InventoryID' => ['value' => 'ITEM-001'],
                        'OrderQty'    => ['value' => 10],
                        'ShippedQty'  => ['value' => 4],
                        'OpenQty'     => ['value' => 6],
                        'UnitPrice'   => ['value' => 100],
                    ],
                ],
            ],
        ]);

        $service = new AcumaticaBackorderSyncService($client);
        $run = $service->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->success_count);
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr'           => 'SO100',
            'inventory_id'        => 'ITEM-001',
            'open_qty'            => 6,
            'backorder_qty'       => 6,
            'fulfillment_status'  => 'Backorders Imported',
            'revenue_at_risk'     => 600,
        ]);
    }

    public function test_fill_rate_sync_computes_snapshot_from_qty_on_shipments(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchOrdersForFillRate')->once()->andReturn([
            [
                'OrderNbr'   => ['value' => 'SO200'],
                'Status'     => ['value' => 'Open'],
                'CustomerID' => ['value' => 'CUST02'],
                'CurrencyID' => ['value' => 'KES'],
                'DocumentDetails' => [
                    [
                        'InventoryID'    => ['value' => 'ITEM-002'],
                        'OrderQty'       => ['value' => 10],
                        'ShippedQty'     => ['value' => 5],
                        'QtyOnShipments' => ['value' => 5],
                        'OpenQty'        => ['value' => 5],
                        'UnitPrice'      => ['value' => 20],
                    ],
                ],
            ],
        ]);

        $service = new AcumaticaFillRateSyncService($client, new FillRateCalculator);
        $run = $service->syncDateRange('2026-06-01', '2026-06-30');

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['orders_computed']);
        $this->assertSame(0, $run->filters['lines_out_of_stock']);
        $this->assertDatabaseHas('acumatica_fill_rate_snapshots', [
            'order_nbr'        => 'SO200',
            'fill_rate_pct'    => 50,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 100,
            'out_of_stock_line_count' => 0,
        ]);
    }

    public function test_fill_rate_sync_marks_out_of_stock_lines_and_guardrails(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchOrdersForFillRate')->once()->andReturn([
            [
                'OrderNbr'   => ['value' => 'SO359765'],
                'Status'     => ['value' => 'Rejected'],
                'CustomerID' => ['value' => 'CUST102396'],
                'CurrencyID' => ['value' => 'KES'],
                'Details' => [
                    [
                        'InventoryID'    => ['value' => 'FAYWP0024'],
                        'LineDescription'=> ['value' => 'Fay Antibacterial Wet Wipes'],
                        'OrderQty'       => ['value' => 10],
                        'QtyOnShipments' => ['value' => 0],
                        'UnitPrice'      => ['value' => 1706.89655],
                    ],
                    [
                        'InventoryID'    => ['value' => 'FAYWP0025'],
                        'OrderQty'       => ['value' => 5],
                        'QtyOnShipments' => ['value' => 5],
                        'UnitPrice'      => ['value' => 1706.89655],
                    ],
                ],
            ],
        ]);

        $service = new AcumaticaFillRateSyncService($client, new FillRateCalculator);
        $run = $service->syncDateRange('2026-06-01', '2026-06-30');

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['orders_computed']);
        $this->assertSame(1, $run->filters['lines_out_of_stock']);

        $this->assertDatabaseHas('acumatica_fill_rate_snapshots', [
            'order_nbr'               => 'SO359765',
            'fill_rate_pct'           => 33.33,
            'out_of_stock_line_count' => 1,
        ]);
    }

    public function test_operations_endpoints_require_auth(): void
    {
        $this->getJson('/api/operations/inventory')->assertUnauthorized();
        $this->getJson('/api/operations/backorders')->assertUnauthorized();
        $this->getJson('/api/operations/fill-rate')->assertUnauthorized();
        $this->getJson('/api/operations/status')->assertUnauthorized();
        $this->getJson('/api/operations/business-optimization')->assertUnauthorized();
    }

    public function test_operations_status_returns_last_sync_timestamps(): void
    {
        $user = User::factory()->create();

        AcumaticaSyncLog::create([
            'sync_type'     => 'inventory_stocks',
            'started_at'    => now()->subMinutes(10),
            'ended_at'      => now()->subMinutes(5),
            'status'        => 'completed',
            'record_count'  => 10,
            'success_count' => 10,
            'failed_count'  => 0,
        ]);

        AcumaticaSyncLog::create([
            'sync_type'     => 'backorders',
            'started_at'    => now()->subHour(),
            'ended_at'      => now()->subMinutes(50),
            'status'        => 'completed',
            'record_count'  => 5,
            'success_count' => 5,
            'failed_count'  => 0,
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/status')
            ->assertOk()
            ->assertJsonStructure([
                'last_inventory_sync_at',
                'last_backorder_sync_at',
                'last_fill_rate_sync_at',
                'inventory_stale',
                'backorders_stale',
                'fill_rate_stale',
            ])
            ->assertJsonPath('last_inventory_sync_type', 'inventory_stocks');
    }

    public function test_business_optimization_returns_insight_sections(): void
    {
        $user = User::factory()->create();

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-OPT',
            'description'  => 'Optimization Widget',
            'qty_on_hand'  => 2,
            'synced_at'    => now(),
        ]);

        AcumaticaBackorderLine::create([
            'order_nbr'             => 'SO-OPT',
            'inventory_id'          => 'ITEM-OPT',
            'customer_acumatica_id' => 'CUST-OPT',
            'customer_name'         => 'Opt Customer',
            'order_qty'             => 20,
            'shipped_qty'           => 5,
            'open_qty'              => 15,
            'revenue_at_risk'       => 1500,
            'reason_code'           => 'supplier_delay',
            'synced_at'             => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-OPT',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-OPT',
            'status'                => 'Open',
            'order_date'            => now(),
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id'       => $order->id,
            'inventory_id'         => 'ITEM-OPT',
            'order_qty'            => 20,
            'qty_on_shipments'     => 0,
            'unit_price'           => 75,
            'unfilled_reason_code' => 'inventory_shortage',
        ]);

        AcumaticaFillRateSnapshot::create([
            'order_nbr'             => 'SO-OPT',
            'customer_acumatica_id' => 'CUST-OPT',
            'status'                => 'Open',
            'total_ordered_qty'     => 20,
            'total_shipped_qty'     => 10,
            'fill_rate_pct'         => 50,
            'fill_rate_status'      => 'critical',
            'revenue_not_shipped'   => 500,
            'computed_at'           => now(),
        ]);

        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $this->actingAs($user)
            ->getJson("/api/operations/business-optimization?date_from={$from}&date_to={$to}")
            ->assertOk()
            ->assertJsonStructure([
                'customer_focus',
                'product_focus',
                'production_forecast',
                'revenue_bleeding',
                'executive_alerts',
                'charts' => [
                    'backorders_by_reason',
                    'fill_rate_unfilled_reasons',
                ],
            ])
            ->assertJsonPath('product_focus.shortfall_count', 1)
            ->assertJsonPath('revenue_bleeding.backorder_revenue_at_risk', 1500)
            ->assertJsonPath('revenue_bleeding.zero_qty_on_shipments_lines', 1)
            ->assertJsonPath('charts.backorders_by_reason.0.reason_code', 'supplier_delay')
            ->assertJsonPath('charts.fill_rate_unfilled_reasons.0.reason_code', 'inventory_shortage');
    }

    public function test_business_optimization_filters_by_shipping_zone(): void
    {
        $user = User::factory()->create();

        AcumaticaShippingZone::query()->updateOrCreate(
            ['acumatica_id' => 'Z001'],
            ['description' => 'Westlands (Nairobi)', 'name' => 'Westlands', 'region' => 'Nairobi', 'synced_at' => now()],
        );
        AcumaticaShippingZone::query()->updateOrCreate(
            ['acumatica_id' => 'Z012'],
            ['description' => 'Mombasa (Coast)', 'name' => 'Mombasa', 'region' => 'Coast', 'synced_at' => now()],
        );
        AcumaticaShippingZone::query()->updateOrCreate(
            ['acumatica_id' => 'Z999'],
            ['description' => 'Empty Zone', 'name' => 'Empty', 'region' => 'Other', 'synced_at' => now()],
        );

        AcumaticaCustomer::create([
            'acumatica_id'      => 'CUST-Z1',
            'name'              => 'Nairobi Buyer',
            'shipping_zone_id'  => 'Z001',
            'synced_at'         => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id'      => 'CUST-Z2',
            'name'              => 'Mombasa Buyer',
            'shipping_zone_id'  => 'Z012',
            'synced_at'         => now(),
        ]);

        AcumaticaBackorderLine::create([
            'order_nbr'             => 'SO-Z1',
            'inventory_id'          => 'ITEM-Z1',
            'customer_acumatica_id' => 'CUST-Z1',
            'customer_name'         => 'Nairobi Buyer',
            'order_qty'             => 10,
            'shipped_qty'           => 0,
            'open_qty'              => 10,
            'revenue_at_risk'       => 1000,
            'reason_code'           => 'supplier_delay',
            'synced_at'             => now(),
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr'             => 'SO-Z2',
            'inventory_id'          => 'ITEM-Z2',
            'customer_acumatica_id' => 'CUST-Z2',
            'customer_name'         => 'Mombasa Buyer',
            'order_qty'             => 20,
            'shipped_qty'           => 0,
            'open_qty'              => 20,
            'revenue_at_risk'       => 2000,
            'reason_code'           => 'quality_hold',
            'synced_at'             => now(),
        ]);

        $orderZ1 = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-Z1',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-Z1',
            'status'                => 'Open',
            'order_date'            => now(),
        ]);
        $orderZ2 = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-Z2',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-Z2',
            'status'                => 'Open',
            'order_date'            => now(),
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id'       => $orderZ1->id,
            'inventory_id'         => 'ITEM-Z1',
            'order_qty'            => 10,
            'qty_on_shipments'     => 0,
            'unit_price'           => 100,
            'unfilled_reason_code' => 'inventory_shortage',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id'       => $orderZ2->id,
            'inventory_id'         => 'ITEM-Z2',
            'order_qty'            => 20,
            'qty_on_shipments'     => 0,
            'unit_price'           => 100,
            'unfilled_reason_code' => 'quality_hold',
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id'        => $orderZ1->id,
            'order_nbr'             => 'SO-Z1',
            'customer_acumatica_id' => 'CUST-Z1',
            'status'                => 'Open',
            'total_ordered_qty'     => 10,
            'total_shipped_qty'     => 0,
            'fill_rate_pct'         => 0,
            'fill_rate_status'      => 'critical',
            'revenue_not_shipped'   => 1000,
            'computed_at'           => now(),
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id'        => $orderZ2->id,
            'order_nbr'             => 'SO-Z2',
            'customer_acumatica_id' => 'CUST-Z2',
            'status'                => 'Open',
            'total_ordered_qty'     => 20,
            'total_shipped_qty'     => 0,
            'fill_rate_pct'         => 0,
            'fill_rate_status'      => 'critical',
            'revenue_not_shipped'   => 2000,
            'computed_at'           => now(),
        ]);

        $from = now()->startOfMonth()->toDateString();
        $to   = now()->toDateString();

        $this->actingAs($user)
            ->getJson("/api/operations/business-optimization?date_from={$from}&date_to={$to}&shipping_zone_id=Z001")
            ->assertOk()
            ->assertJsonPath('filters.selected_shipping_zone_id', 'Z001')
            ->assertJsonPath('filters.selected_shipping_zone_id', 'Z001')
            ->assertJsonPath('revenue_bleeding.backorder_revenue_at_risk', 1000)
            ->assertJsonPath('revenue_bleeding.fill_rate_not_shipped', 1000)
            ->assertJsonPath('revenue_bleeding.zero_qty_on_shipments_lines', 1)
            ->assertJsonPath('charts.backorders_by_reason.0.reason_code', 'supplier_delay')
            ->assertJsonPath('charts.fill_rate_unfilled_reasons.0.reason_code', 'inventory_shortage');

        $this->actingAs($user)
            ->getJson("/api/operations/business-optimization?date_from={$from}&date_to={$to}&shipping_zone_id=Z999")
            ->assertOk()
            ->assertJsonPath('filters.selected_shipping_zone_id', 'Z999')
            ->assertJsonPath('revenue_bleeding.backorder_revenue_at_risk', 0)
            ->assertJsonPath('revenue_bleeding.fill_rate_not_shipped', 0)
            ->assertJsonPath('revenue_bleeding.zero_qty_on_shipments_lines', 0)
            ->assertJsonPath('charts.backorders_by_reason', [])
            ->assertJsonPath('charts.fill_rate_unfilled_reasons', []);
    }

    public function test_operations_inventory_list_returns_synced_items(): void
    {
        $user = User::factory()->create();
        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-XYZ',
            'description'  => 'Test item',
            'qty_on_hand'  => 42,
            'synced_at'    => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/inventory')
            ->assertOk()
            ->assertJsonPath('data.0.inventory_id', 'ITEM-XYZ');
    }

    public function test_backorders_list_enriches_product_and_customer_names(): void
    {
        $user = User::factory()->create();

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-BO',
            'description'  => 'Backorder Widget',
            'qty_on_hand'  => 5,
            'synced_at'    => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-BO',
            'name'         => 'Backorder Buyer Ltd',
            'synced_at'    => now(),
        ]);

        AcumaticaBackorderLine::create([
            'order_nbr'             => 'SO-BO-1',
            'inventory_id'          => 'ITEM-BO',
            'customer_acumatica_id' => 'CUST-BO',
            'customer_name'         => null,
            'order_qty'             => 10,
            'shipped_qty'           => 2,
            'open_qty'              => 8,
            'revenue_at_risk'       => 800,
            'synced_at'             => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders')
            ->assertOk()
            ->assertJsonPath('data.0.product_name', 'Backorder Widget')
            ->assertJsonPath('data.0.customer_name', 'Backorder Buyer Ltd')
            ->assertJsonPath('data.0.qty_on_hand', '5.0000')
            ->assertJsonPath('data.0.stock_shortfall', true);
    }

    public function test_inventory_stocks_only_updates_existing_items(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null)->andReturn([
            [
                'InventoryID' => ['value' => 'ITEM-STK'],
                'DefaultUOM'  => ['value' => 'EA'],
                'QtyOnHand'   => ['value' => 99],
            ],
            [
                'InventoryID' => ['value' => 'ITEM-NEW'],
                'QtyOnHand'   => ['value' => 50],
            ],
        ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-STK',
            'description'  => 'Existing item',
            'qty_on_hand'  => 0,
            'default_uom'  => null,
            'synced_at'    => now()->subDay(),
        ]);

        $service = $this->inventoryService($client);
        $run = $service->runStocksOnly();

        $this->assertSame('completed', $run->status);
        $this->assertSame('inventory_stocks', $run->sync_type);
        $this->assertSame(1, $run->success_count);
        $this->assertSame(1, $run->filters['skipped_unknown']);
        $this->assertDatabaseHas('acumatica_inventory_items', [
            'inventory_id' => 'ITEM-STK',
            'qty_on_hand'  => 99,
            'default_uom'  => 'EA',
        ]);
        $this->assertDatabaseMissing('acumatica_inventory_items', ['inventory_id' => 'ITEM-NEW']);
    }

    public function test_inventory_stocks_only_imports_selected_warehouse_quantity(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, 'FGS', null)->andReturn([
            [
                'InventoryID' => ['value' => 'ITEM-FGS'],
                'DefaultUOM'  => ['value' => 'EA'],
                'DefaultWarehouseID' => ['value' => 'FGS'],
                'WarehouseDetails' => [
                    [
                        'WarehouseID' => ['value' => 'DTC'],
                        'QtyOnHand' => ['value' => 300],
                    ],
                    [
                        'WarehouseID' => ['value' => 'FGS'],
                        'QtyOnHand' => ['value' => 2000],
                    ],
                    [
                        'WarehouseID' => ['value' => 'PRMS'],
                        'QtyOnHand' => ['value' => 75],
                    ],
                ],
            ],
        ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-FGS',
            'description'  => 'Existing item',
            'qty_on_hand'  => 0,
            'default_uom'  => null,
            'synced_at'    => now()->subDay(),
        ]);

        $run = $this->inventoryService($client)->runStocksOnly(filters: ['warehouse_id' => 'FGS']);

        $this->assertSame('completed', $run->status);
        $this->assertSame('FGS', $run->filters['warehouse_id']);
        $this->assertDatabaseHas('acumatica_inventory_items', [
            'inventory_id' => 'ITEM-FGS',
            'default_warehouse_id' => 'FGS',
            'qty_on_hand' => 2000,
        ]);
    }

    public function test_inventory_sync_ignores_stale_running_lock_and_stops_when_requested(): void
    {
        AcumaticaSyncLog::create([
            'sync_type' => 'inventory',
            'started_at' => now()->subMinutes(10),
            'heartbeat_at' => now()->subMinutes(10),
            'status' => 'running',
            'record_count' => 0,
            'success_count' => 0,
            'failed_count' => 0,
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null)->andReturnUsing(function () {
            AcumaticaSyncLog::query()
                ->where('sync_type', 'inventory')
                ->where('status', 'running')
                ->latest('id')
                ->first()
                ?->update(['stop_requested_at' => now()]);

            return [
                [
                    'InventoryID' => ['value' => 'ITEM-STOP'],
                    'Description' => ['value' => 'Stop widget'],
                    'QtyOnHand' => ['value' => 5],
                ],
            ];
        });

        $service = $this->inventoryService($client);
        $run = $service->run();

        $this->assertSame('stopped', $run->status);
        $this->assertSame('Sync stopped by user.', $run->error_message);
        $this->assertDatabaseHas('acumatica_sync_logs', [
            'sync_type' => 'inventory',
            'status' => 'failed',
            'error_message' => 'Sync ended unexpectedly after losing its runtime heartbeat.',
        ]);
        $this->assertDatabaseMissing('acumatica_inventory_items', ['inventory_id' => 'ITEM-STOP']);
    }

    public function test_backorder_sync_imports_reason_codes_and_notes_from_acumatica(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllOpenSalesOrdersForBackorders')->once()->andReturn([
            [
                'OrderNbr' => ['value' => 'SO-BO-ERP'],
                'CustomerID' => ['value' => 'CUST-ERP'],
                'CustomerName' => ['value' => 'ERP Buyer'],
                'CurrencyID' => ['value' => 'KES'],
                'RequestedOn' => ['value' => '2026-06-20'],
                'Details' => [
                    [
                        'InventoryID' => ['value' => 'ITEM-ERP'],
                        'OrderQty' => ['value' => 12],
                        'ShippedQty' => ['value' => 4],
                        'OpenQty' => ['value' => 8],
                        'UnitPrice' => ['value' => 125],
                        'WarehouseID' => ['value' => 'MAIN'],
                        'ReasonCode' => ['value' => 'SUPPLIER_DELAY'],
                        'ReasonDescription' => ['value' => 'Supplier shipment delayed at origin.'],
                    ],
                ],
            ],
        ]);

        $service = new AcumaticaBackorderSyncService($client);
        $run = $service->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['reason_codes_imported']);
        $this->assertSame(1, $run->filters['reason_notes_imported']);
        $this->assertSame(0, $run->filters['missing_reason_codes']);
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO-BO-ERP',
            'inventory_id' => 'ITEM-ERP',
            'reason_code' => 'SUPPLIER_DELAY',
            'reason_notes' => 'Supplier shipment delayed at origin.',
        ]);
    }

    public function test_backorders_analytics_and_reason_updates_support_operational_workflows(): void
    {
        $user = User::factory()->create([
            'role' => 'Sales Operations',
        ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-AN-1',
            'description' => 'Analytics Widget',
            'item_class' => 'Trading',
            'default_warehouse_id' => 'MAIN',
            'qty_on_hand' => 3,
            'synced_at' => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-AN-1',
            'name' => 'Analytics Customer',
            'customer_class' => 'Consumer sales',
            'synced_at' => now(),
        ]);

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-AN-1',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-AN-1',
            'customer_name' => 'Analytics Customer',
            'status' => 'Open',
            'order_date' => '2026-06-12',
            'synced_at' => now(),
        ]);

        $line = AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-AN-1',
            'inventory_id' => 'ITEM-AN-1',
            'customer_acumatica_id' => 'CUST-AN-1',
            'customer_name' => 'Analytics Customer',
            'order_qty' => 12,
            'shipped_qty' => 2,
            'open_qty' => 10,
            'backorder_qty' => 10,
            'fulfillment_status' => 'Backorders Imported',
            'reason_code' => 'supplier_delay',
            'reason_notes' => 'Vendor shipment missed dispatch window.',
            'warehouse_id' => 'MAIN',
            'unit_price' => 150,
            'revenue_at_risk' => 1500,
            'requested_on' => '2026-06-20',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders/analytics?date_from=2026-06-01&date_to=2026-06-30&product_line=Trading&warehouse_id=MAIN&reason_code=supplier_delay')
            ->assertOk()
            ->assertJsonPath('summary.open_lines', 1)
            ->assertJsonPath('excel_summary.totals.back_order_value', 1500)
            ->assertJsonPath('excel_summary.by_customer_group.0.customer_group', 'Consumer sales')
            ->assertJsonPath('excel_summary.by_customer_group.0.contribution_pct', 100)
            ->assertJsonPath('charts.category_distribution.0.product_line', 'Trading')
            ->assertJsonPath('charts.customer_group_distribution.0.customer_group', 'Consumer sales')
            ->assertJsonPath('charts.reason_distribution.0.reason_code', 'supplier_delay');

        $this->actingAs($user)
            ->patchJson('/api/operations/backorders/'.$line->id, [
                'reason_code' => 'logistics_disruption',
                'reason_notes' => 'Cross-dock delay at the main warehouse.',
            ])
            ->assertOk()
            ->assertJsonPath('reason_code', 'logistics_disruption')
            ->assertJsonPath('reason_notes', 'Cross-dock delay at the main warehouse.');

        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'id' => $line->id,
            'reason_code' => 'logistics_disruption',
        ]);
    }


    public function test_fill_rate_list_enriches_customer_and_product_names(): void
    {
        $user = User::factory()->create();

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-FR',
            'description'  => 'Fill Rate Gadget',
            'qty_on_hand'  => 20,
            'synced_at'    => now(),
        ]);

        AcumaticaShippingZone::create([
            'acumatica_id' => 'Z005',
            'description' => 'Nairobi Zone',
            'synced_at' => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-FR',
            'name'         => 'Fill Rate Customer',
            'shipping_zone_id' => 'Z005',
            'synced_at'    => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-FR-1',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-FR',
            'customer_name'         => null,
            'status'                => 'Open',
            'order_date'            => now()->subHours(30),
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id'   => $order->id,
            'line_nbr'         => 1,
            'inventory_id'     => 'ITEM-FR',
            'description'      => 'Line fallback name',
            'order_qty'        => 10,
            'shipped_qty'      => 7,
            'qty_on_shipments' => 7,
            'open_qty'         => 3,
            'unit_price'       => 20,
            'uom'              => 'CS',
            'fill_rate_pct'    => 70,
        ]);

        AcumaticaFillRateSnapshot::create([
            'sales_order_id'        => $order->id,
            'order_nbr'             => 'SO-FR-1',
            'customer_acumatica_id' => 'CUST-FR',
            'status'                => 'Open',
            'total_ordered_qty'     => 10,
            'total_shipped_qty'     => 7,
            'fill_rate_pct'         => 70,
            'fill_rate_status'      => 'critical',
            'computed_at'           => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/fill-rate')
            ->assertOk()
            ->assertJsonPath('data.0.customer_name', 'Fill Rate Customer')
            ->assertJsonPath('data.0.products.0.inventory_id', 'ITEM-FR')
            ->assertJsonPath('data.0.products.0.product_name', 'Fill Rate Gadget')
            ->assertJsonPath('data.0.products.0.unit_price', '20.0000')
            ->assertJsonPath('data.0.products.0.uom', 'CS')
            ->assertJsonPath('data.0.products.0.not_shipped_value', '60.00')
            ->assertJsonPath('data.0.delivery_sla_status', 'breach')
            ->assertJsonPath('data.0.shipping_zone_description', 'Nairobi Zone')
            ->assertJsonPath('data.0.is_metro_zone', true);

        $this->actingAs($user)
            ->getJson('/api/operations/fill-rate/summary?date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('delivery_sla_breach_count', 1)
            ->assertJsonPath('delivery_sla_rules.metro_sla_hours', 24)
            ->assertJsonPath('excel_summary.totals.ordered_qty', 10)
            ->assertJsonPath('excel_summary.totals.actual_qty', 7)
            ->assertJsonPath('excel_summary.totals.undershipped_value', 0);
    }

    public function test_fill_rate_list_filters_by_shipping_zone(): void
    {
        $user = User::factory()->create();

        AcumaticaShippingZone::create([
            'acumatica_id' => 'Z005',
            'description' => 'Nairobi Zone',
            'synced_at' => now(),
        ]);
        AcumaticaShippingZone::create([
            'acumatica_id' => 'Z010',
            'description' => 'Mombasa Zone',
            'synced_at' => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-NRB',
            'name' => 'Nairobi Customer',
            'shipping_zone_id' => 'Z005',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-MSA',
            'name' => 'Mombasa Customer',
            'shipping_zone_id' => 'Z010',
            'synced_at' => now(),
        ]);

        AcumaticaFillRateSnapshot::create([
            'order_nbr' => 'SO-NRB-1',
            'customer_acumatica_id' => 'CUST-NRB',
            'fill_rate_pct' => 60,
            'fill_rate_status' => 'critical',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 6,
            'computed_at' => now(),
        ]);
        AcumaticaFillRateSnapshot::create([
            'order_nbr' => 'SO-MSA-1',
            'customer_acumatica_id' => 'CUST-MSA',
            'fill_rate_pct' => 90,
            'fill_rate_status' => 'healthy',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 9,
            'computed_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/fill-rate?shipping_zone_id=Z005')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_nbr', 'SO-NRB-1');

        $this->actingAs($user)
            ->getJson('/api/operations/fill-rate/summary?date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString().'&shipping_zone_id=Z010')
            ->assertOk()
            ->assertJsonPath('order_count', 1)
            ->assertJsonPath('healthy_count', 1)
            ->assertJsonPath('filters.shipping_zones.0.acumatica_id', 'Z005');
    }

    public function test_fill_rate_list_filters_critical_and_sorts_high_to_low(): void
    {
        $user = User::factory()->create();

        AcumaticaFillRateSnapshot::create([
            'order_nbr'         => 'SO-FR-HIGH',
            'fill_rate_pct'     => 75,
            'fill_rate_status'  => 'critical',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 7.5,
            'computed_at'       => now(),
        ]);

        AcumaticaFillRateSnapshot::create([
            'order_nbr'         => 'SO-FR-LOW',
            'fill_rate_pct'     => 40,
            'fill_rate_status'  => 'critical',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 4,
            'computed_at'       => now(),
        ]);

        AcumaticaFillRateSnapshot::create([
            'order_nbr'         => 'SO-FR-OK',
            'fill_rate_pct'     => 98,
            'fill_rate_status'  => 'healthy',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 9.8,
            'computed_at'       => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/fill-rate?status=critical&sort=high_to_low')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.order_nbr', 'SO-FR-HIGH')
            ->assertJsonPath('data.1.order_nbr', 'SO-FR-LOW');

        $this->actingAs($user)
            ->getJson('/api/operations/fill-rate?status=critical&sort=low_to_high')
            ->assertOk()
            ->assertJsonPath('data.0.order_nbr', 'SO-FR-LOW')
            ->assertJsonPath('data.1.order_nbr', 'SO-FR-HIGH');
    }

    private function inventoryService(AcumaticaClient $client): AcumaticaInventorySyncService
    {
        return new AcumaticaInventorySyncService(
            $client,
            new InventoryRunRatePredictor,
            new ProductBrandClassifier,
        );
    }
}
