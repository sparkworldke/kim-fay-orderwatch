<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Cron\CronExecutionService;
use App\Services\Fol\FolAcumaticaSalesOrderService;
use Illuminate\Console\Command;

/**
 * Checks final-approved FOLs that never got an Acumatica SO and retries creation.
 * Also surfaces open failure logs in the cron run output.
 */
class RunFolSalesOrderRetry extends Command
{
    protected $signature = 'orderwatch:fol-so-retry
                            {--source=scheduler : scheduler|manual|api}
                            {--user-id= : Optional actor user id}
                            {--limit=50 : Max FOL requests to retry this run}
                            {--check-only : Only report missing SOs; do not retry create}';

    protected $description = 'Check FOLs missing Acumatica SOs after CCO approval and retry creation';

    public function handle(CronExecutionService $cron, FolAcumaticaSalesOrderService $folSalesOrders): int
    {
        $job = CronJob::folSalesOrderRetry();
        $userId = $this->option('user-id') !== null && $this->option('user-id') !== ''
            ? (int) $this->option('user-id')
            : null;
        $limit = max(1, min((int) $this->option('limit'), 200));
        $checkOnly = (bool) $this->option('check-only');
        $source = (string) $this->option('source');

        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $folSalesOrders, $limit, $checkOnly),
            $source,
            $userId,
            null,
            1800,
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        if ($run->output) {
            $this->line($run->output);
        }

        return in_array($run->status, ['failed'], true) ? self::FAILURE : self::SUCCESS;
    }

    private function perform(
        CronRunLog $run,
        FolAcumaticaSalesOrderService $folSalesOrders,
        int $limit,
        bool $checkOnly,
    ): array {
        $missing = $folSalesOrders->missingSalesOrderRequests($limit);
        $missingCount = $missing->count();

        if ($checkOnly || ! $folSalesOrders->isEnabled()) {
            $lines = $missing->map(fn ($r) => "{$r->public_ref} id={$r->id} customer={$r->customer_acumatica_id}")->all();
            $output = $checkOnly
                ? "Check-only: {$missingCount} FOL(s) missing SO."
                : "SO auto-create disabled; {$missingCount} FOL(s) still missing SO.";
            if ($lines !== []) {
                $output .= "\n".implode("\n", array_slice($lines, 0, 30));
            }

            return [
                'status' => $missingCount > 0 ? 'partial' : 'success',
                'output' => $output,
                'metadata' => [
                    'missing_count' => $missingCount,
                    'check_only' => $checkOnly,
                    'enabled' => $folSalesOrders->isEnabled(),
                    'missing' => $missing->map(fn ($r) => [
                        'id' => $r->id,
                        'public_ref' => $r->public_ref,
                        'customer_acumatica_id' => $r->customer_acumatica_id,
                        'decided_at' => $r->decided_at?->toIso8601String(),
                    ])->values()->all(),
                ],
                'unmatched_count' => $missingCount,
                'error_count' => 0,
            ];
        }

        $summary = $folSalesOrders->retryMissing(
            limit: $limit,
            attemptSource: 'cron_retry',
            cronRunLogId: $run->id,
        );

        $status = match (true) {
            $summary['failed'] > 0 && $summary['created'] === 0 && $summary['checked'] > 0 => 'failed',
            $summary['failed'] > 0 => 'partial',
            default => 'success',
        };

        $output = sprintf(
            'FOL SO retry: checked=%d created=%d failed=%d skipped=%d already_linked=%d',
            $summary['checked'],
            $summary['created'],
            $summary['failed'],
            $summary['skipped'],
            $summary['already_linked'],
        );

        if ($summary['failures'] !== []) {
            $output .= "\nFailures:\n".collect($summary['failures'])
                ->map(fn (array $f) => "  {$f['public_ref']} (#{$f['fol_id']}): ".($f['error'] ?? 'unknown'))
                ->take(20)
                ->implode("\n");
        }

        return [
            'status' => $status,
            'output' => $output,
            'metadata' => $summary,
            'matches_created' => $summary['created'],
            'unmatched_count' => $summary['failed'],
            'skipped_count' => $summary['skipped'] + $summary['already_linked'],
            'error_count' => $summary['failed'],
            'error_summary' => $summary['failed'] > 0
                ? "{$summary['failed']} FOL SO create failure(s) this run"
                : null,
        ];
    }
}
