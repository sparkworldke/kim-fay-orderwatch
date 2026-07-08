<?php

namespace Tests\Feature;

use App\Mail\DailyManagementReportMail;
use App\Models\AcumaticaSalesOrder;
use App\Models\DailyReportConfig;
use App\Models\DailyReportRun;
use App\Models\User;
use App\Services\Reports\DailyExecutiveReportService;
use App\Services\Reports\DailyReportRunnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyManagementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_service_builds_executive_payload(): void
    {
        $yesterday = now('Africa/Nairobi')->subDay();

        $this->createOrder($yesterday, 'Completed', 1000);
        $this->createOrder($yesterday, 'Pending Approval', 500);

        $payload = app(DailyExecutiveReportService::class)->buildPayload(now('Africa/Nairobi'), 'Africa/Nairobi');

        $this->assertSame('daily_executive_email', $payload['report_type']);
        $this->assertSame($yesterday->toDateString(), $payload['report_date']);
        $this->assertSame($yesterday->format('d/m/Y'), $payload['report_date_display']);
        $this->assertArrayHasKey('orders', $payload);
        $this->assertArrayHasKey('fill_rate', $payload);
        $this->assertArrayHasKey('backorders', $payload);
        $this->assertArrayHasKey('sla', $payload);
        $this->assertArrayHasKey('revenue_split', $payload);
    }

    public function test_scheduled_runner_skips_when_not_send_time(): void
    {
        DailyReportConfig::singleton()->update([
            'is_enabled' => true,
            'send_time' => '08:00',
            'timezone' => 'Africa/Nairobi',
            'recipients_json' => ['ops@kimfay.test'],
        ]);

        $atNine = Carbon::parse('2026-06-24 09:00:00', 'Africa/Nairobi');
        $runner = app(DailyReportRunnerService::class);

        $this->assertFalse($runner->shouldRunScheduled(DailyReportConfig::singleton(), $atNine));
    }

    public function test_force_run_sends_email_and_logs_run(): void
    {
        Mail::fake();

        $yesterday = now('Africa/Nairobi')->subDay();
        $this->createOrder($yesterday, 'Completed', 1200);

        DailyReportConfig::singleton()->update([
            'is_enabled' => true,
            'reply_to_json' => ['customercare@kimfay.test', 'cco@kimfay.test'],
            'recipients_json' => ['director@kimfay.test'],
            'include_ai_insights' => false,
        ]);

        $run = app(DailyReportRunnerService::class)->run(
            DailyReportConfig::singleton(),
            'manual_test',
            true,
        );

        $this->assertSame('completed', $run->status);
        $this->assertSame('sent', $run->delivery_status);
        $this->assertSame(3, $run->recipient_count);
        $this->assertNotNull($run->payload_json);
        $this->assertDatabaseHas('daily_report_delivery_logs', [
            'daily_report_run_id' => $run->id,
            'recipient_email' => 'customercare@kimfay.test',
            'recipient_role' => 'to',
            'delivery_status' => 'sent',
        ]);
        $this->assertDatabaseHas('daily_report_delivery_logs', [
            'daily_report_run_id' => $run->id,
            'recipient_email' => 'director@kimfay.test',
            'recipient_role' => 'cc',
            'delivery_status' => 'sent',
        ]);

        Mail::assertSent(DailyManagementReportMail::class, function ($mail) {
            return str_contains($mail->envelope()->subject, 'Executive Exceptions')
                && $mail->hasTo('customercare@kimfay.test')
                && $mail->hasTo('cco@kimfay.test')
                && $mail->hasCc('director@kimfay.test')
                && count($mail->envelope()->replyTo) === 2;
        });
    }

    public function test_admin_can_manage_daily_report_config_and_test_send(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'Administrator', 'is_super_admin' => true, 'is_active' => true]);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/daily-reports/config')
            ->assertOk()
            ->assertJsonPath('send_time', '08:00');

        $response = $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/daily-reports/config', [
                'recipients' => ['manager@kimfay.test'],
                'reply_to' => ['customercare@kimfay.test', 'cco@kimfay.test'],
                'send_time' => '07:30',
            ])
            ->assertOk()
            ->assertJsonPath('send_time', '07:30')
            ->assertJsonPath('reply_to.0', 'customercare@kimfay.test')
            ->assertJsonPath('reply_to.1', 'cco@kimfay.test');

        $payload = $response->json();
        $this->assertIsArray($payload['recipients'] ?? null);
        $this->assertContains('manager@kimfay.test', $payload['recipients']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/daily-reports/test-send')
            ->assertOk();

        $this->assertSame(1, DailyReportRun::count());
        Mail::assertSent(DailyManagementReportMail::class);
    }

    public function test_daily_report_email_uses_frontend_url_for_dashboard_link(): void
    {
        config([
            'app.url' => 'https://api.orderwatch.test',
            'app.frontend_url' => 'https://orderwatch.test',
        ]);

        $mail = new DailyManagementReportMail(
            'OrderWatch Executive Exceptions – 24 Jun 2026',
            [
                'report_date_label' => '24 Jun 2026',
                'week' => ['label' => '23 Jun – 24 Jun 2026'],
                'generated_at_display' => '25 Jun 2026 08:00',
                'timezone' => 'Africa/Nairobi',
                'orders' => ['week_totals' => ['total_orders' => 0, 'pending_approval' => 0, 'in_shipping' => 0], 'daily_table' => [], 'prior_month_carryover' => ['show' => false]],
                'fill_rate' => ['fill_rate_pct' => null, 'orders_tracked' => 0, 'revenue_not_shipped' => 0],
                'backorders' => ['backorder_exposure_pct' => 0, 'revenue_at_risk' => 0, 'top_reasons' => []],
                'sla' => ['nairobi' => ['delayed_pct' => 0, 'delayed_orders' => 0, 'total_orders' => 0, 'delayed_value' => 0], 'mombasa' => ['delayed_pct' => 0, 'delayed_orders' => 0, 'total_orders' => 0, 'delayed_value' => 0]],
                'revenue_split' => ['date_label' => '24 Jun 2026', 'kp' => 0, 'cs' => 0, 'total' => 0, 'unclassified' => 0],
            ],
            ['ai_status' => 'disabled'],
            DailyReportConfig::singleton(),
        );

        $html = $mail->render();

        $this->assertStringContainsString('https://orderwatch.test/app', $html);
        $this->assertStringNotContainsString('api.orderwatch.test', $html);
    }

    public function test_resend_last_reuses_saved_payload(): void
    {
        Mail::fake();

        DailyReportConfig::singleton()->update([
            'reply_to_json' => ['customercare@kimfay.test'],
            'recipients_json' => ['ops@kimfay.test'],
        ]);

        $original = DailyReportRun::create([
            'report_config_id' => DailyReportConfig::singleton()->id,
            'report_date' => now()->subDay()->toDateString(),
            'started_at' => now(),
            'completed_at' => now(),
            'sent_at' => now(),
            'status' => 'completed',
            'ai_status' => 'success',
            'delivery_status' => 'sent',
            'recipient_count' => 1,
            'payload_json' => [
                'report_type' => 'daily_executive_email',
                'report_date_label' => '23 Jun 2026',
                'orders' => ['week_totals' => ['total_orders' => 10, 'pending_approval' => 1, 'in_shipping' => 2], 'daily_table' => [], 'prior_month_carryover' => ['show' => false]],
                'fill_rate' => [],
                'backorders' => [],
                'sla' => [],
                'revenue_split' => [],
                'insights' => ['ai_status' => 'disabled'],
            ],
        ]);

        $resent = app(DailyReportRunnerService::class)->resendLast(DailyReportConfig::singleton());

        $this->assertNotNull($resent);
        $this->assertSame('completed', $resent->status);
        Mail::assertSent(DailyManagementReportMail::class);
        $this->assertNotSame($original->id, $resent->id);
    }

    private function createOrder(Carbon $date, string $status, float $total): void
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
