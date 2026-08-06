<?php

namespace App\Services\Team;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\CustomerAssignmentRule;
use App\Models\CustomerData;
use App\Models\User;
use App\Models\UserAcumaticaRepMapping;
use App\Models\UserCustomerAssignment;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Central customer-portfolio attribution service (PRD §7.1–§7.4, §7.8).
 *
 * This is the single deterministic source of truth for three concerns that the
 * rest of the application must delegate to, so that customer scoping is never
 * recomputed ad-hoc in controllers or scopes:
 *
 *   1. Identity resolution (§7.1) — turn an Acumatica rep alias into one user.
 *   2. Customer assignment precedence (§7.2) — resolve the canonical servicing
 *      rep for a customer through a fixed precedence ladder.
 *   3. Effective portfolio + directional visibility (§7.3, §7.4) — the exact
 *      mapped customer set for a user and the de-duplicated union across their
 *      reportee subtree.
 *
 * Identity aliases determine the rep; they never override an approved customer
 * assignment (PRD §7.1). For mapped-only Sales Consultants, rep-code and
 * employee-number matches are used only to propose/reconcile mappings, never to
 * broaden dashboard scope (PRD §7.3).
 */
class CustomerAttributionService
{
    /** Servicing-relevant assignment types for canonical-rep resolution. */
    private const SERVICING_TYPES = [
        UserCustomerAssignment::TYPE_SERVICING,
        UserCustomerAssignment::TYPE_LEGACY_PRIMARY,
    ];

    private const CACHE_PREFIX = 'attribution:';

