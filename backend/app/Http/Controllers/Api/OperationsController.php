<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaShippingZone;
use App\Services\Admin\FillRateCalculator;
use App\Services\Admin\InventoryRunRatePredictor;
use App\Services\Operations\BusinessOptimizationService;
use App\Services\Operations\DeliverySlaEvaluator;
use App\Services\Operations\FillRateBusinessCategory;
use App\Services\Operations\FillRateExcelExporter;
use App\Services\Operations\FillRateReasonCaptureReport;
use App\Services\Operations\OperationsCatalogResolver;
use App\Services\Operations\SalesOrderReasonCatalog;
use App\Services\Operations\SalesOrderReasonTaxonomyService;
use App\Services\Operations\SoReasonAuditService;
use App\Support\DataScope;
use App\Support\DepartmentScope;
use App\Support\SalesConsultantScope;
use App\Services\Team\BrandAssignmentScope;
use App\Services\Team\BrandFilterService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly FillRateCalculator $fillRateCalculator,
        private readonly InventoryRunRatePredictor $predictor,
        private readonly OperationsCatalogResolver $catalogResolver,
        private readonly BusinessOptimizationService $optimization,
        private readonly DeliverySlaEvaluator $deliverySla,
        private readonly FillRateExcelExporter $fillRateExporter,
        private readonly FillRateReasonCaptureReport $reasonCaptureReport,
        private readonly FillRateBusinessCategory $businessCategory,
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
        $lowStock   = AcumaticaInventoryItem::where('qty_on_hand', '<=', 10)->count();
        $critical   = AcumaticaInventoryRunRateLog::whereIn('prediction_status', ['critical', 'at_risk'])
            ->where('logged_at', '>=', now()->subDay())
            ->distinct('inventory_item_id')
            ->count('inventory_item_id');

        $outOfStockCount = AcumaticaInventoryItem::where('qty_on_hand', '<=', 0)->count();
        $criticalPredictionIds = $this->recentPredictionItemIds(['critical']);
        $criticalStockoutCount = AcumaticaInventoryItem::query()
            ->where(function ($q) use ($criticalPredictionIds) {
                $q->where('qty_on_hand', '<=', 0);
                if ($criticalPredictionIds->isNotEmpty()) {
                    $q->orWhereIn('id', $criticalPredictionIds);
                }
            })
            ->count();

        $dbWarehouseCounts = AcumaticaInventoryItem::query()
            ->whereNotNull('default_warehouse_id')
            ->selectRaw('default_warehouse_id, COUNT(*) as sku_count')
            ->groupBy('default_warehouse_id')
            ->orderBy('default_warehouse_id')
            ->get()
            ->mapWithKeys(fn ($row) => [
                (string) $row->default_warehouse_id => (int) $row->sku_count,
            ]);

        // Acumatica warehouse list from config, merged with any extra warehouses seen in synced data.
        $configuredWarehouses = collect(config('inventory.warehouses', []))
            ->map(fn ($id) => strtoupper(trim((string) $id)))
            ->filter()
            ->values();
        $labels = config('inventory.warehouse_labels', []);
        $allWarehouseIds = $configuredWarehouses
            ->merge($dbWarehouseCounts->keys())
            ->unique()
            ->values();

        $warehouseCounts = $allWarehouseIds->map(fn (string $id) => [
            'warehouse_id' => $id,
            'label'        => (string) ($labels[$id] ?? $id),
            'sku_count'    => (int) ($dbWarehouseCounts[$id] ?? 0),
            'configured'   => $configuredWarehouses->contains($id),
        ])->values();

        return response()->json([
            'total_items'      => $totalItems,
            'low_stock_count'  => $lowStock,
            'at_risk_count'    => $critical,
            'out_of_stock_count' => $outOfStockCount,
            'critical_stockout_count' => $criticalStockoutCount,
            'last_synced_at'   => AcumaticaInventoryItem::max('synced_at'),
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
            $query->orderBy('qty_on_hand')->orderBy('inventory_id');
        } else {
            $query->orderByDesc('synced_at');
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
        $lines = $this->backordersScopedLinesQuery($request);

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
        $query = $this->backordersFilteredQuery($request)
            ->orderByDesc('acumatica_backorder_lines.revenue_at_risk')
            ->select([
                'acumatica_backorder_lines.*',
                DB::raw('ai.item_class as product_line'),
                DB::raw($this->backorderLeadTimeDaysExpression().' as lead_time_days'),
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

            $stock = $inventoryStock->get($line->inventory_id);
            $line->qty_on_hand = $stock['qty_on_hand'] ?? null;
            $line->qty_available = $stock['qty_available'] ?? null;
            $line->stock_shortfall = $stock !== null
                && (float) ($stock['qty_on_hand'] ?? 0) < (float) $line->open_qty;

            return $line;
        });

        return response()->json($paginated);
    }

    public function exportBackorders(Request $request): JsonResponse|StreamedResponse
    {
        $query = $this->backordersFilteredQuery($request)
            ->orderByDesc('acumatica_backorder_lines.revenue_at_risk')
            ->select([
                'acumatica_backorder_lines.*',
                DB::raw('ai.item_class as product_line'),
                DB::raw($this->backorderLeadTimeDaysExpression().' as lead_time_days'),
            ]);

        $count = (clone $query)->count();
        if ($limitResponse = $this->exportLimitResponse($count)) {
            return $limitResponse;
        }

        $lines = $query->get();
        $inventoryDescriptions = $this->catalogResolver->descriptionsForInventoryIds($lines->pluck('inventory_id')->all());
        $inventoryStock = $this->catalogResolver->stockForInventoryIds($lines->pluck('inventory_id')->all());
        $customerNames = $this->catalogResolver->namesForCustomerIds($lines->pluck('customer_acumatica_id')->all());
        $spreadsheet = $this->newSpreadsheet('Backorders Export');

        $this->writeSheet($spreadsheet, 'Backorders', [
            'Order', 'Customer ID', 'Customer Name', 'Inventory ID', 'Product Name', 'Product Line', 'Warehouse',
            'UOM', 'Order Qty', 'Shipped Qty', 'Open Qty', 'Backorder Qty', 'Cancelled Qty', 'Qty At Approval',
            'Qty On Hand', 'Qty Available', 'Stock Shortfall', 'Unit Price', 'Revenue At Risk', 'Lead Time Days',
            'Fulfillment Status', 'Reason Code', 'Reason', 'Reason Notes', 'Reason Updated At', 'Currency', 'Synced At',
        ], $lines->map(function ($line) use ($inventoryDescriptions, $inventoryStock, $customerNames) {
            $stock = $inventoryStock->get($line->inventory_id);
            $productName = $this->catalogResolver->resolveProductName($line->inventory_id, null, $inventoryDescriptions);
            $customerName = $this->catalogResolver->resolveCustomerName($line->customer_name, $line->customer_acumatica_id, $customerNames);
            $uom = $this->catalogResolver->resolveUom($line->uom, $line->inventory_id, $inventoryStock);
            $stockShortfall = $stock !== null && (float) ($stock['qty_on_hand'] ?? 0) < (float) $line->open_qty;

            return [
                $line->order_nbr,
                $line->customer_acumatica_id,
                $customerName,
                $line->inventory_id,
                $productName,
                $line->product_line,
                $line->warehouse_id,
                $uom,
                (float) $line->order_qty,
                (float) $line->shipped_qty,
                (float) $line->open_qty,
                (float) $line->backorder_qty,
                (float) $line->cancelled_qty,
                $line->qty_at_approval !== null ? (float) $line->qty_at_approval : null,
                $stock['qty_on_hand'] ?? null,
                $stock['qty_available'] ?? null,
                $stockShortfall ? 'Yes' : 'No',
                (float) $line->unit_price,
                (float) $line->revenue_at_risk,
                $line->lead_time_days !== null ? (float) $line->lead_time_days : null,
                $line->fulfillment_status,
                $line->reason_code ?: 'unassigned',
                $this->reasonDisplay($line->reason_code),
                $line->reason_notes,
                $this->dateString($line->reason_updated_at),
                $line->currency_id,
                $this->dateString($line->synced_at),
            ];
        })->all());

        $this->writeContributionSheet($spreadsheet, 'Reason Summary', $this->backordersReasonSummary($request), [
            'reason' => 'Reason',
            'line_count' => 'Line Count',
            'back_order_qty' => 'Backorder Qty',
            'back_order_value' => 'Backorder Value',
            'contribution_pct' => 'Contribution %',
        ]);
        $this->writeContributionSheet($spreadsheet, 'Customer Summary', $this->backordersCustomerDistribution($request), [
            'customer_id' => 'Customer ID',
            'customer_name' => 'Customer Name',
            'order_count' => 'Order Count',
            'line_count' => 'Line Count',
            'back_order_value' => 'Backorder Value',
            'contribution_pct' => 'Contribution %',
        ]);
        $this->writeContributionSheet($spreadsheet, 'Product Summary', $this->backordersProductDistribution($request), [
            'inventory_id' => 'Inventory ID',
            'product_name' => 'Product Name',
            'line_count' => 'Line Count',
            'back_order_qty' => 'Backorder Qty',
            'back_order_value' => 'Backorder Value',
            'contribution_pct' => 'Contribution %',
        ]);

        return $this->downloadSpreadsheet($spreadsheet, 'backorders-export-'.now()->format('Ymd-Hi').'.xlsx');
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

        $reasonDistribution = $this->backordersFilteredQuery($request)
            ->select([
                DB::raw("COALESCE(acumatica_backorder_lines.reason_code, 'unassigned') as reason_code"),
                DB::raw('COUNT(*) as line_count'),
                DB::raw('SUM(acumatica_backorder_lines.revenue_at_risk) as revenue_at_risk'),
            ])
            ->groupBy('reason_code')
            ->orderByDesc('line_count')
            ->get();

        $filtered = $this->backordersFilteredQuery($request);

        return response()->json([
            'summary' => [
                'open_lines' => (clone $filtered)->count(),
                'open_orders' => (clone $filtered)->distinct('acumatica_backorder_lines.order_nbr')->count('acumatica_backorder_lines.order_nbr'),
                'revenue_at_risk' => round((float) (clone $filtered)->sum('acumatica_backorder_lines.revenue_at_risk'), 2),
                'total_open_qty' => round((float) (clone $filtered)->sum('acumatica_backorder_lines.open_qty'), 4),
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

        return response()->json($backorderLine->fresh());
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
                    $shippedQty = (float) (($line->shipped_qty ?? 0) > 0 ? $line->shipped_qty : $line->qty_on_shipments);
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
        $query = $this->fillRateFilteredQuery($request)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date',
                'order.customer:acumatica_id,shipping_zone_id',
                'order.customer.shippingZone:acumatica_id,description,name,region',
                'order.lines:id,sales_order_id,inventory_id,description,order_qty,shipped_qty,qty_on_shipments,open_qty,unit_price,uom,fill_rate_pct,qty_at_approval,unfilled_reason_code',
            ]);

        $this->applyFillRateSearch($query, $request);

        $sort = $request->input('sort', 'high_to_low');
        if ($sort === 'low_to_high') {
            $query->orderByRaw('fill_rate_pct IS NULL')->orderBy('fill_rate_pct');
        } else {
            $query->orderByRaw('fill_rate_pct IS NULL')->orderByDesc('fill_rate_pct');
        }

        if ($request->filled('delivery_sla')) {
            $deliverySla = $request->input('delivery_sla');
            if (! in_array($deliverySla, ['breach', 'warning'], true)) {
                return response()->json(['message' => 'Invalid delivery_sla filter.'], 422);
            }
            $snapshots = $query->get()
                ->filter(fn (AcumaticaFillRateSnapshot $snapshot) => $this->deliverySlaForSnapshot($snapshot)['delivery_sla_status'] === $deliverySla)
                ->values();
            if ($limitResponse = $this->exportLimitResponse($snapshots->count())) {
                return $limitResponse;
            }
        } else {
            $count = (clone $query)->count();
            if ($limitResponse = $this->exportLimitResponse($count)) {
                return $limitResponse;
            }
            $snapshots = $query->get();
        }

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
                $demandQty = (float) $line->order_qty;
                $qtyOnShipments = (float) (($line->shipped_qty ?? 0) > 0
                    ? $line->shipped_qty
                    : $line->qty_on_shipments);
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
                    $line->fill_rate_pct !== null ? (float) $line->fill_rate_pct : null,
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

        // Compute KP/CS segment data for the Summary sheet.
        $salesOrderIds = $snapshots->pluck('order.id')->filter()->unique()->values()->all();
        $segmentRows = $this->fillRateSegmentSummary($snapshots);
        $segmentReasonRows = $this->fillRateSegmentReasonSummary($request, $snapshots, $salesOrderIds);

        $excelSummary = $this->fillRateExcelSummary($request, $snapshots, $salesOrderIds);

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
        $query = AcumaticaInventoryItem::query();

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('inventory_id', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->boolean('low_stock')) {
            $query->where('qty_on_hand', '<=', 10);
        }

        if ($warehouseIds = $request->input('warehouse_id')) {
            $query->whereIn('default_warehouse_id', (array) $warehouseIds);
        }

        if ($productType = $request->input('product_type')) {
            $query->where('product_type', $productType);
        }

        if ($status = $request->input('prediction_status')) {
            $ids = $this->recentPredictionItemIds([(string) $status]);
            $query->whereIn('id', $ids);
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
            'out_of_stock' => $query->where('qty_on_hand', '<=', 0),
            'critical' => $query->whereIn('id', $this->recentPredictionItemIds(['critical'])),
            'at_risk' => $query->whereIn('id', $this->recentPredictionItemIds(['at_risk'])),
            // Default / primary tab view: critical stockout prediction OR zero stock.
            default => $query->where(function ($q) {
                $criticalIds = $this->recentPredictionItemIds(['critical']);
                $q->where('qty_on_hand', '<=', 0);
                if ($criticalIds->isNotEmpty()) {
                    $q->orWhereIn('id', $criticalIds);
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
            $query->whereBetween('computed_at', [$dateFrom, $dateTo.' 23:59:59']);
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

        if ($search = $request->input('q')) {
            $inventoryIds = AcumaticaInventoryItem::query()
                ->where('description', 'like', "%{$search}%")
                ->pluck('inventory_id');
            $customerIds = AcumaticaCustomer::query()
                ->where('name', 'like', "%{$search}%")
                ->pluck('acumatica_id');

            $query->where(function ($q) use ($search, $inventoryIds, $customerIds) {
                $q->where('acumatica_backorder_lines.order_nbr', 'like', "%{$search}%")
                    ->orWhere('acumatica_backorder_lines.inventory_id', 'like', "%{$search}%")
                    ->orWhere('acumatica_backorder_lines.customer_name', 'like', "%{$search}%")
                    ->orWhere('acumatica_backorder_lines.customer_acumatica_id', 'like', "%{$search}%")
                    ->orWhere('ai.item_class', 'like', "%{$search}%");

                if ($inventoryIds->isNotEmpty()) {
                    $q->orWhereIn('acumatica_backorder_lines.inventory_id', $inventoryIds);
                }
                if ($customerIds->isNotEmpty()) {
                    $q->orWhereIn('acumatica_backorder_lines.customer_acumatica_id', $customerIds);
                }
            });
        }

        if ($customerId = $request->input('customer_id')) {
            $query->where('acumatica_backorder_lines.customer_acumatica_id', $customerId);
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
            if ($reasonCode === 'unassigned') {
                $query->whereNull('acumatica_backorder_lines.reason_code');
            } else {
                $query->where('acumatica_backorder_lines.reason_code', $reasonCode);
            }
        }

        $dateExpr = $this->backorderTimelineDateExpression();

        if ($dateFrom = $request->input('date_from')) {
            $query->whereRaw($dateExpr.' >= ?', [$dateFrom]);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereRaw($dateExpr.' <= ?', [$dateTo]);
        }

        $this->applySalesConsultantBackorderScope($query, $request);
        $this->applyDepartmentPortfolioScope($query, $request, 'acumatica_backorder_lines.customer_acumatica_id');
        $this->applyBrandFilterToBackorderQuery($query, $request);

        return $query;
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
        if (! SalesConsultantScope::appliesTo($request->user())) {
            return;
        }

        $repCode = SalesConsultantScope::repCode($request->user());
        if ($repCode === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('aso.sales_consultant_rep_code', $repCode);
    }

    private function applySalesConsultantFillRateScope(Builder $query, Request $request): void
    {
        if (! SalesConsultantScope::appliesTo($request->user())) {
            return;
        }

        $repCode = SalesConsultantScope::repCode($request->user());
        if ($repCode === null) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereHas('order', fn ($orderQuery) => $orderQuery->where('sales_consultant_rep_code', $repCode));
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
            'by_reason' => $this->fillRateReasonSummary($request, $salesOrderIds),
            'by_department' => $this->unassignedDepartmentDistribution($undershippedValue, $snapshots->count(), 'Undershipped Value'),
            'by_customer_group' => $this->fillRateCustomerGroupSummary($request, $snapshots),
            'top_customers' => $this->fillRateTopCustomers($request, $snapshots),
            'top_products' => $this->fillRateTopProducts($request, $salesOrderIds),
            'by_segment' => $this->fillRateSegmentSummary($snapshots),
            'by_segment_reason' => $this->fillRateSegmentReasonSummary($request, $snapshots, $salesOrderIds),
            'by_business_category' => $this->fillRateBusinessCategorySummary($request, $snapshots, $salesOrderIds),
            'reason_capture_report' => $this->fillRateReasonCaptureReport($request, $salesOrderIds),
        ];
    }

    /**
     * Build Manufactured vs Trading (Partners) fill-rate metrics for cross-category comparison.
     */
    private function fillRateBusinessCategorySummary(Request $request, $snapshots, array $salesOrderIds): array
    {
        $lines = $this->fillRateShortfallLines($request, $salesOrderIds);
        $productTypes = $this->reasonCaptureReport->productTypesForOrderLines($salesOrderIds);

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
            $onShipments = (float) (($line->shipped_qty ?? 0) > 0
                ? $line->shipped_qty
                : ($line->qty_on_shipments ?? 0));
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

        $rowsQuery = AcumaticaSalesOrderLine::query()
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'acumatica_sales_order_lines.sales_order_id')
            ->select([
                'acumatica_sales_order_lines.sales_order_id',
                'acumatica_sales_order_lines.inventory_id',
                'acumatica_sales_order_lines.unfilled_reason_code',
                'acumatica_sales_order_lines.qty_at_approval',
                'acumatica_sales_order_lines.order_qty',
                'acumatica_sales_order_lines.qty_on_shipments',
                'acumatica_sales_order_lines.unit_price',
                'o.acumatica_order_nbr as order_nbr',
                'o.customer_acumatica_id',
            ]);

        if ($salesOrderIds !== []) {
            $rowsQuery->whereIn('o.id', $salesOrderIds);
        } else {
            $rowsQuery->whereBetween('o.order_date', [$dateFrom, $dateTo.' 23:59:59']);
        }

        $rows = $rowsQuery->get();

        // When "include out of stock" is off, drop OOS shortfall lines from fill-rate summaries.
        if (! $this->includeOutOfStock($request)) {
            $rows = $rows->reject(fn ($line) => $this->lineIsOutOfStock($line))->values();
        }

        return $rows;
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

    /**
     * Recompute snapshot fill-rate fields from order lines when OOS is excluded.
     *
     * @param  \Illuminate\Support\Collection<int, AcumaticaFillRateSnapshot>|\Illuminate\Database\Eloquent\Collection  $snapshots
     */
    /**
     * Recompute fill rate from order lines using current formula:
     * Completed only · Shipped Qty ÷ Order Qty × 100.
     * When $includeOutOfStock is false, OOS shortfall lines are excluded from the math.
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
                // No line payload: still enforce Completed-only on stored status.
                if (! \App\Services\Admin\FillRateCalculator::isEligibleStatus($status)) {
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
                $demand = max((float) ($line->qty_at_approval ?? 0), (float) ($line->order_qty ?? 0));
                $shipped = (float) ($line->qty_on_shipments ?? 0);

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

            $demand = max((float) ($line->qty_at_approval ?? 0), (float) ($line->order_qty ?? 0));
            $shipped = (float) ($line->qty_on_shipments ?? 0);
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
            'acumatica_sales_order_lines.qty_on_shipments',
            'acumatica_sales_order_lines.unit_price',
            'o.customer_acumatica_id',
        ]);

        $bucketTotals = [FillRateCalculator::SEGMENT_KP => 0.0, FillRateCalculator::SEGMENT_CS => 0.0];
        $acc = [];

        foreach ($rows as $line) {
            $segment = $segmentByCustomerId[$line->customer_acumatica_id] ?? FillRateCalculator::SEGMENT_CS;
            $reason = $line->unfilled_reason_code ?: 'Unassigned';
            $demand = max((float) $line->qty_at_approval, (float) $line->order_qty);
            $value = max($demand - (float) $line->qty_on_shipments, 0) * (float) $line->unit_price;

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

            $demand = max((float) ($line->qty_at_approval ?? 0), (float) ($line->order_qty ?? 0));
            $shipped = (float) ($line->qty_on_shipments ?? 0);
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
                'acumatica_sales_order_lines.qty_on_shipments',
                'acumatica_sales_order_lines.unit_price',
            ]);

        $total = $rows->sum(function ($line) {
            $demand = max((float) $line->qty_at_approval, (float) $line->order_qty);
            return max($demand - (float) $line->qty_on_shipments, 0) * (float) $line->unit_price;
        });

        return $rows
            ->groupBy(fn ($line) => $line->unfilled_reason_code ?: 'Unassigned')
            ->map(function ($group, $reason) use ($total) {
                $value = $group->sum(function ($line) {
                    $demand = max((float) $line->qty_at_approval, (float) $line->order_qty);
                    return max($demand - (float) $line->qty_on_shipments, 0) * (float) $line->unit_price;
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

    private function fillRateTopProducts(Request $request, array $salesOrderIds = []): array
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
                'acumatica_sales_order_lines.inventory_id',
                'acumatica_sales_order_lines.description',
                'acumatica_sales_order_lines.qty_at_approval',
                'acumatica_sales_order_lines.order_qty',
                'acumatica_sales_order_lines.qty_on_shipments',
                'acumatica_sales_order_lines.unit_price',
            ]);
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($rows->pluck('inventory_id')->all());
        $total = $rows->sum(function ($line) {
            $demand = max((float) $line->qty_at_approval, (float) $line->order_qty);
            return max($demand - (float) $line->qty_on_shipments, 0) * (float) $line->unit_price;
        });

        return $rows
            ->groupBy('inventory_id')
            ->map(function ($group, $inventoryId) use ($descriptions, $total) {
                $value = $group->sum(function ($line) {
                    $demand = max((float) $line->qty_at_approval, (float) $line->order_qty);
                    return max($demand - (float) $line->qty_on_shipments, 0) * (float) $line->unit_price;
                });

                return [
                    'inventory_id' => $inventoryId ?: 'Unassigned',
                    'product_name' => $this->catalogResolver->resolveProductName($inventoryId, $group->first()?->description, $descriptions),
                    'line_count' => $group->count(),
                    'undershipped_value' => round((float) $value, 2),
                    'contribution_pct' => $total > 0 ? round(((float) $value / (float) $total) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('undershipped_value')
            ->take(10)
            ->values()
            ->all();
    }

    private function backordersReasonSummary(Request $request): array
    {
        $rows = $this->backordersFilteredQuery($request)->get(['acumatica_backorder_lines.reason_code', 'acumatica_backorder_lines.open_qty', 'acumatica_backorder_lines.revenue_at_risk']);
        $total = (float) $rows->sum('revenue_at_risk');

        return $rows
            ->groupBy(fn ($row) => $row->reason_code ?: 'Unassigned')
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
            ->take(10)
            ->values()
            ->all();
    }

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
            ->sortByDesc('back_order_value')
            ->take(10)
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

    private function backorderTimelineDateExpression(): string
    {
        return "COALESCE(acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(aso.order_date), DATE(acumatica_backorder_lines.synced_at))";
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

    private function applyBrandFilterToBackorderQuery(Builder $query, Request $request): void
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

        $query->whereIn('acumatica_backorder_lines.inventory_id', $ids);
    }
}
