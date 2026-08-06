<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SalesPortfolioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'Africa/Nairobi'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_portfolio_summary_for_consultant(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P900',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-P1',
            'name' => 'Portfolio Customer',
            'customer_class' => 'KP-HORECA',
            'status' => 'Active',
        ]);

        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-P1',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'C-P1',
            'customer_name' => 'Portfolio Customer',
            'sales_consultant_rep_code' => 'P900',
            'order_date' => '2026-07-10',
            'status' => 'Open',
            'order_total' => 50000,
        ]);

        $this->actingAs($consultant)
            ->getJson('/api/sales/portfolio/summary?mode=self')
            ->assertOk()
            ->assertJsonPath('kpis.customers_total', 1)
            ->assertJsonPath('kpis.orders_count', 1)
            ->assertJsonPath('kpis.revenue_mtd', 50000)
            ->assertJsonPath('top_movers.top_customers.0.customer_name', 'Portfolio Customer')
            ->assertJsonStructure(['plain_language', 'windows', 'top_movers']);
    }

    public function test_top_customers_falls_back_to_acumatica_customer_name_when_order_name_is_null(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P901',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-P2',
            'name' => 'Canonical Customer Name',
            'customer_class' => 'KP-HORECA',
            'status' => 'Active',
        ]);

        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-P2',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'C-P2',
            'customer_name' => null,
            'sales_consultant_rep_code' => 'P901',
            'order_date' => '2026-07-10',
            'status' => 'Open',
            'order_total' => 12000,
        ]);

        $this->actingAs($consultant)
            ->getJson('/api/sales/portfolio/summary?mode=self')
            ->assertOk()
            ->assertJsonPath('top_movers.top_customers.0.customer_name', 'Canonical Customer Name');
    }

    public function test_portfolio_summary_accepts_a_past_month_and_marks_it_not_current(): void
    {
        $consultant = User::factory()->create([
            'role' => 'Sales Consultant',
            'is_consultant' => true,
            'rep_code' => 'P902',
            'is_active' => true,
        ]);

        AcumaticaCustomer::query()->create([
            'acumatica_id' => 'C-P3',
            'name' => 'June Customer',
            'customer_class' => 'KP-HORECA',
            'status' => 'Active',
        ]);

        AcumaticaSalesOrder::query()->create([
            'acumatica_order_nbr' => 'SO-P3',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'C-P3',
            'customer_name' => 'June Customer',
            'sales_consultant_rep_code' => 'P902',
            'order_date' => '2026-06-15',
            'status' => 'Open',
            'order_total' => 30000,
        ]);

        $this->actingAs($consultant)
            ->getJson('/api/sales/portfolio/summary?mode=self&month=2026-06')
            ->assertOk()
            ->assertJsonPath('windows.month', '2026-06')
            ->assertJsonPath('windows.is_current_month', false)
            ->assertJsonPath('kpis.revenue_mtd', 30000);

        // A future month clamps back to the real current month instead of erroring.
        $this->actingAs($consultant)
            ->getJson('/api/sales/portfolio/summary?mode=self&month=2027-01')
            ->assertOk()
            ->assertJsonPath('windows.month', '2026-07')
            ->assertJsonPath('windows.is_current_month', true);
    }
}
