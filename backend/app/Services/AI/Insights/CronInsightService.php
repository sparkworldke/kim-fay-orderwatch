<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

class CronInsightService
{
    public function getSnapshot(): array
    {
        // Latest run per cron job
        $latestRuns = DB::table('cron_run_logs as rl')
            ->join('cron_jobs as j', 'j.id', '=', 'rl.cron_job_id')
            ->whereIn('rl.id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('cron_run_logs')
                    ->groupBy('cron_job_id');
            })
            ->select([
                'j.name as job_name',
                'rl.status',
                'rl.started_at',
                'rl.ended_at',
                'rl.duration_ms',
                'rl.emails_processed',
                'rl.matches_created',
                'rl.unmatched_count',
                'rl.error_count',
                'rl.error_summary',
            ])
            ->get()
            ->toArray();

        // Last 24h run summary
        $last24h = DB::table('cron_run_logs')
            ->where('started_at', '>=', now()->subDay())
            ->selectRaw('
                COUNT(*) as total_runs,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as successful,
                SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = "running" THEN 1 ELSE 0 END) as running,
                COALESCE(SUM(emails_processed), 0) as emails_processed,
                COALESCE(SUM(matches_created), 0) as matches_created
            ')
            ->first();

        // Recent errors
        $recentErrors = DB::table('cron_run_logs as rl')
            ->join('cron_jobs as j', 'j.id', '=', 'rl.cron_job_id')
            ->where('rl.status', 'failed')
            ->where('rl.started_at', '>=', now()->subDays(7))
            ->select(['j.name as job_name', 'rl.error_summary', 'rl.started_at'])
            ->orderByDesc('rl.started_at')
            ->limit(5)
            ->get()
            ->toArray();

        return [
            'latest_runs_per_job' => array_map(fn($r) => (array) $r, $latestRuns),
            'last_24h' => [
                'total_runs'      => (int) ($last24h->total_runs ?? 0),
                'successful'      => (int) ($last24h->successful ?? 0),
                'failed'          => (int) ($last24h->failed ?? 0),
                'running'         => (int) ($last24h->running ?? 0),
                'emails_processed'=> (int) ($last24h->emails_processed ?? 0),
                'matches_created' => (int) ($last24h->matches_created ?? 0),
            ],
            'recent_errors' => array_map(fn($r) => (array) $r, $recentErrors),
        ];
    }
}
