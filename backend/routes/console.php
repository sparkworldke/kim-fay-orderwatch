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
