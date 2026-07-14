<?php

namespace App\Console\Commands;

use App\Services\Operations\SoReasonAuditService;
use Illuminate\Console\Command;

class AuditSoReasonCapture extends Command
{
    protected $signature = 'audit:so-reason-capture {--json : Output full JSON report}';

    protected $description = 'Audit cancelled/rejected/on-hold SO and backorder reason capture against the 33-reason taxonomy';

    public function handle(SoReasonAuditService $audit): int
    {
        $report = $audit->report();

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('=== WORKFLOW ORDERS (Cancelled / Rejected / On Hold) ===');
        foreach ($report['workflow_orders'] as $key => $row) {
            $this->line(sprintf(
                '%s: total=%d hierarchical=%d missing=%d capture=%s%%',
                $key,
                $row['total_orders'],
                $row['with_hierarchical_reason'],
                $row['missing_reason'],
                $row['capture_rate_pct'] ?? 'N/A',
            ));
        }

        $this->newLine();
        $this->info('=== REQUIRED 33-REASON COVERAGE ===');
        $coverage = $report['required_reason_coverage'];
        $this->line(sprintf(
            'Observed approved: %d / %d | Unclassified in data: %d',
            count($coverage['observed_approved']),
            $coverage['required_count'],
            count($coverage['unclassified_observed']),
        ));

        $this->newLine();
        $this->info('=== REASONS NOT YET OBSERVED IN DATA ===');
        foreach ($coverage['missing_required'] as $code) {
            $label = collect($report['required_reasons'])->firstWhere('sub_reason_code', $code)['label'] ?? $code;
            $this->line("  - {$label} ({$code})");
        }

        $this->newLine();
        $this->info('=== UNCLASSIFIED ACUMATICA CODES ===');
        foreach ($coverage['unclassified_observed'] as $raw) {
            $this->line("  - {$raw}");
        }

        $gaps = $report['gaps_summary'];
        $this->newLine();
        $this->warn(sprintf(
            'Gaps: %d workflow orders missing reason, %d required reasons not observed, %d unclassified codes',
            $gaps['workflow_orders_missing_hierarchical_reason'],
            $gaps['required_reasons_not_observed'],
            $gaps['unclassified_codes_in_data'],
        ));

        return self::SUCCESS;
    }
}