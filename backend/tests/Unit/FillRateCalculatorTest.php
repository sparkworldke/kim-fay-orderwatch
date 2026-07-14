<?php

namespace Tests\Unit;

use App\Services\Admin\FillRateCalculator;
use PHPUnit\Framework\TestCase;

class FillRateCalculatorTest extends TestCase
{
    private FillRateCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FillRateCalculator;
    }

    public function test_computes_shipped_over_order_qty_for_completed_only(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 10, 'shipped_qty' => 8, 'unit_price' => 100],
            ['order_qty' => 5, 'shipped_qty' => 5, 'unit_price' => 50],
        ]);

        // (8+5) / (10+5) = 86.67%
        $this->assertSame(86.67, $result['fill_rate_pct']);
        $this->assertSame('at_risk', $result['fill_rate_status']);
        $this->assertSame(15.0, $result['total_ordered_qty']);
        $this->assertSame(13.0, $result['total_shipped_qty']);
        $this->assertSame(200.0, $result['revenue_not_shipped']);
        $this->assertSame(0, $result['out_of_stock_line_count']);
    }

    public function test_returns_na_for_non_completed_statuses(): void
    {
        foreach (['Open', 'Shipping', 'On Hold', 'Pending Approval', 'Rejected'] as $status) {
            $result = $this->calculator->compute($status, [
                ['order_qty' => 10, 'shipped_qty' => 10, 'unit_price' => 100],
            ]);

            $this->assertNull($result['fill_rate_pct'], "Expected NA for status {$status}");
            $this->assertSame('na', $result['fill_rate_status']);
        }
    }

    public function test_uses_order_qty_not_qty_at_approval(): void
    {
        // Order 100, approval 40, shipped 20 → fill = 20/100 = 20% (not 20/40)
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 100, 'qty_at_approval' => 40, 'shipped_qty' => 20, 'unit_price' => 10],
        ]);

        $this->assertSame(20.0, $result['fill_rate_pct']);
        $this->assertSame(100.0, $result['total_ordered_qty']);
        $this->assertSame(20.0, $result['total_shipped_qty']);
        $this->assertSame(800.0, $result['revenue_not_shipped']);
    }

    public function test_falls_back_to_qty_on_shipments_when_shipped_missing(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 10, 'qty_on_shipments' => 7, 'unit_price' => 5],
        ]);

        $this->assertSame(70.0, $result['fill_rate_pct']);
        $this->assertSame(7.0, $result['total_shipped_qty']);
    }

    public function test_skips_zero_quantity_lines(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 0, 'shipped_qty' => 0, 'unit_price' => 0],
            ['order_qty' => 10, 'shipped_qty' => 10, 'unit_price' => 25],
        ]);

        $this->assertSame(100.0, $result['fill_rate_pct']);
    }

    public function test_returns_na_when_all_lines_have_zero_order_qty(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 0, 'shipped_qty' => 0, 'unit_price' => 10],
        ]);

        $this->assertNull($result['fill_rate_pct']);
        $this->assertSame('na', $result['fill_rate_status']);
    }

    public function test_counts_zero_shipped_lines(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 10, 'shipped_qty' => 0, 'unit_price' => 100],
            ['order_qty' => 5, 'shipped_qty' => 5, 'unit_price' => 50],
        ]);

        $this->assertSame(33.33, $result['fill_rate_pct']);
        $this->assertSame(1, $result['out_of_stock_line_count']);
        $this->assertSame(1000.0, $result['revenue_not_shipped']);
    }

    public function test_can_exclude_out_of_stock_reason_lines_from_fill_rate(): void
    {
        $lines = [
            [
                'inventory_id' => 'A',
                'order_qty' => 10,
                'shipped_qty' => 0,
                'unit_price' => 10,
                'unfilled_reason_code' => 'out_of_stock_procurement',
            ],
            [
                'inventory_id' => 'B',
                'order_qty' => 10,
                'shipped_qty' => 10,
                'unit_price' => 10,
                'unfilled_reason_code' => null,
            ],
        ];

        $withOos = $this->calculator->compute('Completed', $lines, includeOutOfStock: true);
        $withoutOos = $this->calculator->compute('Completed', $lines, includeOutOfStock: false);

        $this->assertSame(50.0, $withOos['fill_rate_pct']);
        $this->assertSame(100.0, $withoutOos['fill_rate_pct']);
        $this->assertSame(0.0, $withoutOos['revenue_not_shipped']);
    }

    public function test_threshold_status_boundaries(): void
    {
        $this->assertSame('healthy', $this->calculator->thresholdStatus(95));
        $this->assertSame('at_risk', $this->calculator->thresholdStatus(94.9));
        $this->assertSame('critical', $this->calculator->thresholdStatus(79.9));
    }

    public function test_rolls_up_duplicate_inventory_ids_before_computing_fill_rate(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['inventory_id' => 'SKU-A', 'order_qty' => 5, 'shipped_qty' => 3, 'unit_price' => 10],
            ['inventory_id' => 'SKU-A', 'order_qty' => 5, 'shipped_qty' => 2, 'unit_price' => 10],
            ['inventory_id' => 'SKU-B', 'order_qty' => 10, 'shipped_qty' => 10, 'unit_price' => 20],
        ]);

        $this->assertSame(2, $result['unique_item_count']);
        $this->assertSame(20.0, $result['total_ordered_qty']);
        $this->assertSame(15.0, $result['total_shipped_qty']);
        $this->assertSame(75.0, $result['fill_rate_pct']);
        $this->assertSame(50.0, $result['revenue_not_shipped']);
    }

    public function test_caps_fill_rate_at_100_when_overshipped(): void
    {
        $result = $this->calculator->compute('Completed', [
            ['order_qty' => 10, 'shipped_qty' => 15, 'unit_price' => 1],
        ]);

        $this->assertSame(100.0, $result['fill_rate_pct']);
        $this->assertSame(10.0, $result['total_shipped_qty']);
    }
}
