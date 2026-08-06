<?php

namespace App\Services\Sales;

use App\Models\AcumaticaCustomer;
use App\Models\User;
use App\Services\Team\OrgScopeService;
use App\Services\Team\CustomerAttributionService;
use App\Services\Team\KpReportingHierarchyService;
use App\Support\DataScope;
use App\Support\SalesConsultantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Single portfolio resolver + KPIs for the Sales Consultant dashboard.
 * Personal sales book = rep_code + assignments (managers who also sell).
 * Team mode = OrgScopeService union of reports.
 */
class SalesPortfolioService
{
    public function __construct(
        private readonly OrgScopeService $orgScope,
        private readonly CustomerAttributionService $attribution,
        private readonly KpReportingHierarchyService $kpHierarchy,
    ) {}

    /**
     * @return list<string>
     */
    public function customerIdsFor(User $user, string $mode = 'auto'): array
    {
        $mode = $this->resolveMode($user, $mode);

        // My Portfolio is a KP product surface for Susan: retain her broad access
        // elsewhere, but make this dashboard's totals and lists KP-only.
        if ($this->kpHierarchy->requiresPrivilegedKpOverlay($user)) {
            return $this->kpHierarchy->visibleKpCustomerIds($user);
        }

        if ($mode === 'self') {
            return $this->personalBookCustomerIds($user);
        }

        // team / all — DataScope (null = org-wide)
        $ids = DataScope::scopedCustomerAcumaticaIds($user);
        if ($ids === null) {
            // Org-wide: every active (or unstatused) customer — not KP-only.
            return AcumaticaCustomer::query()
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw('LOWER(status) = ?', ['active']);
                })
                ->pluck('acumatica_id')
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->values()
                ->all();
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    public function resolveMode(User $user, string $mode): string
    {
        $mode = strtolower(trim($mode));
        if (in_array($mode, ['self', 'team', 'all'], true)) {
            if ($mode === 'all' && ! ($user->is_super_admin || $user->role === 'Administrator')) {
                return $this->defaultMode($user);
            }

            return $mode === 'all' ? 'team' : $mode;
        }

        return $this->defaultMode($user);
    }

    public function defaultMode(User $user): string
    {
        if ($user->is_super_admin || $user->role === 'Administrator') {
            return 'team'; // org-wide via DataScope null
        }

        if ($this->hasTeamVisibility($user)) {
            return 'team';
        }

        return 'self';
    }

    public function hasTeamVisibility(User $user): bool
    {
        if ($user->is_super_admin || $user->role === 'Administrator') {
            return true;
        }
        $levels = config('departments.org_levels_with_subtree_visibility', ['executive', 'c_suite', 'hod']);
        if (in_array((string) $user->org_level, $levels, true)) {
            return true;
        }
        if (in_array((string) $user->department_role, ['hod', 'executive', 'manager'], true)) {
            return true;
        }

        return count($this->orgScope->effectiveScopeUserIds($user)) > 1;
    }

    /**
     * @return list<string>
     */
    public function personalBookCustomerIds(User $user): array
    {
        return $this->attribution->directCustomerIds($user->id);
    }

