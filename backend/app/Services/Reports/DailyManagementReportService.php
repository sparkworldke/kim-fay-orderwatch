<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyManagementReportService
{
    private const CAPTURED_STATUSES = ['completed', 'shipping', 'back order'];

    private const CRITICAL_STATUSES = ['on hold', 'credit hold', 'pending approval', 'rejected'];

    public function buildPayload(?Carbon $asOf = null, string $timezone = 'Africa/Nairobi'): array
    {
        $now = ($asOf ?? now())->copy()->timezone($timezone);
        $yesterday = $now->copy()->subDay()->startOfDay();
        $dayBefore = $now->copy()->subDays(2)->startOfDay();
        $mtdStart = $yesterday->copy()->startOfMonth();
        $priorMtdStart = $mtdStart->copy()->subMonth();
        $priorMtdEnd = $priorMtdStart->copy()->addDays($yesterday->day - 1)->endOfDay();

        $yesterdayMetrics = $this->periodMetrics($yesterday, $yesterday->copy()->endOfDay());
        $dayBeforeMetrics = $this->periodMetrics($dayBefore, $dayBefore->copy()->endOfDay());
        $mtdMetrics = $this->periodMetrics($mtdStart, $yesterday->copy()->endOfDay());
        $priorMtdMetrics = $this->periodMetrics($priorMtdStart, $priorMtdEnd);

        $comparison = $this->buildComparison($yesterdayMetrics, $dayBeforeMetrics);
        $mtdComparison = $this->buildComparison($mtdMetrics, $priorMtdMetrics);

        $customerHighlights = $this->customerHighlights(
            $yesterday->toDateString(),
            $yesterday->copy()->endOfDay()->toDateTimeString(),
        );

        $risk = $this->riskMetrics($yesterday, $yesterday->copy()->endOfDay());

        return [
            'report_type' => 'daily_management_email',
            'generated_at' => $now->toIso8601String(),
            'timezone' => $timezone,
            'report_date' => $yesterday->toDateString(),
            'report_date_label' => $yesterday->format('j M Y'),
            'report_date_display' => $yesterday->format('d/m/Y'),
            'comparison_date' => $dayBefore->toDateString(),
            'comparison_date_label' => $dayBefore->format('j M Y'),
            'comparison_date_display' => $dayBefore->format('d/m/Y'),
            'mtd_period_label' => $mtdStart->format('F Y'),
            'generated_at_display' => $now->format('d/m/Y H:i'),
            'mtd_period' => [
                'from' => $mtdStart->toDateString(),
                'to' => $yesterday->toDateString(),
                'label' => $mtdStart->format('F Y'),
            ],
            'yesterday' => $yesterdayMetrics,
            'day_before' => $dayBeforeMetrics,
            'mtd' => $mtdMetrics,
            'prior_mtd' => $priorMtdMetrics,
            'comparison' => $comparison,
            'mtd_comparison' => $mtdComparison,
            'operational' => [
                'completion_rate_yesterday' => $yesterdayMetrics['completion_rate'],
                'completion_rate_day_before' => $dayBeforeMetrics['completion_rate'],
                'completion_rate_mtd' => $mtdMetrics['completion_rate'],
                'capture_rate_yesterday' => $yesterdayMetrics['capture_rate'],
                'capture_rate_mtd' => $mtdMetrics['capture_rate'],
                'pending_action_count' => $risk['pending_manual_review'],
            ],
            'risk' => $risk,
            'customer_highlights' => $customerHighlights,
            'formulas' => [
                'completion_rate' => 'orders_completed / orders_received * 100',
                'capture_rate' => 'orders_captured / orders_received * 100',
                'revenue_at_risk' => 'SUM(order_total) WHERE status NOT captured',
                'critical_orders' => 'COUNT WHERE status IN (on hold, credit hold, pending approval, rejected)',
            ],
        ];
    }

    private function periodMetrics(Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateTimeString();
        $toStr = $to->toDateTimeString();

        $capturedSql = $this->statusInSql(self::CAPTURED_STATUSES);
        $criticalSql = $this->statusInSql(self::CRITICAL_STATUSES);

        $row = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$fromStr, $toStr])
            ->selectRaw("
                COUNT(*) as orders_received,
                COALESCE(SUM(order_total), 0) as total_order_value,
                SUM(CASE WHEN {$capturedSql} THEN 1 ELSE 0 END) as orders_captured,
                SUM(CASE WHEN {$capturedSql} THEN 0 ELSE 1 END) as outstanding_orders,
                COALESCE(SUM(CASE WHEN {$capturedSql} THEN order_total ELSE 0 END), 0) as completed_value,
                COALESCE(SUM(CASE WHEN {$capturedSql} THEN 0 ELSE order_total END), 0) as outstanding_value,
                COALESCE(SUM(CASE WHEN {$capturedSql} THEN 0 ELSE order_total END), 0) as revenue_at_risk,
                SUM(CASE WHEN {$criticalSql} THEN 1 ELSE 0 END) as critical_orders
            ")
            ->first();

        $received = (int) ($row->orders_received ?? 0);
        $captured = (int) ($row->orders_captured ?? 0);

        return [
            'orders_received' => $received,
            'total_order_value' => round((float) ($row->total_order_value ?? 0), 2),
            'orders_completed' => $captured,
            'orders_captured' => $captured,
            'outstanding_orders' => (int) ($row->outstanding_orders ?? 0),
            'completed_value' => round((float) ($row->completed_value ?? 0), 2),
            'outstanding_value' => round((float) ($row->outstanding_value ?? 0), 2),
            'revenue_at_risk' => round((float) ($row->revenue_at_risk ?? 0), 2),
            'critical_orders' => (int) ($row->critical_orders ?? 0),
            'completion_rate' => $received > 0 ? round(($captured / $received) * 100, 1) : 0.0,
            'capture_rate' => $received > 0 ? round(($captured / $received) * 100, 1) : 0.0,
        ];
    }

    private function riskMetrics(Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateTimeString();
        $toStr = $to->toDateTimeString();

        $emailRow = DB::table('emails')
            ->whereBetween('received_at', [$fromStr, $toStr])
            ->selectRaw('
                SUM(CASE WHEN match_classification IS NULL OR match_classification = "unmatched" THEN 1 ELSE 0 END) as unmatched_emails,
                SUM(CASE WHEN match_classification = "needs_review" THEN 1 ELSE 0 END) as needs_review_emails,
                SUM(CASE WHEN ingestion_review_status = "pending" THEN 1 ELSE 0 END) as pending_ingestion_review
            ')
            ->first();

        $pendingManual = (int) DB::table('emails')
            ->where('match_classification', 'needs_review')
            ->count();

        $discrepancyOrders = (int) DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$fromStr, $toStr])
            ->where('match_status', 'matched_with_discrepancies')
            ->count();

        return [
            'unmatched_emails' => (int) ($emailRow->unmatched_emails ?? 0),
            'needs_review_emails' => (int) ($emailRow->needs_review_emails ?? 0),
            'pending_ingestion_review' => (int) ($emailRow->pending_ingestion_review ?? 0),
            'pending_manual_review' => $pendingManual,
            'orders_with_discrepancies' => $discrepancyOrders,
        ];
    }

    private function customerHighlights(string $fromDate, string $toDateTime): array
    {
        $capturedSql = $this->statusInSql(self::CAPTURED_STATUSES);

        $topByValue = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$fromDate.' 00:00:00', $toDateTime])
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->selectRaw('customer_name, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as total_value')
            ->groupBy('customer_name')
            ->orderByDesc('total_value')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'customer_name' => $row->customer_name ?? 'Unknown',
                'orders' => (int) $row->orders,
                'total_value' => round((float) $row->total_value, 2),
            ])
            ->all();

        $topOutstanding = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$fromDate.' 00:00:00', $toDateTime])
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->whereRaw("NOT ({$capturedSql})")
            ->selectRaw('customer_name, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as outstanding_value')
            ->groupBy('customer_name')
            ->orderByDesc('outstanding_value')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'customer_name' => $row->customer_name ?? 'Unknown',
                'orders' => (int) $row->orders,
                'outstanding_value' => round((float) $row->outstanding_value, 2),
            ])
            ->all();

        return [
            'top_by_value' => $topByValue,
            'top_outstanding' => $topOutstanding,
            'top_positive' => $topByValue[0] ?? null,
            'top_risk' => $topOutstanding[0] ?? null,
        ];
    }

    /** @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, array<string, mixed>>
     */
    private function buildComparison(array $current, array $previous): array
    {
        $metrics = [
            'orders_received',
            'total_order_value',
            'orders_completed',
            'completion_rate',
            'outstanding_orders',
            'revenue_at_risk',
            'critical_orders',
        ];

        $comparison = [];
        foreach ($metrics as $metric) {
            $yesterdayVal = $current[$metric] ?? 0;
            $beforeVal = $previous[$metric] ?? 0;
            $absolute = round($yesterdayVal - $beforeVal, $metric === 'completion_rate' ? 1 : 2);
            $percent = $beforeVal != 0
                ? round((($yesterdayVal - $beforeVal) / abs($beforeVal)) * 100, 1)
                : ($yesterdayVal > 0 ? 100.0 : 0.0);

            $direction = $absolute > 0 ? 'up' : ($absolute < 0 ? 'down' : 'flat');
            $lowerIsBetter = in_array($metric, ['outstanding_orders', 'revenue_at_risk', 'critical_orders'], true);
            $sentiment = match (true) {
                $direction === 'flat' => 'stagnant',
                ($direction === 'up' && ! $lowerIsBetter) || ($direction === 'down' && $lowerIsBetter) => 'improvement',
                default => 'decline',
            };

            $comparison[$metric] = [
                'yesterday' => $yesterdayVal,
                'day_before' => $beforeVal,
                'absolute_change' => $absolute,
                'percent_change' => $percent,
                'direction' => $direction,
                'sentiment' => $sentiment,
            ];
        }

        return $comparison;
    }

    /** @param  list<string>  $statuses */
    private function statusInSql(array $statuses): string
    {
        $quoted = collect($statuses)
            ->map(fn (string $status) => "'".str_replace("'", "''", strtolower($status))."'")
            ->implode(', ');

        return "LOWER(TRIM(status)) IN ({$quoted})";
    }
}