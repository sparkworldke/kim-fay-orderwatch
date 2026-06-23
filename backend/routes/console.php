<?php

use App\Console\Commands\PruneExpiredOtps;
use App\Console\Commands\SyncAcumaticaCustomerCategories;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(PruneExpiredOtps::class)->everyFifteenMinutes();

// Sync Acumatica customer categories every hour
Schedule::command(SyncAcumaticaCustomerCategories::class)->hourly();

// Unified Outlook folder sync → Acumatica Sales Order sync → guarded matching.
Schedule::command('orderwatch:hourly-auto-match')
    ->hourly()
    ->withoutOverlapping(55);

// Daily management report — checks every minute against configured send_time.
Schedule::command('orderwatch:send-daily-report')
    ->everyMinute()
    ->withoutOverlapping(10);

// Process queued mailbox sync jobs every minute.
// --stop-when-empty drains all pending jobs then exits cleanly.
// withoutOverlapping(10) prevents a second worker starting if the previous run
// is still processing a large mailbox (overlap guard expires after 10 minutes).
Schedule::command('queue:work database --stop-when-empty --timeout=300 --tries=1 --queue=default')
    ->everyMinute()
    ->withoutOverlapping(10);
