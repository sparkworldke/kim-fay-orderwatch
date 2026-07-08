<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Models\EmailMatchAttempt;
use App\Services\Cron\CronExecutionService;
use App\Services\Email\OrderMatchingService;
use Illuminate\Console\Command;

class RunOrderMatching extends Command
{
    protected $signature = 'orderwatch:order-matching {--source=scheduler} {--user-id=}';

    protected $description = 'Run PO extraction and deterministic order matching (no queue worker)';

    public function handle(CronExecutionService $cron, OrderMatchingService $matching): int
    {
        $job = CronJob::orderMatching();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $matching, $userId),
            (string) $this->option('source'),
            $userId,
            3 * 60 * 60,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, OrderMatchingService $matching, ?int $userId): array
    {
        $started = hrtime(true);

        $extraction = $matching->runPoExtraction();
        $matchRun = $matching->runOrderMatching($userId, $run->id);

        $attempts = EmailMatchAttempt::where('cron_run_log_id', $run->id)
            ->selectRaw('classification, COUNT(*) as total')
            ->groupBy('classification')
            ->pluck('total', 'classification');

        $failed = $matchRun->status === 'failed';

        return [
            'status' => $failed ? 'failed' : 'success',
            'output' => $failed ? 'Order matching failed.' : 'Order matching completed.',
            'matches_created' => (int) ($attempts['matched'] ?? 0),
            'matched_with_discrepancies_count' => (int) ($attempts['matched_discrepancies'] ?? 0),
            'needs_review_count' => (int) ($attempts['needs_review'] ?? 0),
            'unmatched_count' => (int) ($attempts['not_matched'] ?? 0),
            'error_count' => $failed ? 1 : 0,
            'error_summary' => $failed ? ($matchRun->error_message ?? 'Matching failed.') : null,
            'step_status' => [
                'matching' => [
                    'status' => $failed ? 'failed' : 'success',
                    'duration_ms' => $this->milliseconds($started),
                    'metrics' => [
                        'emails_extraction_processed' => (int) ($extraction['processed'] ?? 0),
                        'po_extracted' => (int) ($extraction['extracted'] ?? 0),
                        'matches_created' => (int) ($attempts['matched'] ?? 0),
                        'matched_with_discrepancies' => (int) ($attempts['matched_discrepancies'] ?? 0),
                        'needs_review' => (int) ($attempts['needs_review'] ?? 0),
                        'unmatched' => (int) ($attempts['not_matched'] ?? 0),
                    ],
                    'errors' => $failed && $matchRun->error_message ? [$matchRun->error_message] : [],
                ],
            ],
            'metadata' => [
                'order_match_run_id' => $matchRun->id,
            ],
        ];
    }

    private function milliseconds(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }
}
