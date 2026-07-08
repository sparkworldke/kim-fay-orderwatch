<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaShippingZone;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AcumaticaCustomerSyncService;
use App\Services\Admin\AcumaticaShippingZoneSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class ShippingZoneSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_zone_sync_imports_zone_master_data(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllShippingZones')->once()->andReturn([
            ['ZoneID' => ['value' => 'Z005'], 'Description' => ['value' => 'Nairobi Zone']],
            ['ZoneID' => ['value' => 'Z012'], 'Description' => ['value' => 'Mombasa Zone']],
        ]);

        $run = (new AcumaticaShippingZoneSyncService($client))->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame('master', $run->filters['source'] ?? null);
        $this->assertDatabaseHas('acumatica_shipping_zones', [
            'acumatica_id' => 'Z005',
            'description' => 'Nairobi Zone',
            'name' => 'Mombasa Rd',
            'region' => 'Nairobi',
        ]);
        $this->assertDatabaseHas('acumatica_shipping_zones', [
            'acumatica_id' => 'Z012',
            'description' => 'Mombasa Zone',
            'name' => 'Mombasa',
            'region' => 'Coast',
        ]);
    }

    public function test_shipping_zone_sync_falls_back_to_customer_shipping_zone_ids(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllShippingZones')->once()->andReturn([]);
        $client->shouldReceive('fetchAllCustomers')->once()->andReturn([
            [
                'CustomerID' => ['value' => 'CUS08548'],
                'ShippingZoneID' => ['value' => 'Z005'],
            ],
            [
                'CustomerID' => ['value' => 'CUS09999'],
                'ShippingZoneID' => ['value' => 'Z012'],
            ],
            [
                'CustomerID' => ['value' => 'CUS10000'],
                'ShippingZoneID' => ['value' => 'Z005'],
            ],
        ]);

        $run = (new AcumaticaShippingZoneSyncService($client))->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame('customers', $run->filters['source'] ?? null);
        $this->assertTrue($run->filters['master_unavailable'] ?? false);
        $this->assertDatabaseHas('acumatica_shipping_zones', [
            'acumatica_id' => 'Z005',
            'description' => 'Mombasa Rd (Nairobi)',
            'name' => 'Mombasa Rd',
            'region' => 'Nairobi',
        ]);
        $this->assertDatabaseHas('acumatica_shipping_zones', [
            'acumatica_id' => 'Z012',
            'description' => 'Mombasa (Coast)',
            'name' => 'Mombasa',
            'region' => 'Coast',
        ]);
    }

    public function test_shipping_zone_sync_skips_empty_or_nested_shipping_zone_fields(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllShippingZones')->once()->andReturn([]);
        $client->shouldReceive('fetchAllCustomers')->once()->andReturn([
            [
                'CustomerID' => ['value' => 'CUS00001'],
                'ShippingZoneID' => [],
            ],
            [
                'CustomerID' => ['value' => 'CUS00002'],
                'ShippingZoneID' => [
                    'ZoneID' => ['value' => 'Z001'],
                    'Description' => ['value' => 'Westlands Zone'],
                ],
            ],
            [
                'CustomerID' => ['value' => 'CUS00003'],
            ],
        ]);

        $run = (new AcumaticaShippingZoneSyncService($client))->run();

        $this->assertSame('completed', $run->status);
        $this->assertSame(1, $run->success_count);
        $this->assertDatabaseHas('acumatica_shipping_zones', [
            'acumatica_id' => 'Z001',
            'description' => 'Westlands (Nairobi)',
            'name' => 'Westlands',
            'region' => 'Nairobi',
        ]);
        $this->assertSame(1, AcumaticaShippingZone::where('acumatica_id', 'Z001')->count());
    }

    public function test_ensure_zone_exists_applies_config_metadata(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $service = new AcumaticaShippingZoneSyncService($client);

        $service->ensureZoneExists('Z005');

        $this->assertDatabaseHas('acumatica_shipping_zones', [
            'acumatica_id' => 'Z005',
            'description' => 'Mombasa Rd (Nairobi)',
            'name' => 'Mombasa Rd',
            'region' => 'Nairobi',
        ]);
    }

    public function test_sync_customers_command_runs_customer_import(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllShippingZones')->once()->andReturn([]);
        $client->shouldReceive('fetchAllCustomers')->once()->andReturn([
            [
                'CustomerID' => ['value' => 'CUS08548'],
                'CustomerName' => ['value' => 'Sample Customer'],
                'Status' => ['value' => 'Active'],
                'ShippingZoneID' => ['value' => 'Z005'],
                'CustomerClass' => ['value' => 'RETAIL'],
                'TaxZone' => ['value' => 'VAT'],
            ],
        ]);

        $zoneSync = new AcumaticaShippingZoneSyncService($client);
        $this->app->instance(AcumaticaCustomerSyncService::class, new AcumaticaCustomerSyncService($client, $zoneSync));

        $this->artisan('acumatica:sync-customers')->assertSuccessful();

        $this->assertDatabaseHas('acumatica_customers', [
            'acumatica_id' => 'CUS08548',
            'shipping_zone_id' => 'Z005',
        ]);
    }

    public function test_customer_sync_stores_shipping_zone_id_from_customer_payload(): void
    {
        $client = Mockery::mock(AcumaticaClient::class);
        $client->shouldReceive('fetchAllShippingZones')->once()->andReturn([]);
        $client->shouldReceive('fetchAllCustomers')->once()->andReturn([
            [
                'CustomerID' => ['value' => 'CUS08548'],
                'CustomerName' => ['value' => 'Sample Customer'],
                'Status' => ['value' => 'Active'],
                'ShippingZoneID' => ['value' => 'Z005'],
                'CustomerClass' => ['value' => 'RETAIL'],
                'TaxZone' => ['value' => 'VAT'],
            ],
        ]);

        $service = new AcumaticaCustomerSyncService(
            $client,
            new AcumaticaShippingZoneSyncService($client),
        );

        $run = $service->run();

        $this->assertSame('completed', $run->status);
        $this->assertDatabaseHas('acumatica_customers', [
            'acumatica_id' => 'CUS08548',
            'shipping_zone_id' => 'Z005',
        ]);
    }

    public function test_customer_show_endpoint_includes_shipping_zone(): void
    {
        AcumaticaShippingZone::query()->updateOrCreate(
            ['acumatica_id' => 'Z005'],
            [
                'description' => 'Mombasa Rd (Nairobi)',
                'name' => 'Mombasa Rd',
                'region' => 'Nairobi',
                'synced_at' => now(),
            ],
        );

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUS08548',
            'name' => 'Sample Customer',
            'status' => 'Active',
            'shipping_zone_id' => 'Z005',
            'synced_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/CUS08548')
            ->assertOk()
            ->assertJsonPath('shipping_zone_id', 'Z005')
            ->assertJsonPath('shipping_zone.acumatica_id', 'Z005')
            ->assertJsonPath('shipping_zone.description', 'Mombasa Rd (Nairobi)')
            ->assertJsonPath('shipping_zone.name', 'Mombasa Rd')
            ->assertJsonPath('shipping_zone.region', 'Nairobi');
    }

    public function test_shipping_zones_list_endpoint_returns_master_data(): void
    {
        AcumaticaShippingZone::query()->updateOrCreate(
            ['acumatica_id' => 'Z005'],
            [
                'description' => 'Mombasa Rd (Nairobi)',
                'name' => 'Mombasa Rd',
                'region' => 'Nairobi',
                'synced_at' => now(),
            ],
        );

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUS08548',
            'name' => 'Sample Customer',
            'status' => 'Active',
            'shipping_zone_id' => 'Z005',
            'synced_at' => now(),
        ]);

        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/customers/shipping-zones')
            ->assertOk();

        $zone = collect($response->json())->firstWhere('acumatica_id', 'Z005');
        $this->assertNotNull($zone);
        $this->assertSame('Mombasa Rd (Nairobi)', $zone['description']);
        $this->assertSame('Mombasa Rd', $zone['name']);
        $this->assertSame('Nairobi', $zone['region']);
        $this->assertSame(1, $zone['customer_count']);
    }

}