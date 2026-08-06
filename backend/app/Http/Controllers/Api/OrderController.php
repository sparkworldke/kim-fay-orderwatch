<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaSalesOrder;
use App\Models\Email;
use App\Services\OrderMatch\CustomerPoMatchResolver;
use App\Services\Operations\OperationsCatalogResolver;
use App\Services\Operations\SalesOrderReasonTaxonomyService;
use App\Support\DataScope;
use App\Services\Team\ConsultantGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Models\FulfillmentHistorySnapshot;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\User;
use App\Services\Cache\DomainCache;

class OrderController extends Controller
{
    public function __construct(
        private readonly CustomerPoMatchResolver $poResolver,
        private readonly SalesOrderReasonTaxonomyService $reasonTaxonomy,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $query = $this->scopedOrderQuery($request)
            ->leftJoin('acumatica_customers as ac', 'acumatica_sales_orders.customer_acumatica_id', '=', 'ac.acumatica_id')
            ->select($this->orderIndexColumns())
            ->withCount('lines')
            ->when($request->boolean('with_fulfillment'), fn ($q) => $q
                ->withSum('lines', 'backorder_qty')
                ->addSelect([
                    'acumatica_sales_orders.raw_payload',
                    // Quantity-weighted fill rate: SUM(capped shipped qty) / SUM(order_qty) × 100.
                    // This matches FillRateCalculator::compute() and the SO detail page.
                    // The old withAvg('lines','fill_rate_pct') averaged per-line percentages
                    // which gives wrong results when line qtys differ (e.g. 80% instead of 69%).
                    DB::raw('(
                        SELECT CASE
                            WHEN SUM(COALESCE(l.order_qty, 0)) > 0
                            THEN ROUND(
                                SUM(
                                    CASE
                                        WHEN COALESCE(l.shipped_qty, 0) > COALESCE(l.qty_on_shipments, 0)
                                            THEN CASE
                                                WHEN COALESCE(l.shipped_qty, 0) > COALESCE(l.order_qty, 0)
                                                    THEN COALESCE(l.order_qty, 0)
                                                ELSE COALESCE(l.shipped_qty, 0)
                                            END
                                        ELSE CASE
                                            WHEN COALESCE(l.qty_on_shipments, 0) > COALESCE(l.order_qty, 0)
                                                THEN COALESCE(l.order_qty, 0)
                                            ELSE COALESCE(l.qty_on_shipments, 0)
                                        END
                                    END
                                ) * 100.0 / SUM(COALESCE(l.order_qty, 0)),
                                2
                            )
                            ELSE NULL
                        END
                        FROM acumatica_sales_order_lines AS l
                        WHERE l.sales_order_id = acumatica_sales_orders.id
                          AND COALESCE(l.order_qty, 0) > 0
                    ) AS lines_avg_fill_rate_pct'),
                    DB::raw('(
                        SELECT COALESCE(SUM(
                            CASE
                                WHEN COALESCE(l.order_qty, 0) - COALESCE(l.shipped_qty, 0) > 0
                                    THEN (COALESCE(l.order_qty, 0) - COALESCE(l.shipped_qty, 0)) * COALESCE(l.unit_price, 0)
                                ELSE 0
                            END
                        ), 0)
                        FROM acumatica_sales_order_lines AS l
                        WHERE l.sales_order_id = acumatica_sales_orders.id
                    ) AS revenue_lost'),
                ]));

        $this->applySort($query, (string) $request->input('sort', 'latest'));

        if ($request->has('date_from')) {
            $query->whereDate('acumatica_sales_orders.order_date', '>=', $request->input('date_from'));
        }

        if ($request->has('date_to')) {
            $query->whereDate('acumatica_sales_orders.order_date', '<=', $request->input('date_to'));
        }

        if ($request->has('customer_id')) {
            $query->where('acumatica_sales_orders.customer_acumatica_id', $request->input('customer_id'));
        }

        $this->applyBusinessFilters($query, $request);

        $this->applyConsultantFilters($query, $request);

        if ($request->has('status')) {
            $query->where('acumatica_sales_orders.status', $request->input('status'));
        }

        $this->applyDocumentTypeFilter($query, $request);

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

    public function show(Request $request, string $id): JsonResponse
    {
        $order = AcumaticaSalesOrder::with(['lines', 'customer'])
            ->where('acumatica_order_nbr', $id)
            ->orWhere('id', $id)
            ->firstOrFail();

        if (! DataScope::orderBelongsToUser($request->user(), $order->sales_consultant_rep_code)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $inventoryIds = DataScope::scopedInventoryIds($request->user());
        if ($inventoryIds !== null) {
            $order->setRelation('lines', $order->lines
                ->whereIn('inventory_id', $inventoryIds)
                ->values());
            if ($order->lines->isEmpty()) {
                return response()->json(['message' => 'Order not found.'], 404);
            }
            $order->order_total = round((float) $order->lines->sum(
                fn ($line) => (float) $line->order_qty * (float) $line->unit_price,
            ), 2);
        }

        // Resolve name inline for the detail view too
        if (! $order->customer_name && $order->customer) {
            $order->customer_name = $order->customer->name;
        }

        $this->attachMatchDiscrepanciesToOrder($order);
        $this->attachLineInventoryClassifications($order);
        $history = FulfillmentHistorySnapshot::with('lines')->where('order_nbr', $order->acumatica_order_nbr)->first();
        if ($history && $inventoryIds !== null) {
            $history->setRelation('lines', $history->lines->whereIn('inventory_id', $inventoryIds)->values());
        }
        // Finalized documents cannot still ship — line open_qty is often stale
        // (frozen at first sync), so the current outstanding balance is 0.
        $finalized = in_array(strtolower(trim((string) $order->status)), ['completed', 'cancelled', 'closed', 'invoiced'], true);
        $currentAmount = $finalized
            ? 0.0
            : $order->lines->sum(fn ($line) => max((float) $line->open_qty, 0) * max((float) $line->unit_price, 0));
        $order->setAttribute('fulfillment_history', $history);
        $order->setAttribute('historical_shortfall_amount', (float) ($history?->historical_shortfall_amount ?? 0));
        $order->setAttribute('current_outstanding_amount', round($currentAmount, 2));
        $order->setAttribute('delivered_later', $history !== null && (float) $history->historical_shortfall_amount > 0 && $currentAmount <= 0);

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
        $this->applyBusinessFilters($base, $request);
        $this->applyConsultantFilters($base, $request);
        if ($request->has('status')) {
            $base->where('acumatica_sales_orders.status', $request->input('status'));
        }

        $this->applyDocumentTypeFilter($base, $request);

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

        $typeRows = (clone $base)
            ->select(['acumatica_sales_orders.order_type', DB::raw('COUNT(*) as cnt')])
            ->groupBy('acumatica_sales_orders.order_type')
            ->pluck('cnt', 'order_type')
            ->mapWithKeys(fn ($cnt, $type) => [strtoupper(trim($type ?? 'unknown')) => (int) $cnt])
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
            'by_type'          => $typeRows,
        ]);
    }

