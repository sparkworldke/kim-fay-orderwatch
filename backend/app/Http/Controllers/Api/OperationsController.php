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
use App\Services\Operations\FillRateExcelExporter;
use App\Services\Operations\OperationsCatalogResolver;
use App\Support\SalesConsultantScope;
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
        $shippingZoneId = $request->filled('shipping_zone_id')
            ? strtoupper(trim((string) $request->input('shipping_zone_id')))
            : null;
        $regionFilter = $request->filled('region')
            ? strtolower(trim((string) $request->input('region')))
            : null;

        $user = $request->user();
        $repCode = SalesConsultantScope::repCode($user);

        return response()->json($this->optimization->dashboard(
            $dateFrom,
            $dateTo,
            $repCode,
            SalesConsultantScope::appliesTo($user) && $repCode === null,
            $shippingZoneId,
            $regionFilter,
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

        return response()->json([
            'total_items'      => $totalItems,
            'low_stock_count'  => $lowStock,
            'at_risk_count'    => $critical,
            'last_synced_at'   => AcumaticaInventoryItem::max('synced_at'),
            'warehouse_ids'    => AcumaticaInventoryItem::query()
                ->whereNotNull('default_warehouse_id')
                ->distinct()
                ->orderBy('default_warehouse_id')
                ->pluck('default_warehouse_id')
                ->values(),
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

    public function inventory(Request $request): JsonResponse
    {
        $query = $this->inventoryFilteredQuery($request)->orderByDesc('synced_at');

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
            'reason_code' => ['nullable', 'string', 'in:'.implode(',', AcumaticaBackorderLine::REASON_CODES)],
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

        $snapshots = $this->fillRateFilteredQuery($request, $dateFrom, $dateTo)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date',
                'order.customer:acumatica_id,shipping_zone_id',
                'order.customer.shippingZone:acumatica_id,description,name,region',
            ])
            ->get();

        $deliverySlaCounts = ['breach' => 0, 'warning' => 0];
        foreach ($snapshots as $snapshot) {
            $sla = $this->deliverySlaForSnapshot($snapshot);
            if ($sla['delivery_sla_status'] === 'breach') {
                $deliverySlaCounts['breach']++;
            } elseif ($sla['delivery_sla_status'] === 'warning') {
                $deliverySlaCounts['warning']++;
            }
        }

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
        $query = $this->fillRateFilteredQuery($request)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date',
                'order.customer:acumatica_id,shipping_zone_id',
                'order.customer.shippingZone:acumatica_id,description,name,region',
                'order.lines:id,sales_order_id,inventory_id,description,order_qty,shipped_qty,qty_on_shipments,open_qty,unit_price,uom,fill_rate_pct,qty_at_approval,unfilled_reason_code',
            ]);

        if ($deliverySla = $request->input('delivery_sla')) {
            if (! in_array($deliverySla, ['breach', 'warning'], true)) {
                return response()->json(['message' => 'Invalid delivery_sla filter.'], 422);
            }
        }

        $sort = $request->input('sort', 'high_to_low');
        if ($sort === 'low_to_high') {
            $query->orderByRaw('fill_rate_pct IS NULL')
                ->orderBy('fill_rate_pct');
        } else {
            $query->orderByRaw('fill_rate_pct IS NULL')
                ->orderByDesc('fill_rate_pct');
        }

        $this->applyFillRateSearch($query, $request);

        if ($request->filled('delivery_sla')) {
            $filtered = $query->get()->filter(function ($snapshot) use ($request) {
                $sla = $this->deliverySlaForSnapshot($snapshot);

                return $sla['delivery_sla_status'] === $request->input('delivery_sla');
            })->values();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = max(1, (int) $request->integer('per_page', 50));
            $total = $filtered->count();
            $items = $filtered->slice(($page - 1) * $perPage, $perPage)->values();
            $paginated = new \Illuminate\Pagination\LengthAwarePaginator(
                $items,
                $total,
                $perPage,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $paginated = $query->paginate($request->integer('per_page', 50));
            $items = $paginated->getCollection();
        }

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

            foreach ($this->deliverySlaForSnapshot($snapshot) as $key => $value) {
                $snapshot->{$key} = $value;
            }

            $snapshot->products = collect($order?->lines ?? [])
                ->map(function ($line) use ($inventoryDescriptions, $inventoryStock) {
                    $demandQty = (float) ($line->qty_at_approval ?: $line->order_qty);
                    $qtyOnShipments = (float) $line->qty_on_shipments;
                    $unfilledQty = max($demandQty - $qtyOnShipments, 0);
                    $openQty = (float) $line->open_qty;
                    if ($openQty <= 0) {
                        $openQty = $unfilledQty;
                    }
                    $unitPrice = (float) $line->unit_price;

                    return [
                        'inventory_id'         => $line->inventory_id,
                        'product_name'         => $this->catalogResolver->resolveProductName(
                            $line->inventory_id,
                            $line->description,
                            $inventoryDescriptions,
                        ),
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
                $demandQty = max((float) $line->qty_at_approval, (float) $line->order_qty);
                $qtyOnShipments = (float) $line->qty_on_shipments;
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

        return $this->fillRateExporter->build(
            fillRateRows:       $fillRateRows,
            productRows:        $productRows,
            reasonRows:         $this->fillRateReasonSummary($request),
            customerRows:       $this->fillRateTopCustomers($request),
            productSummaryRows: $this->fillRateTopProducts($request),
            dateFrom:           (string) $request->input('date_from', ''),
            dateTo:             (string) $request->input('date_to', ''),
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
            $ids = AcumaticaInventoryRunRateLog::where('prediction_status', $status)
                ->where('logged_at', '>=', now()->subDays(2))
                ->pluck('inventory_item_id');
            $query->whereIn('id', $ids);
        }

        return $query;
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

    private function fillRateFilteredQuery(Request $request, ?string $dateFrom = null, ?string $dateTo = null): Builder
    {
        $query = AcumaticaFillRateSnapshot::query();

        $dateFrom ??= $request->input('date_from');
        $dateTo ??= $request->input('date_to');

        if ($dateFrom && $dateTo) {
            $query->whereBetween('computed_at', [$dateFrom, $dateTo.' 23:59:59']);
        }

        if ($status = $request->input('status')) {
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
        ];
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
        ];
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

        return ucwords(str_replace('_', ' ', $code));
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
}
