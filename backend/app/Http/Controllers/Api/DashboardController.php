<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use App\Services\Operations\OperationsCatalogResolver;
use App\Support\DataScope;
use App\Support\SalesConsultantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __construct(
        private readonly OperationsCatalogResolver $catalogResolver,
    ) {
    }

    /** Return status-broken-down KPI counts for the given date range (excludes Goods Lost in Transit). */
    public function kpis(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());
        $user = $request->user();

        $counts = $this->statusCounts($dateFrom, $dateTo, $user, excludeSpecialCustomers: true);
        $allCounts = $this->statusCounts($dateFrom, $dateTo, $user, excludeSpecialCustomers: false);
        $gltCounts = $this->statusCounts($dateFrom, $dateTo, $user, onlyGoodsLostInTransit: true);

        return response()->json(array_merge($counts, [
            'date_from' => $dateFrom,
            'date_to'   => $dateTo,
            // Total SO calculation: all SO vs dashboard (excl. GLT) vs GLT tab.
            'so_totals' => [
                'all_so' => $allCounts['total'],
                'dashboard_so' => $counts['total'],
                'goods_lost_in_transit_so' => $gltCounts['total'],
                'excluded_customer_ids' => $this->excludedCustomerIds(),
                'formula' => 'All SO = Dashboard SO + Goods Lost in Transit SO',
                'calculation' => sprintf(
                    '%d = %d + %d',
                    $allCounts['total'],
                    $counts['total'],
                    $gltCounts['total'],
                ),
            ],
            'goods_lost_in_transit' => [
                'customer_id' => $this->goodsLostInTransitCustomerId(),
                'label' => $this->goodsLostInTransitLabel(),
                'total' => $gltCounts['total'],
                'statuses' => $gltCounts,
            ],
        ]));
    }

    /**
     * Goods Lost in Transit tab — SOs for the dedicated customer (default CUST102641).
     */
    public function goodsLostInTransit(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $customerId = $this->goodsLostInTransitCustomerId();
        $user = $request->user();

        $base = DataScope::applyOrderScope(
            AcumaticaSalesOrder::query()->salesOrdersOnly(),
            $user,
        )
            ->where('customer_acumatica_id', $customerId)
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo);

        $statusRows = (clone $base)
            ->select([DB::raw('status'), DB::raw('COUNT(*) as cnt')])
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->mapWithKeys(fn ($cnt, $status) => [strtolower(trim($status ?? 'unknown')) => (int) $cnt])
            ->toArray();

        $total = (int) (clone $base)->count();
        $orderTotalSum = (float) (clone $base)->sum('order_total');

        $orders = (clone $base)
            ->withSum('lines as total_qty', 'order_qty')
            ->orderByDesc('order_date')
            ->orderByDesc('acumatica_order_nbr')
            ->limit(500)
            ->get([
                'id',
                'acumatica_order_nbr',
                'customer_name',
                'customer_acumatica_id',
                'order_total',
                'currency_id',
                'status',
                'order_date',
            ]);

        $customerNames = $this->catalogResolver->namesForCustomerIds([$customerId]);
        $customerName = $this->catalogResolver->resolveCustomerName(
            $orders->first()?->customer_name,
            $customerId,
            $customerNames,
        ) ?? $this->goodsLostInTransitLabel();

        // All SO in range (same scope) for the calculation strip.
        $allSoTotal = (int) DataScope::applyOrderScope(
            AcumaticaSalesOrder::query()->salesOrdersOnly(),
            $user,
        )
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->count();

        $dashboardSoTotal = max(0, $allSoTotal - $total);

        return response()->json([
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'label' => $this->goodsLostInTransitLabel(),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'total' => $total,
            'order_total_sum' => round($orderTotalSum, 2),
            'completed' => $statusRows['completed'] ?? 0,
            'shipping' => $statusRows['shipping'] ?? 0,
            'pending_approval' => $statusRows['pending approval'] ?? 0,
            'rejected' => $statusRows['rejected'] ?? 0,
            'on_hold' => ($statusRows['on hold'] ?? 0) + ($statusRows['credit hold'] ?? 0),
            'open' => $statusRows['open'] ?? 0,
            'so_totals' => [
                'all_so' => $allSoTotal,
                'dashboard_so' => $dashboardSoTotal,
                'goods_lost_in_transit_so' => $total,
                'formula' => 'All SO = Dashboard SO + Goods Lost in Transit SO',
                'calculation' => sprintf(
                    '%d = %d + %d',
                    $allSoTotal,
                    $dashboardSoTotal,
                    $total,
                ),
            ],
            'orders' => $orders->map(fn ($order) => [
                'id' => $order->id,
                'order_nbr' => $order->acumatica_order_nbr,
                'customer_acumatica_id' => $order->customer_acumatica_id,
                'customer_name' => $this->catalogResolver->resolveCustomerName(
                    $order->customer_name,
                    $order->customer_acumatica_id,
                    $customerNames,
                ),
                'amount' => round((float) $order->order_total, 2),
                'currency_id' => $order->currency_id,
                'quantity' => round((float) ($order->total_qty ?? 0), 4),
                'order_date' => $order->order_date?->toDateString(),
                'status' => $order->status,
            ])->values(),
        ]);
    }

    /** Orders for a dashboard status bucket (used by expandable status accordions). */
    public function ordersByStatus(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to', now()->toDateString());
        $statusKey = strtolower(trim((string) $request->input('status', '')));

        $statuses = match ($statusKey) {
            'open'             => ['Open'],
            'completed'        => ['Completed'],
            'shipping'         => ['Shipping'],
            'pending_approval' => ['Pending Approval'],
            'rejected'         => ['Rejected'],
            'on_hold'          => ['On Hold', 'Credit Hold'],
            default            => null,
        };

        if ($statuses === null) {
            return response()->json(['message' => 'Invalid status key.'], 422);
        }

        $orders = $this->excludeSpecialCustomers(
            DataScope::applyOrderScope(
                AcumaticaSalesOrder::query()->salesOrdersOnly(),
                $request->user(),
            ),
        )
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->whereIn('status', $statuses)
            ->withSum('lines as total_qty', 'order_qty')
            ->orderByDesc('order_date')
            ->orderByDesc('acumatica_order_nbr')
            ->limit(200)
            ->get([
                'id',
                'acumatica_order_nbr',
                'customer_name',
                'customer_acumatica_id',
                'order_total',
                'currency_id',
                'status',
                'order_date',
            ]);

        $customerNames = $this->catalogResolver->namesForCustomerIds(
            $orders->pluck('customer_acumatica_id')->filter()->unique()->values()->all(),
        );

        return response()->json([
            'status'     => $statusKey,
            'date_from'  => $dateFrom,
            'date_to'    => $dateTo,
            'count'      => $orders->count(),
            'orders'     => $orders->map(fn ($order) => [
                'id'                    => $order->id,
                'order_nbr'             => $order->acumatica_order_nbr,
                'customer_acumatica_id' => $order->customer_acumatica_id,
                'customer_name'         => $this->catalogResolver->resolveCustomerName(
                    $order->customer_name,
                    $order->customer_acumatica_id,
                    $customerNames,
                ),
                'amount'        => round((float) $order->order_total, 2),
                'currency_id'   => $order->currency_id,
                'quantity'      => round((float) ($order->total_qty ?? 0), 4),
                'order_date'    => $order->order_date?->toDateString(),
                'status'        => $order->status,
            ])->values(),
        ]);
    }

    /** Daily trend data for the chart, optionally with previous-period comparison. */
    public function trend(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to',   now()->toDateString());
        $compare  = $request->boolean('compare', false);

        $current = $this->dailyTrend($dateFrom, $dateTo, null, $request->user());

        $previous = null;
        if ($compare) {
            $days     = Carbon::parse($dateFrom)->diffInDays(Carbon::parse($dateTo)) + 1;
            $prevFrom = Carbon::parse($dateFrom)->subDays($days)->toDateString();
            $prevTo   = Carbon::parse($dateTo)->subDays($days)->toDateString();
            $previous = $this->dailyTrend($prevFrom, $prevTo, $prevFrom, $request->user());
        }

        return response()->json([
            'current'  => $current,
            'previous' => $previous,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function statusCounts(
        string $dateFrom,
        string $dateTo,
        ?User $user,
        bool $excludeSpecialCustomers = true,
        bool $onlyGoodsLostInTransit = false,
    ): array {
        $base = DataScope::applyOrderScope(
            AcumaticaSalesOrder::salesOrdersOnly(),
            $user,
        )
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo);

        if ($onlyGoodsLostInTransit) {
            $base->where('customer_acumatica_id', $this->goodsLostInTransitCustomerId());
        } elseif ($excludeSpecialCustomers) {
            $base = $this->excludeSpecialCustomers($base);
        }

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

    /** @return list<string> */
    private function excludedCustomerIds(): array
    {
        $ids = config('dashboard.excluded_customer_ids', ['CUST102641']);

        return array_values(array_filter(array_map(
            static fn ($id) => strtoupper(trim((string) $id)),
            is_array($ids) ? $ids : [],
        )));
    }

    private function goodsLostInTransitCustomerId(): string
    {
        return strtoupper(trim((string) config(
            'dashboard.goods_lost_in_transit.customer_id',
            'CUST102641',
        )));
    }

    private function goodsLostInTransitLabel(): string
    {
        return (string) config(
            'dashboard.goods_lost_in_transit.label',
            'Goods Lost in Transit',
        );
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<\App\Models\AcumaticaSalesOrder>  $query
     * @return \Illuminate\Database\Eloquent\Builder<\App\Models\AcumaticaSalesOrder>
     */
    private function excludeSpecialCustomers($query)
    {
        $ids = $this->excludedCustomerIds();
        if ($ids !== []) {
            $query->where(function ($q) use ($ids) {
                $q->whereNull('customer_acumatica_id')
                    ->orWhereNotIn('customer_acumatica_id', $ids);
            });
        }

        return $query;
    }

    /**
     * Group orders by day, returning a flat array where each entry is:
     * { day, label, total, completed, shipping, pending_approval, rejected, on_hold }
     *
     * $labelOffset shifts the "label" day for comparison rows so the chart
     * can align current vs previous on the same x-axis position.
     */
    private function dailyTrend(string $dateFrom, string $dateTo, ?string $labelOffset = null, ?User $user = null): array
    {
        $rows = $this->excludeSpecialCustomers(
            DataScope::applyOrderScope(
                AcumaticaSalesOrder::query()->salesOrdersOnly(),
                $user,
            ),
        )
            ->selectRaw('DATE(order_date) as day, status, COUNT(*) as cnt')
            ->whereDate('order_date', '>=', $dateFrom)
            ->whereDate('order_date', '<=', $dateTo)
            ->groupByRaw('DATE(order_date), status')
            ->orderByRaw('DATE(order_date)')
            ->get();

        $fillRatesByDay = $this->fillRateQtyByDay($dateFrom, $dateTo, $user);

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
    private function fillRateQtyByDay(string $dateFrom, string $dateTo, ?User $user = null): array
    {
        $query = DB::table('acumatica_fill_rate_snapshots as f')
            ->join('acumatica_sales_orders as o', 'f.sales_order_id', '=', 'o.id')
            ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->whereDate('o.order_date', '>=', $dateFrom)
            ->whereDate('o.order_date', '<=', $dateTo)
            ->where('f.fill_rate_status', '!=', 'na');

        $excluded = $this->excludedCustomerIds();
        if ($excluded !== []) {
            $query->where(function ($q) use ($excluded) {
                $q->whereNull('o.customer_acumatica_id')
                    ->orWhereNotIn('o.customer_acumatica_id', $excluded);
            });
        }

        if (SalesConsultantScope::appliesTo($user)) {
            $repCode = SalesConsultantScope::repCode($user);
            if ($repCode === null) {
                return [];
            }
            $query->where('o.sales_consultant_rep_code', $repCode);
        }

        $rows = $query
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
