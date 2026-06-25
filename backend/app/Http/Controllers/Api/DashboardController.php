<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaSalesOrder;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Return status-broken-down KPI counts for the given date range. */
    public function kpis(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());

        $counts = $this->statusCounts($dateFrom, $dateTo);

        return response()->json(array_merge($counts, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
        ]));
    }

    /** Daily trend data for the chart, optionally with previous-period comparison. */
    public function trend(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());
        $compare  = $request->boolean('compare', false);

        $current = $this->dailyTrend($dateFrom, $dateTo);

        $previous = null;
        if ($compare) {
            $days     = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1;
            $prevFrom = Carbon::parse($dateFrom)->subDays($days)->toDateString();
            $prevTo   = Carbon::parse($dateTo)->subDays($days)->toDateString();
            $previous = $this->dailyTrend($prevFrom, $prevTo, $prevFrom);
        }

        return response()->json([
            'current'  => $current,
            'previous' => $previous,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function statusCounts(string $dateFrom, string $dateTo): array
    {
        $base = AcumaticaSalesOrder::salesOrdersOnly()
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo);

        $rows = (clone $base)
            ->select([DB::raw('status'), DB::raw('COUNT(*) as cnt')])
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->mapWithKeys(fn ($cnt, $status) => [strtolower(trim($status ?? 'unknown')) => (int) $cnt])
            ->toArray();

        $total = (int) (clone $base)->count();

        // Count distinct days that have at least one order (active trading days)
        $activeDays = (int) (clone $base)
            ->selectRaw('COUNT(DISTINCT DATE(order_date)) as cnt')
            ->value('cnt');

        $avgPerDay = $activeDays > 0
            ? round($total / $activeDays, 1)
            : 0.0;

        return [
            'total'            => $total,
            'completed'        => $rows['completed']        ?? 0,
            'shipping'         => $rows['shipping']         ?? 0,
            'pending_approval' => $rows['pending approval'] ?? 0,
            'rejected'         => $rows['rejected']         ?? 0,
            'on_hold'          => ($rows['on hold'] ?? 0) + ($rows['credit hold'] ?? 0),
            'open'             => $rows['open']             ?? 0,
            'back_order'       => $rows['back order']       ?? 0,
            'avg_per_day'      => $avgPerDay,
            'active_days'      => $activeDays,
            'open_so'          => $rows['open'] ?? 0,
        ];
    }

    /**
     * Group orders by day, returning a flat array where each entry is:
     * { day, label, total, completed, shipping, pending_approval, rejected, on_hold }
     *
     * $labelOffset shifts the "label" day for comparison rows so the chart
     * can align current vs previous on the same x-axis position.
     */
    private function dailyTrend(string $dateFrom, string $dateTo, ?string $labelOffset = null): array
    {
        $rows = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->selectRaw('DATE(order_date) as day, status, COUNT(*) as cnt')
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->groupByRaw('DATE(order_date), status')
            ->orderByRaw('DATE(order_date)')
            ->get();

        $fillRatesByDay = $this->fillRateQtyByDay($dateFrom, $dateTo);

        // Build a map keyed by day
        $byDay = [];
        foreach ($rows as $row) {
            $day = $row->day;
            if (! isset($byDay[$day])) {
                $fillQty = $fillRatesByDay[$day] ?? ['shipped' => 0.0, 'ordered' => 0.0];
                $byDay[$day] = [
                    'day'              => $day,
                    'total'            => 0,
                    'completed'        => 0,
                    'shipping'         => 0,
                    'pending_approval' => 0,
                    'rejected'         => 0,
                    'on_hold'          => 0,
                    'open'             => 0,
                    'fill_shipped_qty' => $fillQty['shipped'],
                    'fill_ordered_qty' => $fillQty['ordered'],
                    'fill_rate_pct'    => SalesOrderLineFulfillmentDeriver::safeFillRate(
                        $fillQty['shipped'],
                        $fillQty['ordered'],
                    ),
                ];
            }
            $byDay[$day]['total'] += (int) $row->cnt;
            $key = match (strtolower(trim($row->status ?? ''))) {
                'completed'        => 'completed',
                'shipping'         => 'shipping',
                'pending approval' => 'pending_approval',
                'rejected'         => 'rejected',
                'on hold', 'credit hold' => 'on_hold',
                'open'             => 'open',
                default            => null,
            };
            if ($key) {
                $byDay[$day][$key] += (int) $row->cnt;
            }
        }

        // If this is a comparison set, shift the day labels forward by the
        // period length so the chart x-axis aligns with the current period.
        if ($labelOffset !== null) {
            $offsetDays = Carbon::parse($labelOffset)->diffInDays(now()->startOfMonth());
            $result = [];
            foreach (array_values($byDay) as $i => $entry) {
                $entry['day'] = Carbon::parse($entry['day'])->addDays($offsetDays)->toDateString();
                $result[] = $entry;
            }
            return $result;
        }

        // Include days that have fill-rate data but no status breakdown rows.
        foreach ($fillRatesByDay as $day => $fillQty) {
            if (isset($byDay[$day])) {
                continue;
            }

            $byDay[$day] = [
                'day'              => $day,
                'total'            => 0,
                'completed'        => 0,
                'shipping'         => 0,
                'pending_approval' => 0,
                'rejected'         => 0,
                'on_hold'          => 0,
                'open'             => 0,
                'fill_shipped_qty' => $fillQty['shipped'],
                'fill_ordered_qty' => $fillQty['ordered'],
                'fill_rate_pct'    => SalesOrderLineFulfillmentDeriver::safeFillRate(
                    $fillQty['shipped'],
                    $fillQty['ordered'],
                ),
            ];
        }

        ksort($byDay);

        return array_values($byDay);
    }

    /**
     * @return array<string, array{shipped: float, ordered: float}>
     */
    private function fillRateQtyByDay(string $dateFrom, string $dateTo): array
    {
        $rows = DB::table('acumatica_fill_rate_snapshots as f')
            ->join('acumatica_sales_orders as o', 'f.sales_order_id', '=', 'o.id')
            ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->whereDate('o.order_date', '>=', $dateFrom)
            ->whereDate('o.order_date', '<=', $dateTo)
            ->where('f.fill_rate_status', '!=', 'na')
            ->selectRaw('DATE(o.order_date) as day')
            ->selectRaw('SUM(f.total_shipped_qty) as shipped')
            ->selectRaw('SUM(f.total_ordered_qty) as ordered')
            ->groupByRaw('DATE(o.order_date)')
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            $byDay[$row->day] = [
                'shipped' => (float) $row->shipped,
                'ordered' => (float) $row->ordered,
            ];
        }

        return $byDay;
    }
}
