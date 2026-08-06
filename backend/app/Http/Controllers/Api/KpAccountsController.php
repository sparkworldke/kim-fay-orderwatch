<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\CustomerContact;
use App\Models\User;
use App\Services\Sales\SalesPortfolioService;
use App\Services\Team\OrgScopeService;
use App\Support\DataScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM Accounts list — scoped to the actor's portfolio (assignments + rep SO book).
 *
 * Sales Consultants only see customers attached via assignment or sales-order rep code.
 * Admins / org-wide roles see all matching customers.
 *
 * Query params:
 *  - customer_class: exact class (e.g. KPCCLNRS)
 *  - exclude_class: class to omit when listing a class family
 *  - all_classes: 1 = no KP% restriction (full book / all classes). Default without this
 *    remains KP% for legacy KP CRM surfaces (e.g. Contract Cleaners still passes exact class).
 */
class KpAccountsController extends Controller
{
    public function __construct(
        private readonly SalesPortfolioService $portfolio,
        private readonly OrgScopeService $orgScope,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $now = CarbonImmutable::now('Africa/Nairobi');
        $month = trim((string) $request->input('month', $now->format('Y-m')));
        abort_unless((bool) preg_match('/^\d{4}-\d{2}$/', $month), 422, 'Month must use YYYY-MM.');
        $start = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', 'Africa/Nairobi')->startOfDay();
        $end = $start->endOfMonth()->min($now);
        $priorStart = $start->subMonth();
        $priorEnd = $priorStart->addDays($end->day - 1)->min($priorStart->endOfMonth());
        $activeCutoff = $now->startOfMonth()->subMonths(3);

        $portfolio = DataScope::applyCustomerScope(
            AcumaticaCustomer::query()
                ->where('customer_class', 'like', 'KP%')
                ->whereRaw('UPPER(customer_class) <> ?', ['KPCCLNRS']),
            $user,
        );
        $portfolioIds = (clone $portfolio)->select('acumatica_id');
        $customerCount = (clone $portfolio)->where(fn ($q) => $q->whereNull('status')->orWhereRaw('LOWER(status) = ?', ['active']))->count();
        $orders = AcumaticaSalesOrder::query()
            ->whereIn('customer_acumatica_id', $portfolioIds)
            ->where('order_type', 'SO')
            ->whereNotIn(DB::raw('LOWER(COALESCE(status, \'\'))'), ['cancelled', 'canceled', 'rejected']);
        $lastOrders = (clone $orders)->select('customer_acumatica_id', DB::raw('MAX(order_date) as last_order_date'))->groupBy('customer_acumatica_id');
        $activeCount = (clone $portfolio)->whereIn('acumatica_id', (clone $lastOrders)
            ->havingRaw('MAX(order_date) >= ?', [$activeCutoff->toDateString()])
            ->select('customer_acumatica_id'))->count();

        $periodOrders = (clone $orders)->whereBetween('order_date', [$start->toDateString(), $end->toDateString()]);
        $revenue = (float) (clone $periodOrders)->sum('order_total');
        $priorRevenue = (float) (clone $orders)->whereBetween('order_date', [$priorStart->toDateString(), $priorEnd->toDateString()])->sum('order_total');
        $backorders = \App\Models\AcumaticaBackorderLine::query()
            ->whereIn('customer_acumatica_id', $portfolioIds)
            ->where(fn ($q) => $q->where('shortfall_kind', 'active_backorder')->orWhereNull('shortfall_kind'));
        $fill = \App\Models\AcumaticaFillRateSnapshot::query()
            ->whereIn('customer_acumatica_id', $portfolioIds)
            ->whereBetween('computed_at', [$start, $end->endOfDay()]);
        $orderedQty = (float) (clone $fill)->sum('total_ordered_qty');
        $shippedQty = (float) (clone $fill)->sum('total_shipped_qty');

        $target = DB::table('commission_targets')->where('user_id', $user->id)
            ->whereDate('period_month', $start->toDateString())->value('target_amount');
        $target = $target !== null ? (float) $target : null;
        $elapsedDays = $start->isSameMonth($now) ? $now->day : ($start->isBefore($now) ? $start->daysInMonth : 0);
        $timeGone = round($elapsedDays / $start->daysInMonth * 100, 1);
        $expected = $target !== null ? $target * $timeGone / 100 : null;

        $topCustomers = (clone $periodOrders)
            ->select('customer_acumatica_id', DB::raw('MAX(customer_name) as customer_name'), DB::raw('SUM(order_total) as revenue'), DB::raw('COUNT(*) as orders'))
            ->groupBy('customer_acumatica_id')->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($row) => [
                'customer_id' => $row->customer_acumatica_id,
                'customer_name' => $row->customer_name ?: $row->customer_acumatica_id,
                'revenue' => round((float) $row->revenue, 2),
                'orders' => (int) $row->orders,
                'share_pct' => $revenue > 0 ? round((float) $row->revenue / $revenue * 100, 1) : 0,
            ])->values();

