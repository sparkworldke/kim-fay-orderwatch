<?php

namespace Tests\Feature;

use App\Mail\DailyManagementReportMail;
use App\Models\CronJob;
use App\Models\DailyReportConfig;
use App\Services\Reports\DailyReportRunnerService;
use Illuminate\Console\Scheduling\Schedule;
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
        // One message only (ops is To; manager is CC — not a second send).
        Mail::assertSentCount(1);
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

    public function test_runner_does_not_double_send_without_force(): void
    {
        Mail::fake();

        $config = DailyReportConfig::singleton();
        $config->update([
            'is_enabled' => true,
            'include_ai_insights' => false,
            'reply_to_json' => ['ops@example.com'],
            'recipients_json' => ['ops@example.com'],
        ]);

        $runner = app(DailyReportRunnerService::class);

        $first = $runner->run($config, 'scheduler', force: false, ignoreSendTimeWindow: true);
        $this->assertSame('completed', $first->status);
        Mail::assertSentCount(1);

        $second = $runner->run($config, 'scheduler', force: false, ignoreSendTimeWindow: true);
        $this->assertSame('skipped', $second->status);
        $this->assertStringContainsString('already sent', strtolower((string) $second->error_summary));
        Mail::assertSentCount(1);
    }

    public function test_daily_report_is_registered_only_once_in_schedule(): void
    {
        // Create a legacy cron_jobs row that would previously double-register.
        CronJob::query()->create([
            'job_key' => 'legacy-daily-report',
            'name' => 'Legacy Daily Report',
            'description' => 'Should be ignored by dynamic scheduler',
            'is_enabled' => true,
            'cron_expression' => '0 7 * * 2-6',
            'frequency_label' => 'Daily',
            'trigger_type' => 'scheduler',
            'command' => 'php artisan orderwatch:send-daily-report-fixed --source=scheduler',
            'status' => 'active',
            'settings' => [],
        ]);

        // Rebuild the application so routes/console.php re-registers with the legacy row present.
        $this->refreshApplication();

        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events())->filter(function ($event): bool {
            return isset($event->command)
                && is_string($event->command)
                && str_contains($event->command, 'send-daily-report');
        });

        $this->assertCount(1, $events, 'Daily report command must appear only once on the schedule');
    }
}

