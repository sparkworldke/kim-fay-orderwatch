<?php

namespace Tests\Feature;

use App\Models\AcumaticaBackorderLine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditBackorderReasonUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_reason_audit_is_read_only_and_reports_usage(): void
    {
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-AUDIT',
            'inventory_id' => 'SKU-AUDIT',
            'order_qty' => 2,
            'shipped_qty' => 0,
            'open_qty' => 2,
            'unit_price' => 10,
            'revenue_at_risk' => 20,
            'reason_code' => 'delay_in_delivery',
            'synced_at' => now(),
        ]);

        $this->artisan('orderwatch:audit-backorder-reasons', ['--days' => 90])
            ->expectsTable(
                ['Reason code', 'Last 90 days', 'Stored references', 'Recommendation'],
                collect(AcumaticaBackorderLine::REASON_CODES)->map(fn (string $code) => [
                    $code,
                    $code === 'delay_in_delivery' ? 1 : 0,
                    $code === 'delay_in_delivery' ? 1 : 0,
                    $code === 'delay_in_delivery' ? 'keep' : 'retire',
                ])->all(),
            )
            ->assertSuccessful();

        $this->assertDatabaseHas('acumatica_backorder_lines', ['order_nbr' => 'SO-AUDIT']);
    }
}
