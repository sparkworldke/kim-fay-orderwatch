<?php

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
    ->everyThirtyMinutes()
    ->withoutOverlapping(10, releaseOnTerminationSignals: false);

Schedule::command('orderwatch:sync-dtc-prices --source=scheduler')
    ->dailyAt('05:30')
    ->timezone((string) config('cron.timezone', config('app.timezone')))
    ->withoutOverlapping(30, releaseOnTerminationSignals: false)
    ->name('dtc-price-sync');

Schedule::command('orderwatch:send-consultant-inactivity-digests')
    ->hourlyAt(15)
    ->timezone((string) config('cron.timezone', config('app.timezone')))
    ->withoutOverlapping(20, releaseOnTerminationSignals: false)
    ->name('sales-consultant-inactivity-digests');

Schedule::command('production:summaries-refresh --recent')
    ->everyThirtyMinutes()
    ->withoutOverlapping(20, releaseOnTerminationSignals: false)
    ->name('production-summary-refresh');

// Order-match notification emails (R5/R6) are paused — only System Health + Daily Report
// remain active. Re-enable via cron_jobs row + notification_rules is_enabled when needed.
// Schedule::command(EvaluateOrderMatchNotifications::class)
//     ->hourly()
//     ->withoutOverlapping(15, releaseOnTerminationSignals: false);

// Single owner of the daily management email schedule.
// Do not also register this command from cron_jobs (see skip below) or system crontab
// direct artisan calls — that was causing multiple emails per morning.
Schedule::command('orderwatch:send-daily-report-fixed --source=scheduler')
    ->cron('0 7 * * 2-6')
    ->timezone((string) config('cron.timezone', config('app.timezone')))
    ->withoutOverlapping(30, releaseOnTerminationSignals: false)
    ->name('orderwatch-daily-report-fixed');

// Sync-monitor alerts paused (high volume). Keep system-health-daily + daily report only.
// Schedule::command('orderwatch:sync-monitor --source=scheduler')
//     ->everyMinute()
//     ->timezone((string) config('cron.timezone', config('app.timezone')))
//     ->withoutOverlapping(5, releaseOnTerminationSignals: false);

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
        // Also block any legacy / renamed DB rows that still point at daily-report commands
        // (those were causing multiple emails when both the hard-coded schedule and a
        // cron_jobs row fired the same artisan command).
        // otp:prune is hard-scheduled via PruneExpiredOtps::class; skip the cron_jobs row
        // so it is not registered twice (and so --source is not forced on it twice).
        if (
            $job->job_key === 'daily-report-fixed-scheduler'
            || $job->job_key === 'otp-prune'
            || str_contains($command, 'send-daily-report')
            || str_contains($command, 'otp:prune')
        ) {
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

        // Overlap prevention is owned by CronExecutionService, which every DB-driven
        // command already calls: it takes an atomic cache lock per job_key and now also
        // recovers stale locks automatically. Layering Laravel's withoutOverlapping() on
        // top here created a SECOND lock with a different TTL that was never released
        // when a process died hard (releaseOnTerminationSignals:false) — so heavy jobs
        // (full Sales Order import, Backorder) skipped every run as "previous run still
        // active" after a deploy/restart. A single lock authority fixes that.
        foreach ($expressions as $expr) {
            Schedule::command($command.' --source=scheduler')
                ->cron($expr)
                ->name($job->job_key)
                ->timezone($timezone);
        }
    }
} catch (\Throwable $e) {
    Log::error('scheduler_bootstrap_failed', ['error' => $e->getMessage()]);
}
