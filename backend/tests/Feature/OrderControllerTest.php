<?php

namespace Tests\Feature;

use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfillment_fill_rate_is_quantity_weighted_instead_of_averaging_lines(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations']);
        $order = $this->makeOrderWithLines('SO-WEIGHTED-FILL', [
            ['order_qty' => 10, 'shipped_qty' => 6, 'qty_on_shipments' => 0, 'fill_rate_pct' => 60],
            ['order_qty' => 3, 'shipped_qty' => 3, 'qty_on_shipments' => 0, 'fill_rate_pct' => 100],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/orders?with_fulfillment=1&customer_id=CUST-FILL')
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.lines_avg_fill_rate_pct', 69.23);
    }

    public function test_fulfillment_fill_rate_uses_the_larger_shipped_source(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations']);
        $this->makeOrderWithLines('SO-SHIPPED-SOURCE', [
            ['order_qty' => 10, 'shipped_qty' => 4, 'qty_on_shipments' => 7],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/orders?with_fulfillment=1&customer_id=CUST-FILL')
            ->assertOk()
            ->assertJsonPath('data.0.lines_avg_fill_rate_pct', 70);
    }

    public function test_fulfillment_fill_rate_caps_each_line_at_ordered_quantity(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations']);
        $this->makeOrderWithLines('SO-CAPPED-FILL', [
            ['order_qty' => 10, 'shipped_qty' => 12, 'qty_on_shipments' => 15],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/orders?with_fulfillment=1&customer_id=CUST-FILL')
            ->assertOk()
            ->assertJsonPath('data.0.lines_avg_fill_rate_pct', 100);
    }

    public function test_fulfillment_fill_rate_excludes_non_positive_order_quantities(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations']);
        $this->makeOrderWithLines('SO-IGNORED-FILL-LINES', [
            ['order_qty' => 10, 'shipped_qty' => 5, 'qty_on_shipments' => 0],
            ['order_qty' => 0, 'shipped_qty' => 100, 'qty_on_shipments' => 100],
            ['order_qty' => -5, 'shipped_qty' => 100, 'qty_on_shipments' => 100],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/orders?with_fulfillment=1&customer_id=CUST-FILL')
            ->assertOk()
            ->assertJsonPath('data.0.lines_avg_fill_rate_pct', 50);
    }

    public function test_fulfillment_fill_rate_is_null_without_positive_order_quantity(): void
    {
        $user = User::factory()->create(['role' => 'Sales Operations']);
        $this->makeOrderWithLines('SO-NO-FILL-DENOMINATOR', [
            ['order_qty' => 0, 'shipped_qty' => 4, 'qty_on_shipments' => 7],
            ['order_qty' => -2, 'shipped_qty' => 1, 'qty_on_shipments' => 1],
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/orders?with_fulfillment=1&customer_id=CUST-FILL')
            ->assertOk()
            ->assertJsonPath('data.0.lines_avg_fill_rate_pct', null);
    }

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
                'rejection_reason_code' => 'out_of_stock_procurement',
                'rejection_reason' => 'Customer requested immediate delivery but stock is unavailable.',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'Rejected')
            ->assertJsonPath('rejection_reason_code', 'out_of_stock_procurement')
            ->assertJsonPath('rejection_reason', 'Customer requested immediate delivery but stock is unavailable.')
            ->assertJsonPath('workflow_parent_reason', 'rejected_order')
            ->assertJsonPath('workflow_sub_reason_code', 'out_of_stock_procurement')
            ->assertJsonPath('workflow_reason_label', 'Rejected Order - Out of stock - Procurement');

        $this->assertDatabaseHas('acumatica_sales_orders', [
            'id' => $order->id,
            'status' => 'Rejected',
            'rejection_reason_code' => 'out_of_stock_procurement',
            'workflow_parent_reason' => 'rejected_order',
            'workflow_sub_reason_code' => 'out_of_stock_procurement',
            'workflow_reason_label' => 'Rejected Order - Out of stock - Procurement',
        ]);
    }

    public function test_cancelled_and_on_hold_orders_require_a_reason_code(): void
    {
        $user = User::factory()->create([
            'role' => 'Sales Operations',
        ]);

        $cancelled = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-TEST-CAN-1',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 1200,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson('/api/orders/'.$cancelled->id, ['status' => 'Cancelled'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason_code']);

        $onHold = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-TEST-HOLD-1',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 1800,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson('/api/orders/'.$onHold->id, ['status' => 'On Hold'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['rejection_reason_code']);
    }

    public function test_cancelled_orders_persist_hierarchical_workflow_reason(): void
    {
        $user = User::factory()->create([
            'role' => 'Sales Operations',
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-TEST-CAN-2',
            'order_type' => 'SO',
            'status' => 'Open',
            'order_total' => 2400,
            'synced_at' => now(),
        ]);

        $this->actingAs($user)
            ->patchJson('/api/orders/'.$order->id, [
                'status' => 'Cancelled',
                'rejection_reason_code' => 'wrong_code',
            ])
            ->assertOk()
            ->assertJsonPath('workflow_parent_reason', 'cancelled_order')
            ->assertJsonPath('workflow_sub_reason_code', 'wrong_code')
            ->assertJsonPath('workflow_reason_label', 'Cancelled Order - Wrong code');
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

    /** @param array<int, array<string, int|float>> $lines */
    private function makeOrderWithLines(string $orderNumber, array $lines): AcumaticaSalesOrder
    {
        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => $orderNumber,
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-FILL',
            'status' => 'Open',
            'order_date' => now(),
            'synced_at' => now(),
        ]);

        foreach ($lines as $index => $line) {
            AcumaticaSalesOrderLine::create(array_merge([
                'sales_order_id' => $order->id,
                'line_nbr' => $index + 1,
                'inventory_id' => 'SKU-'.($index + 1),
            ], $line));
        }

        return $order;
    }
}
