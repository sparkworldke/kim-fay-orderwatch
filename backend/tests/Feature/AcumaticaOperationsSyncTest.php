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
use App\Models\Department;
use App\Models\User;
use App\Models\UserCustomerAssignment;
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

    public function test_operations_lists_include_explicitly_assigned_customer_when_acumatica_rep_differs(): void
    {
        $department = Department::query()->create([
            'slug' => 'mt_consumer_sales',
            'name' => 'MT / Consumer Sales',
            'is_customer_facing' => true,
            'sort_order' => 1,
        ]);
        $user = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'REP-LOCAL',
            'is_consultant' => true,
            'is_super_admin' => false,
            'data_scope_mode' => 'scoped',
            'department_id' => $department->id,
        ]);

        foreach (['ASSIGNED-OPS', 'HIDDEN-OPS'] as $customerId) {
            AcumaticaCustomer::query()->create([
                'acumatica_id' => $customerId,
                'name' => $customerId,
                'customer_class' => 'MT-CHAIN',
            ]);
        }
        UserCustomerAssignment::query()->create([
            'user_id' => $user->id,
            'customer_acumatica_id' => 'ASSIGNED-OPS',
            'assignment_type' => UserCustomerAssignment::TYPE_SERVICING,
        ]);

        foreach (['ASSIGNED-OPS', 'HIDDEN-OPS'] as $index => $customerId) {
            AcumaticaBackorderLine::query()->create([
                'order_nbr' => 'SO-BO-SCOPE-'.$index,
                'inventory_id' => 'ITEM-SCOPE-'.$index,
                'customer_acumatica_id' => $customerId,
                'order_qty' => 10,
                'open_qty' => 5,
                'revenue_at_risk' => 50,
                'first_backordered_at' => now(),
            ]);
            $order = AcumaticaSalesOrder::query()->create([
                'acumatica_order_nbr' => 'SO-FR-SCOPE-'.$index,
                'order_type' => 'SO',
                'customer_acumatica_id' => $customerId,
                'customer_name' => $customerId,
                'status' => 'Completed',
                'sales_consultant_rep_code' => 'REP-SOMEONE-ELSE',
            ]);
            AcumaticaFillRateSnapshot::query()->create([
                'sales_order_id' => $order->id,
                'order_nbr' => $order->acumatica_order_nbr,
                'customer_acumatica_id' => $customerId,
                'status' => 'Completed',
                'total_ordered_qty' => 10,
                'total_shipped_qty' => 5,
                'fill_rate_pct' => 50,
                'fill_rate_status' => 'critical',
                'computed_at' => now(),
            ]);
        }

        $this->actingAs($user)->getJson('/api/operations/backorders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_acumatica_id', 'ASSIGNED-OPS');
        $this->actingAs($user)->getJson('/api/operations/fill-rate')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer_acumatica_id', 'ASSIGNED-OPS');
    }

    public function test_inventory_sync_upserts_and_logs_run_rate(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null, true)->andReturn([
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

    public function test_backorder_resolution_is_archived_when_line_clears(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllOpenSalesOrdersForBackorders')
            ->twice()
            ->andReturn(
                [
                    [
                        'OrderNbr'     => ['value' => 'SO200'],
                        'Status'       => ['value' => 'Open'],
                        'CustomerID'   => ['value' => 'CUST02'],
                        'CustomerName' => ['value' => 'Beta Co'],
                        'CurrencyID'   => ['value' => 'KES'],
                        'DocumentDetails' => [
                            [
                                'InventoryID' => ['value' => 'ITEM-002'],
                                'OrderQty'    => ['value' => 5],
                                'ShippedQty'  => ['value' => 2],
                                'OpenQty'     => ['value' => 3],
                                'UnitPrice'   => ['value' => 50],
                            ],
                        ],
                    ],
                    [
                        'OrderNbr'     => ['value' => 'SO300'],
                        'Status'       => ['value' => 'Open'],
                        'CustomerID'   => ['value' => 'CUST03'],
                        'CustomerName' => ['value' => 'Gamma Ltd'],
                        'CurrencyID'   => ['value' => 'KES'],
                        'DocumentDetails' => [
                            [
                                'InventoryID' => ['value' => 'ITEM-003'],
                                'OrderQty'    => ['value' => 4],
                                'ShippedQty'  => ['value' => 2],
                                'OpenQty'     => ['value' => 2],
                                'UnitPrice'   => ['value' => 20],
                            ],
                        ],
                    ],
                ],
                // Second sync: SO200 no longer appears in the open-orders fetch at all
                // (fully shipped / order completed) — SO300 stays open.
                [
                    [
                        'OrderNbr'     => ['value' => 'SO300'],
                        'Status'       => ['value' => 'Open'],
                        'CustomerID'   => ['value' => 'CUST03'],
                        'CustomerName' => ['value' => 'Gamma Ltd'],
                        'CurrencyID'   => ['value' => 'KES'],
                        'DocumentDetails' => [
                            [
                                'InventoryID' => ['value' => 'ITEM-003'],
                                'OrderQty'    => ['value' => 4],
                                'ShippedQty'  => ['value' => 2],
                                'OpenQty'     => ['value' => 2],
                                'UnitPrice'   => ['value' => 20],
                            ],
                        ],
                    ],
                ],
            );

        $service = new AcumaticaBackorderSyncService($client, new \App\Services\Operations\SalesOrderReasonCatalog());

        $service->run();
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO200',
            'inventory_id' => 'ITEM-002',
        ]);

        // Backdate so days_to_resolve is deterministic rather than 0.
        AcumaticaBackorderLine::where('order_nbr', 'SO200')->update([
            'first_backordered_at' => now()->subDays(4),
        ]);

        $secondRun = $service->run();
        $this->assertSame('completed', $secondRun->status);

        $this->assertDatabaseMissing('acumatica_backorder_lines', [
            'order_nbr' => 'SO200',
            'inventory_id' => 'ITEM-002',
        ]);
        // SO300 never resolved — it must not be archived.
        $this->assertDatabaseMissing('backorder_resolutions', ['order_nbr' => 'SO300']);

        $this->assertDatabaseHas('backorder_resolutions', [
            'order_nbr' => 'SO200',
            'inventory_id' => 'ITEM-002',
            'customer_acumatica_id' => 'CUST02',
            'customer_name' => 'Beta Co',
            'revenue_at_risk' => 150,
            'days_to_resolve' => 4,
            'first_backordered_at_is_backfilled' => false,
        ]);
        $this->assertNotNull(
            \App\Models\BackorderResolution::where('order_nbr', 'SO200')->value('resolved_at'),
        );
    }

    public function test_backorders_and_resolved_backorders_filter_by_rep_code(): void
    {
        $user = User::factory()->create();

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-REPA',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-REPA',
            'customer_name' => 'Rep A Customer',
            'status' => 'Open',
            'order_date' => '2026-06-12',
            'sales_consultant_rep_code' => 'REPA',
            'synced_at' => now(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-REPB',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-REPB',
            'customer_name' => 'Rep B Customer',
            'status' => 'Open',
            'order_date' => '2026-06-12',
            'sales_consultant_rep_code' => 'REPB',
            'synced_at' => now(),
        ]);

        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-REPA',
            'inventory_id' => 'ITEM-REPA',
            'customer_acumatica_id' => 'CUST-REPA',
            'customer_name' => 'Rep A Customer',
            'order_qty' => 5,
            'shipped_qty' => 1,
            'open_qty' => 4,
            'backorder_qty' => 4,
            'fulfillment_status' => 'Backorders Imported',
            'unit_price' => 100,
            'revenue_at_risk' => 400,
            'synced_at' => now(),
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-REPB',
            'inventory_id' => 'ITEM-REPB',
            'customer_acumatica_id' => 'CUST-REPB',
            'customer_name' => 'Rep B Customer',
            'order_qty' => 5,
            'shipped_qty' => 1,
            'open_qty' => 4,
            'backorder_qty' => 4,
            'fulfillment_status' => 'Backorders Imported',
            'unit_price' => 100,
            'revenue_at_risk' => 400,
            'synced_at' => now(),
        ]);

        \App\Models\BackorderResolution::create([
            'order_nbr' => 'SO-REPA',
            'inventory_id' => 'ITEM-REPA-OLD',
            'customer_acumatica_id' => 'CUST-REPA',
            'unit_price' => 50,
            'revenue_at_risk' => 200,
            'resolved_at' => now(),
        ]);
        \App\Models\BackorderResolution::create([
            'order_nbr' => 'SO-REPB',
            'inventory_id' => 'ITEM-REPB-OLD',
            'customer_acumatica_id' => 'CUST-REPB',
            'unit_price' => 50,
            'revenue_at_risk' => 200,
            'resolved_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders?rep_code=REPA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_nbr', 'SO-REPA');

        $this->actingAs($user)
            ->getJson('/api/operations/backorders/resolved?rep_code=REPA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_nbr', 'SO-REPA');
    }

    public function test_resolved_backorders_endpoint_returns_enriched_history(): void
    {
        $user = User::factory()->create();

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-RES',
            'description'  => 'Resolved Widget',
            'brand'        => 'Fay Tissues',
            'qty_on_hand'  => 5,
            'synced_at'    => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-RES',
            'name'         => 'Resolved Buyer Ltd',
            'synced_at'    => now(),
        ]);

        \App\Models\BackorderResolution::create([
            'order_nbr'             => 'SO-RES-1',
            'inventory_id'          => 'ITEM-RES',
            'customer_acumatica_id' => 'CUST-RES',
            'customer_name'         => null,
            'reason_code'           => 'delay_in_delivery',
            'unit_price'            => 100,
            'revenue_at_risk'       => 300,
            'order_qty'             => 10,
            'last_open_qty'         => 3,
            'last_backorder_qty'    => 3,
            'first_backordered_at'  => now()->subDays(10),
            'first_backordered_at_is_backfilled' => false,
            'resolved_at'           => now()->subDays(2),
            'days_to_resolve'       => 8,
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders/resolved')
            ->assertOk()
            ->assertJsonPath('data.0.order_nbr', 'SO-RES-1')
            ->assertJsonPath('data.0.product_name', 'Resolved Widget')
            ->assertJsonPath('data.0.brand', 'Fay Tissues')
            ->assertJsonPath('data.0.customer_name', 'Resolved Buyer Ltd')
            ->assertJsonPath('data.0.days_to_resolve', 8);
    }

    public function test_inventory_sync_skips_inactive_items_without_counting_as_failed(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null, true)->andReturn([
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

        $service = new AcumaticaBackorderSyncService($client, new \App\Services\Operations\SalesOrderReasonCatalog());
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
            'first_backordered_at_is_backfilled' => false,
        ]);
        $this->assertNotNull(AcumaticaBackorderLine::where('order_nbr', 'SO100')->value('first_backordered_at'));
    }

    public function test_date_range_backorder_sync_skips_rejected_and_prunes_period_lines(): void
    {
        // Stale active line on a rejected SO should be cleared when the period is re-synced.
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-REJECT',
            'inventory_id' => 'ITEM-R',
            'order_qty' => 5,
            'open_qty' => 5,
            'unit_price' => 10,
            'revenue_at_risk' => 50,
            'shortfall_kind' => 'active_backorder',
            'synced_at' => now(),
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-SHIP',
            'inventory_id' => 'ITEM-OLD',
            'order_qty' => 2,
            'open_qty' => 2,
            'unit_price' => 10,
            'revenue_at_risk' => 20,
            'shortfall_kind' => 'active_backorder',
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllSalesOrdersByDateRange')
            ->once()
            ->with('2026-07-22', '2026-07-25', Mockery::any())
            ->andReturn([
                [
                    'OrderNbr' => ['value' => 'SO-REJECT'],
                    'Status' => ['value' => 'Rejected'],
                    'CustomerID' => ['value' => 'C1'],
                    'Details' => [[
                        'InventoryID' => ['value' => 'ITEM-R'],
                        'OrderQty' => ['value' => 5],
                        'OpenQty' => ['value' => 5],
                        'UnitPrice' => ['value' => 10],
                    ]],
                ],
                [
                    'OrderNbr' => ['value' => 'SO-SHIP'],
                    'Status' => ['value' => 'Shipping'],
                    'CustomerID' => ['value' => 'C2'],
                    'Details' => [[
                        'InventoryID' => ['value' => 'ITEM-S'],
                        'OrderQty' => ['value' => 10],
                        'ShippedQty' => ['value' => 4],
                        'QtyOnShipments' => ['value' => 4],
                        'OpenQty' => ['value' => 6],
                        'UnitPrice' => ['value' => 50],
                    ]],
                ],
            ]);

        $service = new AcumaticaBackorderSyncService($client, new \App\Services\Operations\SalesOrderReasonCatalog());
        $run = $service->run(null, 'manual', null, '2026-07-22', '2026-07-25');

        $this->assertSame('completed', $run->status);
        $this->assertDatabaseMissing('acumatica_backorder_lines', [
            'order_nbr' => 'SO-REJECT',
            'inventory_id' => 'ITEM-R',
        ]);
        $this->assertDatabaseMissing('acumatica_backorder_lines', [
            'order_nbr' => 'SO-SHIP',
            'inventory_id' => 'ITEM-OLD',
        ]);
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO-SHIP',
            'inventory_id' => 'ITEM-S',
            'open_qty' => 6,
            'revenue_at_risk' => 300,
            'shortfall_kind' => 'active_backorder',
        ]);
        $this->assertSame(1, $run->filters['open_orders_for_active_backorders'] ?? null);
        $this->assertSame(2, $run->filters['orders_in_range'] ?? null);
    }

    public function test_active_open_fetch_date_range_skips_full_pull_and_completed_recon(): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SHIP',
            'order_type' => 'SO',
            'status' => 'Shipping',
            'order_date' => '2026-07-23',
            'customer_acumatica_id' => 'C2',
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-SHIP',
            'inventory_id' => 'ITEM-OLD',
            'order_qty' => 2,
            'open_qty' => 2,
            'unit_price' => 10,
            'revenue_at_risk' => 20,
            'shortfall_kind' => 'active_backorder',
            'synced_at' => now(),
        ]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllOpenSalesOrdersForBackordersByDateRange')
            ->once()
            ->with('2026-07-22', '2026-07-25', Mockery::any())
            ->andReturn([[
                'OrderNbr' => ['value' => 'SO-SHIP'],
                'Status' => ['value' => 'Shipping'],
                'CustomerID' => ['value' => 'C2'],
                'Details' => [[
                    'InventoryID' => ['value' => 'ITEM-S'],
                    'OrderQty' => ['value' => 10],
                    'OpenQty' => ['value' => 6],
                    'UnitPrice' => ['value' => 50],
                ]],
            ]]);
        $client->shouldNotReceive('fetchAllSalesOrdersByDateRange');

        $service = new AcumaticaBackorderSyncService($client, new \App\Services\Operations\SalesOrderReasonCatalog());
        $run = $service->run(null, 'manual', null, '2026-07-22', '2026-07-25', [
            'active_open_fetch' => true,
            'skip_completed_recon' => true,
        ]);

        $this->assertSame('completed', $run->status);
        $this->assertSame('active_open_fetch', $run->filters['mode'] ?? null);
        $this->assertTrue((bool) ($run->filters['completed_invoice_reconciliation']['skipped'] ?? false));
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO-SHIP',
            'inventory_id' => 'ITEM-S',
            'open_qty' => 6,
            'revenue_at_risk' => 300,
        ]);
        $this->assertDatabaseMissing('acumatica_backorder_lines', [
            'order_nbr' => 'SO-SHIP',
            'inventory_id' => 'ITEM-OLD',
        ]);
    }

    public function test_fill_rate_sync_computes_snapshot_from_qty_on_shipments(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchOrdersForFillRate')->once()->andReturn([
            [
                'OrderNbr'   => ['value' => 'SO200'],
                'Status'     => ['value' => 'Completed'],
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
        $client->shouldReceive('fetchSalesInvoicesForSalesOrders')->once()->andReturn([[
            'Released' => ['value' => true],
            'Details' => [[
                'OrderNbr' => ['value' => 'SO200'],
                'InventoryID' => ['value' => 'ITEM-002'],
                'Qty' => ['value' => 5],
            ]],
        ]]);

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
            'out_of_stock_line_count' => 1,
        ]);
    }

    public function test_fill_rate_sync_marks_out_of_stock_lines_and_guardrails(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchOrdersForFillRate')->once()->andReturn([
            [
                'OrderNbr'   => ['value' => 'SO359765'],
                'Status'     => ['value' => 'Completed'],
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
        $client->shouldReceive('fetchSalesInvoicesForSalesOrders')->once()->andReturn([[
            'Released' => ['value' => true],
            'Details' => [[
                'OrderNbr' => ['value' => 'SO359765'],
                'InventoryID' => ['value' => 'FAYWP0025'],
                'Qty' => ['value' => 5],
            ]],
        ]]);

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
            'reason_code'           => 'delay_in_delivery',
            'synced_at'             => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-OPT',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-OPT',
            'status'                => 'Completed',
            'order_date'            => now(),
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id'       => $order->id,
            'inventory_id'         => 'ITEM-OPT',
            'order_qty'            => 20,
            'qty_on_shipments'     => 0,
            'unit_price'           => 75,
            'unfilled_reason_code' => 'out_of_stock_procurement',
        ]);

        AcumaticaFillRateSnapshot::create([
            'order_nbr'             => 'SO-OPT',
            'customer_acumatica_id' => 'CUST-OPT',
            'status'                => 'Completed',
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
            ->assertJsonPath('charts.backorders_by_reason.0.reason_code', 'delay_in_delivery')
            ->assertJsonPath('charts.fill_rate_unfilled_reasons.0.reason_code', 'out_of_stock_procurement');
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
            'reason_code'           => 'delay_in_delivery',
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
            'status'                => 'Completed',
            'order_date'            => now(),
        ]);
        $orderZ2 = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-Z2',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-Z2',
            'status'                => 'Completed',
            'order_date'            => now(),
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id'       => $orderZ1->id,
            'inventory_id'         => 'ITEM-Z1',
            'order_qty'            => 10,
            'qty_on_shipments'     => 0,
            'unit_price'           => 100,
            'unfilled_reason_code' => 'out_of_stock_procurement',
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
            ->assertJsonPath('charts.backorders_by_reason.0.reason_code', 'delay_in_delivery')
            ->assertJsonPath('charts.fill_rate_unfilled_reasons.0.reason_code', 'out_of_stock_procurement');

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

    public function test_inventory_stockout_filter_returns_critical_or_zero_stock(): void
    {
        $user = User::factory()->create();

        $zero = AcumaticaInventoryItem::create([
            'inventory_id'         => 'OOS-ZERO',
            'description'          => 'Zero stock item',
            'qty_on_hand'          => 0,
            'default_warehouse_id' => 'FGS',
            'synced_at'            => now(),
        ]);

        $critical = AcumaticaInventoryItem::create([
            'inventory_id'         => 'OOS-CRIT',
            'description'          => 'Critical prediction item',
            'qty_on_hand'          => 12,
            'default_warehouse_id' => 'FGS',
            'synced_at'            => now(),
        ]);

        $healthy = AcumaticaInventoryItem::create([
            'inventory_id'         => 'OOS-OK',
            'description'          => 'Healthy stock',
            'qty_on_hand'          => 500,
            'default_warehouse_id' => 'MSA',
            'synced_at'            => now(),
        ]);

        AcumaticaInventoryRunRateLog::create([
            'inventory_item_id'   => $critical->id,
            'inventory_id'        => 'OOS-CRIT',
            'qty_on_hand'         => 12,
            'daily_run_rate'      => 4,
            'days_until_stockout' => 3,
            'prediction_status'   => 'critical',
            'logged_at'           => now(),
        ]);

        AcumaticaInventoryRunRateLog::create([
            'inventory_item_id'   => $healthy->id,
            'inventory_id'        => 'OOS-OK',
            'qty_on_hand'         => 500,
            'daily_run_rate'      => 2,
            'days_until_stockout' => 250,
            'prediction_status'   => 'healthy',
            'logged_at'           => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/inventory?stockout_filter=critical_or_oos')
            ->assertOk()
            ->assertJsonFragment(['inventory_id' => 'OOS-ZERO'])
            ->assertJsonFragment(['inventory_id' => 'OOS-CRIT'])
            ->assertJsonMissing(['inventory_id' => 'OOS-OK']);

        $this->actingAs($user)
            ->getJson('/api/operations/inventory?stockout_filter=out_of_stock')
            ->assertOk()
            ->assertJsonFragment(['inventory_id' => 'OOS-ZERO'])
            ->assertJsonMissing(['inventory_id' => 'OOS-CRIT']);

        $this->actingAs($user)
            ->getJson('/api/operations/inventory?stockout_filter=critical_or_oos&warehouse_id[]=FGS')
            ->assertOk()
            ->assertJsonFragment(['inventory_id' => 'OOS-ZERO'])
            ->assertJsonFragment(['inventory_id' => 'OOS-CRIT']);

        $this->actingAs($user)
            ->getJson('/api/operations/inventory/summary')
            ->assertOk()
            ->assertJsonPath('out_of_stock_count', 1)
            ->assertJsonStructure([
                'warehouses' => [
                    ['warehouse_id', 'label', 'sku_count'],
                ],
            ]);

        // Keep static analysis happy that seeded models exist.
        $this->assertNotNull($zero->id);
        $this->assertNotNull($critical->id);
        $this->assertNotNull($healthy->id);
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
            'first_backordered_at'  => now()->subDays(8),
            'first_backordered_at_is_backfilled' => true,
            'synced_at'             => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders')
            ->assertOk()
            ->assertJsonPath('data.0.product_name', 'Backorder Widget')
            ->assertJsonPath('data.0.customer_name', 'Backorder Buyer Ltd')
            ->assertJsonPath('data.0.qty_on_hand', '5.0000')
            ->assertJsonPath('data.0.stock_shortfall', true)
            ->assertJsonPath('data.0.backorder_age_days', 8)
            ->assertJsonPath('data.0.aging_bucket', '8-14')
            ->assertJsonPath('data.0.missing_reason_exception', true)
            ->assertJsonPath('data.0.first_backordered_at_is_backfilled', true);
    }

    public function test_inventory_stocks_only_updates_balances_and_creates_missing_master_items(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchStockItemsForWarehouseBalances')->once()->with(0, 50)->andReturn([
            [
                'InventoryID' => ['value' => 'ITEM-STK'],
                'DefaultUOM'  => ['value' => 'EA'],
                'DefaultWarehouseID' => ['value' => 'FGS'],
                'QtyOnHand'   => ['value' => 99],
            ],
            [
                'InventoryID' => ['value' => 'ITEM-NEW'],
                'DefaultWarehouseID' => ['value' => 'FGS'],
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
        $this->assertSame(2, $run->success_count);
        $this->assertSame(0, $run->filters['skipped_unknown']);
        $this->assertSame(2, $run->filters['balances_saved']);
        $this->assertSame(1, $run->filters['masters_created']);
        $this->assertDatabaseHas('acumatica_inventory_items', [
            'inventory_id' => 'ITEM-STK',
            'default_uom'  => 'EA',
        ]);
        $this->assertDatabaseHas('acumatica_inventory_items', ['inventory_id' => 'ITEM-NEW']);
        $this->assertDatabaseHas('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-STK',
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 99,
        ]);
        $this->assertDatabaseHas('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-NEW',
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 50,
        ]);
    }

    public function test_inventory_stocks_only_imports_selected_warehouse_quantity(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        // Stocks-only warehouse jobs scan all SKUs via WarehouseDetails (no DefaultWarehouseID filter).
        $client->shouldReceive('fetchStockItemsForWarehouseBalances')
            ->once()
            ->with(0, 50)
            ->andReturn([
                [
                    'InventoryID' => ['value' => 'ITEM-FGS'],
                    'DefaultUOM'  => ['value' => 'EA'],
                    'DefaultWarehouseID' => ['value' => 'DTC'],
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
                [
                    // SKU with no FGS site row — must not create a zero FGS balance.
                    'InventoryID' => ['value' => 'ITEM-NO-FGS'],
                    'DefaultWarehouseID' => ['value' => 'MSA'],
                    'WarehouseDetails' => [
                        [
                            'WarehouseID' => ['value' => 'MSA'],
                            'QtyOnHand' => ['value' => 10],
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
        $this->assertDatabaseHas('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-FGS',
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 2000,
        ]);
        $this->assertDatabaseMissing('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-NO-FGS',
            'warehouse_id' => 'FGS',
        ]);
    }

    public function test_inventory_stocks_only_reads_secondary_warehouse_tpfgs_from_details(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchStockItemsForWarehouseBalances')
            ->once()
            ->with(0, 50)
            ->andReturn([
                [
                    'InventoryID' => ['value' => 'COSTP0023'],
                    'DefaultWarehouseID' => ['value' => 'FGS'],
                    'WarehouseDetails' => [
                        [
                            'WarehouseID' => ['value' => 'FGS'],
                            'QtyOnHand' => ['value' => 500],
                        ],
                        [
                            'WarehouseID' => ['value' => 'TPFGS'],
                            'QtyOnHand' => ['value' => 1125],
                        ],
                    ],
                ],
            ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'COSTP0023',
            'description' => 'Tatu FG item',
            'qty_on_hand' => 500,
            'synced_at' => now()->subDay(),
        ]);

        $run = $this->inventoryService($client)->runStocksOnly(filters: ['warehouse_id' => 'TPFGS']);

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->success_count);
        $this->assertSame(1, $run->filters['balances_written']);
        $this->assertDatabaseHas('inventory_warehouse_balances', [
            'inventory_id' => 'COSTP0023',
            'warehouse_id' => 'TPFGS',
            'qty_on_hand' => 1125,
        ]);
        // Targeted TPFGS run must not write FGS from the same page.
        $this->assertDatabaseMissing('inventory_warehouse_balances', [
            'inventory_id' => 'COSTP0023',
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 500,
        ]);
    }

    public function test_inventory_stocks_only_without_warehouse_writes_all_configured_sites(): void
    {
        config(['inventory.warehouses' => ['FGS', 'TPFGS', 'MSA']]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchStockItemsForWarehouseBalances')
            ->once()
            ->with(0, 50)
            ->andReturn([
                [
                    'InventoryID' => ['value' => 'ITEM-MULTI'],
                    'WarehouseDetails' => [
                        ['WarehouseID' => ['value' => 'FGS'], 'QtyOnHand' => ['value' => 10]],
                        ['WarehouseID' => ['value' => 'TPFGS'], 'QtyOnHand' => ['value' => 20]],
                        ['WarehouseID' => ['value' => 'EXPORT'], 'QtyOnHand' => ['value' => 99]], // not in config
                    ],
                ],
            ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'ITEM-MULTI',
            'description' => 'Multi-site',
            'qty_on_hand' => 0,
            'synced_at' => now()->subDay(),
        ]);

        $run = $this->inventoryService($client)->runStocksOnly();

        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->filters['balances_written']);
        $this->assertDatabaseHas('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-MULTI',
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 10,
        ]);
        $this->assertDatabaseHas('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-MULTI',
            'warehouse_id' => 'TPFGS',
            'qty_on_hand' => 20,
        ]);
        $this->assertDatabaseMissing('inventory_warehouse_balances', [
            'inventory_id' => 'ITEM-MULTI',
            'warehouse_id' => 'EXPORT',
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
        $client->shouldReceive('fetchActiveInventoryItems')->once()->with(0, 50, null, null, true)->andReturnUsing(function () {
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

        $service = new AcumaticaBackorderSyncService($client, new \App\Services\Operations\SalesOrderReasonCatalog());
        $run = $service->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->filters['reason_codes_imported']);
        $this->assertSame(1, $run->filters['reason_notes_imported']);
        $this->assertSame(0, $run->filters['missing_reason_codes']);
        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'order_nbr' => 'SO-BO-ERP',
            'inventory_id' => 'ITEM-ERP',
            'reason_code' => 'delay_in_delivery',
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
            'reason_code' => 'delay_in_delivery',
            'reason_notes' => 'Vendor shipment missed dispatch window.',
            'warehouse_id' => 'MAIN',
            'unit_price' => 150,
            'revenue_at_risk' => 1500,
            'requested_on' => '2026-06-20',
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders/analytics?date_from=2026-06-01&date_to=2026-06-30&product_line=Trading&warehouse_id=MAIN&reason_code=delay_in_delivery')
            ->assertOk()
            ->assertJsonPath('summary.open_lines', 1)
            ->assertJsonPath('excel_summary.totals.back_order_value', 1500)
            ->assertJsonPath('excel_summary.by_customer_group.0.customer_group', 'Consumer sales')
            ->assertJsonPath('excel_summary.by_customer_group.0.contribution_pct', 100)
            ->assertJsonPath('charts.category_distribution.0.product_line', 'Trading')
            ->assertJsonPath('charts.customer_group_distribution.0.customer_group', 'Consumer sales')
            ->assertJsonPath('charts.reason_distribution.0.reason_code', 'delay_in_delivery')
            ->assertJsonFragment(['fulfillment_statuses' => [
                'Fully Fulfilled',
                'Backorders Imported',
                'Cancelled',
                'Partially Shipped — Backorder Pending',
                'Pending Shipment',
            ]]);

        $this->actingAs($user)
            ->getJson('/api/operations/backorders?fulfillment_status=Backorders%20Imported')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($user)
            ->getJson('/api/operations/backorders?fulfillment_status=Not%20A%20Status')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fulfillment_status');

        $this->actingAs($user)
            ->patchJson('/api/operations/backorders/'.$line->id, [
                'reason_code' => 'delay_in_delivery',
                'reason_notes' => 'Cross-dock delay at the main warehouse.',
            ])
            ->assertOk()
            ->assertJsonPath('reason_code', 'delay_in_delivery')
            ->assertJsonPath('reason_notes', 'Cross-dock delay at the main warehouse.');

        $this->assertDatabaseHas('acumatica_backorder_lines', [
            'id' => $line->id,
            'reason_code' => 'delay_in_delivery',
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

        $this->ensureShippingZone('Z005', 'Nairobi Zone');

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
            'status'                => 'Completed',
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
            'status'                => 'Completed',
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
            ->assertJsonPath('excel_summary.totals.undershipped_value', 60);
    }

    public function test_fill_rate_list_filters_by_shipping_zone(): void
    {
        $user = User::factory()->create();

        $this->ensureShippingZone('Z005', 'Nairobi Zone');
        $this->ensureShippingZone('Z010', 'Mombasa Zone');

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

        $summary = $this->actingAs($user)
            ->getJson('/api/operations/fill-rate/summary?date_from='.now()->startOfMonth()->toDateString().'&date_to='.now()->toDateString().'&shipping_zone_id=Z010')
            ->assertOk()
            ->assertJsonPath('order_count', 1)
            ->assertJsonPath('healthy_count', 1);

        $zoneIds = collect($summary->json('filters.shipping_zones'))->pluck('acumatica_id');
        $this->assertTrue($zoneIds->contains('Z005'));
        $this->assertTrue($zoneIds->contains('Z010'));
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

    public function test_backorders_summary_returns_value_summary_with_segment_splits(): void
    {
        $user = User::factory()->create();
        $this->seedValueSummaryData();

        $response = $this->actingAs($user)
            ->getJson('/api/operations/backorders/summary')
            ->assertOk();

        // Value summary is based on backorder lines (same as table/export):
        // KP FAY: order 100@10=1000, shipped 80@10=800, open 20@10=200.
        // CS DOV: order 50@20=1000, shipped 40@20=800, open 10@20=200.
        $response
            ->assertJsonPath('value_summary.order_value', 2000)
            ->assertJsonPath('value_summary.invoiced_value', 1600)
            ->assertJsonPath('value_summary.backorder_value', 400)
            ->assertJsonPath('value_summary.by_product_segment.manufactured.backorder_value', 200)
            ->assertJsonPath('value_summary.by_product_segment.trading.backorder_value', 200)
            ->assertJsonPath('value_summary.by_product_segment.trading.invoiced_value', 800)
            ->assertJsonPath('value_summary.by_customer_segment.KP.backorder_value', 200)
            ->assertJsonPath('value_summary.by_customer_segment.CS.backorder_value', 200)
            ->assertJsonPath('value_summary.by_customer_segment.CS.invoiced_value', 800);
    }

    public function test_backorders_summary_value_summary_honors_product_segment_filter(): void
    {
        $user = User::factory()->create();
        $this->seedValueSummaryData();

        $this->actingAs($user)
            ->getJson('/api/operations/backorders/summary?product_segment=manufactured')
            ->assertOk()
            ->assertJsonPath('value_summary.order_value', 1000)
            ->assertJsonPath('value_summary.invoiced_value', 800)
            ->assertJsonPath('value_summary.backorder_value', 200)
            // Breakdown cards keep both buckets for comparison (unfiltered split).
            ->assertJsonPath('value_summary.by_product_segment.trading.order_value', 1000)
            ->assertJsonPath('value_summary.by_product_segment.trading.backorder_value', 200)
            // Customer split is full (not filtered) so cards stay stable when a segment is active.
            ->assertJsonPath('value_summary.by_customer_segment.KP.order_value', 1000)
            ->assertJsonPath('value_summary.by_customer_segment.CS.order_value', 1000);

        // Trading segment filter must not zero out partner exposure.
        $this->actingAs($user)
            ->getJson('/api/operations/backorders/summary?product_segment=trading')
            ->assertOk()
            ->assertJsonPath('value_summary.order_value', 1000)
            ->assertJsonPath('value_summary.backorder_value', 200)
            ->assertJsonPath('value_summary.by_product_segment.trading.backorder_value', 200)
            ->assertJsonPath('value_summary.by_product_segment.manufactured.backorder_value', 200);
    }

    public function test_backorders_list_honors_product_segment_filter(): void
    {
        $user = User::factory()->create();
        $this->seedValueSummaryData();

        $this->actingAs($user)
            ->getJson('/api/operations/backorders?product_segment=manufactured')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inventory_id', 'FAY0001');

        $this->actingAs($user)
            ->getJson('/api/operations/backorders?product_segment=trading')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.inventory_id', 'DOV0001');

        $this->actingAs($user)
            ->getJson('/api/operations/backorders?segment=KP')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.order_nbr', 'SO-VAL-KP');
    }

    public function test_truncate_backorders_and_fill_rate_require_admin(): void
    {
        AcumaticaBackorderLine::create([
            'order_nbr'       => 'SO-TRUNC',
            'inventory_id'    => 'ITEM-TRUNC',
            'order_qty'       => 5,
            'open_qty'        => 5,
            'unit_price'      => 10,
            'revenue_at_risk' => 50,
            'synced_at'       => now(),
        ]);
        AcumaticaFillRateSnapshot::create([
            'order_nbr'         => 'SO-TRUNC',
            'fill_rate_pct'     => 50,
            'fill_rate_status'  => 'critical',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 5,
            'computed_at'       => now(),
        ]);

        $nonAdmin = User::factory()->create(['role' => 'Customer Service Manager']);
        $this->actingAs($nonAdmin)
            ->postJson('/api/admin/so-imports/truncate/backorders')
            ->assertForbidden();
        $this->actingAs($nonAdmin)
            ->postJson('/api/admin/so-imports/truncate/fill-rate')
            ->assertForbidden();

        $admin = User::factory()->create(['role' => 'Administrator']);
        $this->actingAs($admin)
            ->postJson('/api/admin/so-imports/truncate/backorders', ['clear_all' => true])
            ->assertOk();
        $this->actingAs($admin)
            ->postJson('/api/admin/so-imports/truncate/fill-rate', ['clear_all' => true])
            ->assertOk();

        $this->assertDatabaseCount('acumatica_backorder_lines', 0);
        $this->assertDatabaseCount('acumatica_fill_rate_snapshots', 0);
    }

    /**
     * Seeds one KP customer buying a Manufactured SKU (partially shipped)
     * and one CS customer buying a Trading SKU (fully shipped).
     */
    private function seedValueSummaryData(): void
    {
        AcumaticaCustomer::create([
            'acumatica_id'   => 'CUST-VAL-KP',
            'name'           => 'Professional Buyer',
            'customer_class' => 'KP-DISTRIB',
            'synced_at'      => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id'   => 'CUST-VAL-CS',
            'name'           => 'Retail Buyer',
            'customer_class' => 'RETAIL',
            'synced_at'      => now(),
        ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'FAY0001',
            'description'  => 'Kim-Fay Bales',
            'product_type' => 'manufactured',
            'qty_on_hand'  => 0,
            'synced_at'    => now(),
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'DOV0001',
            'description'  => 'Partner Soap',
            'product_type' => 'trading',
            'qty_on_hand'  => 0,
            'synced_at'    => now(),
        ]);

        $kpOrder = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-VAL-KP',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-VAL-KP',
            'status'                => 'Open',
            'order_date'            => now(),
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $kpOrder->id,
            'inventory_id'   => 'FAY0001',
            'order_qty'      => 100,
            'shipped_qty'    => 80,
            'unit_price'     => 10,
        ]);

        $csOrder = AcumaticaSalesOrder::create([
            'acumatica_order_nbr'   => 'SO-VAL-CS',
            'order_type'            => 'SO',
            'customer_acumatica_id' => 'CUST-VAL-CS',
            'status'                => 'Open',
            'order_date'            => now(),
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $csOrder->id,
            'inventory_id'   => 'DOV0001',
            'order_qty'      => 50,
            'shipped_qty'    => 40,
            'unit_price'     => 20,
        ]);

        // Value summary (dashboard cards) is driven by backorder lines so it
        // matches the table and Excel export — not the full SO order book.
        // qty_on_shipments equals shipped on purpose: residual open must NOT be
        // open − qty_on_shipments again (that zeroed partial-ship lines).
        AcumaticaBackorderLine::create([
            'order_nbr'             => 'SO-VAL-KP',
            'inventory_id'          => 'FAY0001',
            'customer_acumatica_id' => 'CUST-VAL-KP',
            'order_qty'             => 100,
            'shipped_qty'           => 80,
            'qty_on_shipments'      => 80,
            'open_qty'              => 20,
            'unit_price'            => 10,
            'revenue_at_risk'       => 200,
            'shortfall_kind'        => 'active_backorder',
            'synced_at'             => now(),
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr'             => 'SO-VAL-CS',
            'inventory_id'          => 'DOV0001',
            'customer_acumatica_id' => 'CUST-VAL-CS',
            'order_qty'             => 50,
            'shipped_qty'           => 40,
            'qty_on_shipments'      => 40,
            'open_qty'              => 10,
            'unit_price'            => 20,
            'revenue_at_risk'       => 200,
            'shortfall_kind'        => 'active_backorder',
            'synced_at'             => now(),
        ]);
    }

    private function ensureShippingZone(string $acumaticaId, string $description): AcumaticaShippingZone
    {
        return AcumaticaShippingZone::query()->updateOrCreate(
            ['acumatica_id' => $acumaticaId],
            [
                'description' => $description,
                'synced_at' => now(),
            ],
        );
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
