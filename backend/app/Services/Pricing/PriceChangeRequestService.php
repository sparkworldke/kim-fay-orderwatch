<?php

namespace App\Services\Pricing;

use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\NotificationRule;
use App\Models\PriceChangeApprovalAction;
use App\Models\PriceChangeApprovalStage;
use App\Models\PriceChangeEvent;
use App\Models\PriceChangeRequest;
use App\Models\PriceChangeSetting;
use App\Models\User;
use App\Support\DataScope;
use App\Support\FrontendUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PriceChangeRequestService
{
    /** @return array<string, mixed> */
    public function settings(): array
    {
        $stored = PriceChangeSetting::query()
            ->get(['key', 'value_json'])
            ->mapWithKeys(fn (PriceChangeSetting $setting) => [$setting->key => $setting->value_json])
            ->all();

        return [
            'margin_floor_pct' => (float) ($stored['margin_floor_pct'] ?? 15),
            'erp_updater_roles' => $stored['erp_updater_roles'] ?? ['Sales Operations'],
            'erp_updater_emails' => $stored['erp_updater_emails'] ?? [],
            'mail_from_address' => $stored['mail_from_address'] ?? 'pricing@fayshop.co.ke',
            'mail_from_name' => $stored['mail_from_name'] ?? 'Price Change Approvals',
            'allow_admin_testing_override' => (bool) ($stored['allow_admin_testing_override'] ?? true),
        ];
    }

    /** @param array<string, mixed> $settings */
    public function saveSettings(array $settings): array
    {
        foreach ([
            'margin_floor_pct',
            'erp_updater_roles',
            'erp_updater_emails',
            'mail_from_address',
            'mail_from_name',
            'allow_admin_testing_override',
        ] as $key) {
            if (array_key_exists($key, $settings)) {
                PriceChangeSetting::updateOrCreate(['key' => $key], ['value_json' => $settings[$key]]);
            }
        }

        return $this->settings();
    }

    public function ensureCan(User $user, string $permission): void
    {
        if (! $user->hasPermission($permission)) {
            abort(403, 'Forbidden.');
        }
    }

    public function listQuery(User $user, ?string $view = null): Builder
    {
        $query = PriceChangeRequest::query()
            ->with(['submitter:id,name,email,role', 'approvalActions', 'events'])
            ->latest('id');

        if ($view === 'my') {
            $query->where('submitted_by_user_id', $user->id);
        } elseif ($view === 'pending_approval') {
            $query->whereIn('status', ['submitted', 'in_approval']);
        } elseif ($view === 'pending_erp_apply') {
            $query->where('status', 'pending_erp_apply');
        }

        if ($this->canReadAll($user)) {
            return $query;
        }

        if ($view === 'pending_approval' && $user->hasPermission('pricing.pcr.approve')) {
            $stageKeys = $this->stageKeysForUser($user);
            return $query->whereIn('current_stage_key', $stageKeys === [] ? ['__none__'] : $stageKeys);
        }

        if ($view === 'pending_erp_apply' && $user->hasPermission('pricing.pcr.apply_erp')) {
            return $query;
        }

        $customerIds = DataScope::scopedCustomerAcumaticaIds($user);
        return $query->where(function (Builder $scoped) use ($user, $customerIds) {
            $scoped->where('submitted_by_user_id', $user->id);
            if ($customerIds !== null && $customerIds !== []) {
                $scoped->orWhereIn('customer_acumatica_id', $customerIds);
            }
        });
    }

    public function present(User $user, PriceChangeRequest $request): array
    {
        $request->loadMissing(['submitter:id,name,email,role', 'approvalActions', 'events']);
        $data = $request->toArray();
        $data['submitter'] = $request->submitter;
        $data['approval_actions'] = $request->approvalActions->values();
        $data['events'] = $request->events()->latest('id')->get();
        $data['current_stage'] = $request->current_stage_key
            ? PriceChangeApprovalStage::query()->where('key', $request->current_stage_key)->first()
            : null;
        $data['can_actor_approve'] = $this->canApproveStage($user, $request);
        $data['can_actor_apply_erp'] = $this->canApplyErp($user);
        $data['can_actor_ack_duplicate'] = $this->canApproveStage($user, $request);

        if (! $this->canViewMargin($user)) {
            unset($data['base_price_snapshot'], $data['margin_pct_snapshot'], $data['margin_kes_snapshot']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public function resolvePrice(User $actor, string $customerId, string $inventoryId, ?float $proposedPrice = null): array
    {
        $customer = $this->customerForActor($actor, $customerId);
        $item = AcumaticaInventoryItem::query()->where('inventory_id', $inventoryId)->firstOrFail();
        $this->ensureActiveInventory($item);

        $latestLine = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.customer_acumatica_id', $customer->acumatica_id)
            ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->where('l.inventory_id', $item->inventory_id)
            ->orderByDesc('o.order_date')
            ->orderByDesc('l.id')
            ->select(['l.unit_price', 'o.acumatica_order_nbr', 'o.order_date'])
            ->first();

        $current = $latestLine?->unit_price !== null
            ? (float) $latestLine->unit_price
            : (float) ($item->sales_price ?? 0);
        $base = $this->basePrice($item);
        $margin = $proposedPrice !== null ? $this->margin($base, $proposedPrice) : null;

        $data = [
            'customer' => [
                'acumatica_id' => $customer->acumatica_id,
                'name' => $customer->name,
                'customer_class' => $customer->customer_class,
                'payment_terms' => $customer->payment_terms,
            ],
            'inventory' => [
                'inventory_id' => $item->inventory_id,
                'description' => $item->description,
                'sales_price' => $item->sales_price,
            ],
            'current_selling_price' => round($current, 4),
            'current_price_source' => $latestLine ? 'latest_so_line' : 'inventory_sales_price',
            'source_order_nbr' => $latestLine?->acumatica_order_nbr,
            'source_order_date' => $latestLine?->order_date,
        ];

        if ($this->canViewMargin($actor)) {
            $data['base_price_snapshot'] = round($base, 4);
            if ($margin) {
                $data['margin_pct_snapshot'] = $margin['pct'];
                $data['margin_kes_snapshot'] = $margin['kes'];
            }
            $data['margin_floor_pct'] = $this->settings()['margin_floor_pct'];
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function create(User $actor, array $data): PriceChangeRequest
    {
        $this->ensureCan($actor, 'pricing.pcr.create');

        $customer = $this->customerForActor($actor, (string) $data['customer_acumatica_id']);
        $item = AcumaticaInventoryItem::query()->where('inventory_id', $data['inventory_id'])->firstOrFail();
        $this->ensureActiveInventory($item);
        $resolved = $this->resolvePrice($actor, (string) $customer->acumatica_id, (string) $item->inventory_id, (float) $data['proposed_selling_price']);
        $base = $this->basePrice($item);
        $margin = $this->margin($base, (float) $data['proposed_selling_price']);
        $stage = $this->firstStage();
        $duplicate = PriceChangeRequest::query()
            ->where('customer_acumatica_id', $customer->acumatica_id)
            ->where('inventory_id', $item->inventory_id)
            ->where('created_at', '>=', now()->subHours(48))
            ->whereNotIn('status', ['rejected', 'applied_erp'])
            ->exists();

        return DB::transaction(function () use ($actor, $data, $customer, $item, $resolved, $base, $margin, $stage, $duplicate): PriceChangeRequest {
            $request = PriceChangeRequest::create([
                'public_ref' => $this->nextPublicRef(),
                'customer_acumatica_id' => $customer->acumatica_id,
                'customer_name' => $customer->name,
                'customer_price_class' => $customer->customer_class,
                'customer_payment_terms' => $customer->payment_terms,
                'inventory_id' => $item->inventory_id,
                'product_description' => $item->description,
                'current_selling_price' => $resolved['current_selling_price'],
                'proposed_selling_price' => $data['proposed_selling_price'],
                'base_price_snapshot' => $base,
                'margin_pct_snapshot' => $margin['pct'],
                'margin_kes_snapshot' => $margin['kes'],
                'currency_id' => $data['currency_id'] ?? 'KES',
                'justification' => $data['justification'],
                'effective_date_requested' => $data['effective_date_requested'] ?? null,
                'status' => 'submitted',
                'current_stage_key' => $stage?->key,
                'submitted_by_user_id' => $actor->id,
                'duplicate_ack_required' => $duplicate,
                'submitted_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $this->event($request, 'submitted', $actor, null, [
                'stage' => $stage?->key,
                'duplicate_ack_required' => $duplicate,
            ]);
            $this->notify($request, 'PCR-P1', 'Price change request submitted', $this->stageRecipients($stage), [
                'stage' => $stage?->name,
            ]);

            return $request->fresh(['approvalActions', 'events']);
        });
    }

    public function decide(User $actor, PriceChangeRequest $request, string $decision, string $comment): PriceChangeRequest
    {
        $this->ensureCan($actor, 'pricing.pcr.approve');

        if (blank($comment)) {
            throw ValidationException::withMessages(['comment' => ['Comment is required.']]);
        }
        if (! in_array($request->status, ['submitted', 'in_approval'], true)) {
            throw ValidationException::withMessages(['status' => ['Request is not pending approval.']]);
        }
        if (! $this->canApproveStage($actor, $request)) {
            abort(403, 'Forbidden.');
        }
        if ($decision === 'approved' && $request->duplicate_ack_required && ! $request->duplicate_acked_at && ! $this->isAdministrator($actor)) {
            throw ValidationException::withMessages(['duplicate' => ['Acknowledge the duplicate warning before approval.']]);
        }

        return DB::transaction(function () use ($actor, $request, $decision, $comment): PriceChangeRequest {
            PriceChangeApprovalAction::create([
                'price_change_request_id' => $request->id,
                'stage_key' => (string) $request->current_stage_key,
                'actor_user_id' => $actor->id,
                'decision' => $decision,
                'comment' => $comment,
                'margin_seen_pct' => $this->canViewMargin($actor) ? $request->margin_pct_snapshot : null,
                'decided_at' => now(),
            ]);

            if ($decision === 'rejected') {
                $request->forceFill(['status' => 'rejected', 'decided_at' => now(), 'updated_by' => $actor->id])->save();
                $this->event($request, 'rejected', $actor, $comment);
                $this->notify($request, 'PCR-P4', 'Price change request rejected', $this->consultantRecipients($request), ['comment' => $comment]);
                return $request->fresh(['approvalActions', 'events']);
            }

            $next = $this->nextStage((string) $request->current_stage_key);
            if ($next) {
                $request->forceFill(['status' => 'in_approval', 'current_stage_key' => $next->key, 'updated_by' => $actor->id])->save();
                $this->event($request, 'stage_approved', $actor, $comment, ['next_stage' => $next->key]);
                $this->notify($request, 'PCR-P2', 'Price change approval stage completed', $this->consultantRecipients($request), ['comment' => $comment]);
                $this->notify($request, 'PCR-P2', 'Price change request pending next approval', $this->stageRecipients($next), ['stage' => $next->name, 'comment' => $comment]);
                return $request->fresh(['approvalActions', 'events']);
            }

            $request->forceFill([
                'status' => 'pending_erp_apply',
                'current_stage_key' => 'done',
                'decided_at' => now(),
                'acumatica_apply_notified_at' => now(),
                'updated_by' => $actor->id,
            ])->save();
            $this->event($request, 'final_approved', $actor, $comment);
            $this->notify($request, 'PCR-P3', 'Price change approved - pending ERP update', $this->erpUpdaterRecipients(), ['comment' => $comment]);

            return $request->fresh(['approvalActions', 'events']);
        });
    }

    public function acknowledgeDuplicate(User $actor, PriceChangeRequest $request): PriceChangeRequest
    {
        if (! $this->canApproveStage($actor, $request)) {
            abort(403, 'Forbidden.');
        }

        $request->forceFill([
            'duplicate_acked_by' => $actor->id,
            'duplicate_acked_at' => now(),
            'updated_by' => $actor->id,
        ])->save();
        $this->event($request, 'duplicate_acknowledged', $actor);

        return $request->fresh(['approvalActions', 'events']);
    }

    public function markAppliedErp(User $actor, PriceChangeRequest $request): PriceChangeRequest
    {
        if (! $this->canApplyErp($actor)) {
            abort(403, 'Forbidden.');
        }
        if ($request->status !== 'pending_erp_apply') {
            throw ValidationException::withMessages(['status' => ['Only final approved requests can be marked applied in ERP.']]);
        }

        $request->forceFill([
            'status' => 'applied_erp',
            'acumatica_applied_at' => now(),
            'acumatica_applied_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();
        $this->event($request, 'applied_erp', $actor);
        $this->notify($request, 'PCR-P5', 'Price change marked applied in ERP', $this->consultantRecipients($request));

        return $request->fresh(['approvalActions', 'events']);
    }

    /** @return array<string, int> */
    public function dashboard(User $actor): array
    {
        $base = $this->listQuery($actor);

        return [
            'total' => (clone $base)->count(),
            'pending_approval' => (clone $base)->whereIn('status', ['submitted', 'in_approval'])->count(),
            'pending_erp_apply' => (clone $base)->where('status', 'pending_erp_apply')->count(),
            'applied_erp' => (clone $base)->where('status', 'applied_erp')->count(),
            'duplicates' => (clone $base)->where('duplicate_ack_required', true)->whereNull('duplicate_acked_at')->count(),
        ];
    }

    /** @return Collection<int, PriceChangeApprovalStage> */
    public function stages(bool $activeOnly = true): Collection
    {
        $query = PriceChangeApprovalStage::query()->orderBy('sort_order')->orderBy('id');
        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function event(PriceChangeRequest $request, string $eventType, ?User $actor = null, ?string $comment = null, array $payload = []): void
    {
        PriceChangeEvent::create([
            'price_change_request_id' => $request->id,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'comment' => $comment,
            'payload_json' => $payload ?: null,
        ]);
    }

    private function customerForActor(User $actor, string $customerId): AcumaticaCustomer
    {
        $customer = AcumaticaCustomer::query()->where('acumatica_id', $customerId)->firstOrFail();
        if (! DataScope::customerAccessible($actor, (string) $customer->acumatica_id, $customer->customer_class)) {
            abort(403, 'Customer is outside your accessible portfolio.');
        }

        return $customer;
    }

    private function basePrice(AcumaticaInventoryItem $item): float
    {
        foreach (['last_cost', 'average_cost', 'sales_price'] as $field) {
            $value = (float) ($item->{$field} ?? 0);
            if ($value > 0) {
                return $value;
            }
        }

        return 0.0;
    }

    private function ensureActiveInventory(AcumaticaInventoryItem $item): void
    {
        $status = strtoupper((string) $item->item_status);
        if ($status !== '' && str_contains($status, 'INACTIVE')) {
            throw ValidationException::withMessages(['inventory_id' => ['Select an active inventory item.']]);
        }
    }

    /** @return array{pct: float, kes: float} */
    private function margin(float $base, float $selling): array
    {
        $kes = $selling - $base;

        return [
            'pct' => $selling > 0 ? round(($kes / $selling) * 100, 4) : 0.0,
            'kes' => round($kes, 4),
        ];
    }

    private function firstStage(): ?PriceChangeApprovalStage
    {
        return $this->stages()->first();
    }

    private function nextStage(string $stageKey): ?PriceChangeApprovalStage
    {
        $current = $this->stages()->firstWhere('key', $stageKey);
        if (! $current) {
            return null;
        }

        return $this->stages()->first(fn (PriceChangeApprovalStage $stage) => $stage->sort_order > $current->sort_order);
    }

    private function canReadAll(User $user): bool
    {
        return $this->isAdministrator($user)
            || $user->hasPermission('pricing.pcr.config')
            || $user->hasPermission('pricing.pcr.approve_escalated');
    }

    private function canViewMargin(User $user): bool
    {
        return $this->isAdministrator($user) || $user->hasPermission('pricing.pcr.view_margin');
    }

    private function canApplyErp(User $user): bool
    {
        return $this->isAdministrator($user) || $user->hasPermission('pricing.pcr.apply_erp');
    }

    private function canApproveStage(User $user, PriceChangeRequest $request): bool
    {
        if (! $user->hasPermission('pricing.pcr.approve')) {
            return false;
        }
        if (! in_array($request->status, ['submitted', 'in_approval'], true)) {
            return false;
        }
        if ($this->isAdministrator($user) && $this->settings()['allow_admin_testing_override']) {
            return true;
        }

        return in_array((string) $request->current_stage_key, $this->stageKeysForUser($user), true);
    }

    private function isAdministrator(User $user): bool
    {
        return $user->role === 'Administrator' || (bool) $user->is_super_admin;
    }

    /** @return list<string> */
    private function stageKeysForUser(User $user): array
    {
        $roleNames = $user->roles()->pluck('roles.name')->push($user->role)->filter()->unique()->values()->all();

        return $this->stages()
            ->filter(function (PriceChangeApprovalStage $stage) use ($user, $roleNames): bool {
                $users = array_map('intval', $stage->user_ids ?? []);
                if (in_array((int) $user->id, $users, true)) {
                    return true;
                }

                return count(array_intersect($roleNames, $stage->role_names ?? [])) > 0;
            })
            ->pluck('key')
            ->values()
            ->all();
    }

    /** @return list<string> */
    private function stageRecipients(?PriceChangeApprovalStage $stage): array
    {
        if (! $stage) {
            return [];
        }

        $emails = [];
        if (($stage->user_ids ?? []) !== []) {
            $emails = User::query()->whereIn('id', $stage->user_ids)->where('is_active', true)->pluck('email')->all();
        }
        if (($stage->role_names ?? []) !== []) {
            $roleEmails = User::query()
                ->where('is_active', true)
                ->where(function ($query) use ($stage) {
                    $query->whereIn('role', $stage->role_names)
                        ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', $stage->role_names));
                })
                ->pluck('email')
                ->all();
            $emails = [...$emails, ...$roleEmails];
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /** @return list<string> */
    private function consultantRecipients(PriceChangeRequest $request): array
    {
        return array_values(array_filter([
            User::query()->whereKey($request->submitted_by_user_id)->value('email'),
        ]));
    }

    /** @return list<string> */
    private function erpUpdaterRecipients(): array
    {
        $settings = $this->settings();
        $roles = $settings['erp_updater_roles'] ?? [];
        $emails = $settings['erp_updater_emails'] ?? [];

        if ($roles !== []) {
            $roleEmails = User::query()
                ->where('is_active', true)
                ->where(function ($query) use ($roles) {
                    $query->whereIn('role', $roles)
                        ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', $roles));
                })
                ->pluck('email')
                ->all();
            $emails = [...$emails, ...$roleEmails];
        }

        return array_values(array_unique(array_filter($emails)));
    }

    /** @param list<string> $recipients */
    private function notify(PriceChangeRequest $request, string $ruleKey, string $subject, array $recipients, array $context = []): void
    {
        $ruleRecipients = NotificationRule::query()->where('rule_key', $ruleKey)->first()?->configuredRecipientEmails() ?? [];
        $recipients = array_values(array_unique(array_filter([...$recipients, ...$ruleRecipients])));

        $sent = [];
        $failed = [];
        foreach ($recipients as $recipient) {
            try {
                Mail::html($this->mailHtml($request, $ruleKey, $context), function ($message) use ($recipient, $subject) {
                    $settings = $this->settings();
                    $message->to($recipient)
                        ->from((string) $settings['mail_from_address'], (string) $settings['mail_from_name'])
                        ->subject($subject);
                });
                $sent[] = $recipient;
            } catch (\Throwable $e) {
                // Do not roll back PCR create/approve when SMTP is misconfigured.
                $failed[] = ['email' => $recipient, 'error' => $e->getMessage()];
                report($e);
            }
        }

        $this->event($request, 'notification_sent', null, null, [
            'rule_key' => $ruleKey,
            'subject' => $subject,
            'to' => $sent,
            'failed' => $failed,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function mailHtml(PriceChangeRequest $request, string $ruleKey, array $context = []): string
    {
        $link = e(FrontendUrl::path('/app/price-change-requests/'.$request->id));
        $comment = isset($context['comment']) ? '<p><strong>Comment:</strong> '.nl2br(e((string) $context['comment'])).'</p>' : '';

        return '<div style="font-family:Arial,Helvetica,sans-serif">'
            .'<h2>'.e($request->public_ref).' - Price Change Request</h2>'
            .'<p><strong>Rule:</strong> '.e($ruleKey).'</p>'
            .'<p><strong>Customer:</strong> '.e($request->customer_name).' ('.e($request->customer_acumatica_id).')</p>'
            .'<p><strong>SKU:</strong> '.e($request->inventory_id).' - '.e((string) $request->product_description).'</p>'
            .'<p><strong>Current:</strong> '.e((string) $request->current_selling_price).' | <strong>Proposed:</strong> '.e((string) $request->proposed_selling_price).'</p>'
            .'<p><strong>Justification:</strong><br>'.nl2br(e($request->justification)).'</p>'
            .$comment
            .'<p><a href="'.$link.'">Open in OrderWatch</a></p>'
            .'</div>';
    }

    private function nextPublicRef(): string
    {
        $year = now('Africa/Nairobi')->format('Y');
        $count = PriceChangeRequest::query()->where('public_ref', 'like', "PCR-{$year}-%")->count() + 1;

        return sprintf('PCR-%s-%06d', $year, $count);
    }
}
