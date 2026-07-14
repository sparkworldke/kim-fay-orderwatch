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

        // Registered explicitly above — avoid duplicate scheduler events.
        if ($job->job_key === 'daily-report-fixed-scheduler') {
            continue;
        }

        $expressions = [];
        $settingsExpressions = $job->settings['cron_expressions'] ?? null;
        if (is_array($settingsExpressions)) {
            foreach ($settingsExpressions as $expr) {
                $expr = trim((string) $expr);
                if ($expr !== '') {
                    $expressions[] = $expr;
                }
            }
        }
        if ($expressions === [] && $job->cron_expression !== null && trim((string) $job->cron_expression) !== '') {
            $expressions[] = trim((string) $job->cron_expression);
        }

        if ($command === '' || $expressions === []) {
            continue;
        }

        $overlapMinutes = match (true) {
            str_starts_with((string) $job->job_key, 'inventory-sync-') => 25,
            $job->job_key === 'email-sales-order-auto-match' => 55,
            $job->job_key === 'sales-order-status-sync' => 25,
            $job->job_key === 'sales-order-prune-missing' => 50,
            $job->job_key === 'inventory-sync-5h' => 220,
            $job->job_key === 'backorders-daily-4pm' => 360,
            in_array($job->job_key, ['fill-rate-nightly', 'fill-rate-noon'], true) => 360,
            $job->job_key === 'system-health-daily' => 30,
            default => 115,
        };

        foreach ($expressions as $expr) {
            Schedule::command($command.' --source=scheduler')
                ->cron($expr)
                ->timezone($timezone)
                ->withoutOverlapping($overlapMinutes, releaseOnTerminationSignals: false);
        }
    }
} catch (\Throwable $e) {
    Log::error('scheduler_bootstrap_failed', ['error' => $e->getMessage()]);
}
