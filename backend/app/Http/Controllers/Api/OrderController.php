<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Services\OrderMatch\CustomerPoMatchResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderController extends Controller
{
    public function __construct(
        private readonly CustomerPoMatchResolver $poResolver = new CustomerPoMatchResolver,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedOrderQuery($request)
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id')
            ->withCount('lines')
            ->select($this->orderIndexColumns())
            ->when(
            $request->input('sort', 'latest') === 'oldest',
            fn ($q) => $q->orderBy('acumatica_sales_orders.order_date', 'asc'),
            fn ($q) => $q->orderByDesc('acumatica_sales_orders.order_date')
        );

        if ($request->has('date_from')) {
            $query->whereDate('acumatica_sales_orders.order_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('acumatica_sales_orders.order_date', '<=', $request->input('date_to'));
        }

        if ($request->has('customer_id')) {
            $query->where('acumatica_sales_orders.customer_acumatica_id', $request->input('customer_id'));
        }

        if ($request->has('status')) {
            $query->where('acumatica_sales_orders.status', $request->input('status'));
        }

        if ($request->filled('match_status')) {
            $query->where('acumatica_sales_orders.match_status', $request->input('match_status'));
        }

        if ($request->has('flag_source')) {
            $flag = $request->input('flag_source');
            if ($flag === 'none') {
                $query->whereNull('acumatica_sales_orders.flag_source');
            } elseif (in_array($flag, ['acumatica', 'email'], true)) {
                $query->where('acumatica_sales_orders.flag_source', $flag);
            }
        }

        if ($request->has('has_email')) {
            if ($request->boolean('has_email')) {
                $query->whereNotNull('acumatica_sales_orders.email_received_at');
            } else {
                $query->whereNull('acumatica_sales_orders.email_received_at');
            }
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('acumatica_sales_orders.acumatica_order_nbr', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_name', 'like', "%{$q}%")
                   ->orWhere('ac.name', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_order', 'like', "%{$q}%");
            });
        }

        $paginator = $query->paginate(min((int) $request->input('per_page', 50), 200));
        $this->attachMatchDiscrepancies($paginator);

        return response()->json($paginator);
    }

    public function show(string $id): JsonResponse
    {
        $order = AcumaticaSalesOrder::with(['lines', 'customer'])
            ->where('acumatica_order_nbr', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        // Resolve name inline for the detail view too
        if (! $order->customer_name && $order->customer) {
            $order->customer_name = $order->customer->name;
        }

        $this->attachMatchDiscrepanciesToOrder($order);

        return response()->json($order);
    }

    /**
     * Return status-breakdown counts matching the same filters as index().
     * Used by the stat cards on the Orders page and Dashboard.
     */
    public function stats(Request $request): JsonResponse
    {
        $base = $this->scopedOrderQuery($request)
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id');

        if ($request->has('date_from')) {
            $base->whereDate('acumatica_sales_orders.order_date', '>=', $request->input('date_from'));
        }
        if ($request->has('date_to')) {
            $base->whereDate('acumatica_sales_orders.order_date', '<=', $request->input('date_to'));
        }
        if ($request->has('customer_id')) {
            $base->where('acumatica_sales_orders.customer_acumatica_id', $request->input('customer_id'));
        }

        $q = trim((string) $request->input('q', ''));
        if ($q !== '') {
            $base->where(function ($qb) use ($q) {
                $qb->where('acumatica_sales_orders.acumatica_order_nbr', 'like', "%{$q}%")
                   ->orWhere('acumatica_sales_orders.customer_name', 'like', "%{$q}%")
                   ->orWhere('ac.name', 'like', "%{$q}%");
            });
        }

        $rows = (clone $base)
            ->select(['acumatica_sales_orders.status', DB::raw('COUNT(*) as cnt')])
            ->groupBy('acumatica_sales_orders.status')
            ->pluck('cnt', 'status')
            ->mapWithKeys(fn ($cnt, $status) => [strtolower(trim($status ?? 'unknown')) => (int) $cnt])
            ->toArray();

        $matchRows = (clone $base)
            ->select(['acumatica_sales_orders.match_status', DB::raw('COUNT(*) as cnt')])
            ->groupBy('acumatica_sales_orders.match_status')
            ->pluck('cnt', 'match_status')
            ->mapWithKeys(fn ($cnt, $status) => [strtolower(trim($status ?? 'pending')) => (int) $cnt])
            ->toArray();

        return response()->json([
            'total'            => (int) (clone $base)->count(),
            'completed'        => $rows['completed']        ?? 0,
            'shipping'         => $rows['shipping']         ?? 0,
            'pending_approval' => $rows['pending approval'] ?? 0,
            'rejected'         => $rows['rejected']         ?? 0,
            'on_hold'          => ($rows['on hold'] ?? 0) + ($rows['credit hold'] ?? 0),
            'open'             => $rows['open']             ?? 0,
            'email_in'         => (int) (clone $base)->whereNotNull('acumatica_sales_orders.email_received_at')->count(),
            'matched'          => $matchRows['matched'] ?? 0,
            'matched_discrepancies' => $matchRows['matched_discrepancies'] ?? 0,
            'needs_review'     => $matchRows['needs_review'] ?? 0,
            'missing'          => $matchRows['missing'] ?? 0,
            'pending'          => $matchRows['pending'] ?? 0,
            'unmatched'        => $matchRows['unmatched'] ?? 0,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Orders are managed via Acumatica sync.'], 405);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $order = AcumaticaSalesOrder::where('acumatica_order_nbr', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'match_status'     => ['sometimes', 'string', 'in:pending,matched,matched_discrepancies,needs_review,unmatched,duplicate,escalated,missing'],
            'flag_source'      => ['sometimes', 'nullable', 'string', 'in:acumatica,email'],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'on_hold_reason'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email_subject'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'email_received_at'=> ['sometimes', 'nullable', 'date'],
        ]);

        $order->update($validated);

        return response()->json($order->withCount('lines')->first());
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Orders are managed via Acumatica sync.'], 405);
    }

    /**
     * Guardrail: dashboard/orders use SO only; Credit Notes & More page uses CREDIT_NOTES_MORE.
     */
    private function scopedOrderQuery(Request $request)
    {
        $type = strtoupper(trim((string) $request->input('order_type', 'SO')));

        if ($type === 'CREDIT_NOTES_MORE') {
            return AcumaticaSalesOrder::query()->creditNotesAndMore();
        }

        return AcumaticaSalesOrder::query()->salesOrdersOnly();
    }

    private function attachMatchDiscrepancies(LengthAwarePaginator $paginator): void
    {
        $paginator->setCollection(
            $this->enrichOrdersWithMatchDiscrepancies(collect($paginator->items()))
        );
    }

    private function attachMatchDiscrepanciesToOrder(AcumaticaSalesOrder $order): void
    {
        $this->enrichOrdersWithMatchDiscrepancies(collect([$order]))->first();
    }

    /**
     * @param  Collection<int, AcumaticaSalesOrder|object>  $orders
     * @return Collection<int, AcumaticaSalesOrder|object>
     */
    private function enrichOrdersWithMatchDiscrepancies(Collection $orders): Collection
    {
        if ($orders->isEmpty()) {
            return $orders;
        }

        $orderIds = $orders->pluck('id')->filter()->values();
        $emailsByOrder = $this->loadMatchEmailsByOrder($orderIds);

        return $orders->map(function ($order) use ($emailsByOrder) {
            $email = $emailsByOrder->get($order->id);
            $order->matched_po_number = $email?->canonical_po ?? $email?->extracted_po_number;
            $order->extracted_po_number = $email?->extracted_po_number;
            $order->match_conflicts = $email?->match_conflicts ?? [];
            $order->sanitized_po_number = $this->resolveSanitizedPo(
                $order->customer_order,
                $order->customer_name,
                $order->matched_po_number,
            );

            return $order;
        });
    }

    private function resolveSanitizedPo(
        ?string $customerOrder,
        ?string $customerName,
        ?string $matchedPo,
    ): ?string {
        if ($customerOrder !== null && trim($customerOrder) !== '') {
            $sender = $this->inferSenderForCustomer($customerName);
            $sanitized = $this->poResolver->toCustomerOrderId($customerOrder, $sender, $customerName);
            if ($sanitized !== null) {
                return $sanitized;
            }
        }

        return $matchedPo !== null && trim($matchedPo) !== '' ? $matchedPo : null;
    }

    private function inferSenderForCustomer(?string $customerName): ?string
    {
        if ($customerName === null) {
            return null;
        }

        if (stripos($customerName, 'naivas') !== false) {
            return CustomerPoMatchResolver::NAIVAS_SENDER;
        }

        if (stripos($customerName, 'carrefour') !== false) {
            return CustomerPoMatchResolver::CARREFOUR_SENDER;
        }

        return null;
    }

    /** @return list<string|object> */
    private function orderIndexColumns(): array
    {
        $columns = [
            'acumatica_sales_orders.id',
            'acumatica_sales_orders.acumatica_order_nbr',
            'acumatica_sales_orders.order_type',
            'acumatica_sales_orders.customer_acumatica_id',
            DB::raw('COALESCE(acumatica_sales_orders.customer_name, ac.name) as customer_name'),
            'acumatica_sales_orders.customer_order',
            'acumatica_sales_orders.status',
            'acumatica_sales_orders.match_status',
            'acumatica_sales_orders.flag_source',
            'acumatica_sales_orders.order_date',
            'acumatica_sales_orders.ship_date',
            'acumatica_sales_orders.requested_on',
            'acumatica_sales_orders.last_modified_at',
            'acumatica_sales_orders.approved_at',
            'acumatica_sales_orders.shipped_at',
            'acumatica_sales_orders.completed_at',
            'acumatica_sales_orders.order_total',
            'acumatica_sales_orders.currency_id',
            'acumatica_sales_orders.rejection_reason',
            'acumatica_sales_orders.on_hold_reason',
            'acumatica_sales_orders.email_subject',
            'acumatica_sales_orders.email_received_at',
            'acumatica_sales_orders.synced_at',
        ];

        if (Schema::hasColumn('acumatica_sales_orders', 'approved_by_id')) {
            $columns[] = 'acumatica_sales_orders.approved_by_id';
        }

        return $columns;
    }

    /**
     * @param  Collection<int, int|string>  $orderIds
     * @return Collection<int|string, Email>
     */
    private function loadMatchEmailsByOrder(Collection $orderIds): Collection
    {
        if ($orderIds->isEmpty() || ! Schema::hasColumn('emails', 'matched_order_id')) {
            return collect();
        }

        $emailColumns = ['matched_order_id', 'extracted_po_number'];
        if (Schema::hasColumn('emails', 'canonical_po')) {
            $emailColumns[] = 'canonical_po';
        }
        if (Schema::hasColumn('emails', 'match_conflicts')) {
            $emailColumns[] = 'match_conflicts';
        }

        $query = Email::query()->whereIn('matched_order_id', $orderIds);

        if (Schema::hasColumn('emails', 'match_classification') && Schema::hasColumn('emails', 'match_conflicts')) {
            $query->where(function ($builder) {
                $builder->where('match_classification', 'matched_discrepancies')
                    ->orWhereNotNull('match_conflicts');
            });
        } elseif (Schema::hasColumn('emails', 'match_classification')) {
            $query->where('match_classification', 'matched_discrepancies');
        } elseif (Schema::hasColumn('emails', 'match_conflicts')) {
            $query->whereNotNull('match_conflicts');
        }

        return $query
            ->orderByDesc('updated_at')
            ->get($emailColumns)
            ->unique('matched_order_id')
            ->keyBy('matched_order_id');
    }
}
