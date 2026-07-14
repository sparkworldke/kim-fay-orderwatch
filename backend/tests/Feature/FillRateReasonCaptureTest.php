<?php

namespace Tests\Feature;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaShippingZone;
use App\Models\User;
use App\Services\Admin\FillRateCalculator;
use App\Services\Operations\FillRateBusinessCategory;
use App\Services\Operations\FillRateReasonCatalog;
use App\Services\Operations\FillRateReasonCaptureReport;
use App\Services\Operations\SalesOrderReasonCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FillRateReasonCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_reason_catalog_classifies_approved_and_unclassified_codes(): void
    {
        $catalog = new FillRateReasonCatalog(new SalesOrderReasonCatalog());

        $valid = $catalog->classify('supplier_delay');
        $this->assertSame('valid', $valid['issue']);
        $this->assertSame('Fill Rate Shortfall', $valid['parent_reason_label']);
        $this->assertSame('delay_in_delivery', $valid['sub_reason']);

        $missing = $catalog->classify(null);
        $this->assertSame(FillRateReasonCatalog::ISSUE_MISSING, $missing['issue']);

        $unclassified = $catalog->classify('unknown_reason');
        $this->assertSame(FillRateReasonCatalog::ISSUE_UNCLASSIFIED, $unclassified['issue']);
    }

    public function test_reason_capture_report_segments_by_business_category(): void
    {
        $report = new FillRateReasonCaptureReport(
            new FillRateReasonCatalog(new SalesOrderReasonCatalog()),
            new FillRateBusinessCategory(),
        );

        $lines = [
            (object) [
                'sales_order_id' => 1,
                'order_nbr' => 'SO-MFG',
                'inventory_id' => 'FAY001',
                'unfilled_reason_code' => 'supplier_delay',
                'qty_at_approval' => 10,
                'order_qty' => 10,
                'qty_on_shipments' => 4,
                'unit_price' => 100,
            ],
            (object) [
                'sales_order_id' => 2,
                'order_nbr' => 'SO-TRD',
                'inventory_id' => 'DOV001',
                'unfilled_reason_code' => null,
                'qty_at_approval' => 5,
                'order_qty' => 5,
                'qty_on_shipments' => 0,
                'unit_price' => 50,
            ],
            (object) [
                'sales_order_id' => 3,
                'order_nbr' => 'SO-BAD',
                'inventory_id' => 'VAT001',
                'unfilled_reason_code' => 'not_on_approved_list',
                'qty_at_approval' => 8,
                'order_qty' => 8,
                'qty_on_shipments' => 2,
                'unit_price' => 25,
            ],
        ];

        $result = $report->build($lines);

        $this->assertSame(3, $result['summary']['total_shortfall_lines']);
        $this->assertSame(1, $result['summary']['valid_reason_lines']);
        $this->assertSame(1, $result['summary']['missing_reason_lines']);
        $this->assertSame(1, $result['summary']['unclassified_reason_lines']);
        $this->assertSame(33.3, $result['summary']['capture_rate_pct']);

        $this->assertSame(1, $result['by_business_category']['manufactured']['line_count']);
        $this->assertSame(2, $result['by_business_category']['trading']['line_count']);
        $this->assertCount(2, $result['flagged_records']);
        $this->assertNotEmpty($result['breakdown']);
    }

    public function test_fill_rate_summary_includes_reason_capture_report_and_business_categories(): void
    {
        $user = User::factory()->create(['role' => 'Administrator', 'is_active' => true]);

        AcumaticaShippingZone::create([
            'acumatica_id' => 'NAIROBI',
            'description' => 'Nairobi',
            'name' => 'Nairobi',
            'region' => 'nairobi',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-KP',
            'name' => 'KP Customer',
            'customer_class' => 'KP Retail',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);
        AcumaticaInventoryItem::create([
            'inventory_id' => 'FAY100',
            'description' => 'Manufactured Item',
            'product_type' => 'manufactured',
            'qty_on_hand' => 10,
            'synced_at' => now(),
        ]);

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-RC',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-KP',
            'customer_name' => 'KP Customer',
            'order_date' => '2026-07-06',
            'approved_at' => '2026-07-06 08:00:00',
        ]);
        AcumaticaSalesOrderLine::create([
            'sales_order_id' => $order->id,
            'inventory_id' => 'FAY100',
            'description' => 'Manufactured Item',
            'order_qty' => 10,
            'shipped_qty' => 2,
            'qty_on_shipments' => 2,
            'qty_at_approval' => 10,
            'open_qty' => 8,
            'unit_price' => 100,
            'unfilled_reason_code' => 'out_of_stock_procurement',
        ]);
        AcumaticaFillRateSnapshot::create([
            'sales_order_id' => $order->id,
            'order_nbr' => 'SO-RC',
            'customer_acumatica_id' => 'CUST-KP',
            'status' => 'Completed',
            'total_ordered_qty' => 10,
            'total_shipped_qty' => 2,
            'fill_rate_pct' => 20,
            'fill_rate_status' => 'critical',
            'revenue_not_shipped' => 800,
            'computed_at' => '2026-07-06 12:00:00',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/fill-rate/summary?date_from=2026-07-01&date_to=2026-07-31&include_out_of_stock=1');

        $response->assertOk()
            ->assertJsonPath('excel_summary.by_segment.0.label', FillRateCalculator::SEGMENT_KP_LABEL)
            ->assertJsonPath('excel_summary.by_business_category.0.label', FillRateBusinessCategory::LABEL_MANUFACTURED)
            ->assertJsonPath('excel_summary.reason_capture_report.summary.valid_reason_lines', 1)
            ->assertJsonPath('excel_summary.reason_capture_report.summary.total_shortfall_lines', 1);

        // Default (exclude OOS) drops out-of-stock shortfall lines from capture report.
        $excluded = $this->actingAs($user, 'sanctum')
            ->getJson('/api/operations/fill-rate/summary?date_from=2026-07-01&date_to=2026-07-31&include_out_of_stock=0');
        $excluded->assertOk()
            ->assertJsonPath('include_out_of_stock', false)
            ->assertJsonPath('excel_summary.reason_capture_report.summary.total_shortfall_lines', 0);
    }
}