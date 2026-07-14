<?php

namespace App\Console\Commands;

use App\Models\DailyReportConfig;
use App\Services\Reports\DailyReportRunnerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyManagementReportFixed extends Command
{
    protected $signature = 'orderwatch:send-daily-report-fixed
                            {--source=scheduler : Run source label for logs}
                            {--force : Regenerate and send even if already sent for the report date}';

    protected $description = 'Generate and send the daily executive exceptions report for the previous calendar day';

    public function handle(DailyReportRunnerService $runner): int
    {
        $config = DailyReportConfig::singleton();
        $source = (string) $this->option('source');
        $force = (bool) $this->option('force');
        $timezone = $config->timezone ?: 'Africa/Nairobi';

        $asOf = now()->timezone($timezone);
        $reportDate = $asOf->copy()->subDay()->toDateString();

        if (! $config->is_enabled) {
            Log::info('daily_report_fixed_skipped', [
                'reason' => 'disabled',
                'report_date' => $reportDate,
                'timezone' => $timezone,
                'source' => $source,
            ]);
            $this->line('Skipped: Daily report is disabled.');

            return self::SUCCESS;
        }

        // Pre-check for clear CLI messaging. Runner re-checks under a cache lock
        // so concurrent schedule:run processes cannot both send.
        if (! $force && $runner->hasSuccessfulSend($config->id, $reportDate)) {
            Log::info('daily_report_fixed_skipped', [
                'reason' => 'already_sent',
                'report_date' => $reportDate,
                'timezone' => $timezone,
                'source' => $source,
            ]);
            $this->line("Skipped: Report already sent for {$reportDate}. Use --force to regenerate and resend.");

            return self::SUCCESS;
        }

        if ($force) {
            $this->warn("Resending report for {$reportDate} with fresh data (--force).");
        }

        // ignoreSendTimeWindow=true: fixed cron owns the schedule (Tue–Sat 07:00),
        // so we do not require config.send_time. force only controls resend.
        $run = $runner->run(
            $config,
            $source,
            force: $force,
            asOf: $asOf,
            ignoreSendTimeWindow: true,
        );

        Log::info('daily_report_fixed_completed', [
            'run_id' => $run->id,
            'status' => $run->status,
            'delivery_status' => $run->delivery_status,
            'report_date' => $reportDate,
            'recipient_count' => $run->recipient_count,
            'source' => $source,
            'force' => $force,
            'error_summary' => $run->error_summary,
        ]);

        if ($run->status === 'skipped') {
            $this->line('Skipped: '.($run->error_summary ?? 'not run'));

            return self::SUCCESS;
        }

        $this->info("Daily report run {$run->id}: {$run->status} (delivery: {$run->delivery_status})");

        return in_array($run->status, ['completed', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }
}