        $declining = (clone $portfolio)->whereIn('acumatica_id', (clone $lastOrders)
            ->havingRaw('MAX(order_date) < ?', [$now->subDays(30)->toDateString()])
            ->havingRaw('MAX(order_date) >= ?', [$activeCutoff->toDateString()])
            ->select('customer_acumatica_id'))->count();

        return response()->json([
            'period' => ['month' => $start->format('Y-m'), 'label' => $start->format('F Y'), 'from' => $start->toDateString(), 'to' => $end->toDateString(), 'active_cutoff' => $activeCutoff->toDateString(), 'timezone' => 'Africa/Nairobi'],
            'scope' => $this->scopeLabel($user),
            'customers' => ['total' => $customerCount, 'active' => $activeCount, 'dormant' => max(0, $customerCount - $activeCount), 'declining' => $declining],
            'sales' => ['revenue' => round($revenue, 2), 'prior_revenue' => round($priorRevenue, 2), 'change_pct' => $priorRevenue > 0 ? round(($revenue - $priorRevenue) / $priorRevenue * 100, 1) : null, 'orders' => (clone $periodOrders)->count()],
            'target' => ['amount' => $target, 'achieved_pct' => $target && $target > 0 ? round($revenue / $target * 100, 1) : null, 'time_gone_pct' => $timeGone, 'expected_to_date' => $expected !== null ? round($expected, 2) : null, 'variance' => $expected !== null ? round($revenue - $expected, 2) : null],
            'backorders' => ['lines' => (clone $backorders)->count(), 'value_at_risk' => round((float) (clone $backorders)->sum('revenue_at_risk'), 2)],
            'fill_rate' => ['percentage' => $orderedQty > 0 ? round($shippedQty / $orderedQty * 100, 1) : null, 'sample_orders' => (clone $fill)->count(), 'last_sync' => (clone $fill)->max('computed_at')],
            'top_customers' => $topCustomers,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanView($user);

        $q = trim((string) $request->input('q', ''));
        $status = trim((string) $request->input('status', ''));
        $customerClass = strtoupper(trim((string) $request->input('customer_class', '')));
        $excludeClass = strtoupper(trim((string) $request->input('exclude_class', '')));
        $allClasses = $request->boolean('all_classes')
            || in_array($customerClass, ['ALL', '*', 'ANY'], true);
        if (in_array($customerClass, ['ALL', '*', 'ANY'], true)) {
            $customerClass = '';
        }
        $perPage = min(max($request->integer('per_page', 25), 5), 100);
        $mode = strtolower(trim((string) $request->input('mode', 'auto')));
        $repCode = strtoupper(trim((string) $request->input('rep_code', '')));

        $base = AcumaticaCustomer::query()->orderBy('name');

        if ($customerClass !== '') {
            // Exact class (e.g. KP Contract Cleaners = KPCCLNRS)
            $base->whereRaw('UPPER(customer_class) = ?', [$customerClass]);
        } elseif (! $allClasses) {
            // Legacy default for KP CRM: KP* classes only
            $base->where('customer_class', 'like', 'KP%');
            if ($excludeClass !== '') {
                $base->whereRaw('UPPER(customer_class) <> ?', [$excludeClass]);
            }
        } elseif ($excludeClass !== '') {
            $base->whereRaw('UPPER(COALESCE(customer_class, \'\')) <> ?', [$excludeClass]);
        }

        if ($repCode !== '') {
            // "My team" accordion expand — one rep's book, authorized against the caller's scope.
            $repUser = $this->resolveTeamMemberByRepCode($user, $repCode);
            $ids = $repUser ? $this->portfolio->personalBookCustomerIds($repUser) : [];
            $query = $base->whereIn('acumatica_id', $ids === [] ? ['__none__'] : $ids);
        } elseif ($mode === 'self') {
            // "My book" — strictly the caller's own personal book, even for managers who would
            // otherwise see their whole subtree via DataScope.
            $ids = $this->portfolio->personalBookCustomerIds($user);
            $query = $base->whereIn('acumatica_id', $ids === [] ? ['__none__'] : $ids);
        } else {
            $query = DataScope::applyCustomerScope($base, $user);
        }

        if ($q !== '') {
            $query->where(function ($scoped) use ($q) {
                $scoped->where('name', 'like', "%{$q}%")
                    ->orWhere('acumatica_id', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            });
        }

        if ($status !== '' && strtolower($status) !== 'all') {
            $query->where('status', $status);
        }

        $paginator = $query->paginate($perPage);

        $ids = collect($paginator->items())->pluck('acumatica_id')->filter()->values()->all();

        $contactCounts = $ids === []
            ? collect()
            : CustomerContact::query()
                ->whereIn('customer_acumatica_id', $ids)
                ->where('is_active', true)
                ->select('customer_acumatica_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('customer_acumatica_id')
                ->pluck('cnt', 'customer_acumatica_id');

        $lastOrders = $ids === []
            ? collect()
            : AcumaticaSalesOrder::query()
                ->whereIn('customer_acumatica_id', $ids)
                ->select('customer_acumatica_id', DB::raw('MAX(order_date) as last_order_date'))
                ->groupBy('customer_acumatica_id')
                ->pluck('last_order_date', 'customer_acumatica_id');

        $data = collect($paginator->items())->map(function (AcumaticaCustomer $c) use ($contactCounts, $lastOrders) {
            return [
                'acumatica_id' => $c->acumatica_id,
                'name' => $c->name,
                'customer_class' => $c->customer_class,
                'status' => $c->status,
                'email' => $c->email,
                'phone' => $c->phone,
                'payment_terms' => $c->payment_terms,
                'parent_acumatica_id' => $c->parent_acumatica_id,
                'is_main_account' => (bool) $c->is_main_account,
                'contact_count' => (int) ($contactCounts[$c->acumatica_id] ?? 0),
                'last_order_date' => isset($lastOrders[$c->acumatica_id])
                    ? (string) $lastOrders[$c->acumatica_id]
                    : null,
            ];
        })->values();

        return response()->json([
            'data' => $data,
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'scope' => ($mode === 'self' || $repCode !== '') ? 'my_portfolio' : $this->scopeLabel($user),
            'customer_class' => $customerClass !== '' ? $customerClass : ($allClasses ? 'ALL' : 'KP%'),
            'exclude_class' => $excludeClass !== '' ? $excludeClass : null,
            'all_classes' => $allClasses,
        ]);
    }

    /**
     * Grouped-by-rep summary for the "My team" accordion — one row per team member with
     * their own book: account counts (active/dormant/on-hold), revenue MTD, and target pace.
     * Mirrors SalesPortfolioService::summary()'s per-user math, looped across the caller's team.
     */
    public function byRep(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureCanView($user);

        if (! $this->orgScope->hasOrgWideAccess($user) && ! $this->portfolio->hasTeamVisibility($user)) {
            abort(403, 'Team view is only available to managers.');
        }

        $customerClass = strtoupper(trim((string) $request->input('customer_class', '')));
        $excludeClass = strtoupper(trim((string) $request->input('exclude_class', '')));
        $allClasses = $request->boolean('all_classes')
            || in_array($customerClass, ['ALL', '*', 'ANY'], true);
        if (in_array($customerClass, ['ALL', '*', 'ANY'], true)) {
            $customerClass = '';
        }

        $tz = 'Africa/Nairobi';
        $now = CarbonImmutable::now($tz);
        $month = trim((string) $request->input('month', $now->format('Y-m')));
        abort_unless((bool) preg_match('/^\d{4}-\d{2}$/', $month), 422, 'Month must use YYYY-MM.');
        $monthStart = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01', $tz)->startOfMonth();
        $periodEnd = $monthStart->isSameMonth($now) ? $now : $monthStart->endOfMonth();
        $activeFrom = $monthStart->subMonths(3);
        $timeGonePct = $this->workingDayPacePct($monthStart, $periodEnd);

        $teamUserIds = app(\App\Services\Team\OrgTreeService::class)->descendantIds($user->id);
        $teamUsers = User::query()
            ->whereIn('id', $teamUserIds)
            ->where('is_active', true)
            ->when(
                ! $user->is_super_admin && ! in_array(strtolower((string) $user->org_level), ['executive', 'c_suite'], true) && $user->department_id,
                fn ($query) => $query->where('department_id', $user->department_id),
            )
            ->orderBy('name')
            ->get();
        // Commission tables may not exist on every deploy — never fail the team view for it.
        $targetsAvailable = \Illuminate\Support\Facades\Schema::hasTable('commission_targets');

        $groups = [];
        foreach ($teamUsers as $teamUser) {
            $bookIds = $this->portfolio->personalBookCustomerIds($teamUser);
            if ($bookIds === []) {
                continue;
            }

            $classed = AcumaticaCustomer::query()->whereIn('acumatica_id', $bookIds);
            if ($customerClass !== '') {
                $classed->whereRaw('UPPER(customer_class) = ?', [$customerClass]);
            } elseif (! $allClasses) {
                $classed->where('customer_class', 'like', 'KP%');
                if ($excludeClass !== '') {
                    $classed->whereRaw('UPPER(customer_class) <> ?', [$excludeClass]);
                }
            } elseif ($excludeClass !== '') {
                $classed->whereRaw('UPPER(COALESCE(customer_class, \'\')) <> ?', [$excludeClass]);
            }
            $statusById = $classed->pluck('status', 'acumatica_id');
            $ids = $statusById->keys()->all();
            if ($ids === []) {
                continue;
            }

            $lastOrders = DB::table('acumatica_sales_orders')
                ->where('order_type', 'SO')
                ->whereIn('customer_acumatica_id', $ids)
                ->groupBy('customer_acumatica_id')
                ->selectRaw('customer_acumatica_id, MAX(order_date) as last_order')
                ->pluck('last_order', 'customer_acumatica_id');

            $active = 0;
            $dormant = 0;
            $onHold = 0;
            foreach ($ids as $cid) {
                $last = $lastOrders[$cid] ?? null;
                if ($last && Carbon::parse($last)->gte($activeFrom)) {
                    $active++;
                } else {
                    $dormant++;
                }
                if (str_contains(strtolower((string) ($statusById[$cid] ?? '')), 'hold')) {
                    $onHold++;
                }
            }

            $revenue = (float) DB::table('acumatica_sales_orders')
                ->where('order_type', 'SO')
                ->whereIn('customer_acumatica_id', $ids)
                ->whereBetween('order_date', [$monthStart->toDateString(), $periodEnd->toDateString()])
                ->where(fn ($qw) => $qw->whereNull('status')->orWhereRaw("LOWER(status) NOT IN ('cancelled','canceled','rejected')"))
                ->sum('order_total');

            $target = $targetsAvailable
                ? DB::table('commission_targets')
                    ->where('user_id', $teamUser->id)
                    ->whereDate('period_month', $monthStart->toDateString())
                    ->value('target_amount')
                : null;
            $target = $target !== null ? round((float) $target, 2) : null;
            $expected = $target !== null ? round($target * $timeGonePct / 100, 2) : null;

            $repCode = $teamUser->rep_code ?: $teamUser->acumaticaRepMappings()->where('is_primary', true)->value('acumatica_rep_code');

            $backorder = DB::table('acumatica_backorder_lines')
                ->whereIn('customer_acumatica_id', $ids)
                ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(revenue_at_risk), 0) as value_at_risk')
                ->first();
            $dormantShare = count($ids) > 0 ? round($dormant / count($ids) * 100, 1) : 0.0;
            $achieved = $target !== null && $target > 0 ? round($revenue / $target * 100, 1) : null;
            $paceStatus = $target === null || $target <= 0
                ? 'no_target'
                : ($achieved >= $timeGonePct + 10 ? 'ahead' : ($achieved >= $timeGonePct - 5 ? 'on_track' : ($achieved >= $timeGonePct - 20 ? 'at_risk' : 'off_track')));
            $attentionReasons = [];
            if ($paceStatus === 'no_target') $attentionReasons[] = 'Monthly target missing';
            if (in_array($paceStatus, ['at_risk', 'off_track'], true)) $attentionReasons[] = 'Sales pace is behind target';
            if ($dormantShare >= 40) $attentionReasons[] = "{$dormantShare}% of accounts are dormant";
            if ((float) ($backorder->value_at_risk ?? 0) >= 100000) $attentionReasons[] = 'High backorder value at risk';

            $atRiskAccounts = collect($ids)->map(fn ($cid) => [
                'customer_id' => $cid,
                'last_order_date' => $lastOrders[$cid] ?? null,
            ])->sortBy('last_order_date')->take(3)->values()->all();

            $groups[] = [
                'user_id' => $teamUser->id,
                'rep_code' => $repCode,
                'rep_name' => $teamUser->name,
                'employee_number' => $teamUser->employee_number,
                'account_count' => count($ids),
                'active_count' => $active,
                'dormant_count' => $dormant,
                'on_hold_count' => $onHold,
                'revenue_mtd' => round($revenue, 2),
                'target' => $target,
                'achieved_pct' => $achieved,
                'time_gone_pct' => $timeGonePct,
                'variance_to_pace' => $expected !== null ? round($revenue - $expected, 2) : null,
                'pace_status' => $paceStatus,
                'needs_attention' => $attentionReasons !== [],
                'attention_reason' => $attentionReasons[0] ?? 'On track',
                'attention_reasons' => $attentionReasons,
                'dormant_share_pct' => $dormantShare,
                'backorder_lines' => (int) ($backorder->line_count ?? 0),
                'backorder_revenue_at_risk' => round((float) ($backorder->value_at_risk ?? 0), 2),
                'last_activity_at' => collect($lastOrders)->filter()->max(),
                'top_at_risk_accounts' => $atRiskAccounts,
            ];
        }

        usort($groups, fn ($a, $b) => [$b['needs_attention'], $b['revenue_mtd']] <=> [$a['needs_attention'], $a['revenue_mtd']]);

        $summary = [
            'revenue_mtd' => round((float) collect($groups)->sum('revenue_mtd'), 2),
            'target' => round((float) collect($groups)->whereNotNull('target')->sum('target'), 2),
            'targeted_reportees' => collect($groups)->whereNotNull('target')->count(),
            'reportees_needing_attention' => collect($groups)->where('needs_attention', true)->count(),
            'backorder_revenue_at_risk' => round((float) collect($groups)->sum('backorder_revenue_at_risk'), 2),
        ];

        return response()->json([
            'period' => [
                'month_label' => $monthStart->format('F Y'),
                'time_gone_pct' => $timeGonePct,
                'active_from' => $activeFrom->toDateString(),
            ],
            'groups' => array_values($groups),
            'summary' => $summary,
        ]);
    }

    private function resolveTeamMemberByRepCode(User $caller, string $repCode): ?User
    {
        $candidate = User::query()
            ->where(function ($q) use ($repCode) {
                $q->whereRaw('UPPER(TRIM(rep_code)) = ?', [$repCode])
                    ->orWhereHas('acumaticaRepMappings', fn ($m) => $m->whereRaw('UPPER(TRIM(acumatica_rep_code)) = ?', [$repCode]));
            })
            ->first();

        if ($candidate === null) {
            return null;
        }

        if ($this->orgScope->hasOrgWideAccess($caller)) {
            return $candidate;
        }

        $allowed = $this->orgScope->effectiveScopeUserIds($caller);

        return in_array($candidate->id, $allowed, true) ? $candidate : null;
    }

    /** @return float Percentage (0-100) of this month's working days elapsed, capped at "now". */
    private function workingDayPacePct(CarbonImmutable $monthStart, CarbonImmutable $now): float
    {
        $total = 0;
        $elapsed = 0;
        $cursor = $monthStart;
        $end = $monthStart->endOfMonth();
        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $total++;
                if ($cursor->lte($now->startOfDay())) {
                    $elapsed++;
                }
            }
            $cursor = $cursor->addDay();
        }
        $total = max(1, $total);

        return round(min($elapsed, $total) / $total * 100, 1);
    }

    private function ensureCanView(?User $user): void
    {
        if ($user === null) {
            abort(401);
        }

        if ($user->role === 'Administrator' || $user->is_super_admin) {
            return;
        }

        // KP Accounts shares the KP CRM portfolio surface with FOL.
        if ($user->hasPermission('kp.fol.view') || $user->hasPermission('kp.accounts.view')) {
            return;
        }

        if ($this->portfolio->hasSalesBook($user) || $this->portfolio->hasTeamVisibility($user)) {
            return;
        }

        abort(403, 'You do not have access to KP Accounts.');
    }

    private function scopeLabel(?User $user): string
    {
        if ($user === null) {
            return 'none';
        }

        if (! app(\App\Services\Team\OrgScopeService::class)->appliesTo($user)) {
            return 'all_kp';
        }

        if ((string) $user->role === 'Sales Consultant' || $user->is_consultant) {
            return 'my_portfolio';
        }

        return 'scoped';
    }
}
