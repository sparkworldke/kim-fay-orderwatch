<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaShippingZone;
use App\Services\Operations\OperationsCatalogResolver;
use App\Support\DataScope;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = DataScope::applyCustomerScope(
            AcumaticaCustomer::query()->orderBy('name'),
            $request->user(),
        );

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('acumatica_id', 'like', "%{$q}%")
                   ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($request->has('class')) {
            $query->where('customer_class', $request->input('class'));
        }

        if ($request->filled('class_prefix')) {
            $prefix = trim((string) $request->input('class_prefix'));
            $query->where('customer_class', 'like', "{$prefix}%");
        }

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('shipping_zone_id')) {
            $query->where('shipping_zone_id', strtoupper(trim((string) $request->input('shipping_zone_id'))));
        }

        if ($request->filled('customer_group')) {
            $query->whereHas('customerData', fn ($qb) => $qb->where('customer_group', $request->input('customer_group')));
        }

        return response()->json($query->paginate(min((int) $request->input('per_page', 50), 200)));
    }

    /**
     * Shipping zone master list synced from Acumatica Zone entity.
     */
    public function shippingZones(): JsonResponse
    {
        $zones = AcumaticaShippingZone::query()
            ->withCount('customers')
            ->orderBy('region')
            ->orderBy('name')
            ->orderBy('acumatica_id')
            ->get(['acumatica_id', 'description', 'name', 'region', 'synced_at'])
            ->map(fn (AcumaticaShippingZone $zone) => [
                'acumatica_id' => $zone->acumatica_id,
                'description' => $zone->description,
                'name' => $zone->name,
                'region' => $zone->region,
                'synced_at' => $zone->synced_at,
                'customer_count' => $zone->customers_count,
            ])
            ->values();

        return response()->json($zones);
    }

    /**
     * Category summary — each customer_class with Active / Inactive / On Hold breakdown.
     */
    public function categories(Request $request): JsonResponse
    {
        $rows = DataScope::applyCustomerScope(
            AcumaticaCustomer::query(),
            $request->user(),
        )
            ->select([
                DB::raw('COALESCE(customer_class, "Uncategorised") as class'),
                DB::raw('LOWER(COALESCE(status, "unknown")) as status_lower'),
                DB::raw('COUNT(*) as cnt'),
            ])
            ->groupByRaw('COALESCE(customer_class, "Uncategorised"), LOWER(COALESCE(status, "unknown"))')
            ->orderBy('class')
            ->get();

        $categories = [];
        foreach ($rows as $row) {
            $cls = $row->class;
            if (! isset($categories[$cls])) {
                $categories[$cls] = [
                    'class'    => $cls,
                    'total'    => 0,
                    'active'   => 0,
                    'inactive' => 0,
                    'on_hold'  => 0,
                    'other'    => 0,
                ];
            }
            $cnt = (int) $row->cnt;
            $categories[$cls]['total'] += $cnt;
            $key = match ($row->status_lower) {
                'active'              => 'active',
                'inactive'            => 'inactive',
                'on hold', 'onhold'   => 'on_hold',
                default               => 'other',
            };
            $categories[$cls][$key] += $cnt;
        }

        return response()->json(array_values($categories));
    }

    /**
     * All customers in a category, structured as main accounts with nested branches.
     */
    public function byCategory(Request $request, string $class): JsonResponse
    {
        $customers = DataScope::applyCustomerScope(
            AcumaticaCustomer::query(),
            $request->user(),
        )
            ->where(function ($q) use ($class) {
                if ($class === 'Uncategorised') {
                    $q->whereNull('customer_class')->orWhere('customer_class', '');
                } else {
                    $q->where('customer_class', $class);
                }
            })
            ->orderByDesc('is_main_account')
            ->orderBy('name')
            ->get();

        // Separate main accounts and branches
        $mains    = $customers->filter(fn ($c) => $c->is_main_account || is_null($c->parent_acumatica_id));
        $branches = $customers->filter(fn ($c) => ! $c->is_main_account && ! is_null($c->parent_acumatica_id))
            ->groupBy('parent_acumatica_id');

        $result = $mains->map(function ($main) use ($branches) {
            $data              = $main->toArray();
            $data['branches']  = ($branches[$main->acumatica_id] ?? collect())->values()->toArray();
            $data['branch_count'] = count($data['branches']);
            return $data;
        })->values();

        return response()->json([
            'class'     => $class,
            'total'     => $customers->count(),
            'customers' => $result,
        ]);
    }

    /**
     * Set the parent/main account relationship for a customer.
     */
    public function setParent(Request $request, string $id): JsonResponse
    {
        $customer = AcumaticaCustomer::where('acumatica_id', $id)->firstOrFail();

        $validated = $request->validate([
            'parent_acumatica_id' => ['nullable', 'string', 'max:50'],
            'is_main_account'     => ['boolean'],
        ]);

        $customer->update($validated);

        return response()->json($customer);
    }

    /**
     * Reorder predictions — items this customer has ordered on a recurring
     * cadence that are now overdue, based on the average gap between their
     * past orders for each item. Needs at least 2 distinct past orders per
     * item to establish a pattern.
     */
    public function suggestedOrders(Request $request, string $id): JsonResponse
    {
        $customer = AcumaticaCustomer::where('acumatica_id', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customer->acumatica_id, $customer->customer_class)) {
            return $denied;
        }

        $lines = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.customer_acumatica_id', $customer->acumatica_id)
            ->where('o.order_type', 'SO')
            ->whereNotNull('o.order_date')
            ->whereNotNull('l.inventory_id')
            ->select(['l.inventory_id', 'l.description', 'l.uom', 'o.order_date', 'l.order_qty'])
            ->orderBy('o.order_date')
            ->get();

        $today = now()->startOfDay();
        $suggestions = [];

        foreach ($lines->groupBy('inventory_id') as $inventoryId => $itemLines) {
            $dates = $itemLines->map(fn ($row) => Carbon::parse($row->order_date)->startOfDay())
                ->unique(fn ($date) => $date->toDateString())
                ->sort()
                ->values();

            if ($dates->count() < 2) {
                continue; // not enough history to establish a cadence
            }

            $first = $dates->first();
            $last = $dates->last();
            $avgIntervalDays = $first->diffInDays($last) / ($dates->count() - 1);

            if ($avgIntervalDays < 1) {
                continue; // same-day duplicates — no real cadence
            }

            $nextExpected = $last->copy()->addDays((int) round($avgIntervalDays));
            if ($nextExpected->gt($today)) {
                continue; // not due yet
            }

            $suggestions[] = [
                'inventory_id' => $inventoryId,
                'description' => $itemLines->last()->description,
                'uom' => $itemLines->last()->uom,
                'order_count' => $dates->count(),
                'avg_interval_days' => (int) round($avgIntervalDays),
                'last_order_date' => $last->toDateString(),
                'last_order_qty' => round((float) $itemLines->last()->order_qty, 2),
                'next_expected_date' => $nextExpected->toDateString(),
                'days_overdue' => $nextExpected->diffInDays($today),
                'avg_order_qty' => round((float) $itemLines->avg('order_qty'), 2),
            ];
        }

        usort($suggestions, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);
        $suggestions = $this->attachInventoryClassifications($suggestions);

        return response()->json([
            'customer_id' => $customer->acumatica_id,
            'customer_name' => $customer->name,
            'suggestions' => array_values($suggestions),
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $customer = AcumaticaCustomer::where('acumatica_id', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customer->acumatica_id, $customer->customer_class)) {
            return $denied;
        }

        return response()->json($this->formatCustomer($customer));
    }

    private function formatCustomer(AcumaticaCustomer $customer): array
    {
        $customer->loadMissing([
            'shippingZone',
            'route',
            'customerData',
            'parent:id,acumatica_id,name',
            'assignments' => fn ($query) => $query
                ->whereIn('assignment_type', ['servicing', 'primary'])
                ->with('user:id,name,email,rep_code'),
        ]);
        $data = $customer->toArray();
        $branches = $customer->branches()->orderBy('name')->get();
        $data['branches'] = $branches->toArray();
        $data['branch_count'] = $branches->count();
        $data['shipping_zone'] = $customer->shippingZone ? [
            'acumatica_id' => $customer->shippingZone->acumatica_id,
            'description' => $customer->shippingZone->description,
            'name' => $customer->shippingZone->name,
            'region' => $customer->shippingZone->region,
        ] : null;
        $data['route'] = $customer->route?->only(['route_code', 'route_name']);
        $data['customer_data'] = $customer->customerData?->toArray();
        $data['parent'] = $customer->parent?->only(['acumatica_id', 'name']);
        $data['servicing_consultant'] = $customer->assignments
            ->sortByDesc('priority')
            ->first()?->user?->only(['id', 'name', 'email', 'rep_code']);
        $activeContacts = $customer->contacts()->active()->get(['phone', 'email', 'is_primary']);
        $data['contact_health'] = [
            'has_phone' => filled($customer->phone) || $activeContacts->contains(fn ($contact) => filled($contact->phone)),
            'has_email' => filled($customer->email) || filled($customer->customerData?->email) || $activeContacts->contains(fn ($contact) => filled($contact->email)),
            'has_primary' => $activeContacts->contains('is_primary', true),
        ];

        return $data;
    }

    /**
     * Most frequently purchased items across a customer's SO history — used to
     * surface "common products" alongside their order/document list.
     */
    public function commonProducts(Request $request, string $id): JsonResponse
    {
        $customer = AcumaticaCustomer::where('acumatica_id', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customer->acumatica_id, $customer->customer_class)) {
            return $denied;
        }

        $lines = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.customer_acumatica_id', $customer->acumatica_id)
            ->where('o.order_type', 'SO')
            ->whereNotNull('l.inventory_id')
            ->select(['l.inventory_id', 'l.description', 'l.uom', 'o.order_date', 'l.order_qty'])
            ->orderBy('o.order_date')
            ->get();

        $products = [];
        foreach ($lines->groupBy('inventory_id') as $inventoryId => $itemLines) {
            $last = $itemLines->last();
            $products[] = [
                'inventory_id' => $inventoryId,
                'description' => $last->description,
                'uom' => $last->uom,
                'order_count' => $itemLines->count(),
                'total_qty' => round((float) $itemLines->sum('order_qty'), 2),
                'last_order_date' => Carbon::parse($last->order_date)->toDateString(),
                'last_order_qty' => round((float) $last->order_qty, 2),
            ];
        }

        usort($products, fn ($a, $b) => ($b['order_count'] <=> $a['order_count']) ?: ($b['total_qty'] <=> $a['total_qty']));
        $products = $this->attachInventoryClassifications($products);

        return response()->json([
            'customer_id' => $customer->acumatica_id,
            'customer_name' => $customer->name,
            'products' => array_slice(array_values($products), 0, 10),
        ]);
    }

    /**
     * White-spot / cohort product-gap engine (PRD S6, S7, C13).
     *
     * Compares what this account buys against what peer accounts in the same
     * segment (customer class by default) buy over a trailing window, then
     * surfaces SKUs that a meaningful share of peers purchase but this account
     * does not — either never bought, or lapsed (not bought for several months
     * and likely needing revival). Gives consultants a closing angle:
     * "X% of similar customers buy this".
     *
     * Query params:
     *  - cohort: customer_class (default) | customer_group | shipping_zone
     *  - months: trailing analysis window in months (1–24, default 6)
     *  - lapsed_months: a line is "lapsed" once this old (1–24, default 3)
     *  - min_penetration: cohort share (%) required to flag a SKU (1–100, default 25)
     */
    public function whitespace(Request $request, string $id): JsonResponse
    {
        $customer = AcumaticaCustomer::where('acumatica_id', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        if ($denied = DataScope::denyUnlessCustomerAccessible($request->user(), $customer->acumatica_id, $customer->customer_class)) {
            return $denied;
        }

        $tz = 'Africa/Nairobi';
        $today = Carbon::now($tz)->startOfDay();

        $months = min(max($request->integer('months', 6), 1), 24);
        $lapsedMonths = min(max($request->integer('lapsed_months', 3), 1), 24);
        $minPenetration = min(max((float) $request->input('min_penetration', 25), 1), 100);
        $cohortDim = strtolower(trim((string) $request->input('cohort', 'customer_class')));
        if (! in_array($cohortDim, ['customer_class', 'customer_group', 'shipping_zone'], true)) {
            $cohortDim = 'customer_class';
        }

        $customer->loadMissing('customerData');
        $cohortValue = match ($cohortDim) {
            'customer_group' => $customer->customerData?->customer_group,
            'shipping_zone' => $customer->shipping_zone_id,
            default => $customer->customer_class,
        };
        $cohortLabel = filled($cohortValue) ? (string) $cohortValue : null;

        $summary = $this->whitespaceSummary([
            'cohort_dimension' => $cohortDim,
            'cohort_value' => $cohortLabel,
            'analysis_window_months' => $months,
            'lapsed_months' => $lapsedMonths,
            'min_penetration_pct' => $minPenetration,
        ]);

        $baseResponse = fn (string $reason) => response()->json([
            'customer_id' => $customer->acumatica_id,
            'customer_name' => $customer->name,
            'cohort_dimension' => $cohortDim,
            'cohort_value' => $cohortLabel,
            'available' => false,
            'reason' => $reason,
            'opportunities' => [],
            'summary' => $summary,
        ]);

        if ($cohortLabel === null) {
            return $baseResponse('This account has no '.str_replace('_', ' ', $cohortDim).' to build a peer cohort from.');
        }

        // Peer cohort: same segment value, within the actor's visibility, excluding self.
        $peers = DataScope::applyCustomerScope(AcumaticaCustomer::query(), $request->user())
            ->where(function ($q) use ($cohortDim, $cohortLabel) {
                if ($cohortDim === 'customer_group') {
                    $q->whereHas('customerData', fn ($cd) => $cd->where('customer_group', $cohortLabel));
                } elseif ($cohortDim === 'shipping_zone') {
                    $q->where('shipping_zone_id', $cohortLabel);
                } else {
                    $q->where('customer_class', $cohortLabel);
                }
            })
            ->where('acumatica_id', '<>', $customer->acumatica_id)
            ->pluck('acumatica_id')
            ->all();

        $summary['peer_accounts'] = count($peers);

        if (count($peers) === 0) {
            return $baseResponse('No other accounts in this segment are visible to you, so no peer comparison is possible.');
        }

        $windowStart = $today->copy()->subMonths($months);
        $from = $windowStart->toDateString();
        $to = $today->toDateString();
        $notCancelled = ['cancelled', 'canceled', 'rejected'];
        $notFulfilment = fn ($q) => $q->whereNull('o.import_source')->orWhere('o.import_source', 'not like', '%fol%');

        // Cohort purchasing in the trailing window, aggregated per SKU.
        $cohortRows = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->whereIn('o.customer_acumatica_id', $peers)
            ->where('o.order_type', 'SO')
            ->whereNotIn(DB::raw('LOWER(COALESCE(o.status, \'\'))'), $notCancelled)
            ->where($notFulfilment)
            ->whereBetween('o.order_date', [$from, $to])
            ->whereNotNull('l.inventory_id')
            ->groupBy('l.inventory_id')
            ->selectRaw(
                'l.inventory_id, '
                .'MAX(l.description) as description, MAX(l.uom) as uom, '
                .'COUNT(DISTINCT o.customer_acumatica_id) as buyer_count, '
                .'SUM(l.order_qty) as total_qty, '
                .'AVG(l.unit_price) as avg_unit_price'
            )
            ->get();

        // Active peers = accounts in the cohort that ordered anything in the window.
        $activePeers = (int) DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereNotIn(DB::raw('LOWER(COALESCE(status, \'\'))'), $notCancelled)
            ->whereIn('customer_acumatica_id', $peers)
            ->whereBetween('order_date', [$from, $to])
            ->selectRaw('COUNT(DISTINCT customer_acumatica_id) as c')
            ->value('c');

        $summary['active_peers'] = $activePeers;
        $summary['catalog_lines'] = $cohortRows->count();

        if ($activePeers < 3 || $cohortRows->isEmpty()) {
            return $baseResponse($activePeers < 3
                ? 'Only '.$activePeers.' active peer account(s) ordered in the last '.$months.' month(s) — too few for a reliable comparison.'
                : 'No peer purchasing recorded in the last '.$months.' month(s).');
        }

        // Focal customer's all-time purchasing — drives never-bought / lapsed detection.
        $focalAllTime = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.customer_acumatica_id', $customer->acumatica_id)
            ->where('o.order_type', 'SO')
            ->whereNotIn(DB::raw('LOWER(COALESCE(o.status, \'\'))'), $notCancelled)
            ->where($notFulfilment)
            ->whereNotNull('l.inventory_id')
            ->groupBy('l.inventory_id')
            ->selectRaw('l.inventory_id, MAX(o.order_date) as last_order_date')
            ->get()
            ->keyBy('inventory_id');

        // Focal monthly distinct lines in the window — lines purchased month-on-month.
        $focalWindowRows = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.customer_acumatica_id', $customer->acumatica_id)
            ->where('o.order_type', 'SO')
            ->whereNotIn(DB::raw('LOWER(COALESCE(o.status, \'\'))'), $notCancelled)
            ->where($notFulfilment)
            ->whereBetween('o.order_date', [$from, $to])
            ->whereNotNull('l.inventory_id')
            ->select(['l.inventory_id', 'o.order_date'])
            ->get();

        $focalLinesInWindow = $focalWindowRows->pluck('inventory_id')->unique()->count();
        $byMonth = [];
        foreach ($focalWindowRows as $r) {
            $ym = Carbon::parse($r->order_date, $tz)->format('Y-m');
            $byMonth[$ym][$r->inventory_id] = true;
        }

        // sum(buyer_count) across SKUs = total distinct (peer, SKU) pairs in the window.
        $cohortAvgLines = round((float) $cohortRows->sum('buyer_count') / $activePeers, 1);
        $summary['cohort_avg_lines'] = $cohortAvgLines;
        $summary['focal_lines_in_window'] = $focalLinesInWindow;
        $summary['focal_line_penetration_pct'] = $cohortRows->count() > 0
            ? round($focalLinesInWindow / $cohortRows->count() * 100, 1)
            : 0.0;

        // Month-on-month line trend for the focal account.
        $monthly = [];
        $cursor = $windowStart->copy()->startOfMonth();
        $thisMonth = $today->copy()->startOfMonth();
        while ($cursor <= $thisMonth) {
            $key = $cursor->format('Y-m');
            $monthly[] = [
                'month' => $key,
                'focal_lines' => isset($byMonth[$key]) ? count($byMonth[$key]) : 0,
                'cohort_avg_lines' => $cohortAvgLines,
            ];
            $cursor = $cursor->copy()->addMonth();
        }
        $summary['monthly_lines'] = $monthly;

        $lapsedCutoff = $today->copy()->subMonths($lapsedMonths);
        $opportunities = [];
        $opportunityValueTotal = 0.0;

        foreach ($cohortRows as $row) {
            $penetration = round((float) $row->buyer_count / $activePeers * 100, 1);
            if ($penetration < $minPenetration) {
                continue;
            }

            $focal = $focalAllTime->get($row->inventory_id);
            if ($focal) {
                $lastDate = Carbon::parse($focal->last_order_date, $tz)->startOfDay();
                if ($lastDate >= $lapsedCutoff) {
                    continue; // actively bought — not a white spot
                }
                $status = 'lapsed';
                $lastOrderDate = $lastDate->toDateString();
                $monthsSince = (int) round($lastDate->diffInMonths($today));
            } else {
                $status = 'never_bought';
                $lastOrderDate = null;
                $monthsSince = null;
            }

            $avgQty = $row->buyer_count > 0 ? (float) $row->total_qty / (int) $row->buyer_count : 0.0;
            $avgPrice = (float) $row->avg_unit_price;
            $opportunityValue = round($avgQty * $avgPrice, 2);
            $opportunityValueTotal += $opportunityValue;

            $opportunities[] = [
                'inventory_id' => $row->inventory_id,
                'description' => $row->description,
                'uom' => $row->uom,
                'status' => $status,
                'last_order_date' => $lastOrderDate,
                'months_since_last' => $monthsSince,
                'buyer_count' => (int) $row->buyer_count,
                'cohort_penetration_pct' => $penetration,
                'cohort_avg_qty' => round($avgQty, 2),
                'avg_unit_price' => round($avgPrice, 2),
                'opportunity_value' => $opportunityValue,
            ];
        }

        usort($opportunities, fn ($a, $b) => ($b['cohort_penetration_pct'] <=> $a['cohort_penetration_pct'])
            ?: ($b['opportunity_value'] <=> $a['opportunity_value']));
        $opportunities = $this->attachInventoryClassifications(array_slice(array_values($opportunities), 0, 50));

        $summary['opportunity_count'] = count($opportunities);
        $summary['opportunity_value'] = round($opportunityValueTotal, 2);

        return response()->json([
            'customer_id' => $customer->acumatica_id,
            'customer_name' => $customer->name,
            'cohort_dimension' => $cohortDim,
            'cohort_value' => $cohortLabel,
            'available' => true,
            'opportunities' => $opportunities,
            'summary' => $summary,
            'data_as_of' => Carbon::now($tz)->toIso8601String(),
        ]);
    }

    /**
     * Default shape for the white-spot summary, merged with computed values.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function whitespaceSummary(array $overrides = []): array
    {
        return array_merge([
            'cohort_dimension' => null,
            'cohort_value' => null,
            'peer_accounts' => 0,
            'active_peers' => 0,
            'analysis_window_months' => 6,
            'lapsed_months' => 3,
            'min_penetration_pct' => 25,
            'catalog_lines' => 0,
            'focal_lines_in_window' => 0,
            'focal_line_penetration_pct' => 0.0,
            'cohort_avg_lines' => 0.0,
            'opportunity_count' => 0,
            'opportunity_value' => 0.0,
            'monthly_lines' => [],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function attachInventoryClassifications(array $rows): array
    {
        if ($rows === []) {
            return $rows;
        }

        $resolver = app(OperationsCatalogResolver::class);
        $classifications = $resolver->classificationsForInventoryIds(
            array_values(array_filter(array_column($rows, 'inventory_id'))),
        );

        return array_map(function (array $row) use ($resolver, $classifications) {
            foreach ($resolver->classificationFieldsFor($row['inventory_id'] ?? null, $classifications) as $field => $value) {
                $row[$field] = $value;
            }

            return $row;
        }, $rows);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Customers are managed via Acumatica sync.'], 405);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json(['message' => 'Customers are managed via Acumatica sync.'], 405);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Customers are managed via Acumatica sync.'], 405);
    }
}
