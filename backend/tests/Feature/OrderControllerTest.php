<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejected_orders_require_a_reason_code(): void
    {
        $user = User::factory()->create([
            'role' => 'Sales Operations',
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-TEST-REJ-1',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 4500,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson('/api/orders/'.$order->id, [
                'status' => 'Rejected',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason_code']);
    }

    public function test_rejected_orders_can_store_reason_code_and_notes(): void
    {
        $user = User::factory()->create([
            'role' => 'Sales Operations',
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-TEST-REJ-2',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 5200,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson('/api/orders/'.$order->id, [
                'status' => 'Rejected',
                'rejection_reason_code' => 'out_of_stock',
                'rejection_reason' => 'Customer requested immediate delivery but stock is unavailable.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'Rejected')
            ->assertJsonPath('rejection_reason_code', 'out_of_stock')
            ->assertJsonPath('rejection_reason', 'Customer requested immediate delivery but stock is unavailable.');

        $this->assertDatabaseHas('acumatica_sales_orders', [
            'id' => $order->id,
            'status' => 'Rejected',
            'rejection_reason_code' => 'out_of_stock',
        ]);
    }

    public function test_sales_consultant_only_sees_their_own_orders_and_stats(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SC-OWN',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 1000,
            'sales_consultant_rep_code' => 'P505',
            'order_date' => now(),
            'synced_at' => now(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SC-OTHER',
            'order_type' => 'SO',
            'status' => 'Completed',
            'order_total' => 2000,
            'sales_consultant_rep_code' => 'P777',
            'order_date' => now(),
            'synced_at' => now(),
        ]);

        $this->actingAs($consultant, 'sanctum')
            ->getJson('/api/orders?order_type=SO')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.acumatica_order_nbr', 'SO-SC-OWN');

        $this->actingAs($consultant, 'sanctum')
            ->getJson('/api/orders/stats?order_type=SO')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('open', 1)
            ->assertJsonPath('completed', 0);

        $this->actingAs($consultant, 'sanctum')
            ->getJson('/api/orders/SO-SC-OTHER')
            ->assertNotFound();
    }

    public function test_sales_consultant_dashboard_kpis_reflect_only_their_orders(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'rep_code' => 'P505',
            'is_active' => true,
        ]);
        $today = now()->toDateString();

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SC-KPI-1',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 500,
            'sales_consultant_rep_code' => 'P505',
            'order_date' => $today,
            'synced_at' => now(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SC-KPI-2',
            'order_type' => 'SO',
            'status' => 'Completed',
            'order_total' => 900,
            'sales_consultant_rep_code' => 'P777',
            'order_date' => $today,
            'synced_at' => now(),
        ]);

        $this->actingAs($consultant, 'sanctum')
            ->getJson("/api/dashboard/kpis?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('open', 1)
            ->assertJsonPath('completed', 0);
    }
}
