<?php

namespace App\Services\AI;

use App\Exceptions\AiGenerationException;
use App\Jobs\GenerateKimfayGeniusJob;
use App\Models\AiGeniusBriefing;
use App\Models\User;
use App\Services\Team\KpReportingHierarchyService;
use App\Services\Team\OrgScopeService;
use App\Support\SalesConsultantScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class KimfayGeniusService
{
    public function __construct(
        private readonly LlmClient $llm,
        private readonly AiPromptLogService $logger,
        private readonly OrgScopeService $orgScope,
        private readonly KpReportingHierarchyService $kpHierarchy,
    ) {}

    public function weekStart(?Carbon $at = null): Carbon
    {
        $tz = (string) config('ai.genius.timezone', 'Africa/Nairobi');
        $at = ($at ?? now($tz))->copy()->timezone($tz)->startOfDay();
        $start = strtolower((string) config('ai.genius.week_start', 'monday'));

        return $start === 'sunday'
            ? $at->copy()->startOfWeek(Carbon::SUNDAY)
            : $at->copy()->startOfWeek(Carbon::MONDAY);
    }

    public function unlockAt(Carbon $weekStart): Carbon
    {
        return $weekStart->copy()->addWeek()->startOfDay();
    }

    /**
     * Users who carry a personal sales book (rep-code / consultant flag / SC role).
     * Managers, HODs, and executives can double as consultants — role alone does not exclude them.
     */
    public function hasSalesBook(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ((bool) $user->is_consultant) {
            return true;
        }

        if ((string) $user->role === SalesConsultantScope::ROLE) {
            return true;
        }

        $rep = strtoupper(trim((string) ($user->rep_code ?? '')));
        if ($rep !== '') {
            return true;
        }

        // Explicit customer assignments count as a book even without rep_code.
        return $user->customerAssignments()->exists();
    }

    /**
     * Whether the actor manages a team (HOD / manager / executive subtree), not only self.
     */
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

        // Subtree larger than self ⇒ team manager
        $ids = $this->orgScope->effectiveScopeUserIds($user);

        return count($ids) > 1;
    }

    /**
     * Consultants the actor may view for Kimfay Genius.
     *
     * Product rule: every authenticated user may open Genius and browse everyone
     * who has a sales book (rep code / consultant flag / assignments). Portfolio
     * metrics for a brief remain scoped to that consultant's personal book.
     *
     * @return Collection<int, User>
     */
    public function listConsultants(User $actor): Collection
    {
        // Actor must be active; guests never reach here (auth:sanctum).
        if (! $actor->is_active && ! $actor->is_super_admin) {
            return collect();
        }

        $columns = ['id', 'name', 'email', 'rep_code', 'role', 'is_consultant', 'org_level', 'department_role'];

        $salesBooks = User::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('role', SalesConsultantScope::ROLE)
                    ->orWhere('is_consultant', true)
                    ->orWhere(function ($r) {
                        $r->whereNotNull('rep_code')->where('rep_code', '!=', '');
                    })
                    ->orWhereHas('customerAssignments');
            })
            ->orderBy('name')
            ->get([...$columns, 'employee_number']);

        return $this->kpHierarchy->filterVisibleSalesBooks($actor, $salesBooks);
    }

    public function canAccessConsultant(User $actor, User $consultant): bool
    {
        if (! $consultant->is_active) {
            return false;
        }

        // Org-wide Genius: any signed-in active user may open any sales-book brief.
        if (! $actor->is_active && ! $actor->is_super_admin) {
            return false;
        }

        return $this->listConsultants($actor)->contains(
            fn (User $u) => (int) $u->id === (int) $consultant->id,
        );
    }

    public function canForceRegenerate(User $actor): bool
    {
        return (bool) $actor->is_super_admin || $actor->role === 'Administrator';
    }

    /**
     * @return array{
     *   consultant: array<string, mixed>,
     *   week_start: string,
     *   unlock_at: string,
     *   lock_active: bool,
     *   can_generate: bool,
     *   briefing: array<string, mixed>|null
     * }
     */
    public function show(User $actor, User $consultant): array
    {
        if (! $this->canAccessConsultant($actor, $consultant)) {
            abort(404, 'Consultant not found.');
        }

        $weekStart = $this->weekStart();
        $briefing = AiGeniusBriefing::query()
            ->where('consultant_user_id', $consultant->id)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        $lockActive = $briefing !== null && $briefing->ai_status === 'success';
        $canGenerate = ! $lockActive
            || $this->canForceRegenerate($actor);

        // Failed / queued / running still allow retry without force
        if ($briefing && in_array($briefing->ai_status, ['failed', 'queued', 'running'], true)) {
            $canGenerate = true;
            $lockActive = false;
        }

        return [
            'consultant' => [
                'id' => $consultant->id,
                'name' => $consultant->name,
                'email' => $consultant->email,
                'rep_code' => $consultant->rep_code,
                'role' => $consultant->role,
            ],
            'week_start' => $weekStart->toDateString(),
            'unlock_at' => $this->unlockAt($weekStart)->toIso8601String(),
            'lock_active' => $lockActive,
            'can_generate' => $canGenerate && $this->canAccessConsultant($actor, $consultant),
            'briefing' => $briefing ? $this->presentBriefing($briefing) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function enqueue(User $actor, User $consultant, bool $force = false): array
    {
        if (! $this->canAccessConsultant($actor, $consultant)) {
            abort(404, 'Consultant not found.');
        }

        $weekStart = $this->weekStart();
        $existing = AiGeniusBriefing::query()
            ->where('consultant_user_id', $consultant->id)
            ->whereDate('week_start', $weekStart->toDateString())
            ->first();

        if ($existing && $existing->ai_status === 'success' && ! ($force && $this->canForceRegenerate($actor))) {
            throw new AiGenerationException(
                'Kimfay Genius already generated for this consultant this week. Next generation available '.$this->unlockAt($weekStart)->toDateString().'.',
                'AI_GENIUS_WEEKLY_LOCK',
                422,
            );
        }

        if ($existing && in_array($existing->ai_status, ['queued', 'running'], true)) {
            return $this->presentBriefing($existing);
        }

        $uuid = (string) Str::uuid();
        $briefing = $existing ?? new AiGeniusBriefing([
            'consultant_user_id' => $consultant->id,
            'week_start' => $weekStart->toDateString(),
        ]);
        $briefing->fill([
            'ai_status' => 'queued',
            'error_message' => null,
            'queue_uuid' => $uuid,
            'generated_by_user_id' => $actor->id,
            'insights' => $force ? null : $briefing->insights,
        ]);
        $briefing->save();

        GenerateKimfayGeniusJob::dispatch($briefing->id);

        return $this->presentBriefing($briefing->fresh());
    }

    /**
     * @return array{insights: array<string, mixed>, metrics_snapshot: array<string, mixed>, provider: string|null, model: string|null}
     *
     * @throws AiGenerationException
     */
    public function runGeneration(AiGeniusBriefing $briefing): array
    {
        $consultant = User::query()->findOrFail($briefing->consultant_user_id);
        $metrics = $this->buildPortfolioMetrics($consultant);
        $system = $this->systemPrompt();
        $user = 'Generate a Kimfay Genius weekly coaching brief for this sales consultant from compact JSON: '
            .json_encode($metrics, JSON_UNESCAPED_UNICODE);
        $start = microtime(true);

        try {
            $result = $this->llm->chatJson($system, $user);
            $parsed = $this->parse($result['content'], $consultant, $briefing->week_start->toDateString());
            $elapsed = (int) ((microtime(true) - $start) * 1000);

            $this->logger->log([
                'prompt' => mb_substr($user, 0, 8000),
                'intent' => 'kimfay_genius',
                'domains' => ['portfolio', 'orders', 'customers'],
                'ai_message' => mb_substr($result['content'], 0, 8000),
                'provider' => $result['provider'],
                'response_time_ms' => $elapsed,
                'status' => 'success',
                'user_id' => $briefing->generated_by_user_id,
            ]);

            return [
                'insights' => $parsed,
                'metrics_snapshot' => $metrics,
                'provider' => $result['provider'],
                'model' => $result['model'],
            ];
        } catch (AiGenerationException $e) {
            $this->logger->log([
                'prompt' => mb_substr($user, 0, 8000),
                'intent' => 'kimfay_genius',
                'provider' => $e->provider,
                'response_time_ms' => (int) ((microtime(true) - $start) * 1000),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'user_id' => $briefing->generated_by_user_id,
            ]);
            throw $e;
        } catch (Throwable $e) {
            $this->logger->log([
                'prompt' => mb_substr($user, 0, 8000),
                'intent' => 'kimfay_genius',
                'response_time_ms' => (int) ((microtime(true) - $start) * 1000),
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'user_id' => $briefing->generated_by_user_id,
            ]);
            throw new AiGenerationException(mb_substr($e->getMessage(), 0, 500), 'AI_PROVIDER_ERROR', 502);
        }
    }

    /** @return array<string, mixed> */
    public function buildPortfolioMetrics(User $consultant): array
    {
        $tz = (string) config('ai.genius.timezone', 'Africa/Nairobi');
        $monthStart = now($tz)->startOfMonth();
        $activeFrom = $monthStart->copy()->subMonths(3);
        $to = now($tz)->endOfDay();

        $customerIds = $this->portfolioCustomerIds($consultant);

        $base = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $customerIds === [] ? ['__none__'] : $customerIds);

        $mtd = (clone $base)
            ->whereBetween('order_date', [$monthStart->toDateString(), $to->toDateString()])
            ->selectRaw('COUNT(*) as orders_count, COALESCE(SUM(order_total), 0) as revenue')
            ->first();

        $lastOrderByCustomer = DB::table('acumatica_sales_orders')
            ->where('order_type', 'SO')
            ->whereIn('customer_acumatica_id', $customerIds === [] ? ['__none__'] : $customerIds)
            ->groupBy('customer_acumatica_id')
            ->selectRaw('customer_acumatica_id, MAX(order_date) as last_order')
            ->get()
            ->keyBy('customer_acumatica_id');

        $active = 0;
        $dormant = 0;
        foreach ($customerIds as $cid) {
            $last = $lastOrderByCustomer->get($cid)?->last_order;
            if ($last && Carbon::parse($last)->gte($activeFrom)) {
                $active++;
            } else {
                $dormant++;
            }
        }

        $topCustomers = (clone $base)
            ->whereBetween('order_date', [$monthStart->toDateString(), $to->toDateString()])
            ->selectRaw('customer_acumatica_id, MAX(customer_name) as customer_name, COUNT(*) as orders, COALESCE(SUM(order_total), 0) as value')
            ->groupBy('customer_acumatica_id')
            ->orderByDesc('value')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'customer_id' => $r->customer_acumatica_id,
                'customer_name' => $r->customer_name,
                'orders' => (int) $r->orders,
                'value' => round((float) $r->value, 2),
            ])
            ->all();

        // Alias must not be `lines` — LINES is reserved in MariaDB/MySQL (syntax error 1064).
        $backorders = DB::table('acumatica_backorder_lines')
            ->where('shortfall_kind', 'active_backorder')
            ->whereIn('customer_acumatica_id', $customerIds === [] ? ['__none__'] : $customerIds)
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(revenue_at_risk), 0) as revenue_at_risk')
            ->first();

        $statusBreakdown = (clone $base)
            ->whereBetween('order_date', [$monthStart->toDateString(), $to->toDateString()])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return [
            'consultant' => [
                'id' => $consultant->id,
                'name' => $consultant->name,
                'rep_code' => $consultant->rep_code,
            ],
            'windows' => [
                'timezone' => $tz,
                'month_start' => $monthStart->toDateString(),
                'active_from' => $activeFrom->toDateString(),
                'as_of' => $to->toIso8601String(),
            ],
            'portfolio' => [
                'customers_total' => count($customerIds),
                'customers_active' => $active,
                'customers_dormant' => $dormant,
            ],
            'mtd' => [
                'orders_count' => (int) ($mtd->orders_count ?? 0),
                'revenue' => round((float) ($mtd->revenue ?? 0), 2),
                'status_breakdown' => $statusBreakdown,
            ],
            'backorders' => [
                'lines' => (int) ($backorders->line_count ?? 0),
                'revenue_at_risk' => round((float) ($backorders->revenue_at_risk ?? 0), 2),
            ],
            'top_customers' => $topCustomers,
        ];
    }

    /**
     * Personal sales book only — not org-wide executive visibility.
     * Managers/executives who double as consultants must get Genius metrics for
     * their rep-code + assignments, not the entire company portfolio.
     *
     * @return list<string>
     */
    private function portfolioCustomerIds(User $consultant): array
    {
        $ids = [];

        $assigned = $consultant->customerAssignments()
            ->pluck('customer_acumatica_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->all();
        foreach ($assigned as $id) {
            $ids[$id] = $id;
        }

        $rep = strtoupper(trim((string) ($consultant->rep_code ?? '')));
        if ($rep === '') {
            $mapped = $consultant->acumaticaRepMappings()
                ->where('is_primary', true)
                ->value('acumatica_rep_code');
            $rep = $mapped ? strtoupper(trim((string) $mapped)) : '';
        }

        if ($rep !== '') {
            $fromOrders = DB::table('acumatica_sales_orders')
                ->where('sales_consultant_rep_code', $rep)
                ->whereNotNull('customer_acumatica_id')
                ->distinct()
                ->pluck('customer_acumatica_id')
                ->all();
            foreach ($fromOrders as $id) {
                $id = (string) $id;
                if ($id !== '') {
                    $ids[$id] = $id;
                }
            }
        }

        return array_values($ids);
    }

    /** @return array<string, mixed> */
    private function presentBriefing(AiGeniusBriefing $b): array
    {
        return [
            'id' => $b->id,
            'week_start' => $b->week_start?->toDateString(),
            'ai_status' => $b->ai_status,
            'provider' => $b->provider,
            'model' => $b->model,
            'error_message' => $b->error_message,
            'queue_uuid' => $b->queue_uuid,
            'generated_at' => $b->generated_at?->toIso8601String(),
            'insights' => $b->insightPayload() ?: null,
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Kimfay Genius — a field sales coach for Kim-Fay East Africa (Kenya distribution).
You coach ONE sales consultant using only the portfolio metrics provided.

Return ONLY valid JSON:
{
  "executive_summary": "3-5 sentences, practical coaching tone",
  "portfolio": { "summary": "2-3 sentences", "highlights": ["…"] },
  "risks": { "summary": "2-3 sentences on dormant, backorders, declining accounts", "highlights": ["…"] },
  "predictions": { "summary": "2-3 sentences forward outlook from MTD run-rate", "highlights": ["…"] },
  "actions": ["this week action 1", "action 2", "action 3"]
}

Rules:
- Use ONLY numbers from the payload. Never invent figures.
- Use KES for currency.
- Be direct and actionable for a sales consultant.
- No markdown.
PROMPT;
    }

    /**
     * @return array<string, mixed>
     */
    private function parse(string $raw, User $consultant, string $weekStart): array
    {
        $clean = trim($raw);
        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
            $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;
        }
        $decoded = json_decode($clean, true);
        if (! is_array($decoded)) {
            throw new AiGenerationException('Kimfay Genius response was not valid JSON.', 'AI_PROVIDER_ERROR', 502);
        }

        $section = static function (mixed $s): array {
            $s = is_array($s) ? $s : [];

            return [
                'summary' => (string) ($s['summary'] ?? ''),
                'highlights' => array_values($s['highlights'] ?? []),
            ];
        };

        return [
            'executive_summary' => (string) ($decoded['executive_summary'] ?? ''),
            'portfolio' => $section($decoded['portfolio'] ?? []),
            'risks' => $section($decoded['risks'] ?? []),
            'predictions' => $section($decoded['predictions'] ?? []),
            'actions' => array_values($decoded['actions'] ?? []),
            'week_start' => $weekStart,
            'consultant' => [
                'id' => $consultant->id,
                'name' => $consultant->name,
                'rep_code' => $consultant->rep_code,
            ],
        ];
    }
}
