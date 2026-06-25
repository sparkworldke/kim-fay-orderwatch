<?php

namespace Tests\Feature;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
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
}