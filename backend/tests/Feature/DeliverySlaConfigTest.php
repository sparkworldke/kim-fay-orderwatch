<?php

namespace Tests\Feature;

use App\Models\DeliverySlaConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliverySlaConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_read_delivery_sla_config(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/delivery-sla-config')
            ->assertOk()
            ->assertJsonFragment(['region_key' => 'nairobi', 'sla_hours' => 24])
            ->assertJsonFragment(['region_key' => 'coast', 'sla_hours' => 24])
            ->assertJsonFragment(['region_key' => 'other', 'warning_hours' => 48]);
    }

    public function test_admin_can_update_delivery_sla_config(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/admin/delivery-sla-config', [
                'rules' => DeliverySlaConfig::query()->orderBy('region_key')->get()->map(fn (DeliverySlaConfig $rule) => [
                    ...$rule->toPublicArray(),
                    'is_active' => true,
                    'sla_hours' => $rule->region_key === 'nairobi' ? 20 : $rule->sla_hours,
                ])->values()->all(),
            ])
            ->assertOk()
            ->assertJsonPath('0.region_key', 'coast');

        $this->assertDatabaseHas('delivery_sla_config', [
            'region_key' => 'nairobi',
            'sla_hours' => 20,
        ]);
    }

    public function test_business_optimization_includes_delivery_sla_payload(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);
        $from = now()->startOfMonth()->toDateString();
        $to = now()->toDateString();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/operations/business-optimization?date_from={$from}&date_to={$to}&region=nairobi")
            ->assertOk()
            ->assertJsonStructure([
                'delivery_sla' => [
                    'rules',
                    'summary' => [
                        'total_orders',
                        'delayed_count',
                        'on_time_count',
                    ],
                    'by_region',
                    'most_affected_zones',
                    'daily_trend',
                    'delayed_orders',
                ],
                'zone_guardrails' => [
                    'unmapped_customer_count',
                    'unmapped_with_orders_in_period',
                ],
                'filters' => [
                    'selected_region',
                    'region_options',
                ],
            ])
            ->assertJsonPath('filters.selected_region', 'nairobi');
    }
}