    /**
     * Portfolio for a rep code: users with that rep (assignments ∪ SO book) unioned
     * with any SO that still carries the code (Acumatica single-rep field).
     * Used by consultant detail backorders (FR8 — not rep_code alone).
     *
     * @return list<string>
     */
    public function portfolioCustomerIdsForRepCode(string $repCode): array
    {
        $rep = strtoupper(trim($repCode));
        if ($rep === '') {
            return [];
        }

        $ids = [];
        foreach ($this->customerIdsFromRepCodeOnOrders($rep) as $id) {
            $ids[$id] = $id;
        }

        $users = User::query()
            ->where('is_active', true)
            ->where(function ($q) use ($rep) {
                $q->whereRaw('UPPER(TRIM(rep_code)) = ?', [$rep])
                    ->orWhereHas('acumaticaRepMappings', function ($m) use ($rep) {
                        $m->whereRaw('UPPER(TRIM(acumatica_rep_code)) = ?', [$rep]);
                    });
            })
            ->get();

        foreach ($users as $user) {
            foreach ($this->personalBookCustomerIds($user) as $id) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @return list<string> */
    private function customerIdsFromRepCodeOnOrders(string $rep): array
    {
        return DB::table('acumatica_sales_orders')
            ->where('sales_consultant_rep_code', $rep)
            ->whereNotNull('customer_acumatica_id')
            ->distinct()
            ->pluck('customer_acumatica_id')
            ->map(fn ($id) => trim((string) $id))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Full dashboard summary payload.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user, string $mode = 'auto', ?string $month = null, string $compare = 'mom'): array
    {
        $tz = 'Africa/Nairobi';
        $now = now($tz);
        $currentMonthStart = $now->copy()->startOfMonth();

        // Browsing a past month is allowed; future months clamp back to the current one.
        $monthStart = $currentMonthStart;
        if ($month !== null && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $requested = Carbon::createFromFormat('Y-m-d', $month.'-01', $tz)->startOfMonth();
            if ($requested->lte($currentMonthStart)) {
                $monthStart = $requested;
            }
        }

        $isCurrentMonth = $monthStart->equalTo($currentMonthStart);
        // A past month is fully elapsed; only the current month is "to date".
        $periodEnd = $isCurrentMonth ? $now : $monthStart->copy()->endOfMonth();

        $activeFrom = $monthStart->copy()->subMonths(3);

        $compare = $compare === 'yoy' ? 'yoy' : 'mom';
        $priorMonthStart = $compare === 'yoy' ? $monthStart->copy()->subYear() : $monthStart->copy()->subMonth();
        $priorMonthEnd = $priorMonthStart->copy()->endOfMonth();
        // Compare like-for-like: same number of days elapsed into the prior period.
        $priorPeriodTo = min($priorMonthEnd->toDateString(), $priorMonthStart->copy()->addDays($periodEnd->day - 1)->toDateString());

        $resolvedMode = $this->resolveMode($user, $mode);
        $customerIds = $this->customerIdsFor($user, $resolvedMode);
        $emptyIds = $customerIds === [] ? ['__none__'] : $customerIds;

        $lastOrders = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $emptyIds)
            ->where('order_date', '<=', $periodEnd->toDateString())
            ->groupBy('customer_acumatica_id')
            ->selectRaw('customer_acumatica_id, MAX(order_date) as last_order')
            ->pluck('last_order', 'customer_acumatica_id');

        $active = 0;
        $dormant = 0;
        foreach ($customerIds as $cid) {
            $last = $lastOrders[$cid] ?? null;
            if ($last && Carbon::parse($last)->gte($activeFrom)) {
                $active++;
            } else {
                $dormant++;
            }
        }

        $mtd = $this->orderAgg($emptyIds, $monthStart->toDateString(), $periodEnd->toDateString());
        $priorMtd = $this->orderAgg($emptyIds, $priorMonthStart->toDateString(), $priorPeriodTo);

        $target = $this->monthlyTarget($user, $monthStart, $resolvedMode);
        $timeGone = $this->workingDayPace($monthStart, $periodEnd);
        $expected = $target !== null ? round($target * $timeGone['pct'], 2) : null;
        $variance = ($target !== null && $expected !== null)
            ? round((float) $mtd['revenue'] - $expected, 2)
            : null;

        $backorders = $this->backorderAgg($emptyIds);

        $fillRate = $this->fillRateApprox($emptyIds, $monthStart->toDateString(), $periodEnd->toDateString());

        $topCustomers = $this->topCustomers($emptyIds, $monthStart->toDateString(), $periodEnd->toDateString(), $priorMonthStart->toDateString(), $priorPeriodTo);

        $predicted = $this->simplePrediction($emptyIds, $monthStart, $periodEnd);

        $declining = $this->decliningCustomers($emptyIds, $activeFrom, $monthStart, $periodEnd);

        $noBook = $customerIds === []
            && ! $user->is_super_admin
            && $user->role !== 'Administrator';

        return [
            'scope' => [
                'mode' => $resolvedMode,
                'rep_code' => $user->rep_code,
                'customer_count' => count($customerIds),
                'can_toggle_team' => $this->hasTeamVisibility($user) || $this->hasSalesBook($user),
                'has_sales_book' => $this->hasSalesBook($user),
                'empty_portfolio' => $noBook,
                'empty_hint' => $noBook
                    ? 'No customers on your rep code or assignments yet. Ask Sales Ops to set your rep code or assign customers.'
                    : null,
            ],
            'windows' => [
                'timezone' => $tz,
                'month' => $monthStart->format('Y-m'),
                'month_start' => $monthStart->toDateString(),
                'month_label' => $monthStart->format('F Y'),
                'is_current_month' => $isCurrentMonth,
                'active_from' => $activeFrom->toDateString(),
                'as_of' => $now->toIso8601String(),
                'dormant_tooltip' => "No sales order since {$activeFrom->toDateString()}. Cut-off is 3 calendar months before this month’s start ({$monthStart->toDateString()}).",
            ],
            'compare' => [
                'mode' => $compare,
                'label' => $compare === 'yoy' ? 'vs same month last year' : 'vs previous month',
                'prior_month_label' => $priorMonthStart->format('F Y'),
            ],
            'kpis' => [
                'customers_total' => count($customerIds),
                'customers_active' => $active,
                'customers_dormant' => $dormant,
                'revenue_mtd' => $mtd['revenue'],
                'revenue_prior_comparable' => $priorMtd['revenue'],
                'revenue_change_pct' => $this->pctChange($mtd['revenue'], $priorMtd['revenue']),
                'target' => $target,
                'time_gone_pct' => round($timeGone['pct'] * 100, 1),
                'working_days_elapsed' => $timeGone['elapsed'],
                'working_days_total' => $timeGone['total'],
                'expected_revenue_to_date' => $expected,
                'variance_to_pace' => $variance,
                'orders_count' => $mtd['orders'],
                'orders_open' => $mtd['open'],
                'orders_completed' => $mtd['completed'],
                'backorder_lines' => (int) ($backorders->line_count ?? 0),
                'backorder_revenue_at_risk' => round((float) ($backorders->revenue_at_risk ?? 0), 2),
                'fill_rate_pct' => $fillRate,
                'predicted_remaining_orders' => $predicted['orders'],
                'predicted_remaining_value' => $predicted['value'],
            ],
            'top_movers' => [
                'top_customers' => $topCustomers,
                'declining' => $declining,
                'concentration_top3_pct' => $this->concentration($topCustomers, $mtd['revenue']),
            ],
            'plain_language' => $this->plainLanguageSummary([
                'active' => $active,
                'dormant' => $dormant,
                'revenue' => $mtd['revenue'],
                'target' => $target,
                'variance' => $variance,
                'backorder_lines' => (int) ($backorders->line_count ?? 0),
                'month' => $monthStart->format('F'),
            ]),
        ];
    }

    /**
     * Orders for the period, grouped by customer for accordion UI.
     * Pagination is by customer group (not flat SO rows).
     *
     * @return array{data: list<array<string, mixed>>, grouped: list<array<string, mixed>>, total: int, current_page: int, last_page: int, order_count: int}
     */
    public function orders(User $user, string $mode, ?string $status, int $page, int $perPage): array
    {
        $tz = 'Africa/Nairobi';
        $monthStart = now($tz)->startOfMonth()->toDateString();
        $to = now($tz)->toDateString();
        $ids = $this->customerIdsFor($user, $mode);
        $emptyIds = $ids === [] ? ['__none__'] : $ids;

        $q = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $emptyIds)
            ->whereBetween('order_date', [$monthStart, $to]);

        if ($status !== null && $status !== '' && strtolower($status) !== 'all') {
            $q->where('status', $status);
        }

        $rows = $q->orderByDesc('order_date')
            ->orderByDesc('id')
            ->limit(1500)
            ->get([
                'id',
                'acumatica_order_nbr',
                'order_date',
                'customer_acumatica_id',
                'customer_name',
                'status',
                'order_total',
                'sales_consultant_rep_code',
            ]);

        $names = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $rows->pluck('customer_acumatica_id')->filter()->unique()->all() ?: ['__none__'])
            ->pluck('name', 'acumatica_id');

