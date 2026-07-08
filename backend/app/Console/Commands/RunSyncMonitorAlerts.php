<?php

namespace App\Console\Commands;

use App\Mail\SyncMonitorAlertMail;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\Email;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RunSyncMonitorAlerts extends Command
{
    protected $signature = 'orderwatch:sync-monitor {--source=scheduler}';

    protected $description = 'Monitor sync outcomes and send immediate alerts on successful new syncs or guardrail failures';

    public function handle(CronExecutionService $cron): int
    {
        $job = CronJob::syncMonitor();
        $source = (string) $this->option('source');

        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $job),
            $source,
            null,
            null,
            300,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, CronJob $job): array
    {
        $recipient = 'commercialtechlead@kimfay.com';
        $settings = $job->settings ?? [];
        $lastSeenCronRunId = (int) ($settings['last_seen_cron_run_log_id'] ?? 0);
        $lastSeenEmailId = (int) ($settings['last_seen_email_id'] ?? 0);

        $syncJobKeys = [
            'email-sync-3h',
            'order-matching-3h',
            'sales-order-sync-3h',
            'sales-order-status-sync',
            'inventory-sync-5h',
            'backorders-daily-4pm',
            'fill-rate-nightly',
            'fill-rate-noon',
            'system-health-daily',
        ];

        $newRuns = CronRunLog::query()
            ->with('cronJob')
            ->where('id', '>', $lastSeenCronRunId)
            ->whereNotNull('ended_at')
            ->whereIn('status', ['success', 'partial', 'failed'])
            ->orderBy('id')
            ->get()
            ->filter(fn (CronRunLog $r) => $r->cronJob && in_array($r->cronJob->job_key, $syncJobKeys, true))
            ->values();

        $successEvents = $newRuns
            ->filter(fn (CronRunLog $r) => $r->status === 'success' && $this->processedSomething($r))
            ->values();

        $failureEvents = $newRuns
            ->filter(fn (CronRunLog $r) => in_array($r->status, ['partial', 'failed'], true))
            ->values();

        $guardrailEmails = Email::query()
            ->where('id', '>', $lastSeenEmailId)
            ->whereNotNull('import_guardrail_status')
            ->where('import_guardrail_status', '!=', 'matched')
            ->orderBy('id')
            ->get(['id', 'import_guardrail_status', 'import_guardrail_reason', 'from_email', 'received_at']);

        $newMaxCronRunLogId = $newRuns->max('id') ?? $lastSeenCronRunId;
        $newMaxEmailId = (int) (Email::max('id') ?? $lastSeenEmailId);

        if ($successEvents->isEmpty() && $failureEvents->isEmpty() && $guardrailEmails->isEmpty()) {
            $job->update(['settings' => array_merge($settings, [
                'last_seen_cron_run_log_id' => $newMaxCronRunLogId,
                'last_seen_email_id' => $newMaxEmailId,
                'last_checked_at' => now()->toISOString(),
            ])]);

            Log::info('sync_monitor_no_events', [
                'cron_run_log_id' => $run->id,
                'last_seen_cron_run_log_id' => $newMaxCronRunLogId,
                'last_seen_email_id' => $newMaxEmailId,
            ]);

            return ['status' => 'success', 'output' => 'No sync events detected.'];
        }

        $subject = $this->buildSubject($successEvents->count(), $failureEvents->count(), $guardrailEmails->count());
        $body = $this->buildBody($successEvents, $failureEvents, $guardrailEmails);

        try {
            Mail::to($recipient)->send(new SyncMonitorAlertMail($subject, $body));

            $job->update(['settings' => array_merge($settings, [
                'last_seen_cron_run_log_id' => $newMaxCronRunLogId,
                'last_seen_email_id' => $newMaxEmailId,
                'last_alerted_at' => now()->toISOString(),
                'last_checked_at' => now()->toISOString(),
            ])]);

            Log::info('sync_monitor_alert_sent', [
                'cron_run_log_id' => $run->id,
                'to' => $recipient,
                'success_events' => $successEvents->count(),
                'failure_events' => $failureEvents->count(),
                'guardrail_emails' => $guardrailEmails->count(),
            ]);
        } catch (\Throwable $e) {
            Log::error('sync_monitor_alert_failed', [
                'cron_run_log_id' => $run->id,
                'to' => $recipient,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        return [
            'status' => $failureEvents->isNotEmpty() ? 'partial' : 'success',
            'output' => 'Sync monitor alert dispatched.',
            'emails_checked' => $guardrailEmails->count(),
            'error_count' => $failureEvents->count(),
        ];
    }

    private function processedSomething(CronRunLog $run): bool
    {
        $simpleCounters = [
            (int) ($run->emails_processed ?? 0),
            (int) ($run->sales_orders_processed ?? 0),
            (int) ($run->matches_created ?? 0),
        ];

        if (max($simpleCounters) > 0) {
            return true;
        }

        $stepStatus = is_array($run->step_status) ? $run->step_status : [];
        foreach ($stepStatus as $step) {
            if (! is_array($step)) {
                continue;
            }
            $metrics = $step['metrics'] ?? null;
            if (! is_array($metrics)) {
                continue;
            }
            foreach ($metrics as $key => $value) {
                if (! is_string($key)) {
                    continue;
                }
                if (! str_ends_with($key, '_processed')) {
                    continue;
                }
                if ((int) $value > 0) {
                    return true;
                }
            }
        }

        return false;
    }

    private function buildSubject(int $successEvents, int $failureEvents, int $guardrailEmails): string
    {
        $parts = [];
        if ($successEvents > 0) $parts[] = "{$successEvents} sync success";
        if ($failureEvents > 0) $parts[] = "{$failureEvents} sync issue";
        if ($guardrailEmails > 0) $parts[] = "{$guardrailEmails} guardrail email";
        $label = $parts !== [] ? implode(' / ', $parts) : 'No events';

        return "OrderWatch Sync Monitor Alert: {$label}";
    }

    private function buildBody($successEvents, $failureEvents, $guardrailEmails): string
    {
        $lines = [];
        $lines[] = 'OrderWatch Sync Monitor';
        $lines[] = 'Generated at: '.now()->toISOString();
        $lines[] = '';

        if ($successEvents->isNotEmpty()) {
            $lines[] = 'Successful sync updates (new data):';
            foreach ($successEvents as $run) {
                $lines[] = $this->formatRunLine($run);
            }
            $lines[] = '';
        }

        if ($failureEvents->isNotEmpty()) {
            $lines[] = 'Sync issues (partial/failed):';
            foreach ($failureEvents as $run) {
                $lines[] = $this->formatRunLine($run, includeErrors: true);
            }
            $lines[] = '';
        }

        if ($guardrailEmails->isNotEmpty()) {
            $lines[] = 'Guardrail email events (non-matched):';
            $grouped = $guardrailEmails->groupBy('import_guardrail_status')->map->count();
            foreach ($grouped as $status => $count) {
                $lines[] = "- {$status}: {$count}";
            }
            $sample = $guardrailEmails->take(5);
            foreach ($sample as $email) {
                $lines[] = "- #{$email->id} {$email->import_guardrail_status} {$email->import_guardrail_reason} from={$email->from_email}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function formatRunLine(CronRunLog $run, bool $includeErrors = false): string
    {
        $jobKey = $run->cronJob?->job_key ?? 'unknown';
        $summary = "#{$run->id} {$jobKey} status={$run->status} duration_ms=".(int) ($run->duration_ms ?? 0);

        $counters = [];
        if ((int) ($run->emails_processed ?? 0) > 0) $counters[] = 'emails_processed='.(int) $run->emails_processed;
        if ((int) ($run->sales_orders_processed ?? 0) > 0) $counters[] = 'sales_orders_processed='.(int) $run->sales_orders_processed;
        if ((int) ($run->matches_created ?? 0) > 0) $counters[] = 'matches_created='.(int) $run->matches_created;
        if ($counters !== []) $summary .= ' '.implode(' ', $counters);

        if ($includeErrors && is_string($run->error_summary) && $run->error_summary !== '') {
            $summary .= ' error='.mb_substr($run->error_summary, 0, 250);
        }

        return '- '.$summary;
    }
}

