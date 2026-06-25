<?php

namespace Tests\Feature;

use App\Mail\DailyManagementReportMail;
use App\Models\AcumaticaSalesOrder;
use App\Models\DailyReportConfig;
use App\Models\DailyReportRun;
use App\Models\User;
use App\Services\Reports\DailyManagementReportService;
use App\Services\Reports\DailyReportRunnerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyManagementReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_service_builds_yesterday_mtd_and_comparison_payload(): void
    {
        $yesterday = now('Africa/Nairobi')->subDay();
        $dayBefore = now('Africa/Nairobi')->subDays(2);

        $this->createOrder($yesterday, 'Completed', 1000);
        $this->createOrder($yesterday, 'Open', 500);
        $this->createOrder($dayBefore, 'Completed', 800);

        $payload = app(DailyManagementReportService::class)->buildPayload(now('Africa/Nairobi'), 'Africa/Nairobi');

        $this->assertSame($yesterday->toDateString(), $payload['report_date']);
        $this->assertSame($yesterday->format('d/m/Y'), $payload['report_date_display']);
        $this->assertSame($yesterday->copy()->startOfMonth()->format('F Y'), $payload['mtd_period_label']);
        $this->assertArrayHasKey('sentiment', $payload['comparison']['orders_received']);
        $this->assertSame(2, $payload['yesterday']['orders_received']);
        $this->assertSame(1, $payload['yesterday']['orders_completed']);
        $this->assertSame(500.0, $payload['yesterday']['revenue_at_risk']);
        $this->assertArrayHasKey('comparison', $payload);
        $this->assertArrayHasKey('mtd', $payload);
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
            return str_contains($mail->envelope()->subject, 'OrderWatch')
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

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/admin/daily-reports/config', [
                'recipients' => ['manager@kimfay.test'],
                'reply_to' => ['customercare@kimfay.test', 'cco@kimfay.test'],
                'send_time' => '07:30',
            ])
            ->assertOk()
            ->assertJsonPath('send_time', '07:30')
            ->assertJsonPath('recipients.0', 'manager@kimfay.test')
            ->assertJsonPath('reply_to.0', 'customercare@kimfay.test');

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
            'OrderWatch Daily Brief',
            [
                'report_date_label' => '24 Jun 2026',
                'report_date_display' => '24/06/2026',
                'comparison_date_display' => '23/06/2026',
                'mtd_period_label' => 'June 2026',
                'generated_at_display' => '25 Jun 2026 08:00',
                'timezone' => 'Africa/Nairobi',
                'yesterday' => [],
                'mtd' => [],
                'comparison' => [],
                'risk' => [],
                'customer_highlights' => [],
                'formulas' => [],
            ],
            [
                'executive_summary' => 'Summary',
                'performance_commentary' => 'Commentary',
                'improvements' => [],
            ],
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
                'report_date_label' => '23 Jun 2026',
                'yesterday' => ['orders_received' => 10, 'completion_rate' => 80],
                'insights' => ['executive_summary' => 'Cached summary', 'improvements' => []],
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
            'customer_name' => 'Test Customer',
            'order_date' => $date,
            'status' => $status,
            'order_total' => $total,
        ]);
    }
}