<?php

namespace App\Services\SalesManagement;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSyncLog;
use App\Models\SalesManagementPrompt;
use App\Models\SalesManagementPromptEvent;
use App\Models\SalesManagementSetting;
use App\Models\User;
use App\Services\Team\OrgTreeService;
use App\Support\DataScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesManagementPromptService
{
    public const RESERVED_TYPES = [
        'debt_collection',
        'volume_delta',
        'whitespot_customer',
        'whitespot_product',
        'incentive_review',
    ];

    public function __construct(private readonly OrgTreeService $orgTree) {}

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $stored = SalesManagementSetting::query()
            ->get(['key', 'value_json'])
            ->mapWithKeys(fn (SalesManagementSetting $setting) => [$setting->key => $setting->value_json])
            ->all();

        return [
            'cycle_history_orders' => (int) ($stored['cycle_history_orders'] ?? 6),
            'cycle_due_multiplier' => (float) ($stored['cycle_due_multiplier'] ?? 1.1),
            'cycle_overdue_multiplier' => (float) ($stored['cycle_overdue_multiplier'] ?? 1.5),
            'max_snooze_days' => (int) ($stored['max_snooze_days'] ?? 30),
            'stale_so_sync_hours' => (int) ($stored['stale_so_sync_hours'] ?? 24),
            'month_gap_statuses' => $stored['month_gap_statuses'] ?? ['completed', 'closed', 'invoiced'],
            'reserved_prompt_types' => self::RESERVED_TYPES,
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): array
    {
        foreach ([
            'cycle_history_orders',
            'cycle_due_multiplier',
            'cycle_overdue_multiplier',
            'max_snooze_days',
            'stale_so_sync_hours',
            'month_gap_statuses',
        ] as $key) {
            if (array_key_exists($key, $settings)) {
                SalesManagementSetting::updateOrCreate(['key' => $key], ['value_json' => $settings[$key]]);
            }
        }

        return $this->settings();
    }

    public function ensureAudience(User $user): void
    {
        if (! $this->isAllowedAudience($user)) {
            abort(403, 'Forbidden.');
        }
    }

    public function ensureCan(User $user, string $permission): void
    {
        $this->ensureAudience($user);
        if (! $user->hasPermission($permission)) {
            abort(403, 'Forbidden.');
        }
    }

    public function listQuery(User $actor, ?string $view = null): Builder
    {
        $this->ensureCan($actor, 'sales.management.view');

        $query = SalesManagementPrompt::query()
            ->with(['consultant:id,name,email,role,rep_code,org_level,department_role'])
            ->latest('id');

        if ($view === 'due') {
            $query->whereIn('status', ['open', 'snoozed'])
                ->where(function (Builder $scoped) {
                    $scoped->whereNull('snoozed_until')
                        ->orWhere('snoozed_until', '<=', now());
                });
        } elseif ($view === 'resolved') {
            $query->where('status', 'resolved');
        } elseif ($view === 'month_gap') {
            $query->where('prompt_type', SalesManagementPrompt::TYPE_NOT_BILLED);
        } elseif ($view === 'my') {
            $query->where(function (Builder $scoped) use ($actor) {
                $scoped->where('consultant_user_id', $actor->id);
                $rep = strtoupper(trim((string) $actor->rep_code));
                if ($rep !== '') {
                    $scoped->orWhere('consultant_rep_code', $rep);
                }
            });
        }

        return $this->applyActorScope($query, $actor);
    }

    public function present(SalesManagementPrompt $prompt): array
    {
        $prompt->loadMissing(['consultant:id,name,email,role,rep_code', 'events']);

        return [
            ...$prompt->toArray(),
            'consultant' => $prompt->consultant,
            'events' => $prompt->events()->latest('id')->limit(20)->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function dashboard(User $actor): array
    {
        $base = $this->listQuery($actor);
        $sync = $this->salesOrderSyncFreshness();

        return [
            'total_open' => (clone $base)->whereIn('status', ['open', 'snoozed'])->count(),
            'due' => (clone $base)->whereIn('status', ['open', 'snoozed'])->where(function (Builder $query) {
                $query->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now());
            })->count(),
            'overdue' => (clone $base)->where('severity', 'overdue')->whereIn('status', ['open', 'snoozed'])->count(),
            'month_gaps' => (clone $base)->where('prompt_type', SalesManagementPrompt::TYPE_NOT_BILLED)->whereIn('status', ['open', 'snoozed'])->count(),
            'resolved_30d' => (clone $base)->where('status', 'resolved')->where('resolved_at', '>=', now()->subDays(30))->count(),
            'sales_order_sync' => $sync,
        ];
    }

    /**
     * @return array{created:int,updated:int,skipped:int,stale_blocked:bool,stale_message:?string}
     */
    public function generate(User $actor, ?string $period = null, bool $force = false): array
    {
        $this->ensureCan($actor, 'sales.management.manage');

        $freshness = $this->salesOrderSyncFreshness();
        if (! $force && ($freshness['is_stale'] ?? false)) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'stale_blocked' => true,
                'stale_message' => (string) ($freshness['message'] ?? 'Sales order sync is stale.'),
            ];
        }

        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $cycle = $this->generateOrderCyclePrompts($actor);
        $month = $this->generateMonthGapPrompts($actor, $period);

        foreach ($stats as $key => $_) {
            $stats[$key] = $cycle[$key] + $month[$key];
        }

        return [
            ...$stats,
            'stale_blocked' => false,
            'stale_message' => null,
        ];
    }

    public function resolve(User $actor, SalesManagementPrompt $prompt, string $note): SalesManagementPrompt
    {
        $this->ensureCan($actor, 'sales.management.resolve');
        $this->ensurePromptVisible($actor, $prompt);
        if (blank($note)) {
            throw ValidationException::withMessages(['note' => ['Resolution note is required.']]);
        }

        $prompt->forceFill([
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution_note' => $note,
            'updated_by' => $actor->id,
        ])->save();
        $this->event($prompt, 'resolved', $actor, $note);

        return $prompt->fresh(['consultant', 'events']);
    }

    public function dismiss(User $actor, SalesManagementPrompt $prompt, string $reason): SalesManagementPrompt
    {
        $this->ensureCan($actor, 'sales.management.resolve');
        $this->ensurePromptVisible($actor, $prompt);
        if (blank($reason)) {
            throw ValidationException::withMessages(['reason' => ['Dismiss reason is required.']]);
        }

        $prompt->forceFill([
            'status' => 'dismissed',
            'dismissed_at' => now(),
            'dismissed_by' => $actor->id,
            'dismiss_reason' => $reason,
            'updated_by' => $actor->id,
        ])->save();
        $this->event($prompt, 'dismissed', $actor, $reason);

        return $prompt->fresh(['consultant', 'events']);
    }

    public function snooze(User $actor, SalesManagementPrompt $prompt, string $until, ?string $note = null): SalesManagementPrompt
    {
        $this->ensureCan($actor, 'sales.management.resolve');
        $this->ensurePromptVisible($actor, $prompt);

        $date = CarbonImmutable::parse($until, 'Africa/Nairobi')->endOfDay();
        if ($date->lte(now('Africa/Nairobi'))) {
            throw ValidationException::withMessages(['snoozed_until' => ['Snooze date must be in the future.']]);
        }

        $maxDate = now('Africa/Nairobi')->addDays($this->settings()['max_snooze_days'])->endOfDay();
        if ($date->gt($maxDate)) {
            throw ValidationException::withMessages(['snoozed_until' => ['Snooze date is beyond the configured maximum.']]);
        }

        $prompt->forceFill([
            'status' => 'snoozed',
            'snoozed_until' => $date,
            'updated_by' => $actor->id,
        ])->save();
        $this->event($prompt, 'snoozed', $actor, $note, ['snoozed_until' => $date->toDateTimeString()]);

        return $prompt->fresh(['consultant', 'events']);
    }

    /** @return array{created:int,updated:int,skipped:int} */
    private function generateOrderCyclePrompts(User $actor): array
    {
        $settings = $this->settings();
        $historyLimit = max(2, (int) $settings['cycle_history_orders']);
        $asOf = CarbonImmutable::now('Africa/Nairobi')->startOfDay();
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        foreach ($this->consultantCustomerPairs() as $pair) {
            $customer = AcumaticaCustomer::query()->where('acumatica_id', $pair['customer_id'])->first();
            $consultant = $this->resolveConsultant($pair['consultant_user_id'], $pair['rep_code']);
            if (! $customer || ! $consultant) {
                $stats['skipped']++;
                continue;
            }

            $dates = AcumaticaSalesOrder::query()
                ->salesOrdersOnly()
                ->where('customer_acumatica_id', $customer->acumatica_id)
                ->where(function (Builder $query) use ($consultant) {
                    $query->where('consultant_user_id', $consultant->id);
                    $rep = strtoupper(trim((string) $consultant->rep_code));
                    if ($rep !== '') {
                        $query->orWhere('sales_consultant_rep_code', $rep);
                    }
                })
                ->whereNotNull('order_date')
                ->orderByDesc('order_date')
                ->limit($historyLimit)
                ->pluck('order_date')
                ->map(fn ($date) => CarbonImmutable::parse($date, 'Africa/Nairobi')->startOfDay())
                ->unique(fn (CarbonImmutable $date) => $date->toDateString())
                ->values();

            if ($dates->count() < 2) {
                $stats['skipped']++;
                continue;
            }

            $ascending = $dates->sortBy(fn (CarbonImmutable $date) => $date->timestamp)->values();
            $gaps = [];
            for ($i = 1; $i < $ascending->count(); $i++) {
                $gaps[] = max(1, $ascending[$i - 1]->diffInDays($ascending[$i]));
            }

            $expected = (int) round($this->median($gaps));
            $lastOrder = $dates->first();
            $daysSince = $lastOrder->diffInDays($asOf);
            $dueAfter = $expected * (float) $settings['cycle_due_multiplier'];
            $overdueAfter = $expected * (float) $settings['cycle_overdue_multiplier'];
            if ($daysSince <= $dueAfter) {
                $stats['skipped']++;
                continue;
            }

            $severity = $daysSince > $overdueAfter ? 'overdue' : 'due';
            $dueDate = $lastOrder->addDays((int) ceil($dueAfter));
            $key = $this->idempotencyKey(SalesManagementPrompt::TYPE_ORDER_CYCLE, (string) $customer->acumatica_id, $consultant, $lastOrder->toDateString());
            $prompt = $this->upsertPrompt($key, [
                'prompt_type' => SalesManagementPrompt::TYPE_ORDER_CYCLE,
                'severity' => $severity,
                'period_key' => $lastOrder->toDateString(),
                'customer_acumatica_id' => $customer->acumatica_id,
                'customer_name' => $customer->name,
                'consultant_user_id' => $consultant->id,
                'consultant_rep_code' => strtoupper(trim((string) $consultant->rep_code)) ?: null,
                'consultant_name' => $consultant->name,
                'last_order_date' => $lastOrder->toDateString(),
                'expected_cycle_days' => $expected,
                'days_since_last_order' => $daysSince,
                'due_date' => $dueDate->toDateString(),
                'reason' => "Customer is {$daysSince} days from last SO against an expected {$expected}-day cycle.",
                'payload_json' => ['gap_days' => $gaps],
                'updated_by' => $actor->id,
            ], $actor);
            $stats[$prompt->wasRecentlyCreated ? 'created' : 'updated']++;
        }

        return $stats;
    }

    /** @return array{created:int,updated:int,skipped:int} */
    private function generateMonthGapPrompts(User $actor, ?string $period = null): array
    {
        $month = $period
            ? CarbonImmutable::parse($period.'-01', 'Africa/Nairobi')
            : CarbonImmutable::now('Africa/Nairobi')->subMonthNoOverflow();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $periodKey = $month->format('Y-m');
        $completedStatuses = array_map('strtolower', $this->settings()['month_gap_statuses']);
        $stats = ['created' => 0, 'updated' => 0, 'skipped' => 0];

        $rows = AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->leftJoin('acumatica_customers as c', 'c.acumatica_id', '=', 'acumatica_sales_orders.customer_acumatica_id')
            ->whereBetween('acumatica_sales_orders.order_date', [$from, $to])
            ->whereNotNull('acumatica_sales_orders.customer_acumatica_id')
            ->select([
                'acumatica_sales_orders.customer_acumatica_id',
                'acumatica_sales_orders.consultant_user_id',
                'acumatica_sales_orders.sales_consultant_rep_code as rep_code',
                DB::raw('MAX(COALESCE(c.name, acumatica_sales_orders.customer_name)) as customer_name'),
                DB::raw('COUNT(*) as order_count'),
                DB::raw('COALESCE(SUM(acumatica_sales_orders.order_total), 0) as order_value'),
                DB::raw("SUM(CASE WHEN acumatica_sales_orders.completed_at IS NULL THEN 1 ELSE 0 END) as open_orders"),
                DB::raw("MAX(acumatica_sales_orders.order_date) as last_order_date"),
                DB::raw("MAX(LOWER(COALESCE(acumatica_sales_orders.status, ''))) as sample_status"),
            ])
            ->groupBy(
                'acumatica_sales_orders.customer_acumatica_id',
                'acumatica_sales_orders.consultant_user_id',
                'acumatica_sales_orders.sales_consultant_rep_code',
            )
            ->get();

        foreach ($rows as $row) {
            $customer = AcumaticaCustomer::query()->where('acumatica_id', $row->customer_acumatica_id)->first();
            $consultant = $this->resolveConsultant($row->consultant_user_id ? (int) $row->consultant_user_id : null, $row->rep_code);
            if (! $customer || ! $consultant) {
                $stats['skipped']++;
                continue;
            }

            $sampleStatus = strtolower((string) $row->sample_status);
            $hasGap = (int) $row->open_orders > 0 || ! in_array($sampleStatus, $completedStatuses, true);
            if (! $hasGap) {
                $stats['skipped']++;
                continue;
            }

            $key = $this->idempotencyKey(SalesManagementPrompt::TYPE_NOT_BILLED, (string) $customer->acumatica_id, $consultant, $periodKey);
            $prompt = $this->upsertPrompt($key, [
                'prompt_type' => SalesManagementPrompt::TYPE_NOT_BILLED,
                'severity' => 'due',
                'period_key' => $periodKey,
                'customer_acumatica_id' => $customer->acumatica_id,
                'customer_name' => $customer->name,
                'consultant_user_id' => $consultant->id,
                'consultant_rep_code' => strtoupper(trim((string) $consultant->rep_code)) ?: null,
                'consultant_name' => $consultant->name,
                'source_from' => $from->toDateString(),
                'source_to' => $to->toDateString(),
                'last_order_date' => $row->last_order_date ? CarbonImmutable::parse($row->last_order_date)->toDateString() : null,
                'due_date' => CarbonImmutable::now('Africa/Nairobi')->toDateString(),
                'value_snapshot' => round((float) $row->order_value, 2),
                'order_count_snapshot' => (int) $row->order_count,
                'reason' => "Customer has {$row->order_count} SO(s) in {$periodKey} with local completion/billing gap signals.",
                'payload_json' => ['open_orders' => (int) $row->open_orders, 'sample_status' => $sampleStatus],
                'updated_by' => $actor->id,
            ], $actor);
            $stats[$prompt->wasRecentlyCreated ? 'created' : 'updated']++;
        }

        return $stats;
    }

    /** @param array<string, mixed> $values */
    private function upsertPrompt(string $key, array $values, User $actor): SalesManagementPrompt
    {
        $prompt = SalesManagementPrompt::query()->where('idempotency_key', $key)->first();
        if ($prompt && in_array($prompt->status, ['resolved', 'dismissed'], true)) {
            $this->event($prompt, 'regeneration_skipped_terminal', $actor);
            return $prompt;
        }

        $prompt = SalesManagementPrompt::updateOrCreate(
            ['idempotency_key' => $key],
            [
                'status' => $prompt?->status === 'snoozed' ? 'snoozed' : 'open',
                'created_by' => $prompt?->created_by ?? $actor->id,
                ...$values,
            ],
        );
        $this->event($prompt, $prompt->wasRecentlyCreated ? 'generated' : 'regenerated', $actor);

        return $prompt;
    }

    private function applyActorScope(Builder $query, User $actor): Builder
    {
        if ($this->canSeeAll($actor)) {
            return $query;
        }

        if ($this->isHod($actor)) {
            $ids = $this->orgTree->descendantIds($actor->id, false);
            return $query->whereIn('consultant_user_id', $ids === [] ? [-1] : $ids);
        }

        $rep = strtoupper(trim((string) $actor->rep_code));
        return $query->where(function (Builder $scoped) use ($actor, $rep) {
            $scoped->where('consultant_user_id', $actor->id);
            if ($rep !== '') {
                $scoped->orWhere('consultant_rep_code', $rep);
            }
            $ids = DataScope::scopedCustomerAcumaticaIds($actor);
            if ($ids !== null && $ids !== []) {
                $scoped->orWhereIn('customer_acumatica_id', $ids);
            }
        });
    }

    private function ensurePromptVisible(User $actor, SalesManagementPrompt $prompt): void
    {
        if (! $this->listQuery($actor)->whereKey($prompt->id)->exists()) {
            abort(403, 'Forbidden.');
        }
    }

    private function isAllowedAudience(User $user): bool
    {
        return $this->canSeeAll($user)
            || $this->isHod($user)
            || $this->isSalesConsultant($user);
    }

    private function canSeeAll(User $user): bool
    {
        return (bool) $user->is_super_admin
            || $user->role === 'Administrator'
            || $user->org_level === 'c_suite'
            || $user->department_role === 'executive';
    }

    private function isHod(User $user): bool
    {
        return $user->org_level === 'hod' || $user->department_role === 'hod';
    }

    private function isSalesConsultant(User $user): bool
    {
        return $user->role === 'Sales Consultant' || (bool) $user->is_consultant;
    }

    private function resolveConsultant(?int $userId, ?string $repCode): ?User
    {
        if ($userId) {
            $user = User::query()->whereKey($userId)->where('is_active', true)->first();
            if ($user && $this->isSalesConsultant($user)) {
                return $user;
            }
        }

        $rep = strtoupper(trim((string) $repCode));
        if ($rep === '') {
            return null;
        }

        $matches = User::query()
            ->where('is_active', true)
            ->where('rep_code', $rep)
            ->where(function (Builder $query) {
                $query->where('role', 'Sales Consultant')->orWhere('is_consultant', true);
            })
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /** @return Collection<int, array{customer_id:string,consultant_user_id:?int,rep_code:?string}> */
    private function consultantCustomerPairs(): Collection
    {
        return AcumaticaSalesOrder::query()
            ->salesOrdersOnly()
            ->whereNotNull('customer_acumatica_id')
            ->where(function (Builder $query) {
                $query->whereNotNull('consultant_user_id')
                    ->orWhereNotNull('sales_consultant_rep_code');
            })
            ->select([
                'customer_acumatica_id',
                'consultant_user_id',
                'sales_consultant_rep_code',
            ])
            ->groupBy('customer_acumatica_id', 'consultant_user_id', 'sales_consultant_rep_code')
            ->get()
            ->map(fn ($row) => [
                'customer_id' => (string) $row->customer_acumatica_id,
                'consultant_user_id' => $row->consultant_user_id ? (int) $row->consultant_user_id : null,
                'rep_code' => $row->sales_consultant_rep_code ? strtoupper(trim((string) $row->sales_consultant_rep_code)) : null,
            ]);
    }

    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }

    private function idempotencyKey(string $type, string $customerId, User $consultant, string $period): string
    {
        $rep = strtoupper(trim((string) $consultant->rep_code));

        return implode('|', [$type, strtoupper($customerId), $consultant->id ?: $rep, $period]);
    }

    /** @return array<string, mixed> */
    private function salesOrderSyncFreshness(): array
    {
        $latest = AcumaticaSyncLog::query()
            ->whereIn('sync_type', ['sales_orders', 'customer_orders'])
            ->whereIn('status', ['success', 'completed'])
            ->latest('ended_at')
            ->first();
        $thresholdHours = (int) $this->settings()['stale_so_sync_hours'];
        $last = $latest?->ended_at;
        $isStale = $last === null || $last->lt(now()->subHours($thresholdHours));

        return [
            'last_success_at' => $last?->toISOString(),
            'threshold_hours' => $thresholdHours,
            'is_stale' => $isStale,
            'message' => $isStale
                ? 'Sales order sync is stale; scheduled prompt generation should not run without Admin force.'
                : null,
        ];
    }

    private function event(SalesManagementPrompt $prompt, string $type, ?User $actor = null, ?string $comment = null, array $payload = []): void
    {
        SalesManagementPromptEvent::create([
            'sales_management_prompt_id' => $prompt->id,
            'event_type' => $type,
            'actor_user_id' => $actor?->id,
            'comment' => $comment,
            'payload_json' => $payload ?: null,
        ]);
    }
}
