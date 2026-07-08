<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SalesConsultantOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_can_view_all_sales_consultants(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Shirleen Chebet',
            'email' => 'shirleen@example.test',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Second Consultant',
            'email' => 'second@example.test',
            'rep_code' => 'P777',
            'is_active' => true,
        ]);

        $this->createOrder('SO900001', 'P505', 1500, null);
        $this->createOrder('SO900002', 'P505', 2500, now());
        $this->createOrder('SO900003', 'P777', 900, null);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/operations/sales-consultants');

        $response->assertOk()
            ->assertJsonPath('scope', 'all')
            ->assertJsonCount(2, 'items');

        $items = collect($response->json('items'))->keyBy('rep_code');

        $this->assertSame(2, $items['P505']['assigned_orders']);
        $this->assertSame(1, $items['P505']['active_orders']);
        $this->assertSame(1, $items['P505']['completed_orders']);
        $this->assertSame(4000, $items['P505']['assigned_revenue']);
    }

    public function test_customer_service_manager_and_executive_can_view_all_sales_consultants(): void
    {
        User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Shirleen Chebet',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);

        foreach (['Customer Service Manager', 'Executive'] as $role) {
            $viewer = User::factory()->create(['role' => $role, 'is_active' => true]);

            $this->actingAs($viewer, 'sanctum')
                ->getJson('/api/operations/sales-consultants')
                ->assertOk()
                ->assertJsonPath('scope', 'all')
                ->assertJsonCount(1, 'items');
        }
    }

    public function test_sales_operations_user_can_view_own_detail_page(): void
    {
        User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'P777',
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => 'Sales Operations',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);

        $this->createOrder('SO900030', 'P505', 1200, null);

        $this->actingAs($viewer, 'sanctum')
            ->getJson("/api/operations/sales-consultants/{$viewer->id}")
            ->assertOk()
            ->assertJsonPath('consultant.id', $viewer->id)
            ->assertJsonPath('consultant.rep_code', 'P505')
            ->assertJsonPath('summary.total_orders', 1);
    }

    public function test_sales_operations_user_only_sees_their_own_profile(): void
    {
        User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Shirleen Chebet',
            'email' => 'shirleen@example.test',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Second Consultant',
            'email' => 'second@example.test',
            'rep_code' => 'P777',
            'is_active' => true,
        ]);
        $viewer = User::factory()->create([
            'role' => 'Sales Operations',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);

        $this->createOrder('SO900004', 'P505', 1200, null);
        $this->createOrder('SO900005', 'P777', 800, null);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/operations/sales-consultants');

        $response->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonPath('rep_code', 'P505')
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.rep_code', 'P505')
            ->assertJsonPath('items.0.assigned_orders', 1);
    }

    public function test_consultant_detail_endpoint_returns_profile_and_summary(): void
    {
        $manager = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Shirleen Chebet',
            'email' => 'shirleen@example.test',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);

        $this->createOrder('SO900020', 'P505', 1500, now());
        $this->createOrder('SO900021', 'P505', 2500, null);

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/operations/sales-consultants/{$consultant->id}?date_from=2026-07-01&date_to=2026-07-31");

        $response->assertOk()
            ->assertJsonPath('consultant.id', $consultant->id)
            ->assertJsonPath('consultant.rep_code', 'P505')
            ->assertJsonPath('summary.total_orders', 2)
            ->assertJsonPath('summary.active_orders', 1)
            ->assertJsonPath('summary.total_completed_orders', 1)
            ->assertJsonPath('summary.total_order_value', 4000);
    }

    public function test_consultant_customer_endpoint_returns_grouped_customers_and_summary(): void
    {
        $manager = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'name' => 'Shirleen Chebet',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST001',
            'name' => 'Naivas Jogoo Rd',
            'status' => 'Active',
            'customer_class' => 'Supermarket',
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST002',
            'name' => 'Chandarana Kisumu',
            'status' => 'Active',
            'customer_class' => 'Retail',
        ]);

        $this->createOrder('SO900010', 'P505', 1500, now(), 'CUST001', 'Fallback One');
        $this->createOrder('SO900011', 'P505', 2500, null, 'CUST001', 'Fallback One');
        $this->createOrder('SO900012', 'P505', 900, '2026-07-05 10:00:00', 'CUST002', 'Fallback Two');
        $this->createOrder('SO900013', 'P777', 3000, null, 'CUST003', 'Other Rep');

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/operations/sales-consultants/{$consultant->id}/customers?date_from=2026-07-01&date_to=2026-07-31");

        $response->assertOk()
            ->assertJsonPath('rep_code', 'P505')
            ->assertJsonPath('summary.customer_count', 2)
            ->assertJsonPath('summary.total_order_value', 4900)
            ->assertJsonPath('summary.total_completed_orders', 2)
            ->assertJsonPath('summary.active_orders', 1)
            ->assertJsonPath('summary.total_orders', 3)
            ->assertJsonCount(2, 'customers');

        $customers = collect($response->json('customers'))->keyBy('customer_id');
        $this->assertSame('Naivas Jogoo Rd', $customers['CUST001']['customer_name']);
        $this->assertSame(2, $customers['CUST001']['order_count']);
        $this->assertSame(1, $customers['CUST001']['completed_orders']);
        $this->assertSame(4000, $customers['CUST001']['total_order_value']);
        $this->assertNotNull($customers['CUST001']['orders_per_month']);
    }

    public function test_sales_operations_user_without_rep_code_gets_empty_profile_response(): void
    {
        $viewer = User::factory()->create([
            'role' => 'Sales Operations',
            'rep_code' => null,
            'is_active' => true,
        ]);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/operations/sales-consultants');

        $response->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonPath('rep_code', null)
            ->assertJsonCount(0, 'items')
            ->assertJsonPath('message', 'No Rep Code is assigned to your profile.');
    }

    public function test_other_roles_cannot_view_sales_consultants(): void
    {
        $viewer = User::factory()->create(['role' => 'Customer Service Agent', 'is_active' => true]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/operations/sales-consultants')
            ->assertForbidden();
    }

    public function test_manager_can_import_consultants_from_sales_orders(): void
    {
        $manager = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);
        $this->createOrder('SO900006', 'P505', 1200, null);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/operations/sales-consultants/import', [
                'source' => 'sales_orders',
            ]);

        $response->assertOk()
            ->assertJsonPath('source', 'sales_orders')
            ->assertJsonPath('found', 1)
            ->assertJsonPath('created', 1)
            ->assertJsonPath('items.0.rep_code', 'P505')
            ->assertJsonPath('items.0.placeholder_email', true);

        $this->assertDatabaseHas('users', [
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
            'is_active' => false,
        ]);
    }

    public function test_admin_can_import_single_consultant_from_acumatica_users_module(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('Consultant', 'ConsultantID', 'P505')
            ->andReturn([
                'ConsultantID' => ['value' => 'P505'],
                'Name' => ['value' => 'Shirleen Consultant'],
                'Email' => ['value' => 'shirleen@kimfay.test'],
            ]);
        $this->app->instance(AcumaticaClient::class, $client);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/operations/sales-consultants/import', [
                'source' => 'acumatica_users',
                'rep_code' => 'p505',
            ]);

        $response->assertOk()
            ->assertJsonPath('source', 'acumatica_users')
            ->assertJsonPath('requested_rep_code', 'P505')
            ->assertJsonPath('created', 1)
            ->assertJsonPath('items.0.email', 'shirleen@kimfay.test')
            ->assertJsonPath('items.0.placeholder_email', false);

        $this->assertDatabaseHas('users', [
            'name' => 'Shirleen Consultant',
            'email' => 'shirleen@kimfay.test',
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);
    }

    public function test_consultant_import_rejects_invalid_rep_code(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/operations/sales-consultants/import', [
                'source' => 'sales_orders',
                'rep_code' => 'P505#',
            ])
            ->assertStatus(422);
    }

    public function test_non_manager_cannot_import_consultants(): void
    {
        $agent = User::factory()->create(['role' => 'Customer Service Agent', 'is_active' => true]);

        $this->actingAs($agent, 'sanctum')
            ->postJson('/api/operations/sales-consultants/import', [
                'source' => 'sales_orders',
            ])
            ->assertForbidden();
    }

    private function createOrder(
        string $orderNbr,
        string $repCode,
        float $total,
        mixed $completedAt,
        string $customerId = 'CUST001',
        string $customerName = 'Test Outlet',
    ): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => $orderNbr,
            'order_type' => 'SO',
            'customer_acumatica_id' => $customerId,
            'customer_name' => $customerName,
            'status' => $completedAt ? 'Completed' : 'Open',
            'order_date' => '2026-07-05',
            'order_total' => $total,
            'currency_id' => 'KES',
            'sales_consultant_rep_code' => $repCode,
            'sales_consultant_name' => 'Sales Consultant',
            'completed_at' => $completedAt,
        ]);
    }
}
