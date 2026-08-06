<?php

namespace Tests\Feature;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\InventoryWarehouseBalance;
use App\Models\MonthlySkuSummary;
use App\Models\User;
use App\Services\Production\ProductionSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductionIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_aggregates_warehouses_and_uses_available_stock(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $item = AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-001',
            'description' => 'Test Product',
            'product_type' => 'manufactured',
            'is_stock_item' => true,
        ]);
        InventoryWarehouseBalance::create([
            'inventory_item_id' => $item->id,
            'inventory_id' => $item->inventory_id,
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 100,
            'qty_available' => 80,
        ]);
        InventoryWarehouseBalance::create([
            'inventory_item_id' => $item->id,
            'inventory_id' => $item->inventory_id,
            'warehouse_id' => 'DTC',
            'qty_on_hand' => 40,
            'qty_available' => 30,
        ]);

        $this->getJson('/api/operations/production/inventory?ownership=manufactured')
            ->assertOk()
            ->assertJsonPath('data.0.total_on_hand', 140)
            ->assertJsonPath('data.0.total_available', 110)
            ->assertJsonPath('data.0.stock_basis', 'available');
    }

    public function test_inventory_uses_on_hand_when_available_is_absent_and_plan_crud_is_authorized(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);
        Sanctum::actingAs($admin);
        $item = AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-002',
            'description' => 'Planned Product',
            'product_type' => 'manufactured',
            'is_stock_item' => true,
        ]);
        InventoryWarehouseBalance::create([
            'inventory_item_id' => $item->id,
            'inventory_id' => $item->inventory_id,
            'warehouse_id' => 'FGS',
            'qty_on_hand' => 40,
            'qty_available' => null,
        ]);

        $planId = $this->postJson('/api/operations/production/plans', [
            'inventory_id' => $item->inventory_id,
            'ownership' => 'manufactured',
            'msi' => 100,
        ])->assertCreated()->json('id');

        $this->getJson('/api/operations/production/inventory?ownership=manufactured')
            ->assertOk()
            ->assertJsonPath('data.0.total_available', null)
            ->assertJsonPath('data.0.resolved_stock', 40)
            ->assertJsonPath('data.0.stock_basis', 'on_hand_fallback')
            ->assertJsonPath('data.0.status', 'critical')
            ->assertJsonPath('data.0.requirement', 60);

        $this->deleteJson("/api/operations/production/plans/{$planId}")->assertOk();
        $this->assertDatabaseHas('acumatica_inventory_items', ['inventory_id' => 'SKU-002']);
        $this->assertSoftDeleted('production_sku_plans', ['id' => $planId]);
    }

    public function test_store_manager_and_coo_can_bulk_upload_msi_safety_and_buffer(): void
    {
        $stores = \App\Models\Department::query()->create([
            'slug' => 'stores',
            'name' => 'Stores',
            'segment' => 'Operations',
            'is_customer_facing' => false,
            'sort_order' => 10,
        ]);

        $storeManager = User::factory()->create([
            'role' => 'Sales Operations',
            'department_id' => $stores->id,
            'department_role' => 'hod',
            'org_level' => 'operations',
            'is_active' => true,
        ]);
        $coo = User::factory()->create([
            'role' => 'Executive',
            'org_level' => 'c_suite',
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => 'Sales Consultant',
            'org_level' => 'sales',
            'is_active' => true,
        ]);

        $item = AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU-BULK-1',
            'description' => 'Bulk plan product',
            'product_type' => 'manufactured',
            'is_stock_item' => true,
        ]);

        $payload = [
            'rows' => [[
                'inventory_id' => $item->inventory_id,
                'msi' => 500,
                'safety_stock' => 120,
                'buffer_stock' => 200,
            ]],
        ];

        Sanctum::actingAs($viewer);
        $this->postJson('/api/operations/production/plans/bulk-msi', $payload)->assertForbidden();

        Sanctum::actingAs($storeManager);
        $this->postJson('/api/operations/production/plans/bulk-msi', $payload)
            ->assertOk()
            ->assertJsonPath('created', 1)
            ->assertJsonPath('updated', 0);

        $this->assertDatabaseHas('production_sku_plans', [
            'inventory_item_id' => $item->id,
            'msi' => 500,
            'safety_stock' => 120,
            'buffer_stock' => 200,
        ]);

        Sanctum::actingAs($coo);
        $this->postJson('/api/operations/production/plans/bulk-msi', [
            'rows' => [[
                'inventory_id' => $item->inventory_id,
                'msi' => 600,
                'safety_stock' => 150,
                'buffer_stock' => 250,
            ]],
        ])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertDatabaseHas('production_sku_plans', [
            'inventory_item_id' => $item->id,
            'msi' => 600,
            'safety_stock' => 150,
            'buffer_stock' => 250,
        ]);
    }

    public function test_monthly_summary_calculates_delivered_missed_volume_and_revenue(): void
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-MISSED-1',
            'order_type' => 'SO',
            'order_date' => '2026-07-10',
            'status' => 'Cancelled',
            'currency_id' => 'KES',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'line_nbr' => 1,
            'inventory_id' => 'SKU-MISSED',
            'warehouse_id' => 'FGS',
            'order_qty' => 100,
            'shipped_qty' => 40,
            'qty_on_shipments' => 55,
            'unit_price' => 20,
        ]);

        app(ProductionSummaryService::class)->refreshMonthly('2026-07-10', '2026-07-10');

        $summary = MonthlySkuSummary::where('inventory_id', 'SKU-MISSED')->firstOrFail();
        $this->assertSame(100.0, (float) $summary->ordered_qty);
        $this->assertSame(55.0, (float) $summary->delivered_qty);
        $this->assertSame(45.0, (float) $summary->missed_qty);
        $this->assertSame(900.0, (float) $summary->missed_revenue);
        $this->assertTrue($summary->revenue_complete);
    }
}
