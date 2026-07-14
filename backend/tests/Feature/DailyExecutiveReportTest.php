<?php

namespace Tests\Feature;

use App\Mail\DailyManagementReportMail;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaShippingZone;
use App\Models\DailyReportConfig;
use App\Services\Reports\DailyExecutiveReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyExecutiveReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_executive_report_builds_four_section_payload(): void
    {
        $asOf = Carbon::parse('2026-07-08 08:00:00', 'Africa/Nairobi');
        $yesterday = $asOf->copy()->subDay();

        $this->seedOrder($yesterday, 'Open', 1000);
        $this->seedOrder($yesterday, 'Pending Approval', 500);
        $this->seedOrder($yesterday, 'Shipping', 750);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-KP',
            'name' => 'KP Buyer',
            'customer_class' => 'KP-RETAIL',
            'synced_at' => now(),
        ]);
        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-CS',
            'name' => 'CS Buyer',
            'customer_class' => 'CS-CONSUMER',
            'synced_at' => now(),
        ]);

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-KP',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-KP',
            'customer_name' => 'KP Buyer',
            'status' => 'Completed',
            'order_date' => $yesterday,
            'order_total' => 2000,
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-CS',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-CS',
            'customer_name' => 'CS Buyer',
            'status' => 'Completed',
            'order_date' => $yesterday,
            'order_total' => 3000,
        ]);

        $payload = app(DailyExecutiveReportService::class)->buildPayload($asOf, 'Africa/Nairobi');

        $this->assertSame('daily_executive_email', $payload['report_type']);
        $this->assertSame($yesterday->toDateString(), $payload['report_date']);
        $this->assertSame(5, $payload['orders']['week_totals']['total_orders']);
        $this->assertSame(2, $payload['orders']['week_totals']['completed_orders']);
        $this->assertSame(1, $payload['orders']['week_totals']['pending_approval']);
        $this->assertSame(1, $payload['orders']['week_totals']['in_shipping']);
        $this->assertNotEmpty($payload['orders']['daily_table']);
        $this->assertFalse(collect($payload['orders']['daily_table'])->contains(fn ($row) => str_starts_with((string) ($row['date_label'] ?? ''), 'Sun')));
        $this->assertSame(2000.0, $payload['revenue_split']['kp']);
        $this->assertSame(3000.0, $payload['revenue_split']['cs']);
        $this->assertArrayHasKey('fill_rate', $payload);
        $this->assertArrayHasKey('backorders', $payload);
        $this->assertArrayNotHasKey('top_skus', $payload['backorders']);
        $this->assertArrayNotHasKey('top_customers', $payload['backorders']);
        $this->assertArrayHasKey('nairobi', $payload['sla']);
        $this->assertArrayHasKey('mombasa', $payload['sla']);
    }

    public function test_prior_month_carryover_counts_incomplete_june_orders(): void
    {
        $asOf = Carbon::parse('2026-07-08 08:00:00', 'Africa/Nairobi');
        $priorMonth = $asOf->copy()->subDay()->subMonth();

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-OLD-PA',
            'order_type' => 'SO',
            'customer_name' => 'Old Customer',
            'status' => 'Pending Approval',
            'order_date' => $priorMonth->copy()->day(15),
            'order_total' => 400,
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-OLD-SH',
            'order_type' => 'SO',
            'customer_name' => 'Old Customer',
            'status' => 'Shipping',
            'order_date' => $priorMonth->copy()->day(20),
            'order_total' => 600,
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-OLD-DONE',
            'order_type' => 'SO',
            'customer_name' => 'Old Customer',
            'status' => 'Completed',
            'order_date' => $priorMonth->copy()->day(10),
            'order_total' => 900,
        ]);

        $payload = app(DailyExecutiveReportService::class)->buildPayload($asOf, 'Africa/Nairobi');
        $carryover = $payload['orders']['prior_month_carryover'];

        $this->assertTrue($carryover['show']);
        $this->assertSame(2, $carryover['total_incomplete']);
        $this->assertSame(1, $carryover['pending_approval']);
        $this->assertSame(1, $carryover['in_shipping']);
    }

    public function test_metro_sla_counts_only_uncompleted_orders_past_24_hours_as_delayed(): void
    {
        $asOf = Carbon::parse('2026-07-08 08:00:00', 'Africa/Nairobi');
        Carbon::setTestNow($asOf);
        $reportDate = $asOf->copy()->subDay();

        AcumaticaShippingZone::create([
            'acumatica_id' => 'NAIROBI',
            'description' => 'Nairobi',
            'name' => 'Nairobi',
            'region' => 'nairobi',
            'synced_at' => now(),
        ]);

        AcumaticaCustomer::create([
            'acumatica_id' => 'CUST-NBO',
            'name' => 'Nairobi Buyer',
            'shipping_zone_id' => 'NAIROBI',
            'synced_at' => now(),
        ]);

        $completed = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NBO-DONE',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-NBO',
            'customer_name' => 'Nairobi Buyer',
            'status' => 'Completed',
            'order_date' => $reportDate->copy()->setTime(6, 0),
            'approved_at' => $reportDate->copy()->setTime(6, 0),
            'completed_at' => $reportDate->copy()->addDay()->setTime(7, 30),
            'order_total' => 4000,
        ]);
        $open = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-NBO-OPEN',
            'order_type' => 'SO',
            'customer_acumatica_id' => 'CUST-NBO',
            'customer_name' => 'Nairobi Buyer',
            'status' => 'Shipping',
            'order_date' => $reportDate->copy()->setTime(6, 0),
            'approved_at' => $reportDate->copy()->setTime(6, 0),
            'ship_date' => $reportDate->copy()->setTime(10, 0),
            'order_total' => 6000,
        ]);

        foreach ([$completed, $open] as $order) {
            AcumaticaFillRateSnapshot::create([
                'sales_order_id' => $order->id,
                'order_nbr' => $order->acumatica_order_nbr,
                'customer_acumatica_id' => 'CUST-NBO',
                'status' => $order->status,
                'total_ordered_qty' => 10,
                'total_shipped_qty' => $order->status === 'Completed' ? 10 : 0,
                'fill_rate_pct' => $order->status === 'Completed' ? 100 : 0,
                'fill_rate_status' => 'ok',
                'revenue_not_shipped' => $order->status === 'Completed' ? 0 : 6000,
                'computed_at' => now(),
            ]);
        }

        $payload = app(DailyExecutiveReportService::class)->buildPayload($asOf, 'Africa/Nairobi');
        $nairobi = $payload['sla']['nairobi'];

        $this->assertSame(2, $nairobi['total_orders']);
        $this->assertSame(1, $nairobi['completed_orders']);
        $this->assertSame(1, $nairobi['undelivered_orders']);
        $this->assertSame(1, $nairobi['delayed_orders']);
        $this->assertSame(6000.0, $nairobi['delayed_value']);

        Carbon::setTestNow();
    }

    public function test_top_reasons_exclude_unassigned_and_use_yesterday_scope(): void
    {
        $asOf = Carbon::parse('2026-07-08 08:00:00', 'Africa/Nairobi');
        $yesterday = $asOf->copy()->subDay()->toDateString();

        $order = AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-YEST',
            'order_type' => 'SO',
            'customer_name' => 'Buyer',
            'status' => 'Open',
            'order_date' => $yesterday.' 10:00:00',
            'order_total' => 1000,
        ]);

        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-YEST',
            'inventory_id' => 'SKU-1',
            'customer_name' => 'Buyer',
            'open_qty' => 2,
            'revenue_at_risk' => 500,
            'reason_code' => 'supplier_delay',
            'synced_at' => now(),
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-OLD',
            'inventory_id' => 'SKU-2',
            'customer_name' => 'Old',
            'open_qty' => 99,
            'revenue_at_risk' => 99999,
            'reason_code' => null,
            'synced_at' => now(),
        ]);
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-OLD',
            'order_type' => 'SO',
            'customer_name' => 'Old',
            'status' => 'Open',
            'order_date' => '2026-05-01 10:00:00',
            'order_total' => 100,
        ]);

        $payload = app(\App\Services\Reports\DailyExecutiveReportService::class)
            ->buildPayload($asOf, 'Africa/Nairobi');

        $reasons = $payload['backorders']['top_reasons'];
        $this->assertNotEmpty($reasons);
        $this->assertSame('supplier_delay', $reasons[0]['reason_code']);
        $this->assertFalse(collect($reasons)->contains(fn ($r) => ($r['reason_code'] ?? '') === 'unassigned'));
    }

    public function test_payload_excludes_skus_and_customers(): void
    {
        $asOf = Carbon::parse('2026-07-08 08:00:00', 'Africa/Nairobi');
        $yesterday = $asOf->copy()->subDay()->toDateString();

        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO-SKU',
            'order_type' => 'SO',
            'customer_name' => 'Buyer',
            'status' => 'Open',
            'order_date' => $yesterday.' 12:00:00',
            'order_total' => 500,
        ]);
        AcumaticaBackorderLine::create([
            'order_nbr' => 'SO-SKU',
            'inventory_id' => 'FAYMU0004',
            'customer_name' => 'Buyer',
            'open_qty' => 1,
            'revenue_at_risk' => 2500,
            'reason_code' => 'inventory_shortage',
            'synced_at' => now(),
        ]);

        $payload = app(DailyExecutiveReportService::class)->buildPayload($asOf, 'Africa/Nairobi');

        $this->assertArrayNotHasKey('fill_rate_backorders', $payload);
        $this->assertArrayNotHasKey('top_skus', $payload['backorders']);
        $this->assertArrayNotHasKey('top_customers', $payload['backorders']);
    }

    public function test_executive_email_html_contains_all_sections(): void
    {
        config(['app.frontend_url' => 'https://orderwatch.test']);

        $mail = new DailyManagementReportMail(
            'OrderWatch Executive Exceptions – 7 Jul 2026',
            [
                'report_date_label' => '7 Jul 2026',
                'week' => ['label' => '6 Jul – 7 Jul 2026'],
                'generated_at_display' => '8 Jul 2026 08:00',
                'timezone' => 'Africa/Nairobi',
                'orders' => [
                    'week_totals' => ['total_orders' => 10, 'completed_orders' => 4, 'pending_approval' => 2, 'in_shipping' => 3],
                    'daily_table' => [
                        ['date_label' => 'Mon 6 Jul', 'total_orders' => 5, 'completed_orders' => 2, 'pending_approval' => 1, 'in_shipping' => 1],
                        ['date_label' => 'Tue 7 Jul', 'total_orders' => 5, 'completed_orders' => 2, 'pending_approval' => 1, 'in_shipping' => 2],
                    ],
                    'prior_month_carryover' => [
                        'show' => true,
                        'month_label' => 'June 2026',
                        'total_incomplete' => 371,
                        'pending_approval' => 16,
                        'in_shipping' => 111,
                    ],
                ],
                'fill_rate' => [
                    'fill_rate_pct' => 82.5,
                    'orders_tracked' => 198,
                    'revenue_not_shipped' => 35000000,
                ],
                'backorders' => [
                    'backorder_exposure_pct' => 15.0,
                    'revenue_at_risk' => 12826803,
                    'top_reasons' => [['reason_code' => 'inventory_shortage', 'line_count' => 3, 'revenue_at_risk' => 5000]],
                ],
                'sla' => [
                    'nairobi' => ['delayed_pct' => 12.5, 'delayed_orders' => 5, 'total_orders' => 40, 'completed_orders' => 20, 'delayed_value' => 50000],
                    'mombasa' => ['delayed_pct' => 8.0, 'delayed_orders' => 2, 'total_orders' => 25, 'completed_orders' => 15, 'delayed_value' => 20000],
                ],
                'revenue_split' => [
                    'date_label' => '7 Jul 2026',
                    'kp' => 100000,
                    'cs' => 80000,
                    'total' => 180000,
                    'unclassified' => 0,
                ],
            ],
            ['ai_status' => 'disabled'],
            DailyReportConfig::singleton(),
        );

        $html = $mail->render();

        $this->assertStringContainsString('1. Order Exceptions', $html);
        $this->assertStringContainsString('2. Fill Rate', $html);
        $this->assertStringContainsString('3. Backorders', $html);
        $this->assertStringContainsString('4. Revenue Split', $html);
        $this->assertStringNotContainsString('Prior month (June 2026):', $html);
        $this->assertStringNotContainsString('371 incomplete orders', $html);
        $this->assertStringNotContainsString('not delivered after 24h', $html);
        $this->assertStringNotContainsString('Nairobi &amp; Mombasa 24hr SLA', $html);
        $this->assertStringNotContainsString('Top 5 SKUs', $html);
        $this->assertStringNotContainsString('Top affected customers', $html);
        $this->assertStringContainsString('https://orderwatch.test/app', $html);
        $this->assertStringNotContainsString('Executive Summary', $html);
    }

    private function seedOrder(Carbon $date, string $status, float $total): void
    {
        AcumaticaSalesOrder::create([
            'acumatica_order_nbr' => 'SO'.uniqid(),
            'order_type' => 'SO',
            'customer_name' => 'Test Customer',
            'order_date' => $date,
            'status' => $status,
            'order_total' => $total,
        ]);
    }
}
