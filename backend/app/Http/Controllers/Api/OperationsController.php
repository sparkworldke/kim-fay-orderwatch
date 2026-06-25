<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Services\Admin\FillRateCalculator;
use App\Services\Admin\InventoryRunRatePredictor;
use App\Services\Operations\BusinessOptimizationService;
use App\Services\Operations\OperationsCatalogResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OperationsController extends Controller
{
    public function __construct(
        private readonly FillRateCalculator $fillRateCalculator,
        private readonly InventoryRunRatePredictor $predictor,
        private readonly OperationsCatalogResolver $catalogResolver,
        private readonly BusinessOptimizationService $optimization,
    ) {
    }

    public function opsStatus(): JsonResponse
    {
        return response()->json($this->optimization->opsStatus());
    }

    public function businessOptimization(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to', now()->toDateString());

        return response()->json($this->optimization->dashboard($dateFrom, $dateTo));
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
            $inventoryIds = AcumaticaInventoryItem::query()
                ->where('description', 'like', "%{$search}%")
                ->pluck('inventory_id');
            $customerIds = AcumaticaCustomer::query()
                ->where('name', 'like', "%{$search}%")
                ->pluck('acumatica_id');

            $query->where(function ($q) use ($search, $inventoryIds, $customerIds) {
                $q->where('order_nbr', 'like', "%{$search}%")
                    ->orWhere('inventory_id', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$search}%");

                if ($inventoryIds->isNotEmpty()) {
                    $q->orWhereIn('inventory_id', $inventoryIds);
                }
                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('customer_acumatica_id', $customerIds);
                }
            });
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('customer_acumatica_id', $customerId);
        }

        $paginated = $query->paginate($request->integer('per_page', 50));
        $items = $paginated->getCollection();

        $inventoryIds = $items->pluck('inventory_id')->all();
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($inventoryIds);
        $customerNames = $this->catalogResolver->namesForCustomerIds(
            $items->pluck('customer_acumatica_id')->all(),
        );

        $paginated->getCollection()->transform(function ($line) use ($inventoryDescriptions, $inventoryStock, $customerNames) {
            $line->product_name = $this->catalogResolver->resolveProductName(
                $line->inventory_id,
                null,
                $inventoryDescriptions,
            );
            $line->customer_name = $this->catalogResolver->resolveCustomerName(
                $line->customer_name,
                $line->customer_acumatica_id,
                $customerNames,
            );
            $line->uom = $this->catalogResolver->resolveUom(
                $line->uom,
                $line->inventory_id,
                $inventoryStock,
            );

            $stock = $inventoryStock->get($line->inventory_id);
            $line->qty_on_hand = $stock['qty_on_hand'] ?? null;
            $line->qty_available = $stock['qty_available'] ?? null;
            $line->stock_shortfall = $stock !== null
                && (float) ($stock['qty_on_hand'] ?? 0) < (float) $line->open_qty;

            return $line;
        });

        return response()->json($paginated);
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

        $customerNames = $this->catalogResolver->namesForCustomerIds(
            $rows->pluck('customer_acumatica_id')->all(),
        );

        $rows->transform(function ($row) use ($customerNames) {
            $row->customer_name = $this->catalogResolver->resolveCustomerName(
                $row->customer_name,
                $row->customer_acumatica_id,
                $customerNames,
            );

            return $row;
        });

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
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date',
                'order.lines:id,sales_order_id,inventory_id,description,order_qty,shipped_qty,open_qty,unit_price,uom,fill_rate_pct,qty_at_approval',
            ])
            ->orderByDesc('computed_at');

        if ($status = $request->input('status')) {
            $query->where('fill_rate_status', $status);
        }

        if ($search = $request->input('q')) {
            $inventoryIds = AcumaticaInventoryItem::query()
                ->where('description', 'like', "%{$search}%")
                ->pluck('inventory_id');
            $customerIds = AcumaticaCustomer::query()
                ->where('name', 'like', "%{$search}%")
                ->pluck('acumatica_id');

            $query->where(function ($q) use ($search, $inventoryIds, $customerIds) {
                $q->where('order_nbr', 'like', "%{$search}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$search}%");

                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('customer_acumatica_id', $customerIds);
                }

                $q->orWhereHas('order', function ($oq) use ($search, $customerIds) {
                    $oq->where('customer_name', 'like', "%{$search}%");
                    if ($customerIds->isNotEmpty()) {
                        $oq->orWhereIn('customer_acumatica_id', $customerIds);
                    }
                });

                $q->orWhereHas('order.lines', function ($lq) use ($search, $inventoryIds) {
                    $lq->where('inventory_id', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                    if ($inventoryIds->isNotEmpty()) {
                        $lq->orWhereIn('inventory_id', $inventoryIds);
                    }
                });
            });
        }

        $paginated = $query->paginate($request->integer('per_page', 50));
        $items = $paginated->getCollection();

        $inventoryIds = $items
            ->flatMap(fn ($snapshot) => $snapshot->order?->lines?->pluck('inventory_id') ?? collect())
            ->all();
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($inventoryIds);

        $customerIds = $items
            ->map(fn ($snapshot) => $snapshot->customer_acumatica_id ?? $snapshot->order?->customer_acumatica_id)
            ->all();
        $customerNames = $this->catalogResolver->namesForCustomerIds($customerIds);

        $paginated->getCollection()->transform(function ($snapshot) use ($inventoryDescriptions, $inventoryStock, $customerNames) {
            $order = $snapshot->order;
            $storedCustomerName = $order?->customer_name;

            $snapshot->customer_name = $this->catalogResolver->resolveCustomerName(
                $storedCustomerName,
                $snapshot->customer_acumatica_id ?? $order?->customer_acumatica_id,
                $customerNames,
            );

            $snapshot->products = collect($order?->lines ?? [])
                ->map(function ($line) use ($inventoryDescriptions, $inventoryStock) {
                    $openQty = (float) $line->open_qty;
                    if ($openQty <= 0) {
                        $openQty = max((float) $line->order_qty - (float) $line->shipped_qty, 0);
                    }
                    $unitPrice = (float) $line->unit_price;

                    return [
                        'inventory_id'       => $line->inventory_id,
                        'product_name'       => $this->catalogResolver->resolveProductName(
                            $line->inventory_id,
                            $line->description,
                            $inventoryDescriptions,
                        ),
                        'order_qty'          => $line->order_qty,
                        'shipped_qty'        => $line->shipped_qty,
                        'open_qty'           => number_format($openQty, 4, '.', ''),
                        'uom'                => $this->catalogResolver->resolveUom(
                            $line->uom,
                            $line->inventory_id,
                            $inventoryStock,
                        ),
                        'unit_price'         => $line->unit_price,
                        'line_fill_rate_pct' => $line->fill_rate_pct,
                        'not_shipped_value'  => $unitPrice > 0
                            ? number_format(round($openQty * $unitPrice, 2), 2, '.', '')
                            : '0.00',
                    ];
                })
                ->values()
                ->all();

            return $snapshot;
        });

        return response()->json($paginated);
    }
}