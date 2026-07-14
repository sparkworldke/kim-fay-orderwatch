<?php

namespace App\Console\Commands;

use App\Mail\SyncMonitorAlertMail;
use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\MailboxAccount;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RunSystemHealthCheck extends Command
{
    protected $signature = 'orderwatch:system-health {--source=scheduler} {--user-id=}';

    protected $description = 'Send a daily system health report to the tech lead';

    private const CRITICAL_JOB_KEYS = [
        'email-sync-3h',
        'sales-order-sync-3h',
        // Per-warehouse stock syncs (inventory-sync-dtc, inventory-sync-fgs, …)
        // are matched by prefix in perform(); keep legacy key for compatibility.
        'inventory-sync-5h',
        'backorders-daily-4pm',
        'fill-rate-nightly',
        'fill-rate-noon',
    ];

    public function handle(CronExecutionService $cron): int
    {
        $job = CronJob::systemHealthCheck();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;

        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $job),
            (string) $this->option('source'),
            $userId,
            null,
            600,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, CronJob $job): array
    {
        $settings = $job->settings ?? [];
        $recipient = $settings['recipient'] ?? 'commercialtechlead@kimfay.com';

        [$body, $overallStatus] = $this->buildReport();
        $subject = "OrderWatch System Health [{$overallStatus}] — " . now()->format('D d M Y');

        try {
            Mail::to($recipient)->send(new SyncMonitorAlertMail($subject, $body));

            Log::info('system_health_report_sent', [
                'cron_run_log_id' => $run->id,
                'to' => $recipient,
                'overall_status' => $overallStatus,
            ]);
        } catch (\Throwable $e) {
            Log::error('system_health_report_failed', [
                'cron_run_log_id' => $run->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return [
            'status' => 'success',
            'output' => "System health report sent to {$recipient}. Overall: {$overallStatus}.",
        ];
    }

    private function buildReport(): array
    {
        $lines = [];
        $lines[] = 'OrderWatch System Health Report';
        $lines[] = 'Generated: ' . now()->toDateTimeString() . ' (' . config('cron.timezone', config('app.timezone')) . ')';
        $lines[] = str_repeat('=', 60);
        $lines[] = '';

        // Database connectivity
        $dbOk = $this->checkDatabase();
        $lines[] = '=== Database ===';
        $lines[] = $dbOk ? 'Status: CONNECTED' : 'Status: ERROR — cannot reach database';
        $lines[] = '';

        // Scheduled jobs overview
        $jobs = CronJob::where('trigger_type', 'scheduler')
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();

        $lines[] = '=== Scheduled Jobs ===';
        $jobIssues = [];

        foreach ($jobs as $j) {
            $lastRun = $j->last_run_at ? $j->last_run_at->diffForHumans() : 'never';
            $status = $j->last_run_status ?? 'never run';
            $flag = $this->jobHealthFlag($j);
            if ($flag !== 'OK') {
                $jobIssues[] = $j->name;
            }
            $lines[] = sprintf(
                '  [%s] %-35s last=%-18s status=%s',
                $flag,
                mb_substr((string) $j->name, 0, 35),
                $lastRun,
                $status,
            );
        }
        $lines[] = '';

        // Recent failures (last 24 hours)
        $recentFailures = CronRunLog::query()
            ->with('cronJob')
            ->where('started_at', '>=', now()->subDay())
            ->whereIn('status', ['failed', 'partial'])
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        $lines[] = '=== Failures in Last 24 Hours ===';
        if ($recentFailures->isEmpty()) {
            $lines[] = '  None';
        } else {
            foreach ($recentFailures as $r) {
                $key = $r->cronJob?->job_key ?? 'unknown';
                $lines[] = sprintf(
                    '  #%d %-30s status=%-8s started=%s',
                    $r->id,
                    $key,
                    $r->status,
                    $r->started_at?->format('H:i:s') ?? '-',
                );
                if ($r->error_summary) {
                    $lines[] = '       error: ' . mb_substr((string) $r->error_summary, 0, 120);
                }
            }
        }
        $lines[] = '';

        // Mailbox accounts
        $mailboxes = MailboxAccount::orderBy('email')->get(['id', 'email', 'status', 'updated_at']);
        $lines[] = '=== Mailbox Accounts ===';
        if ($mailboxes->isEmpty()) {
            $lines[] = '  No mailbox accounts configured';
        } else {
            foreach ($mailboxes as $mb) {
                $flag = $mb->status === 'connected' ? 'OK  ' : 'WARN';
                $lines[] = sprintf('  [%s] %-40s status=%s', $flag, (string) $mb->email, (string) $mb->status);
            }
        }
        $lines[] = '';

        // Last successful run for critical jobs
        $lines[] = '=== Critical Jobs — Last Success ===';
        foreach (self::CRITICAL_JOB_KEYS as $key) {
            $j = $jobs->firstWhere('job_key', $key);
            if ($j === null) {
                $lines[] = sprintf('  %-35s not found', $key);
                continue;
            }
            $lastSuccess = $j->last_success_at ? $j->last_success_at->diffForHumans() : 'never';
            $flag = $j->last_success_at && $j->last_success_at->isAfter(now()->subHours(26)) ? 'OK  ' : 'WARN';
            $lines[] = sprintf('  [%s] %-35s last_success=%s', $flag, $key, $lastSuccess);
        }
        $lines[] = '';

        // Overall assessment
        $hasDbError = ! $dbOk;
        $hasCriticalFailure = $recentFailures->where('status', 'failed')->isNotEmpty();
        $hasMailboxError = $mailboxes->where('status', '!=', 'connected')->isNotEmpty();
        $hasJobIssues = $jobIssues !== [];

        $overallStatus = match (true) {
            $hasDbError || $hasCriticalFailure => 'CRITICAL',
            $hasMailboxError || $hasJobIssues => 'DEGRADED',
            default => 'HEALTHY',
        };

        $lines[] = '=== Overall Status: ' . $overallStatus . ' ===';
        if ($hasDbError) $lines[] = '  - Database unreachable';
        if ($hasCriticalFailure) $lines[] = '  - One or more critical job failures in last 24h';
        if ($hasMailboxError) $lines[] = '  - One or more mailbox accounts not connected';
        if ($hasJobIssues) $lines[] = '  - Overdue or failing jobs: ' . implode(', ', $jobIssues);
        if ($overallStatus === 'HEALTHY') $lines[] = '  All systems nominal.';

        return [implode("\n", $lines), $overallStatus];
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->getPdo();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function jobHealthFlag(CronJob $job): string
    {
        if ($job->last_run_status === 'failed') {
            return 'FAIL';
        }
        if ($job->last_run_at === null) {
            return 'WAIT';
        }
        // Warn if last run was more than 26 hours ago (allows for daily jobs missing a tick)
        if ($job->last_run_at->isBefore(now()->subHours(26))) {
            return 'WARN';
        }
        return 'OK  ';
    }
}
