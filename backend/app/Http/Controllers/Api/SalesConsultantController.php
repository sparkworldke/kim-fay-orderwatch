<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaSalesOrder;
use App\Models\User;
use App\Services\Admin\SalesConsultantImportService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Illuminate\Support\Facades\DB;

class SalesConsultantController extends Controller
{
    private const FULL_ACCESS_ROLES = [
        'Administrator',
        'Customer Service Manager',
        'Executive',
    ];

    private const OWN_PROFILE_ROLES = [
        'Sales Operations',
        'Sales Consultant',
    ];

    public function __construct(private readonly SalesConsultantImportService $importService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = (string) ($user?->role ?? '');

        if (! in_array($role, self::FULL_ACCESS_ROLES, true)
            && ! in_array($role, self::OWN_PROFILE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $repCode = strtoupper(trim((string) ($user?->rep_code ?? '')));
        $scope = in_array($role, self::FULL_ACCESS_ROLES, true) ? 'all' : 'own';

        if ($scope === 'own' && $repCode === '') {
            return response()->json([
                'scope' => 'own',
                'rep_code' => null,
                'items' => [],
                'message' => 'No Rep Code is assigned to your profile.',
            ]);
        }

        $search = trim((string) $request->input('q', ''));

        $rows = User::query()
            ->where(function ($q) {
                $q->where('users.is_consultant', true)
                    ->orWhere('users.role', 'Sales Consultant');
            })
            ->where(function ($q) {
                $q->whereNotNull('users.rep_code')
                    ->orWhere('users.is_consultant', true);
            })
            ->when($scope === 'own', fn ($query) => $query->where('users.rep_code', $repCode))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like, $search) {
                    $q->where('users.name', 'like', $like)
                      ->orWhere('users.rep_code', 'like', $like)
                      ->orWhere('users.employee_number', 'like', $like);
                });
            })
            ->leftJoin('acumatica_sales_orders as so', 'users.rep_code', '=', 'so.sales_consultant_rep_code')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'users.rep_code',
                'users.employee_number',
                'users.is_active',
                DB::raw('COUNT(so.id) as assigned_orders'),
                DB::raw("SUM(CASE WHEN so.id IS NOT NULL AND so.completed_at IS NULL THEN 1 ELSE 0 END) as active_orders"),
                DB::raw("SUM(CASE WHEN so.id IS NOT NULL AND so.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_orders"),
                DB::raw('COALESCE(SUM(so.order_total), 0) as assigned_revenue'),
                DB::raw('MAX(so.order_date) as last_order_date'),
            ])
            ->groupBy('users.id', 'users.name', 'users.email', 'users.role', 'users.rep_code', 'users.employee_number', 'users.is_active')
            ->orderBy('users.name')
            ->get();

        if ($scope === 'own' && $rows->isEmpty()) {
            $rows = collect([$this->ownProfileFallback($user, $repCode)]);
        }

        return response()->json([
            'scope' => $scope,
            'rep_code' => $scope === 'own' ? $repCode : null,
            'items' => $rows->map(fn ($row) => [
                'id' => $row->id,
                'name' => $row->name,
                'email' => $row->email,
                'role' => $row->role,
                'rep_code' => $row->rep_code,
                'employee_number' => $row->employee_number,
                'is_active' => (bool) $row->is_active,
                'assigned_orders' => (int) $row->assigned_orders,
                'active_orders' => (int) $row->active_orders,
                'completed_orders' => (int) $row->completed_orders,
                'assigned_revenue' => round((float) $row->assigned_revenue, 2),
                'last_order_date' => $row->last_order_date,
            ])->values(),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $consultant = $this->resolveConsultant($request, $id);
        if ($consultant instanceof JsonResponse) {
            return $consultant;
        }

        $repCode = strtoupper(trim((string) ($consultant->rep_code ?? '')));
        $summary = $repCode !== ''
            ? $this->buildSummary($this->ordersBaseQuery($repCode, $request))
            : [
                'total_order_value' => 0.0,
                'customer_count' => 0,
                'total_completed_orders' => 0,
                'active_orders' => 0,
                'total_orders' => 0,
                'last_order_date' => null,
            ];

        return response()->json([
            'consultant' => $this->formatConsultantProfile($consultant, $summary),
            'summary' => $summary,
        ]);
    }

    public function showByRepCode(Request $request, string $repCode): JsonResponse
    {
        $normalizedRepCode = strtoupper(trim($repCode));

        if ($normalizedRepCode === '') {
            return response()->json(['message' => 'Rep Code is required.'], 422);
        }

        $user = $request->user();
        $role = (string) ($user?->role ?? '');

        if ($denied = $this->authorizeRepCodeAccess($user, $role, $normalizedRepCode)) {
            return $denied;
        }

        $isFullAccess = in_array($role, self::FULL_ACCESS_ROLES, true);
        $consultant = null;

        if (! $isFullAccess && strtoupper(trim((string) ($user?->rep_code ?? ''))) === $normalizedRepCode) {
            $consultant = $user;
        }

        $consultant ??= User::query()
            ->where('users.rep_code', $normalizedRepCode)
            ->where(function ($query) {
                $query->where('users.is_consultant', true)
                    ->orWhere('users.role', 'Sales Consultant');
            })
            ->first();

        if (! $consultant) {
            return response()->json(['message' => 'Sales consultant not found.'], 404);
        }

        $summary = $this->buildSummary($this->ordersBaseQuery($normalizedRepCode, $request));

        return response()->json([
            'consultant' => $this->formatConsultantProfile($consultant, $summary),
            'summary' => $summary,
        ]);
    }

    /**
     * Compute a fill rate percentage from line-level data for a set of order IDs.
     * Returns null when no line data is available.
     *
     * @param  \Illuminate\Support\Collection  $orderIds
     */
    private function computeFillRate($orderIds): ?float
    {
        if ($orderIds->isEmpty()) {
            return null;
        }

        $lineStats = \App\Models\AcumaticaSalesOrderLine::query()
            ->whereIn('sales_order_id', $orderIds)
            ->selectRaw('
                COALESCE(
                    AVG(
                        CASE
                            WHEN order_qty > 0 THEN
                                CASE
                                    WHEN COALESCE(shipped_qty, 0) * 100.0 / order_qty > 100.0 THEN 100.0
                                    ELSE COALESCE(shipped_qty, 0) * 100.0 / order_qty
                                END
                            ELSE 100.0
                        END
                    ),
                    0
                ) as avg_fill_rate
            ')
            ->first();

        $avg = $lineStats?->avg_fill_rate;

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * Compute revenue lost (value of unshipped quantity) from line-level data.
     *
     * @param  \Illuminate\Support\Collection  $orderIds
     */
    private function computeRevenueLost($orderIds): float
    {
        if ($orderIds->isEmpty()) {
            return 0.0;
        }

        $lost = \App\Models\AcumaticaSalesOrderLine::query()
            ->whereIn('sales_order_id', $orderIds)
            ->selectRaw('
                COALESCE(
                    SUM(
                        CASE
                            WHEN COALESCE(order_qty, 0) - COALESCE(shipped_qty, 0) > 0
                                THEN (COALESCE(order_qty, 0) - COALESCE(shipped_qty, 0)) * COALESCE(unit_price, 0)
                            ELSE 0
                        END
                    ),
                    0
                ) as revenue_lost
            ')
            ->first();

        return round((float) ($lost?->revenue_lost ?? 0), 2);
    }

    public function customersById(Request $request, int $id): JsonResponse
    {
        $consultant = $this->resolveConsultant($request, $id);
        if ($consultant instanceof JsonResponse) {
            return $consultant;
        }

        $repCode = strtoupper(trim((string) ($consultant->rep_code ?? '')));
        if ($repCode === '') {
            return response()->json([
                'rep_code' => null,
                'summary' => [
                    'total_order_value' => 0.0,
                    'customer_count' => 0,
                    'total_completed_orders' => 0,
                    'active_orders' => 0,
                    'total_orders' => 0,
                    'last_order_date' => null,
                ],
                'customers' => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => max(1, min(500, (int) $request->input('per_page', 20))),
                    'total' => 0,
                    'from' => 0,
                    'to' => 0,
                ],
                'message' => 'This consultant has no rep code assigned yet.',
            ]);
        }

        return $this->customersResponse($request, $repCode);
    }

    public function customers(Request $request, string $repCode): JsonResponse
    {
        $user = $request->user();
        $role = (string) ($user?->role ?? '');
        $normalizedRepCode = strtoupper(trim($repCode));

        if ($normalizedRepCode === '') {
            return response()->json(['message' => 'Rep Code is required.'], 422);
        }

        if ($denied = $this->authorizeRepCodeAccess($user, $role, $normalizedRepCode)) {
            return $denied;
        }

        return $this->customersResponse($request, $normalizedRepCode);
    }

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', Rule::in(['sales_orders', 'acumatica_users'])],
            'rep_code' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9 ._\\-\\/]+$/'],
        ]);

        try {
            $result = $this->importService->import(
                $validated['source'],
                $validated['rep_code'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => 'Consultant import source is unavailable.',
                'error' => $exception->getMessage(),
            ], 502);
        }

        return response()->json([
            'message' => $this->importMessage($result),
            ...$result,
        ]);
    }

    /** @param array{found:int,created:int,updated:int,skipped:int} $result */
    private function importMessage(array $result): string
    {
        if ($result['found'] === 0) {
            return 'No matching consultants were found.';
        }

        return "Consultant import complete: {$result['created']} created, {$result['updated']} updated, {$result['skipped']} skipped.";
    }

    private function customersResponse(Request $request, string $normalizedRepCode): JsonResponse
    {
        $base = $this->ordersBaseQuery($normalizedRepCode, $request);
        $summary = $this->buildSummary($base);
        $dateFrom = $request->filled('date_from') ? (string) $request->input('date_from') : null;
        $dateTo = $request->filled('date_to') ? (string) $request->input('date_to') : null;
        $search = trim((string) $request->input('q', ''));

        $rows = (clone $base)
            ->leftJoin('acumatica_customers as cust', 'cust.acumatica_id', '=', 'acumatica_sales_orders.customer_acumatica_id')
            ->select([
                'acumatica_sales_orders.customer_acumatica_id as customer_id',
                DB::raw('MAX(COALESCE(cust.name, acumatica_sales_orders.customer_name)) as customer_name'),
                DB::raw('MAX(cust.customer_class) as customer_class'),
                DB::raw('MAX(cust.status) as customer_status'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('SUM(CASE WHEN acumatica_sales_orders.completed_at IS NULL THEN 1 ELSE 0 END) as active_orders'),
                DB::raw("SUM(CASE WHEN acumatica_sales_orders.completed_at IS NOT NULL OR LOWER(COALESCE(acumatica_sales_orders.status, '')) = 'completed' THEN 1 ELSE 0 END) as completed_orders"),
                DB::raw('COALESCE(SUM(acumatica_sales_orders.order_total), 0) as total_order_value'),
                DB::raw('MIN(acumatica_sales_orders.order_date) as first_order_date'),
                DB::raw('MAX(acumatica_sales_orders.order_date) as last_order_date'),
            ])
            ->groupBy('acumatica_sales_orders.customer_acumatica_id')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%' . $search . '%';
                $query->havingRaw('MAX(COALESCE(cust.name, acumatica_sales_orders.customer_name)) LIKE ?', [$like])
                      ->orHavingRaw('acumatica_sales_orders.customer_acumatica_id LIKE ?', [$like]);
            })
            ->orderByDesc('total_order_value')
            ->get();

        // Gather order IDs per customer for fill-rate and revenue-lost computation
        $customerOrderIds = (clone $base)
            ->selectRaw('customer_acumatica_id, GROUP_CONCAT(id) as order_ids')
            ->groupBy('customer_acumatica_id')
            ->pluck('order_ids', 'customer_acumatica_id');

        // Sorting (multi-directional)
        $sort = (string) $request->input('sort', 'total_order_value');
        $sortDir = strtolower((string) $request->input('sort_dir', 'desc')) === 'asc' ? SORT_ASC : SORT_DESC;

        $mapped = $rows->map(fn ($row) => [
            'customer_id' => $row->customer_id,
            'customer_name' => $row->customer_name,
            'customer_class' => $row->customer_class,
            'customer_status' => $row->customer_status,
            'order_count' => (int) $row->order_count,
            'active_orders' => (int) $row->active_orders,
            'completed_orders' => (int) $row->completed_orders,
            'total_order_value' => round((float) $row->total_order_value, 2),
            'total_revenue' => round((float) $row->total_order_value, 2),
            'fill_rate_pct' => $this->computeFillRate(
                collect(explode(',', (string) ($customerOrderIds[$row->customer_id] ?? '')))
                    ->filter()
                    ->values()
            ),
            'revenue_lost' => $this->computeRevenueLost(
                collect(explode(',', (string) ($customerOrderIds[$row->customer_id] ?? '')))
                    ->filter()
                    ->values()
            ),
            'first_order_date' => $row->first_order_date,
            'last_order_date' => $row->last_order_date,
            'orders_per_month' => $this->ordersPerMonth(
                (int) $row->order_count,
                $dateFrom,
                $dateTo,
                $row->first_order_date,
                $row->last_order_date,
            ),
        ]);

        // Apply sorting
        $sortMap = [
            'customer_name' => 'customer_name',
            'order_count' => 'order_count',
            'orders_per_month' => 'orders_per_month',
            'active_orders' => 'active_orders',
            'completed_orders' => 'completed_orders',
            'total_order_value' => 'total_order_value',
            'fill_rate_pct' => 'fill_rate_pct',
            'revenue_lost' => 'revenue_lost',
            'last_order_date' => 'last_order_date',
        ];
        $sortKey = $sortMap[$sort] ?? 'total_order_value';
        $sorted = $mapped->values()->all();
        usort($sorted, function ($a, $b) use ($sortKey, $sortDir) {
            $valA = $a[$sortKey] ?? null;
            $valB = $b[$sortKey] ?? null;
            if ($valA === $valB) {
                return 0;
            }

            return $sortDir === SORT_ASC ? ($valA <=> $valB) : ($valB <=> $valA);
        });
        $sortedCollection = collect($sorted);

        // Pagination
        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min(500, $perPage));
        $currentPage = max(1, (int) $request->input('page', 1));
        $total = $sortedCollection->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = min($currentPage, $lastPage);
        $offset = ($currentPage - 1) * $perPage;
        $paged = $sortedCollection->slice($offset, $perPage)->values();

        return response()->json([
            'rep_code' => $normalizedRepCode,
            'summary' => $summary,
            'customers' => $paged,
            'pagination' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => min($offset + $perPage, $total),
            ],
        ]);
    }

    private function resolveConsultant(Request $request, int $id): User|JsonResponse
    {
        $user = $request->user();
        $role = (string) ($user?->role ?? '');

        if (! in_array($role, self::FULL_ACCESS_ROLES, true)
            && ! in_array($role, self::OWN_PROFILE_ROLES, true)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $consultant = User::query()->where('users.id', $id)->first();

        if (! $consultant) {
            return response()->json(['message' => 'Sales consultant not found.'], 404);
        }

        $repCode = strtoupper(trim((string) ($consultant->rep_code ?? '')));
        $isListedConsultant = (bool) $consultant->is_consultant || $consultant->role === 'Sales Consultant';

        // Own-profile roles may only open their own card (or matching rep code).
        if (in_array($role, self::OWN_PROFILE_ROLES, true)
            && ! in_array($role, self::FULL_ACCESS_ROLES, true)) {
            $ownId = (int) ($user?->id ?? 0);
            $ownRep = strtoupper(trim((string) ($user?->rep_code ?? '')));
            $sameUser = $ownId === $id;
            $sameRep = $ownRep !== '' && $repCode !== '' && $ownRep === $repCode;

            if (! $sameUser && ! $sameRep) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }

            return $consultant;
        }

        // Full-access viewers may open directory-listed consultant records.
        if (! $isListedConsultant) {
            return response()->json(['message' => 'Sales consultant not found.'], 404);
        }

        // Empty rep_code is allowed for profile view; order metrics will be empty.

        return $consultant;
    }

    private function authorizeRepCodeAccess(?User $user, string $role, string $normalizedRepCode): ?JsonResponse
    {
        $isFullAccess = in_array($role, self::FULL_ACCESS_ROLES, true);
        $isOwnProfile = in_array($role, self::OWN_PROFILE_ROLES, true);

        if (! $isFullAccess && ! $isOwnProfile) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $isFullAccess) {
            $ownRepCode = strtoupper(trim((string) ($user?->rep_code ?? '')));
            if ($ownRepCode === '' || $ownRepCode !== $normalizedRepCode) {
                return response()->json(['message' => 'Forbidden.'], 403);
            }
        }

        return null;
    }

    /** @return \Illuminate\Database\Eloquent\Builder<AcumaticaSalesOrder> */
    private function ordersBaseQuery(string $repCode, Request $request)
    {
        $base = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->where('sales_consultant_rep_code', strtoupper(trim($repCode)))
            ->whereNotNull('customer_acumatica_id');

        if ($request->filled('date_from')) {
            $base->whereDate('order_date', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $base->whereDate('order_date', '<=', $request->input('date_to'));
        }

        return $base;
    }

    /** @param \Illuminate\Database\Eloquent\Builder<AcumaticaSalesOrder> $base */
    private function buildSummary($base): array
    {
        $completedExpression = "(completed_at IS NOT NULL OR LOWER(COALESCE(status, '')) = 'completed')";

        $summary = (clone $base)
            ->select([
                DB::raw('COUNT(DISTINCT customer_acumatica_id) as customer_count'),
                DB::raw('COALESCE(SUM(order_total), 0) as total_order_value'),
                DB::raw("SUM(CASE WHEN {$completedExpression} THEN 1 ELSE 0 END) as total_completed_orders"),
                DB::raw('SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) as active_orders'),
                DB::raw('COUNT(*) as total_orders'),
                DB::raw('MAX(order_date) as last_order_date'),
            ])
            ->first();

        return [
            'total_order_value' => round((float) ($summary?->total_order_value ?? 0), 2),
            'customer_count' => (int) ($summary?->customer_count ?? 0),
            'total_completed_orders' => (int) ($summary?->total_completed_orders ?? 0),
            'active_orders' => (int) ($summary?->active_orders ?? 0),
            'total_orders' => (int) ($summary?->total_orders ?? 0),
            'last_order_date' => $summary?->last_order_date,
        ];
    }

    /** @param array<string, mixed> $summary */
    private function formatConsultantProfile(User $consultant, array $summary): array
    {
        return [
            'id' => $consultant->id,
            'name' => $consultant->name,
            'email' => $consultant->email,
            'role' => $consultant->role,
            'rep_code' => $consultant->rep_code,
            'employee_number' => $consultant->employee_number,
            'is_active' => (bool) $consultant->is_active,
            'assigned_orders' => $summary['total_orders'],
            'active_orders' => $summary['active_orders'],
            'completed_orders' => $summary['total_completed_orders'],
            'assigned_revenue' => $summary['total_order_value'],
            'last_order_date' => $summary['last_order_date'],
        ];
    }

    private function ordersPerMonth(
        int $orderCount,
        ?string $dateFrom,
        ?string $dateTo,
        ?string $firstOrderDate,
        ?string $lastOrderDate,
    ): ?float {
        if ($orderCount === 0) {
            return null;
        }

        $rangeStart = $dateFrom ?? $firstOrderDate;
        $rangeEnd = $dateTo ?? $lastOrderDate;

        if (! $rangeStart || ! $rangeEnd) {
            return null;
        }

        $days = max(1, Carbon::parse($rangeStart)->diffInDays(Carbon::parse($rangeEnd)) + 1);
        $months = max($days / 30.0, 1 / 30.0);

        return round($orderCount / $months, 2);
    }

    private function ownProfileFallback(User $user, string $repCode): object
    {
        $metrics = AcumaticaSalesOrder::query()
            ->where('sales_consultant_rep_code', $repCode)
            ->select([
                DB::raw('COUNT(*) as assigned_orders'),
                DB::raw("SUM(CASE WHEN completed_at IS NULL THEN 1 ELSE 0 END) as active_orders"),
                DB::raw("SUM(CASE WHEN completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_orders"),
                DB::raw('COALESCE(SUM(order_total), 0) as assigned_revenue'),
                DB::raw('MAX(order_date) as last_order_date'),
            ])
            ->first();

        return (object) [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'rep_code' => $repCode,
            'employee_number' => $user->employee_number,
            'is_active' => $user->is_active,
            'assigned_orders' => (int) ($metrics?->assigned_orders ?? 0),
            'active_orders' => (int) ($metrics?->active_orders ?? 0),
            'completed_orders' => (int) ($metrics?->completed_orders ?? 0),
            'assigned_revenue' => (float) ($metrics?->assigned_revenue ?? 0),
            'last_order_date' => $metrics?->last_order_date,
        ];
    }
}
