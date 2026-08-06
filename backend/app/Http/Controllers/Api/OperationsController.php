<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\BackorderResolution;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\InventoryWarehouseBalance;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaShippingZone;
use App\Services\Admin\FillRateCalculator;
use App\Services\Admin\InventoryRunRatePredictor;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use App\Services\Operations\BusinessOptimizationService;
use App\Services\Operations\DeliverySlaEvaluator;
use App\Services\Operations\FillRateBusinessCategory;
use App\Services\Operations\BackorderExcelExporter;
use App\Services\Operations\BackorderLineTransformer;
use App\Services\Operations\BackorderMetricsService;
use App\Services\Operations\FillRateExcelExporter;
use App\Services\Operations\FillRateReasonCaptureReport;
use App\Services\Operations\OperationsCatalogResolver;
use App\Services\Operations\SalesOrderReasonCatalog;
use App\Services\Operations\SalesOrderReasonTaxonomyService;
use App\Services\Operations\SoReasonAuditService;
use App\Services\Sales\SalesPortfolioService;
use App\Support\DataScope;
use App\Support\DepartmentScope;
use App\Support\SalesConsultantScope;
use App\Services\Team\BrandAssignmentScope;
use App\Services\Team\BrandFilterService;
use App\Services\Cache\DomainCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsController extends Controller
{
    private const EXPORT_LIMIT = 50000;
    private const FILL_RATE_INTERACTIVE_EXPORT_LIMIT = 8000;

    public function __construct(
        private readonly FillRateCalculator $fillRateCalculator,
        private readonly InventoryRunRatePredictor $predictor,
        private readonly OperationsCatalogResolver $catalogResolver,
        private readonly BusinessOptimizationService $optimization,
        private readonly DeliverySlaEvaluator $deliverySla,
        private readonly FillRateExcelExporter $fillRateExporter,
        private readonly BackorderExcelExporter $backorderExporter,
        private readonly BackorderLineTransformer $backorderLineTransformer,
        private readonly BackorderMetricsService $backorderMetrics,
        private readonly FillRateReasonCaptureReport $reasonCaptureReport,
        private readonly FillRateBusinessCategory $businessCategory,
        private readonly SalesPortfolioService $salesPortfolio,
        private readonly SoReasonAuditService $soReasonAudit,
        private readonly SalesOrderReasonCatalog $reasonCatalog,
        private readonly SalesOrderReasonTaxonomyService $reasonTaxonomy,
    ) {
    }

    public function reasonTaxonomy(): JsonResponse
    {
        return response()->json($this->reasonTaxonomy->taxonomy());
    }

    public function soReasonAudit(): JsonResponse
    {
        return response()->json($this->soReasonAudit->report());
    }

    public function opsStatus(): JsonResponse
    {
        return response()->json($this->optimization->opsStatus());
    }

    public function businessOptimization(Request $request): JsonResponse
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo   = $request->input('date_to', now()->toDateString());
        $shippingZoneId = $request->filled('shipping_zone_id')
            ? strtoupper(trim((string) $request->input('shipping_zone_id')))
            : null;
        $regionFilter = $request->filled('region')
            ? strtolower(trim((string) $request->input('region')))
            : null;

        $user = $request->user();
        $repCode = SalesConsultantScope::repCode($user);
        $portfolioIds = DataScope::scopedCustomerAcumaticaIds($user);
        $emptyScope = (SalesConsultantScope::appliesTo($user) && $repCode === null)
            || ($portfolioIds !== null && $portfolioIds === []);

        return response()->json($this->optimization->dashboard(
            $dateFrom,
            $dateTo,
            $repCode,
            $emptyScope,
            $shippingZoneId,
            $regionFilter,
            $portfolioIds,
        ));
    }

    public function inventorySummary(): JsonResponse
    {
        $totalItems = AcumaticaInventoryItem::count();
        $fgs = InventoryWarehouseBalance::query()->where('warehouse_id', 'FGS');
        $hasBalances = InventoryWarehouseBalance::query()->exists();
        $lowStock = $hasBalances
            ? (clone $fgs)->where('qty_on_hand', '<=', 10)->count()
            : AcumaticaInventoryItem::where('default_warehouse_id', 'FGS')->where('qty_on_hand', '<=', 10)->count();
        $critical   = AcumaticaInventoryRunRateLog::whereIn('prediction_status', ['critical', 'at_risk'])
            ->where('logged_at', '>=', now()->subDay())
            ->distinct('inventory_item_id')
            ->count('inventory_item_id');

        $outOfStockCount = $hasBalances
            ? (clone $fgs)->where('qty_on_hand', '<=', 0)->count()
            : AcumaticaInventoryItem::where('default_warehouse_id', 'FGS')->where('qty_on_hand', '<=', 0)->count();
        $criticalPredictionIds = $this->recentPredictionItemIds(['critical']);
        $criticalStockoutCount = InventoryWarehouseBalance::query()
            ->where('warehouse_id', 'FGS')
            ->where(function ($q) use ($criticalPredictionIds) {
                $q->where('qty_on_hand', '<=', 0);
                if ($criticalPredictionIds->isNotEmpty()) {
                    $q->orWhereIn('inventory_item_id', $criticalPredictionIds);
                }
            })
            ->count();

        $warehouseRows = InventoryWarehouseBalance::query()
            ->selectRaw('warehouse_id, COUNT(DISTINCT inventory_id) as sku_count, MAX(synced_at) as synced_at')
            ->groupBy('warehouse_id')
            ->orderBy('warehouse_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->warehouse_id => [
                    'sku_count' => (int) $row->sku_count,
                    'synced_at' => $row->synced_at,
                ],
            ]);
        if (! $hasBalances) {
            $warehouseRows = AcumaticaInventoryItem::query()
                ->whereNotNull('default_warehouse_id')
                ->selectRaw('default_warehouse_id as warehouse_id, COUNT(*) as sku_count, MAX(synced_at) as synced_at')
                ->groupBy('default_warehouse_id')
                ->get()
                ->mapWithKeys(fn ($row) => [
                    (string) $row->warehouse_id => [
                        'sku_count' => (int) $row->sku_count,
                        'synced_at' => $row->synced_at,
                    ],
                ]);
        }

        // Acumatica warehouse list from config, merged with any extra warehouses seen in synced data.
        $configuredWarehouses = collect(config('inventory.warehouses', []))
            ->map(fn ($id) => strtoupper(trim((string) $id)))
            ->filter()
            ->values();
        $labels = config('inventory.warehouse_labels', []);
        $allWarehouseIds = $configuredWarehouses
            ->merge($warehouseRows->keys())
            ->unique()
            ->values();

        $warehouseCounts = $allWarehouseIds->map(fn (string $id) => [
            'warehouse_id' => $id,
            'label'        => (string) ($labels[$id] ?? $id),
            'sku_count'    => (int) ($warehouseRows[$id]['sku_count'] ?? 0),
            'synced_at'    => $warehouseRows[$id]['synced_at'] ?? null,
            'configured'   => $configuredWarehouses->contains($id),
        ])->values();

        return response()->json([
            'total_items'      => $totalItems,
            'low_stock_count'  => $lowStock,
            'at_risk_count'    => $critical,
            'out_of_stock_count' => $outOfStockCount,
            'critical_stockout_count' => $criticalStockoutCount,
            'last_synced_at'   => InventoryWarehouseBalance::max('synced_at'),
            'warehouse_ids'    => $allWarehouseIds,
            'warehouse_counts' => $warehouseCounts,
            'warehouses'       => $warehouseCounts,
            'brands' => AcumaticaInventoryItem::query()
                ->whereNotNull('brand')
                ->distinct()
                ->orderBy('brand')
                ->pluck('brand')
                ->values(),
            'manufactured_count' => AcumaticaInventoryItem::where('product_type', 'manufactured')->count(),
            'trading_count'      => AcumaticaInventoryItem::where('product_type', 'trading')->count(),
        ]);
    }

    public function brandFilterOptions(BrandFilterService $brandFilters): JsonResponse
    {
        return response()->json([
            'hierarchy' => $brandFilters->hierarchyOptions(),
        ]);
    }

    public function inventory(Request $request): JsonResponse
    {
        $query = $this->inventoryFilteredQuery($request);

        // Stockout risk tab: show empties and near-stockouts first.
        if ($request->filled('stockout_filter')) {
            $query->orderBy('iwb.qty_on_hand')->orderBy('acumatica_inventory_items.inventory_id');
        } else {
            $query->orderByDesc('iwb.synced_at');
        }

        $paginated = $query->paginate($request->integer('per_page', 50));

        $itemIds = collect($paginated->items())->pluck('id');
        $latestLogs = $this->latestInventoryRunRateLogs($itemIds);

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

    public function exportInventory(Request $request): JsonResponse|StreamedResponse
    {
        $query = $this->inventoryFilteredQuery($request)->orderBy('inventory_id');
        $count = (clone $query)->count();
        if ($limitResponse = $this->exportLimitResponse($count)) {
            return $limitResponse;
        }

        $items = $query->get();
        $latestLogs = $this->latestInventoryRunRateLogs($items->pluck('id'));
        $spreadsheet = $this->newSpreadsheet('Inventory Export');

        $this->writeSheet($spreadsheet, 'Inventory', [
            'Item ID', 'Description', 'Brand', 'Product Type', 'Item Class', 'Warehouse', 'UOM',
            'Qty On Hand', 'Qty Available', 'Sales Price', 'Daily Run Rate', 'Days Until Stockout',
            'Qty Delta', 'Prediction Status', 'Prediction Logged At', 'Last Synced',
        ], $items->map(function (AcumaticaInventoryItem $item) use ($latestLogs) {
            $log = $latestLogs->get($item->id);

            return [
                $item->inventory_id,
                $item->description,
                $item->brand,
                $item->product_type,
                $item->item_class,
                $item->default_warehouse_id,
                $item->default_uom,
                (float) $item->qty_on_hand,
                $item->qty_available !== null ? (float) $item->qty_available : null,
                (float) $item->sales_price,
                $log?->daily_run_rate !== null ? (float) $log->daily_run_rate : null,
                $log?->days_until_stockout !== null ? (float) $log->days_until_stockout : null,
                $log?->qty_delta !== null ? (float) $log->qty_delta : null,
                $log?->prediction_status,
                $this->dateString($log?->logged_at),
                $this->dateString($item->synced_at),
            ];
        })->all());

        $this->writeSheet($spreadsheet, 'Risk Summary', [
            'Risk Bucket', 'Item Count',
        ], [
            ['Low Stock <= 10', $items->filter(fn ($item) => (float) $item->qty_on_hand <= 10)->count()],
            ['Critical', $latestLogs->where('prediction_status', 'critical')->count()],
            ['At Risk', $latestLogs->where('prediction_status', 'at_risk')->count()],
            ['Healthy', $latestLogs->where('prediction_status', 'healthy')->count()],
            ['Needs History', $latestLogs->where('prediction_status', 'insufficient_history')->count()],
            ['No Prediction', max(0, $items->count() - $latestLogs->count())],
        ]);

        $warehouseRows = $items
            ->groupBy(fn ($item) => $item->default_warehouse_id ?: 'Unassigned')
            ->map(fn ($group, $warehouse) => [
                $warehouse,
                $group->count(),
                round((float) $group->sum('qty_on_hand'), 4),
                round((float) $group->sum('qty_available'), 4),
            ])
            ->sortByDesc(fn ($row) => $row[2])
            ->values()
            ->all();
        $this->writeSheet($spreadsheet, 'Warehouse Summary', [
            'Warehouse', 'Item Count', 'Qty On Hand', 'Qty Available',
        ], $warehouseRows);

        return $this->downloadSpreadsheet($spreadsheet, 'inventory-export-'.now()->format('Ymd-Hi').'.xlsx');
    }

    public function backordersSummary(Request $request): JsonResponse
    {
        $lines = $this->backordersFilteredQuery($request);
        $activeLines = (clone $lines)->where('acumatica_backorder_lines.shortfall_kind', 'active_backorder');
        $completedLines = (clone $lines)->where('acumatica_backorder_lines.shortfall_kind', 'completed_shortfall');
        $completedOrderNbrs = (clone $completedLines)->distinct()->pluck('acumatica_backorder_lines.order_nbr');
        $completedSnapshots = \App\Models\AcumaticaFillRateSnapshot::query()->whereIn('order_nbr', $completedOrderNbrs);
        $completedOrdered = (float) (clone $completedSnapshots)->sum('total_ordered_qty');
        $completedDelivered = (float) (clone $completedSnapshots)->sum('total_shipped_qty');
        $history = \App\Models\FulfillmentHistorySnapshot::query();
        $scopedIds = \App\Support\DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($scopedIds !== null) $history->whereIn('customer_acumatica_id', $scopedIds);
        if ($request->filled('date_from')) $history->whereDate('order_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $history->whereDate('order_date', '<=', $request->input('date_to'));
        $completedOrders = \App\Models\AcumaticaSalesOrder::query()->whereRaw('LOWER(TRIM(status)) = ?', ['completed']);
        \App\Support\DataScope::applyOrderScope($completedOrders, $request->user());
        if ($request->filled('date_from')) $completedOrders->whereDate('order_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $completedOrders->whereDate('order_date', '<=', $request->input('date_to'));
        $unrecoverable = (clone $completedOrders)->whereNotIn('acumatica_order_nbr', (clone $history)->select('order_nbr'))->count();
        $metricRows = (clone $lines)
            ->select([
                'acumatica_backorder_lines.*',
                DB::raw('aso.status as sales_order_status'),
                DB::raw($this->backorderSoLineReasonSubquery().' as so_line_reason_code'),
            ])
            ->get();
        $canonical = $this->backorderMetrics->summarize(
            $this->backorderLineTransformer->transform($metricRows)
        );

        // SQL distinct counts are authoritative for KPI cards (not limited by list page size).
        $openSkus = (int) (clone $activeLines)
            ->whereNotNull('acumatica_backorder_lines.inventory_id')
            ->where('acumatica_backorder_lines.inventory_id', '!=', '')
            ->distinct()
            ->count('acumatica_backorder_lines.inventory_id');
        $openOrders = (int) (clone $activeLines)
            ->whereNotNull('acumatica_backorder_lines.order_nbr')
            ->distinct()
            ->count('acumatica_backorder_lines.order_nbr');
        $openCustomers = (int) (clone $activeLines)
            ->whereNotNull('acumatica_backorder_lines.customer_acumatica_id')
            ->distinct()
            ->count('acumatica_backorder_lines.customer_acumatica_id');
        $openLines = (int) (clone $activeLines)->count();

        return response()->json([
            'open_lines'        => $openLines,
            'open_orders'       => $openOrders,
            'open_customers'    => $openCustomers,
            'open_skus'         => $openSkus,
            'revenue_at_risk'   => round((float) (clone $activeLines)->sum('acumatica_backorder_lines.revenue_at_risk'), 2),
            'total_open_qty'    => round((float) (clone $activeLines)->sum('acumatica_backorder_lines.open_qty'), 4),
            'last_synced_at'    => AcumaticaBackorderLine::max('synced_at'),
            'historical_shortfall_amount' => round((float) (clone $history)->sum('historical_shortfall_amount'), 2),
            'current_outstanding_amount' => round((float) (clone $activeLines)->sum('acumatica_backorder_lines.revenue_at_risk'), 2),
            'completed' => [
                'shortfall_lines' => (clone $completedLines)->count(),
                'affected_orders' => $completedOrderNbrs->count(),
                'missed_qty' => round((float) (clone $completedLines)->sum('acumatica_backorder_lines.open_qty'), 4),
                'missed_value' => round((float) (clone $completedLines)->sum('acumatica_backorder_lines.revenue_at_risk'), 2),
                'fill_rate_pct' => $completedOrdered > 0 ? round($completedDelivered / $completedOrdered * 100, 2) : null,
            ],
            'historical_snapshot_count' => (clone $history)->count(),
            'historical_last_observed_at' => (clone $history)->max('observed_at'),
            'unrecoverable_order_count' => $unrecoverable,
            'value_summary' => $this->backordersValueSummary($request),
            ...$canonical,
            // Keep SQL counts after $canonical so list-page-sized metrics cannot overwrite them.
            'open_lines' => $openLines,
            'open_orders' => $openOrders,
            'open_skus' => $openSkus,
            'open_episodes' => $openLines,
        ]);
    }

    /**
     * Order / Invoiced / Backorder value cards + Manufactured/Trading and KP/CS splits.
     *
     * Uses the **same filtered active backorder lines** as the table and Excel export
     * (date, reason, warehouse, segments, etc.) so dashboard ≈ download totals.
     *
     * Per line (canonical, matches Acumatica OpenQty / sync revenue_at_risk):
     *   order_value     = order_qty × unit_price
     *   invoiced_value  = min(delivered_qty, net_order_qty) × unit_price
     *   backorder_value = residual_open_qty × unit_price
     *
     * residual_open_qty prefers stored open_qty (already net of shipments). Do not
     * subtract qty_on_shipments again from that residual.
     *
     * @return array<string, mixed>
     */
    private function backordersValueSummary(Request $request): array
    {
        // Intentionally ignore product_segment / segment when building the
        // *breakdown* cards so Manufactured/Trading (and KP/CS) always show
        // full split amounts; only the selected filter applies to $totals.
        $baseRequest = clone $request;
        $baseRequest->query->remove('product_segment');
        $baseRequest->request->remove('product_segment');
        $baseRequest->query->remove('segment');
        $baseRequest->request->remove('segment');

        // backordersFilteredQuery already left-joins inventory as `ai`.
        $query = $this->backordersFilteredQuery($baseRequest);
        // Default cards to live open shortfalls (not completed historical shortfalls).
        if (! $request->filled('shortfall_kind') && ! $baseRequest->filled('shortfall_kind')) {
            $query->where(function ($q) {
                $q->where('acumatica_backorder_lines.shortfall_kind', 'active_backorder')
                    ->orWhereNull('acumatica_backorder_lines.shortfall_kind');
            });
        }

        $rows = $query->get([
            'acumatica_backorder_lines.inventory_id',
            'acumatica_backorder_lines.customer_acumatica_id',
            'acumatica_backorder_lines.order_qty',
            'acumatica_backorder_lines.shipped_qty',
            'acumatica_backorder_lines.cancelled_qty',
            'acumatica_backorder_lines.qty_on_shipments',
            'acumatica_backorder_lines.open_qty',
            'acumatica_backorder_lines.unit_price',
            'acumatica_backorder_lines.revenue_at_risk',
            DB::raw('ai.product_type as product_type'),
        ]);

        $customerClasses = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $rows->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');

        $selectedProductSegment = $request->input('product_segment');
        $selectedCustomerSegment = $request->input('segment');

        $zero = fn (): array => ['order_value' => 0.0, 'invoiced_value' => 0.0, 'backorder_value' => 0.0];
        $totals = $zero();
        $byProduct = [
            FillRateBusinessCategory::MANUFACTURED => $zero(),
            FillRateBusinessCategory::TRADING => $zero(),
        ];
        $byCustomer = [
            FillRateCalculator::SEGMENT_KP => $zero(),
            FillRateCalculator::SEGMENT_CS => $zero(),
        ];

        foreach ($rows as $row) {
            $orderQty = (float) ($row->order_qty ?? 0);
            $shippedQty = (float) ($row->shipped_qty ?? 0);
            $cancelledQty = max(0, (float) ($row->cancelled_qty ?? 0));
            $qtyOnShipments = max(0, (float) ($row->qty_on_shipments ?? 0));
            $storedOpenQty = max(0, (float) ($row->open_qty ?? 0));
            $unitPrice = max(0, (float) ($row->unit_price ?? 0));
            $netOrderQty = max(0, $orderQty - $cancelledQty);
            $deliveredQty = SalesOrderLineFulfillmentDeriver::deliveredQty($shippedQty, $qtyOnShipments);
            $cappedDelivered = min($deliveredQty, $netOrderQty);
            $openQty = SalesOrderLineFulfillmentDeriver::residualOpenQty(
                $orderQty,
                $shippedQty,
                $qtyOnShipments,
                $cancelledQty,
                $storedOpenQty > 0 ? $storedOpenQty : null,
            );

            $orderValue = $orderQty * $unitPrice;
            $invoicedValue = $cappedDelivered * $unitPrice;
            $backorderValue = SalesOrderLineFulfillmentDeriver::openLineValue($openQty, $unitPrice);

            $productSegment = $this->businessCategory->classify(
                $row->inventory_id ?? null,
                $row->product_type ?? null,
            );
            $customerSegment = $this->fillRateCalculator->segmentForCustomerClass(
                $customerClasses->get($row->customer_acumatica_id),
            );

            $matchesProduct = ! $selectedProductSegment || $productSegment === $selectedProductSegment;
            $matchesCustomer = ! $selectedCustomerSegment || $customerSegment === $selectedCustomerSegment;

            $bucket = [
                'order_value' => $orderValue,
                'invoiced_value' => $invoicedValue,
                'backorder_value' => $backorderValue,
            ];

            foreach ($bucket as $key => $value) {
                if ($matchesProduct && $matchesCustomer) {
                    $totals[$key] += $value;
                }
                // Breakdown cards always show full split (ignore selected segment).
                $byProduct[$productSegment][$key] += $value;
                $byCustomer[$customerSegment][$key] += $value;
            }
        }

        $round = fn (array $values): array => array_map(fn ($v) => round($v, 2), $values);

        return [
            ...$round($totals),
            'by_product_segment' => array_map($round, $byProduct),
            'by_customer_segment' => array_map($round, $byCustomer),
        ];
    }

    public function backorders(Request $request): JsonResponse
    {
        $query = $this->backordersFilteredQuery($request)
            ->orderByDesc('acumatica_backorder_lines.revenue_at_risk')
            ->select([
                'acumatica_backorder_lines.*',
                DB::raw('ai.item_class as product_line'),
                DB::raw($this->backorderLeadTimeDaysExpression().' as lead_time_days'),
                DB::raw('aso.order_date as order_date'),
                DB::raw('aso.status as sales_order_status'),
                DB::raw($this->backorderSoLineReasonSubquery().' as so_line_reason_code'),
            ]);
        $paginated = $query->paginate($request->integer('per_page', 50));
        $items = $paginated->getCollection();

        $inventoryIds = $items->pluck('inventory_id')->all();
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $inventoryClassifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($inventoryIds);
        $customerNames = $this->catalogResolver->namesForCustomerIds(
            $items->pluck('customer_acumatica_id')->all(),
        );

        $paginated->getCollection()->transform(function ($line) use ($inventoryDescriptions, $inventoryClassifications, $inventoryStock, $customerNames) {
            $line->product_name = $this->catalogResolver->resolveProductName(
                $line->inventory_id,
                null,
                $inventoryDescriptions,
            );
            foreach ($this->catalogResolver->classificationFieldsFor($line->inventory_id, $inventoryClassifications) as $field => $value) {
                $line->{$field} = $value;
            }
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
            $line->order_date = $this->dateString($line->order_date ?? null);

            // Prefer stored line status; fall back to joined sales order status (shipping, completed, …).
            $storedStatus = is_string($line->order_status ?? null) ? trim((string) $line->order_status) : '';
            $soStatus = is_string($line->sales_order_status ?? null) ? trim((string) $line->sales_order_status) : '';
            $line->order_status = $storedStatus !== '' ? $storedStatus : ($soStatus !== '' ? $soStatus : null);
            unset($line->sales_order_status);

            // Reasons come from Acumatica SO line (unfilled_reason_code), not manual UI edits.
            $line->reason_code = $this->effectiveBackorderReasonCode(
                $line->reason_code ?? null,
                $line->so_line_reason_code ?? null,
            );
            unset($line->so_line_reason_code);

            $stock = $inventoryStock->get($line->inventory_id);
            $line->qty_on_hand = $stock['qty_on_hand'] ?? null;
            $line->qty_available = $stock['qty_available'] ?? null;
            $line->stock_shortfall = $stock !== null
                && (float) ($stock['qty_on_hand'] ?? 0) < (float) $line->open_qty;

            return $line;
        });

        // Shared final normalization adds aging/exception fields and keeps line consumers aligned.
        $this->backorderLineTransformer->transform($paginated->getCollection());

        return response()->json($paginated);
    }

    public function backordersReconciliation(Request $request): JsonResponse|StreamedResponse
    {
        $query = \App\Models\FulfillmentHistoryLine::query()
            ->join('fulfillment_history_snapshots as s', 's.id', '=', 'fulfillment_history_lines.snapshot_id')
            ->select(['s.order_nbr','s.order_date','s.customer_acumatica_id','s.observed_at','s.source','fulfillment_history_lines.inventory_id','fulfillment_history_lines.order_qty','fulfillment_history_lines.delivered_qty','fulfillment_history_lines.cancelled_qty','fulfillment_history_lines.open_qty','fulfillment_history_lines.unit_price','fulfillment_history_lines.shortfall_amount']);
        $ids = \App\Support\DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($ids !== null) $query->whereIn('s.customer_acumatica_id', $ids);
        if ($request->filled('date_from')) $query->whereDate('s.order_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('s.order_date', '<=', $request->input('date_to'));
        $rows = $query->orderBy('s.order_date')->orderBy('s.order_nbr')->get();
        if ($request->input('format') !== 'csv') return response()->json(['data'=>$rows,'total_shortfall'=>round((float)$rows->sum('shortfall_amount'),2),'row_count'=>$rows->count()]);
        return response()->streamDownload(function()use($rows){$out=fopen('php://output','w');fputcsv($out,['SO','Order Date','Customer','Observed At','Source','SKU','Ordered','Delivered','Cancelled','Missing','Unit Price','Shortfall']);foreach($rows as $r)fputcsv($out,[$r->order_nbr,$r->order_date,$r->customer_acumatica_id,$r->observed_at,$r->source,$r->inventory_id,$r->order_qty,$r->delivered_qty,$r->cancelled_qty,$r->open_qty,$r->unit_price,$r->shortfall_amount]);fclose($out);},'backorder-reconciliation.csv',['Content-Type'=>'text/csv']);
    }

    public function exportBackorders(Request $request): JsonResponse|StreamedResponse
    {
        // Export builds a multi-sheet XLSX in-process. Without raising limits,
        // large date ranges hit nginx/Cloudflare 504 before the stream starts.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '1024M');

        $query = $this->backordersFilteredQuery($request)
            ->orderByDesc('acumatica_backorder_lines.revenue_at_risk')
            ->select([
                'acumatica_backorder_lines.*',
                DB::raw('ai.item_class as product_line'),
                DB::raw($this->backorderLeadTimeDaysExpression().' as lead_time_days'),
                DB::raw('aso.status as sales_order_status'),
                DB::raw($this->backorderSoLineReasonSubquery().' as so_line_reason_code'),
            ]);

        $count = (clone $query)->count();
        if ($limitResponse = $this->exportLimitResponse($count)) {
            return $limitResponse;
        }

        $lines = $query->get();
        $this->backorderLineTransformer->transform($lines);
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($lines->pluck('inventory_id')->all());
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($lines->pluck('inventory_id')->all());
        $customerNames = $this->catalogResolver->namesForCustomerIds($lines->pluck('customer_acumatica_id')->all());

        // Line layout for BackorderExcelExporter (see exporter class docblock).
        $lineRows = $lines->map(function ($line) use ($inventoryDescriptions, $inventoryStock, $customerNames) {
            $productName = $this->catalogResolver->resolveProductName($line->inventory_id, null, $inventoryDescriptions);
            $customerName = $this->catalogResolver->resolveCustomerName($line->customer_name, $line->customer_acumatica_id, $customerNames);
            $uom = $this->catalogResolver->resolveUom($line->uom, $line->inventory_id, $inventoryStock);
            $storedStatus = is_string($line->order_status ?? null) ? trim((string) $line->order_status) : '';
            $soStatus = is_string($line->sales_order_status ?? null) ? trim((string) $line->sales_order_status) : '';
            $orderStatus = $storedStatus !== '' ? $storedStatus : ($soStatus !== '' ? $soStatus : null);
            $openQty = (float) $line->open_qty;
            $unitPrice = (float) ($line->unit_price ?? 0);
            $revenueAtRisk = (float) ($line->revenue_at_risk ?? 0);
            if ($revenueAtRisk <= 0 && $openQty > 0 && $unitPrice > 0) {
                $revenueAtRisk = SalesOrderLineFulfillmentDeriver::openLineValue($openQty, $unitPrice);
            }
            $reasonCode = $this->effectiveBackorderReasonCode(
                $line->reason_code ?? null,
                $line->so_line_reason_code ?? null,
            );

            return [
                $line->order_nbr,
                $line->customer_acumatica_id,
                $customerName,
                $line->inventory_id,
                $productName,
                $line->product_line,
                $line->warehouse_id,
                $line->shortfall_kind,
                $orderStatus,
                (float) $line->order_qty,
                (float) $line->shipped_qty,
                $openQty,
                $unitPrice,
                $revenueAtRisk,
                $reasonCode ?: 'unassigned',
                $this->reasonDisplay($reasonCode),
                $line->reason_notes,
                $uom,
                $line->currency_id,
                $this->dateString($line->synced_at),
                // 20+ — populated by BackorderLineTransformer::transform() above, not re-derived here.
                $line->brand,
                $line->fulfillment_status,
                $line->qty_on_hand,
                $line->qty_available,
                $this->dateString($line->first_backordered_at),
                $line->backorder_age_days,
                $line->aging_bucket,
                $line->missing_reason_exception ? 'Yes' : 'No',
            ];
        })->all();

        $reasonRows = collect($this->backordersReasonSummary($request))
            ->map(function (array $row) {
                $code = (string) ($row['reason'] ?? 'Unassigned');
                $row['reason'] = $code === 'Unassigned' || $code === 'unassigned'
                    ? 'Unassigned'
                    : $this->reasonDisplay($code);

                return $row;
            })
            ->all();

        $dateFrom = (string) ($request->input('date_from') ?: now()->startOfMonth()->toDateString());
        $dateTo = (string) ($request->input('date_to') ?: now()->toDateString());

        return $this->backorderExporter->build(
            lineRows: $lineRows,
            reasonRows: $reasonRows,
            customerRows: $this->backordersCustomerDistribution($request),
            productSummaryRows: $this->backordersProductDistribution($request),
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            businessCategoryRows: $this->backordersBusinessCategorySummary($request),
            valueSummary: $this->backordersValueSummary($request),
            resolvedRows: $this->resolvedBackordersExportRows($request),
        );
    }

    /**
     * Resolved-backorder rows for the export — filtered on resolved_at by the request's
     * date_from/date_to, same as the live "Resolved" view. Independent of the active-line
     * date filter above (which reads the order/sync timeline), by design.
     *
     * @return array<int, array<int, mixed>>
     */
    private function resolvedBackordersExportRows(Request $request): array
    {
        $rows = $this->backorderResolutionsFilteredQuery($request)
            ->orderByDesc('resolved_at')
            ->limit(self::EXPORT_LIMIT)
            ->get();

        $inventoryIds = $rows->pluck('inventory_id')->all();
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $classifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);
        $customerNames = $this->catalogResolver->namesForCustomerIds($rows->pluck('customer_acumatica_id')->all());

        return $rows->map(function (BackorderResolution $row) use ($descriptions, $classifications, $customerNames) {
            $productName = $this->catalogResolver->resolveProductName($row->inventory_id, null, $descriptions);
            $brand = $this->catalogResolver->classificationFieldsFor($row->inventory_id, $classifications)['brand'] ?? null;
            $customerName = $this->catalogResolver->resolveCustomerName($row->customer_name, $row->customer_acumatica_id, $customerNames);

            return [
                $row->order_nbr,
                $row->customer_acumatica_id,
                $customerName,
                $row->inventory_id,
                $productName,
                $brand,
                $this->reasonDisplay($row->reason_code),
                (float) $row->unit_price,
                (float) $row->revenue_at_risk,
                $this->dateString($row->first_backordered_at),
                $this->dateString($row->resolved_at),
                $row->days_to_resolve,
            ];
        })->all();
    }

    public function backordersAnalytics(Request $request): JsonResponse
    {
        $dateExpr = $this->backorderTimelineDateExpression();

        $trend = $this->backordersFilteredQuery($request)
            ->select([
                DB::raw($dateExpr.' as bucket_date'),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('COUNT(DISTINCT acumatica_backorder_lines.order_nbr) as order_count'),
                DB::raw('SUM(acumatica_backorder_lines.open_qty) as open_qty'),
                DB::raw('SUM(acumatica_backorder_lines.revenue_at_risk) as revenue_at_risk'),
            ])
            ->whereRaw($dateExpr.' is not null')
            ->groupBy('bucket_date')
            ->orderBy('bucket_date')
            ->get();

        $leadTimeExpr = $this->backorderLeadTimeDaysExpression();
        $leadTimeBucketExpr = $this->backorderLeadTimeBucketExpression($leadTimeExpr);

        $leadTimeCorrelation = $this->backordersFilteredQuery($request)
            ->select([
                DB::raw($leadTimeBucketExpr.' as lead_time_bucket'),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('AVG('.$leadTimeExpr.') as avg_lead_time_days'),
                DB::raw('SUM(acumatica_backorder_lines.revenue_at_risk) as revenue_at_risk'),
                DB::raw('SUM(acumatica_backorder_lines.open_qty) as open_qty'),
            ])
            ->whereRaw($leadTimeExpr.' is not null')
            ->groupBy('lead_time_bucket')
            ->get();

        $leadTimeCorrelation = $leadTimeCorrelation
            ->sortBy(fn ($row) => match ($row->lead_time_bucket) {
                '0-2 days' => 0,
                '3-5 days' => 1,
                '6-10 days' => 2,
                '11-15 days' => 3,
                default => 4,
            })
            ->values();

        $categoryDistribution = $this->backordersFilteredQuery($request)
            ->select([
                DB::raw("COALESCE(ai.item_class, 'Unclassified') as product_line"),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('SUM(acumatica_backorder_lines.revenue_at_risk) as revenue_at_risk'),
            ])
            ->groupBy('product_line')
            ->orderByDesc('revenue_at_risk')
            ->limit(8)
            ->get();

        // ONLY_FULL_GROUP_BY (MariaDB/MySQL): grouping by a correlated subquery expression
        // fails with "reason_code isn't in GROUP BY". Wrap as a derived table first, then
        // aggregate on the plain alias.
        $effectiveReasonExpr = 'COALESCE(NULLIF(TRIM(acumatica_backorder_lines.reason_code), \'\'), ('.$this->backorderSoLineReasonSubquery().'), \'unassigned\')';
        $reasonRows = $this->backordersFilteredQuery($request)
            ->select([
                DB::raw("{$effectiveReasonExpr} as reason_code"),
                'acumatica_backorder_lines.revenue_at_risk',
            ]);
        $reasonDistribution = DB::query()
            ->fromSub($reasonRows->toBase(), 'bo_reason_rows')
            ->selectRaw('reason_code, COUNT(*) as line_count, COALESCE(SUM(revenue_at_risk), 0) as revenue_at_risk')
            ->groupBy('reason_code')
            ->orderByDesc('line_count')
            ->get();

        $filtered = $this->backordersFilteredQuery($request);
        // Open KPIs default to active shortfalls so "Open lines / Current outstanding"
        // match the Backorder value card (not completed historical shortfalls).
        $openFiltered = clone $filtered;
        if (! $request->filled('shortfall_kind')) {
            $openFiltered->where(function ($q) {
                $q->where('acumatica_backorder_lines.shortfall_kind', 'active_backorder')
                    ->orWhereNull('acumatica_backorder_lines.shortfall_kind');
            });
        }
        $historical = \App\Models\FulfillmentHistorySnapshot::query();
        $scopedHistoryIds = \App\Support\DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($scopedHistoryIds !== null) $historical->whereIn('customer_acumatica_id', $scopedHistoryIds);
        if ($request->filled('date_from')) $historical->whereDate('order_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $historical->whereDate('order_date', '<=', $request->input('date_to'));

        $openLines = (int) (clone $openFiltered)->count();
        $openOrders = (int) (clone $openFiltered)
            ->whereNotNull('acumatica_backorder_lines.order_nbr')
            ->distinct()
            ->count('acumatica_backorder_lines.order_nbr');
        $openSkus = (int) (clone $openFiltered)
            ->whereNotNull('acumatica_backorder_lines.inventory_id')
            ->where('acumatica_backorder_lines.inventory_id', '!=', '')
            ->distinct()
            ->count('acumatica_backorder_lines.inventory_id');

        return response()->json([
            'summary' => [
                'open_lines' => $openLines,
                'open_orders' => $openOrders,
                'open_skus' => $openSkus,
                'revenue_at_risk' => round((float) (clone $openFiltered)->sum('acumatica_backorder_lines.revenue_at_risk'), 2),
                'total_open_qty' => round((float) (clone $openFiltered)->sum('acumatica_backorder_lines.open_qty'), 4),
                'historical_shortfall_amount' => round((float) (clone $historical)->sum('historical_shortfall_amount'), 2),
                'current_outstanding_amount' => round((float) (clone $openFiltered)->sum('acumatica_backorder_lines.revenue_at_risk'), 2),
                'historical_snapshot_count' => (clone $historical)->count(),
            ],
            'excel_summary' => $this->backordersExcelSummary($request),
            'filters' => [
                'product_lines' => AcumaticaInventoryItem::query()
                    ->whereNotNull('item_class')
                    ->distinct()
                    ->orderBy('item_class')
                    ->pluck('item_class')
                    ->values(),
                'customer_groups' => AcumaticaCustomer::query()
                    ->whereNotNull('customer_class')
                    ->distinct()
                    ->orderBy('customer_class')
                    ->pluck('customer_class')
                    ->values(),
                'departments' => collect(['Unassigned'])->values(),
                'warehouse_ids' => AcumaticaBackorderLine::query()
                    ->whereNotNull('warehouse_id')
                    ->distinct()
                    ->orderBy('warehouse_id')
                    ->pluck('warehouse_id')
                    ->values(),
                'reason_codes' => collect(AcumaticaBackorderLine::REASON_CODES)->values(),
                'fulfillment_statuses' => collect($this->backorderFulfillmentStatuses())->values(),
            ],
            'charts' => [
                'trend' => $trend,
                'lead_time_correlation' => $leadTimeCorrelation,
                'category_distribution' => $categoryDistribution,
                'reason_distribution' => $reasonDistribution,
                'customer_group_distribution' => $this->backordersCustomerGroupDistribution($request),
                'department_distribution' => $this->unassignedDepartmentDistribution(
                    (float) (clone $filtered)->sum('acumatica_backorder_lines.revenue_at_risk'),
                    (int) (clone $filtered)->count(),
                    'Back Order Value',
                ),
                'customer_distribution' => $this->backordersCustomerDistribution($request),
                'product_distribution' => $this->backordersProductDistribution($request),
            ],
        ]);
    }

    public function updateBackorderReason(Request $request, AcumaticaBackorderLine $backorderLine): JsonResponse
    {
        $user = $request->user();
        $allowedRoles = ['Administrator', 'Customer Service Manager', 'Sales Operations'];

        abort_unless(
            $user !== null && (
                $user->is_super_admin
                || $user->is_account_manager
                || in_array($user->role, $allowedRoles, true)
            ),
            403,
            'You are not authorized to edit backorder reasons.'
        );

        $validated = $request->validate([
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', $this->reasonTaxonomy->approvedSubReasonCodes())],
            'reason_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $backorderLine->update([
            'reason_code' => $validated['reason_code'] ?? null,
            'reason_notes' => $validated['reason_notes'] ?? null,
            'reason_updated_by' => $user->id,
            'reason_updated_at' => now(),
        ]);
        app(DomainCache::class)->bump(
            DomainCache::BACKORDERS,
            DomainCache::BUSINESS_OPTIMIZATION,
        );

        return response()->json($backorderLine->fresh());
    }

    public function backordersByAccount(Request $request): JsonResponse
    {
        $topN = min(20, $request->integer('top', 10));

        $rows = $this->backordersFilteredQuery($request)
            ->select([
                'acumatica_backorder_lines.customer_acumatica_id',
                'acumatica_backorder_lines.customer_name',
                DB::raw('COUNT(DISTINCT acumatica_backorder_lines.order_nbr) as order_count'),
                DB::raw('COUNT(*) as open_lines'),
                DB::raw('SUM(acumatica_backorder_lines.revenue_at_risk) as revenue_at_risk'),
                DB::raw('SUM(acumatica_backorder_lines.open_qty) as total_open_qty'),
            ])
            ->groupBy('acumatica_backorder_lines.customer_acumatica_id', 'acumatica_backorder_lines.customer_name')
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
        $includeOos = $this->includeOutOfStock($request);

        $snapshots = $this->fillRateFilteredQuery($request, $dateFrom, $dateTo, applyStatusFilter: $includeOos)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date,status',
                'order.customer:acumatica_id,shipping_zone_id',
                'order.customer.shippingZone:acumatica_id,description,name,region',
                'order.lines:id,sales_order_id,inventory_id,order_qty,shipped_qty,qty_on_shipments,qty_at_approval,unit_price,unfilled_reason_code',
            ])
            ->get();

        $this->applyFillRateOutOfStockMode($snapshots, $includeOos);

        if (! $includeOos && ($status = $request->input('status'))) {
            $snapshots = $snapshots->where('fill_rate_status', $status)->values();
        }

        $deliverySlaCounts = ['breach' => 0, 'warning' => 0];
        foreach ($snapshots as $snapshot) {
            $sla = $this->deliverySlaForSnapshot($snapshot);
            if ($sla['delivery_sla_status'] === 'breach') {
                $deliverySlaCounts['breach']++;
            } elseif ($sla['delivery_sla_status'] === 'warning') {
                $deliverySlaCounts['warning']++;
            }
        }

        // Build KP / CS segment split using customer_class.
        $customerClasses = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $snapshots->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');
        $segmentSplit = $this->fillRateCalculator->segmentSplit($snapshots, $customerClasses->all());

        $eligible = $snapshots->where('fill_rate_status', '!=', 'na');
        $totalOrdered = $eligible->sum('total_ordered_qty');
        $totalShipped = $eligible->sum('total_shipped_qty');

        $overallPct = $totalOrdered > 0
            ? round(($totalShipped / $totalOrdered) * 1000) / 10
            : null;

        return response()->json([
            'date_from'            => $dateFrom,
            'date_to'              => $dateTo,
            'include_out_of_stock' => $includeOos,
            'overall_fill_rate'    => $overallPct,
            'overall_status'       => $overallPct !== null ? $this->fillRateCalculator->thresholdStatus($overallPct) : 'na',
            'segment_split'        => $segmentSplit,
            'revenue_not_shipped'  => round((float) $eligible->sum('revenue_not_shipped'), 2),
            'order_count'          => $snapshots->count(),
            'healthy_count'        => $snapshots->where('fill_rate_status', 'healthy')->count(),
            'at_risk_count'        => $snapshots->where('fill_rate_status', 'at_risk')->count(),
            'critical_count'       => $snapshots->where('fill_rate_status', 'critical')->count(),
            'na_count'             => $snapshots->where('fill_rate_status', 'na')->count(),
            'out_of_stock_line_count' => (int) $snapshots->sum('out_of_stock_line_count'),
            'delivery_sla_breach_count' => $deliverySlaCounts['breach'],
            'delivery_sla_warning_count' => $deliverySlaCounts['warning'],
            'delivery_sla_rules' => $this->deliverySla->publicRules(),
            'last_computed_at'     => AcumaticaFillRateSnapshot::max('computed_at'),
            'excel_summary'        => $this->fillRateExcelSummary($request, $snapshots, $snapshots->pluck('sales_order_id')->filter()->unique()->values()->all()),
            'filters'              => [
                'customer_groups' => AcumaticaCustomer::query()
                    ->whereNotNull('customer_class')
                    ->distinct()
                    ->orderBy('customer_class')
                    ->pluck('customer_class')
                    ->values(),
                'departments' => collect(['Unassigned'])->values(),
                'reason_codes' => AcumaticaSalesOrderLine::query()
                    ->whereNotNull('unfilled_reason_code')
                    ->distinct()
                    ->orderBy('unfilled_reason_code')
                    ->pluck('unfilled_reason_code')
                    ->values(),
                'product_lines' => AcumaticaInventoryItem::query()
                    ->whereNotNull('item_class')
                    ->distinct()
                    ->orderBy('item_class')
                    ->pluck('item_class')
                    ->values(),
                'shipping_zones' => AcumaticaShippingZone::query()
                    ->orderBy('region')
                    ->orderBy('name')
                    ->orderBy('acumatica_id')
                    ->get(['acumatica_id', 'description', 'name', 'region'])
                    ->map(fn (AcumaticaShippingZone $zone) => [
                        'acumatica_id' => $zone->acumatica_id,
                        'description' => $zone->description,
                        'name' => $zone->name,
                        'region' => $zone->region,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function fillRate(Request $request): JsonResponse
    {
        $includeOos = $this->includeOutOfStock($request);
        $query = $this->fillRateFilteredQuery($request, applyStatusFilter: $includeOos)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date,raw_payload,status',
                'order.customer:acumatica_id,shipping_zone_id',
                'order.customer.shippingZone:acumatica_id,description,name,region',
                'order.lines:id,sales_order_id,inventory_id,description,order_qty,shipped_qty,qty_on_shipments,open_qty,unit_price,uom,fill_rate_pct,qty_at_approval,unfilled_reason_code',
            ]);

        if ($deliverySla = $request->input('delivery_sla')) {
            if (! in_array($deliverySla, ['breach', 'warning'], true)) {
                return response()->json(['message' => 'Invalid delivery_sla filter.'], 422);
            }
        }

        $this->applyFillRateSearch($query, $request);

        // Recompute when excluding OOS so sort/status reflect adjusted fill rates.
        $needsMemorySort = ! $includeOos || $request->filled('delivery_sla');

        if ($needsMemorySort) {
            $all = $query->get();
            $this->applyFillRateOutOfStockMode($all, $includeOos);

            if ($status = $request->input('status')) {
                $all = $all->where('fill_rate_status', $status)->values();
            }

            if ($request->filled('delivery_sla')) {
                $all = $all->filter(function ($snapshot) use ($request) {
                    $sla = $this->deliverySlaForSnapshot($snapshot);

                    return $sla['delivery_sla_status'] === $request->input('delivery_sla');
                })->values();
            }

            $sort = $request->input('sort', 'high_to_low');
            $all = $sort === 'low_to_high'
                ? $all->sortBy(fn ($s) => $s->fill_rate_pct ?? PHP_FLOAT_MAX)->values()
                : $all->sortByDesc(fn ($s) => $s->fill_rate_pct ?? -1)->values();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, (int) $request->integer('per_page', 50));
            $items = $all->slice(($page - 1) * $perPage, $perPage)->values();
            $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $all->count(),
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $sort = $request->input('sort', 'high_to_low');
            if ($sort === 'low_to_high') {
                $query->orderByRaw('fill_rate_pct IS NULL')->orderBy('fill_rate_pct');
            } else {
                $query->orderByRaw('fill_rate_pct IS NULL')->orderByDesc('fill_rate_pct');
            }

            $paginated = $query->paginate($request->integer('per_page', 50));
            $items = $paginated->getCollection();
            $this->applyFillRateOutOfStockMode($items, $includeOos);
        }

        $inventoryIds = $items
            ->flatMap(fn ($snapshot) => $snapshot->order?->lines?->pluck('inventory_id') ?? collect())
            ->all();
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $inventoryClassifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($inventoryIds);

        $customerIds = $items
            ->map(fn ($snapshot) => $snapshot->customer_acumatica_id ?? $snapshot->order?->customer_acumatica_id)
            ->all();
        $customerNames = $this->catalogResolver->namesForCustomerIds($customerIds);

        $paginated->getCollection()->transform(function ($snapshot) use ($inventoryDescriptions, $inventoryClassifications, $inventoryStock, $customerNames, $includeOos) {
            $order = $snapshot->order;
            $storedCustomerName = $order?->customer_name;

            $snapshot->customer_name = $this->catalogResolver->resolveCustomerName(
                $storedCustomerName,
                $snapshot->customer_acumatica_id ?? $order?->customer_acumatica_id,
                $customerNames,
            );

            $snapshot->order_description = $order?->description;
            $snapshot->include_out_of_stock = $includeOos;

            foreach ($this->deliverySlaForSnapshot($snapshot) as $key => $value) {
                $snapshot->{$key} = $value;
            }

            $snapshot->products = collect($order?->lines ?? [])
                ->map(function ($line) use ($inventoryDescriptions, $inventoryClassifications, $inventoryStock, $includeOos) {
                    $isOos = $this->lineIsOutOfStock($line);
                    // Fill rate demand = Order Qty; shipped = Shipped Qty (fallback qty on shipments).
                    $demandQty = (float) $line->order_qty;
                    $shippedQty = $this->effectiveFillRateShippedQty($line);
                    $qtyOnShipments = $shippedQty;
                    $unfilledQty = max($demandQty - $shippedQty, 0);
                    $openQty = (float) $line->open_qty;
                    if ($openQty <= 0) {
                        $openQty = $unfilledQty;
                    }
                    $unitPrice = (float) $line->unit_price;
                    $classification = $this->catalogResolver->classificationFieldsFor(
                        $line->inventory_id,
                        $inventoryClassifications,
                    );

                    return [
                        'inventory_id'         => $line->inventory_id,
                        'product_name'         => $this->catalogResolver->resolveProductName(
                            $line->inventory_id,
                            $line->description,
                            $inventoryDescriptions,
                        ),
                        'brand'                => $classification['brand'],
                        'posting_class'        => $classification['posting_class'],
                        'sub_trading_group'    => $classification['sub_trading_group'],
                        'supplier'             => $classification['supplier'],
                        'order_qty'            => $line->order_qty,
                        'shipped_qty'          => $line->shipped_qty,
                        'qty_on_shipments'     => $line->qty_on_shipments,
                        'open_qty'             => number_format($openQty, 4, '.', ''),
                        'uom'                  => $this->catalogResolver->resolveUom(
                            $line->uom,
                            $line->inventory_id,
                            $inventoryStock,
                        ),
                        'unit_price'           => $line->unit_price,
                        'line_fill_rate_pct'   => $line->fill_rate_pct,
                        'unfilled_reason_code' => $line->unfilled_reason_code,
                        'is_out_of_stock'      => $isOos,
                        'excluded_from_fill_rate' => ! $includeOos && $isOos,
                        'not_shipped_value'    => $unitPrice > 0
                            ? number_format(round($unfilledQty * $unitPrice, 2), 2, '.', '')
                            : '0.00',
                    ];
                })
                ->values()
                ->all();

            return $snapshot;
        });

        return response()->json($paginated);
    }

    public function exportFillRate(Request $request): JsonResponse|StreamedResponse
    {
        // Export builds a multi-sheet XLSX in-process. Without raising limits,
        // large date ranges hit nginx/Cloudflare 504 before the stream starts.
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }
        @ini_set('max_execution_time', '300');
        @ini_set('memory_limit', '1024M');

        $includeOos = $this->includeOutOfStock($request);
        $query = $this->fillRateFilteredQuery($request, applyStatusFilter: $includeOos)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date,status',
                'order.customer:acumatica_id,shipping_zone_id',
                'order.customer.shippingZone:acumatica_id,description,name,region',
                'order.lines:id,sales_order_id,inventory_id,description,order_qty,shipped_qty,qty_on_shipments,open_qty,unit_price,uom,fill_rate_pct,qty_at_approval,unfilled_reason_code',
            ]);

        $this->applyFillRateSearch($query, $request);

        if (($baseCount = (clone $query)->count()) > 0) {
            if ($limitResponse = $this->exportLimitResponse($baseCount)) {
                return $limitResponse;
            }
            if ($limitResponse = $this->fillRateInteractiveExportLimitResponse($baseCount)) {
                return $limitResponse;
            }
        }

        $snapshots = $query->get();
        $this->applyFillRateOutOfStockMode($snapshots, $includeOos);

        if (! $includeOos && ($status = $request->input('status'))) {
            $snapshots = $snapshots->where('fill_rate_status', $status)->values();
        }

        if ($request->filled('delivery_sla')) {
            $deliverySla = $request->input('delivery_sla');
            if (! in_array($deliverySla, ['breach', 'warning'], true)) {
                return response()->json(['message' => 'Invalid delivery_sla filter.'], 422);
            }

            $snapshots = $snapshots
                ->filter(fn (AcumaticaFillRateSnapshot $snapshot) => $this->deliverySlaForSnapshot($snapshot)['delivery_sla_status'] === $deliverySla)
                ->values();
        }

        $sort = $request->input('sort', 'high_to_low');
        $snapshots = $sort === 'low_to_high'
            ? $snapshots->sortBy(fn ($snapshot) => $snapshot->fill_rate_pct ?? PHP_FLOAT_MAX)->values()
            : $snapshots->sortByDesc(fn ($snapshot) => $snapshot->fill_rate_pct ?? -1)->values();

        $inventoryIds = $snapshots
            ->flatMap(fn ($snapshot) => $snapshot->order?->lines?->pluck('inventory_id') ?? collect())
            ->all();
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($inventoryIds);
        $customerNames = $this->catalogResolver->namesForCustomerIds(
            $snapshots->map(fn ($snapshot) => $snapshot->customer_acumatica_id ?? $snapshot->order?->customer_acumatica_id)->all(),
        );

        // $fillRateRows are built below alongside $productRows and then passed to the exporter.

        $productRows = [];
        foreach ($snapshots as $snapshot) {
            $order = $snapshot->order;
            foreach ($order?->lines ?? [] as $line) {
                if (! $includeOos && $this->lineIsOutOfStock($line)) {
                    continue;
                }

                $demandQty = (float) $line->order_qty;
                $qtyOnShipments = $this->effectiveFillRateShippedQty($line);
                $openQty = (float) $line->open_qty;
                $unfilledQty = max($demandQty - $qtyOnShipments, 0);
                if ($openQty <= 0) {
                    $openQty = $unfilledQty;
                }
                $unitPrice = (float) $line->unit_price;

                $productRows[] = [
                    $snapshot->order_nbr,
                    $snapshot->customer_acumatica_id ?? $order?->customer_acumatica_id,
                    $line->inventory_id,
                    $this->catalogResolver->resolveProductName($line->inventory_id, $line->description, $inventoryDescriptions),
                    $demandQty,
                    (float) $line->order_qty,
                    (float) $line->shipped_qty,
                    $qtyOnShipments,
                    $openQty,
                    $this->catalogResolver->resolveUom($line->uom, $line->inventory_id, $inventoryStock),
                    $unitPrice,
                    $demandQty > 0 ? round(min($qtyOnShipments, $demandQty) / $demandQty * 100, 2) : null,
                    $line->unfilled_reason_code ?: 'unassigned',
                    $this->reasonDisplay($line->unfilled_reason_code),
                    round($unfilledQty * $unitPrice, 2),
                ];
            }
        }

        // Delegate to FillRateExcelExporter for the full enhanced workbook.
        // It builds all sheets including brand split, lost sales analysis, summary, and instructions.
        $fillRateRows = $snapshots->map(function (AcumaticaFillRateSnapshot $snapshot) use ($customerNames) {
            $order = $snapshot->order;
            $zone  = $order?->customer?->shippingZone;
            $sla   = $this->deliverySlaForSnapshot($snapshot);

            return [
                $snapshot->order_nbr,
                $snapshot->customer_acumatica_id ?? $order?->customer_acumatica_id,
                $this->catalogResolver->resolveCustomerName($order?->customer_name, $snapshot->customer_acumatica_id ?? $order?->customer_acumatica_id, $customerNames),
                $snapshot->status,
                $this->dateString($order?->order_date),
                (float) $snapshot->total_ordered_qty,
                (float) $snapshot->total_shipped_qty,
                $snapshot->fill_rate_pct !== null ? (float) $snapshot->fill_rate_pct : null,
                $snapshot->fill_rate_status,
                (float) $snapshot->revenue_not_shipped,
                $zone?->acumatica_id ?? $order?->customer?->shipping_zone_id,
                $zone?->name ?? $zone?->description,
                $sla['delivery_hours'],
                $sla['sla_hours'],
                $sla['delivery_sla_status'],
                $sla['delivery_sla_label'],
                $this->dateString($snapshot->computed_at),
            ];
        })->all();

        // Compute KP/CS segment data for the Summary sheet once (not re-queried
        // inside fillRateExcelSummary for the same work).
        $salesOrderIds = $snapshots->pluck('order.id')->filter()->unique()->values()->all();
        $segmentRows = $this->fillRateSegmentSummary($snapshots);
        $segmentReasonRows = $this->fillRateSegmentReasonSummary($request, $snapshots, $salesOrderIds);

        // Reuse line-level shortfall query once for reason / category / capture sheets.
        $shortfallLines = $this->fillRateShortfallLines($request, $salesOrderIds);
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);

        $excelSummary = $this->fillRateExcelSummaryFromPrepared(
            $request,
            $snapshots,
            $salesOrderIds,
            $shortfallLines,
            $productTypes,
            $segmentRows,
            $segmentReasonRows,
        );

        return $this->fillRateExporter->build(
            fillRateRows:       $fillRateRows,
            productRows:        $productRows,
            reasonRows:         $excelSummary['by_reason'],
            customerRows:       $excelSummary['top_customers'],
            productSummaryRows: $excelSummary['top_products'],
            dateFrom:           (string) $request->input('date_from', ''),
            dateTo:             (string) $request->input('date_to', ''),
            segmentRows:        $segmentRows,
            segmentReasonRows:  $segmentReasonRows,
            businessCategoryRows: $excelSummary['by_business_category'],
            reasonCaptureReport: $excelSummary['reason_capture_report'],
        );
    }

    private function inventoryFilteredQuery(Request $request): Builder
    {
        $warehouseIds = array_values(array_filter((array) $request->input('warehouse_id', ['FGS'])));
        if ($warehouseIds === []) {
            $warehouseIds = ['FGS'];
        }
        $query = AcumaticaInventoryItem::query()
            ->leftJoin('inventory_warehouse_balances as iwb', function ($join) use ($warehouseIds) {
                $join->on('iwb.inventory_item_id', '=', 'acumatica_inventory_items.id')
                    ->whereIn('iwb.warehouse_id', $warehouseIds);
            })
            ->select([
                'acumatica_inventory_items.*',
                DB::raw("COALESCE(iwb.warehouse_id, 'FGS') as selected_warehouse_id"),
                DB::raw('COALESCE(iwb.qty_on_hand, acumatica_inventory_items.qty_on_hand) as qty_on_hand'),
                DB::raw('COALESCE(iwb.qty_available, acumatica_inventory_items.qty_available) as qty_available'),
                DB::raw('COALESCE(iwb.synced_at, acumatica_inventory_items.synced_at) as synced_at'),
            ]);
        if (! in_array('FGS', $warehouseIds, true)) {
            $query->whereNotNull('iwb.id');
        }

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('acumatica_inventory_items.inventory_id', 'like', "%{$search}%")
                    ->orWhere('acumatica_inventory_items.description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->whereRaw('COALESCE(iwb.qty_on_hand, acumatica_inventory_items.qty_on_hand) <= 10');
        }

        if ($productType = $request->input('product_type')) {
            $query->where('acumatica_inventory_items.product_type', $productType);
        }

        if ($status = $request->input('prediction_status')) {
            $ids = $this->recentPredictionItemIds([(string) $status]);
            $query->whereIn('acumatica_inventory_items.id', $ids);
        }

        // Stockout prediction tab filters:
        // - critical_or_oos: prediction critical OR completely out of stock (qty <= 0)
        // - critical: prediction status critical only
        // - out_of_stock: qty on hand <= 0
        // - at_risk: prediction status at_risk only
        if ($stockout = $request->input('stockout_filter')) {
            $this->applyStockoutFilter($query, (string) $stockout);
        }

        app(BrandFilterService::class)->applyInventoryScope(
            $query,
            $request->input('partner_brand'),
            $request->input('brand'),
            $request->input('category'),
        );

        app(BrandAssignmentScope::class)->applyInventoryScope($query, $request->user());

        return $query;
    }

    /**
     * @param  list<string>  $statuses
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function recentPredictionItemIds(array $statuses)
    {
        return AcumaticaInventoryRunRateLog::query()
            ->whereIn('prediction_status', $statuses)
            ->where('logged_at', '>=', now()->subDays(2))
            ->distinct()
            ->pluck('inventory_item_id');
    }

    private function applyStockoutFilter(Builder $query, string $stockout): void
    {
        match ($stockout) {
            'out_of_stock' => $query->whereRaw('COALESCE(iwb.qty_on_hand, acumatica_inventory_items.qty_on_hand) <= 0'),
            'critical' => $query->whereIn('acumatica_inventory_items.id', $this->recentPredictionItemIds(['critical'])),
            'at_risk' => $query->whereIn('acumatica_inventory_items.id', $this->recentPredictionItemIds(['at_risk'])),
            // Default / primary tab view: critical stockout prediction OR zero stock.
            default => $query->where(function ($q) {
                $criticalIds = $this->recentPredictionItemIds(['critical']);
                $q->whereRaw('COALESCE(iwb.qty_on_hand, acumatica_inventory_items.qty_on_hand) <= 0');
                if ($criticalIds->isNotEmpty()) {
                    $q->orWhereIn('acumatica_inventory_items.id', $criticalIds);
                }
            }),
        };
    }

    private function latestInventoryRunRateLogs($itemIds)
    {
        $itemIds = collect($itemIds)->filter()->values();
        if ($itemIds->isEmpty()) {
            return collect();
        }

        return AcumaticaInventoryRunRateLog::whereIn('inventory_item_id', $itemIds)
            ->whereIn('id', function ($sub) use ($itemIds) {
                $sub->selectRaw('MAX(id)')
                    ->from('acumatica_inventory_run_rate_logs')
                    ->whereIn('inventory_item_id', $itemIds)
                    ->groupBy('inventory_item_id');
            })
            ->get()
            ->keyBy('inventory_item_id');
    }

    private function applyFillRateSearch(Builder $query, Request $request): void
    {
        if (! ($search = $request->input('q'))) {
            return;
        }

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

    private function fillRateFilteredQuery(
        Request $request,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        bool $applyStatusFilter = true,
    ): Builder {
        $query = AcumaticaFillRateSnapshot::query();

        $dateFrom ??= $request->input('date_from');
        $dateTo ??= $request->input('date_to');

        if ($dateFrom && $dateTo) {
            $query->where(function (Builder $q) use ($dateFrom, $dateTo) {
                $q->whereHas('order', function (Builder $orderQuery) use ($dateFrom, $dateTo) {
                    $orderQuery->whereBetween('order_date', [$dateFrom, $dateTo.' 23:59:59']);
                })->orWhere(function (Builder $fallback) use ($dateFrom, $dateTo) {
                    $fallback
                        ->whereDoesntHave('order')
                        ->whereBetween('computed_at', [$dateFrom, $dateTo.' 23:59:59']);
                });
            });
        }

        // When excluding OOS, status is recomputed in memory — skip DB status filter.
        if ($applyStatusFilter && ($status = $request->input('status'))) {
            $query->where('fill_rate_status', $status);
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('customer_acumatica_id', $customerId);
        }

        if ($customerGroup = $request->input('customer_group')) {
            $customerIds = AcumaticaCustomer::query()
                ->where('customer_class', $customerGroup)
                ->pluck('acumatica_id');
            $query->whereIn('customer_acumatica_id', $customerIds);
        }

        // Segment filter: KP (Kimfay Professional) vs CS (Consumer Sales).
        // KP = customer_class starts with "KP"; CS = all other classes.
        // When a segment is selected, restrict snapshots to customers whose
        // customer_class falls into that segment.
        if ($segment = $request->input('segment')) {
            $segmentCustomers = AcumaticaCustomer::query()
                ->whereNotNull('customer_class')
                ->get(['acumatica_id', 'customer_class'])
                ->filter(fn ($c) => $this->fillRateCalculator->segmentForCustomerClass($c->customer_class) === $segment)
                ->pluck('acumatica_id');

            $query->whereIn('customer_acumatica_id', $segmentCustomers);
        }

        if ($request->filled('shipping_zone_id')) {
            $zoneId = strtoupper(trim((string) $request->input('shipping_zone_id')));
            $customerIds = AcumaticaCustomer::query()
                ->where('shipping_zone_id', $zoneId)
                ->pluck('acumatica_id');
            $query->whereIn('customer_acumatica_id', $customerIds);
        }

        if ($reasonCode = $request->input('reason_code')) {
            $query->whereHas('order.lines', function ($q) use ($reasonCode) {
                if ($reasonCode === 'unassigned') {
                    $q->whereNull('unfilled_reason_code');
                } else {
                    $q->where('unfilled_reason_code', $reasonCode);
                }
            });
        }

        if ($productLine = $request->input('product_line')) {
            $inventoryIds = AcumaticaInventoryItem::query()
                ->where('item_class', $productLine)
                ->pluck('inventory_id');
            $query->whereHas('order.lines', fn ($q) => $q->whereIn('inventory_id', $inventoryIds));
        }

        $this->applySalesConsultantFillRateScope($query, $request);
        $this->applyDepartmentPortfolioScope($query, $request);
        $this->applyBrandFilterToFillRateQuery($query, $request);

        return $query;
    }

    private function backordersFilteredQuery(Request $request): Builder
    {
        $query = AcumaticaBackorderLine::query()
            ->leftJoin('acumatica_inventory_items as ai', 'acumatica_backorder_lines.inventory_id', '=', 'ai.inventory_id')
            ->leftJoin('acumatica_sales_orders as aso', function ($join) {
                $join->on('acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr');
            });

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            // Escape LIKE wildcards in user input.
            $like = '%'.addcslashes($search, '%_\\').'%';
            $digitsOnly = preg_replace('/\D+/', '', $search) ?: '';

            $inventoryIds = AcumaticaInventoryItem::query()
                ->where(function ($iq) use ($like) {
                    $iq->where('description', 'like', $like)
                        ->orWhere('inventory_id', 'like', $like);
                })
                ->limit(500)
                ->pluck('inventory_id');

            $customerIds = AcumaticaCustomer::query()
                ->where(function ($cq) use ($like) {
                    $cq->where('name', 'like', $like)
                        ->orWhere('acumatica_id', 'like', $like);
                })
                ->limit(500)
                ->pluck('acumatica_id');

            $query->where(function ($q) use ($like, $search, $digitsOnly, $inventoryIds, $customerIds) {
                // SO number (full or partial, e.g. SO359099 or 359099)
                $q->where('acumatica_backorder_lines.order_nbr', 'like', $like)
                    ->orWhere('aso.acumatica_order_nbr', 'like', $like)
                    // Inventory ID / product class
                    ->orWhere('acumatica_backorder_lines.inventory_id', 'like', $like)
                    ->orWhere('ai.description', 'like', $like)
                    ->orWhere('ai.item_class', 'like', $like)
                    // Customer id / name on the backorder line
                    ->orWhere('acumatica_backorder_lines.customer_name', 'like', $like)
                    ->orWhere('acumatica_backorder_lines.customer_acumatica_id', 'like', $like)
                    ->orWhere('aso.customer_name', 'like', $like)
                    ->orWhere('aso.customer_acumatica_id', 'like', $like);

                // Match SO359099 when user types only 359099
                if ($digitsOnly !== '' && strcasecmp($digitsOnly, $search) !== 0) {
                    $q->orWhere('acumatica_backorder_lines.order_nbr', 'like', '%'.$digitsOnly.'%')
                        ->orWhere('aso.acumatica_order_nbr', 'like', '%'.$digitsOnly.'%');
                }

                // Case-insensitive exact SO match (common paste with spaces)
                $normalizedSo = strtoupper(preg_replace('/\s+/', '', $search) ?? $search);
                if ($normalizedSo !== '') {
                    $q->orWhereRaw("UPPER(REPLACE(acumatica_backorder_lines.order_nbr, ' ', '')) = ?", [$normalizedSo])
                        ->orWhereRaw("UPPER(REPLACE(COALESCE(aso.acumatica_order_nbr, ''), ' ', '')) = ?", [$normalizedSo]);
                }

                if ($inventoryIds->isNotEmpty()) {
                    $q->orWhereIn('acumatica_backorder_lines.inventory_id', $inventoryIds);
                }
                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('acumatica_backorder_lines.customer_acumatica_id', $customerIds);
                }
            });
        }

        if ($customerId = $request->input('customer_id')) {
            if ($request->boolean('include_branches')) {
                $branchIds = AcumaticaCustomer::query()
                    ->where('parent_acumatica_id', $customerId)
                    ->pluck('acumatica_id')
                    ->all();
                $ids = array_values(array_unique(array_filter([$customerId, ...$branchIds])));
                $query->whereIn('acumatica_backorder_lines.customer_acumatica_id', $ids);
            } else {
                $query->where('acumatica_backorder_lines.customer_acumatica_id', $customerId);
            }
        }

        if ($customerGroup = $request->input('customer_group')) {
            $customerIds = AcumaticaCustomer::query()
                ->where('customer_class', $customerGroup)
                ->pluck('acumatica_id');
            $query->whereIn('acumatica_backorder_lines.customer_acumatica_id', $customerIds);
        }

        if ($productLine = $request->input('product_line')) {
            $query->where('ai.item_class', $productLine);
        }

        if ($warehouseId = $request->input('warehouse_id')) {
            $query->where('acumatica_backorder_lines.warehouse_id', $warehouseId);
        }

        if ($reasonCode = $request->input('reason_code')) {
            // Match stored backorder reason OR Acumatica SO-line unfilled_reason_code
            // so root-cause filters work after sales-order import (not only manual edits).
            if ($reasonCode === 'unassigned') {
                $query->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->whereNull('acumatica_backorder_lines.reason_code')
                            ->orWhere('acumatica_backorder_lines.reason_code', '');
                    })->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('acumatica_sales_order_lines as sol_r')
                            ->join('acumatica_sales_orders as so_r', 'so_r.id', '=', 'sol_r.sales_order_id')
                            ->whereColumn('so_r.acumatica_order_nbr', 'acumatica_backorder_lines.order_nbr')
                            ->whereColumn('sol_r.inventory_id', 'acumatica_backorder_lines.inventory_id')
                            ->whereNotNull('sol_r.unfilled_reason_code')
                            ->where('sol_r.unfilled_reason_code', '!=', '');
                    });
                });
            } else {
                $query->where(function ($q) use ($reasonCode) {
                    $q->where('acumatica_backorder_lines.reason_code', $reasonCode)
                        ->orWhereExists(function ($sub) use ($reasonCode) {
                            $sub->select(DB::raw(1))
                                ->from('acumatica_sales_order_lines as sol_r')
                                ->join('acumatica_sales_orders as so_r', 'so_r.id', '=', 'sol_r.sales_order_id')
                                ->whereColumn('so_r.acumatica_order_nbr', 'acumatica_backorder_lines.order_nbr')
                                ->whereColumn('sol_r.inventory_id', 'acumatica_backorder_lines.inventory_id')
                                ->where('sol_r.unfilled_reason_code', $reasonCode);
                        });
                });
            }
        }

        if ($segment = $request->input('segment')) {
            $segmentCustomers = AcumaticaCustomer::query()
                ->whereNotNull('customer_class')
                ->get(['acumatica_id', 'customer_class'])
                ->filter(fn ($c) => $this->fillRateCalculator->segmentForCustomerClass($c->customer_class) === $segment)
                ->pluck('acumatica_id');

            $query->whereIn('acumatica_backorder_lines.customer_acumatica_id', $segmentCustomers);
        }

        if ($productSegment = $request->input('product_segment')) {
            $this->applyProductSegmentFilter($query, $productSegment, 'acumatica_backorder_lines.inventory_id');
        }

        if ($shortfallKind = $request->input('shortfall_kind')) {
            if (in_array($shortfallKind, ['active_backorder', 'completed_shortfall'], true)) {
                $query->where('acumatica_backorder_lines.shortfall_kind', $shortfallKind);
            }
        }

        if ($request->filled('fulfillment_status')) {
            $fulfillmentStatus = trim((string) $request->input('fulfillment_status'));
            if (! in_array($fulfillmentStatus, $this->backorderFulfillmentStatuses(), true)) {
                throw ValidationException::withMessages([
                    'fulfillment_status' => ['The selected fulfillment status is invalid.'],
                ]);
            }
            $query->where('acumatica_backorder_lines.fulfillment_status', $fulfillmentStatus);
        }

        $dateExpr = $this->backorderTimelineDateExpression();

        if ($dateFrom = $request->input('date_from')) {
            $query->whereRaw($dateExpr.' >= ?', [$dateFrom]);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereRaw($dateExpr.' <= ?', [$dateTo]);
        }

        // Explicit rep filter (consultant detail "My backorders"): assignment ∪ SO rep-code portfolio.
        if ($repCode = $request->input('rep_code')) {
            $this->applyRepPortfolioCustomerFilter($query, (string) $repCode, 'acumatica_backorder_lines.customer_acumatica_id');
        }

        $this->applySalesConsultantBackorderScope($query, $request);
        $this->applyDepartmentPortfolioScope($query, $request, 'acumatica_backorder_lines.customer_acumatica_id');
        $this->applyBrandFilterToBackorderQuery($query, $request);

        return $query;
    }

    /** @return list<string> */
    private function backorderFulfillmentStatuses(): array
    {
        return [
            SalesOrderLineFulfillmentDeriver::STATUS_FULLY_FULFILLED,
            SalesOrderLineFulfillmentDeriver::STATUS_BACKORDERS_IMPORTED,
            SalesOrderLineFulfillmentDeriver::STATUS_CANCELLED,
            SalesOrderLineFulfillmentDeriver::STATUS_PARTIALLY_SHIPPED,
            SalesOrderLineFulfillmentDeriver::STATUS_PENDING_SHIPMENT,
        ];
    }

    /**
     * Restrict a query to Manufactured or Trading inventory. Items absent
     * from acumatica_inventory_items fall to Trading, mirroring
     * FillRateBusinessCategory's default.
     */
    private function applyProductSegmentFilter(Builder $query, string $productSegment, string $inventoryColumn): void
    {
        if (! in_array($productSegment, [FillRateBusinessCategory::MANUFACTURED, FillRateBusinessCategory::TRADING], true)) {
            return;
        }

        $manufacturedIds = AcumaticaInventoryItem::query()
            ->get(['inventory_id', 'product_type'])
            ->filter(fn ($item) => $this->businessCategory->classify($item->inventory_id, $item->product_type) === FillRateBusinessCategory::MANUFACTURED)
            ->pluck('inventory_id')
            ->values()
            ->all();

        if ($productSegment === FillRateBusinessCategory::MANUFACTURED) {
            // Laravel whereIn([]) becomes 0=1 (no rows) — correct when nothing is manufactured.
            if ($manufacturedIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn($inventoryColumn, $manufacturedIds);
            }

            return;
        }

        // Trading = not manufactured. Empty manufactured list ⇒ every SKU is trading.
        // Laravel whereNotIn([]) also becomes 0=1 — must skip the clause when empty.
        if ($manufacturedIds !== []) {
            $query->where(function ($q) use ($inventoryColumn, $manufacturedIds) {
                $q->whereNotIn($inventoryColumn, $manufacturedIds)
                    // Include SKUs missing from inventory catalog (prefix-classified as trading).
                    ->orWhereNull($inventoryColumn);
            });
        }
    }

    private function backordersScopedLinesQuery(Request $request): Builder
    {
        $query = AcumaticaBackorderLine::query()
            ->leftJoin('acumatica_sales_orders as aso', function ($join) {
                $join->on('acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr');
            });

        $this->applySalesConsultantBackorderScope($query, $request);

        return $query;
    }

    private function applySalesConsultantBackorderScope(Builder $query, Request $request): void
    {
        $customerIds = DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($customerIds === null) {
            return;
        }
        if ($customerIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->whereIn('acumatica_backorder_lines.customer_acumatica_id', $customerIds);
    }

    private function applySalesConsultantFillRateScope(Builder $query, Request $request): void
    {
        $customerIds = DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($customerIds === null) {
            return;
        }
        if ($customerIds === []) {
            $query->whereRaw('1 = 0');
            return;
        }
        $query->where(function (Builder $visible) use ($customerIds) {
            $visible->whereIn('customer_acumatica_id', $customerIds)
                ->orWhereHas('order', fn ($orderQuery) => $orderQuery->whereIn('customer_acumatica_id', $customerIds));
        });
    }

    /**
     * Narrow to one consultant's fair portfolio: assignment rows ∪ Acumatica SO rep-code.
     * Used when ?rep_code= is set from the sales-consultant detail page.
     */
    private function applyRepPortfolioCustomerFilter(Builder $query, string $repCode, string $customerColumn): void
    {
        $ids = $this->salesPortfolio->portfolioCustomerIdsForRepCode($repCode);
        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->whereIn($customerColumn, $ids);
    }

    /** @return array<string, mixed> */
    private function deliverySlaForSnapshot(AcumaticaFillRateSnapshot $snapshot): array
    {
        $order = $snapshot->order;
        $zone = $order?->customer?->shippingZone;

        return $this->deliverySla->evaluate(
            $order?->order_date,
            $order?->approved_at,
            $order?->shipped_at,
            $order?->ship_date,
            $zone?->acumatica_id ?? $order?->customer?->shipping_zone_id,
            $zone?->description,
            null,
            $zone?->region,
        );
    }

    private function fillRateExcelSummary(Request $request, $snapshots, array $salesOrderIds = []): array
    {
        $shortfallLines = $this->fillRateShortfallLines($request, $salesOrderIds);
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);

        return $this->fillRateExcelSummaryFromPrepared(
            $request,
            $snapshots,
            $salesOrderIds,
            $shortfallLines,
            $productTypes,
            $this->fillRateSegmentSummary($snapshots),
            $this->fillRateSegmentReasonSummary($request, $snapshots, $salesOrderIds),
        );
    }

    /**
     * Build excel_summary using pre-fetched shortfall lines / segment rows so export
     * does not re-query the same order lines 3–4 times.
     *
     * @param  \Illuminate\Support\Collection<int, object>|\Illuminate\Database\Eloquent\Collection  $snapshots
     * @param  list<int|string>  $salesOrderIds
     * @param  \Illuminate\Support\Collection<int, object>  $shortfallLines
     * @param  array<string, mixed>  $productTypes
     * @param  list<array<string, mixed>>  $segmentRows
     * @param  list<array<string, mixed>>  $segmentReasonRows
     * @return array<string, mixed>
     */
    private function fillRateExcelSummaryFromPrepared(
        Request $request,
        $snapshots,
        array $salesOrderIds,
        $shortfallLines,
        array $productTypes,
        array $segmentRows,
        array $segmentReasonRows,
    ): array {
        $eligible = $snapshots->where('fill_rate_status', '!=', 'na');
        $actualQty = round((float) $eligible->sum('total_shipped_qty'), 4);
        $orderedQty = round((float) $eligible->sum('total_ordered_qty'), 4);
        $undershippedQty = round(max($orderedQty - $actualQty, 0), 4);
        $undershippedValue = round((float) $eligible->sum('revenue_not_shipped'), 2);

        return [
            'totals' => [
                'actual_qty' => $actualQty,
                'ordered_qty' => $orderedQty,
                'undershipped_qty' => $undershippedQty,
                'undershipped_value' => $undershippedValue,
                'fill_rate_pct' => $orderedQty > 0 ? round(($actualQty / $orderedQty) * 100, 1) : null,
                'order_count' => $snapshots->count(),
            ],
            'by_status' => $this->contributionRows(
                $snapshots->groupBy('fill_rate_status'),
                'status',
                'undershipped_value',
                fn ($group) => (float) $group->sum('revenue_not_shipped'),
                fn ($group) => $group->count(),
            ),
            'by_reason' => $this->fillRateReasonSummaryFromLines($shortfallLines),
            'by_department' => $this->unassignedDepartmentDistribution($undershippedValue, $snapshots->count(), 'Undershipped Value'),
            'by_customer_group' => $this->fillRateCustomerGroupSummary($request, $snapshots),
            'top_customers' => $this->fillRateTopCustomers($request, $snapshots),
            'top_products' => $this->fillRateProductSummaryFromLines($shortfallLines),
            'by_segment' => $segmentRows,
            'by_segment_reason' => $segmentReasonRows,
            'by_business_category' => $this->fillRateBusinessCategorySummaryFromLines($shortfallLines, $productTypes),
            'reason_capture_report' => $this->reasonCaptureReport->build($shortfallLines, $productTypes),
        ];
    }

    /**
     * Build Manufactured vs Trading (Partners) fill-rate metrics for cross-category comparison.
     */
    private function fillRateBusinessCategorySummary(Request $request, $snapshots, array $salesOrderIds): array
    {
        $lines = $this->fillRateShortfallLines($request, $salesOrderIds);
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);

        return $this->fillRateBusinessCategorySummaryFromLines($lines, $productTypes);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @param  array<string, mixed>  $productTypes
     * @return list<array<string, mixed>>
     */
    private function fillRateBusinessCategorySummaryFromLines($lines, array $productTypes): array
    {
        $buckets = [
            FillRateBusinessCategory::MANUFACTURED => [
                'business_category' => FillRateBusinessCategory::MANUFACTURED,
                'label' => FillRateBusinessCategory::LABEL_MANUFACTURED,
                'line_count' => 0,
                'order_count' => 0,
                'ordered_qty' => 0.0,
                'shipped_qty' => 0.0,
                'undershipped_value' => 0.0,
                'fill_rate_pct' => null,
            ],
            FillRateBusinessCategory::TRADING => [
                'business_category' => FillRateBusinessCategory::TRADING,
                'label' => FillRateBusinessCategory::LABEL_TRADING,
                'line_count' => 0,
                'order_count' => 0,
                'ordered_qty' => 0.0,
                'shipped_qty' => 0.0,
                'undershipped_value' => 0.0,
                'fill_rate_pct' => null,
            ],
        ];

        $orderIdsByCategory = [
            FillRateBusinessCategory::MANUFACTURED => [],
            FillRateBusinessCategory::TRADING => [],
        ];

        foreach ($lines as $line) {
            $inventoryId = (string) ($line->inventory_id ?? '');
            $category = $this->businessCategory->classify(
                $inventoryId,
                $productTypes[$inventoryId] ?? null,
            );
            $demand = (float) ($line->order_qty ?? 0);
            $onShipments = $this->effectiveFillRateShippedQty($line);
            $value = max($demand - $onShipments, 0) * (float) ($line->unit_price ?? 0);

            $buckets[$category]['line_count']++;
            $buckets[$category]['ordered_qty'] += $demand;
            $buckets[$category]['shipped_qty'] += $onShipments;
            $buckets[$category]['undershipped_value'] += $value;
            $orderIdsByCategory[$category][(string) ($line->sales_order_id ?? '')] = true;
        }

        foreach ($buckets as $category => $bucket) {
            $ordered = $bucket['ordered_qty'];
            $buckets[$category]['order_count'] = count($orderIdsByCategory[$category]);
            $buckets[$category]['ordered_qty'] = round($bucket['ordered_qty'], 4);
            $buckets[$category]['shipped_qty'] = round($bucket['shipped_qty'], 4);
            $buckets[$category]['undershipped_value'] = round($bucket['undershipped_value'], 2);
            $buckets[$category]['fill_rate_pct'] = $ordered > 0
                ? round(($bucket['shipped_qty'] / $ordered) * 1000) / 10
                : null;
        }

        return array_values($buckets);
    }

    private function fillRateReasonCaptureReport(Request $request, array $salesOrderIds): array
    {
        $lines = $this->fillRateShortfallLines($request, $salesOrderIds);
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);

        return $this->reasonCaptureReport->build($lines, $productTypes);
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function fillRateShortfallLines(Request $request, array $salesOrderIds)
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $select = [
            'acumatica_sales_order_lines.sales_order_id',
            'acumatica_sales_order_lines.inventory_id',
            'acumatica_sales_order_lines.unfilled_reason_code',
            'acumatica_sales_order_lines.qty_at_approval',
            'acumatica_sales_order_lines.order_qty',
            'acumatica_sales_order_lines.shipped_qty',
            'acumatica_sales_order_lines.qty_on_shipments',
            'acumatica_sales_order_lines.unit_price',
            'o.acumatica_order_nbr as order_nbr',
            'o.customer_acumatica_id',
        ];

        if ($salesOrderIds === []) {
            $rows = AcumaticaSalesOrderLine::query()
                ->join('acumatica_sales_orders as o', 'o.id', '=', 'acumatica_sales_order_lines.sales_order_id')
                ->select($select)
                ->whereBetween('o.order_date', [$dateFrom, $dateTo.' 23:59:59'])
                ->get();
        } else {
            // Chunk whereIn to avoid oversized SQL packets on large exports.
            $rows = collect();
            foreach (array_chunk($salesOrderIds, 500) as $chunk) {
                $rows = $rows->merge(
                    AcumaticaSalesOrderLine::query()
                        ->join('acumatica_sales_orders as o', 'o.id', '=', 'acumatica_sales_order_lines.sales_order_id')
                        ->select($select)
                        ->whereIn('o.id', $chunk)
                        ->get(),
                );
            }
        }

        // When "include out of stock" is off, drop OOS shortfall lines from fill-rate summaries.
        if (! $this->includeOutOfStock($request)) {
            $rows = $rows->reject(fn ($line) => $this->lineIsOutOfStock($line))->values();
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function fillRateReasonSummaryFromLines($lines): array
    {
        $total = $lines->sum(function ($line) {
            $demand = (float) ($line->order_qty ?? 0);

            return max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) ($line->unit_price ?? 0);
        });

        return $lines
            ->groupBy(fn ($line) => $line->unfilled_reason_code ?: 'Unassigned')
            ->map(function ($group, $reason) use ($total) {
                $value = $group->sum(function ($line) {
                    $demand = (float) ($line->order_qty ?? 0);

                    return max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) ($line->unit_price ?? 0);
                });

                return [
                    'reason' => (string) $reason,
                    'line_count' => $group->count(),
                    'undershipped_value' => round((float) $value, 2),
                    'contribution_pct' => $total > 0 ? round(((float) $value / (float) $total) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();
    }

    /**
     * Default false: fill rate is calculated without out-of-stock lines.
     * Toggle include_out_of_stock=1/true to include them.
     */
    private function includeOutOfStock(Request $request): bool
    {
        $raw = $request->input('include_out_of_stock', $request->input('include_oos', false));

        if (is_bool($raw)) {
            return $raw;
        }

        $normalized = strtolower(trim((string) $raw));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function lineIsOutOfStock(object $line): bool
    {
        return SalesOrderReasonCatalog::isOutOfStockReason(
            isset($line->unfilled_reason_code) ? (string) $line->unfilled_reason_code : null,
        );
    }

    private function effectiveFillRateShippedQty(object $line): float
    {
        $shipped = (float) ($line->shipped_qty ?? 0);

        return $shipped > 0 ? $shipped : (float) ($line->qty_on_shipments ?? 0);
    }

    /**
     * Recompute fill rate from order lines using current formula:
     * Completed only · Shipped Qty ÷ Order Qty × 100.
     * When $includeOutOfStock is false, OOS shortfall lines are excluded from the math.
     *
     * @param  \Illuminate\Support\Collection<int, AcumaticaFillRateSnapshot>|\Illuminate\Database\Eloquent\Collection  $snapshots
     */
    private function applyFillRateOutOfStockMode($snapshots, bool $includeOutOfStock): void
    {
        foreach ($snapshots as $snapshot) {
            $status = (string) ($snapshot->status ?? $snapshot->order?->status ?? '');
            $lines = collect($snapshot->order?->lines ?? [])->map(function ($line) {
                return [
                    'inventory_id' => $line->inventory_id,
                    'order_qty' => (float) $line->order_qty,
                    'shipped_qty' => (float) ($line->shipped_qty ?? 0),
                    'qty_on_shipments' => (float) ($line->qty_on_shipments ?? 0),
                    'unit_price' => (float) $line->unit_price,
                    'unfilled_reason_code' => $line->unfilled_reason_code,
                    'is_out_of_stock' => $this->lineIsOutOfStock($line),
                ];
            })->all();

            if ($lines === []) {
                // No line payload: keep stored snapshot values unless a known
                // non-completed status makes the metric explicitly ineligible.
                if ($status !== '' && ! \App\Services\Admin\FillRateCalculator::isEligibleStatus($status)) {
                    $snapshot->fill_rate_pct = null;
                    $snapshot->fill_rate_status = 'na';
                    $snapshot->total_ordered_qty = 0;
                    $snapshot->total_shipped_qty = 0;
                    $snapshot->revenue_not_shipped = 0;
                }
                $snapshot->fill_rate_excludes_out_of_stock = ! $includeOutOfStock;

                continue;
            }

            $computed = $this->fillRateCalculator->compute($status, $lines, includeOutOfStock: $includeOutOfStock);
            $snapshot->total_ordered_qty = $computed['total_ordered_qty'];
            $snapshot->total_shipped_qty = $computed['total_shipped_qty'];
            $snapshot->fill_rate_pct = $computed['fill_rate_pct'];
            $snapshot->fill_rate_status = $computed['fill_rate_status'];
            $snapshot->revenue_not_shipped = $computed['revenue_not_shipped'];
            $snapshot->out_of_stock_line_count = $computed['out_of_stock_line_count'];
            $snapshot->fill_rate_excludes_out_of_stock = ! $includeOutOfStock;
        }
    }

    /**
     * Out-of-stock shortfall report: Manufactured vs Trading, brand-filterable SKUs.
     */
    public function fillRateOutOfStockReport(Request $request): JsonResponse
    {
        return response()->json($this->buildFillRateOutOfStockReport($request));
    }

    public function exportFillRateOutOfStockReport(Request $request): JsonResponse|StreamedResponse
    {
        $payload = $this->buildFillRateOutOfStockReport($request);
        $skus = $payload['skus'];

        if ($limitResponse = $this->exportLimitResponse(count($skus))) {
            return $limitResponse;
        }

        $spreadsheet = $this->newSpreadsheet('Out of Stock Report');
        $this->writeSheet($spreadsheet, 'By Category', [
            'Category', 'Lines', 'Orders', 'SKUs', 'Undershipped Qty', 'Value (KES)',
        ], collect($payload['by_business_category'])->map(fn (array $row) => [
            $row['label'],
            $row['line_count'],
            $row['order_count'],
            $row['sku_count'],
            $row['undershipped_qty'],
            $row['undershipped_value'],
        ])->all());

        $this->writeSheet($spreadsheet, 'SKU Detail', [
            'Inventory ID', 'Product Name', 'Brand', 'Business Category', 'Reason',
            'Line Count', 'Order Count', 'Undershipped Qty', 'Value (KES)',
        ], collect($skus)->map(fn (array $row) => [
            $row['inventory_id'],
            $row['product_name'],
            $row['brand'],
            $row['business_category_label'],
            $row['reason_label'],
            $row['line_count'],
            $row['order_count'],
            $row['undershipped_qty'],
            $row['undershipped_value'],
        ])->all());

        return $this->downloadSpreadsheet(
            $spreadsheet,
            'fill-rate-out-of-stock-'.now()->format('Ymd-Hi').'.xlsx',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFillRateOutOfStockReport(Request $request): array
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $brandFilter = strtoupper(trim((string) $request->input('brand', '')));
        $partnerBrand = strtolower(trim((string) $request->input('partner_brand', '')));
        $businessCategoryFilter = strtolower(trim((string) $request->input('business_category', '')));

        // Force include OOS lines for this report regardless of the fill-rate toggle.
        $request->merge(['include_out_of_stock' => true]);

        $salesOrderIds = $this->fillRateFilteredQuery($request, $dateFrom, $dateTo)
            ->pluck('sales_order_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $lines = $this->fillRateShortfallLines($request, $salesOrderIds)
            ->filter(function ($line) {
                $demand = (float) ($line->order_qty ?? 0);
                $shipped = $this->effectiveFillRateShippedQty($line);

                return $this->lineIsOutOfStock($line) && max($demand - $shipped, 0) > 0;
            })
            ->values();

        $inventoryIds = $lines->pluck('inventory_id')->filter()->unique()->values()->all();
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $classifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);

        $grouped = [];
        $categoryBuckets = [
            FillRateBusinessCategory::MANUFACTURED => [
                'business_category' => FillRateBusinessCategory::MANUFACTURED,
                'label' => FillRateBusinessCategory::LABEL_MANUFACTURED,
                'line_count' => 0,
                'order_ids' => [],
                'sku_ids' => [],
                'undershipped_qty' => 0.0,
                'undershipped_value' => 0.0,
            ],
            FillRateBusinessCategory::TRADING => [
                'business_category' => FillRateBusinessCategory::TRADING,
                'label' => FillRateBusinessCategory::LABEL_TRADING,
                'line_count' => 0,
                'order_ids' => [],
                'sku_ids' => [],
                'undershipped_qty' => 0.0,
                'undershipped_value' => 0.0,
            ],
        ];
        $brandOptions = [];

        foreach ($lines as $line) {
            $inventoryId = (string) ($line->inventory_id ?? '');
            if ($inventoryId === '') {
                continue;
            }

            $classification = $this->catalogResolver->classificationFieldsFor($inventoryId, $classifications);
            $brand = $classification['brand'] ? strtoupper(trim((string) $classification['brand'])) : null;
            if ($brand) {
                $brandOptions[$brand] = true;
            }

            if ($brandFilter !== '' && $brand !== $brandFilter) {
                continue;
            }

            // partner_brand filter: manufactured group or specific trading brand cascade key
            $category = $this->businessCategory->classify(
                $inventoryId,
                $productTypes[$inventoryId] ?? null,
            );
            if ($partnerBrand === 'manufactured' && $category !== FillRateBusinessCategory::MANUFACTURED) {
                continue;
            }
            if ($partnerBrand !== '' && $partnerBrand !== 'manufactured' && $partnerBrand !== 'all') {
                // When a specific partner brand group is selected, keep trading SKUs matching brand filter only.
                if ($category !== FillRateBusinessCategory::TRADING) {
                    continue;
                }
            }
            if ($businessCategoryFilter !== ''
                && in_array($businessCategoryFilter, [FillRateBusinessCategory::MANUFACTURED, FillRateBusinessCategory::TRADING], true)
                && $category !== $businessCategoryFilter) {
                continue;
            }

            $demand = (float) ($line->order_qty ?? 0);
            $shipped = $this->effectiveFillRateShippedQty($line);
            $undershipped = max($demand - $shipped, 0);
            $value = $undershipped * (float) ($line->unit_price ?? 0);
            $reason = (string) ($line->unfilled_reason_code ?? 'out_of_stock_procurement');
            $orderId = (string) ($line->sales_order_id ?? '');

            $categoryBuckets[$category]['line_count']++;
            $categoryBuckets[$category]['undershipped_qty'] += $undershipped;
            $categoryBuckets[$category]['undershipped_value'] += $value;
            $categoryBuckets[$category]['sku_ids'][$inventoryId] = true;
            if ($orderId !== '') {
                $categoryBuckets[$category]['order_ids'][$orderId] = true;
            }

            if (! isset($grouped[$inventoryId])) {
                $grouped[$inventoryId] = [
                    'inventory_id' => $inventoryId,
                    'product_name' => $this->catalogResolver->resolveProductName(
                        $inventoryId,
                        null,
                        $descriptions,
                    ),
                    'brand' => $classification['brand'],
                    'posting_class' => $classification['posting_class'],
                    'sub_trading_group' => $classification['sub_trading_group'],
                    'supplier' => $classification['supplier'],
                    'business_category' => $category,
                    'business_category_label' => $this->businessCategory->label($category),
                    'reason_code' => $reason,
                    'reason_label' => $this->reasonDisplay($reason),
                    'line_count' => 0,
                    'order_ids' => [],
                    'undershipped_qty' => 0.0,
                    'undershipped_value' => 0.0,
                ];
            }

            $grouped[$inventoryId]['line_count']++;
            $grouped[$inventoryId]['undershipped_qty'] += $undershipped;
            $grouped[$inventoryId]['undershipped_value'] += $value;
            if ($orderId !== '') {
                $grouped[$inventoryId]['order_ids'][$orderId] = true;
            }
        }

        $skus = collect($grouped)
            ->map(function (array $row) {
                $row['order_count'] = count($row['order_ids']);
                unset($row['order_ids']);
                $row['undershipped_qty'] = round($row['undershipped_qty'], 4);
                $row['undershipped_value'] = round($row['undershipped_value'], 2);

                return $row;
            })
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();

        $byCategory = collect($categoryBuckets)->map(function (array $bucket) {
            return [
                'business_category' => $bucket['business_category'],
                'label' => $bucket['label'],
                'line_count' => $bucket['line_count'],
                'order_count' => count($bucket['order_ids']),
                'sku_count' => count($bucket['sku_ids']),
                'undershipped_qty' => round($bucket['undershipped_qty'], 4),
                'undershipped_value' => round($bucket['undershipped_value'], 2),
            ];
        })->values()->all();

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'brand' => $brandFilter !== '' ? $brandFilter : null,
            'business_category' => $businessCategoryFilter !== '' ? $businessCategoryFilter : null,
            'totals' => [
                'line_count' => array_sum(array_column($byCategory, 'line_count')),
                'order_count' => collect($byCategory)->sum('order_count'),
                'sku_count' => count($skus),
                'undershipped_qty' => round(array_sum(array_column($byCategory, 'undershipped_qty')), 4),
                'undershipped_value' => round(array_sum(array_column($byCategory, 'undershipped_value')), 2),
            ],
            'by_business_category' => $byCategory,
            'brands' => array_values(array_keys($brandOptions)),
            'skus' => $skus,
        ];
    }

    /**
     * Build the KP / CS segment split summary for the Excel export.
     * Returns fill-rate metrics per segment (KP + CS).
     */
    private function fillRateSegmentSummary($snapshots): array
    {
        $customerClasses = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $snapshots->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');

        $split = $this->fillRateCalculator->segmentSplit($snapshots, $customerClasses->all());

        return collect($split)->map(function ($bucket, $segment) {
            return [
                'segment' => $segment,
                'label' => $this->fillRateCalculator->segmentLabel($segment),
                'order_count' => $bucket['order_count'],
                'total_ordered_qty' => round((float) $bucket['total_ordered_qty'], 4),
                'total_shipped_qty' => round((float) $bucket['total_shipped_qty'], 4),
                'fill_rate_pct' => $bucket['fill_rate_pct'],
                'status' => $bucket['status'],
                'revenue_not_shipped' => round((float) $bucket['revenue_not_shipped'], 2),
                'healthy_count' => $bucket['healthy_count'],
                'at_risk_count' => $bucket['at_risk_count'],
                'critical_count' => $bucket['critical_count'],
            ];
        })->values()->all();
    }

    /**
     * Build a root-cause breakdown mapped to the KP / CS segments.
     * Each row shows how much undershipped value each reason contributes
     * within each segment.
     */
    private function fillRateSegmentReasonSummary(Request $request, $snapshots, array $salesOrderIds): array
    {
        $customerClasses = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $snapshots->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');

        $segmentByCustomerId = [];
        foreach ($customerClasses as $customerId => $class) {
            $segmentByCustomerId[$customerId] = $this->fillRateCalculator->segmentForCustomerClass($class);
        }

        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $rowsQuery = AcumaticaSalesOrderLine::query()
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'acumatica_sales_order_lines.sales_order_id');

        if ($salesOrderIds !== []) {
            $rowsQuery->whereIn('o.id', $salesOrderIds);
        } else {
            $rowsQuery->whereBetween('o.order_date', [$dateFrom, $dateTo.' 23:59:59']);
        }

        $rows = $rowsQuery->get([
            'acumatica_sales_order_lines.unfilled_reason_code',
            'acumatica_sales_order_lines.qty_at_approval',
            'acumatica_sales_order_lines.order_qty',
            'acumatica_sales_order_lines.shipped_qty',
            'acumatica_sales_order_lines.qty_on_shipments',
            'acumatica_sales_order_lines.unit_price',
            'o.customer_acumatica_id',
        ]);

        $bucketTotals = [FillRateCalculator::SEGMENT_KP => 0.0, FillRateCalculator::SEGMENT_CS => 0.0];
        $acc = [];

        foreach ($rows as $line) {
            $segment = $segmentByCustomerId[$line->customer_acumatica_id] ?? FillRateCalculator::SEGMENT_CS;
            $reason = $line->unfilled_reason_code ?: 'Unassigned';
            $demand = (float) $line->order_qty;
            $value = max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) $line->unit_price;

            if (! isset($acc[$segment][$reason])) {
                $acc[$segment][$reason] = 0.0;
            }
            $acc[$segment][$reason] += $value;
            $bucketTotals[$segment] += $value;
        }

        $result = [];
        foreach ([FillRateCalculator::SEGMENT_KP, FillRateCalculator::SEGMENT_CS] as $segment) {
            $reasons = $acc[$segment] ?? [];
            arsort($reasons);
            $total = $bucketTotals[$segment] ?: 1.0;

            foreach ($reasons as $reason => $value) {
                $result[] = [
                    'segment' => $segment,
                    'reason' => (string) $reason,
                    'undershipped_value' => round((float) $value, 2),
                    'contribution_pct' => round(((float) $value / $total) * 100, 1),
                ];
            }
        }

        return $result;
    }

    private function backordersExcelSummary(Request $request): array
    {
        $filtered = $this->backordersFilteredQuery($request);
        $totalValue = round((float) (clone $filtered)->sum('acumatica_backorder_lines.revenue_at_risk'), 2);

        return [
            'totals' => [
                'back_order_qty' => round((float) (clone $filtered)->sum('acumatica_backorder_lines.open_qty'), 4),
                'back_order_value' => $totalValue,
                'line_count' => (clone $filtered)->count(),
                'order_count' => (clone $filtered)->distinct('acumatica_backorder_lines.order_nbr')->count('acumatica_backorder_lines.order_nbr'),
            ],
            'by_reason' => $this->backordersReasonSummary($request),
            'by_department' => $this->unassignedDepartmentDistribution($totalValue, (int) (clone $filtered)->count(), 'Back Order Value'),
            'by_customer_group' => $this->backordersCustomerGroupDistribution($request),
            'top_customers' => $this->backordersCustomerDistribution($request),
            'top_products' => $this->backordersProductDistribution($request),
            'by_business_category' => $this->backordersBusinessCategorySummary($request),
        ];
    }

    /**
     * SKU breakdown for Manufactured or Trading on the Fill Rate page.
     */
    public function fillRateSkuBreakdown(Request $request): JsonResponse
    {
        $category = $this->validatedBusinessCategory($request);
        $payload = $this->buildFillRateSkuBreakdown($request, $category);

        return response()->json($payload);
    }

    public function exportFillRateSkuBreakdown(Request $request): JsonResponse|StreamedResponse
    {
        $category = $this->validatedBusinessCategory($request);
        $payload = $this->buildFillRateSkuBreakdown($request, $category);
        $skus = $payload['skus'];

        if ($limitResponse = $this->exportLimitResponse(count($skus))) {
            return $limitResponse;
        }

        $label = $this->businessCategory->label($category);
        $spreadsheet = $this->newSpreadsheet("Fill Rate SKUs — {$label}");
        $this->writeSheet($spreadsheet, 'SKU Breakdown', [
            'Inventory ID', 'Product Name', 'Brand', 'Posting Class', 'Sub Trading Group', 'Supplier',
            'Business Category', 'Line Count', 'Order Count', 'Ordered Qty', 'Shipped Qty',
            'Undershipped Qty', 'Undershipped Value (KES)', 'Fill Rate %',
        ], collect($skus)->map(fn (array $row) => [
            $row['inventory_id'],
            $row['product_name'],
            $row['brand'],
            $row['posting_class'],
            $row['sub_trading_group'],
            $row['supplier'],
            $row['business_category_label'],
            $row['line_count'],
            $row['order_count'],
            $row['ordered_qty'],
            $row['shipped_qty'],
            $row['undershipped_qty'],
            $row['undershipped_value'],
            $row['fill_rate_pct'],
        ])->all());

        $safe = str_replace([' ', '/', '\\'], '-', strtolower($label));

        return $this->downloadSpreadsheet(
            $spreadsheet,
            'fill-rate-skus-'.$safe.'-'.now()->format('Ymd-Hi').'.xlsx',
        );
    }

    /**
     * SKU breakdown for Manufactured or Trading on the Backorders page.
     */
    public function backordersSkuBreakdown(Request $request): JsonResponse
    {
        $category = $this->validatedBusinessCategory($request);
        $payload = $this->buildBackordersSkuBreakdown($request, $category);

        return response()->json($payload);
    }

    public function exportBackordersSkuBreakdown(Request $request): JsonResponse|StreamedResponse
    {
        $category = $this->validatedBusinessCategory($request);
        $payload = $this->buildBackordersSkuBreakdown($request, $category);
        $skus = $payload['skus'];

        if ($limitResponse = $this->exportLimitResponse(count($skus))) {
            return $limitResponse;
        }

        $label = $this->businessCategory->label($category);
        $spreadsheet = $this->newSpreadsheet("Backorder SKUs — {$label}");
        $this->writeSheet($spreadsheet, 'SKU Breakdown', [
            'Inventory ID', 'Product Name', 'Brand', 'Posting Class', 'Sub Trading Group', 'Supplier',
            'Business Category', 'Line Count', 'Order Count', 'Open Qty', 'Backorder Value (KES)',
        ], collect($skus)->map(fn (array $row) => [
            $row['inventory_id'],
            $row['product_name'],
            $row['brand'],
            $row['posting_class'],
            $row['sub_trading_group'],
            $row['supplier'],
            $row['business_category_label'],
            $row['line_count'],
            $row['order_count'],
            $row['open_qty'],
            $row['back_order_value'],
        ])->all());

        $safe = str_replace([' ', '/', '\\'], '-', strtolower($label));

        return $this->downloadSpreadsheet(
            $spreadsheet,
            'backorder-skus-'.$safe.'-'.now()->format('Ymd-Hi').'.xlsx',
        );
    }

    private function validatedBusinessCategory(Request $request): string
    {
        $category = strtolower(trim((string) $request->input('business_category', '')));
        if (! in_array($category, [FillRateBusinessCategory::MANUFACTURED, FillRateBusinessCategory::TRADING], true)) {
            abort(422, 'business_category must be manufactured or trading.');
        }

        return $category;
    }

    /**
     * @return array{
     *   business_category: string,
     *   label: string,
     *   sku_count: int,
     *   line_count: int,
     *   order_count: int,
     *   undershipped_value: float,
     *   fill_rate_pct: float|null,
     *   skus: list<array<string, mixed>>
     * }
     */
    private function buildFillRateSkuBreakdown(Request $request, string $category): array
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $salesOrderIds = $this->fillRateFilteredQuery($request, $dateFrom, $dateTo)
            ->pluck('sales_order_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $lines = $this->fillRateShortfallLines($request, $salesOrderIds);
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);
        $inventoryIds = $lines->pluck('inventory_id')->filter()->unique()->values()->all();
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $classifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);

        $grouped = [];
        $orderIds = [];
        $totalOrdered = 0.0;
        $totalShipped = 0.0;
        $totalValue = 0.0;
        $lineCount = 0;

        foreach ($lines as $line) {
            $inventoryId = (string) ($line->inventory_id ?? '');
            if ($inventoryId === '') {
                continue;
            }

            $lineCategory = $this->businessCategory->classify(
                $inventoryId,
                $productTypes[$inventoryId] ?? null,
            );
            if ($lineCategory !== $category) {
                continue;
            }

            $demand = (float) ($line->order_qty ?? 0);
            $shipped = $this->effectiveFillRateShippedQty($line);
            $undershipped = max($demand - $shipped, 0);
            // SKU breakdown focuses on shortfall contribution (same as undershipped value tiles).
            if ($undershipped <= 0) {
                continue;
            }

            $value = $undershipped * (float) ($line->unit_price ?? 0);
            $orderId = (string) ($line->sales_order_id ?? '');

            if (! isset($grouped[$inventoryId])) {
                $classification = $this->catalogResolver->classificationFieldsFor($inventoryId, $classifications);
                $grouped[$inventoryId] = [
                    'inventory_id' => $inventoryId,
                    'product_name' => $this->catalogResolver->resolveProductName(
                        $inventoryId,
                        $line->description ?? null,
                        $descriptions,
                    ),
                    'brand' => $classification['brand'],
                    'posting_class' => $classification['posting_class'],
                    'sub_trading_group' => $classification['sub_trading_group'],
                    'supplier' => $classification['supplier'],
                    'business_category' => $category,
                    'business_category_label' => $this->businessCategory->label($category),
                    'line_count' => 0,
                    'order_ids' => [],
                    'ordered_qty' => 0.0,
                    'shipped_qty' => 0.0,
                    'undershipped_qty' => 0.0,
                    'undershipped_value' => 0.0,
                ];
            }

            $grouped[$inventoryId]['line_count']++;
            $grouped[$inventoryId]['ordered_qty'] += $demand;
            $grouped[$inventoryId]['shipped_qty'] += $shipped;
            $grouped[$inventoryId]['undershipped_qty'] += $undershipped;
            $grouped[$inventoryId]['undershipped_value'] += $value;
            if ($orderId !== '') {
                $grouped[$inventoryId]['order_ids'][$orderId] = true;
                $orderIds[$orderId] = true;
            }

            $lineCount++;
            $totalOrdered += $demand;
            $totalShipped += $shipped;
            $totalValue += $value;
        }

        $skus = collect($grouped)
            ->map(function (array $row) {
                $ordered = $row['ordered_qty'];
                $orderCount = count($row['order_ids']);
                unset($row['order_ids']);

                return array_merge($row, [
                    'order_count' => $orderCount,
                    'ordered_qty' => round($row['ordered_qty'], 4),
                    'shipped_qty' => round($row['shipped_qty'], 4),
                    'undershipped_qty' => round($row['undershipped_qty'], 4),
                    'undershipped_value' => round($row['undershipped_value'], 2),
                    'fill_rate_pct' => $ordered > 0
                        ? round(($row['shipped_qty'] / $ordered) * 1000) / 10
                        : null,
                ]);
            })
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();

        return [
            'business_category' => $category,
            'label' => $this->businessCategory->label($category),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'sku_count' => count($skus),
            'line_count' => $lineCount,
            'order_count' => count($orderIds),
            'undershipped_value' => round($totalValue, 2),
            'fill_rate_pct' => $totalOrdered > 0
                ? round(($totalShipped / $totalOrdered) * 1000) / 10
                : null,
            'skus' => $skus,
        ];
    }

    /**
     * @return array{
     *   business_category: string,
     *   label: string,
     *   sku_count: int,
     *   line_count: int,
     *   order_count: int,
     *   back_order_value: float,
     *   open_qty: float,
     *   skus: list<array<string, mixed>>
     * }
     */
    private function buildBackordersSkuBreakdown(Request $request, string $category): array
    {
        $lines = $this->backordersFilteredQuery($request)
            ->select(['acumatica_backorder_lines.*'])
            ->get();

        $inventoryIds = $lines->pluck('inventory_id')->filter()->unique()->values()->all();
        $productTypes = AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->pluck('product_type', 'inventory_id')
            ->all();
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $classifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);

        $grouped = [];
        $orderNbrs = [];
        $totalValue = 0.0;
        $totalOpen = 0.0;
        $lineCount = 0;

        foreach ($lines as $line) {
            $inventoryId = (string) ($line->inventory_id ?? '');
            if ($inventoryId === '') {
                continue;
            }

            $lineCategory = $this->businessCategory->classify(
                $inventoryId,
                $productTypes[$inventoryId] ?? null,
            );
            if ($lineCategory !== $category) {
                continue;
            }

            $openQty = (float) ($line->open_qty ?? $line->backorder_qty ?? 0);
            $value = (float) ($line->revenue_at_risk ?? 0);
            $orderNbr = (string) ($line->order_nbr ?? '');

            if (! isset($grouped[$inventoryId])) {
                $classification = $this->catalogResolver->classificationFieldsFor($inventoryId, $classifications);
                $grouped[$inventoryId] = [
                    'inventory_id' => $inventoryId,
                    'product_name' => $this->catalogResolver->resolveProductName(
                        $inventoryId,
                        null,
                        $descriptions,
                    ),
                    'brand' => $classification['brand'],
                    'posting_class' => $classification['posting_class'],
                    'sub_trading_group' => $classification['sub_trading_group'],
                    'supplier' => $classification['supplier'],
                    'business_category' => $category,
                    'business_category_label' => $this->businessCategory->label($category),
                    'line_count' => 0,
                    'order_nbrs' => [],
                    'open_qty' => 0.0,
                    'back_order_value' => 0.0,
                ];
            }

            $grouped[$inventoryId]['line_count']++;
            $grouped[$inventoryId]['open_qty'] += $openQty;
            $grouped[$inventoryId]['back_order_value'] += $value;
            if ($orderNbr !== '') {
                $grouped[$inventoryId]['order_nbrs'][$orderNbr] = true;
                $orderNbrs[$orderNbr] = true;
            }

            $lineCount++;
            $totalOpen += $openQty;
            $totalValue += $value;
        }

        $skus = collect($grouped)
            ->map(function (array $row) {
                $orderCount = count($row['order_nbrs']);
                unset($row['order_nbrs']);

                return array_merge($row, [
                    'order_count' => $orderCount,
                    'open_qty' => round($row['open_qty'], 4),
                    'back_order_value' => round($row['back_order_value'], 2),
                ]);
            })
            ->sortByDesc('back_order_value')
            ->values()
            ->all();

        return [
            'business_category' => $category,
            'label' => $this->businessCategory->label($category),
            'sku_count' => count($skus),
            'line_count' => $lineCount,
            'order_count' => count($orderNbrs),
            'open_qty' => round($totalOpen, 4),
            'back_order_value' => round($totalValue, 2),
            'skus' => $skus,
        ];
    }

    private function backordersBusinessCategorySummary(Request $request): array
    {
        $lines = $this->backordersFilteredQuery($request)
            ->select([
                'acumatica_backorder_lines.inventory_id',
                'acumatica_backorder_lines.order_nbr',
                'acumatica_backorder_lines.open_qty',
                'acumatica_backorder_lines.revenue_at_risk',
            ])
            ->get();

        $inventoryIds = $lines->pluck('inventory_id')->filter()->unique()->values()->all();
        $productTypes = AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->pluck('product_type', 'inventory_id')
            ->all();

        $buckets = [
            FillRateBusinessCategory::MANUFACTURED => [
                'business_category' => FillRateBusinessCategory::MANUFACTURED,
                'label' => FillRateBusinessCategory::LABEL_MANUFACTURED,
                'line_count' => 0,
                'order_count' => 0,
                'open_qty' => 0.0,
                'back_order_value' => 0.0,
                'orders' => [],
            ],
            FillRateBusinessCategory::TRADING => [
                'business_category' => FillRateBusinessCategory::TRADING,
                'label' => FillRateBusinessCategory::LABEL_TRADING,
                'line_count' => 0,
                'order_count' => 0,
                'open_qty' => 0.0,
                'back_order_value' => 0.0,
                'orders' => [],
            ],
        ];

        foreach ($lines as $line) {
            $inventoryId = (string) ($line->inventory_id ?? '');
            $category = $this->businessCategory->classify(
                $inventoryId,
                $productTypes[$inventoryId] ?? null,
            );
            $buckets[$category]['line_count']++;
            $buckets[$category]['open_qty'] += (float) ($line->open_qty ?? 0);
            $buckets[$category]['back_order_value'] += (float) ($line->revenue_at_risk ?? 0);
            $orderNbr = (string) ($line->order_nbr ?? '');
            if ($orderNbr !== '') {
                $buckets[$category]['orders'][$orderNbr] = true;
            }
        }

        return collect($buckets)->map(function (array $bucket) {
            $bucket['order_count'] = count($bucket['orders']);
            unset($bucket['orders']);
            $bucket['open_qty'] = round($bucket['open_qty'], 4);
            $bucket['back_order_value'] = round($bucket['back_order_value'], 2);

            return $bucket;
        })->values()->all();
    }

    private function fillRateReasonSummary(Request $request, array $salesOrderIds = []): array
    {
        $dateFrom = $request->input('date_from', now()->startOfMonth()->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());

        $rowsQuery = AcumaticaSalesOrderLine::query()
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'acumatica_sales_order_lines.sales_order_id');

        if ($salesOrderIds !== []) {
            $rowsQuery->whereIn('o.id', $salesOrderIds);
        } else {
            $rowsQuery->whereBetween('o.order_date', [$dateFrom, $dateTo.' 23:59:59']);
        }

        $rows = $rowsQuery->get([
            'acumatica_sales_order_lines.unfilled_reason_code',
            'acumatica_sales_order_lines.qty_at_approval',
            'acumatica_sales_order_lines.order_qty',
            'acumatica_sales_order_lines.shipped_qty',
            'acumatica_sales_order_lines.qty_on_shipments',
            'acumatica_sales_order_lines.unit_price',
        ]);

        $total = $rows->sum(function ($line) {
            $demand = (float) $line->order_qty;
            return max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) $line->unit_price;
        });

        return $rows
            ->groupBy(fn ($line) => $line->unfilled_reason_code ?: 'Unassigned')
            ->map(function ($group, $reason) use ($total) {
                $value = $group->sum(function ($line) {
                    $demand = (float) $line->order_qty;
                    return max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) $line->unit_price;
                });

                return [
                    'reason' => (string) $reason,
                    'line_count' => $group->count(),
                    'undershipped_value' => round((float) $value, 2),
                    'contribution_pct' => $total > 0 ? round(((float) $value / (float) $total) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();
    }

    private function fillRateCustomerGroupSummary(Request $request, $snapshots = null): array
    {
        $snapshots ??= $this->fillRateFilteredQuery($request)->get();
        $customerClasses = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $snapshots->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');
        $total = (float) $snapshots->sum('revenue_not_shipped');

        return $snapshots
            ->groupBy(fn ($row) => $customerClasses[$row->customer_acumatica_id] ?? 'Unassigned')
            ->map(fn ($group, $label) => [
                'customer_group' => (string) $label,
                'order_count' => $group->count(),
                'undershipped_value' => round((float) $group->sum('revenue_not_shipped'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_not_shipped') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();
    }

    private function fillRateTopCustomers(Request $request, $snapshots = null): array
    {
        $snapshots ??= $this->fillRateFilteredQuery($request)->get();
        $names = $this->catalogResolver->namesForCustomerIds($snapshots->pluck('customer_acumatica_id')->all());
        $total = (float) $snapshots->sum('revenue_not_shipped');

        return $snapshots
            ->groupBy('customer_acumatica_id')
            ->map(fn ($group, $customerId) => [
                'customer_id' => $customerId ?: 'Unassigned',
                'customer_name' => $this->catalogResolver->resolveCustomerName(null, $customerId, $names),
                'order_count' => $group->count(),
                'undershipped_value' => round((float) $group->sum('revenue_not_shipped'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_not_shipped') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('undershipped_value')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Full product roll-up by InventoryID (not top-N). Used by Excel Product Summary so
     * the sheet total reconciles to period undershipped value (100M+ for June-scale data).
     *
     * @param  \Illuminate\Support\Collection<int, object>  $lines
     * @return list<array<string, mixed>>
     */
    private function fillRateProductSummaryFromLines($lines): array
    {
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($lines->pluck('inventory_id')->all());
        $total = $lines->sum(function ($line) {
            $demand = (float) $line->order_qty;

            return max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) $line->unit_price;
        });

        return $lines
            ->groupBy('inventory_id')
            ->map(function ($group, $inventoryId) use ($descriptions, $total) {
                $value = $group->sum(function ($line) {
                    $demand = (float) $line->order_qty;

                    return max($demand - $this->effectiveFillRateShippedQty($line), 0) * (float) $line->unit_price;
                });

                return [
                    'inventory_id' => $inventoryId ?: 'Unassigned',
                    'product_name' => $this->catalogResolver->resolveProductName($inventoryId, $group->first()?->description, $descriptions),
                    'line_count' => $group->count(),
                    'undershipped_value' => round((float) $value, 2),
                    'contribution_pct' => $total > 0 ? round(((float) $value / (float) $total) * 100, 1) : 0.0,
                ];
            })
            // Keep every InventoryID with shortfall so Product Summary sums to full period value.
            ->filter(fn (array $row) => $row['undershipped_value'] > 0)
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();
    }

    private function backordersReasonSummary(Request $request): array
    {
        $rows = $this->backordersFilteredQuery($request)
            ->select([
                'acumatica_backorder_lines.reason_code',
                'acumatica_backorder_lines.open_qty',
                'acumatica_backorder_lines.revenue_at_risk',
                DB::raw($this->backorderSoLineReasonSubquery().' as so_line_reason_code'),
            ])
            ->get();
        $total = (float) $rows->sum('revenue_at_risk');

        return $rows
            ->groupBy(function ($row) {
                return $this->effectiveBackorderReasonCode(
                    $row->reason_code ?? null,
                    $row->so_line_reason_code ?? null,
                ) ?: 'Unassigned';
            })
            ->map(fn ($group, $label) => [
                'reason' => (string) $label,
                'line_count' => $group->count(),
                'back_order_qty' => round((float) $group->sum('open_qty'), 4),
                'back_order_value' => round((float) $group->sum('revenue_at_risk'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_at_risk') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('back_order_value')
            ->values()
            ->all();
    }

    /**
     * Latest non-empty unfilled_reason_code from the matching SO line (order + inventory).
     */
    private function backorderSoLineReasonSubquery(): string
    {
        return '(SELECT sol.unfilled_reason_code
            FROM acumatica_sales_order_lines sol
            INNER JOIN acumatica_sales_orders so_r ON so_r.id = sol.sales_order_id
            WHERE so_r.acumatica_order_nbr = acumatica_backorder_lines.order_nbr
              AND sol.inventory_id = acumatica_backorder_lines.inventory_id
              AND sol.unfilled_reason_code IS NOT NULL
              AND TRIM(sol.unfilled_reason_code) != \'\'
            ORDER BY sol.id DESC
            LIMIT 1)';
    }

    private function effectiveBackorderReasonCode(mixed $stored, mixed $soLine): ?string
    {
        $stored = is_string($stored) ? trim($stored) : '';
        $soLine = is_string($soLine) ? trim($soLine) : '';
        if ($stored !== '' && strcasecmp($stored, 'unassigned') !== 0) {
            return $stored;
        }
        if ($soLine !== '' && strcasecmp($soLine, 'unassigned') !== 0) {
            return $soLine;
        }

        return null;
    }

    private function backordersCustomerGroupDistribution(Request $request): array
    {
        $rows = $this->backordersFilteredQuery($request)->get([
            'acumatica_backorder_lines.customer_acumatica_id',
            'acumatica_backorder_lines.open_qty',
            'acumatica_backorder_lines.revenue_at_risk',
        ]);
        $classes = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $rows->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');
        $total = (float) $rows->sum('revenue_at_risk');

        return $rows
            ->groupBy(fn ($row) => $classes[$row->customer_acumatica_id] ?? 'Unassigned')
            ->map(fn ($group, $label) => [
                'customer_group' => (string) $label,
                'line_count' => $group->count(),
                'back_order_qty' => round((float) $group->sum('open_qty'), 4),
                'back_order_value' => round((float) $group->sum('revenue_at_risk'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_at_risk') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('back_order_value')
            ->values()
            ->all();
    }

    private function backordersCustomerDistribution(Request $request): array
    {
        $rows = $this->backordersFilteredQuery($request)->get([
            'acumatica_backorder_lines.customer_acumatica_id',
            'acumatica_backorder_lines.customer_name',
            'acumatica_backorder_lines.order_nbr',
            'acumatica_backorder_lines.open_qty',
            'acumatica_backorder_lines.revenue_at_risk',
        ]);
        $names = $this->catalogResolver->namesForCustomerIds($rows->pluck('customer_acumatica_id')->all());
        $total = (float) $rows->sum('revenue_at_risk');

        return $rows
            ->groupBy('customer_acumatica_id')
            ->map(fn ($group, $customerId) => [
                'customer_id' => $customerId ?: 'Unassigned',
                'customer_name' => $this->catalogResolver->resolveCustomerName($group->first()?->customer_name, $customerId, $names),
                'order_count' => $group->pluck('order_nbr')->unique()->count(),
                'line_count' => $group->count(),
                'back_order_value' => round((float) $group->sum('revenue_at_risk'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_at_risk') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('back_order_value')
            ->values()
            ->all();
    }

    /**
     * Full product roll-up by InventoryID (not top-N). Export Product Summary must include
     * every SKU so back-order value reconciles to the period total (100M+ at current scale).
     */
    private function backordersProductDistribution(Request $request): array
    {
        $rows = $this->backordersFilteredQuery($request)->get([
            'acumatica_backorder_lines.inventory_id',
            'acumatica_backorder_lines.open_qty',
            'acumatica_backorder_lines.revenue_at_risk',
        ]);
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($rows->pluck('inventory_id')->all());
        $total = (float) $rows->sum('revenue_at_risk');

        return $rows
            ->groupBy('inventory_id')
            ->map(fn ($group, $inventoryId) => [
                'inventory_id' => $inventoryId ?: 'Unassigned',
                'product_name' => $this->catalogResolver->resolveProductName($inventoryId, null, $descriptions),
                'line_count' => $group->count(),
                'back_order_qty' => round((float) $group->sum('open_qty'), 4),
                'back_order_value' => round((float) $group->sum('revenue_at_risk'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_at_risk') / $total) * 100, 1) : 0.0,
            ])
            ->filter(fn (array $row) => $row['back_order_value'] > 0 || $row['back_order_qty'] > 0)
            ->sortByDesc('back_order_value')
            ->values()
            ->all();
    }

    private function unassignedDepartmentDistribution(float $value, int $count, string $valueLabel): array
    {
        if ($value <= 0 && $count === 0) {
            return [];
        }

        return [[
            'department' => 'Unassigned',
            'line_count' => $count,
            'value_label' => $valueLabel,
            'value' => round($value, 2),
            'contribution_pct' => $value > 0 ? 100.0 : 0.0,
        ]];
    }

    private function contributionRows($groups, string $labelKey, string $valueKey, callable $valueCallback, callable $countCallback): array
    {
        $total = (float) collect($groups)->sum(fn ($group) => $valueCallback($group));

        return collect($groups)
            ->map(fn ($group, $label) => [
                $labelKey => (string) ($label ?: 'Unassigned'),
                'count' => $countCallback($group),
                $valueKey => round((float) $valueCallback($group), 2),
                'contribution_pct' => $total > 0 ? round(((float) $valueCallback($group) / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc($valueKey)
            ->values()
            ->all();
    }

    private function exportLimitResponse(int $count): ?JsonResponse
    {
        if ($count <= self::EXPORT_LIMIT) {
            return null;
        }

        return response()->json([
            'message' => 'Export is too large. Narrow your filters and try again.',
            'limit' => self::EXPORT_LIMIT,
            'matched_rows' => $count,
        ], 422);
    }

    private function fillRateInteractiveExportLimitResponse(int $count): ?JsonResponse
    {
        if ($count <= self::FILL_RATE_INTERACTIVE_EXPORT_LIMIT) {
            return null;
        }

        return response()->json([
            'message' => 'Fill rate export is too large for an interactive download ('.$count.' orders). Narrow the date range or filters and try again (recommended under '.number_format(self::FILL_RATE_INTERACTIVE_EXPORT_LIMIT).' orders).',
            'limit' => self::FILL_RATE_INTERACTIVE_EXPORT_LIMIT,
            'matched_rows' => $count,
        ], 422);
    }

    private function newSpreadsheet(string $title): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Kim-Fay OrderWatch')
            ->setTitle($title);

        return $spreadsheet;
    }

    /** @param array<int, string> $headers @param array<int, array<int, mixed>> $rows */
    private function writeSheet(Spreadsheet $spreadsheet, string $title, array $headers, array $rows): void
    {
        $sheet = $spreadsheet->getSheetCount() === 1 && $spreadsheet->getActiveSheet()->getHighestRow() === 1 && $spreadsheet->getActiveSheet()->getCell('A1')->getValue() === null
            ? $spreadsheet->getActiveSheet()
            : $spreadsheet->createSheet();

        $sheet->setTitle(substr($title, 0, 31));
        $sheet->fromArray($headers, null, 'A1');

        if ($rows !== []) {
            $sheet->fromArray($rows, null, 'A2');
        }

        $highestColumn = $sheet->getHighestColumn();
        $sheet->getStyle("A1:{$highestColumn}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$highestColumn}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()
            ->setARGB('FFE5E7EB');
        $sheet->freezePane('A2');
        $sheet->setAutoFilter($sheet->calculateWorksheetDimension());

        for ($column = 1, $max = Coordinate::columnIndexFromString($highestColumn); $column <= $max; $column++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }
    }

    /** @param array<int, array<string, mixed>> $rows @param array<string, string> $columns */
    private function writeContributionSheet(Spreadsheet $spreadsheet, string $title, array $rows, array $columns): void
    {
        $this->writeSheet(
            $spreadsheet,
            $title,
            array_values($columns),
            collect($rows)
                ->map(fn (array $row) => collect(array_keys($columns))
                    ->map(fn (string $key) => $row[$key] ?? null)
                    ->all())
                ->all(),
        );
    }

    private function downloadSpreadsheet(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function dateString(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return (string) $value;
    }

    private function reasonDisplay(?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return 'Unassigned';
        }

        $resolved = $this->reasonCatalog->resolveSubReason($code);

        return $resolved !== null
            ? $this->reasonCatalog->subReasonLabel($resolved)
            : $this->reasonCatalog->formatLabel($code);
    }

    /**
     * Date used for range filters on the Backorders page.
     *
     * Prefer sales-order order_date so cumulative KPIs and the line table match
     * Order / Invoice / Backorder value cards (also filtered by aso.order_date)
     * and the business formula: Back order = OrderTotal − InvoiceTotal for SOs
     * in the selected period.
     */
    private function backorderTimelineDateExpression(): string
    {
        return "COALESCE(DATE(aso.order_date), acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(acumatica_backorder_lines.synced_at))";
    }

    private function backorderLeadTimeDaysExpression(): string
    {
        $daysDiffExpression = $this->daysDiffExpression(
            'acumatica_backorder_lines.requested_on',
            'DATE(aso.order_date)'
        );
        $scheduledDaysDiffExpression = $this->daysDiffExpression(
            'acumatica_backorder_lines.scheduled_shipment_date',
            'DATE(aso.order_date)'
        );

        return "CASE
            WHEN aso.order_date is not null and acumatica_backorder_lines.requested_on is not null
                THEN {$daysDiffExpression}
            WHEN aso.order_date is not null and acumatica_backorder_lines.scheduled_shipment_date is not null
                THEN {$scheduledDaysDiffExpression}
            ELSE null
        END";
    }

    private function backorderLeadTimeBucketExpression(string $leadTimeExpr): string
    {
        return "CASE
            WHEN {$leadTimeExpr} <= 2 THEN '0-2 days'
            WHEN {$leadTimeExpr} <= 5 THEN '3-5 days'
            WHEN {$leadTimeExpr} <= 10 THEN '6-10 days'
            WHEN {$leadTimeExpr} <= 15 THEN '11-15 days'
            ELSE '16+ days'
        END";
    }

    private function daysDiffExpression(string $endDateExpression, string $startDateExpression): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(julianday({$endDateExpression}) - julianday({$startDateExpression}) AS INTEGER)"
            : "DATEDIFF({$endDateExpression}, {$startDateExpression})";
    }

    private function applyDepartmentPortfolioScope(
        Builder $query,
        Request $request,
        string $customerColumn = 'customer_acumatica_id',
    ): void {
        if (! DepartmentScope::appliesTo($request->user())) {
            return;
        }

        $customerQuery = AcumaticaCustomer::query()->select('acumatica_id');
        DataScope::applyCustomerScope($customerQuery, $request->user());
        $ids = $customerQuery->pluck('acumatica_id');

        if ($ids->isEmpty()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($customerColumn, $ids);
    }

    private function applyBrandFilterToFillRateQuery(Builder $query, Request $request): void
    {
        $ids = app(BrandFilterService::class)->inventoryIdsMatching(
            $request->input('partner_brand'),
            $request->input('brand'),
            $request->input('category'),
        );

        $ids = app(BrandAssignmentScope::class)->intersectInventoryIds($request->user(), $ids);

        if ($ids === null) {
            return;
        }

        if ($ids === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('order.lines', fn ($lineQuery) => $lineQuery->whereIn('inventory_id', $ids));
    }

    private function applyBrandFilterToBackorderQuery(
        Builder $query,
        Request $request,
        string $inventoryColumn = 'acumatica_backorder_lines.inventory_id',
    ): void {
        $partnerBrand = $request->input('partner_brand');
        $brand = $request->input('brand');
        $category = $request->input('category');

        // Prefix-aware filter: Aptamil (APT*) / Cow & Gate (COW*) still match when
        // the inventory master is empty or missing brand labels.
        app(BrandFilterService::class)->applyToInventoryIdColumn(
            $query,
            $inventoryColumn,
            is_string($partnerBrand) ? $partnerBrand : null,
            is_string($brand) ? $brand : null,
            is_string($category) ? $category : null,
        );

        // Optional user brand-assignment ceiling (Partner Brand teams, etc.).
        $userIds = app(BrandAssignmentScope::class)->inventoryIdsForUser($request->user());
        if ($userIds === null) {
            return;
        }
        if ($userIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }
        $query->whereIn($inventoryColumn, $userIds);
    }

    /**
     * Filtered, paginated query over resolved backorder lines (archived at prune time).
     * Deliberately a separate query builder from backordersFilteredQuery(): resolved lines
     * live in a different table with a different shape (no open_qty/current status — a
     * resolution is a closed, historical fact).
     *
     * date_from/date_to filter on resolved_at (when it cleared) by default. opened_from/
     * opened_to filter on first_backordered_at (when it started) independently — a line
     * that opened in June and resolved in July is visible under either filter on its own
     * terms; there is no single "owning" month.
     */
    private function backorderResolutionsFilteredQuery(Request $request): Builder
    {
        $query = BackorderResolution::query();

        if ($q = trim((string) $request->input('q', ''))) {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';
            $query->where(function (Builder $sub) use ($like) {
                $sub->where('order_nbr', 'like', $like)
                    ->orWhere('inventory_id', 'like', $like)
                    ->orWhere('customer_name', 'like', $like)
                    ->orWhere('customer_acumatica_id', 'like', $like);
            });
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('customer_acumatica_id', $customerId);
        }

        if ($warehouseId = $request->input('warehouse_id')) {
            $query->where('warehouse_id', $warehouseId);
        }

        if ($reasonCode = $request->input('reason_code')) {
            if ($reasonCode === 'unassigned') {
                $query->where(function (Builder $sub) {
                    $sub->whereNull('reason_code')->orWhere('reason_code', '');
                });
            } else {
                $query->where('reason_code', $reasonCode);
            }
        }

        // Explicit rep filter: assignment ∪ SO rep-code (not SO rep alone — FR8 fairness).
        if ($repCode = $request->input('rep_code')) {
            $this->applyRepPortfolioCustomerFilter($query, (string) $repCode, 'backorder_resolutions.customer_acumatica_id');
        }

        $this->applyBrandFilterToBackorderQuery($query, $request, 'backorder_resolutions.inventory_id');
        $this->applyDepartmentPortfolioScope($query, $request, 'backorder_resolutions.customer_acumatica_id');

        $visibleCustomerIds = DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($visibleCustomerIds !== null) {
            if ($visibleCustomerIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('backorder_resolutions.customer_acumatica_id', $visibleCustomerIds);
            }
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('resolved_at', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('resolved_at', '<=', $dateTo);
        }

        if ($openedFrom = $request->input('opened_from')) {
            $query->whereDate('first_backordered_at', '>=', $openedFrom);
        }
        if ($openedTo = $request->input('opened_to')) {
            $query->whereDate('first_backordered_at', '<=', $openedTo);
        }

        return $query;
    }

    public function backordersResolved(Request $request): JsonResponse
    {
        $query = $this->backorderResolutionsFilteredQuery($request)
            ->orderByDesc('resolved_at');

        $paginated = $query->paginate($request->integer('per_page', 50));
        $items = $paginated->getCollection();

        $inventoryIds = $items->pluck('inventory_id')->all();
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $classifications = $this->catalogResolver->classificationsForInventoryIds($inventoryIds);
        $customerNames = $this->catalogResolver->namesForCustomerIds(
            $items->pluck('customer_acumatica_id')->all(),
        );

        $items->transform(function (BackorderResolution $row) use ($descriptions, $classifications, $customerNames) {
            $row->product_name = $this->catalogResolver->resolveProductName($row->inventory_id, null, $descriptions);
            foreach ($this->catalogResolver->classificationFieldsFor($row->inventory_id, $classifications) as $field => $value) {
                $row->{$field} = $value;
            }
            $row->customer_name = $this->catalogResolver->resolveCustomerName(
                $row->customer_name,
                $row->customer_acumatica_id,
                $customerNames,
            );

            return $row;
        });

        return response()->json($paginated);
    }
}
