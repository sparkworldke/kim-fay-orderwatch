<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AcumaticaOperationsSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_sync_upserts_and_logs_run_rate(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0)->andReturn([
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

        $service = new AcumaticaInventorySyncService($client, new InventoryRunRatePredictor);

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
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0)->andReturn([
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

        $service = new AcumaticaInventorySyncService($client, new InventoryRunRatePredictor);
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

    public function test_fill_rate_sync_computes_snapshot(): void
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
                        'InventoryID' => ['value' => 'ITEM-002'],
                        'OrderQty'    => ['value' => 10],
                        'ShippedQty'  => ['value' => 5],
                        'OpenQty'     => ['value' => 5],
                        'UnitPrice'   => ['value' => 20],
                    ],
                ],
            ],
        ]);

        $service = new AcumaticaFillRateSyncService($client, new FillRateCalculator);
        $run = $service->syncDateRange('2026-06-01', '2026-06-30');

        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('acumatica_fill_rate_snapshots', [
            'order_nbr'        => 'SO200',
            'fill_rate_pct'    => 50,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 100,
        ]);
    }

    public function test_operations_endpoints_require_auth(): void
    {
        $this->getJson('/api/operations/inventory')->assertUnauthorized();
        $this->getJson('/api/operations/backorders')->assertUnauthorized();
        $this->getJson('/api/operations/fill-rate')->assertUnauthorized();
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
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0)->andReturn([
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

        $service = new AcumaticaInventorySyncService($client, new InventoryRunRatePredictor);
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

    public function test_fill_rate_list_enriches_customer_and_product_names(): void
    {
        $user = User::factory()->create();

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-FR',
            'description'  => 'Fill Rate Gadget',
            'qty_on_hand'  => 20,
            'synced_at'    => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-FR',
            'name'         => 'Fill Rate Customer',
            'synced_at'    => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-FR-1',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-FR',
            'customer_name'         => null,
            'status'                => 'Open',
            'order_date'            => now(),
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_nbr'       => 1,
            'inventory_id'   => 'ITEM-FR',
            'description'    => 'Line fallback name',
            'order_qty'      => 10,
            'shipped_qty'    => 7,
            'open_qty'       => 3,
            'unit_price'     => 20,
            'uom'            => 'CS',
            'fill_rate_pct'  => 70,
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
            ->assertJsonPath('data.0.products.0.not_shipped_value', '60.00');
    }
}