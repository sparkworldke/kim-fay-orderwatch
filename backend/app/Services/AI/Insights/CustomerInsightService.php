<?php

namespace App\Services\AI\Insights;

use Illuminate\Support\Facades\DB;

class CustomerInsightService
{
    public function getSnapshot(): array
    {
        // Active vs inactive customers
        $customerCounts = DB::table('acumatica_customers')
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = "Active" THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status != "Active" THEN 1 ELSE 0 END) as inactive
            ')
            ->first();

        // Top customers by order value (all time)
        $topCustomers = DB::table('acumatica_sales_orders')
            ->selectRaw('customer_name, COUNT(*) as order_count, COALESCE(SUM(order_total), 0) as total_value')
            ->groupBy('customer_name')
            ->orderByDesc('total_value')
            ->limit(5)
            ->get()
            ->toArray();

        // Customers by last order — find those inactive for >30 days
        $now = now();
        $thirtyDaysAgo = $now->copy()->subDays(30)->toDateTimeString();
        $ninetyDaysAgo = $now->copy()->subDays(90)->toDateTimeString();

        $decliners = DB::table('acumatica_sales_orders')
            ->selectRaw('customer_name, MAX(order_date) as last_order_date, COUNT(*) as recent_count')
            ->where('order_date', '>=', $thirtyDaysAgo)
            ->groupBy('customer_name')
            ->orderBy('recent_count')
            ->limit(5)
            ->get()
            ->toArray();

        // Customers with no orders in last 90 days (churn risk)
        $activeCustomerNames = DB::table('acumatica_sales_orders')
            ->where('order_date', '>=', $ninetyDaysAgo)
            ->distinct()
            ->pluck('customer_name')
            ->toArray();

        $churnRisk = DB::table('acumatica_customers')
            ->where('status', 'Active')
            ->whereNotIn('name', $activeCustomerNames)
            ->select(['name', 'acumatica_id'])
            ->limit(5)
            ->get()
            ->toArray();

        // This month vs last month per customer (top 5)
        $monthFrom     = $now->copy()->startOfMonth()->toDateTimeString();
        $lastMonthFrom = $now->copy()->subMonth()->startOfMonth()->toDateTimeString();
        $lastMonthTo   = $now->copy()->subMonth()->endOfMonth()->toDateTimeString();

        $thisMonthCustomers = DB::table('acumatica_sales_orders')
            ->where('order_date', '>=', $monthFrom)
            ->selectRaw('customer_name, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as value')
            ->groupBy('customer_name')
            ->orderByDesc('value')
            ->limit(5)
            ->pluck('value', 'customer_name')
            ->toArray();

        $lastMonthCustomers = DB::table('acumatica_sales_orders')
            ->whereBetween('order_date', [$lastMonthFrom, $lastMonthTo])
            ->selectRaw('customer_name, COALESCE(SUM(order_total), 0) as value')
            ->groupBy('customer_name')
            ->pluck('value', 'customer_name')
            ->toArray();

        return [
            'total_customers'  => (int) ($customerCounts->total ?? 0),
            'active_customers' => (int) ($customerCounts->active ?? 0),
            'inactive_customers'=> (int) ($customerCounts->inactive ?? 0),
            'top_customers'    => array_map(fn($r) => (array) $r, $topCustomers),
            'declining'        => array_map(fn($r) => (array) $r, $decliners),
            'churn_risk'       => array_map(fn($r) => (array) $r, $churnRisk),
            'this_month_by_customer' => $thisMonthCustomers,
            'last_month_by_customer' => $lastMonthCustomers,
            'formulas' => [
                'churn_risk'   => 'Active customers with no orders in last 90 days',
                'declining'    => 'Customers with lowest order count in last 30 days',
            ],
        ];
    }
}
