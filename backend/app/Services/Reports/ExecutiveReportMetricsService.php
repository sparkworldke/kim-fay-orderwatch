<?php

namespace App\Services\Reports;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaFillRateSnapshot;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\DeliverySlaConfig;
use App\Services\Operations\DeliverySlaEvaluator;
use App\Services\Operations\OperationsCatalogResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExecutiveReportMetricsService
{
    public function __construct(
        private readonly OperationsCatalogResolver $catalogResolver,
        private readonly DeliverySlaEvaluator $deliverySla,
    ) {}

    /**
     * @return list<array{reason_code:string,reason_label:string,source:string,line_count:int,revenue_at_risk:float}>
     */
    public function topCombinedReasons(string $reportDate, int $limit = 5): array
    {
        $backorder = collect($this->backordersByReasonForDate($reportDate))
            ->map(fn (array $row) => [
                'reason_code' => $row['reason_code'],
                'reason_label' => $this->formatReasonLabel($row['reason_code']),
                'source' => 'backorder',
                'line_count' => $row['line_count'],
                'revenue_at_risk' => $row['revenue_at_risk'],
            ]);

        $fill = collect($this->fillRateUnfilledReasons($reportDate, $reportDate))
            ->map(fn (array $row) => [
                'reason_code' => $row['reason_code'],
                'reason_label' => $this->formatReasonLabel($row['reason_code']),
                'source' => 'fill_rate',
                'line_count' => $row['line_count'],
                'revenue_at_risk' => $row['revenue_at_risk'],
            ]);

        return $backorder
            ->concat($fill)
            ->groupBy('reason_code')
            ->map(function (Collection $group, string $reasonCode) {
                $first = $group->first();

                return [
                    'reason_code' => $reasonCode,
                    'reason_label' => $first['reason_label'] ?? $this->formatReasonLabel($reasonCode),
                    'source' => $group->pluck('source')->unique()->sort()->implode(' + '),
                    'line_count' => (int) $group->sum('line_count'),
                    'revenue_at_risk' => round((float) $group->sum('revenue_at_risk'), 2),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<array{inventory_id:string,product_name:string,revenue_at_risk:float,open_lines:int}>
     */
    public function topSkusByBackorderValue(string $reportDate, int $limit = 5): array
    {
        $lines = $this->backorderLinesForDate($reportDate)->get();
        $descriptions = $this->inventoryDescriptionsForLines($lines->pluck('inventory_id')->all());

        return $lines
            ->groupBy('inventory_id')
            ->map(function (Collection $group, $inventoryId) use ($descriptions) {
                $productName = $this->resolveSkuName((string) $inventoryId, $descriptions);

                return [
                    'inventory_id' => (string) $inventoryId,
                    'product_name' => $productName,
                    'revenue_at_risk' => round((float) $group->sum('revenue_at_risk'), 2),
                    'open_lines' => $group->count(),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return list<array{customer_name:string,revenue_at_risk:float,open_lines:int}>
     */
    public function topCustomers(string $reportDate, int $limit = 5): array
    {
        $lines = $this->backorderLinesForDate($reportDate)->get();
        $customerIds = $lines->pluck('customer_acumatica_id')->filter()->unique()->values()->all();

        $customers = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $customerIds)
            ->pluck('name', 'acumatica_id');

        return $lines
            ->groupBy(fn (AcumaticaBackorderLine $line) => $line->customer_acumatica_id ?: trim((string) $line->customer_name))
            ->map(function (Collection $group) use ($customers) {
                /** @var AcumaticaBackorderLine $first */
                $first = $group->first();
                $customerId = $first->customer_acumatica_id;
                $customerName = $customerId
                    ? (string) ($customers->get($customerId) ?? $first->customer_name ?? 'Unknown customer')
                    : (string) ($first->customer_name ?? 'Unknown customer');

                return [
                    'customer_name' => $customerName,
                    'revenue_at_risk' => round((float) $group->sum('revenue_at_risk'), 2),
                    'open_lines' => $group->count(),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->take($limit)
            ->values()
            ->all();
    }

    public function fillRateSummary(string $reportDate): array
    {
        $snapshots = $this->fillSnapshotsForOrderDate($reportDate)->get();
        $eligible = $snapshots->where('fill_rate_status', '!=', 'na');
        $avgFill = $eligible->where('fill_rate_pct', '!=', null)->avg('fill_rate_pct');

        $backorderValue = round((float) $this->backorderLinesForDate($reportDate)->sum('acumatica_backorder_lines.revenue_at_risk'), 2);
        $notShipped = round((float) $eligible->sum('revenue_not_shipped'), 2);
        $denominator = $notShipped + $backorderValue;
        $backorderExposurePct = $denominator > 0
            ? round(($backorderValue / $denominator) * 1000) / 10
            : 0.0;

        return [
            'fill_rate_pct' => $avgFill !== null ? round((float) $avgFill, 1) : null,
            'backorder_revenue_at_risk' => $backorderValue,
            'fill_rate_not_shipped' => $notShipped,
            'backorder_exposure_pct' => $backorderExposurePct,
            'orders_tracked' => $eligible->count(),
        ];
    }

    /**
     * @return array{nairobi: array<string, mixed>, mombasa: array<string, mixed>}
     */
    public function metroSlaSummary(string $reportDate): array
    {
        $snapshots = $this->fillSnapshotsForOrderDate($reportDate)
            ->with([
                'order:id,acumatica_order_nbr,customer_acumatica_id,customer_name,status,order_date,approved_at,shipped_at,ship_date,completed_at,order_total',
                'order.customer:acumatica_id,shipping_zone_id,name',
                'order.customer.shippingZone:acumatica_id,description,name,region',
            ])
            ->get();

        $byRegion = [
            'nairobi' => ['region_key' => 'nairobi', 'label' => 'Nairobi', 'total' => 0, 'completed' => 0, 'delayed' => 0, 'delayed_value' => 0.0],
            'coast' => ['region_key' => 'coast', 'label' => 'Mombasa', 'total' => 0, 'completed' => 0, 'delayed' => 0, 'delayed_value' => 0.0],
        ];

        foreach ($snapshots as $snapshot) {
            $order = $snapshot->order;
            $zone = $order?->customer?->shippingZone;
            $isCompleted = $this->isCompletedOrder($order);
            $sla = $this->deliverySla->evaluate(
                $order?->order_date,
                $order?->approved_at,
                $isCompleted ? ($order?->completed_at ?? $order?->shipped_at) : null,
                $isCompleted ? $order?->ship_date : null,
                $zone?->acumatica_id ?? $order?->customer?->shipping_zone_id,
                $zone?->description,
                null,
                $zone?->region,
            );

            $regionKey = (string) ($sla['region_key'] ?? 'other');
            if (! isset($byRegion[$regionKey]) || $sla['delivery_sla_status'] === 'unknown') {
                continue;
            }

            $byRegion[$regionKey]['total']++;
            if ($isCompleted) {
                $byRegion[$regionKey]['completed']++;
            }

            if (! $isCompleted && $sla['delivery_sla_status'] === 'breach') {
                $byRegion[$regionKey]['delayed']++;
                $byRegion[$regionKey]['delayed_value'] += (float) ($order?->order_total ?? $snapshot->revenue_not_shipped ?? 0);
            }
        }

        $result = [];
        foreach ($byRegion as $key => $row) {
            $tracked = $row['total'];
            $delayedPct = $tracked > 0 ? round(($row['delayed'] / $tracked) * 1000) / 10 : 0.0;
            $config = DeliverySlaConfig::forRegionKey($row['region_key']);

            $result[$key === 'coast' ? 'mombasa' : $key] = [
                'label' => $row['label'],
                'sla_hours' => $config->sla_hours,
                'total_orders' => $tracked,
                'completed_orders' => $row['completed'],
                'undelivered_orders' => max(0, $tracked - $row['completed']),
                'delayed_orders' => $row['delayed'],
                'delayed_pct' => $delayedPct,
                'on_time_pct' => $tracked > 0 ? round((($tracked - $row['delayed']) / $tracked) * 1000) / 10 : null,
                'delayed_value' => round($row['delayed_value'], 2),
            ];
        }

        return [
            'nairobi' => $result['nairobi'] ?? $this->emptyMetroRow('Nairobi'),
            'mombasa' => $result['mombasa'] ?? $this->emptyMetroRow('Mombasa'),
        ];
    }

    private function isCompletedOrder(mixed $order): bool
    {
        if (! $order) {
            return false;
        }

        return strtolower(trim((string) ($order->status ?? ''))) === 'completed';
    }

    /**
     * @return array{kp: float, cs: float, unclassified: float, total: float}
     */
    public function revenueSplitForDate(string $date): array
    {
        [$from, $to] = $this->dayBounds($date);

        $rows = DB::table('acumatica_sales_orders as o')
            ->leftJoin('acumatica_customers as c', 'c.acumatica_id', '=', 'o.customer_acumatica_id')
            ->where('o.order_type', 'SO')
            ->whereBetween('o.order_date', [$from, $to])
            ->selectRaw("
                SUM(CASE WHEN UPPER(TRIM(c.customer_class)) LIKE 'KP%' THEN o.order_total ELSE 0 END) as kp_total,
                SUM(CASE WHEN UPPER(TRIM(c.customer_class)) LIKE 'CS%' THEN o.order_total ELSE 0 END) as cs_total,
                SUM(CASE WHEN c.customer_class IS NULL OR (UPPER(TRIM(c.customer_class)) NOT LIKE 'KP%' AND UPPER(TRIM(c.customer_class)) NOT LIKE 'CS%') THEN o.order_total ELSE 0 END) as unclassified_total,
                SUM(o.order_total) as grand_total
            ")
            ->first();

        return [
            'kp' => round((float) ($rows->kp_total ?? 0), 2),
            'cs' => round((float) ($rows->cs_total ?? 0), 2),
            'unclassified' => round((float) ($rows->unclassified_total ?? 0), 2),
            'total' => round((float) ($rows->grand_total ?? 0), 2),
        ];
    }

    /** @return Builder<AcumaticaBackorderLine> */
    private function backorderLinesForDate(string $reportDate): Builder
    {
        [$from, $to] = $this->dayBounds($reportDate);

        return AcumaticaBackorderLine::query()
            ->select('acumatica_backorder_lines.*')
            ->join('acumatica_sales_orders as o', 'o.acumatica_order_nbr', '=', 'acumatica_backorder_lines.order_nbr')
            ->where('o.order_type', 'SO')
            ->whereBetween('o.order_date', [$from, $to]);
    }

    /** @return Builder<AcumaticaFillRateSnapshot> */
    private function fillSnapshotsForOrderDate(string $reportDate): Builder
    {
        [$from, $to] = $this->dayBounds($reportDate);

        return AcumaticaFillRateSnapshot::query()
            ->whereIn('sales_order_id', function ($query) use ($from, $to) {
                $query->select('id')
                    ->from('acumatica_sales_orders')
                    ->where('order_type', 'SO')
                    ->whereBetween('order_date', [$from, $to]);
            });
    }

    /** @return list<array{reason_code:string,line_count:int,revenue_at_risk:float}> */
    private function backordersByReasonForDate(string $reportDate): array
    {
        return $this->backorderLinesForDate($reportDate)
            ->whereNotNull('acumatica_backorder_lines.reason_code')
            ->where('acumatica_backorder_lines.reason_code', '!=', '')
            ->get()
            ->groupBy(fn ($line) => trim((string) $line->reason_code))
            ->map(function ($group, $reasonCode) {
                return [
                    'reason_code' => (string) $reasonCode,
                    'line_count' => $group->count(),
                    'revenue_at_risk' => round((float) $group->sum('revenue_at_risk'), 2),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values()
            ->all();
    }

    /** @return list<array{reason_code:string,line_count:int,revenue_at_risk:float}> */
    private function fillRateUnfilledReasons(string $dateFrom, string $dateTo): array
    {
        [$from, $to] = $this->dayBounds($dateFrom);

        $lines = AcumaticaSalesOrderLine::query()
            ->join('acumatica_sales_orders', 'acumatica_sales_orders.id', '=', 'acumatica_sales_order_lines.sales_order_id')
            ->where('acumatica_sales_orders.order_type', 'SO')
            ->where('acumatica_sales_order_lines.qty_on_shipments', '<=', 0)
            ->where(function ($q) {
                $q->where('acumatica_sales_order_lines.qty_at_approval', '>', 0)
                    ->orWhere('acumatica_sales_order_lines.order_qty', '>', 0);
            })
            ->whereBetween('acumatica_sales_orders.order_date', [$from, $to])
            ->whereNotNull('acumatica_sales_order_lines.unfilled_reason_code')
            ->where('acumatica_sales_order_lines.unfilled_reason_code', '!=', '')
            ->get([
                'acumatica_sales_order_lines.unfilled_reason_code',
                'acumatica_sales_order_lines.qty_at_approval',
                'acumatica_sales_order_lines.order_qty',
                'acumatica_sales_order_lines.unit_price',
            ]);

        return $lines
            ->groupBy(fn ($line) => trim((string) $line->unfilled_reason_code))
            ->map(function ($group, $reasonCode) {
                $revenue = $group->sum(function ($line) {
                    $qty = max((float) $line->qty_at_approval, (float) $line->order_qty);

                    return $qty * (float) $line->unit_price;
                });

                return [
                    'reason_code' => (string) $reasonCode,
                    'line_count' => $group->count(),
                    'revenue_at_risk' => round($revenue, 2),
                ];
            })
            ->sortByDesc('revenue_at_risk')
            ->values()
            ->all();
    }

    /** @return Collection<string, string> keyed by uppercase inventory id */
    private function inventoryDescriptionsForLines(array $inventoryIds): Collection
    {
        $normalized = collect($inventoryIds)
            ->filter()
            ->map(fn ($id) => strtoupper(trim((string) $id)))
            ->unique()
            ->values()
            ->all();

        if ($normalized === []) {
            return collect();
        }

        $items = AcumaticaInventoryItem::query()
            ->where(function ($query) use ($normalized) {
                foreach ($normalized as $id) {
                    $query->orWhereRaw('UPPER(TRIM(inventory_id)) = ?', [$id]);
                }
            })
            ->get(['inventory_id', 'description']);

        return $items->mapWithKeys(fn (AcumaticaInventoryItem $item) => [
            strtoupper(trim((string) $item->inventory_id)) => trim((string) $item->description),
        ]);
    }

    /** @param  Collection<string, string>  $descriptions */
    private function resolveSkuName(string $inventoryId, Collection $descriptions): string
    {
        $key = strtoupper(trim($inventoryId));
        $name = $descriptions->get($key);

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $resolved = $this->catalogResolver->resolveProductName($inventoryId, null, $descriptions);
        if (is_string($resolved) && trim($resolved) !== '' && strtoupper(trim($resolved)) !== $key) {
            return trim($resolved);
        }

        return 'Product '.$inventoryId;
    }

    private function formatReasonLabel(string $reasonCode): string
    {
        $label = trim(str_replace('_', ' ', $reasonCode));

        return $label !== '' ? ucwords(strtolower($label)) : 'Unknown reason';
    }

    /** @return array{0: string, 1: string} */
    private function dayBounds(string $date): array
    {
        return [$date.' 00:00:00', $date.' 23:59:59'];
    }

    /** @return array<string, mixed> */
    private function emptyMetroRow(string $label): array
    {
        return [
            'label' => $label,
            'sla_hours' => 24,
            'total_orders' => 0,
            'completed_orders' => 0,
            'undelivered_orders' => 0,
            'delayed_orders' => 0,
            'delayed_pct' => 0.0,
            'on_time_pct' => null,
            'delayed_value' => 0.0,
        ];
    }
}
