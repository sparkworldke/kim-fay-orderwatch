<?php

namespace App\Services\AI;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AiIntelligenceDataService
{
    private const CAPTURED_STATUSES = ['completed', 'shipping', 'back order'];

    private function salesOrdersOnly($query)
    {
        return $query->where('order_type', 'SO');
    }

    public function build(string $dateFrom, string $dateTo, string $timezone = 'Africa/Nairobi'): array
    {
        $from = Carbon::parse($dateFrom, $timezone)->startOfDay();
        $to = Carbon::parse($dateTo, $timezone)->endOfDay();
        $days = max(1, $from->diffInDays($to) + 1);

        $priorTo = $from->copy()->subDay()->endOfDay();
        $priorFrom = $priorTo->copy()->subDays($days - 1)->startOfDay();

        $orders = $this->orderMetrics($from, $to);
        $priorOrders = $this->orderMetrics($priorFrom, $priorTo);
        $customers = $this->customerMetrics($from, $to, $priorFrom, $priorTo);
        $daily = $this->dailySeries($from, $to);
        $historical = $this->historicalWeekly(now($timezone));
        $projections = $this->projections($daily, $orders, $historical);

        return [
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'label' => $from->format('d M Y').' – '.$to->format('d M Y'),
                'days' => $days,
            ],
            'comparison_period' => [
                'from' => $priorFrom->toDateString(),
                'to' => $priorTo->toDateString(),
                'label' => $priorFrom->format('d M Y').' – '.$priorTo->format('d M Y'),
            ],
            'orders' => $orders,
            'orders_prior' => $priorOrders,
            'orders_comparison' => $this->compareMetrics($orders, $priorOrders),
            'customers' => $customers,
            'daily_trend' => $daily,
            'historical_weekly' => $historical,
            'projections' => $projections,
            'generated_at' => now($timezone)->toIso8601String(),
        ];
    }

    private function orderMetrics(Carbon $from, Carbon $to): array
    {
        $fromStr = $from->toDateTimeString();
        $toStr = $to->toDateTimeString();
        $capturedSql = $this->statusInSql(self::CAPTURED_STATUSES);

        $row = $this->salesOrdersOnly(DB::table('acumatica_sales_orders'))
            ->whereBetween('order_date', [$fromStr, $toStr])
            ->selectRaw("
                COUNT(*) as orders_received,
                COALESCE(SUM(order_total), 0) as total_value,
                SUM(CASE WHEN {$capturedSql} THEN 1 ELSE 0 END) as orders_captured,
                SUM(CASE WHEN {$capturedSql} THEN 0 ELSE 1 END) as outstanding,
                COALESCE(SUM(CASE WHEN {$capturedSql} THEN 0 ELSE order_total END), 0) as revenue_at_risk,
                COALESCE(AVG(order_total), 0) as avg_order_value
            ")
            ->first();

        $received = (int) ($row->orders_received ?? 0);
        $captured = (int) ($row->orders_captured ?? 0);

        $statusBreakdown = $this->salesOrdersOnly(DB::table('acumatica_sales_orders'))
            ->whereBetween('order_date', [$fromStr, $toStr])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        return [
            'orders_received' => $received,
            'total_value' => round((float) ($row->total_value ?? 0), 2),
            'orders_captured' => $captured,
            'outstanding' => (int) ($row->outstanding ?? 0),
            'completion_rate' => $received > 0 ? round(($captured / $received) * 100, 1) : 0.0,
            'revenue_at_risk' => round((float) ($row->revenue_at_risk ?? 0), 2),
            'avg_order_value' => round((float) ($row->avg_order_value ?? 0), 2),
            'status_breakdown' => $statusBreakdown,
        ];
    }

    private function customerMetrics(Carbon $from, Carbon $to, Carbon $priorFrom, Carbon $priorTo): array
    {
        $fromStr = $from->toDateTimeString();
        $toStr = $to->toDateTimeString();
        $priorFromStr = $priorFrom->toDateTimeString();
        $priorToStr = $priorTo->toDateTimeString();

        $topCurrent = $this->customerRanking($fromStr, $toStr, 8);
        $topPrior = $this->customerRanking($priorFromStr, $priorToStr, 8);

        $growth = [];
        $decline = [];
        foreach ($topCurrent as $name => $current) {
            $prior = $topPrior[$name] ?? ['orders' => 0, 'value' => 0.0];
            $delta = $current['value'] - $prior['value'];
            $entry = [
                'customer_name' => $name,
                'orders' => $current['orders'],
                'value' => $current['value'],
                'prior_value' => $prior['value'],
                'value_change' => round($delta, 2),
                'value_change_pct' => $prior['value'] > 0 ? round(($delta / $prior['value']) * 100, 1) : ($current['value'] > 0 ? 100.0 : 0.0),
            ];
            if ($delta > 0) {
                $growth[] = $entry;
            } elseif ($delta < 0) {
                $decline[] = $entry;
            }
        }

        usort($growth, fn ($a, $b) => $b['value_change'] <=> $a['value_change']);
        usort($decline, fn ($a, $b) => $a['value_change'] <=> $b['value_change']);

        $activeInPeriod = array_keys($topCurrent);
        $activePrior = array_keys($topPrior);

        return [
            'top_customers' => collect($topCurrent)
                ->map(fn ($data, $name) => array_merge(['customer_name' => $name], $data))
                ->values()
                ->all(),
            'fastest_growth' => array_slice($growth, 0, 5),
            'fastest_decline' => array_slice($decline, 0, 5),
            'new_or_returning' => array_values(array_diff($activeInPeriod, $activePrior)),
            'went_quiet' => array_values(array_diff($activePrior, $activeInPeriod)),
            'unique_customers' => count($activeInPeriod),
            'prior_unique_customers' => count($activePrior),
        ];
    }

    /** @return array<string, array{orders: int, value: float}> */
    private function customerRanking(string $from, string $to, int $limit): array
    {
        return $this->salesOrdersOnly(DB::table('acumatica_sales_orders'))
            ->whereBetween('order_date', [$from, $to])
            ->whereNotNull('customer_name')
            ->where('customer_name', '!=', '')
            ->selectRaw('customer_name, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as value')
            ->groupBy('customer_name')
            ->orderByDesc('value')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->customer_name => [
                    'orders' => (int) $row->orders,
                    'value' => round((float) $row->value, 2),
                ],
            ])
            ->all();
    }

    /** @return list<array{day: string, orders: int, value: float, captured: int}> */
    private function dailySeries(Carbon $from, Carbon $to): array
    {
        $capturedSql = $this->statusInSql(self::CAPTURED_STATUSES);

        $rows = $this->salesOrdersOnly(DB::table('acumatica_sales_orders'))
            ->selectRaw("DATE(order_date) as day, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as value, SUM(CASE WHEN {$capturedSql} THEN 1 ELSE 0 END) as captured")
            ->whereBetween('order_date', [$from->toDateTimeString(), $to->toDateTimeString()])
            ->groupByRaw('DATE(order_date)')
            ->orderByRaw('DATE(order_date)')
            ->get();

        return $rows->map(fn ($row) => [
            'day' => $row->day,
            'orders' => (int) $row->orders,
            'value' => round((float) $row->value, 2),
            'captured' => (int) $row->captured,
        ])->all();
    }

    /** @return list<array{week_start: string, orders: int, value: float}> */
    private function historicalWeekly(Carbon $now): array
    {
        $from = $now->copy()->subDays(84)->startOfDay();

        $driver = DB::connection()->getDriverName();
        $weekExpr = $driver === 'sqlite'
            ? "strftime('%Y-%W', order_date)"
            : "DATE(DATE_SUB(order_date, INTERVAL WEEKDAY(order_date) DAY))";

        return $this->salesOrdersOnly(DB::table('acumatica_sales_orders'))
            ->selectRaw("{$weekExpr} as week_start, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as value")
            ->where('order_date', '>=', $from->toDateTimeString())
            ->groupByRaw('week_start')
            ->orderByRaw('week_start')
            ->get()
            ->map(fn ($row) => [
                'week_start' => $row->week_start,
                'orders' => (int) $row->orders,
                'value' => round((float) $row->value, 2),
            ])
            ->all();
    }

    /** @param  list<array{day: string, orders: int, value: float, captured: int}>  $daily
     * @param  list<array{week_start: string, orders: int, value: float}>  $historical */
    private function projections(array $daily, array $orders, array $historical): array
    {
        $dayCount = max(1, count($daily));
        $avgDailyOrders = round($orders['orders_received'] / $dayCount, 1);
        $avgDailyValue = round($orders['total_value'] / $dayCount, 2);

        $recent = array_slice($daily, -7);
        $priorSlice = array_slice($daily, -14, 7);
        $recentOrders = array_sum(array_column($recent, 'orders'));
        $priorOrders = max(1, array_sum(array_column($priorSlice, 'orders')));
        $momentum = round((($recentOrders - $priorOrders) / $priorOrders) * 100, 1);

        $weeklyOrders = array_column($historical, 'orders');
        $weeklyTrend = count($weeklyOrders) >= 2
            ? ($weeklyOrders[count($weeklyOrders) - 1] - $weeklyOrders[count($weeklyOrders) - 2])
            : 0;

        return [
            'avg_daily_orders' => $avgDailyOrders,
            'avg_daily_value' => $avgDailyValue,
            'projected_next_7_days_orders' => (int) round($avgDailyOrders * 7 * (1 + ($momentum / 100))),
            'projected_next_7_days_value' => round($avgDailyValue * 7 * (1 + ($momentum / 100)), 2),
            'volume_momentum_pct' => $momentum,
            'weekly_order_trend_delta' => $weeklyTrend,
            'completion_rate_forecast' => $orders['completion_rate'],
            'method' => 'Trailing period average adjusted by 7-day volume momentum and 12-week history',
        ];
    }

    /** @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $prior */
    private function compareMetrics(array $current, array $prior): array
    {
        $keys = ['orders_received', 'total_value', 'orders_captured', 'completion_rate', 'revenue_at_risk'];
        $comparison = [];
        foreach ($keys as $key) {
            $cur = $current[$key] ?? 0;
            $prev = $prior[$key] ?? 0;
            $comparison[$key] = [
                'current' => $cur,
                'prior' => $prev,
                'change' => round($cur - $prev, is_float($cur) ? 2 : 0),
                'change_pct' => $prev != 0 ? round((($cur - $prev) / abs($prev)) * 100, 1) : ($cur > 0 ? 100.0 : 0.0),
            ];
        }

        return $comparison;
    }

    private function statusInSql(array $statuses): string
    {
        $quoted = collect($statuses)
            ->map(fn (string $status) => "'".str_replace("'", "''", strtolower($status))."'")
            ->implode(', ');

        return "LOWER(TRIM(status)) IN ({$quoted})";
    }
}