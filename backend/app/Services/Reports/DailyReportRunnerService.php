<?php

namespace App\Services\Reports;

use App\Models\DailyReportConfig;
use App\Models\DailyReportRun;
use Illuminate\Support\Carbon;
use Throwable;

class DailyReportRunnerService
{
    public function __construct(
        private readonly DailyManagementReportService $report,
        private readonly DailyManagementInsightService $insights,
        private readonly DailyReportMailerService $mailer,
    ) {}

    public function shouldRunScheduled(DailyReportConfig $config, ?Carbon $now = null): bool
    {
        if (! $config->is_enabled) {
            return false;
        }

        $now = ($now ?? now())->timezone($config->timezone);
        $sendTime = substr((string) $config->send_time, 0, 5);

        if ($now->format('H:i') !== $sendTime) {
            return false;
        }

        $reportDate = $now->copy()->subDay()->toDateString();

        return ! DailyReportRun::query()
            ->where('report_config_id', $config->id)
            ->whereDate('report_date', $reportDate)
            ->whereIn('status', ['completed', 'partial'])
            ->exists();
    }

    public function run(
        DailyReportConfig $config,
        string $trigger = 'scheduler',
        bool $force = false,
        ?Carbon $asOf = null,
        ?array $overrideRecipients = null,
    ): DailyReportRun {
        $started = hrtime(true);
        $timezone = $config->timezone ?: 'Africa/Nairobi';
        $asOf = ($asOf ?? now())->timezone($timezone);
        $reportDate = $asOf->copy()->subDay()->startOfDay();

        if (! $force && $trigger === 'scheduler' && ! $this->shouldRunScheduled($config, $asOf)) {
            return DailyReportRun::create([
                'report_config_id' => $config->id,
                'report_date' => $reportDate,
                'started_at' => now(),
                'completed_at' => now(),
                'status' => 'skipped',
                'error_summary' => 'Not scheduled to run at this time or already sent.',
                'duration_ms' => 0,
            ]);
        }

        $run = DailyReportRun::create([
            'report_config_id' => $config->id,
            'report_date' => $reportDate,
            'started_at' => now(),
            'status' => 'running',
        ]);

        try {
            $payload = $this->report->buildPayload($asOf, $timezone);

            if (! $config->include_mtd) {
                unset($payload['mtd'], $payload['mtd_comparison'], $payload['prior_mtd']);
            }
            if (! $config->include_comparison) {
                unset($payload['comparison'], $payload['mtd_comparison']);
            }
            if (! $config->include_customer_highlights) {
                unset($payload['customer_highlights']);
            }

            $aiInsights = $this->insights->generate($payload, $config->include_ai_insights);
            $payload['insights'] = $aiInsights;

            $sendConfig = clone $config;
            if ($overrideRecipients !== null) {
                $sendConfig->recipients_json = $overrideRecipients;
            }

            $delivery = $this->mailer->send($run, $sendConfig, $payload, $aiInsights);
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);

            $status = match ($delivery['delivery_status']) {
                'sent' => 'completed',
                'partial' => 'partial',
                'skipped' => 'completed',
                default => 'failed',
            };

            $run->update([
                'completed_at' => now(),
                'sent_at' => in_array($delivery['delivery_status'], ['sent', 'partial'], true) ? now() : null,
                'status' => $status,
                'ai_status' => $aiInsights['ai_status'] ?? 'unknown',
                'delivery_status' => $delivery['delivery_status'],
                'recipient_count' => $delivery['sent_count'],
                'duration_ms' => $duration,
                'payload_json' => $payload,
                'error_summary' => $delivery['errors'] !== [] ? implode("\n", $delivery['errors']) : null,
            ]);

            return $run->fresh('deliveryLogs');
        } catch (Throwable $e) {
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);
            $run->update([
                'completed_at' => now(),
                'status' => 'failed',
                'ai_status' => 'failed',
                'delivery_status' => 'failed',
                'duration_ms' => $duration,
                'error_summary' => $e->getMessage(),
            ]);

            return $run->fresh('deliveryLogs');
        }
    }

    public function resendLast(DailyReportConfig $config, ?array $overrideRecipients = null): ?DailyReportRun
    {
        $last = DailyReportRun::query()
            ->where('report_config_id', $config->id)
            ->whereNotNull('payload_json')
            ->whereIn('status', ['completed', 'partial'])
            ->latest('report_date')
            ->first();

        if (! $last || ! is_array($last->payload_json)) {
            return null;
        }

        $started = hrtime(true);
        $run = DailyReportRun::create([
            'report_config_id' => $config->id,
            'report_date' => $last->report_date,
            'started_at' => now(),
            'status' => 'running',
            'payload_json' => $last->payload_json,
        ]);

        try {
            $payload = $last->payload_json;
            $insights = $payload['insights'] ?? $this->insights->generate($payload, $config->include_ai_insights);

            $sendConfig = clone $config;
            if ($overrideRecipients !== null) {
                $sendConfig->recipients_json = $overrideRecipients;
            }

            $delivery = $this->mailer->send($run, $sendConfig, $payload, $insights);
            $duration = (int) ((hrtime(true) - $started) / 1_000_000);

            $run->update([
                'completed_at' => now(),
                'sent_at' => in_array($delivery['delivery_status'], ['sent', 'partial'], true) ? now() : null,
                'status' => $delivery['delivery_status'] === 'sent' ? 'completed' : ($delivery['delivery_status'] === 'partial' ? 'partial' : 'failed'),
                'ai_status' => $insights['ai_status'] ?? 'reused',
                'delivery_status' => $delivery['delivery_status'],
                'recipient_count' => $delivery['sent_count'],
                'duration_ms' => $duration,
                'error_summary' => $delivery['errors'] !== [] ? implode("\n", $delivery['errors']) : null,
            ]);

            return $run->fresh('deliveryLogs');
        } catch (Throwable $e) {
            $run->update([
                'completed_at' => now(),
                'status' => 'failed',
                'delivery_status' => 'failed',
                'error_summary' => $e->getMessage(),
            ]);

            return $run->fresh('deliveryLogs');
        }
    }
}