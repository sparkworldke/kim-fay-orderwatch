<?php

namespace Tests\Unit;

use App\Services\Operations\BackorderMetricsService;
use PHPUnit\Framework\TestCase;

class BackorderMetricsServiceTest extends TestCase
{
    public function test_summary_partitions_segment_and_stock_risk(): void
    {
        $rows = collect([
            (object) [
                'shortfall_kind' => 'active_backorder',
                'order_nbr' => 'SO1',
                'inventory_id' => 'FAY001',
                'brand' => 'Fay',
                'product_segment' => 'manufactured',
                'revenue_at_risk' => 1000,
                'backorder_qty' => 10,
                'stock_signal' => 'true_stockout',
                'fgs_synced_at' => '2026-07-26T08:00:00+03:00',
                'reason_code' => 'production_stockout',
                'first_backordered_at_is_backfilled' => false,
                'is_excluded_from_kpi' => false,
            ],
            (object) [
                'shortfall_kind' => 'active_backorder',
                'order_nbr' => 'SO2',
                'inventory_id' => 'DOV001',
                'brand' => 'Dove',
                'product_segment' => 'trading',
                'revenue_at_risk' => 2500,
                'backorder_qty' => 5,
                'stock_signal' => 'stock_available_not_shipped',
                'fgs_synced_at' => '2026-07-26T09:00:00+03:00',
                'reason_code' => null,
                'first_backordered_at_is_backfilled' => true,
                'is_excluded_from_kpi' => false,
            ],
        ]);

        $summary = (new BackorderMetricsService())->summarize($rows);

        $this->assertSame(3500.0, $summary['revenue_at_risk']);
        $this->assertSame(1000.0, $summary['by_product_segment']['manufactured']['revenue_at_risk']);
        $this->assertSame(2500.0, $summary['by_product_segment']['trading']['revenue_at_risk']);
        $this->assertSame(1000.0, $summary['stock_diagnosis']['rar_true_stockout']);
        $this->assertSame(2500.0, $summary['stock_diagnosis']['rar_stock_available_not_shipped']);
        $this->assertSame(50.0, $summary['data_quality']['reason_coverage_pct']);
    }
}
