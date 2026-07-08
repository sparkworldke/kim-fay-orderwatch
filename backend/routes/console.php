<?php

use App\Console\Commands\EvaluateOrderMatchNotifications;
use App\Console\Commands\PruneExpiredOtps;
use App\Models\CronJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneExpiredOtps::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping(10, releaseOnTerminationSignals: false);

Schedule::command(EvaluateOrderMatchNotifications::class)
    ->hourly()
    ->withoutOverlapping(15, releaseOnTerminationSignals: false);

Schedule::command('orderwatch:send-daily-report-fixed --source=scheduler')
    ->cron('0 7 * * 2-6')
    ->timezone((string) config('cron.timezone', config('app.timezone')))
    ->withoutOverlapping(20, releaseOnTerminationSignals: false);

Schedule::command('orderwatch:sync-monitor --source=scheduler')
    ->everyMinute()
    ->timezone((string) config('cron.timezone', config('app.timezone')))
    ->withoutOverlapping(5, releaseOnTerminationSignals: false);

try {
    CronJob::ensureDefaults();
    $timezone = (string) config('cron.timezone', config('app.timezone'));

    foreach (CronJob::where('trigger_type', 'scheduler')->get() as $job) {
        if (! $job->is_enabled || $job->status === 'paused') {
            continue;
        }

        $command = trim((string) $job->command);
        if (str_starts_with($command, 'php artisan ')) {
            $command = trim(substr($command, strlen('php artisan ')));
        }

        if ($command === '' || $job->cron_expression === null || trim((string) $job->cron_expression) === '') {
            continue;
        }

        // Registered explicitly above — avoid duplicate scheduler events.
        if ($job->job_key === 'daily-report-fixed-scheduler') {
            continue;
        }

        $overlapMinutes = match ($job->job_key) {
            'email-sales-order-auto-match' => 55,
            'sales-order-status-sync' => 25,
            'inventory-sync-5h' => 220,
            'backorders-daily-4pm' => 360,
            'fill-rate-nightly', 'fill-rate-noon' => 360,
            'system-health-daily' => 30,
            default => 115,
        };

        Schedule::command($command.' --source=scheduler')
            ->cron((string) $job->cron_expression)
            ->timezone($timezone)
            ->withoutOverlapping($overlapMinutes, releaseOnTerminationSignals: false);
    }
} catch (\Throwable $e) {
    Log::error('scheduler_bootstrap_failed', ['error' => $e->getMessage()]);
}