        $flat = [];
        $byCustomer = [];
        foreach ($rows as $r) {
            $cid = (string) ($r->customer_acumatica_id ?? '');
            $cname = trim((string) ($names[$cid] ?? $r->customer_name ?? $cid));
            if ($cname === '') {
                $cname = $cid !== '' ? $cid : 'Unknown';
            }
            $order = [
                'id' => $r->id,
                'order_nbr' => $r->acumatica_order_nbr,
                'order_date' => $r->order_date,
                'customer_id' => $cid,
                'customer_name' => $cname,
                'status' => $r->status,
                'order_total' => round((float) $r->order_total, 2),
                'rep_code' => $r->sales_consultant_rep_code,
            ];
            $flat[] = $order;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $cname,
                    'order_count' => 0,
                    'total_value' => 0.0,
                    'label' => '',
                    'orders' => [],
                ];
            }
            $byCustomer[$cid]['orders'][] = $order;
            $byCustomer[$cid]['order_count']++;
            $byCustomer[$cid]['total_value'] += $order['order_total'];
        }

        $groups = array_values($byCustomer);
        foreach ($groups as &$g) {
            $n = (int) $g['order_count'];
            $g['total_value'] = round((float) $g['total_value'], 2);
            $g['label'] = $g['customer_name'].' · '.$n.' '.($n === 1 ? 'order' : 'orders');
            usort($g['orders'], fn ($a, $b) => strcmp((string) $b['order_date'], (string) $a['order_date']));
        }
        unset($g);
        usort($groups, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);

        $groupTotal = count($groups);
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $pageGroups = array_slice($groups, $offset, $perPage);

        return [
            'data' => $flat,
            'grouped' => $pageGroups,
            'total' => $groupTotal,
            'order_count' => count($flat),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($groupTotal / $perPage)),
            'per_page' => $perPage,
            'hint' => 'Grouped by customer. Expand a row to see sales orders this month.',
        ];
    }

    /**
     * Active backorder lines grouped by customer for accordion UI.
     *
     * @return array{data: list<array<string, mixed>>, grouped: list<array<string, mixed>>, total: int, current_page: int, last_page: int, revenue_at_risk: float, line_count: int}
     */
    public function backorders(User $user, string $mode, int $page, int $perPage): array
    {
        $ids = $this->customerIdsFor($user, $mode);
        $emptyIds = $ids === [] ? ['__none__'] : $ids;

        $q = DB::table('acumatica_backorder_lines')
            ->where(function ($w) {
                $w->where('shortfall_kind', 'active_backorder')->orWhereNull('shortfall_kind');
            })
            ->whereIn('customer_acumatica_id', $emptyIds);

        $risk = (float) (clone $q)->sum('revenue_at_risk');
        $rows = $q->orderByDesc('revenue_at_risk')
            ->limit(2000)
            ->get([
                'id',
                'order_nbr',
                'inventory_id',
                'customer_acumatica_id',
                'customer_name',
                'open_qty',
                'unit_price',
                'revenue_at_risk',
                'fulfillment_status',
                'reason_code',
            ]);

        $names = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $rows->pluck('customer_acumatica_id')->filter()->unique()->all() ?: ['__none__'])
            ->pluck('name', 'acumatica_id');

        $flat = [];
        $byCustomer = [];
        foreach ($rows as $r) {
            $cid = (string) ($r->customer_acumatica_id ?? '');
            $cname = trim((string) ($names[$cid] ?? $r->customer_name ?? $cid));
            if ($cname === '') {
                $cname = $cid !== '' ? $cid : 'Unknown';
            }
            $line = [
                'id' => $r->id,
                'order_nbr' => $r->order_nbr,
                'inventory_id' => $r->inventory_id,
                'customer_id' => $cid,
                'customer_name' => $cname,
                'open_qty' => (float) $r->open_qty,
                'unit_price' => (float) $r->unit_price,
                'revenue_at_risk' => round((float) $r->revenue_at_risk, 2),
                'fulfillment_status' => $r->fulfillment_status,
                'reason_code' => $r->reason_code,
            ];
            $flat[] = $line;
            if (! isset($byCustomer[$cid])) {
                $byCustomer[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $cname,
                    'line_count' => 0,
                    'revenue_at_risk' => 0.0,
                    'label' => '',
                    'lines' => [],
                ];
            }
            $byCustomer[$cid]['lines'][] = $line;
            $byCustomer[$cid]['line_count']++;
            $byCustomer[$cid]['revenue_at_risk'] += $line['revenue_at_risk'];
        }

        $groups = array_values($byCustomer);
        foreach ($groups as &$g) {
            $n = (int) $g['line_count'];
            $g['revenue_at_risk'] = round((float) $g['revenue_at_risk'], 2);
            $g['label'] = $g['customer_name'].' · '.$n.' '.($n === 1 ? 'line' : 'lines');
            usort($g['lines'], fn ($a, $b) => $b['revenue_at_risk'] <=> $a['revenue_at_risk']);
        }
        unset($g);
        usort($groups, fn ($a, $b) => $b['revenue_at_risk'] <=> $a['revenue_at_risk']);

        $groupTotal = count($groups);
        $page = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $offset = ($page - 1) * $perPage;
        $pageGroups = array_slice($groups, $offset, $perPage);

        return [
            'data' => $flat,
            'grouped' => $pageGroups,
            'total' => $groupTotal,
            'line_count' => count($flat),
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($groupTotal / $perPage)),
            'per_page' => $perPage,
            'revenue_at_risk' => round($risk, 2),
            'hint' => 'Grouped by customer. Expand a row to see backorder lines (open qty × unit price = value at risk).',
        ];
    }

    /**
     * Items not ordered, grouped by customer (simplified: no order in 60+ days but ordered in prior 6 months).
     *
     * @return array{data: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function itemsNotOrdered(User $user, string $mode, int $daysOverdue = 60): array
    {
        $tz = 'Africa/Nairobi';
        $now = now($tz);
        $ids = $this->customerIdsFor($user, $mode);
        if ($ids === []) {
            return ['data' => [], 'summary' => ['customer_count' => 0, 'item_count' => 0]];
        }

        $historyFrom = $now->copy()->subMonths(6)->toDateString();
        $cutoff = $now->copy()->subDays($daysOverdue)->toDateString();

        $customerNames = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $ids)
            ->pluck('name', 'acumatica_id');

        // Last order date per customer × SKU in last 6 months
        $lines = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.order_type', 'SO')
            ->whereIn('o.customer_acumatica_id', $ids)
            ->where('o.order_date', '>=', $historyFrom)
            ->whereNotNull('l.inventory_id')
            ->selectRaw('o.customer_acumatica_id, MAX(o.customer_name) as customer_name, l.inventory_id, MAX(l.description) as description, MAX(o.order_date) as last_order, COUNT(*) as order_times, AVG(l.unit_price * COALESCE(l.order_qty, 0)) as avg_line_value')
            ->groupBy('o.customer_acumatica_id', 'l.inventory_id')
            ->havingRaw('MAX(o.order_date) < ?', [$cutoff])
            ->havingRaw('COUNT(*) >= 1')
            ->limit(800)
            ->get();

        $grouped = [];
        foreach ($lines as $row) {
            $cid = (string) $row->customer_acumatica_id;
            $name = trim((string) ($customerNames[$cid] ?? $row->customer_name ?? $cid));
            if ($name === '') {
                $name = $cid;
            }
            if (! isset($grouped[$cid])) {
                $grouped[$cid] = [
                    'customer_id' => $cid,
                    'customer_name' => $name,
                    'item_count' => 0,
                    'label' => '',
                    'items' => [],
                ];
            }
            $avg = $row->avg_line_value !== null ? round((float) $row->avg_line_value, 2) : null;
            $last = substr((string) $row->last_order, 0, 10);
            $daysGone = $last ? (int) Carbon::parse($last)->diffInDays($now) : null;
            $grouped[$cid]['item_count']++;
            $grouped[$cid]['items'][] = [
                'inventory_id' => $row->inventory_id,
                'description' => $row->description,
                'last_order_date' => $last,
                'days_since_order' => $daysGone,
                'order_times' => (int) $row->order_times,
                // Value only when we have average sales history
                'avg_value' => $avg !== null && $avg > 0 ? $avg : null,
            ];
        }

        $data = array_values($grouped);
        foreach ($data as &$group) {
            $n = (int) $group['item_count'];
            $group['label'] = $group['customer_name'].' · '.$n.' '.($n === 1 ? 'item' : 'items');
            usort($group['items'], fn ($a, $b) => ($b['days_since_order'] ?? 0) <=> ($a['days_since_order'] ?? 0));
        }
        unset($group);
        usort($data, fn ($a, $b) => $b['item_count'] <=> $a['item_count']);

        return [
            'data' => $data,
            'grouped' => $data,
            'summary' => [
                'customer_count' => count($data),
                'item_count' => array_sum(array_column($data, 'item_count')),
                'days_overdue' => $daysOverdue,
            ],
            'hint' => 'Grouped by customer. Expand a row to see SKUs not ordered in the last '.$daysOverdue.' days (bought in the prior 6 months). Value only when average sales exist.',
        ];
    }

    public function hasSalesBook(User $user): bool
    {
        if ((bool) $user->is_consultant || (string) $user->role === SalesConsultantScope::ROLE) {
            return true;
        }
        if (strtoupper(trim((string) ($user->rep_code ?? ''))) !== '') {
            return true;
        }

        return $user->customerAssignments()->exists();
    }

    /** @param  list<string>  $ids */
    private function orderAgg(array $ids, string $from, string $to): array
    {
        $row = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $ids)
            ->whereBetween('order_date', [$from, $to])
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw("LOWER(status) NOT IN ('cancelled','canceled','rejected')");
            })
            ->selectRaw("
                COUNT(*) as orders,
                COALESCE(SUM(order_total), 0) as revenue,
                SUM(CASE WHEN LOWER(COALESCE(status,'')) IN ('completed','shipping') THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN LOWER(COALESCE(status,'')) NOT IN ('completed','cancelled','canceled','rejected') THEN 1 ELSE 0 END) as open_cnt
            ")
            ->first();

        return [
            'orders' => (int) ($row->orders ?? 0),
            'revenue' => round((float) ($row->revenue ?? 0), 2),
            'completed' => (int) ($row->completed ?? 0),
            'open' => (int) ($row->open_cnt ?? 0),
        ];
    }

    private function monthlyTarget(User $user, Carbon $monthStart, string $mode): ?float
    {
        // Commission tables may not exist on every deploy — never fail the portfolio summary.
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('commission_targets')) {
                return null;
            }

            $userIds = [$user->id];
            if ($mode === 'team' && $this->hasTeamVisibility($user)) {
                $userIds = $this->orgScope->effectiveScopeUserIds($user);
            }

            $sum = DB::table('commission_targets')
                ->whereIn('user_id', $userIds)
                ->whereDate('period_month', $monthStart->toDateString())
                ->sum('target_amount');

            return $sum > 0 ? round((float) $sum, 2) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $ids
     * @return object{line_count: int|string|null, revenue_at_risk: float|string|null}
     */
    private function backorderAgg(array $ids): object
    {
        try {
            $q = DB::table('acumatica_backorder_lines')
                ->whereIn('customer_acumatica_id', $ids);

            if (\Illuminate\Support\Facades\Schema::hasColumn('acumatica_backorder_lines', 'shortfall_kind')) {
                $q->where(function ($w) {
                    $w->where('shortfall_kind', 'active_backorder')->orWhereNull('shortfall_kind');
                });
            }

            // Alias must not be `lines` — LINES is reserved in MariaDB/MySQL (syntax error 1064).
            return $q->selectRaw('COUNT(*) as line_count, COALESCE(SUM(revenue_at_risk), 0) as revenue_at_risk')->first()
                ?? (object) ['line_count' => 0, 'revenue_at_risk' => 0];
        } catch (\Throwable) {
            return (object) ['line_count' => 0, 'revenue_at_risk' => 0];
        }
    }

    /** @return array{pct: float, elapsed: int, total: int} */
    private function workingDayPace(Carbon $monthStart, Carbon $now): array
    {
        $total = 0;
        $elapsed = 0;
        $cursor = $monthStart->copy();
        $end = $monthStart->copy()->endOfMonth();
        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $total++;
                if ($cursor->lte($now->copy()->startOfDay())) {
                    $elapsed++;
                }
            }
            $cursor->addDay();
        }
        $total = max(1, $total);
        $elapsed = min($elapsed, $total);

        return ['pct' => $elapsed / $total, 'elapsed' => $elapsed, 'total' => $total];
    }

    /** @param  list<string>  $ids */
    private function fillRateApprox(array $ids, string $from, string $to): ?float
    {
        // Approx from SO lines: shipped / ordered when columns exist
        try {
            $row = DB::table('acumatica_sales_order_lines as l')
                ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
                ->where('o.order_type', 'SO')
                ->whereIn('o.customer_acumatica_id', $ids)
                ->whereBetween('o.order_date', [$from, $to])
                ->selectRaw('COALESCE(SUM(l.order_qty),0) as ordered, COALESCE(SUM(l.shipped_qty),0) as shipped')
                ->first();
            $ordered = (float) ($row->ordered ?? 0);
            if ($ordered <= 0) {
                return null;
            }

            return round(((float) ($row->shipped ?? 0) / $ordered) * 100, 1);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function topCustomers(array $ids, string $from, string $to, string $priorFrom, string $priorTo): array
    {
        $notCancelled = function ($q) {
            $q->whereNull('status')
                ->orWhereRaw("LOWER(status) NOT IN ('cancelled','canceled','rejected')");
        };

        $current = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $ids)
            ->whereBetween('order_date', [$from, $to])
            ->where($notCancelled)
            ->selectRaw('customer_acumatica_id, MAX(customer_name) as customer_name, COALESCE(SUM(order_total),0) as revenue, COUNT(*) as orders')
            ->groupBy('customer_acumatica_id')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        $priorMap = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $current->pluck('customer_acumatica_id')->all() ?: ['__none__'])
            ->whereBetween('order_date', [$priorFrom, $priorTo])
            ->where($notCancelled)
            ->selectRaw('customer_acumatica_id, COALESCE(SUM(order_total),0) as revenue')
            ->groupBy('customer_acumatica_id')
            ->pluck('revenue', 'customer_acumatica_id');

        $names = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $current->pluck('customer_acumatica_id')->all() ?: ['__none__'])
            ->pluck('name', 'acumatica_id');

        return $current->map(function ($r) use ($priorMap, $names) {
            $prior = (float) ($priorMap[$r->customer_acumatica_id] ?? 0);
            $rev = (float) $r->revenue;
            $cname = trim((string) ($names[$r->customer_acumatica_id] ?? $r->customer_name ?? ''));
            if ($cname === '') {
                $cname = (string) $r->customer_acumatica_id;
            }

            return [
                'customer_id' => $r->customer_acumatica_id,
                'customer_name' => $cname,
                'revenue' => round($rev, 2),
                'orders' => (int) $r->orders,
                'change_pct' => $this->pctChange($rev, $prior),
                'trend' => $prior <= 0 ? 'flat' : ($rev >= $prior ? 'up' : 'down'),
            ];
        })->all();
    }

    /**
     * @param  list<string>  $ids
     * @return list<array<string, mixed>>
     */
    private function decliningCustomers(array $ids, Carbon $activeFrom, Carbon $monthStart, ?Carbon $asOf = null): array
    {
        // Active window but no order in last 45 days (relative to the viewed period) → early warning
        $warnBefore = ($asOf ?? now('Africa/Nairobi'))->copy()->subDays(45)->toDateString();
        $rows = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $ids)
            ->groupBy('customer_acumatica_id')
            ->selectRaw('customer_acumatica_id, MAX(customer_name) as customer_name, MAX(order_date) as last_order')
            ->havingRaw('MAX(order_date) >= ?', [$activeFrom->toDateString()])
            ->havingRaw('MAX(order_date) < ?', [$warnBefore])
            ->orderBy('last_order')
            ->limit(8)
            ->get();

        $names = AcumaticaCustomer::query()
            ->whereIn('acumatica_id', $rows->pluck('customer_acumatica_id')->all() ?: ['__none__'])
            ->pluck('name', 'acumatica_id');

        return $rows->map(function ($r) use ($names) {
            $cname = trim((string) ($names[$r->customer_acumatica_id] ?? $r->customer_name ?? ''));
            if ($cname === '') {
                $cname = (string) $r->customer_acumatica_id;
            }

            return [
                'customer_id' => $r->customer_acumatica_id,
                'customer_name' => $cname,
                'last_order_date' => substr((string) $r->last_order, 0, 10),
                'label' => 'At risk — no order in 45+ days',
            ];
        })->all();
    }

    /**
     * @param  list<string>  $ids
     * @return array{orders: int, value: float}
     */
    private function simplePrediction(array $ids, Carbon $monthStart, Carbon $now): array
    {
        $from = $monthStart->copy()->subMonths(3)->toDateString();
        $to = $now->toDateString();
        $hist = $this->orderAgg($ids, $from, $to);
        $days = max(1, $monthStart->copy()->subMonths(3)->diffInDays($now));
        $dailyOrders = $hist['orders'] / $days;
        $dailyRev = $hist['revenue'] / $days;
        $daysLeft = max(0, (int) $now->diffInDays($now->copy()->endOfMonth()));
        $mtd = $this->orderAgg($ids, $monthStart->toDateString(), $to);
        $expectedMonthOrders = $dailyOrders * max(1, (int) $monthStart->diffInDays($now->copy()->endOfMonth()) + 1);
        $remainingOrders = max(0, (int) round($expectedMonthOrders - $mtd['orders']));

        return [
            'orders' => $remainingOrders,
            'value' => round($dailyRev * $daysLeft, 2),
        ];
    }

    /** @param  list<array{revenue: float}>  $top */
    private function concentration(array $top, float $total): ?float
    {
        if ($total <= 0 || $top === []) {
            return null;
        }
        $sum = array_sum(array_map(fn ($r) => (float) ($r['revenue'] ?? 0), array_slice($top, 0, 3)));

        return round(($sum / $total) * 100, 1);
    }

    private function pctChange(float $current, float $prior): ?float
    {
        if ($prior <= 0) {
            return null;
        }

        return round((($current - $prior) / $prior) * 100, 1);
    }

    /** @param  array<string, mixed>  $s */
    private function plainLanguageSummary(array $s): string
    {
        $parts = [];
        $parts[] = "You have {$s['active']} customers actively buying and {$s['dormant']} dormant (quiet 3+ months).";
        $parts[] = "{$s['month']} revenue so far: KES ".number_format((float) $s['revenue'], 0);
        if ($s['target'] !== null) {
            if ($s['variance'] !== null && $s['variance'] >= 0) {
                $parts[] = 'You are ahead of pace vs target by KES '.number_format((float) $s['variance'], 0).'.';
            } elseif ($s['variance'] !== null) {
                $parts[] = 'You are behind pace vs target by KES '.number_format(abs((float) $s['variance']), 0).'.';
            }
        } else {
            $parts[] = 'No monthly target set yet.';
        }
        if ((int) $s['backorder_lines'] > 0) {
            $parts[] = "{$s['backorder_lines']} backorder lines need attention (value at risk — not lost sales).";
        }

        return implode(' ', $parts);
    }
}
