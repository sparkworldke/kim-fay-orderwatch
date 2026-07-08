<?php

namespace App\Services\Operations;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\AcumaticaShippingZone;
use App\Models\AcumaticaSyncLog;
use App\Models\DeliverySlaConfig;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;


class BusinessOptimizationService
{
    public function __construct(
        private readonly OperationsCatalogResolver $catalogResolver,
        private readonly DeliverySlaEvaluator $deliverySla,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function opsStatus(): array
    {
        $inventorySync = $this->lastCompletedSync(['inventory', 'inventory_stocks']);
        $backorderSync = $this->lastCompletedSync(['backorders']);
        $fillRateSync  = $this->lastCompletedSync(['fill_rate']);

        return [
            'last_inventory_sync_at' => $inventorySync?->ended_at?->toIso8601String()
                ?? $this->isoMax(AcumaticaInventoryItem::max('synced_at')),
            'last_inventory_sync_type' => $inventorySync?->sync_type,
            'last_backorder_sync_at'   => $backorderSync?->ended_at?->toIso8601String()
                ?? $this->isoMax(AcumaticaBackorderLine::max('synced_at')),
            'last_fill_rate_sync_at'   => $fillRateSync?->ended_at?->toIso8601String(),
            'last_fill_rate_computed_at' => $this->isoMax(AcumaticaFillRateSnapshot::max('computed_at')),
            'inventory_stale'          => $this->isStale($inventorySync?->ended_at ?? AcumaticaInventoryItem::max('synced_at')),
            'backorders_stale'         => $this->isStale($backorderSync?->ended_at ?? AcumaticaBackorderLine::max('synced_at')),
            'fill_rate_stale'          => $this->isStale($fillRateSync?->ended_at ?? AcumaticaFillRateSnapshot::max('computed_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(
        string $dateFrom,
        string $dateTo,
        ?string $repCode = null,
        bool $emptyScope = false,
        ?string $shippingZoneId = null,
        ?string $regionFilter = null,
    ): array
    {
        $status = $this->opsStatus();
        $scope = $this->resolveScope($shippingZoneId, $regionFilter);
        $shippingZoneId = $scope['shipping_zone_id'];
        $regionFilter = $scope['region_filter'];

        if ($emptyScope) {
            $backorderLines = collect();
            $fillSnapshots = collect();
        } else {
            $backorderQuery = AcumaticaBackorderLine::query();
            $fillQuery = AcumaticaFillRateSnapshot::query()
                ->whereBetween('computed_at', [$dateFrom, $dateTo . ' 23:59:59']);

            if ($repCode !== null) {
                $backorderQuery->whereIn('order_nbr', function ($sub) use ($repCode) {
                    $sub->select('acumatica_order_nbr')
                        ->from('acumatica_sales_orders')
                        ->where('sales_consultant_rep_code', $repCode);
                });
                $fillQuery->whereIn('sales_order_id', function ($sub) use ($repCode) {
                    $sub->select('id')
                        ->from('acumatica_sales_orders')
                        ->where('sales_consultant_rep_code', $repCode);
                });
            }

            $this->applyCustomerScope($backorderQuery, 'customer_acumatica_id', $scope['customer_ids']);
            $this->applyCustomerScope($fillQuery, 'customer_acumatica_id', $scope['customer_ids']);

            $backorderLines = $backorderQuery->get();
            $fillSnapshots = $fillQuery
                ->with([
                    'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,order_date,approved_at,shipped_at,ship_date,order_total',
                    'order.customer:acumatica_id,shipping_zone_id,name',
                    'order.customer.shippingZone:acumatica_id,description,name,region',
                    'order.lines:id,sales_order_id,unfilled_reason_code',
                ])
                ->get();
        }

        $zoneGuardrails = $this->zoneGuardrails($fillSnapshots, $dateFrom, $dateTo);
        $deliverySla = $this->deliverySlaAnalytics($fillSnapshots);

        $customerFocus = $this->customerFocus($backorderLines, $fillSnapshots);
        $productFocus  = $this->productFocus($backorderLines);
        $forecasting   = $this->productionForecasting();
        $revenue       = $this->revenueBleeding($backorderLines, $fillSnapshots, $dateFrom, $dateTo, $shippingZoneId);
        $executive     = $this->executiveAlerts(
            $status,
            $customerFocus,
            $productFocus,
            $forecasting,
            $revenue,
            $fillSnapshots,
            $deliverySla,
            $zoneGuardrails,
        );

        return [
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'ops_status'         => $status,
            'customer_focus'     => $customerFocus,
            'product_focus'      => $productFocus,
            'production_forecast'=> $forecasting,
            'revenue_bleeding'   => $revenue,
            'delivery_sla'       => $deliverySla,
            'zone_guardrails'    => $zoneGuardrails,
            'executive_alerts'   => $executive,
            'charts'             => [
                'backorders_by_customer'    => $customerFocus['top_by_revenue'],
                'backorders_by_reason'      => $this->backordersByReason($backorderLines),
                'backorders_by_customer_group' => $this->backordersByCustomerGroup($backorderLines),
                'backorders_by_department'  => $this->unassignedDepartmentRows($backorderLines->count(), (float) $backorderLines->sum('revenue_at_risk'), 'Back Order Value'),
                'fill_rate_by_status'       => $this->fillRateByStatus($fillSnapshots),
                'fill_rate_by_customer_group' => $this->fillRateByCustomerGroup($fillSnapshots),
                'fill_rate_unfilled_reasons'=> $this->fillRateUnfilledReasons($dateFrom, $dateTo, $shippingZoneId),
                'stockout_risk_products'    => $forecasting['at_risk_items'],
                'revenue_bleeding_split'    => [
                    ['label' => 'Backorders at risk', 'value' => $revenue['backorder_revenue_at_risk']],
                    ['label' => 'Fill rate not shipped', 'value' => $revenue['fill_rate_not_shipped']],
                ],
            ],
            'filters' => [
                'shipping_zones' => $this->shippingZoneFilters(),
                'selected_shipping_zone_id' => $shippingZoneId,
                'selected_region' => $regionFilter,
                'region_options' => [
                    ['value' => 'all', 'label' => 'All regions'],
                    ['value' => 'nairobi', 'label' => 'Nairobi'],
                    ['value' => 'coast', 'label' => 'Coast / MSA'],
                    ['value' => 'other', 'label' => 'Other regions'],
                    ['value' => 'unmapped', 'label' => 'Unmapped'],
                ],
            ],
            'excel_summary' => [
                'fill_rate' => $this->fillRateExcelSummary($fillSnapshots, $dateFrom, $dateTo, $shippingZoneId),
                'backorders' => $this->backordersExcelSummary($backorderLines),
            ],
        ];
    }

    /**
     * @param  Collection<int, AcumaticaBackorderLine>  $lines
     * @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots
     * @return array<string, mixed>
     */
    private function customerFocus(Collection $lines, Collection $snapshots): array
    {
        $customerIds = $lines->pluck('customer_acumatica_id')
            ->merge($snapshots->pluck('customer_acumatica_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $names = $this->catalogResolver->namesForCustomerIds($customerIds);

        $byBackorder = $lines
            ->groupBy('customer_acumatica_id')
            ->map(function ($group, $customerId) use ($names) {
                $stored = $group->first()?->customer_name;

                return [
                    'customer_acumatica_id' => $customerId,
                    'customer_name'         => $this->catalogResolver->resolveCustomerName($stored, $customerId, $names),
                    'open_lines'            => $group->count(),
                    'open_orders'           => $group->pluck('order_nbr')->unique()->count(),
                    'total_open_qty'        => round((float) $group->sum('open_qty'), 4),
                    'revenue_at_risk'       => round((float) $group->sum('revenue_at_risk'), 2),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values();

        $byFillRate = $snapshots
            ->groupBy('customer_acumatica_id')
            ->map(function ($group, $customerId) use ($names) {
                $critical = $group->where('fill_rate_status', 'critical')->count();
                $atRisk   = $group->where('fill_rate_status', 'at_risk')->count();

                return [
                    'customer_acumatica_id' => $customerId,
                    'customer_name'         => $this->catalogResolver->resolveCustomerName(null, $customerId, $names),
                    'order_count'           => $group->count(),
                    'critical_orders'       => $critical,
                    'at_risk_orders'        => $atRisk,
                    'revenue_not_shipped'   => round((float) $group->sum('revenue_not_shipped'), 2),
                    'avg_fill_rate_pct'     => round((float) $group->where('fill_rate_pct', '!=', null)->avg('fill_rate_pct'), 1),
                ];
            })
            ->sortByDesc('revenue_not_shipped')
            ->values();

        $totalRevRisk = (float) $byBackorder->sum('revenue_at_risk');
        $topCustomer  = $byBackorder->first();
        $concentrationPct = $totalRevRisk > 0 && $topCustomer
            ? round(((float) $topCustomer['revenue_at_risk'] / $totalRevRisk) * 1000) / 10
            : null;

        return [
            'top_by_revenue'        => $byBackorder->take(10)->values()->all(),
            'top_by_fill_rate_risk' => $byFillRate->filter(fn ($r) => $r['critical_orders'] > 0 || $r['at_risk_orders'] > 0)->take(10)->values()->all(),
            'total_customers_at_risk' => $byBackorder->count(),
            'top_customer_concentration_pct' => $concentrationPct,
        ];
    }

    /**
     * @param  Collection<int, AcumaticaBackorderLine>  $lines
     * @return array<string, mixed>
     */
    private function productFocus(Collection $lines): array
    {
        $inventoryIds = $lines->pluck('inventory_id')->unique()->values()->all();
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);
        $stock        = $this->catalogResolver->stockForInventoryIds($inventoryIds);

        $byProduct = $lines
            ->groupBy('inventory_id')
            ->map(function ($group, $inventoryId) use ($descriptions, $stock) {
                $openQty = (float) $group->sum('open_qty');
                $onHand  = isset($stock[$inventoryId]) ? (float) $stock[$inventoryId]['qty_on_hand'] : null;

                return [
                    'inventory_id'    => $inventoryId,
                    'product_name'    => $this->catalogResolver->resolveProductName($inventoryId, null, $descriptions),
                    'open_lines'      => $group->count(),
                    'total_open_qty'  => round($openQty, 4),
                    'revenue_at_risk' => round((float) $group->sum('revenue_at_risk'), 2),
                    'qty_on_hand'     => $onHand,
                    'stock_shortfall' => $onHand !== null && $onHand < $openQty,
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values();

        return [
            'top_by_revenue'       => $byProduct->take(10)->values()->all(),
            'stock_shortfall_skus' => $byProduct->where('stock_shortfall', true)->take(10)->values()->all(),
            'shortfall_count'      => $byProduct->where('stock_shortfall', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function productionForecasting(): array
    {
        $latestLogIds = AcumaticaInventoryRunRateLog::query()
            ->selectRaw('MAX(id) as id')
            ->where('logged_at', '>=', now()->subDays(2))
            ->groupBy('inventory_item_id')
            ->pluck('id');

        $logs = AcumaticaInventoryRunRateLog::query()
            ->whereIn('id', $latestLogIds)
            ->whereIn('prediction_status', ['critical', 'at_risk'])
            ->orderBy('days_until_stockout')
            ->limit(15)
            ->get();

        $inventoryIds = $logs->pluck('inventory_id')->all();
        $descriptions = $this->catalogResolver->descriptionsForInventoryIds($inventoryIds);

        $atRiskItems = $logs->map(function ($log) use ($descriptions) {
            return [
                'inventory_id'        => $log->inventory_id,
                'product_name'        => $this->catalogResolver->resolveProductName($log->inventory_id, null, $descriptions),
                'qty_on_hand'         => (float) $log->qty_on_hand,
                'daily_run_rate'      => $log->daily_run_rate !== null ? (float) $log->daily_run_rate : null,
                'days_until_stockout' => $log->days_until_stockout,
                'prediction_status'   => $log->prediction_status,
            ];
        })->values()->all();

        $criticalCount = collect($atRiskItems)->where('prediction_status', 'critical')->count();
        $atRiskCount   = collect($atRiskItems)->where('prediction_status', 'at_risk')->count();

        return [
            'at_risk_items'   => $atRiskItems,
            'critical_count'  => $criticalCount,
            'at_risk_count'   => $atRiskCount,
            'zero_stock_skus' => AcumaticaInventoryItem::where('qty_on_hand', '<=', 0)->count(),
        ];
    }

    /**
     * @param  Collection<int, AcumaticaBackorderLine>  $lines
     * @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots
     * @return array<string, mixed>
     */
    private function revenueBleeding(
        Collection $lines,
        Collection $snapshots,
        string $dateFrom,
        string $dateTo,
        ?string $shippingZoneId,
    ): array
    {
        $backorderRisk = round((float) $lines->sum('revenue_at_risk'), 2);
        $eligible      = $snapshots->where('fill_rate_status', '!=', 'na');
        $notShipped    = round((float) $eligible->sum('revenue_not_shipped'), 2);
        $criticalRev   = round((float) $snapshots->where('fill_rate_status', 'critical')->sum('revenue_not_shipped'), 2);

        $zeroShipmentLines = AcumaticaSalesOrderLine::query()
            ->join('acumatica_sales_orders', 'acumatica_sales_orders.id', '=', 'acumatica_sales_order_lines.sales_order_id')
            ->where('qty_on_shipments', '<=', 0)
            ->where(function ($q) {
                $q->where('qty_at_approval', '>', 0)
                    ->orWhere('order_qty', '>', 0);
            })
            ->whereBetween('acumatica_sales_orders.order_date', [$dateFrom, $dateTo . ' 23:59:59'])
            ->when($shippingZoneId !== null, function ($q) use ($shippingZoneId) {
                $q->whereIn('acumatica_sales_orders.customer_acumatica_id', function ($sub) use ($shippingZoneId) {
                    $sub->select('acumatica_id')
                        ->from('acumatica_customers')
                        ->where('shipping_zone_id', $shippingZoneId);
                });
            })
            ->count('acumatica_sales_order_lines.id');

        return [
            'backorder_revenue_at_risk'      => $backorderRisk,
            'fill_rate_not_shipped'          => $notShipped,
            'fill_rate_critical_not_shipped' => $criticalRev,
            'combined_exposure'              => round($backorderRisk + $notShipped, 2),
            'open_backorder_lines'           => $lines->count(),
            'orders_below_80_pct'            => $snapshots->where('fill_rate_status', 'critical')->count(),
            'zero_qty_on_shipments_lines'    => $zeroShipmentLines,
            'backorders_without_reason'      => $lines->where(fn ($l) => blank($l->reason_code))->count(),
        ];
    }

    /**
     * @param  Collection<int, AcumaticaBackorderLine>  $lines
     * @return list<array{reason_code: string, line_count: int, revenue_at_risk: float, total_open_qty: float}>
     */
    private function backordersByReason(Collection $lines): array
    {
        return $lines
            ->groupBy(fn ($line) => $line->reason_code ?: 'unassigned')
            ->map(function ($group, $reasonCode) {
                return [
                    'reason_code'     => $reasonCode,
                    'line_count'      => $group->count(),
                    'revenue_at_risk' => round((float) $group->sum('revenue_at_risk'), 2),
                    'total_open_qty'  => round((float) $group->sum('open_qty'), 4),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values()
            ->all();
    }

    /**
     * Lines with zero QtyOnShipments while demand exists — primary fill-rate shortage signal.
     *
     * @return list<array{reason_code: string, line_count: int, total_demand_qty: float, revenue_at_risk: float}>
     */
    private function fillRateUnfilledReasons(string $dateFrom, string $dateTo, ?string $shippingZoneId = null): array
    {
        $lines = AcumaticaSalesOrderLine::query()
            ->join('acumatica_sales_orders', 'acumatica_sales_orders.id', '=', 'acumatica_sales_order_lines.sales_order_id')
            ->where('acumatica_sales_order_lines.qty_on_shipments', '<=', 0)
            ->where(function ($q) {
                $q->where('acumatica_sales_order_lines.qty_at_approval', '>', 0)
                    ->orWhere('acumatica_sales_order_lines.order_qty', '>', 0);
            })
            ->whereBetween('acumatica_sales_orders.order_date', [$dateFrom, $dateTo.' 23:59:59'])
            ->when($shippingZoneId !== null, function ($q) use ($shippingZoneId) {
                $q->whereIn('acumatica_sales_orders.customer_acumatica_id', function ($sub) use ($shippingZoneId) {
                    $sub->select('acumatica_id')
                        ->from('acumatica_customers')
                        ->where('shipping_zone_id', $shippingZoneId);
                });
            })
            ->get([
                'acumatica_sales_order_lines.unfilled_reason_code',
                'acumatica_sales_order_lines.qty_at_approval',
                'acumatica_sales_order_lines.order_qty',
                'acumatica_sales_order_lines.unit_price',
            ]);

        return $lines
            ->groupBy(fn ($line) => $line->unfilled_reason_code ?: 'inventory_shortage')
            ->map(function ($group, $reasonCode) {
                $demand = $group->sum(fn ($line) => max((float) $line->qty_at_approval, (float) $line->order_qty));
                $revenue = $group->sum(function ($line) {
                    $qty = max((float) $line->qty_at_approval, (float) $line->order_qty);

                    return $qty * (float) $line->unit_price;
                });

                return [
                    'reason_code'      => (string) $reasonCode,
                    'line_count'       => $group->count(),
                    'total_demand_qty' => round($demand, 4),
                    'revenue_at_risk'  => round($revenue, 2),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots
     * @return list<array{status: string, count: int}>
     */
    private function fillRateByStatus(Collection $snapshots): array
    {
        return collect(['healthy', 'at_risk', 'critical', 'na'])
            ->map(fn ($status) => [
                'status' => $status,
                'count'  => $snapshots->where('fill_rate_status', $status)->count(),
            ])
            ->filter(fn ($row) => $row['count'] > 0)
            ->values()
            ->all();
    }

    /** @param  Collection<int, AcumaticaBackorderLine>  $lines */
    private function backordersExcelSummary(Collection $lines): array
    {
        $value = round((float) $lines->sum('revenue_at_risk'), 2);

        return [
            'totals' => [
                'back_order_qty' => round((float) $lines->sum('open_qty'), 4),
                'back_order_value' => $value,
                'line_count' => $lines->count(),
                'order_count' => $lines->pluck('order_nbr')->unique()->count(),
            ],
            'by_reason' => $this->backordersByReason($lines),
            'by_department' => $this->unassignedDepartmentRows($lines->count(), $value, 'Back Order Value'),
            'by_customer_group' => $this->backordersByCustomerGroup($lines),
            'top_customers' => $this->customerFocus($lines, collect())['top_by_revenue'],
            'top_products' => $this->productFocus($lines)['top_by_revenue'],
        ];
    }

    /** @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots */
    private function fillRateExcelSummary(
        Collection $snapshots,
        string $dateFrom,
        string $dateTo,
        ?string $shippingZoneId = null,
    ): array
    {
        $eligible = $snapshots->where('fill_rate_status', '!=', 'na');
        $ordered = round((float) $eligible->sum('total_ordered_qty'), 4);
        $actual = round((float) $eligible->sum('total_shipped_qty'), 4);
        $undershippedValue = round((float) $eligible->sum('revenue_not_shipped'), 2);

        return [
            'totals' => [
                'actual_qty' => $actual,
                'ordered_qty' => $ordered,
                'undershipped_qty' => round(max($ordered - $actual, 0), 4),
                'undershipped_value' => $undershippedValue,
                'fill_rate_pct' => $ordered > 0 ? round(($actual / $ordered) * 100, 1) : null,
                'order_count' => $snapshots->count(),
            ],
            'by_status' => $this->fillRateStatusContribution($snapshots),
            'by_reason' => $this->fillRateUnfilledReasons($dateFrom, $dateTo, $shippingZoneId),
            'by_department' => $this->unassignedDepartmentRows($snapshots->count(), $undershippedValue, 'Undershipped Value'),
            'by_customer_group' => $this->fillRateByCustomerGroup($snapshots),
            'top_customers' => $this->customerFocus(collect(), $snapshots)['top_by_fill_rate_risk'],
            'top_products' => [],
        ];
    }

    /** @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots */
    private function fillRateStatusContribution(Collection $snapshots): array
    {
        $total = (float) $snapshots->sum('revenue_not_shipped');

        return $snapshots
            ->groupBy('fill_rate_status')
            ->map(fn ($group, $status) => [
                'status' => (string) $status,
                'count' => $group->count(),
                'undershipped_value' => round((float) $group->sum('revenue_not_shipped'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_not_shipped') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('undershipped_value')
            ->values()
            ->all();
    }

    /** @param  Collection<int, AcumaticaBackorderLine>  $lines */
    private function backordersByCustomerGroup(Collection $lines): array
    {
        $classes = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $lines->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');
        $total = (float) $lines->sum('revenue_at_risk');

        return $lines
            ->groupBy(fn ($line) => $classes[$line->customer_acumatica_id] ?? 'Unassigned')
            ->map(fn ($group, $label) => [
                'customer_group' => (string) $label,
                'line_count' => $group->count(),
                'back_order_value' => round((float) $group->sum('revenue_at_risk'), 2),
                'contribution_pct' => $total > 0 ? round(((float) $group->sum('revenue_at_risk') / $total) * 100, 1) : 0.0,
            ])
            ->sortByDesc('back_order_value')
            ->values()
            ->all();
    }

    /** @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots */
    private function fillRateByCustomerGroup(Collection $snapshots): array
    {
        $classes = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $snapshots->pluck('customer_acumatica_id')->filter()->unique())
            ->pluck('customer_class', 'acumatica_id');
        $total = (float) $snapshots->sum('revenue_not_shipped');

        return $snapshots
            ->groupBy(fn ($row) => $classes[$row->customer_acumatica_id] ?? 'Unassigned')
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

    private function unassignedDepartmentRows(int $count, float $value, string $valueLabel): array
    {
        if ($count === 0 && $value <= 0) {
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

    /** @return list<array{acumatica_id: string, description: string|null, name: string|null, region: string|null}> */
    private function shippingZoneFilters(): array
    {
        return AcumaticaShippingZone::query()
            ->orderBy('region')
            ->orderBy('name')
            ->orderBy('acumatica_id')
            ->get(['acumatica_id', 'description', 'name', 'region'])
            ->map(fn ($zone) => [
                'acumatica_id' => $zone->acumatica_id,
                'description' => $zone->description,
                'name' => $zone->name,
                'region' => $zone->region,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $customerFocus
     * @param  array<string, mixed>  $productFocus
     * @param  array<string, mixed>  $forecasting
     * @param  array<string, mixed>  $revenue
     * @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots
     * @return list<array{severity: string, category: string, message: string}>
     */
    /**
     * @param  list<string>|null  $customerIds
     */
    private function applyCustomerScope(Builder $query, string $column, ?array $customerIds): void
    {
        if ($customerIds === null) {
            return;
        }

        if ($customerIds === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($column, $customerIds);
    }

    /**
     * @return array{
     *   shipping_zone_id: string|null,
     *   region_filter: string|null,
     *   customer_ids: list<string>|null
     * }
     */
    private function resolveScope(?string $shippingZoneId, ?string $regionFilter): array
    {
        $zoneId = $shippingZoneId !== null && $shippingZoneId !== ''
            ? strtoupper(trim($shippingZoneId))
            : null;

        $region = $regionFilter !== null && $regionFilter !== '' && strtolower($regionFilter) !== 'all'
            ? strtolower(trim($regionFilter))
            : null;

        if ($zoneId !== null) {
            return [
                'shipping_zone_id' => $zoneId,
                'region_filter' => $region,
                'customer_ids' => AcumaticaCustomer::query()
                    ->where('shipping_zone_id', $zoneId)
                    ->pluck('acumatica_id')
                    ->all(),
            ];
        }

        if ($region === null) {
            return [
                'shipping_zone_id' => null,
                'region_filter' => null,
                'customer_ids' => null,
            ];
        }

        $customerQuery = AcumaticaCustomer::query();

        match ($region) {
            'unmapped' => $customerQuery->where(function (Builder $q) {
                $q->whereNull('shipping_zone_id')->orWhere('shipping_zone_id', '');
            }),
            'nairobi' => $customerQuery->whereHas('shippingZone', fn (Builder $q) => $q->where('region', 'Nairobi')),
            'coast' => $customerQuery->whereHas('shippingZone', fn (Builder $q) => $q->where('region', 'Coast')),
            'other' => $customerQuery->where(function (Builder $q) {
                $q->whereHas('shippingZone', function (Builder $zone) {
                    $zone->where(function (Builder $inner) {
                        $inner->whereNull('region')
                            ->orWhereNotIn('region', ['Nairobi', 'Coast']);
                    });
                });
            }),
            default => $customerQuery->whereRaw('1 = 0'),
        };

        return [
            'shipping_zone_id' => null,
            'region_filter' => $region,
            'customer_ids' => $customerQuery->pluck('acumatica_id')->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function zoneGuardrails(Collection $snapshots, string $dateFrom, string $dateTo): array
    {
        $unmappedTotal = AcumaticaCustomer::query()
            ->where(function (Builder $q) {
                $q->whereNull('shipping_zone_id')->orWhere('shipping_zone_id', '');
            })
            ->count();

        $orderCustomerIds = $snapshots->pluck('customer_acumatica_id')->filter()->unique();
        $unmappedWithOrders = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $orderCustomerIds)
            ->where(function (Builder $q) {
                $q->whereNull('shipping_zone_id')->orWhere('shipping_zone_id', '');
            })
            ->count();

        $config = DeliverySlaConfig::forRegionKey('other');

        return [
            'unmapped_customer_count' => $unmappedTotal,
            'unmapped_with_orders_in_period' => $unmappedWithOrders,
            'alert_min_orders' => $config->alert_min_orders,
            'alert_delayed_pct' => (float) $config->alert_delayed_pct,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /** @return array<string, mixed> */
    private function deliverySlaAnalytics(Collection $snapshots): array
    {
        $rules = $this->deliverySla->publicRules();
        $evaluated = [];
        $byRegion = [];
        $byZone = [];
        $daily = [];

        foreach ($snapshots as $snapshot) {
            $order = $snapshot->order;
            $zone = $order?->customer?->shippingZone;
            $sla = $this->deliverySla->evaluate(
                $order?->order_date,
                $order?->approved_at,
                $order?->shipped_at,
                $order?->ship_date,
                $zone?->acumatica_id ?? $order?->customer?->shipping_zone_id,
                $zone?->description,
                null,
                $zone?->region,
            );

            $orderValue = (float) ($order?->order_total ?? $snapshot->revenue_not_shipped ?? 0);
            $primaryReason = $order?->lines
                ?->pluck('unfilled_reason_code')
                ->filter()
                ->countBy()
                ->sortDesc()
                ->keys()
                ->first();

            $row = [
                'order_nbr' => $snapshot->order_nbr,
                'customer_name' => $order?->customer_name,
                'customer_acumatica_id' => $snapshot->customer_acumatica_id,
                'shipping_zone_id' => $sla['shipping_zone_id'],
                'shipping_zone_name' => $zone?->name,
                'shipping_zone_region' => $zone?->region,
                'region_key' => $sla['region_key'] ?? null,
                'order_value' => round($orderValue, 2),
                'delivery_hours' => $sla['delivery_hours'],
                'delivery_sla_status' => $sla['delivery_sla_status'],
                'delivery_sla_label' => $sla['delivery_sla_label'],
                'sla_hours' => $sla['sla_hours'],
                'primary_reason' => $primaryReason,
                'order_date' => $order?->order_date?->toDateString(),
            ];

            $evaluated[] = $row;

            $regionKey = (string) ($sla['region_key'] ?? 'other');
            $byRegion[$regionKey] ??= ['region_key' => $regionKey, 'total' => 0, 'on_time' => 0, 'warning' => 0, 'breach' => 0, 'unknown' => 0, 'delayed_value' => 0.0, 'hours_sum' => 0.0, 'hours_count' => 0];
            $byRegion[$regionKey]['total']++;
            if ($sla['delivery_sla_status'] === 'breach') {
                $byRegion[$regionKey]['breach']++;
                $byRegion[$regionKey]['delayed_value'] += $orderValue;
            } elseif ($sla['delivery_sla_status'] === 'warning') {
                $byRegion[$regionKey]['warning']++;
            } elseif ($sla['delivery_sla_status'] === 'unknown') {
                $byRegion[$regionKey]['unknown']++;
            } else {
                $byRegion[$regionKey]['on_time']++;
            }
            if ($sla['delivery_hours'] !== null) {
                $byRegion[$regionKey]['hours_sum'] += (float) $sla['delivery_hours'];
                $byRegion[$regionKey]['hours_count']++;
            }

            $zoneId = (string) ($sla['shipping_zone_id'] ?? '__unmapped__');
            $byZone[$zoneId] ??= [
                'acumatica_id' => $zoneId === '__unmapped__' ? null : $zoneId,
                'name' => $zone?->name ?? ($zoneId === '__unmapped__' ? 'Unmapped' : $zoneId),
                'region' => $zone?->region,
                'total_orders' => 0,
                'delayed_orders' => 0,
                'warning_orders' => 0,
                'delayed_value' => 0.0,
                'hours_sum' => 0.0,
                'hours_count' => 0,
                'reason_counts' => [],
            ];
            $byZone[$zoneId]['total_orders']++;
            if ($sla['delivery_sla_status'] === 'breach') {
                $byZone[$zoneId]['delayed_orders']++;
                $byZone[$zoneId]['delayed_value'] += $orderValue;
            } elseif ($sla['delivery_sla_status'] === 'warning') {
                $byZone[$zoneId]['warning_orders']++;
            }
            if ($sla['delivery_hours'] !== null) {
                $byZone[$zoneId]['hours_sum'] += (float) $sla['delivery_hours'];
                $byZone[$zoneId]['hours_count']++;
            }
            if ($primaryReason) {
                $byZone[$zoneId]['reason_counts'][$primaryReason] = ($byZone[$zoneId]['reason_counts'][$primaryReason] ?? 0) + 1;
            }

            $day = $order?->order_date?->toDateString() ?? 'unknown';
            $daily[$day] ??= ['day' => $day, 'total' => 0, 'delayed' => 0, 'on_time' => 0];
            $daily[$day]['total']++;
            if ($sla['delivery_sla_status'] === 'breach') {
                $daily[$day]['delayed']++;
            } else {
                $daily[$day]['on_time']++;
            }
        }

        $total = count($evaluated);
        $breachCount = count(array_filter($evaluated, fn ($r) => $r['delivery_sla_status'] === 'breach'));
        $warningCount = count(array_filter($evaluated, fn ($r) => $r['delivery_sla_status'] === 'warning'));
        $onTimeCount = count(array_filter($evaluated, fn ($r) => $r['delivery_sla_status'] === 'ok'));
        $unknownCount = count(array_filter($evaluated, fn ($r) => $r['delivery_sla_status'] === 'unknown'));
        $delayedValue = round(array_sum(array_map(
            fn ($r) => $r['delivery_sla_status'] === 'breach' ? $r['order_value'] : 0,
            $evaluated,
        )), 2);
        $hoursTracked = array_filter(array_column($evaluated, 'delivery_hours'), fn ($h) => $h !== null);
        $avgHours = $hoursTracked !== []
            ? round(array_sum($hoursTracked) / count($hoursTracked), 1)
            : null;

        $mostAffected = collect($byZone)
            ->map(function (array $zone) use ($rules) {
                $delayedPct = $zone['total_orders'] > 0
                    ? round(($zone['delayed_orders'] / $zone['total_orders']) * 1000) / 10
                    : 0.0;
                $regionKey = DeliverySlaConfig::regionKeyFromZoneRegion($zone['region'] ?? null);
                $config = DeliverySlaConfig::forRegionKey($regionKey);
                arsort($zone['reason_counts']);
                $primaryReason = array_key_first($zone['reason_counts']);

                return [
                    'acumatica_id' => $zone['acumatica_id'],
                    'name' => $zone['name'],
                    'region' => $zone['region'],
                    'total_orders' => $zone['total_orders'],
                    'delayed_orders' => $zone['delayed_orders'],
                    'warning_orders' => $zone['warning_orders'],
                    'delayed_pct' => $delayedPct,
                    'delayed_value' => round($zone['delayed_value'], 2),
                    'avg_delay_hours' => $zone['hours_count'] > 0
                        ? round($zone['hours_sum'] / $zone['hours_count'], 1)
                        : null,
                    'primary_reason' => $primaryReason,
                    'meets_min_sample' => $zone['total_orders'] >= $config->alert_min_orders,
                    'alert_triggered' => $zone['total_orders'] >= $config->alert_min_orders
                        && $delayedPct >= (float) $config->alert_delayed_pct,
                ];
            })
            ->sortByDesc('delayed_pct')
            ->values()
            ->take(10)
            ->all();

        $byRegionRows = collect($byRegion)
            ->map(function (array $row) use ($rules) {
                $config = DeliverySlaConfig::forRegionKey($row['region_key']);
                $tracked = $row['total'] - $row['unknown'];

                return [
                    'region_key' => $row['region_key'],
                    'label' => $config->label,
                    'total_orders' => $row['total'],
                    'on_time_orders' => $row['on_time'],
                    'warning_orders' => $row['warning'],
                    'delayed_orders' => $row['breach'],
                    'on_time_pct' => $tracked > 0 ? round(($row['on_time'] / $tracked) * 1000) / 10 : null,
                    'delayed_pct' => $tracked > 0 ? round(($row['breach'] / $tracked) * 1000) / 10 : null,
                    'delayed_value' => round($row['delayed_value'], 2),
                    'avg_delivery_hours' => $row['hours_count'] > 0
                        ? round($row['hours_sum'] / $row['hours_count'], 1)
                        : null,
                    'sla_hours' => $config->sla_hours,
                ];
            })
            ->sortBy('region_key')
            ->values()
            ->all();

        $delayedOrders = collect($evaluated)
            ->where('delivery_sla_status', 'breach')
            ->sortByDesc('order_value')
            ->take(25)
            ->values()
            ->all();

        return [
            'rules' => $rules,
            'summary' => [
                'total_orders' => $total,
                'on_time_count' => $onTimeCount,
                'on_time_pct' => $total > 0 ? round(($onTimeCount / max($total - $unknownCount, 1)) * 1000) / 10 : null,
                'warning_count' => $warningCount,
                'delayed_count' => $breachCount,
                'delayed_pct' => $total > 0 ? round(($breachCount / max($total - $unknownCount, 1)) * 1000) / 10 : null,
                'delayed_value' => $delayedValue,
                'unknown_count' => $unknownCount,
                'avg_delivery_hours' => $avgHours,
            ],
            'by_region' => $byRegionRows,
            'most_affected_zones' => $mostAffected,
            'daily_trend' => collect($daily)->sortBy('day')->values()->all(),
            'delayed_orders' => $delayedOrders,
        ];
    }

    private function executiveAlerts(
        array $status,
        array $customerFocus,
        array $productFocus,
        array $forecasting,
        array $revenue,
        Collection $snapshots,
        array $deliverySla,
        array $zoneGuardrails,
    ): array {
        $alerts = [];

        if ($status['inventory_stale']) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'data_freshness',
                'message'  => 'Inventory has not been synced in over 24 hours — stock figures may be outdated.',
            ];
        }
        if ($status['backorders_stale']) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'data_freshness',
                'message'  => 'Backorders have not been refreshed in over 24 hours.',
            ];
        }
        if ($status['fill_rate_stale']) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'data_freshness',
                'message'  => 'Fill rate has not been recomputed recently for the selected period.',
            ];
        }

        $concentration = $customerFocus['top_customer_concentration_pct'];
        if ($concentration !== null && $concentration >= 40) {
            $top = $customerFocus['top_by_revenue'][0] ?? null;
            $name = $top['customer_name'] ?? 'Top account';
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'customer_focus',
                'message'  => "{$name} accounts for {$concentration}% of backorder revenue at risk — prioritize fulfillment.",
            ];
        }

        if ($productFocus['shortfall_count'] > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'product_focus',
                'message'  => "{$productFocus['shortfall_count']} SKUs have on-hand stock below open backorder qty — production or procurement gap.",
            ];
        }

        if ($forecasting['critical_count'] > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'production_forecast',
                'message'  => "{$forecasting['critical_count']} items predicted to stock out within 7 days based on run rate.",
            ];
        }

        if ($revenue['orders_below_80_pct'] > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'revenue_bleeding',
                'message'  => "{$revenue['orders_below_80_pct']} orders below 80% fill rate — KES "
                    . number_format($revenue['fill_rate_critical_not_shipped'], 0) . ' revenue not shipped.',
            ];
        }

        $zeroShipmentLines = (int) ($revenue['zero_qty_on_shipments_lines'] ?? 0);
        if ($zeroShipmentLines > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'fill_rate',
                'message'  => "{$zeroShipmentLines} order lines show zero quantity on shipments — likely out of stock or not yet allocated to shipments.",
            ];
        }

        $unassignedBackorders = (int) ($revenue['backorders_without_reason'] ?? 0);
        if ($unassignedBackorders > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'backorders',
                'message'  => "{$unassignedBackorders} backorder lines have no reason code assigned — review in Backorders.",
            ];
        }

        if ($revenue['combined_exposure'] > 1_000_000) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'revenue_bleeding',
                'message'  => 'Combined backorder + fill-rate exposure exceeds KES '
                    . number_format($revenue['combined_exposure'], 0) . '.',
            ];
        }

        if ($forecasting['zero_stock_skus'] > 50) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'production_forecast',
                'message'  => "{$forecasting['zero_stock_skus']} catalog SKUs show zero on-hand — verify Acumatica qty fields or run Sync stocks only.",
            ];
        }

        $healthyPct = $snapshots->count() > 0
            ? round($snapshots->where('fill_rate_status', 'healthy')->count() / $snapshots->count() * 1000) / 10
            : null;
        if ($healthyPct !== null && $healthyPct < 50) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'executive',
                'message'  => "Only {$healthyPct}% of tracked orders are healthy (≥95% fill) in this period.",
            ];
        }

        $delayedCount = (int) ($deliverySla['summary']['delayed_count'] ?? 0);
        if ($delayedCount > 0) {
            $delayedValue = number_format((float) ($deliverySla['summary']['delayed_value'] ?? 0), 0);
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'delivery_sla',
                'message'  => "{$delayedCount} orders breached delivery SLA in this period — KES {$delayedValue} delayed value.",
            ];
        }

        foreach ($deliverySla['most_affected_zones'] ?? [] as $zone) {
            if (! ($zone['alert_triggered'] ?? false)) {
                continue;
            }

            $zoneLabel = $zone['name'] ?? $zone['acumatica_id'] ?? 'Unknown zone';
            $alerts[] = [
                'severity' => 'critical',
                'category' => 'delivery_sla',
                'message'  => "{$zoneLabel}: {$zone['delayed_pct']}% of orders delayed ({$zone['delayed_orders']}/{$zone['total_orders']}) — investigate zone.",
            ];
        }

        $unmappedOrders = (int) ($zoneGuardrails['unmapped_with_orders_in_period'] ?? 0);
        if ($unmappedOrders > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'category' => 'zone_mapping',
                'message'  => "{$unmappedOrders} orders in this period belong to customers without a shipping zone — map zones in Acumatica.",
            ];
        }

        $unmappedTotal = (int) ($zoneGuardrails['unmapped_customer_count'] ?? 0);
        if ($unmappedTotal > 0 && $unmappedOrders === 0) {
            $alerts[] = [
                'severity' => 'info',
                'category' => 'zone_mapping',
                'message'  => "{$unmappedTotal} customers have no shipping zone assigned — review customer master data.",
            ];
        }

        if ($alerts === []) {
            $alerts[] = [
                'severity' => 'info',
                'category' => 'executive',
                'message'  => 'No critical optimization alerts — continue monitoring customer and SKU concentration.',
            ];
        }

        return $alerts;
    }

    /** @param  list<string>  $types */
    private function lastCompletedSync(array $types): ?AcumaticaSyncLog
    {
        return AcumaticaSyncLog::query()
            ->whereIn('sync_type', $types)
            ->where('status', 'completed')
            ->orderByDesc('ended_at')
            ->first();
    }

    private function isoMax(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return \Carbon\Carbon::parse($value)->toIso8601String();
    }

    private function isStale(mixed $timestamp): bool
    {
        if ($timestamp === null) {
            return true;
        }

        return \Carbon\Carbon::parse($timestamp)->lt(now()->subDay());
    }
}
