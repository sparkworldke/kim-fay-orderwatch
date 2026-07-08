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

        $rows = User::query()
            ->where('users.role', 'Sales Consultant')
            ->whereNotNull('users.rep_code')
            ->when($scope === 'own', fn ($query) => $query->where('users.rep_code', $repCode))
            ->leftJoin('acumatica_sales_orders as so', 'users.rep_code', '=', 'so.sales_consultant_rep_code')
            ->select([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'users.rep_code',
                'users.is_active',
                DB::raw('COUNT(so.id) as assigned_orders'),
                DB::raw("SUM(CASE WHEN so.id IS NOT NULL AND so.completed_at IS NULL THEN 1 ELSE 0 END) as active_orders"),
                DB::raw("SUM(CASE WHEN so.id IS NOT NULL AND so.completed_at IS NOT NULL THEN 1 ELSE 0 END) as completed_orders"),
                DB::raw('COALESCE(SUM(so.order_total), 0) as assigned_revenue'),
                DB::raw('MAX(so.order_date) as last_order_date'),
            ])
            ->groupBy('users.id', 'users.name', 'users.email', 'users.role', 'users.rep_code', 'users.is_active')
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

        $summary = $this->buildSummary(
            $this->ordersBaseQuery($consultant->rep_code, $request),
        );

        return response()->json([
            'consultant' => $this->formatConsultantProfile($consultant, $summary),
            'summary' => $summary,
        ]);
    }

    public function customersById(Request $request, int $id): JsonResponse
    {
        $consultant = $this->resolveConsultant($request, $id);
        if ($consultant instanceof JsonResponse) {
            return $consultant;
        }

        return $this->customersResponse($request, $consultant->rep_code);
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
            ->orderByDesc('total_order_value')
            ->get();

        return response()->json([
            'rep_code' => $normalizedRepCode,
            'summary' => $summary,
            'customers' => $rows->map(fn ($row) => [
                'customer_id' => $row->customer_id,
                'customer_name' => $row->customer_name,
                'customer_class' => $row->customer_class,
                'customer_status' => $row->customer_status,
                'order_count' => (int) $row->order_count,
                'active_orders' => (int) $row->active_orders,
                'completed_orders' => (int) $row->completed_orders,
                'total_order_value' => round((float) $row->total_order_value, 2),
                'total_revenue' => round((float) $row->total_order_value, 2),
                'first_order_date' => $row->first_order_date,
                'last_order_date' => $row->last_order_date,
                'orders_per_month' => $this->ordersPerMonth(
                    (int) $row->order_count,
                    $dateFrom,
                    $dateTo,
                    $row->first_order_date,
                    $row->last_order_date,
                ),
            ])->values(),
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

        $consultant = User::query()
            ->whereNotNull('users.rep_code')
            ->where('users.id', $id)
            ->where(function ($query) use ($user, $role, $id) {
                $query->where('users.role', 'Sales Consultant');

                if (in_array($role, self::OWN_PROFILE_ROLES, true) && (int) ($user?->id ?? 0) === $id) {
                    $query->orWhereIn('users.role', self::OWN_PROFILE_ROLES);
                }
            })
            ->first();

        if (! $consultant) {
            return response()->json(['message' => 'Sales consultant not found.'], 404);
        }

        if ($denied = $this->authorizeRepCodeAccess($user, $role, (string) $consultant->rep_code)) {
            return $denied;
        }

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
            'is_active' => $user->is_active,
            'assigned_orders' => (int) ($metrics?->assigned_orders ?? 0),
            'active_orders' => (int) ($metrics?->active_orders ?? 0),
            'completed_orders' => (int) ($metrics?->completed_orders ?? 0),
            'assigned_revenue' => (float) ($metrics?->assigned_revenue ?? 0),
            'last_order_date' => $metrics?->last_order_date,
        ];
    }
}
