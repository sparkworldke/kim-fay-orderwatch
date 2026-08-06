<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerBrandReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_groups_so_lines_by_customer_and_brand_and_calculates_partial_delivery(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create(['acumatica_id' => 'CUST-1', 'name' => 'Test Retailer', 'customer_class' => 'Retail']);
        AcumaticaInventoryItem::create(['inventory_id' => 'SKU-1', 'description' => 'Item', 'brand' => 'Fay', 'qty_on_hand' => 10]);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO0001', 'order_type' => 'SO', 'customer_acumatica_id' => 'CUST-1',
            'customer_name' => 'Test Retailer', 'order_date' => '2026-06-10', 'status' => 'Open', 'order_total' => 950,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_nbr' => 1, 'inventory_id' => 'SKU-1',
            'order_qty' => 10, 'shipped_qty' => 4, 'unit_price' => 100, 'discount_amount' => 50,
            'unfilled_reason_code' => 'inventory_shortage',
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO0001', 'inventory_id' => 'SKU-1', 'customer_acumatica_id' => 'CUST-1',
            'order_qty' => 10, 'shipped_qty' => 4, 'open_qty' => 6, 'unit_price' => 100, 'reason_code' => 'supplier_delay',
        ]);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30');

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.customer.id', 'CUST-1')
            ->assertJsonPath('data.0.totals.so_count', 1)
            ->assertJsonPath('data.0.totals.ordered_qty', 10)
            ->assertJsonPath('data.0.totals.sold_qty', 4)
            ->assertJsonPath('data.0.totals.sold_value', 380)
            ->assertJsonPath('data.0.totals.undelivered_qty', 6)
            ->assertJsonPath('data.0.totals.undelivered_value', 570)
            ->assertJsonPath('data.0.totals.fill_rate_pct', 40)
            ->assertJsonPath('data.0.brands.0.brand', 'Fay')
            ->assertJsonPath('data.0.sales_orders.0.brands.0.brand', 'Fay')
            ->assertJsonPath('data.0.undelivered_reasons.0.reason', 'inventory_shortage');
    }

    public function test_report_excludes_non_so_documents_and_validates_dates(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create(['acumatica_id' => 'CUST-2', 'name' => 'Quote Customer']);
        $quote = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'QT0001', 'order_type' => 'QT', 'customer_acumatica_id' => 'CUST-2',
            'customer_name' => 'Quote Customer', 'order_date' => '2026-06-10', 'status' => 'Open', 'order_total' => 100,
        ]);
        AcumaticaSalesOrderLine::create(['sales_order_id' => $quote->id, 'line_nbr' => 1, 'order_qty' => 1, 'unit_price' => 100]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/customer-brands?date_from=2026-06-30&date_to=2026-06-01')
            ->assertUnprocessable();
    }

    public function test_report_requires_authentication(): void
    {
        $this->getJson('/api/dashboard/customer-brands')->assertUnauthorized();
    }

    public function test_report_filters_kim_fay_and_trading_brands(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create(['acumatica_id' => 'CUST-3', 'name' => 'Mixed Customer']);
        AcumaticaInventoryItem::create(['inventory_id' => 'FAY-1', 'brand' => 'Kim-Fay', 'product_type' => 'manufactured', 'qty_on_hand' => 10]);
        AcumaticaInventoryItem::create(['inventory_id' => 'DOV-1', 'brand' => 'Dove', 'product_type' => 'trading', 'qty_on_hand' => 10]);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO0003', 'order_type' => 'SO', 'customer_acumatica_id' => 'CUST-3',
            'customer_name' => 'Mixed Customer', 'order_date' => '2026-06-10', 'status' => 'Open', 'order_total' => 300,
        ]);
        AcumaticaSalesOrderLine::create(['sales_order_id' => $order->id, 'line_nbr' => 1, 'inventory_id' => 'FAY-1', 'order_qty' => 2, 'shipped_qty' => 2, 'unit_price' => 100]);
        AcumaticaSalesOrderLine::create(['sales_order_id' => $order->id, 'line_nbr' => 2, 'inventory_id' => 'DOV-1', 'order_qty' => 1, 'shipped_qty' => 1, 'unit_price' => 100]);

        $base = '/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30&business_category=';
        $this->actingAs($user, 'sanctum')->getJson($base.'manufactured')->assertOk()
            ->assertJsonPath('data.0.totals.sold_qty', 2)
            // Stored "Kim-Fay" normalizes to cascade brand "Kimfay".
            ->assertJsonPath('data.0.brands.0.brand', 'Kimfay');
        $this->actingAs($user, 'sanctum')->getJson($base.'trading')->assertOk()
            ->assertJsonPath('data.0.totals.sold_qty', 1)
            ->assertJsonPath('data.0.brands.0.brand', 'Dove');
        $this->actingAs($user, 'sanctum')->getJson($base.'invalid')->assertUnprocessable();
    }

    public function test_report_resolves_brand_from_inventory_id_prefix_when_master_brand_missing(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create(['acumatica_id' => 'CUST-PREFIX', 'name' => 'Prefix Customer']);
        // No inventory master rows — brand must come from inventory ID prefixes.
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-PREFIX', 'order_type' => 'SO', 'customer_acumatica_id' => 'CUST-PREFIX',
            'customer_name' => 'Prefix Customer', 'order_date' => '2026-06-10', 'status' => 'Open', 'order_total' => 500,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_nbr' => 1, 'inventory_id' => 'APTML0004',
            'description' => 'Aptamil Infant 800g', 'order_qty' => 2, 'shipped_qty' => 0, 'unit_price' => 100,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_nbr' => 2, 'inventory_id' => 'COWGT0001',
            'description' => 'Cow & Gate First 400g', 'order_qty' => 3, 'shipped_qty' => 0, 'unit_price' => 100,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_nbr' => 3, 'inventory_id' => 'FAYTP0008',
            'description' => 'Fay TP Emb. Unwrap. 4x10s White', 'order_qty' => 1, 'shipped_qty' => 0, 'unit_price' => 50,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30&include_sales_orders=0');

        $response->assertOk()->assertJsonPath('meta.total', 1);
        $brands = collect($response->json('data.0.brands'))->pluck('brand')->all();
        $this->assertContains('Aptamil', $brands);
        $this->assertContains('Cow & Gate', $brands);
        $this->assertContains('Fay', $brands);
        $this->assertNotContains('Unclassified', $brands);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30&brand=Aptamil&include_sales_orders=0')
            ->assertOk()
            ->assertJsonPath('data.0.brands.0.brand', 'Aptamil')
            ->assertJsonPath('data.0.totals.ordered_qty', 2);
    }

    public function test_report_filters_cs_and_kp_customer_segments(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        foreach ([['KP-1', 'KP Buyer', 'KP Retail'], ['CS-1', 'CS Buyer', 'CONSUMER']] as [$id, $name, $class]) {
            AcumaticaCustomer::create(['acumatica_id' => $id, 'name' => $name, 'customer_class' => $class]);
            $order = AcumaticaSalesOrder::create([
                'acumatica_order_nbr' => 'SO-'.$id, 'order_type' => 'SO', 'customer_acumatica_id' => $id,
                'customer_name' => $name, 'order_date' => '2026-06-10', 'status' => 'Open', 'order_total' => 100,
            ]);
            AcumaticaSalesOrderLine::create(['sales_order_id' => $order->id, 'line_nbr' => 1, 'order_qty' => 1, 'shipped_qty' => 1, 'unit_price' => 100]);
        }

        $base = '/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30&segment=';
        $this->actingAs($user, 'sanctum')->getJson($base.'KP')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.id', 'KP-1');
        $this->actingAs($user, 'sanctum')->getJson($base.'CS')->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.customer.id', 'CS-1');
        $this->actingAs($user, 'sanctum')->getJson($base.'GT')->assertUnprocessable();
    }

    public function test_report_filters_by_specific_brand_and_returns_brand_rollup(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create(['acumatica_id' => 'CUST-B', 'name' => 'Brand Buyer']);
        AcumaticaInventoryItem::create(['inventory_id' => 'DOV-2', 'brand' => 'Dove', 'product_type' => 'trading', 'qty_on_hand' => 10]);
        AcumaticaInventoryItem::create(['inventory_id' => 'LUX-1', 'brand' => 'Lux', 'product_type' => 'trading', 'qty_on_hand' => 10]);
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-BRAND', 'order_type' => 'SO', 'customer_acumatica_id' => 'CUST-B',
            'customer_name' => 'Brand Buyer', 'order_date' => '2026-06-10', 'status' => 'Open', 'order_total' => 300,
        ]);
        AcumaticaSalesOrderLine::create(['sales_order_id' => $order->id, 'line_nbr' => 1, 'inventory_id' => 'DOV-2', 'order_qty' => 2, 'shipped_qty' => 2, 'unit_price' => 100]);
        AcumaticaSalesOrderLine::create(['sales_order_id' => $order->id, 'line_nbr' => 2, 'inventory_id' => 'LUX-1', 'order_qty' => 1, 'shipped_qty' => 0, 'unit_price' => 100]);

        $base = '/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30';
        $this->actingAs($user, 'sanctum')
            ->getJson($base.'&partner_brand=trading&brand=Dove')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.brands.0.brand', 'Dove')
            ->assertJsonPath('data.0.totals.sold_qty', 2)
            ->assertJsonPath('summary.brand_count', 1)
            ->assertJsonPath('brand_rollup.0.brand', 'Dove')
            ->assertJsonPath('brand_rollup.0.customer_count', 1);

        $this->actingAs($user, 'sanctum')
            ->getJson($base.'&partner_brand=trading')
            ->assertOk()
            ->assertJsonPath('summary.brand_count', 2);
    }

    public function test_fill_rate_uses_order_quantity_shipped_quantity_and_so_order_date(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        AcumaticaCustomer::create(['acumatica_id' => 'CUST-DATE', 'name' => 'Date Customer']);
        $included = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-DATE-IN', 'order_type' => 'SO', 'customer_acumatica_id' => 'CUST-DATE',
            'customer_name' => 'Date Customer', 'order_date' => '2026-06-15', 'status' => 'Shipping', 'order_total' => 1000,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $included->id, 'line_nbr' => 1, 'order_qty' => 10,
            'shipped_qty' => 6, 'qty_on_shipments' => 7, 'unit_price' => 100,
        ]);
        $excluded = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-DATE-OUT', 'order_type' => 'SO', 'customer_acumatica_id' => 'CUST-DATE',
            'customer_name' => 'Date Customer', 'order_date' => '2026-05-31', 'status' => 'Completed', 'order_total' => 500,
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $excluded->id, 'line_nbr' => 1, 'order_qty' => 5,
            'shipped_qty' => 5, 'qty_on_shipments' => 5, 'unit_price' => 100,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/customer-brands?date_from=2026-06-01&date_to=2026-06-30')
            ->assertOk()
            ->assertJsonPath('data.0.totals.ordered_qty', 10)
            ->assertJsonPath('data.0.totals.sold_qty', 6)
            ->assertJsonPath('data.0.totals.fill_rate_pct', 60)
            ->assertJsonPath('data.0.sales_orders.0.order_date', '2026-06-15')
            ->assertJsonCount(1, 'data.0.sales_orders');
    }
}
