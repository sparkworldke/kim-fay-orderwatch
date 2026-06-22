<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AcumaticaCustomer::query()->orderBy('name');

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

        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        return response()->json($query->paginate(min((int) $request->input('per_page', 50), 200)));
    }

    /**
     * Category summary — each customer_class with Active / Inactive / On Hold breakdown.
     */
    public function categories(): JsonResponse
    {
        $rows = AcumaticaCustomer::query()
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
    public function byCategory(string $class): JsonResponse
    {
        $customers = AcumaticaCustomer::query()
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

    public function show(string $id): JsonResponse
    {
        $customer = AcumaticaCustomer::where('acumatica_id', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        return response()->json($customer);
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
