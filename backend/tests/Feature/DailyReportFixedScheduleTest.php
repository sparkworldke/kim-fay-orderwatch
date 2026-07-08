<?php

namespace Tests\Feature;

use App\Console\Commands\SendDailyManagementReportFixed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyReportFixedScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_report_is_scheduled_for_700_tuesday_through_saturday(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $events = collect($schedule->events());

        $event = $events->first(function ($event): bool {
            return isset($event->command) && is_string($event->command)
                && str_contains($event->command, 'orderwatch:send-daily-report-fixed');
        });

        $this->assertNotNull($event);
        $this->assertSame('0 7 * * 2-6', $event->expression);
    }
}

