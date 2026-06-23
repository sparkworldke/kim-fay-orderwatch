<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Services\Cron\HourlyAutoMatchCronService;
use Illuminate\Console\Command;

class RunHourlyAutoMatch extends Command
{
    protected $signature = 'orderwatch:hourly-auto-match {--source=scheduler}';
    protected $description = 'Run folder-aware email sync, Acumatica Sales Order sync, and guarded matching';

    public function handle(HourlyAutoMatchCronService $service): int
    {
        $run = $service->run(CronJob::hourlyAutoMatch(), (string) $this->option('source'));
        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
