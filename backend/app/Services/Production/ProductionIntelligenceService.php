<?php

namespace App\Services\Production;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\DailyStockSummary;
use App\Models\MonthlySkuSummary;
use App\Services\Admin\ProductBrandClassifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductionIntelligenceService
{
    public function __construct(private readonly ProductBrandClassifier $classifier)
    {
    }

    public function inventory(Request $request): array
    {
        $from = Carbon::parse($request->input('date_from', now()->startOfYear()))->startOfMonth();
        $to = Carbon::parse($request->input('date_to', now()->endOfMonth()))->endOfMonth();

        $query = AcumaticaInventoryItem::query()
            ->with(['productionPlan.machines', 'warehouseBalances', 'catalogueProduct.brand',
                'catalogueProduct.category', 'catalogueProduct.subCategory', 'catalogueProduct.tradingGroup'])
            ->where('is_stock_item', true)
            ->withSum('warehouseBalances as stock_on_hand_sort', 'qty_on_hand')
            ->orderByDesc('stock_on_hand_sort')
            ->orderBy('inventory_id');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(fn ($q) => $q
                ->where('inventory_id', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('brand', 'like', "%{$search}%")
                ->orWhereHas('catalogueProduct', fn ($p) => $p->where('name', 'like', "%{$search}%")
                    ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$search}%"))));
        }
        if ($brand = $request->input('brand')) {
            $query->whereHas('catalogueProduct.brand', fn ($b) => $b->whereIn('name', (array) $brand));
        }
        if ($ownership = $request->input('ownership')) {
            $query->where(function ($owned) use ($ownership) {
                $owned->whereHas('catalogueProduct', fn ($product) => $product->where('ownership', $ownership))
                    ->orWhereHas('productionPlan', fn ($plan) => $plan->where('ownership', $ownership))
                    ->orWhere('product_type', $ownership);
            });
        }
        foreach (['business_line', 'site'] as $field) {
            if ($values = array_values(array_filter((array) $request->input($field, [])))) {
                $query->whereHas('productionPlan', fn ($plan) => $plan->whereIn($field, $values));
            }
        }
        if ($values = array_values(array_filter((array) $request->input('category', [])))) {
            $query->whereHas('catalogueProduct.category', fn ($category) => $category->whereIn('name', $values));
        }
        if ($values = array_values(array_filter((array) $request->input('trading_group', [])))) {
            $query->whereHas('catalogueProduct.tradingGroup', fn ($group) => $group->whereIn('name', $values));
        }
        if ($values = array_values(array_filter((array) $request->input('machine', [])))) {
            $query->whereHas('productionPlan', fn ($plan) => $plan
                ->whereIn('machine', $values)
                ->orWhereHas('machines', fn ($machines) => $machines->whereIn('name', $values)));
        }

        $perPage = min(100, max(1, $request->integer('per_page', 75)));
        $paginator = $query->paginate($perPage);
        $items = collect($paginator->items());
        $monthly = $this->monthlySummaries($items->pluck('inventory_id'), $from, $to);
        $warehouseFilter = collect((array) $request->input('warehouse_ids'))->filter()->map(fn ($v) => strtoupper((string) $v));

        $rows = $items->map(fn (AcumaticaInventoryItem $item) =>
            $this->inventoryRow($item, $monthly->get($item->inventory_id, collect()), $warehouseFilter)
        );

        if ($statuses = array_values(array_filter((array) $request->input('status', [])))) {
            $rows = $rows->whereIn('status', $statuses);
        }

        $rows = $rows->values();

        return [
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $perPage,
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'next_page_url' => $paginator->nextPageUrl(),
                'freshness' => app(ProductionSummaryService::class)->versions(),
            ],
        ];
    }

    public function detail(string $inventoryId, Request $request): array
    {
        $item = AcumaticaInventoryItem::with([
            'productionPlan.machines', 'warehouseBalances', 'warehouseBalanceSnapshots', 'productionMonthlyOutputs',
            'catalogueProduct.brand', 'catalogueProduct.category',
            'catalogueProduct.subCategory', 'catalogueProduct.tradingGroup',
        ])->where('inventory_id', $inventoryId)->firstOrFail();
        $from = Carbon::parse($request->input('date_from', now()->subMonths(42)->startOfMonth()));
        $to = Carbon::parse($request->input('date_to', now()));
        $monthly = $this->monthlySummaries(collect([$inventoryId]), $from, $to)->get($inventoryId, collect());

        return $this->inventoryRow($item, $monthly, collect()) + [
            'stock_history' => $item->warehouseBalanceSnapshots
                ->groupBy(fn ($row) => $row->recorded_at?->format('Y-m-d'))
                ->map(fn ($rows, $date) => [
                    'date' => $date,
                    'qty_on_hand' => $rows->whereNotNull('qty_on_hand')->sum('qty_on_hand'),
                    'qty_available' => $rows->whereNotNull('qty_available')->isEmpty()
                        ? null : (float) $rows->whereNotNull('qty_available')->sum('qty_available'),
                ])->values(),
        ];
    }

    public function sales(Request $request): array
    {
        $from = Carbon::parse($request->input('date_from', now()->subMonths(12)->startOfMonth()));
        $to = Carbon::parse($request->input('date_to', now()));
        $version = $this->versions()['sales'] ?? 'empty';
        $cacheKey = "production:sales:{$version}:{$from->format('Ymd')}:{$to->format('Ymd')}";
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }
        $inventory = AcumaticaInventoryItem::with(['productionPlan', 'catalogueProduct.brand',
            'catalogueProduct.category', 'catalogueProduct.subCategory', 'catalogueProduct.tradingGroup'])
            ->get()->keyBy('inventory_id');
        $monthly = $this->monthlySummaries($inventory->keys(), $from, $to);
        $rows = collect();

        foreach ($monthly as $inventoryId => $points) {
            $item = $inventory->get($inventoryId);
            if (! $item) continue;
            foreach ($points as $point) {
                $rows->push([
                    'inventory_id' => $inventoryId,
                    'product_name' => $item->catalogueProduct?->name ?? $item->description,
                    'brand' => $item->catalogueProduct?->brand?->name,
                    'category' => $item->catalogueProduct?->category?->name,
                    'sub_category' => $item->catalogueProduct?->subCategory?->name,
                    'trading_group' => $item->catalogueProduct?->tradingGroup?->name,
                    'portfolio_group' => $item->catalogueProduct?->portfolio_group,
                    'ownership' => $this->ownership($item),
                    'business_line' => $item->productionPlan?->business_line,
                    'warehouse_id' => $point->warehouse_id,
                    'month' => $point->month,
                    'ordered_qty' => (float) $point->ordered_qty,
                    'shipped_qty' => (float) $point->delivered_qty,
                    'missed_qty' => (float) $point->missed_qty,
                    'missed_revenue' => $point->missed_revenue !== null ? (float) $point->missed_revenue : null,
                    'revenue_complete' => (bool) $point->revenue_complete,
                    'currency_id' => $point->currency_id,
                ]);
            }
        }

        $response = ['data' => $rows->values(), 'meta' => [
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'version' => $version,
        ]];
        Cache::put($cacheKey, $response, now()->addMinutes(5));
        return $response;
    }

    private function inventoryRow(AcumaticaInventoryItem $item, Collection $monthly, Collection $warehouseFilter): array
    {
        $balances = $item->warehouseBalances
            ->when($warehouseFilter->isNotEmpty(), fn ($rows) =>
                $rows->filter(fn ($row) => $warehouseFilter->contains(strtoupper((string) $row->warehouse_id)))
            )->values();
        $availableRows = $balances->whereNotNull('qty_available');
        $totalOnHand = (float) $balances->whereNotNull('qty_on_hand')->sum('qty_on_hand');
        $hasAvailable = $availableRows->isNotEmpty();
        $totalAvailable = $hasAvailable ? (float) $availableRows->sum('qty_available') : null;
        $resolvedStock = $hasAvailable ? $totalAvailable : $totalOnHand;
        $plan = $item->productionPlan;
        $product = $item->catalogueProduct;
        $msi = $plan?->msi !== null ? (float) $plan->msi : null;
        $status = null;
        if ($msi !== null && $msi > 0) {
            $ratio = $resolvedStock / $msi;
            $status = $ratio < .5 ? 'critical' : ($ratio < 1 ? 'at-risk' : 'healthy');
        }

        return [
            'inventory_id' => $item->inventory_id,
            'product_name' => $product?->name ?? $item->description,
            'brand' => $product?->brand?->name ?? $item->brand,
            'category' => $product?->category?->name ?? $item->item_group,
            'sub_category' => $product?->subCategory?->name ?? $item->sub_item_group,
            'trading_group' => $product?->tradingGroup?->name ?? $item->trading_group,
            'portfolio_group' => $product?->portfolio_group,
            'conversion_factor' => $product?->conversion_factor !== null ? (float) $product->conversion_factor : null,
            'uom' => $product?->uom,
            'ownership' => $this->ownership($item),
            'business_line' => $plan?->business_line,
            'site' => $plan?->site,
            'machine' => $plan?->machine,
            'machines' => $plan?->machines?->pluck('name')->values() ?? [],
            'msi' => $msi,
            'safety_stock' => $plan?->safety_stock !== null ? (float) $plan->safety_stock : null,
            'buffer_stock' => $plan?->buffer_stock !== null ? (float) $plan->buffer_stock : null,
            'export_msi' => $plan?->export_msi !== null ? (float) $plan->export_msi : null,
            'export_requirement' => $plan?->export_requirement !== null ? (float) $plan->export_requirement : null,
            'total_on_hand' => $totalOnHand,
            'total_available' => $totalAvailable,
            'resolved_stock' => $resolvedStock,
            'stock_basis' => $hasAvailable ? 'available' : 'on_hand_fallback',
            'stock_complete' => $balances->whereNull('qty_available')->isEmpty(),
            'missing_available_warehouses' => $balances->whereNull('qty_available')->pluck('warehouse_id')->values(),
            'status' => $status,
            'requirement' => $msi === null ? null : max(0, $msi - $resolvedStock),
            'last_synced_at' => optional($balances->sortByDesc('synced_at')->first()?->synced_at)->toISOString(),
            'warehouse_stocks' => $balances->map(fn ($row) => [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => config("inventory.warehouse_labels.{$row->warehouse_id}", $row->warehouse_id),
                'qty_on_hand' => $row->qty_on_hand !== null ? (float) $row->qty_on_hand : null,
                'qty_available' => $row->qty_available !== null ? (float) $row->qty_available : null,
                'qty_allocated' => ($row->qty_on_hand !== null && $row->qty_available !== null)
                    ? max(0, (float) $row->qty_on_hand - (float) $row->qty_available) : null,
                'synced_at' => $row->synced_at?->toISOString(),
            ])->values(),
            'monthly_sales' => $monthly->map(fn ($point) => [
                'month' => $point->month,
                'warehouse_id' => $point->warehouse_id,
                'ordered_qty' => (float) $point->ordered_qty,
                'shipped_qty' => (float) $point->delivered_qty,
                'missed_qty' => (float) $point->missed_qty,
                'missed_revenue' => $point->missed_revenue !== null ? (float) $point->missed_revenue : null,
                'revenue_complete' => (bool) $point->revenue_complete,
                'currency_id' => $point->currency_id,
            ])->values(),
            'plan_id' => $plan?->id,
        ];
    }

    /**
     * Resolve manufactured | partner for Production / Partner Intel tabs.
     * Partner includes trading brands from the product catalogue seeder.
     */
    private function ownership(AcumaticaInventoryItem $item): ?string
    {
        $product = $item->catalogueProduct;
        $fromProduct = $this->normalizeOwnership($product?->ownership);
        if ($fromProduct !== null) {
            return $fromProduct;
        }

        $fromBrand = $this->normalizeOwnership($product?->brand?->ownership);
        if ($fromBrand !== null) {
            return $fromBrand;
        }

        $fromPlan = $this->normalizeOwnership($item->productionPlan?->ownership);
        if ($fromPlan !== null) {
            return $fromPlan;
        }

        $fromType = $this->normalizeOwnership($item->product_type);
        if ($fromType !== null) {
            return $fromType;
        }

        // Fall back to brand lists + inventory ID prefixes (Kimfay vs trading partners).
        return $this->classifier->ownershipFromBrand(
            $product?->brand?->name ?? $item->brand,
            $item->inventory_id,
            $product?->name ?? $item->description,
        );
    }

    private function normalizeOwnership(mixed $value): ?string
    {
        return match (strtolower(trim((string) ($value ?? '')))) {
            'manufactured' => 'manufactured',
            'partner', 'trading' => 'partner',
            default => null,
        };
    }

    private function monthlySummaries(Collection $inventoryIds, Carbon $from, Carbon $to): Collection
    {
        if ($inventoryIds->isEmpty()) return collect();

        $monthSql = DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', month)"
            : "DATE_FORMAT(month, '%Y-%m')";

        return MonthlySkuSummary::query()
            ->whereBetween('month', [$from->copy()->startOfMonth(), $to->copy()->startOfMonth()])
            ->whereIn('inventory_id', $inventoryIds)
            ->selectRaw("inventory_id, warehouse_id, {$monthSql} as month")
            ->selectRaw('SUM(ordered_qty) as ordered_qty, SUM(delivered_qty) as delivered_qty, SUM(missed_qty) as missed_qty')
            ->selectRaw('CASE WHEN SUM(missed_qty) > 0 AND SUM(priced_missed_qty) = 0 THEN NULL ELSE SUM(missed_revenue) END as missed_revenue')
            ->selectRaw('MIN(revenue_complete) as revenue_complete, MIN(currency_id) as currency_id')
            ->groupBy('inventory_id', 'warehouse_id', 'month')
            ->get()
            ->groupBy('inventory_id');
    }

    public function versions(): array
    {
        return app(ProductionSummaryService::class)->versions();
    }

    public function summary(Request $request): array
    {
        $filters = collect($request->only([
            'ownership', 'warehouse_ids', 'brand', 'category', 'trading_group',
            'site', 'machine', 'business_line', 'status', 'search',
        ]))->sortKeys()->all();
        $generation = (int) Cache::get('production:cache-generation', 1);
        $key = 'production:summary:'.$generation.':'.hash('sha256', json_encode($filters));

        return Cache::remember($key, now()->addSeconds(60), function () use ($request) {
            $latestDate = DailyStockSummary::max('summary_date');
            if ($latestDate === null) {
                return [
                    'total_skus' => 0, 'critical_skus' => 0, 'at_risk_skus' => 0,
                    'healthy_skus' => 0, 'qty_available' => 0, 'avg_months_of_cover' => null,
                    'skus_below_msi' => 0, 'requirement' => 0, 'freshness' => $this->versions(),
                ];
            }
            $query = DB::table('daily_stock_summaries as stock')
                ->join('acumatica_inventory_items as item', 'item.inventory_id', '=', 'stock.inventory_id')
                ->leftJoin('products as product', 'product.inventory_id', '=', 'stock.inventory_id')
                ->leftJoin('production_sku_plans as plan', 'plan.inventory_item_id', '=', 'item.id')
                ->leftJoin('brands as brand', 'brand.id', '=', 'product.brand_id')
                ->leftJoin('categories as category', 'category.id', '=', 'product.category_id')
                ->leftJoin('trading_groups as trading_group', 'trading_group.id', '=', 'product.trading_group_id')
                ->whereDate('stock.summary_date', $latestDate)
                ->where('item.is_stock_item', true);
            if ($ownership = $request->input('ownership')) {
                $query->whereRaw('LOWER(COALESCE(product.ownership, plan.ownership, item.product_type, ?)) = ?', ['', $ownership]);
            }
            if ($warehouses = array_values(array_filter((array) $request->input('warehouse_ids', [])))) {
                $query->whereIn('stock.warehouse_id', $warehouses);
            }
            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(fn ($items) => $items->where('item.inventory_id', 'like', "%{$search}%")
                    ->orWhere('item.description', 'like', "%{$search}%")
                    ->orWhere('product.name', 'like', "%{$search}%"));
            }
            foreach (['site', 'business_line'] as $field) {
                if ($values = array_values(array_filter((array) $request->input($field, [])))) {
                    $query->whereIn("plan.{$field}", $values);
                }
            }
            foreach (['brand' => 'brand.name', 'category' => 'category.name', 'trading_group' => 'trading_group.name'] as $parameter => $column) {
                if ($values = array_values(array_filter((array) $request->input($parameter, [])))) {
                    $query->whereIn($column, $values);
                }
            }
            if ($machines = array_values(array_filter((array) $request->input('machine', [])))) {
                $query->where(function ($machineQuery) use ($machines) {
                    $machineQuery->whereIn('plan.machine', $machines)
                        ->orWhereExists(function ($assigned) use ($machines) {
                            $assigned->selectRaw('1')
                                ->from('production_machine_plan as pmp')
                                ->join('production_machines as pm', 'pm.id', '=', 'pmp.production_machine_id')
                                ->whereColumn('pmp.production_sku_plan_id', 'plan.id')
                                ->whereIn('pm.name', $machines);
                        });
                });
            }

            $rows = $query
                ->selectRaw('stock.inventory_id, MAX(stock.msi) as msi')
                ->selectRaw('SUM(COALESCE(stock.qty_on_hand, 0)) as total_on_hand')
                ->selectRaw('SUM(CASE WHEN stock.qty_available IS NOT NULL THEN stock.qty_available ELSE 0 END) as total_available')
                ->selectRaw('SUM(CASE WHEN stock.qty_available IS NOT NULL THEN 1 ELSE 0 END) as available_rows')
                ->groupBy('stock.inventory_id')
                ->get()
                ->map(function ($row) {
                    $resolved = (int) $row->available_rows > 0 ? (float) $row->total_available : (float) $row->total_on_hand;
                    $msi = $row->msi !== null ? (float) $row->msi : null;
                    $status = null;
                    if ($msi !== null && $msi > 0) {
                        $ratio = $resolved / $msi;
                        $status = $ratio < .5 ? 'critical' : ($ratio < 1 ? 'at-risk' : 'healthy');
                    }
                    return [
                        'inventory_id' => $row->inventory_id,
                        'resolved_stock' => $resolved,
                        'msi' => $msi,
                        'status' => $status,
                        'requirement' => $msi === null ? null : max(0, $msi - $resolved),
                    ];
                });
            if ($statuses = array_values(array_filter((array) $request->input('status', [])))) {
                $rows = $rows->whereIn('status', $statuses);
            }
            $configured = $rows->whereNotNull('msi');

            return [
                'total_skus' => $rows->count(),
                'critical_skus' => $rows->where('status', 'critical')->count(),
                'at_risk_skus' => $rows->where('status', 'at-risk')->count(),
                'healthy_skus' => $rows->where('status', 'healthy')->count(),
                'qty_available' => (float) $rows->sum('resolved_stock'),
                'avg_months_of_cover' => null,
                'skus_below_msi' => $configured->filter(fn ($row) => $row['resolved_stock'] < $row['msi'])->count(),
                'requirement' => (float) $configured->sum('requirement'),
                'freshness' => $this->versions(),
            ];
        });
    }

    public function reference(): array
    {
        $version = $this->versions()['reference'] ?? 'empty';
        return Cache::rememberForever("production:reference:{$version}", function () {
            return [
                'warehouses' => DB::table('inventory_warehouse_balances')
                    ->select('warehouse_id')->distinct()->orderBy('warehouse_id')->get()
                    ->map(fn ($row) => ['id' => $row->warehouse_id, 'name' => config("inventory.warehouse_labels.{$row->warehouse_id}", $row->warehouse_id)]),
                'brands' => DB::table('brands')->orderBy('name')->pluck('name'),
                'categories' => DB::table('categories')->orderBy('name')->pluck('name'),
                'trading_groups' => DB::table('trading_groups')->orderBy('name')->pluck('name'),
                'sites' => DB::table('production_sku_plans')->whereNotNull('site')->distinct()->orderBy('site')->pluck('site'),
                'machines' => DB::table('production_machines')->orderBy('name')->pluck('name'),
                'business_lines' => DB::table('production_sku_plans')->whereNotNull('business_line')->distinct()->orderBy('business_line')->pluck('business_line'),
                'version' => $version,
            ];
        });
    }

    public function trend(string $inventoryId, Request $request): array
    {
        $from = Carbon::parse($request->input('date_from', now()->startOfYear()))->startOfMonth();
        $to = Carbon::parse($request->input('date_to', now()))->endOfMonth();
        $version = $this->versions()['sales'] ?? 'empty';
        $key = "production:trend:{$version}:{$inventoryId}:{$from->format('Ymd')}:{$to->format('Ymd')}";
        return Cache::remember($key, now()->addMinutes(5), function () use ($inventoryId, $from, $to, $version) {
            $rows = $this->monthlySummaries(collect([$inventoryId]), $from, $to)
                ->get($inventoryId, collect())
                ->groupBy('month')
                ->map(fn ($points, $month) => [
                    'month' => $month,
                    'ordered_qty' => (float) $points->sum('ordered_qty'),
                    'shipped_qty' => (float) $points->sum('delivered_qty'),
                    'missed_qty' => (float) $points->sum('missed_qty'),
                    'missed_revenue' => $points->every(fn ($point) => $point->missed_revenue === null)
                        ? null : (float) $points->sum('missed_revenue'),
                    'revenue_complete' => $points->every(fn ($point) => (bool) $point->revenue_complete),
                    'currency_id' => $points->pluck('currency_id')->filter()->unique()->first() ?? 'KES',
                ])->values();
            return ['data' => $rows, 'meta' => compact('version') + ['from' => $from->toDateString(), 'to' => $to->toDateString()]];
        });
    }

    public function warehouses(string $inventoryId): array
    {
        $item = AcumaticaInventoryItem::with('warehouseBalances')
            ->where('inventory_id', $inventoryId)->firstOrFail();
        return [
            'data' => $item->warehouseBalances->map(fn ($row) => [
                'warehouse_id' => $row->warehouse_id,
                'warehouse_name' => config("inventory.warehouse_labels.{$row->warehouse_id}", $row->warehouse_id),
                'qty_on_hand' => $row->qty_on_hand !== null ? (float) $row->qty_on_hand : null,
                'qty_available' => $row->qty_available !== null ? (float) $row->qty_available : null,
                'synced_at' => $row->synced_at?->toISOString(),
            ])->values(),
            'meta' => ['stock_version' => $this->versions()['stock'] ?? null],
        ];
    }

    private function filterOptions(Collection $items): array
    {
        return [
            'warehouses' => $items->flatMap->warehouseBalances->map(fn ($row) => [
                'id' => $row->warehouse_id,
                'name' => config("inventory.warehouse_labels.{$row->warehouse_id}", $row->warehouse_id),
            ])->unique('id')->values(),
            'brands' => $items->map(fn ($i) => $i->catalogueProduct?->brand?->name ?? $i->brand)->filter()->unique()->sort()->values(),
            'categories' => $items->map(fn ($i) => $i->catalogueProduct?->category?->name ?? $i->item_group)->filter()->unique()->sort()->values(),
            'trading_groups' => $items->map(fn ($i) => $i->catalogueProduct?->tradingGroup?->name ?? $i->trading_group)->filter()->unique()->sort()->values(),
            'portfolio_groups' => $items->pluck('catalogueProduct.portfolio_group')->filter()->unique()->sort()->values(),
            'sites' => $items->pluck('productionPlan.site')->filter()->unique()->sort()->values(),
            'machines' => $items->flatMap(fn ($item) => $item->productionPlan?->machines?->pluck('name') ?? collect())
                ->merge($items->pluck('productionPlan.machine')->filter())->unique()->sort()->values(),
            'business_lines' => $items->pluck('productionPlan.business_line')->filter()->unique()->sort()->values(),
            'ownerships' => $items->map(fn ($i) => $this->ownership($i))->filter()->unique()->sort()->values(),
        ];
    }
}
