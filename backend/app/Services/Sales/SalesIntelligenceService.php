<?php

namespace App\Services\Sales;

use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Services\Team\KpCrmAccessService;
use App\Services\Team\UserCapabilitiesService;
use App\Services\Team\AccessTierService;
use App\Services\Team\CustomerAttributionService;
use App\Services\Team\OrgTreeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Support\DataScope;

class SalesIntelligenceService
{
    public function __construct(
        private readonly KpCrmAccessService $kpAccess,
        private readonly UserCapabilitiesService $capabilities,
        private readonly AccessTierService $accessTier,
        private readonly CustomerAttributionService $attribution,
        private readonly OrgTreeService $orgTree,
    ) {}

    /**
     * Resolve the authorized customer Acumatica IDs for one or more channels.
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    public function channelCustomerIds(User $viewer, array $channels, ?string $region = null): array
    {
        $channels = array_values(array_unique(array_map('strtoupper', array_filter(array_map('trim', $channels)))));
        abort_if($channels === [], 422, 'A Sales Intelligence channel is required.');

        $allowedChannels = $this->capabilities->forUser($viewer)['sales_intelligence_channels'] ?? [];

        // Keep only the channels the viewer is actually authorized to see.
        $authorized = array_values(array_filter(
            $channels,
            function (string $channel) use ($allowedChannels, $viewer): bool {
                if ($channel === 'PORTFOLIO') {
                    return in_array('portfolio', $allowedChannels, true);
                }
                if ($channel === 'KP') {
                    return in_array('kp', $allowedChannels, true) && $this->kpAccess->canAccess($viewer);
                }
                if ($channel === 'MT') {
                    return in_array('mt1', $allowedChannels, true) || in_array('mt2', $allowedChannels, true);
                }

                return in_array(strtolower($channel), $allowedChannels, true);
            },
        ));
        abort_if($authorized === [], 403, 'This Sales Intelligence channel is not authorized.');

        $includePortfolio = in_array('PORTFOLIO', $authorized, true);
        $businessChannels = array_values(array_diff($authorized, ['PORTFOLIO']));
        $portfolioIds = $includePortfolio ? $this->hierarchyPortfolioCustomerIds($viewer) : [];

        if ($businessChannels === []) {
            $ids = $portfolioIds;
        } else {
            $ids = DataScope::scopedCustomerAcumaticaIds($viewer);
            if ($ids === []) {
                $ids = $portfolioIds;
            } elseif ($includePortfolio) {
                $ids = array_values(array_unique(array_merge($ids, $portfolioIds)));
            }
        }

        if ($ids === []) {
            return [];
        }

        $customers = DB::table('acumatica_customers');
        if ($ids !== null) {
            $customers->whereIn('acumatica_id', $ids);
        }
        if ($region !== null && trim($region) !== '' && Schema::hasColumn('acumatica_customers', 'sales_region')) {
            $customers->whereRaw('LOWER(TRIM(sales_region)) = ?', [strtolower(trim($region))]);
        }

        return $customers
            ->when($businessChannels !== [], function ($query) use ($businessChannels) {
                $query->where(function ($subQuery) use ($businessChannels) {
                    foreach ($businessChannels as $channel) {
                        $subQuery->orWhere(function ($channelQuery) use ($channel) {
                            if ($channel === 'MT') {
                                $channelQuery->whereIn('sales_channel_code', ['MT', 'MT1', 'MT2']);
                            } elseif ($channel === 'KP') {
                                $channelQuery->where('sales_channel_code', 'KP')
                                    ->orWhere('customer_class', 'like', 'KP%');
                            } else {
                                $channelQuery->where('sales_channel_code', $channel);
                            }
                        });
                    }
                });
            })
            ->distinct()
            ->pluck('acumatica_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * My Portfolio follows the reporting tree regardless of broad business access:
     * member = self, HOD = self + descendants, CCO = the full descendant tree.
     * Customer IDs are de-duplicated so the same outlet never inflates totals.
     *
     * @return list<string>
     */
    public function hierarchyPortfolioCustomerIds(User $viewer): array
    {
        $userIds = $this->orgTree->descendantIds($viewer->id, true);

        return collect($userIds)
            ->flatMap(fn (int $userId) => $this->attribution->directCustomerIds($userId))
            ->filter(fn ($customerId) => trim((string) $customerId) !== '')
            ->map(fn ($customerId) => (string) $customerId)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    public function metrics(
        User $viewer,
        array $channels,
        ?string $from,
        ?string $to,
        ?string $region = null,
        ?string $comparison = null,
    ): array
    {
        $fromDate = $from ? Carbon::parse($from)->startOfDay() : now('Africa/Nairobi')->startOfMonth();
        $toDate = $to ? Carbon::parse($to)->endOfDay() : now('Africa/Nairobi')->endOfDay();
        if ($fromDate->gt($toDate)) {
            throw ValidationException::withMessages(['from' => ['The from date must be before the to date.']]);
        }

        $allChannelIds = $this->channelCustomerIds($viewer, $channels);
        $ids = $this->channelCustomerIds($viewer, $channels, $region);
        $orders = DB::table('acumatica_sales_orders')
            ->whereIn('customer_acumatica_id', $ids ?: ['__none__'])
            ->whereBetween('order_date', [$fromDate, $toDate]);

        $gross = (clone $orders)->where('order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER);
        $credits = (clone $orders)->whereIn('order_type', [
            AcumaticaSalesOrder::TYPE_CREDIT_NOTE,
            AcumaticaSalesOrder::TYPE_CREDIT_MEMO,
        ]);
        $grossRevenue = (float) $gross->sum('order_total');
        $creditRevenue = abs((float) $credits->sum('order_total'));

        $volumes = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->whereIn('o.customer_acumatica_id', $ids ?: ['__none__'])
            ->whereBetween('o.order_date', [$fromDate, $toDate]);
        $orderedVolume = (float) (clone $volumes)
            ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->sum('l.order_qty');
        $creditedVolume = abs((float) (clone $volumes)
            ->whereIn('o.order_type', [AcumaticaSalesOrder::TYPE_CREDIT_NOTE, AcumaticaSalesOrder::TYPE_CREDIT_MEMO])
            ->sum('l.order_qty'));

        $customers = $this->customerRows($ids, $fromDate, $toDate);
        $notOrdered = array_values(array_filter(
            $customers,
            static fn (array $customer): bool => $customer['so_count'] === 0,
        ));

        $regions = Schema::hasColumn('acumatica_customers', 'sales_region')
            ? DB::table('acumatica_customers')
                ->whereIn('acumatica_id', $allChannelIds ?: ['__none__'])
                ->whereNotNull('sales_region')
                ->where('sales_region', '!=', '')
                ->distinct()
                ->orderBy('sales_region')
                ->pluck('sales_region')
                ->values()
                ->all()
            : [];

        $comparisonResult = $comparison
            ? $this->comparisonMetrics($ids, $fromDate, $toDate, $comparison, $customers)
            : null;

        return [
            'scope' => [
                'channel' => strtoupper(implode(',', $channels)),
                'customer_count' => count($ids),
                'customer_ids' => $ids,
                'portfolio_scoped' => in_array('PORTFOLIO', array_map('strtoupper', $channels), true)
                    || $this->accessTier->isExclusivelySalesConsultant($viewer),
                'portfolio_scope' => in_array('PORTFOLIO', array_map('strtoupper', $channels), true)
                    ? ($viewer->reportees()->where('is_active', true)->exists() ? 'team' : 'own')
                    : null,
                'not_ordered_count' => count($notOrdered),
                'region' => $region,
                'available_regions' => $regions,
            ],
            'period' => ['from' => $fromDate->toDateString(), 'to' => $toDate->toDateString(), 'timezone' => 'Africa/Nairobi'],
            'metrics' => [
                'so_count' => (clone $gross)->distinct()->count('id'),
                'credit_note_count' => (clone $credits)->distinct()->count('id'),
                'gross_revenue' => round($grossRevenue, 2),
                'credit_revenue' => round($creditRevenue, 2),
                'net_revenue' => round($grossRevenue - $creditRevenue, 2),
                'ordered_volume' => round($orderedVolume, 4),
                'credited_volume' => round($creditedVolume, 4),
                'net_volume' => round($orderedVolume - $creditedVolume, 4),
            ],
            'customers' => $customers,
            'not_ordered_customers' => $notOrdered,
            'comparison' => $comparisonResult,
        ];
    }

    /**
     * Per-customer commercial rollup for the channel scope and period.
     *
     * @param  list<string>  $customerIds
     * @return list<array<string, mixed>>
     */
    private function customerRows(array $customerIds, Carbon $fromDate, Carbon $toDate): array
    {
        if ($customerIds === []) {
            return [];
        }

        $detailColumns = [
            'acumatica_id',
            'name',
            'sales_channel_code',
            'customer_class',
            'route_code',
        ];
        if (Schema::hasColumn('acumatica_customers', 'sales_region')) {
            $detailColumns[] = 'sales_region';
        }

        $customerDetails = DB::table('acumatica_customers')
            ->whereIn('acumatica_id', $customerIds)
            ->get($detailColumns)
            ->keyBy('acumatica_id');

        $representatives = DB::table('user_customer_assignments as uca')
            ->join('users as u', 'u.id', '=', 'uca.user_id')
            ->whereIn('uca.customer_acumatica_id', $customerIds)
            ->whereIn('uca.assignment_type', ['servicing', 'primary'])
            ->where('u.is_active', true)
            ->orderBy('u.name')
            ->get(['uca.customer_acumatica_id as customer_id', 'u.id', 'u.name', 'u.rep_code'])
            ->groupBy('customer_id')
            ->map(fn ($rows) => $rows->unique('id')->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'rep_code' => $row->rep_code ? (string) $row->rep_code : null,
            ])->values()->all());

        $so = AcumaticaSalesOrder::TYPE_SALES_ORDER;
        $rc = AcumaticaSalesOrder::TYPE_CREDIT_NOTE;
        $cm = AcumaticaSalesOrder::TYPE_CREDIT_MEMO;

        $orderAgg = DB::table('acumatica_sales_orders')
            ->whereIn('customer_acumatica_id', $customerIds)
            ->whereBetween('order_date', [$fromDate, $toDate])
            ->selectRaw('customer_acumatica_id as customer_id')
            ->selectRaw("SUM(CASE WHEN order_type = '{$so}' THEN 1 ELSE 0 END) as so_count")
            ->selectRaw("SUM(CASE WHEN order_type IN ('{$rc}','{$cm}') THEN 1 ELSE 0 END) as credit_note_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN order_type = '{$so}' THEN order_total ELSE 0 END), 0) as gross_revenue")
            ->selectRaw("COALESCE(SUM(CASE WHEN order_type IN ('{$rc}','{$cm}') THEN ABS(order_total) ELSE 0 END), 0) as credit_revenue")
            ->selectRaw("MAX(CASE WHEN order_type = '{$so}' THEN order_date ELSE NULL END) as last_order_date")
            ->selectRaw("SUM(CASE WHEN order_type = '{$so}' AND LOWER(TRIM(COALESCE(status,''))) IN ('open','shipping','pending approval','on hold','credit hold') THEN 1 ELSE 0 END) as open_so_count")
            ->groupBy('customer_acumatica_id')
            ->get()
            ->keyBy('customer_id');

        $volumeAgg = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->whereIn('o.customer_acumatica_id', $customerIds)
            ->whereBetween('o.order_date', [$fromDate, $toDate])
            ->selectRaw('o.customer_acumatica_id as customer_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN o.order_type = '{$so}' THEN l.order_qty ELSE 0 END), 0) as ordered_volume")
            ->selectRaw("COALESCE(SUM(CASE WHEN o.order_type IN ('{$rc}','{$cm}') THEN ABS(l.order_qty) ELSE 0 END), 0) as credited_volume")
            ->groupBy('o.customer_acumatica_id')
            ->get()
            ->keyBy('customer_id');

        $rows = [];
        foreach ($customerIds as $id) {
            $orders = $orderAgg->get($id);
            $volume = $volumeAgg->get($id);
            $gross = (float) ($orders->gross_revenue ?? 0);
            $credit = (float) ($orders->credit_revenue ?? 0);
            $orderedVol = (float) ($volume->ordered_volume ?? 0);
            $creditedVol = (float) ($volume->credited_volume ?? 0);
            $details = $customerDetails->get($id);
            $name = $details->name ?? null;
            $name = is_string($name) && trim($name) !== '' ? trim($name) : $id;

            $rows[] = [
                'customer_id' => $id,
                'customer_name' => $name,
                'sales_channel' => $details?->sales_channel_code,
                'customer_class' => $details?->customer_class,
                'route_code' => $details?->route_code,
                'region' => $details->sales_region ?? null,
                'representatives' => $representatives->get($id, []),
                'so_count' => (int) ($orders->so_count ?? 0),
                'credit_note_count' => (int) ($orders->credit_note_count ?? 0),
                'open_so_count' => (int) ($orders->open_so_count ?? 0),
                'gross_revenue' => round($gross, 2),
                'credit_revenue' => round($credit, 2),
                'net_revenue' => round($gross - $credit, 2),
                'ordered_volume' => round($orderedVol, 4),
                'credited_volume' => round($creditedVol, 4),
                'net_volume' => round($orderedVol - $creditedVol, 4),
                'last_order_date' => $orders?->last_order_date
                    ? Carbon::parse($orders->last_order_date)->toDateString()
                    : null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['net_revenue'], $b['so_count'], $a['customer_name']]
                <=> [$a['net_revenue'], $a['so_count'], $b['customer_name']];
        });

        return $rows;
    }

