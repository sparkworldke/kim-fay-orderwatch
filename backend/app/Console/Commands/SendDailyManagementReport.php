<?php

namespace App\Console\Commands;

use App\Models\DailyReportConfig;
use App\Services\Reports\DailyReportRunnerService;
use Illuminate\Console\Command;

class SendDailyManagementReport extends Command
{
    protected $signature = 'orderwatch:send-daily-report {--force : Send regardless of schedule} {--source=scheduler}';
    protected $description = 'Generate and send the daily management order briefing email';

    public function handle(DailyReportRunnerService $runner): int
    {
        $config = DailyReportConfig::singleton();
        $force = (bool) $this->option('force');
        $source = (string) $this->option('source');

        if (! $force && $source === 'scheduler' && ! $runner->shouldRunScheduled($config)) {
            return self::SUCCESS;
        }

        $run = $runner->run($config, $source, $force);

        if ($run->status === 'skipped') {
            $this->line('Skipped: '.$run->error_summary);
            return self::SUCCESS;
        }

        $this->info("Daily report run {$run->id}: {$run->status} (delivery: {$run->delivery_status})");

        return in_array($run->status, ['completed', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }
}