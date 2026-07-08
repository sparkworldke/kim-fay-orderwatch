<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AcumaticaLiveProbeLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_lookup_customer_raw_payload(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('Customer', 'CustomerID', 'CUST100002')
            ->andReturn([
                'CustomerID' => ['value' => 'CUST100002'],
                'CustomerName' => ['value' => '4Horsemen Limited'],
            ]);
        $this->app->instance(AcumaticaClient::class, $client);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=customer_id&id=CUST100002')
            ->assertOk()
            ->assertJsonPath('lookup_type', 'customer_id')
            ->assertJsonPath('lookup_label', 'Customer ID')
            ->assertJsonPath('lookup_id', 'CUST100002')
            ->assertJsonPath('entity', 'Customer')
            ->assertJsonPath('field', 'CustomerID')
            ->assertJsonPath('raw.CustomerID.value', 'CUST100002');
    }

    public function test_lookup_rejects_unsupported_type(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=vendor&id=V001')
            ->assertStatus(422)
            ->assertJsonValidationErrors('type');
    }

    public function test_lookup_rejects_invalid_code_id(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=rep_code&id=P505%23')
            ->assertStatus(422)
            ->assertJsonValidationErrors('id');
    }

    public function test_lookup_returns_not_found_when_endpoint_is_available_but_record_is_missing(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('Route', 'RouteCode', 'NOPE')
            ->andReturn(null);
        $this->app->instance(AcumaticaClient::class, $client);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=route_code&id=NOPE')
            ->assertNotFound()
            ->assertJsonPath('lookup_type', 'route_code')
            ->assertJsonPath('lookup_id', 'NOPE');
    }

    public function test_inventory_lookup_falls_back_to_stock_item(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('InventoryItem', 'InventoryID', 'FAYWP0024')
            ->andThrow(new RuntimeException('Acumatica GET InventoryItem failed: 404 Not Found'));
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('StockItem', 'InventoryID', 'FAYWP0024')
            ->andReturn([
                'InventoryID' => ['value' => 'FAYWP0024'],
                'Description' => ['value' => 'Fay Wet Wipes'],
            ]);
        $this->app->instance(AcumaticaClient::class, $client);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=inventory_id&id=FAYWP0024')
            ->assertOk()
            ->assertJsonPath('entity', 'StockItem')
            ->assertJsonPath('field', 'InventoryID')
            ->assertJsonPath('raw.InventoryID.value', 'FAYWP0024');
    }

    public function test_rep_code_lookup_falls_back_from_consultant_to_salesperson(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('Consultant', 'ConsultantID', 'P505')
            ->andReturn(null);
        $client->shouldReceive('fetchFirstByField')
            ->once()
            ->with('SalesPerson', 'SalesPersonID', 'P505')
            ->andReturn([
                'SalesPersonID' => ['value' => 'P505'],
                'Name' => ['value' => 'Shirleen Chebet'],
            ]);
        $this->app->instance(AcumaticaClient::class, $client);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=rep_code&id=P505')
            ->assertOk()
            ->assertJsonPath('entity', 'SalesPerson')
            ->assertJsonPath('field', 'SalesPersonID')
            ->assertJsonPath('raw.SalesPersonID.value', 'P505');
    }

    public function test_non_admin_cannot_access_lookup_endpoint(): void
    {
        $user = User::factory()->create(['role' => 'Customer Service Manager', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/acumatica/lookup?type=rep_code&id=P505')
            ->assertForbidden();
    }
}
