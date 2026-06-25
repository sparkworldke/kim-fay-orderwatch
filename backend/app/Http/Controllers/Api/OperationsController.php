<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Services\Admin\FillRateCalculator;
use App\Services\Admin\InventoryRunRatePredictor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function __construct(
        private readonly FillRateCalculator $fillRateCalculator,
        private readonly InventoryRunRatePredictor $predictor,
    ) {
    }

    public function inventorySummary(): JsonResponse
    {
        $totalItems = AcumaticaInventoryItem::count();
        $lowStock   = AcumaticaInventoryItem::where('qty_on_hand', '<=', 10)->count();
        $critical   = AcumaticaInventoryRunRateLog::whereIn('prediction_status', ['critical', 'at_risk'])
            ->where('logged_at', '>=', now()->subDay())
            ->distinct('inventory_item_id')
            ->count('inventory_item_id');

        return response()->json([
            'total_items'      => $totalItems,
            'low_stock_count'  => $lowStock,
            'at_risk_count'    => $critical,
            'last_synced_at'   => AcumaticaInventoryItem::max('synced_at'),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $query = AcumaticaInventoryItem::query()->orderByDesc('synced_at');

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('inventory_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->where('qty_on_hand', '<=', 10);
        }

        if ($status = $request->input('prediction_status')) {
            $ids = AcumaticaInventoryRunRateLog::where('prediction_status', $status)
                ->where('logged_at', '>=', now()->subDays(2))
                ->pluck('inventory_item_id');
            $query->whereIn('id', $ids);
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        $itemIds = collect($paginated->items())->pluck('id');
        $latestLogs = AcumaticaInventoryRunRateLog::whereIn('inventory_item_id', $itemIds)
            ->whereIn('id', function ($sub) use ($itemIds) {
                $sub->selectRaw('MAX(id)')
                    ->from('acumatica_inventory_run_rate_logs')
                    ->whereIn('inventory_item_id', $itemIds)
                    ->groupBy('inventory_item_id');
            })
            ->get()
            ->keyBy('inventory_item_id');

        $paginated->getCollection()->transform(function ($item) use ($latestLogs) {
            $log = $latestLogs->get($item->id);
            $item->prediction = $log ? [
                'daily_run_rate'      => $log->daily_run_rate,
                'days_until_stockout' => $log->days_until_stockout,
                'prediction_status'   => $log->prediction_status,
                'qty_delta'           => $log->qty_delta,
                'logged_at'           => $log->logged_at,
            ] : null;

            return $item;
        });

        return response()->json($paginated);
    }

    public function inventoryPrediction(int $id): JsonResponse
    {
        $item = AcumaticaInventoryItem::findOrFail($id);

        $logs = AcumaticaInventoryRunRateLog::where('inventory_item_id', $item->id)
            ->orderByDesc('logged_at')
            ->limit(30)
            ->get(['qty_on_hand', 'qty_delta', 'daily_run_rate', 'days_until_stockout', 'prediction_status', 'logged_at']);

        $prediction = $this->predictor->predict($item, (float) $item->qty_on_hand);

        return response()->json([
            'item'       => $item,
            'prediction' => $prediction,
            'history'    => $logs,
        ]);
    }

    public function backordersSummary(): JsonResponse
    {
        $lines = AcumaticaBackorderLine::query();

        return response()->json([
            'open_lines'        => (clone $lines)->count(),
            'open_orders'       => (clone $lines)->distinct('order_nbr')->count('order_nbr'),
            'revenue_at_risk'   => round((float) (clone $lines)->sum('revenue_at_risk'), 2),
            'total_open_qty'    => round((float) (clone $lines)->sum('open_qty'), 4),
            'last_synced_at'    => AcumaticaBackorderLine::max('synced_at'),
        ]);
    }

    public function backorders(Request $request): JsonResponse
    {
        $query = AcumaticaBackorderLine::query()->orderByDesc('revenue_at_risk');

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_nbr', 'like', "%{$search}%")
                    ->orWhere('inventory_id', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$search}%");
            });
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('customer_acumatica_id', $customerId);
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function backordersByAccount(Request $request): JsonResponse
    {
        $topN = min(20, $request->integer('top', 10));

        $rows = AcumaticaBackorderLine::query()
            ->select([
                'customer_acumatica_id',
                'customer_name',
                DB::raw('COUNT(DISTINCT order_nbr) as order_count'),
                DB::raw('COUNT(*) as open_lines'),
                DB::raw('SUM(revenue_at_risk) as revenue_at_risk'),
                DB::raw('SUM(open_qty) as total_open_qty'),
            ])
            ->groupBy('customer_acumatica_id', 'customer_name')
            ->orderByDesc('revenue_at_risk')
            ->limit($topN)
            ->get();

        return response()->json(['accounts' => $rows]);
    }

    public function fillRateSummary(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to', now()->toDateString());

        $snapshots = AcumaticaFillRateSnapshot::query()
            ->whereBetween('computed_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->get();

        $eligible = $snapshots->where('fill_rate_status', '!=', 'na');
        $totalOrdered = $eligible->sum('total_ordered_qty');
        $totalShipped = $eligible->sum('total_shipped_qty');

        $overallPct = $totalOrdered > 0
            ? round(($totalShipped / $totalOrdered) * 1000) / 10
            : null;

        return response()->json([
            'date_from'            => $dateFrom,
            'date_to'              => $dateTo,
            'overall_fill_rate'    => $overallPct,
            'overall_status'       => $overallPct !== null ? $this->fillRateCalculator->thresholdStatus($overallPct) : 'na',
            'revenue_not_shipped'  => round((float) $eligible->sum('revenue_not_shipped'), 2),
            'order_count'          => $snapshots->count(),
            'healthy_count'        => $snapshots->where('fill_rate_status', 'healthy')->count(),
            'at_risk_count'        => $snapshots->where('fill_rate_status', 'at_risk')->count(),
            'critical_count'       => $snapshots->where('fill_rate_status', 'critical')->count(),
            'na_count'             => $snapshots->where('fill_rate_status', 'na')->count(),
            'last_computed_at'     => AcumaticaFillRateSnapshot::max('computed_at'),
        ]);
    }

    public function fillRate(Request $request): JsonResponse
    {
        $query = AcumaticaFillRateSnapshot::query()
            ->with('order:id,acumatica_order_nbr,customer_name,order_date')
            ->orderByDesc('computed_at');

        if ($status = $request->input('status')) {
            $query->where('fill_rate_status', $status);
        }

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('order_nbr', 'like', "%{$search}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$search}%");
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }
}