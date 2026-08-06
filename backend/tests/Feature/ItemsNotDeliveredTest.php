<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\InventoryWarehouseBalance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ItemsNotDeliveredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-07-30 12:00:00');
        Sanctum::actingAs(User::factory()->create(['role' => 'Administrator']));
        AcumaticaCustomer::create([
            'acumatica_id' => 'OUTLET-1', 'name' => 'Outlet One',
            'customer_class' => 'CS01', 'status' => 'Active',
        ]);
    }

    public function test_it_groups_sku_outlet_and_orders_using_ordered_minus_shipped(): void
    {
        $item = $this->item('SKU-1', 'Fay');
        $this->order('SO-OPEN', 'Open', '2026-07-15', 'SKU-1', 100, 40, 10);
        $this->order('SO-CANCELLED', 'Cancelled', '2026-07-16', 'SKU-1', 20, 0, 5);
        $this->stock($item, 'FGS', 90, 80);
        $this->stock($item, 'RMS1', 1000, 1000);

        $this->getJson('/api/operations/items-not-delivered?brands[]=Fay')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.inventory_id', 'SKU-1')
            ->assertJsonPath('data.0.totals.not_delivered_qty', 80)
            ->assertJsonPath('data.0.totals.not_delivered_amount', 700)
            ->assertJsonCount(1, 'data.0.outlets')
            ->assertJsonCount(2, 'data.0.outlets.0.orders')
            ->assertJsonPath('data.0.outlets.0.orders.0.total_available', 80)
            ->assertJsonCount(1, 'data.0.outlets.0.orders.0.warehouse_stocks')
            ->assertJsonPath('summary.not_delivered_units', 80)
            ->assertJsonPath('summary.not_delivered_amount', 700)
            ->assertJsonPath('summary.in_stock_amount', 700);
    }

    public function test_reason_priority_stock_boundaries_custom_dates_and_sku_pagination(): void
    {
        $partial = $this->item('SKU-PART', 'Fay');
        $this->order('SO-PART', 'Rejected', '2026-07-10', 'SKU-PART', 100, 0, 2);
        $this->stock($partial, 'FGS', 60, 50);

        $hold = $this->item('SKU-HOLD', 'Fay');
        AcumaticaCustomer::where('acumatica_id', 'OUTLET-1')->update(['status' => 'On Hold']);
        $this->order('SO-HOLD', 'Open', '2026-07-20', 'SKU-HOLD', 10, 0, 3);
        $this->order('SO-OLD', 'Open', '2026-06-20', 'SKU-HOLD', 99, 0, 3);

        $response = $this->getJson(
            '/api/operations/items-not-delivered?date_from=2026-07-01&date_to=2026-07-30&per_page=1&page=1'
        )->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('summary.affected_skus', 2)
            ->assertJsonMissing(['order_nbr' => 'SO-OLD']);

        $data = collect($response->json('data'));
        $this->assertCount(1, $data);

        $rejected = $this->getJson('/api/operations/items-not-delivered?reasons[]=rejected')
            ->assertOk()
            ->assertJsonPath('data.0.outlets.0.orders.0.reason_label', 'Order Rejected')
            ->assertJsonPath('data.0.outlets.0.orders.0.stock_status', 'partial_stock')
            ->assertJsonPath('data.0.outlets.0.orders.0.not_delivered_qty', 100);
        $this->assertSame(200.0, (float) $rejected->json('data.0.outlets.0.orders.0.not_delivered_amount'));

        $this->getJson('/api/operations/items-not-delivered?reasons[]=on_hold')
            ->assertOk()
            ->assertJsonPath('data.0.inventory_id', 'SKU-HOLD')
            ->assertJsonPath('data.0.outlets.0.orders.0.reason_label', 'Account / Order on Hold');
    }

    public function test_excel_export_contains_filtered_report(): void
    {
        $item = $this->item('SKU-1', 'Fay');
        $this->order('SO-1', 'Open', '2026-07-15', 'SKU-1', 10, 2, 25);
        $this->stock($item, 'FGS', 20, 20);

        $this->get('/api/operations/items-not-delivered/export?date_from=2026-07-01&date_to=2026-07-30')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    private function item(string $inventoryId, string $brand): AcumaticaInventoryItem
    {
        return AcumaticaInventoryItem::create([
            'inventory_id' => $inventoryId, 'description' => "{$brand} Product",
            'brand' => $brand, 'is_stock_item' => true,
        ]);
    }

    private function order(
        string $number,
        string $status,
        string $date,
        string $inventoryId,
        float $ordered,
        float $shipped,
        float $unitPrice,
    ): void {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => $number, 'order_type' => 'SO',
            'customer_acumatica_id' => 'OUTLET-1', 'customer_name' => 'Outlet One',
            'status' => $status, 'order_date' => $date,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_nbr' => 1,
            'inventory_id' => $inventoryId, 'description' => "{$inventoryId} Product",
            'order_qty' => $ordered, 'shipped_qty' => $shipped,
            'qty_on_shipments' => $shipped, 'unit_price' => $unitPrice,
        ]);
    }

    private function stock(
        AcumaticaInventoryItem $item,
        string $warehouse,
        float $onHand,
        ?float $available,
    ): void {
        InventoryWarehouseBalance::create([
            'inventory_item_id' => $item->id, 'inventory_id' => $item->inventory_id,
            'warehouse_id' => $warehouse, 'qty_on_hand' => $onHand, 'qty_available' => $available,
        ]);
    }
}