    /** @param list<string> $customerIds @param list<array<string,mixed>> $currentRows */
    private function comparisonMetrics(
        array $customerIds,
        Carbon $fromDate,
        Carbon $toDate,
        string $preset,
        array $currentRows,
    ): array {
        $months = match ($preset) {
            'past_3_months' => 3,
            'past_6_months' => 6,
            default => 1,
        };
        $comparisonTo = $fromDate->copy()->subDay()->endOfDay();
        $comparisonFrom = $months === 1
            ? $fromDate->copy()->subMonthNoOverflow()->startOfMonth()
            : $fromDate->copy()->subMonthsNoOverflow($months)->startOfDay();

        $previousRows = $this->customerRows($customerIds, $comparisonFrom, $comparisonTo);
        $previousById = collect($previousRows)->keyBy('customer_id');
        $skuRows = function (Carbon $start, Carbon $end) use ($customerIds) {
            return DB::table('acumatica_sales_order_lines as l')
                ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
                ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
                ->whereIn('o.customer_acumatica_id', $customerIds ?: ['__none__'])
                ->whereBetween('o.order_date', [$start, $end])
                ->whereNotNull('l.inventory_id')
                ->where('l.inventory_id', '!=', '')
                ->distinct()
                ->get(['o.customer_acumatica_id as customer_id', 'l.inventory_id'])
                ->groupBy('customer_id')
                ->map(fn ($rows) => $rows->pluck('inventory_id')->map(fn ($sku) => (string) $sku)->unique()->values()->all());
        };
        $currentSkus = $skuRows($fromDate, $toDate);
        $previousSkus = $skuRows($comparisonFrom, $comparisonTo);

        $outletComparisons = collect($currentRows)->map(function (array $current) use ($previousById, $currentSkus, $previousSkus) {
            $previous = $previousById->get($current['customer_id']);
            $previousRevenue = (float) ($previous['net_revenue'] ?? 0);
            $previousOrders = (int) ($previous['so_count'] ?? 0);
            $missingSkus = array_values(array_diff(
                $previousSkus->get($current['customer_id'], []),
                $currentSkus->get($current['customer_id'], []),
            ));

            return [
                'customer_id' => $current['customer_id'],
                'customer_name' => $current['customer_name'],
                'sales_channel' => $current['sales_channel'],
                'region' => $current['region'],
                'representatives' => $current['representatives'],
                'current_revenue' => $current['net_revenue'],
                'comparison_revenue' => $previousRevenue,
                'revenue_change' => round((float) $current['net_revenue'] - $previousRevenue, 2),
                'current_orders' => $current['so_count'],
                'comparison_orders' => $previousOrders,
                'order_change' => (int) $current['so_count'] - $previousOrders,
                'missing_sku_count' => count($missingSkus),
                'missing_skus' => $missingSkus,
                'declined' => (float) $current['net_revenue'] < $previousRevenue
                    || (int) $current['so_count'] < $previousOrders
                    || $missingSkus !== [],
            ];
        })->filter(fn (array $row) => $row['declined'])->values()->all();

        return [
            'preset' => $preset,
            'from' => $comparisonFrom->toDateString(),
            'to' => $comparisonTo->toDateString(),
            'declined_outlet_count' => count($outletComparisons),
            'outlets' => $outletComparisons,
        ];
    }
}