    public function __construct(
        private readonly OrgTreeService $orgTree,
        private readonly Cache $cache,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | §7.1 Identity resolution
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve an Acumatica rep identifier to a single active user.
     *
     * Priority (configurable): employee_number → rep_code → rep_mapping.
     * Duplicate matches at the same priority and cross-priority conflicts are
     * reported as errors rather than silently resolved.
     */
    public function resolveIdentity(string $alias): IdentityResolution
    {
        $normalized = $this->normalizeAlias($alias);

        if ($normalized === '') {
            return new IdentityResolution(
                status: IdentityResolution::STATUS_UNRESOLVED,
                user: null,
                matchedVia: null,
                normalizedAlias: $normalized,
                candidates: [],
                reason: 'Empty identifier.',
            );
        }

        $sources      = $this->orderedIdentitySources();
        $activeBySrc  = []; // source => list<User>
        $candidateRow = []; // audit rows
        $hasInactive  = false;

        foreach ($sources as $source) {
            foreach ($this->findIdentityMatches($source, $normalized) as $user) {
                $isActive = (bool) $user->is_active;

                $candidateRow[] = [
                    'user_id'     => $user->id,
                    'name'        => $user->name,
                    'matched_via' => $source,
                    'is_active'   => $isActive,
                ];

                if ($isActive) {
                    $activeBySrc[$source][] = $user;
                } else {
                    $hasInactive = true;
                }
            }
        }

        $activeUsers = [];
        foreach ($activeBySrc as $users) {
            foreach ($users as $u) {
                $activeUsers[$u->id] = $u;
            }
        }

        if ($activeUsers === []) {
            $status = $hasInactive
                ? IdentityResolution::STATUS_INACTIVE
                : IdentityResolution::STATUS_UNRESOLVED;

            $reason = $hasInactive
                ? 'Identifier matched only inactive user(s); no active resolution.'
                : 'Identifier did not match any active employee number, rep code, or mapping.';

            return new IdentityResolution(
                status: $status,
                user: null,
                matchedVia: null,
                normalizedAlias: $normalized,
                candidates: $candidateRow,
                reason: $reason,
            );
        }

        // Highest priority (lowest ordinal) source that produced an active match.
        $winningSource = null;
        foreach ($sources as $source) {
            if (! empty($activeBySrc[$source])) {
                $winningSource = $source;
                break;
            }
        }

        $winners = $activeBySrc[$winningSource];

        // Duplicate active matches at the same priority → ambiguous (error).
        if (count($winners) > 1) {
            return new IdentityResolution(
                status: IdentityResolution::STATUS_AMBIGUOUS,
                user: null,
                matchedVia: $winningSource,
                normalizedAlias: $normalized,
                candidates: $candidateRow,
                reason: sprintf(
                    'Duplicate active matches at priority "%s" for the same identifier.',
                    $winningSource,
                ),
            );
        }

        $winner = $winners[0];

        // Cross-priority conflict: a different active user matched at any lower priority.
        foreach ($activeUsers as $otherId => $other) {
            if ($otherId !== $winner->id) {
                return new IdentityResolution(
                    status: IdentityResolution::STATUS_CONFLICT,
                    user: null,
                    matchedVia: $winningSource,
                    normalizedAlias: $normalized,
                    candidates: $candidateRow,
                    reason: sprintf(
                        'Cross-priority conflict: identifier matched user #%d via "%s" and user #%d at another priority.',
                        $winner->id,
                        $winningSource,
                        $otherId,
                    ),
                );
            }
        }

        return new IdentityResolution(
            status: IdentityResolution::STATUS_RESOLVED,
            user: $winner,
            matchedVia: $winningSource,
            normalizedAlias: $normalized,
            candidates: $candidateRow,
            reason: sprintf('Resolved via "%s".', $winningSource),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | §7.2 Customer assignment precedence
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve the single canonical servicing rep for a customer as of a date.
     *
     * Walks the precedence ladder and returns the winning assignment together
     * with every competing candidate (for audit). Lower priority numbers win;
     * ties between distinct users at the winning priority are reported as
     * unresolved/ambiguous and require admin action.
     */
    public function resolveCustomerAssignment(string $customerAcumaticaId, ?Carbon $asOf = null): CustomerAssignmentResolution
    {
        $asOf ??= Carbon::now();
        $suffix = "assignment:{$customerAcumaticaId}:{$asOf->toDateString()}";

        $resolution = $this->cached(
            $suffix,
            fn () => $this->computeCustomerAssignment($customerAcumaticaId, $asOf),
        );

        // Redis can outlive a restored/reseeded database. Never return a cached
        // resolution whose user no longer exists in the current database.
        if ($resolution->userId !== null && ! User::query()->whereKey($resolution->userId)->exists()) {
            $this->cache->forget($this->key($suffix));

            return $this->cached(
                $suffix,
                fn () => $this->computeCustomerAssignment($customerAcumaticaId, $asOf),
            );
        }

        return $resolution;
    }

    private function computeCustomerAssignment(string $customerAcumaticaId, Carbon $asOf): CustomerAssignmentResolution
    {
        $candidates = $this->collectAssignmentCandidates($customerAcumaticaId, $asOf);

        $resolved = array_values(array_filter(
            $candidates,
            fn (array $c) => $c['user_id'] !== null,
        ));

        if ($resolved === []) {
            return new CustomerAssignmentResolution(
                customerAcumaticaId: $customerAcumaticaId,
                userId: null,
                winningSource: CustomerAssignmentResolution::SOURCE_UNRESOLVED,
                winningPriority: PHP_INT_MAX,
                assignmentRuleId: null,
                candidates: $candidates,
                resolutionReason: 'No active manual, workbook, main-account, region, customer-rep, or SO-rep assignment resolved this customer.',
            );
        }

        usort($resolved, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        $winningPriority = $resolved[0]['priority'];
        $tied            = array_values(array_filter(
            $resolved,
            fn (array $c) => $c['priority'] === $winningPriority,
        ));
        $distinctUsers = array_values(array_unique(array_column($tied, 'user_id')));

        if (count($distinctUsers) > 1) {
            return new CustomerAssignmentResolution(
                customerAcumaticaId: $customerAcumaticaId,
                userId: null,
                winningSource: CustomerAssignmentResolution::SOURCE_UNRESOLVED,
                winningPriority: $winningPriority,
                assignmentRuleId: null,
                candidates: $candidates,
                resolutionReason: sprintf(
                    'Ambiguous: %d candidate(s) at priority %d resolve to distinct users [%s]. Admin resolution required.',
                    count($tied),
                    $winningPriority,
                    implode(', ', $distinctUsers),
                ),
            );
        }

        $winner = $tied[0];

        return new CustomerAssignmentResolution(
            customerAcumaticaId: $customerAcumaticaId,
            userId: $winner['user_id'],
            winningSource: $winner['source'],
            winningPriority: $winner['priority'],
            assignmentRuleId: $winner['rule_id'],
            candidates: $candidates,
            resolutionReason: sprintf('Resolved via %s (priority %d).', $winner['source'], $winner['priority']),
        );
    }

    /**
     * Gather one candidate row per active source for the precedence ladder.
     *
     * @return list<array{source: string, priority: int, user_id: ?int, rule_id: ?int, assignment_id: ?int, note: string}>
     */
    private function collectAssignmentCandidates(string $customerAcumaticaId, Carbon $asOf): array
    {
        $candidates = [];

        // --- Levels 1 & 2: explicit assignments (manual override vs. workbook) ---
        foreach ($this->effectiveAssignments($customerAcumaticaId, $asOf) as $assignment) {
            $isManual = (bool) $assignment->is_manual_override;

            $candidates[] = [
                'source'        => $isManual
                    ? CustomerAssignmentResolution::SOURCE_MANUAL_OVERRIDE
                    : CustomerAssignmentResolution::SOURCE_WORKBOOK_CUSTOMER,
                'priority'      => $isManual
                    ? (int) config('attribution.precedence.manual_override')
                    : ($assignment->priority ?: (int) config('attribution.default_assignment_priority')),
                'user_id'       => $assignment->user_id,
                'rule_id'       => null,
                'assignment_id' => $assignment->id,
                'note'          => $assignment->assignment_type,
            ];
        }

        // --- Customer context for rules & aliases ---
        $customer = $this->loadCustomer($customerAcumaticaId);

        if ($customer !== null) {
            $mainAccount      = $this->resolveMainAccountName($customer);
            $region           = $customer->customerData?->customer_region;
            $customerRepAlias = $customer->customerData?->main_ac_owner;

            // --- Level 3: main-account rule ---
            if ($this->present($mainAccount)) {
                foreach ($this->effectiveRules(CustomerAssignmentRule::TYPE_MAIN_ACCOUNT, $mainAccount, $asOf) as $rule) {
                    $candidates[] = $this->candidateFromRule(
                        $rule,
                        CustomerAssignmentResolution::SOURCE_MAIN_ACCOUNT,
                    );
                }
            }

            // --- Level 4: region rule ---
            if ($this->present($region)) {
                foreach ($this->effectiveRules(CustomerAssignmentRule::TYPE_REGION, $region, $asOf) as $rule) {
                    $candidates[] = $this->candidateFromRule(
                        $rule,
                        CustomerAssignmentResolution::SOURCE_REGION,
                    );
                }
            }

            // --- Level 5: Acumatica customer rep alias ---
            if ($this->present($customerRepAlias)) {
                $identity = $this->resolveIdentity((string) $customerRepAlias);
                $candidates[] = [
                    'source'        => CustomerAssignmentResolution::SOURCE_CUSTOMER_REP_ALIAS,
                    'priority'      => (int) config('attribution.precedence.customer_rep_alias'),
                    'user_id'       => $identity->resolved() ? $identity->user->id : null,
                    'rule_id'       => null,
                    'assignment_id' => null,
                    'note'          => trim('alias=' . $customerRepAlias . ' ' . $identity->status),
                ];
            }
        }

        // --- Level 6: most recent valid SO rep alias ---
        $soRepAlias = $this->mostRecentSalesOrderRepCode($customerAcumaticaId);
        if ($soRepAlias !== null) {
            $identity = $this->resolveIdentity($soRepAlias);
            $candidates[] = [
                'source'        => CustomerAssignmentResolution::SOURCE_SO_REP_ALIAS,
                'priority'      => (int) config('attribution.precedence.so_rep_alias'),
                'user_id'       => $identity->resolved() ? $identity->user->id : null,
                'rule_id'       => null,
                'assignment_id' => null,
                'note'          => trim('so_rep=' . $soRepAlias . ' ' . $identity->status),
            ];
        }

        return $candidates;
    }

    /*
    |--------------------------------------------------------------------------
    | Effective portfolio & directional visibility (§7.3, §7.4, §7.8)
    |--------------------------------------------------------------------------
    */

    /**
     * The effective mapped (direct portfolio) customer IDs canonically
     * attributed to a user as of a date.
     *
     * Sources: explicit servicing/manual assignments, plus customers resolved
     * to this user through approved main-account or region rules. Rep aliases
     * alone are NOT treated as approved mappings (PRD §7.3) and therefore do
     * not appear here — they drive reconciliation, not dashboard scope.
     *
     * @return list<string>
     */
    public function directCustomerIds(int $userId, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        return $this->cached(
            "direct:{$userId}:{$asOf->toDateString()}",
            fn () => $this->computeDirectCustomerIds($userId, $asOf),
        );
    }

    /**
     * Explicitly attached book used by FOL's hard authorization boundary.
     * ERP/SO rep aliases and rule-derived suggestions intentionally do not enter
     * this set until an administrator/import creates an assignment row.
     *
     * @return list<string>
     */
    public function folCustomerIds(int $userId, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        return UserCustomerAssignment::query()
            ->where('user_id', $userId)
            ->whereIn('assignment_type', self::SERVICING_TYPES)
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOf);
            })
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf);
            })
            ->where(function (Builder $q) {
                $q->where('is_manual_override', true)
                    ->orWhereIn('source', ['manual', 'admin_csv', 'portfolio_import', 'kp_customers_20260805']);
            })
            ->pluck('customer_acumatica_id')
            ->map(fn (mixed $id) => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function computeDirectCustomerIds(int $userId, Carbon $asOf): array
    {
        $explicit = UserCustomerAssignment::query()
            ->where('user_id', $userId)
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOf);
            })
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf);
            })
            ->where(function (Builder $q) {
                // Servicing-type mappings, or any explicitly manual override.
                $q->where('is_manual_override', true)
                    ->orWhereIn('assignment_type', self::SERVICING_TYPES);
            })
            ->pluck('customer_acumatica_id')
            ->all();

        return collect($explicit)
            ->merge($this->ruleDerivedCustomerIds($userId, $asOf))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Customers whose winning assignment resolves to this user through an
     * approved main-account or region rule. Each candidate is re-resolved so
     * that a higher-precedence override to another user is respected.
     *
     * @return list<string>
     */
    private function ruleDerivedCustomerIds(int $userId, Carbon $asOf): array
    {
        $rules = CustomerAssignmentRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereIn('rule_type', [
                CustomerAssignmentRule::TYPE_MAIN_ACCOUNT,
                CustomerAssignmentRule::TYPE_REGION,
            ])
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOf);
            })
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf);
            })
            ->get();

        if ($rules->isEmpty()) {
            return [];
        }

        $ids = [];

        foreach ($rules as $rule) {
            $matches = match ($rule->rule_type) {
                CustomerAssignmentRule::TYPE_MAIN_ACCOUNT => $this->customerIdsForMainAccount($rule->match_value),
                CustomerAssignmentRule::TYPE_REGION       => $this->customerIdsForRegion($rule->match_value),
                default                                   => [],
            };

            foreach ($matches as $customerId) {
                $resolution = $this->computeCustomerAssignment($customerId, $asOf);
                if ($resolution->userId === $userId) {
                    $ids[] = $customerId;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * The de-duplicated union of the direct portfolios of a user and all of
     * their descendants (directional hierarchy, PRD §7.4). The mapped-only gate
     * is applied at each node before the rollup is built, so inherited aliases
     * never leak extra customers into a manager's view.
     *
     * @return list<string>
     */
    public function visibleCustomerIds(int $userId, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $ids = [];
        foreach ($this->visibleUserIds($userId) as $reporteeId) {
            foreach ($this->directCustomerIds($reporteeId, $asOf) as $customerId) {
                $ids[$customerId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * The user and all of their descendants (self included). Used for team
     * rollups and drill-down authorization.
     *
     * @return list<int>
     */
    public function visibleUserIds(int $userId): array
    {
        return $this->orgTree->descendantIds($userId, includeSelf: true);
    }

    /*
    |--------------------------------------------------------------------------
    | §7.3 Mapped-only Sales Consultant gate
    |--------------------------------------------------------------------------
    */

    /**
     * isMappedOnlyConsultant(user) =
     *     user has canonical Sales Consultant role
     *     AND user has at least one active servicing customer assignment.
     *
     * Role evaluation uses the many-to-many user_roles relationship (never only
     * the legacy users.role string). When true, the user's personal dashboard
     * scope is restricted to exactly their mapped customer IDs — see the scope
     * wiring that delegates to {@see directCustomerIds()}.
     */
    public function isMappedOnlyConsultant(User $user): bool
    {
        if (! $user->hasRole((string) config('attribution.sales_consultant_role'))) {
            return false;
        }

        return $this->hasActiveServicingAssignment($user->id);
    }

    /**
     * Audit-friendly explanation of why a customer is (or is not) visible to a
     * viewer, including the canonical owner and the winning precedence source.
     *
     * @return array{
     *     customer_acumatica_id: string,
     *     viewer_user_id: int,
     *     is_visible: bool,
     *     is_direct: bool,
     *     canonical_user_id: ?int,
     *     winning_source: string,
     *     winning_priority: int,
     *     candidates: list<mixed>,
     *     resolution_reason: string
     * }
     */
    public function explainCustomerAccess(int $userId, string $customerAcumaticaId, ?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $resolution = $this->resolveCustomerAssignment($customerAcumaticaId, $asOf);
        $directIds  = $this->directCustomerIds($userId, $asOf);
        $visibleIds = $this->visibleCustomerIds($userId, $asOf);

        return [
            'customer_acumatica_id' => $customerAcumaticaId,
            'viewer_user_id'        => $userId,
            'is_visible'            => in_array($customerAcumaticaId, $visibleIds, true),
            'is_direct'             => in_array($customerAcumaticaId, $directIds, true),
            'canonical_user_id'     => $resolution->userId,
            'winning_source'        => $resolution->winningSource,
            'winning_priority'      => $resolution->winningPriority,
            'candidates'            => $resolution->candidates,
            'resolution_reason'     => $resolution->resolutionReason,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Cache invalidation
    |--------------------------------------------------------------------------
    */

    /** Flush all cached resolution for a single customer. */
    public function flushForCustomer(string $customerAcumaticaId): void
    {
        $this->cache->forget($this->key("assignment:{$customerAcumaticaId}"));

        // Direct-portfolio cache is keyed per-user/day; a customer change can
        // affect multiple users, so flush the whole attribution namespace.
        $this->flushAll();
    }

    /** Flush cached direct/visible portfolios for a single user. */
    public function flushForUser(int $userId): void
    {
        $this->cache->forget($this->key("direct:{$userId}"));
        $this->flushAll();
    }

    /** Flush the entire attribution cache namespace. */
    public function flushAll(): void
    {
        $this->cache->flush();
    }

    /*
    |--------------------------------------------------------------------------
    | Resolution helpers
    |--------------------------------------------------------------------------
    */

    /** @return Collection<int, UserCustomerAssignment> */
    private function effectiveAssignments(string $customerAcumaticaId, Carbon $asOf): Collection
    {
        return UserCustomerAssignment::query()
            ->where('customer_acumatica_id', $customerAcumaticaId)
            ->where(function (Builder $q) {
                // Servicing mappings, or any explicitly manual override.
                $q->where('is_manual_override', true)
                    ->orWhereIn('assignment_type', self::SERVICING_TYPES);
            })
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOf);
            })
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf);
            })
            ->get();
    }

    /** @return Collection<int, CustomerAssignmentRule> */
    private function effectiveRules(string $ruleType, string $matchValue, Carbon $asOf): Collection
    {
        $normalized = $this->normalizeAlias($matchValue);

        return CustomerAssignmentRule::query()
            ->where('rule_type', $ruleType)
            ->where('is_active', true)
            ->whereRaw('UPPER(TRIM(match_value)) = ?', [$normalized])
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $asOf);
            })
            ->where(function (Builder $q) use ($asOf) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $asOf);
            })
            ->orderBy('priority')
            ->get();
    }

    /**
     * @return array{source: string, priority: int, user_id: ?int, rule_id: ?int, assignment_id: null, note: string}
     */
    private function candidateFromRule(CustomerAssignmentRule $rule, string $source): array
    {
        return [
            'source'        => $source,
            'priority'      => $rule->priority ?: (int) config('attribution.precedence.' . $source),
            'user_id'       => $rule->user_id,
            'rule_id'       => $rule->id,
            'assignment_id' => null,
            'note'          => sprintf('%s=%s', $rule->rule_type, $rule->match_value),
        ];
    }

    private function hasActiveServicingAssignment(int $userId): bool
    {
        return UserCustomerAssignment::query()
            ->where('user_id', $userId)
            ->where(function (Builder $q) {
                $q->where('is_manual_override', true)
                    ->orWhereIn('assignment_type', self::SERVICING_TYPES);
            })
            ->where(function (Builder $q) {
                $now = Carbon::now();
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $now);
            })
            ->where(function (Builder $q) {
                $now = Carbon::now();
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $now);
            })
            ->exists();
    }

    private function mostRecentSalesOrderRepCode(string $customerAcumaticaId): ?string
    {
        $code = AcumaticaSalesOrder::query()
            ->whereIn('order_type', AcumaticaSalesOrder::IN_SCOPE_ORDER_TYPES)
            ->where('customer_acumatica_id', $customerAcumaticaId)
            ->whereNotNull('sales_consultant_rep_code')
            ->where('sales_consultant_rep_code', '!=', '')
            ->orderByDesc('order_date')
            ->value('sales_consultant_rep_code');

        return $code !== null ? (string) $code : null;
    }

    private function loadCustomer(string $customerAcumaticaId): ?AcumaticaCustomer
    {
        return AcumaticaCustomer::query()
            ->with(['customerData', 'parent'])
            ->where('acumatica_id', $customerAcumaticaId)
            ->first();
    }

    /**
     * Resolve the canonical main-account name for a customer: the denormalized
     * column first, then the customer itself, then the nearest main-account
     * ancestor up the parent chain.
     */
    private function resolveMainAccountName(AcumaticaCustomer $customer): ?string
    {
        if ($this->present($customer->main_account_name ?? null)) {
            return $customer->main_account_name;
        }

        if ((bool) $customer->is_main_account) {
            return $customer->name;
        }

        $parent = $customer->parent;
        for ($depth = 0; $parent !== null && $depth < 5; $depth++) {
            if ((bool) $parent->is_main_account) {
                return $parent->name;
            }
            $parent = $parent->parent_acumatica_id
                ? AcumaticaCustomer::query()
                    ->with('parent')
                    ->where('acumatica_id', $parent->parent_acumatica_id)
                    ->first()
                : null;
        }

        return $customer->parent?->name;
    }

    /** @return list<string> */
    private function customerIdsForMainAccount(string $mainAccountName): array
    {
        $normalized = $this->normalizeAlias($mainAccountName);

        $byColumn = AcumaticaCustomer::query()
            ->whereRaw('UPPER(TRIM(main_account_name)) = ?', [$normalized])
            ->pluck('acumatica_id')
            ->all();

        // Include outlets whose nearest main-account ancestor carries this name.
        $byParent = AcumaticaCustomer::query()
            ->where('is_main_account', false)
            ->whereRaw('UPPER(TRIM(name)) = ?', [$normalized])
            ->whereNotNull('parent_acumatica_id')
            ->pluck('parent_acumatica_id')
            ->all();

        $childIds = $byParent !== []
            ? AcumaticaCustomer::query()
                ->whereIn('parent_acumatica_id', $byParent)
                ->pluck('acumatica_id')
                ->all()
            : [];

        return array_values(array_unique(array_merge($byColumn, $childIds)));
    }

    /** @return list<string> */
    private function customerIdsForRegion(string $region): array
    {
        $normalized = $this->normalizeAlias($region);

        return CustomerData::query()
            ->whereRaw('UPPER(TRIM(customer_region)) = ?', [$normalized])
            ->pluck('customer_acumatica_id')
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Identity-resolution internals
    |--------------------------------------------------------------------------
    */

    /** @return list<string> */
    private function orderedIdentitySources(): array
    {
        return collect(config('attribution.identity_priority'))
            ->sort()
            ->keys()
            ->all();
    }

    /** @return Collection<int, User> */
    private function findIdentityMatches(string $source, string $normalized): Collection
    {
        return match ($source) {
            'employee_number' => User::query()
                ->whereRaw('UPPER(TRIM(employee_number)) = ?', [$normalized])
                ->get(),
            'rep_code' => User::query()
                ->whereRaw('UPPER(TRIM(rep_code)) = ?', [$normalized])
                ->get(),
            'rep_mapping' => User::query()
                ->whereIn(
                    'id',
                    UserAcumaticaRepMapping::query()
                        ->whereRaw('UPPER(TRIM(acumatica_rep_code)) = ?', [$normalized])
                        ->pluck('user_id'),
                )
                ->get(),
            default => new Collection(),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
    */

    private function normalizeAlias(?string $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    private function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function key(string $suffix): string
    {
        return self::CACHE_PREFIX . $suffix;
    }

    /**
     * Memoize a result for the configured TTL. A TTL of 0 disables caching and
     * the callback runs every time (useful in tests).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function cached(string $suffix, callable $callback): mixed
    {
        $ttl = (int) config('attribution.effective_assignment_cache_ttl', 0);

        if ($ttl <= 0) {
            return $callback();
        }

        return $this->cache->remember($this->key($suffix), $ttl, function () use ($callback) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                Log::warning('Customer attribution resolution failed', [
                    'cache_key' => $suffix,
                    'exception' => $e->getMessage(),
                ]);
                throw $e;
            }
        });
    }
}
