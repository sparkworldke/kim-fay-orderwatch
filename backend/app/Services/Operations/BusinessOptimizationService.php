<?php

namespace App\Services\Operations;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaInventoryRunRateLog;
use App\Models\AcumaticaSyncLog;
use Illuminate\Support\Collection;

class BusinessOptimizationService
{
    public function __construct(
        private readonly OperationsCatalogResolver $catalogResolver,
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
    public function dashboard(string $dateFrom, string $dateTo): array
    {
        $status = $this->opsStatus();

        $backorderLines = AcumaticaBackorderLine::query()->get();
        $fillSnapshots = AcumaticaFillRateSnapshot::query()
            ->whereBetween('computed_at', [$dateFrom, $dateTo . ' 23:59:59'])
            ->get();

        $customerFocus = $this->customerFocus($backorderLines, $fillSnapshots);
        $productFocus  = $this->productFocus($backorderLines);
        $forecasting   = $this->productionForecasting();
        $revenue       = $this->revenueBleeding($backorderLines, $fillSnapshots);
        $executive     = $this->executiveAlerts($status, $customerFocus, $productFocus, $forecasting, $revenue, $fillSnapshots);

        return [
            'date_from'          => $dateFrom,
            'date_to'            => $dateTo,
            'ops_status'         => $status,
            'customer_focus'     => $customerFocus,
            'product_focus'      => $productFocus,
            'production_forecast'=> $forecasting,
            'revenue_bleeding'   => $revenue,
            'executive_alerts'   => $executive,
            'charts'             => [
                'backorders_by_customer'  => $customerFocus['top_by_revenue'],
                'fill_rate_by_status'     => $this->fillRateByStatus($fillSnapshots),
                'stockout_risk_products'  => $forecasting['at_risk_items'],
                'revenue_bleeding_split'  => [
                    ['label' => 'Backorders at risk', 'value' => $revenue['backorder_revenue_at_risk']],
                    ['label' => 'Fill rate not shipped', 'value' => $revenue['fill_rate_not_shipped']],
                ],
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
    private function revenueBleeding(Collection $lines, Collection $snapshots): array
    {
        $backorderRisk = round((float) $lines->sum('revenue_at_risk'), 2);
        $eligible      = $snapshots->where('fill_rate_status', '!=', 'na');
        $notShipped    = round((float) $eligible->sum('revenue_not_shipped'), 2);
        $criticalRev   = round((float) $snapshots->where('fill_rate_status', 'critical')->sum('revenue_not_shipped'), 2);

        return [
            'backorder_revenue_at_risk'  => $backorderRisk,
            'fill_rate_not_shipped'      => $notShipped,
            'fill_rate_critical_not_shipped' => $criticalRev,
            'combined_exposure'          => round($backorderRisk + $notShipped, 2),
            'open_backorder_lines'       => $lines->count(),
            'orders_below_80_pct'        => $snapshots->where('fill_rate_status', 'critical')->count(),
        ];
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

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $customerFocus
     * @param  array<string, mixed>  $productFocus
     * @param  array<string, mixed>  $forecasting
     * @param  array<string, mixed>  $revenue
     * @param  Collection<int, AcumaticaFillRateSnapshot>  $snapshots
     * @return list<array{severity: string, category: string, message: string}>
     */
    private function executiveAlerts(
        array $status,
        array $customerFocus,
        array $productFocus,
        array $forecasting,
        array $revenue,
        Collection $snapshots,
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