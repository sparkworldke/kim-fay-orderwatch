<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

class MatchInsightService
{
    public function getSnapshot(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $from = $dateFrom ?? now()->startOfDay()->toDateTimeString();
        $to   = $dateTo   ?? now()->endOfDay()->toDateTimeString();

        // Email match classification breakdown (today)
        $emailMatchBreakdown = DB::table('emails')
            ->whereBetween('received_at', [$from, $to])
            ->whereNotNull('match_classification')
            ->selectRaw('match_classification, COUNT(*) as count')
            ->groupBy('match_classification')
            ->pluck('count', 'match_classification')
            ->toArray();

        // Sales order match_status breakdown (today)
        $soMatchBreakdown = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('match_status, COUNT(*) as count')
            ->groupBy('match_status')
            ->pluck('count', 'match_status')
            ->toArray();

        // Latest order match run
        $latestRun = DB::table('order_match_runs')
            ->orderByDesc('created_at')
            ->select([
                'status', 'emails_processed', 'po_extracted', 'matched',
                'unmatched', 'duplicate', 'missing_in_acumatica', 'created_at',
            ])
            ->first();

        // All-time email match counts (operational view)
        $allTimeEmailMatch = DB::table('emails')
            ->selectRaw('
                SUM(CASE WHEN match_classification = "matched" THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN match_classification = "matched_with_discrepancies" THEN 1 ELSE 0 END) as matched_with_discrepancies,
                SUM(CASE WHEN match_classification = "needs_review" THEN 1 ELSE 0 END) as needs_review,
                SUM(CASE WHEN match_classification = "unmatched" OR match_classification IS NULL THEN 1 ELSE 0 END) as unmatched
            ')
            ->first();

        // All-time SO match
        $allTimeSoMatch = DB::table('acumatica_sales_orders')
            ->selectRaw('
                SUM(CASE WHEN match_status = "matched" THEN 1 ELSE 0 END) as matched,
                SUM(CASE WHEN match_status = "unmatched" OR match_status IS NULL THEN 1 ELSE 0 END) as unmatched
            ')
            ->first();

        return [
            'period'               => ['from' => $from, 'to' => $to],
            'email_match_today'    => $emailMatchBreakdown,
            'so_match_today'       => $soMatchBreakdown,
            'latest_run'           => $latestRun ? (array) $latestRun : null,
            'all_time_email_match' => [
                'matched'                   => (int) ($allTimeEmailMatch->matched ?? 0),
                'matched_with_discrepancies'=> (int) ($allTimeEmailMatch->matched_with_discrepancies ?? 0),
                'needs_review'              => (int) ($allTimeEmailMatch->needs_review ?? 0),
                'unmatched'                 => (int) ($allTimeEmailMatch->unmatched ?? 0),
            ],
            'all_time_so_match' => [
                'matched'  => (int) ($allTimeSoMatch->matched ?? 0),
                'unmatched'=> (int) ($allTimeSoMatch->unmatched ?? 0),
            ],
            'formulas' => [
                'email_match_rate' => 'matched / total_emails * 100',
                'so_match_rate'    => 'so_matched / total_so * 100',
            ],
        ];
    }
}
