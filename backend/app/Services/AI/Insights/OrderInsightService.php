<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

class OrderInsightService
{
    /**
     * Return a snapshot of order metrics for AI context.
     *
     * @param  string|null  $dateFrom  ISO date, defaults to today
     * @param  string|null  $dateTo    ISO date, defaults to today
     */
    public function getSnapshot(?string $dateFrom = null, ?string $dateTo = null): array
    {
        $from = $dateFrom ?? now()->startOfDay()->toDateTimeString();
        $to   = $dateTo   ?? now()->endOfDay()->toDateTimeString();

        // Today totals
        $today = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('
                COUNT(*) as total,
                COALESCE(SUM(order_total), 0) as total_value,
                SUM(CASE WHEN status IN ("Completed","Shipping","Back Order") THEN 1 ELSE 0 END) as captured,
                SUM(CASE WHEN status NOT IN ("Completed","Shipping","Back Order") THEN 1 ELSE 0 END) as uncaptured,
                COALESCE(SUM(CASE WHEN status NOT IN ("Completed","Shipping","Back Order") THEN order_total ELSE 0 END), 0) as revenue_at_risk,
                COALESCE(AVG(order_total), 0) as avg_order_value
            ')
            ->first();

        $total       = (int) ($today->total ?? 0);
        $captured    = (int) ($today->captured ?? 0);
        $uncaptured  = (int) ($today->uncaptured ?? 0);
        $captureRate = $total > 0 ? round(($captured / $total) * 100, 1) : 0;

        // Status breakdown
        $statusBreakdown = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Match status breakdown
        $matchBreakdown = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('match_status, COUNT(*) as count')
            ->groupBy('match_status')
            ->pluck('count', 'match_status')
            ->toArray();

        // Top customers by order value today
        $topCustomers = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('customer_name, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as total_value')
            ->groupBy('customer_name')
            ->orderByDesc('total_value')
            ->limit(5)
            ->get()
            ->toArray();

        // Yesterday comparison
        $yesterdayFrom = now()->subDay()->startOfDay()->toDateTimeString();
        $yesterdayTo   = now()->subDay()->endOfDay()->toDateTimeString();
        $yesterday = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$yesterdayFrom, $yesterdayTo])
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(order_total), 0) as total_value')
            ->first();

        // This week vs last week
        $weekFrom     = now()->startOfWeek()->toDateTimeString();
        $weekTo       = now()->endOfWeek()->toDateTimeString();
        $lastWeekFrom = now()->subWeek()->startOfWeek()->toDateTimeString();
        $lastWeekTo   = now()->subWeek()->endOfWeek()->toDateTimeString();

        $thisWeek = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$weekFrom, $weekTo])
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(order_total), 0) as total_value')
            ->first();

        $lastWeek = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$lastWeekFrom, $lastWeekTo])
            ->selectRaw('COUNT(*) as total, COALESCE(SUM(order_total), 0) as total_value')
            ->first();

        return [
            'period'          => ['from' => $from, 'to' => $to],
            'total'           => $total,
            'total_value'     => round((float) ($today->total_value ?? 0), 2),
            'captured'        => $captured,
            'uncaptured'      => $uncaptured,
            'capture_rate'    => $captureRate,
            'revenue_at_risk' => round((float) ($today->revenue_at_risk ?? 0), 2),
            'avg_order_value' => round((float) ($today->avg_order_value ?? 0), 2),
            'status_breakdown'=> $statusBreakdown,
            'match_breakdown' => $matchBreakdown,
            'top_customers'   => array_map(fn($r) => (array) $r, $topCustomers),
            'yesterday' => [
                'total'       => (int) ($yesterday->total ?? 0),
                'total_value' => round((float) ($yesterday->total_value ?? 0), 2),
            ],
            'this_week' => [
                'total'       => (int) ($thisWeek->total ?? 0),
                'total_value' => round((float) ($thisWeek->total_value ?? 0), 2),
            ],
            'last_week' => [
                'total'       => (int) ($lastWeek->total ?? 0),
                'total_value' => round((float) ($lastWeek->total_value ?? 0), 2),
            ],
            'formulas' => [
                'capture_rate'    => 'captured / total * 100',
                'revenue_at_risk' => 'SUM(order_total) WHERE status NOT IN (Completed, Shipping, Back Order)',
            ],
        ];
    }
}
