<?php

namespace Tests\Feature;

use App\Mail\DailyManagementReportMail;
use App\Models\DailyReportConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyReportFixedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_daily_report_command_sends_email_synchronously(): void
    {
        Mail::fake();

        $config = DailyReportConfig::singleton();
        $config->update([
            'is_enabled' => true,
            'include_ai_insights' => false,
            'reply_to_json' => ['ops@example.com'],
            'recipients_json' => ['ops@example.com', 'manager@example.com'],
        ]);

        $this->artisan('orderwatch:send-daily-report-fixed --source=scheduler')
            ->assertExitCode(0);

        Mail::assertSent(DailyManagementReportMail::class, function (DailyManagementReportMail $mail): bool {
            return $mail->hasTo('ops@example.com');
        });
    }

    public function test_fixed_daily_report_can_be_resent_with_force(): void
    {
        Mail::fake();

        DailyReportConfig::singleton()->update([
            'is_enabled' => true,
            'include_ai_insights' => false,
            'reply_to_json' => ['ops@example.com'],
            'recipients_json' => ['ops@example.com'],
        ]);

        $this->artisan('orderwatch:send-daily-report-fixed --source=manual')
            ->assertExitCode(0);

        Mail::assertSentCount(1);

        $this->artisan('orderwatch:send-daily-report-fixed --source=manual')
            ->expectsOutputToContain('Skipped: Report already sent')
            ->assertExitCode(0);

        Mail::assertSentCount(1);

        $this->artisan('orderwatch:send-daily-report-fixed --source=manual --force')
            ->expectsOutputToContain('Resending report for')
            ->assertExitCode(0);

        Mail::assertSentCount(2);
    }
}

