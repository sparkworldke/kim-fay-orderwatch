<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsDataClearRangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_backorders_can_be_cleared_by_linked_order_date_range(): void
    {
        [$inside, $outside] = $this->makeOrders();
        AcumaticaBackorderLine::create(['order_nbr' => $inside->acumatica_order_nbr, 'inventory_id' => 'SKU-IN']);
        AcumaticaBackorderLine::create(['order_nbr' => $outside->acumatica_order_nbr, 'inventory_id' => 'SKU-OUT']);

        $this->asAdmin()->postJson('/api/admin/so-imports/truncate/backorders', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ])->assertOk()->assertJsonPath('deleted_count', 1)->assertJsonPath('clear_all', false);

        $this->assertDatabaseMissing('acumatica_backorder_lines', ['order_nbr' => $inside->acumatica_order_nbr]);
        $this->assertDatabaseHas('acumatica_backorder_lines', ['order_nbr' => $outside->acumatica_order_nbr]);
    }

    public function test_fill_rate_range_clear_uses_sales_order_id_and_order_number_fallback(): void
    {
        [$inside, $outside] = $this->makeOrders();
        AcumaticaFillRateSnapshot::create(['sales_order_id' => $inside->id, 'order_nbr' => 'SNAP-BY-ID']);
        AcumaticaFillRateSnapshot::create(['sales_order_id' => null, 'order_nbr' => $inside->acumatica_order_nbr]);
        AcumaticaFillRateSnapshot::create(['sales_order_id' => $outside->id, 'order_nbr' => $outside->acumatica_order_nbr]);

        $this->asAdmin()->postJson('/api/admin/so-imports/truncate/fill-rate', [
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
        ])->assertOk()->assertJsonPath('deleted_count', 2);

        $this->assertDatabaseCount('acumatica_fill_rate_snapshots', 1);
        $this->assertDatabaseHas('acumatica_fill_rate_snapshots', ['order_nbr' => $outside->acumatica_order_nbr]);
    }

    public function test_clear_all_requires_explicit_flag_and_removes_every_row(): void
    {
        [$inside, $outside] = $this->makeOrders();
        AcumaticaBackorderLine::create(['order_nbr' => $inside->acumatica_order_nbr, 'inventory_id' => 'SKU-IN']);
        AcumaticaBackorderLine::create(['order_nbr' => $outside->acumatica_order_nbr, 'inventory_id' => 'SKU-OUT']);

        $this->asAdmin()->postJson('/api/admin/so-imports/truncate/backorders', ['clear_all' => true])
            ->assertOk()
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('clear_all', true);

        $this->assertDatabaseCount('acumatica_backorder_lines', 0);
    }

    public function test_invalid_or_missing_clear_scope_does_not_delete_data(): void
    {
        [$inside] = $this->makeOrders();
        AcumaticaBackorderLine::create(['order_nbr' => $inside->acumatica_order_nbr, 'inventory_id' => 'SKU-IN']);

        $this->asAdmin()->postJson('/api/admin/so-imports/truncate/backorders', [
            'date_from' => '2026-07-31',
            'date_to' => '2026-07-01',
        ])->assertUnprocessable();
        $this->asAdmin()->postJson('/api/admin/so-imports/truncate/backorders', [])->assertUnprocessable();

        $this->assertDatabaseCount('acumatica_backorder_lines', 1);
    }

    public function test_manual_catalog_sync_endpoints_require_valid_date_metadata(): void
    {
        $admin = User::factory()->create(['role' => 'Administrator']);

        foreach (['customers', 'inventory', 'inventory-stocks'] as $endpoint) {
            $this->actingAs($admin, 'sanctum')
                ->postJson("/api/admin/acumatica/sync/{$endpoint}", [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['date_from', 'date_to']);
        }

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/acumatica/sync/customer-orders', [
                'customer_ids' => ['CUST-1'],
                'date_from' => '2026-07-31',
                'date_to' => '2026-07-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date_from']);
    }

    /** @return array{AcumaticaSalesOrder,AcumaticaSalesOrder} */
    private function makeOrders(): array
    {
        return [
            AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO-IN-RANGE', 'order_type' => 'SO', 'order_date' => '2026-07-15']),
            AcumaticaSalesOrder::create(['acumatica_order_nbr' => 'SO-OUT-RANGE', 'order_type' => 'SO', 'order_date' => '2026-06-15']),
        ];
    }

    private function asAdmin(): self
    {
        return $this->actingAs(User::factory()->create(['role' => 'Administrator']), 'sanctum');
    }
}