    public function filterOptions(Request $request): JsonResponse
    {
        $customersQuery = AcumaticaCustomer::query()->where('status', '!=', 'Inactive');
        $scopedCustomerIds = DataScope::scopedCustomerAcumaticaIds($request->user());
        if ($scopedCustomerIds !== null) {
            $customersQuery->whereIn('acumatica_id', $scopedCustomerIds);
        }
        $customers = $customersQuery
            ->orderBy('name')
            ->get(['acumatica_id', 'parent_acumatica_id', 'name', 'customer_class']);
        $byId = $customers->keyBy('acumatica_id');
        $groups = [];

        foreach ($customers as $customer) {
            $rootId = $customer->parent_acumatica_id && $byId->has($customer->parent_acumatica_id)
                ? $customer->parent_acumatica_id
                : $customer->acumatica_id;
            $root = $byId->get($rootId, $customer);
            $groups[$rootId] ??= [
                'id' => $rootId,
                'name' => $root->name ?: $rootId,
                'segment' => str_starts_with(strtoupper(trim((string) $root->customer_class)), 'KP') ? 'KP' : 'CS',
                'outlets' => [],
            ];
            if ($customer->acumatica_id !== $rootId) {
                $groups[$rootId]['outlets'][] = [
                    'id' => $customer->acumatica_id,
                    'name' => $customer->name ?: $customer->acumatica_id,
                ];
            }
        }

        foreach ($groups as &$group) {
            if ($group['outlets'] === []) {
                $group['outlets'][] = ['id' => $group['id'], 'name' => $group['name']];
            }
        }
        unset($group);

        $brands = AcumaticaInventoryItem::query()
            ->whereNotNull('brand')->where('brand', '!=', '')
            ->distinct()->orderBy('brand')->pluck('brand')->values();

        $visibleRepCodes = $this->scopedOrderQuery($request)
            ->whereNotNull('sales_consultant_rep_code')
            ->where('sales_consultant_rep_code', '!=', '')
            ->distinct()
            ->pluck('sales_consultant_rep_code')
            ->map(fn ($code) => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values();

        $users = User::query()
            ->where('is_active', true)
            ->whereNotNull('rep_code')
            ->whereIn(DB::raw('UPPER(rep_code)'), $visibleRepCodes->all())
            ->orderBy('name')
            ->get(['id', 'name', 'rep_code', 'reports_to_user_id', 'org_level', 'department_role']);

        $allUsers = $users->keyBy('id');
        $ancestorIds = $users->pluck('reports_to_user_id')->filter()->unique();
        while ($ancestorIds->isNotEmpty()) {
            $missingIds = $ancestorIds->diff($allUsers->keys());
            if ($missingIds->isEmpty()) {
                break;
            }
            $ancestors = User::query()
                ->whereIn('id', $missingIds->all())
                ->get(['id', 'name', 'reports_to_user_id', 'org_level', 'department_role']);
            if ($ancestors->isEmpty()) {
                break;
            }
            foreach ($ancestors as $ancestor) {
                $allUsers->put($ancestor->id, $ancestor);
            }
            $ancestorIds = $ancestors->pluck('reports_to_user_id')->filter()->unique();
        }

        $consultantGroups = [];
        foreach ($users as $consultant) {
            $manager = $consultant->reports_to_user_id
                ? $allUsers->get($consultant->reports_to_user_id)
                : null;
            $groupLeader = $manager;
            $cursor = $manager;
            $visited = [];

            while ($cursor !== null && ! in_array($cursor->id, $visited, true)) {
                $visited[] = $cursor->id;
                if ($cursor->org_level === 'hod' || $cursor->department_role === 'hod') {
                    $groupLeader = $cursor;
                    break;
                }
                $cursor = $cursor->reports_to_user_id
                    ? $allUsers->get($cursor->reports_to_user_id)
                    : null;
            }

            $groupId = $groupLeader ? (string) $groupLeader->id : 'unassigned';
            $consultantGroups[$groupId] ??= [
                'id' => $groupId,
                'name' => $groupLeader?->name ?? 'Unassigned consultants',
                'is_hod' => $groupLeader !== null
                    && ($groupLeader->org_level === 'hod' || $groupLeader->department_role === 'hod'),
                'members' => [],
            ];
            $consultantGroups[$groupId]['members'][] = [
                'id' => $consultant->id,
                'name' => $consultant->name,
                'rep_code' => strtoupper(trim((string) $consultant->rep_code)),
            ];
        }

        foreach ($consultantGroups as &$consultantGroup) {
            usort($consultantGroup['members'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($consultantGroup);

        return response()->json([
            'segments' => [
                ['id' => 'KP', 'name' => 'KP (Kim-Fay Professional)'],
                ['id' => 'CS', 'name' => 'Consumer Sales'],
            ],
            'brands' => $brands,
            'parents' => collect($groups)->sortBy('name')->values(),
            'consultant_groups' => collect($consultantGroups)
                ->sortBy(fn ($group) => [
                    $group['name'] === 'Unassigned consultants' ? 1 : 0,
                    strtolower($group['name']),
                ])
                ->values(),
        ]);
    }

    private function applyConsultantFilters($query, Request $request): void
    {
        $repCodes = array_merge(
            (array) $request->input('rep_codes', []),
            $request->filled('rep_code') ? [(string) $request->input('rep_code')] : [],
        );
        $repCodes = array_values(array_unique(array_filter(array_map(
            fn ($code) => strtoupper(trim((string) $code)),
            $repCodes,
        ))));

        if ($repCodes !== []) {
            $query->whereIn('acumatica_sales_orders.sales_consultant_rep_code', $repCodes);
        }
    }

    private function applyBusinessFilters($query, Request $request): void
    {
        $segments = array_values(array_filter((array) $request->input('segments', [])));
        if ($segments !== []) {
            $query->where(function ($segmentQuery) use ($segments) {
                if (in_array('KP', $segments, true)) {
                    $segmentQuery->orWhereRaw("UPPER(TRIM(COALESCE(ac.customer_class, ''))) LIKE 'KP%'");
                }
                if (in_array('CS', $segments, true)) {
                    $segmentQuery->orWhereRaw("UPPER(TRIM(COALESCE(ac.customer_class, ''))) NOT LIKE 'KP%'");
                }
            });
        }

        $customerIds = array_values(array_filter((array) $request->input('customer_ids', [])));
        $parentIds = array_values(array_filter((array) $request->input('parent_customer_ids', [])));
        if ($customerIds === [] && $parentIds !== []) {
            $familyIds = AcumaticaCustomer::query()
                ->whereIn('parent_acumatica_id', $parentIds)
                ->orWhereIn('acumatica_id', $parentIds)
                ->pluck('acumatica_id')->all();
            $customerIds = array_values(array_unique([...$customerIds, ...$familyIds]));
        }
        if ($customerIds !== []) {
            $query->whereIn('acumatica_sales_orders.customer_acumatica_id', $customerIds);
        }

        $brands = array_values(array_filter((array) $request->input('brands', [])));
        if ($brands !== []) {
            $query->whereExists(function ($brandQuery) use ($brands) {
                $brandQuery->selectRaw('1')
                    ->from('acumatica_sales_order_lines as brand_lines')
                    ->join('acumatica_inventory_items as brand_items', 'brand_items.inventory_id', '=', 'brand_lines.inventory_id')
                    ->whereColumn('brand_lines.sales_order_id', 'acumatica_sales_orders.id')
                    ->whereIn('brand_items.brand', $brands);
            });
        }
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

        if (! DataScope::orderBelongsToUser($request->user(), $order->sales_consultant_rep_code)) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $approvedCodes = $this->reasonTaxonomy->approvedSubReasonCodes();

        $validated = $request->validate([
            'status'           => ['sometimes', 'string', 'in:Open,Completed,Cancelled,Back Order,Credit Hold,On Hold,Rejected,Shipping,Pending Approval'],
            'match_status'     => ['sometimes', 'string', 'in:pending,matched,matched_discrepancies,needs_review,unmatched,duplicate,escalated,missing'],
            'flag_source'      => ['sometimes', 'nullable', 'string', 'in:acumatica,email'],
            'rejection_reason_code' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $approvedCodes)],
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'on_hold_reason'   => ['sometimes', 'nullable', 'string', 'max:2000'],
            'email_subject'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'email_received_at'=> ['sometimes', 'nullable', 'date'],
        ]);

        $resolvedStatus = $validated['status'] ?? $order->status;
        $resolvedRejectionCode = array_key_exists('rejection_reason_code', $validated)
            ? $validated['rejection_reason_code']
            : $order->rejection_reason_code;

        if ($this->statusRequiresWorkflowReason($resolvedStatus) && blank($resolvedRejectionCode)) {
            throw ValidationException::withMessages([
                'rejection_reason_code' => ['A standardized reason is required for cancelled, rejected, and on-hold orders.'],
            ]);
        }

        $workflow = $this->reasonTaxonomy->workflowAttributesForOrder($resolvedStatus, $resolvedRejectionCode);

        $order->update(array_merge($validated, [
            'rejection_reason_code' => $workflow['rejection_reason_code'] ?? $resolvedRejectionCode,
            'workflow_parent_reason' => $workflow['workflow_parent_reason'],
            'workflow_sub_reason_code' => $workflow['workflow_sub_reason_code'],
            'workflow_reason_label' => $workflow['workflow_reason_label'],
        ]));
        app(DomainCache::class)->bump(
            DomainCache::ORDERS,
            DomainCache::BACKORDERS,
            DomainCache::FILL_RATE,
            DomainCache::BUSINESS_OPTIMIZATION,
            DomainCache::NOT_DELIVERED,
            DomainCache::CUSTOMER_ANALYTICS,
            DomainCache::SALES_PORTFOLIO,
            DomainCache::SALES_INTELLIGENCE,
            DomainCache::KP_CRM,
        );

        return response()->json($order->withCount('lines')->first());
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['message' => 'Orders are managed via Acumatica sync.'], 405);
    }

    /**
     * Guardrail: dashboard/orders use SO only; Credit Notes & More page uses CREDIT_NOTES_MORE.
     */
    private function applySort($query, string $sort): void
    {
        match ($sort) {
            'oldest'      => $query->orderBy('acumatica_sales_orders.order_date', 'asc'),
            'amount_desc' => $query->orderByDesc('acumatica_sales_orders.order_total'),
            'amount_asc'  => $query->orderBy('acumatica_sales_orders.order_total', 'asc'),
            default       => $query->orderByDesc('acumatica_sales_orders.order_date'),
        };
    }

    private function statusRequiresWorkflowReason(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), [
            'rejected',
            'cancelled',
            'canceled',
            'on hold',
            'credit hold',
        ], true);
    }

    private function scopedOrderQuery(Request $request)
    {
        $type = strtoupper(trim((string) $request->input('order_type', 'SO')));

        if (in_array($type, ['ALL', '*'], true)) {
            $query = AcumaticaSalesOrder::query();
        } elseif ($type === 'CREDIT_NOTES_MORE') {
            $query = AcumaticaSalesOrder::query()->creditNotesAndMore();
        } else {
            $query = AcumaticaSalesOrder::query()->salesOrdersOnly();
        }

        return DataScope::applyOrderScope($query, $request->user());
    }

    private function applyDocumentTypeFilter($query, Request $request): void
    {
        $scope = strtoupper(trim((string) $request->input('order_type', 'SO')));
        $documentType = strtoupper(trim((string) $request->input('document_type', '')));

        if ($scope !== 'CREDIT_NOTES_MORE' || $documentType === '' || $documentType === 'ALL') {
            return;
        }

        if (in_array($documentType, AcumaticaSalesOrder::CREDIT_NOTES_AND_MORE_TYPES, true)) {
            $query->where('acumatica_sales_orders.order_type', $documentType);
        }
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
            'acumatica_sales_orders.sales_consultant_rep_code',
            'acumatica_sales_orders.sales_consultant_name',
            'acumatica_sales_orders.rejection_reason',
            'acumatica_sales_orders.rejection_reason_code',
            'acumatica_sales_orders.on_hold_reason',
            'acumatica_sales_orders.workflow_parent_reason',
            'acumatica_sales_orders.workflow_sub_reason_code',
            'acumatica_sales_orders.workflow_reason_label',
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

    private function attachLineInventoryClassifications(AcumaticaSalesOrder $order): void
    {
        if (! $order->relationLoaded('lines') || $order->lines->isEmpty()) {
            return;
        }

        $resolver = app(OperationsCatalogResolver::class);
        $classifications = $resolver->classificationsForInventoryIds(
            $order->lines->pluck('inventory_id')->all(),
        );

        $order->lines->transform(function ($line) use ($resolver, $classifications) {
            foreach ($resolver->classificationFieldsFor($line->inventory_id, $classifications) as $field => $value) {
                $line->{$field} = $value;
            }

            return $line;
        });
    }

    public function assignConsultant(Request $request, int $id, ConsultantGuard $guard): JsonResponse
    {
        $validated = $request->validate([
            'consultant_user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $order = AcumaticaSalesOrder::query()->with('customer:acumatica_id,customer_class')->findOrFail($id);

        if (! DataScope::orderBelongsToUser(
            $request->user(),
            $order->sales_consultant_rep_code,
            $order->customer_acumatica_id,
            $order->customer?->customer_class,
        )) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $consultant = \App\Models\User::query()->findOrFail($validated['consultant_user_id']);

        try {
            $order = $guard->assignToOrder($order, $consultant, $request->user(), 'manual');
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        app(DomainCache::class)->bump(
            DomainCache::ORDERS,
            DomainCache::CUSTOMER_ANALYTICS,
            DomainCache::SALES_PORTFOLIO,
            DomainCache::SALES_INTELLIGENCE,
            DomainCache::KP_CRM,
        );

        return response()->json([
            'message' => 'Consultant assigned successfully.',
            'order' => $order->only([
                'id',
                'acumatica_order_nbr',
                'consultant_user_id',
                'sales_consultant_rep_code',
                'sales_consultant_name',
            ]),
        ]);
    }
}
