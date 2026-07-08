<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AdminDataManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_export_comprehensive_csv(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
            'rep_code' => 'P505',
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO900001',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST001',
            'customer_name' => 'Test Outlet',
            'status' => 'Open',
            'order_date' => '2026-07-05',
            'order_total' => 1200,
            'currency_id' => 'KES',
            'sales_consultant_rep_code' => 'P505',
            'sales_consultant_name' => $consultant->name,
        ]);

        AcumaticaInventoryItem::create([
            'inventory_id' => 'SKU001',
            'description' => 'Test Item',
            'qty_on_hand' => 10,
            'qty_available' => 8,
        ]);

        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'SKU001',
            'description' => 'Test Item',
            'order_qty' => 10,
            'shipped_qty' => 8,
            'open_qty' => 2,
        ]);

        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO900001',
            'inventory_id' => 'SKU001',
            'customer_acumatica_id' => 'CUST001',
            'customer_name' => 'Test Outlet',
            'order_qty' => 10,
            'shipped_qty' => 8,
            'open_qty' => 2,
            'backorder_qty' => 2,
            'unit_price' => 120,
            'revenue_at_risk' => 240,
            'fulfillment_status' => 'partial',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->get('/api/admin/data-management/export?dataset=all');

        $response->assertOk();
        $content = $response->streamedContent();

        $this->assertStringContainsString('dataset,inventory_id,item_description', $content);
        $this->assertStringContainsString('fill_rate,SKU001', $content);
        $this->assertStringContainsString('backorders,SKU001', $content);
        $this->assertStringContainsString('consultants', $content);
        $this->assertStringContainsString('P505', $content);
    }

    public function test_admin_can_import_sales_orders_with_consultant_assignment(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        User::factory()->create([
            'role' => 'Sales Consultant',
            'is_active' => true,
            'name' => 'Shirleen Chebet',
            'rep_code' => 'P505',
        ]);

        $csv = implode("\n", [
            'order_nbr,rep_code,customer_id,customer_name,order_date,order_total,currency_id,status',
            'SO900002,P505,CUST002,Second Outlet,2026-07-05,2500,KES,Open',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/data-management/sales-orders/import', [
                'file' => UploadedFile::fake()->createWithContent('orders.csv', $csv),
            ]);

        $response->assertOk()
            ->assertJsonPath('imported', 1)
            ->assertJsonPath('failed', 0);

        $this->assertDatabaseHas('acumatica_sales_orders', [
            'acumatica_order_nbr' => 'SO900002',
            'sales_consultant_rep_code' => 'P505',
            'sales_consultant_name' => 'Shirleen Chebet',
            'import_source' => 'admin_csv',
        ]);
    }

    public function test_import_rejects_invalid_consultant_without_partial_import(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $csv = implode("\n", [
            'order_nbr,rep_code,customer_id,order_date,order_total',
            'SO900003,P999,CUST003,2026-07-05,3000',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->post('/api/admin/data-management/sales-orders/import', [
                'file' => UploadedFile::fake()->createWithContent('orders.csv', $csv),
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('imported', 0)
            ->assertJsonPath('failed', 1);

        $this->assertDatabaseMissing('acumatica_sales_orders', [
            'acumatica_order_nbr' => 'SO900003',
        ]);
    }

    public function test_non_admin_cannot_use_data_management_endpoints(): void
    {
        $user = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->get('/api/admin/data-management/export?dataset=all')
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->post('/api/admin/data-management/sales-orders/import', [
                'file' => UploadedFile::fake()->createWithContent('orders.csv', 'order_nbr,rep_code,customer_id,order_date,order_total'),
            ])
            ->assertForbidden();
    }
}
