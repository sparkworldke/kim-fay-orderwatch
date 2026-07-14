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
        $customer->loadMissing('shippingZone');
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
