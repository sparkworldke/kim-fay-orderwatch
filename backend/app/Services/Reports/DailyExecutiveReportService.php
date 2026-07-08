<?php

namespace App\Services\Reports;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DailyExecutiveReportService
{
    /** Orders considered fully processed for prior-month carryover (shipping still counts as incomplete). */
    private const PRIOR_MONTH_COMPLETE_STATUSES = ['completed', 'back order'];

    public function __construct(
        private readonly ExecutiveReportMetricsService $metrics,
    ) {}

    public function buildPayload(?Carbon $asOf = null, string $timezone = 'Africa/Nairobi'): array
    {
        $now = ($asOf ?? now())->copy()->timezone($timezone);
        $reportDate = $now->copy()->subDay()->startOfDay();
        $weekStart = $reportDate->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $reportDateStr = $reportDate->toDateString();

        $orders = $this->buildOrdersSection($weekStart, $reportDate);
        $summary = $this->metrics->fillRateSummary($reportDateStr);

        return [
            'report_type' => 'daily_executive_email',
            'generated_at' => $now->toIso8601String(),
            'generated_at_display' => $now->format('d/m/Y H:i'),
            'timezone' => $timezone,
            'report_date' => $reportDateStr,
            'report_date_label' => $reportDate->format('j M Y'),
            'report_date_display' => $reportDate->format('d/m/Y'),
            'week' => [
                'from' => $weekStart->toDateString(),
                'to' => $reportDateStr,
                'label' => $weekStart->format('j M').' – '.$reportDate->format('j M Y'),
            ],
            'orders' => $orders,
            'fill_rate' => [
                'fill_rate_pct' => $summary['fill_rate_pct'],
                'orders_tracked' => $summary['orders_tracked'],
                'revenue_not_shipped' => $summary['fill_rate_not_shipped'],
            ],
            'backorders' => [
                'backorder_exposure_pct' => $summary['backorder_exposure_pct'],
                'revenue_at_risk' => $summary['backorder_revenue_at_risk'],
                'top_reasons' => $this->metrics->topCombinedReasons($reportDateStr),
            ],
            'sla' => $this->metrics->metroSlaSummary($reportDateStr),
            'revenue_split' => array_merge(
                ['date' => $reportDateStr, 'date_label' => $reportDate->format('j M Y')],
                $this->metrics->revenueSplitForDate($reportDateStr),
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function buildOrdersSection(Carbon $weekStart, Carbon $reportDate): array
    {
        $dailyTable = [];
        $weekTotals = ['total_orders' => 0, 'completed_orders' => 0, 'pending_approval' => 0, 'in_shipping' => 0];
        $yesterdayMetrics = $this->orderMetricsForDay($reportDate);

        $cursor = $weekStart->copy();
        while ($cursor->lte($reportDate)) {
            if ($cursor->dayOfWeek === Carbon::SUNDAY) {
                $cursor->addDay();
                continue;
            }

            $dayMetrics = $cursor->isSameDay($reportDate)
                ? $yesterdayMetrics
                : $this->orderMetricsForDay($cursor);

            $dailyTable[] = [
                'date' => $cursor->toDateString(),
                'date_label' => $cursor->format('D j M'),
                'is_report_date' => $cursor->isSameDay($reportDate),
                'total_orders' => $dayMetrics['total_orders'],
                'completed_orders' => $dayMetrics['completed_orders'],
                'pending_approval' => $dayMetrics['pending_approval'],
                'in_shipping' => $dayMetrics['in_shipping'],
            ];

            $weekTotals['total_orders'] += $dayMetrics['total_orders'];
            $weekTotals['completed_orders'] += $dayMetrics['completed_orders'];
            $weekTotals['pending_approval'] += $dayMetrics['pending_approval'];
            $weekTotals['in_shipping'] += $dayMetrics['in_shipping'];

            $cursor->addDay();
        }

        return [
            'yesterday' => array_merge(
                ['date_label' => $reportDate->format('D j M')],
                $yesterdayMetrics,
            ),
            'week_totals' => $weekTotals,
            'daily_table' => $dailyTable,
            'prior_month_carryover' => $this->priorMonthCarryover($reportDate),
        ];
    }

    /** @return array{total_orders:int,completed_orders:int,pending_approval:int,in_shipping:int} */
    private function orderMetricsForDay(Carbon $day): array
    {
        $date = $day->toDateString();

        $row = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereBetween('order_date', [$date.' 00:00:00', $date.' 23:59:59'])
            ->selectRaw("
                COUNT(*) as total_orders,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pending_approval,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as in_shipping
            ")
            ->first();

        return [
            'total_orders' => (int) ($row->total_orders ?? 0),
            'completed_orders' => (int) ($row->completed_orders ?? 0),
            'pending_approval' => (int) ($row->pending_approval ?? 0),
            'in_shipping' => (int) ($row->in_shipping ?? 0),
        ];
    }

    /**
     * Prior-month carryover: SOs dated in the previous calendar month that are still incomplete today.
     *
     * @return array<string, mixed>
     */
    private function priorMonthCarryover(Carbon $reportDate): array
    {
        $priorStart = $reportDate->copy()->subMonth()->startOfMonth();
        $priorEnd = $reportDate->copy()->subMonth()->endOfMonth();
        $capturedSql = $this->statusInSql(self::PRIOR_MONTH_COMPLETE_STATUSES);

        $row = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereBetween('order_date', [$priorStart->format('Y-m-d').' 00:00:00', $priorEnd->format('Y-m-d').' 23:59:59'])
            ->whereRaw("NOT ({$capturedSql})")
            ->selectRaw("
                COUNT(*) as total_incomplete,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'pending approval' THEN 1 ELSE 0 END) as pending_approval,
                SUM(CASE WHEN LOWER(TRIM(status)) = 'shipping' THEN 1 ELSE 0 END) as in_shipping
            ")
            ->first();

        $totalIncomplete = (int) ($row->total_incomplete ?? 0);

        return [
            'month_label' => $priorStart->format('F Y'),
            'total_incomplete' => $totalIncomplete,
            'pending_approval' => (int) ($row->pending_approval ?? 0),
            'in_shipping' => (int) ($row->in_shipping ?? 0),
            'show' => $totalIncomplete > 0,
        ];
    }

    /** @param  list<string>  $statuses */
    private function statusInSql(array $statuses, string $column = 'status'): string
    {
        $quoted = collect($statuses)
            ->map(fn (string $status) => "'".str_replace("'", "''", strtolower($status))."'")
            ->implode(', ');

        return "LOWER(TRIM({$column})) IN ({$quoted})";
    }
}
