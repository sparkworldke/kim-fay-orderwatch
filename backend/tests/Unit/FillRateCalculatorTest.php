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

    public function test_computes_line_level_rollup(): void
    {
        $result = $this->calculator->compute('Open', [
            ['order_qty' => 10, 'shipped_qty' => 8, 'unit_price' => 100],
            ['order_qty' => 5, 'shipped_qty' => 5, 'unit_price' => 50],
        ]);

        $this->assertSame(86.67, $result['fill_rate_pct']);
        $this->assertSame('at_risk', $result['fill_rate_status']);
        $this->assertSame(200.0, $result['revenue_not_shipped']);
    }

    public function test_returns_na_for_on_hold(): void
    {
        $result = $this->calculator->compute('On Hold', [
            ['order_qty' => 10, 'shipped_qty' => 0, 'unit_price' => 100],
        ]);

        $this->assertNull($result['fill_rate_pct']);
        $this->assertSame('na', $result['fill_rate_status']);
    }

    public function test_skips_zero_quantity_lines(): void
    {
        $result = $this->calculator->compute('Open', [
            ['order_qty' => 0, 'shipped_qty' => 0, 'unit_price' => 0],
            ['order_qty' => 10, 'shipped_qty' => 10, 'unit_price' => 25],
        ]);

        $this->assertSame(100.0, $result['fill_rate_pct']);
    }

    public function test_uses_qty_at_approval_as_denominator(): void
    {
        $result = $this->calculator->compute('Open', [
            ['order_qty' => 100, 'qty_at_approval' => 40, 'shipped_qty' => 20, 'unit_price' => 10],
        ]);

        $this->assertSame(50.0, $result['fill_rate_pct']);
        $this->assertSame(40.0, $result['total_ordered_qty']);
        $this->assertSame(200.0, $result['revenue_not_shipped']);
    }

    public function test_returns_na_when_all_lines_have_zero_approval_qty(): void
    {
        $result = $this->calculator->compute('Open', [
            ['order_qty' => 0, 'qty_at_approval' => 0, 'shipped_qty' => 0, 'unit_price' => 10],
        ]);

        $this->assertNull($result['fill_rate_pct']);
        $this->assertSame('na', $result['fill_rate_status']);
    }

    public function test_threshold_status_boundaries(): void
    {
        $this->assertSame('healthy', $this->calculator->thresholdStatus(95));
        $this->assertSame('at_risk', $this->calculator->thresholdStatus(94.9));
        $this->assertSame('critical', $this->calculator->thresholdStatus(79.9));
    }

    public function test_rolls_up_duplicate_inventory_ids_before_computing_fill_rate(): void
    {
        $result = $this->calculator->compute('Open', [
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
}