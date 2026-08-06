<?php

namespace Tests\Unit;

use App\Services\Admin\AiConnectorService;
use App\Services\AI\AiPromptLogService;
use App\Services\Reports\DailyManagementInsightService;
use Tests\TestCase;

class DailyManagementInsightServiceTest extends TestCase
{
    public function test_fallback_handles_executive_payload_without_top_level_yesterday(): void
    {
        $ai = $this->createStub(AiConnectorService::class);
        $ai->method('resolveKey')->willReturn([null, null]);

        $logger = $this->createStub(AiPromptLogService::class);

        $service = new DailyManagementInsightService($ai, $logger);

        $payload = [
            'report_type' => 'daily_executive_email',
            'report_date_label' => '16 Jul 2026',
            'orders' => [
                'yesterday' => [
                    'date_label' => 'Thu 16 Jul',
                    'total_orders' => 42,
                    'completed_orders' => 30,
                    'pending_approval' => 8,
                    'in_shipping' => 4,
                ],
                'week_totals' => [
                    'total_orders' => 100,
                    'completed_orders' => 70,
                    'pending_approval' => 20,
                    'in_shipping' => 10,
                ],
            ],
            'fill_rate' => [
                'fill_rate_pct' => 91.5,
                'orders_tracked' => 40,
                'revenue_not_shipped' => 12000,
            ],
            'backorders' => [
                'revenue_at_risk' => 5500,
                'top_reasons' => [
                    ['reason_label' => 'Stock shortage', 'reason_code' => 'STK'],
                ],
            ],
            'revenue_split' => [
                'total' => 250000,
                'kp' => 150000,
                'cs' => 100000,
            ],
        ];

        $insights = $service->generate($payload, true);

        $this->assertSame('unavailable', $insights['ai_status']);
        $this->assertStringContainsString('42 orders', $insights['executive_summary']);
        $this->assertStringContainsString('16 Jul 2026', $insights['executive_summary']);
        $this->assertStringContainsString('100 orders', $insights['performance_commentary']);
        $this->assertNotEmpty($insights['improvements']);
        $this->assertSame('Stock shortage', $insights['top_negative']);
    }

    public function test_fallback_handles_legacy_management_payload(): void
    {
        $ai = $this->createStub(AiConnectorService::class);
        $ai->method('resolveKey')->willReturn([null, null]);

        $service = new DailyManagementInsightService($ai, $this->createStub(AiPromptLogService::class));

        $payload = [
            'report_type' => 'daily_management_email',
            'yesterday' => [
                'orders_received' => 10,
                'total_order_value' => 5000,
                'completion_rate' => 80.0,
                'revenue_at_risk' => 1000,
                'outstanding_orders' => 2,
            ],
            'comparison' => [
                'orders_received' => ['direction' => 'up'],
            ],
            'mtd' => [
                'completion_rate' => 88.0,
                'orders_received' => 200,
            ],
            'risk' => [],
            'customer_highlights' => [],
        ];

        $insights = $service->generate($payload, true);

        $this->assertSame('unavailable', $insights['ai_status']);
        $this->assertStringContainsString('10 orders', $insights['executive_summary']);
        $this->assertStringContainsString('80.0%', $insights['executive_summary']);
    }

    public function test_fallback_handles_empty_payload_without_throwing(): void
    {
        $ai = $this->createStub(AiConnectorService::class);
        $ai->method('resolveKey')->willReturn([null, null]);

        $service = new DailyManagementInsightService($ai, $this->createStub(AiPromptLogService::class));

        $insights = $service->generate([], true);

        $this->assertSame('unavailable', $insights['ai_status']);
        $this->assertNotSame('', $insights['executive_summary']);
        $this->assertNotEmpty($insights['improvements']);
    }
}
