<?php

namespace App\Services\Fol;

use App\Mail\FolRequestMail;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\FolApprovalAction;
use App\Models\FolApprovalStage;
use App\Models\FolRequest;
use App\Models\FolRequestEvent;
use App\Models\FolSoLink;
use App\Models\Role;
use App\Models\User;
use App\Support\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class FolRequestService
{
    private const TERMINAL_STATUSES = ['rejected', 'approved_final', 'ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled'];

    public function __construct(
        private readonly FolSettingsService $settings,
    ) {}

    public function userCan(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    public function ensureCan(User $user, string $permission): void
    {
        if (! $this->userCan($user, $permission)) {
            abort(403, 'Forbidden.');
        }
    }

    public function listQuery(User $user, ?string $view = null): Builder
    {
        $query = FolRequest::query()
            ->with(['lines', 'attachments', 'approvalActions', 'soLinks', 'assignedTechnician'])
            ->latest('id');

        if ($view === 'pending_approval') {
            $query->whereIn('status', ['submitted', 'in_approval']);
        } elseif ($view === 'ready_for_invoicing') {
            $query->whereIn('status', ['ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled']);
        } elseif ($view === 'my') {
            $query->where('sales_consultant_user_id', $user->id);
        } elseif ($view === 'my_allocations') {
            // Technician open work: assigned to them, not yet fulfilled/rejected
            $query->where('assigned_technician_user_id', $user->id)
                ->whereNotIn('status', ['rejected', 'fulfilled']);
        } elseif ($view === 'my_resolved') {
            $query->where('assigned_technician_user_id', $user->id)
                ->where('status', 'fulfilled');
        }

        if ($this->canReadAll($user)) {
            return $query;
        }

        // Technicians: always scoped to FOLs assigned to them (plus any portfolio if multi-role)
        if ($this->userCan($user, 'kp.fol.install.execute') && in_array($view, ['my_allocations', 'my_resolved', null, ''], true)) {
            if (in_array($view, ['my_allocations', 'my_resolved'], true)) {
                return $query; // already filtered by assignment above
            }
        }

        if ($this->userCan($user, 'kp.fol.approve') && $view === 'pending_approval') {
            $stageKeys = $this->stageKeysForUser($user);
            return $query->whereIn('current_stage_key', $stageKeys === [] ? ['__none__'] : $stageKeys);
        }

        if ($this->userCan($user, 'kp.fol.invoice') && $view === 'ready_for_invoicing') {
            return $query;
        }

        $customerIds = DataScope::scopedCustomerAcumaticaIds($user);
        $isTech = $this->userCan($user, 'kp.fol.install.execute');

        return $query->where(function (Builder $scoped) use ($user, $customerIds, $isTech) {
            $scoped->where('sales_consultant_user_id', $user->id);
            if ($customerIds !== null && $customerIds !== []) {
                $scoped->orWhereIn('customer_acumatica_id', $customerIds);
            }
            // Technicians always see FOLs allocated to them
            if ($isTech) {
                $scoped->orWhere('assigned_technician_user_id', $user->id);
            }
        });
    }

    public function present(FolRequest $request): array
    {
        $request->loadMissing(['lines', 'attachments', 'events', 'approvalActions', 'soLinks', 'assignedTechnician']);

        return [
            ...$request->toArray(),
            'lines' => $request->lines->values(),
            'attachments' => $request->attachments->values(),
            'events' => $request->events()->latest('id')->get(),
            'approval_actions' => $request->approvalActions->values(),
            'so_links' => $request->soLinks->values(),
            'assigned_technician' => $request->assignedTechnician ? [
                'id' => $request->assignedTechnician->id,
                'name' => $request->assignedTechnician->name,
                'email' => $request->assignedTechnician->email,
                'role' => $request->assignedTechnician->role,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createDraft(User $actor, array $data): FolRequest
    {
        $this->ensureCan($actor, 'kp.fol.request');

        $customer = AcumaticaCustomer::query()
            ->where('acumatica_id', $data['customer_acumatica_id'])
            ->firstOrFail();

        $this->ensureCustomerAllowed($actor, $customer);
        $this->ensureKpCustomer($customer);
        $this->ensureFolLines($data['lines']);

        return DB::transaction(function () use ($actor, $data, $customer): FolRequest {
            $metrics = $this->metricsForCustomer((string) $customer->acumatica_id);
            $source = $data['consumables_metrics_source'] ?? 'system_so';

            $request = FolRequest::create([
                'public_ref' => $this->nextPublicRef(),
                'customer_acumatica_id' => $customer->acumatica_id,
                'customer_name' => $customer->name,
                'sales_consultant_user_id' => $actor->id,
                'sales_consultant_email' => $actor->email,
                'sales_consultant_rep_code' => $actor->rep_code,
                'request_origin' => $data['request_origin'],
                'request_origin_other' => $data['request_origin_other'] ?? null,
                'requestor_first_name' => $data['requestor_first_name'],
                'requestor_last_name' => $data['requestor_last_name'],
                'requestor_phone' => $data['requestor_phone'],
                'requestor_email' => $data['requestor_email'],
                'issue_types' => $data['issue_types'],
                'reason_text' => $data['reason_text'],
                'installation_required' => (bool) ($data['installation_required'] ?? false),
                'installation_location' => $data['installation_location'] ?? null,
                'customer_has_submitted_po' => (bool) ($data['customer_has_submitted_po'] ?? false),
                'consumables_last_purchase_date' => $data['consumables_last_purchase_date'] ?? $metrics['last_purchase_date'],
                'consumables_sales_6m_kes' => $data['consumables_sales_6m_kes'] ?? $metrics['sales_6m_kes'],
                'consumables_volume_6m' => $data['consumables_volume_6m'] ?? $metrics['volume_6m'],
                'consumables_metrics_source' => $source,
                'consumables_override_reason' => $data['consumables_override_reason'] ?? null,
                'debt_explanation' => $data['debt_explanation'],
                'status' => 'draft',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach (array_values($data['lines']) as $index => $line) {
                $item = AcumaticaInventoryItem::query()->where('inventory_id', $line['inventory_id'])->firstOrFail();
                $prior = $this->priorIssued((string) $customer->acumatica_id, (string) $line['inventory_id']);
                $request->lines()->create([
                    'line_no' => $index + 1,
                    'inventory_id' => $item->inventory_id,
                    'product_description' => $item->description,
                    'qty_requested' => $line['qty_requested'],
                    'qty_previously_issued' => $line['qty_previously_issued'] ?? $prior['qty'],
                    'date_last_issue' => $line['date_last_issue'] ?? $prior['date'],
                    'previous_source' => 'prior_fol',
                    'commitment_sku_ids' => $line['commitment_sku_ids'] ?? null,
                ]);
            }

            $this->event($request, 'draft_created', $actor, null, ['source' => 'api']);

            return $request->fresh(['lines', 'attachments']);
        });
    }

    public function submit(User $actor, FolRequest $request): FolRequest
    {
        if ($request->sales_consultant_user_id !== $actor->id && ! $this->canReadAll($actor)) {
            abort(403, 'Forbidden.');
        }

        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft requests can be submitted.']]);
        }

        $request->loadMissing(['lines', 'attachments']);
        if ($request->lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => ['At least one FOL line is required.']]);
        }
        if ($this->settings->requireAttachment() && $request->attachments->isEmpty()) {
            throw ValidationException::withMessages(['attachments' => ['At least one attachment is required.']]);
        }
        if ($request->consumables_metrics_source === 'manual_override' && blank($request->consumables_override_reason)) {
            throw ValidationException::withMessages(['consumables_override_reason' => ['Override reason is required.']]);
        }

        return DB::transaction(function () use ($actor, $request): FolRequest {
            $stage = $this->firstStage();
            $request->forceFill([
                'status' => 'submitted',
                'current_stage_key' => $stage?->key,
                'submitted_at' => now(),
                'form_json' => $request->fresh(['lines', 'attachments'])->toArray(),
            ])->save();

            $this->event($request, 'submitted', $actor, null, ['stage' => $stage?->key]);
            $this->notifyStage($request, $stage, 'N1', 'FOL request pending approval');

            return $request->fresh(['lines', 'attachments', 'events']);
        });
    }

    public function decide(User $actor, FolRequest $request, string $decision, string $comment): FolRequest
    {
        $this->ensureCan($actor, 'kp.fol.approve');

        if (blank($comment)) {
            throw ValidationException::withMessages(['comment' => ['Comment is required.']]);
        }
        if (! in_array($request->status, ['submitted', 'in_approval'], true)) {
            throw ValidationException::withMessages(['status' => ['Request is not pending approval.']]);
        }
        if (! $this->stageAllowsUser((string) $request->current_stage_key, $actor)) {
            abort(403, 'Forbidden.');
        }

        return DB::transaction(function () use ($actor, $request, $decision, $comment): FolRequest {
            FolApprovalAction::create([
                'fol_request_id' => $request->id,
                'stage_key' => (string) $request->current_stage_key,
                'actor_user_id' => $actor->id,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => now(),
            ]);

            if ($decision === 'rejected') {
                $request->forceFill([
                    'status' => 'rejected',
                    'decided_at' => now(),
                ])->save();
                $this->event($request, 'rejected', $actor, $comment);
                $this->notifyConsultant($request, 'N6', 'FOL request rejected', ['comment' => $comment]);
                return $request->fresh(['lines', 'attachments', 'events', 'approvalActions']);
            }

            $nextStage = $this->nextStage((string) $request->current_stage_key);
            if ($nextStage) {
                $request->forceFill([
                    'status' => 'in_approval',
                    'current_stage_key' => $nextStage->key,
                ])->save();
                $this->event($request, 'stage_approved', $actor, $comment, ['next_stage' => $nextStage->key]);
                $this->notifyConsultant($request, 'N2', 'FOL request approved by current stage', ['comment' => $comment]);
                $this->notifyStage($request, $nextStage, 'N3', 'FOL request pending final approval', ['comment' => $comment]);
                return $request->fresh(['lines', 'attachments', 'events', 'approvalActions']);
            }

            $request->forceFill([
                'status' => 'ready_for_invoicing',
                'current_stage_key' => 'done',
                'decided_at' => now(),
            ])->save();
            $this->event($request, 'final_approved', $actor, $comment);
            $this->notifyConsultant($request, 'N4', 'FOL request fully approved', ['comment' => $comment]);
            $this->notifyInvoicing($request, 'N5', 'FOL request approved for invoicing', ['comment' => $comment]);

            return $request->fresh(['lines', 'attachments', 'events', 'approvalActions']);
        });
    }

    public function linkSalesOrder(User $actor, FolRequest $request, string $orderNbr): FolRequest
    {
        $this->ensureCan($actor, 'kp.fol.invoice');

        if (! in_array($request->status, ['ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled'], true)) {
            throw ValidationException::withMessages(['status' => ['Request is not ready for SO linking.']]);
        }

        $order = AcumaticaSalesOrder::query()
            ->where('acumatica_order_nbr', $orderNbr)
            ->salesOrdersOnly()
            ->firstOrFail();

        if ($order->customer_acumatica_id !== $request->customer_acumatica_id) {
            throw ValidationException::withMessages(['acumatica_order_nbr' => ['Sales order customer does not match the FOL customer.']]);
        }

        return DB::transaction(function () use ($actor, $request, $order, $orderNbr): FolRequest {
            FolSoLink::firstOrCreate([
                'fol_request_id' => $request->id,
                'acumatica_order_nbr' => $orderNbr,
            ], [
                'sales_order_id' => $order->id,
                'link_type' => 'invoice',
                'matched_at' => now(),
                'matched_by' => $actor->id,
            ]);

            $links = $request->soLinks()->pluck('acumatica_order_nbr')->push($orderNbr)->unique()->values()->all();
            $request->forceFill([
                'status' => $request->status === 'ready_for_invoicing' ? 'so_linked' : $request->status,
                'linked_so_order_nbrs' => $links,
                'linked_so_status_summary' => (string) $order->status,
            ])->save();

            $this->event($request, 'so_linked', $actor, null, [
                'acumatica_order_nbr' => $orderNbr,
                'sales_order_status' => $order->status,
            ]);

            return $request->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks']);
        });
    }

    public function assignTechnician(User $actor, FolRequest $request, int $technicianId): FolRequest
    {
        if (! $this->isAdministrator($actor) && ! $this->userCan($actor, 'kp.fol.install.manage')) {
            abort(403, 'Forbidden.');
        }

        $technician = $this->technicianQuery()->whereKey($technicianId)->first();
        if (! $technician) {
            throw ValidationException::withMessages(['technician_user_id' => ['Select an active Technician user.']]);
        }

        return DB::transaction(function () use ($actor, $request, $technician): FolRequest {
            $request->forceFill([
                'assigned_technician_user_id' => $technician->id,
                'technician_assigned_by' => $actor->id,
                'technician_assigned_at' => now(),
            ])->save();

            $this->event($request, 'technician_assigned', $actor, null, [
                'technician_user_id' => $technician->id,
                'technician_name' => $technician->name,
                'technician_email' => $technician->email,
            ]);

            return $request->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks', 'assignedTechnician']);
        });
    }

    public function matchPurchaseOrder(User $actor, FolRequest $request, string $poNumber): FolRequest
    {
        if (! $this->isAdministrator($actor)
            && ! $this->userCan($actor, 'kp.fol.invoice')
            && (int) $request->sales_consultant_user_id !== (int) $actor->id
            && ! DataScope::customerAccessible($actor, (string) $request->customer_acumatica_id)) {
            abort(403, 'Forbidden.');
        }

        if (! in_array($request->status, ['ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled'], true)) {
            throw ValidationException::withMessages(['status' => ['Request is not ready for PO matching.']]);
        }

        $normalizedPo = strtoupper(trim($poNumber));
        $orders = AcumaticaSalesOrder::query()
            ->where('customer_acumatica_id', $request->customer_acumatica_id)
            ->salesOrdersOnly()
            ->whereRaw('UPPER(TRIM(customer_order)) = ?', [$normalizedPo])
            ->orderByDesc('order_date')
            ->limit(5)
            ->get();

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages(['po_number' => ['No sales order matches this customer PO for the FOL customer.']]);
        }

        if ($orders->count() > 1) {
            $candidates = $orders->map(fn (AcumaticaSalesOrder $order): array => [
                'acumatica_order_nbr' => $order->acumatica_order_nbr,
                'order_date' => $order->order_date,
                'status' => $order->status,
            ])->values()->all();

            throw ValidationException::withMessages([
                'po_number' => ['Multiple sales orders match this PO. Choose the SO number instead.'],
                'candidates' => $candidates,
            ]);
        }

        $order = $orders->first();

        return DB::transaction(function () use ($actor, $request, $order, $normalizedPo): FolRequest {
            FolSoLink::updateOrCreate([
                'fol_request_id' => $request->id,
                'acumatica_order_nbr' => $order->acumatica_order_nbr,
            ], [
                'sales_order_id' => $order->id,
                'po_number' => $normalizedPo,
                'link_type' => 'po_match',
                'matched_at' => now(),
                'matched_by' => $actor->id,
            ]);

            $links = $request->soLinks()->pluck('acumatica_order_nbr')->push($order->acumatica_order_nbr)->unique()->values()->all();
            $request->forceFill([
                'status' => $request->status === 'ready_for_invoicing' ? 'so_linked' : $request->status,
                'linked_so_order_nbrs' => $links,
                'linked_so_status_summary' => (string) $order->status,
            ])->save();

            $this->event($request, 'po_matched', $actor, null, [
                'po_number' => $normalizedPo,
                'acumatica_order_nbr' => $order->acumatica_order_nbr,
                'sales_order_status' => $order->status,
            ]);

            return $request->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks', 'assignedTechnician']);
        });
    }

    public function technicians(User $actor): Collection
    {
        if (! $this->isAdministrator($actor) && ! $this->userCan($actor, 'kp.fol.install.manage')) {
            abort(403, 'Forbidden.');
        }

        return $this->technicianQuery()
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role']);
    }

    /**
     * Technician (or manager) calendar: allocations by account, open vs resolved counts.
     *
     * @return array{
     *   month: string,
     *   technician: array{id: int, name: string, email: string|null}|null,
     *   summary: array{
     *     allocated_open: int,
     *     resolved: int,
     *     total_assigned: int,
     *     distinct_accounts: int,
     *     resolved_this_month: int,
     *     open_this_month: int
     *   },
     *   days: list<array{date: string, open: int, resolved: int, items: list<array<string, mixed>>}>,
     *   accounts: list<array{customer_acumatica_id: string, customer_name: string, open: int, resolved: int, total: int}>,
     *   items: list<array<string, mixed>>
     * }
     */
    public function technicianCalendar(User $actor, string $month, ?int $technicianUserId = null): array
    {
        $canManage = $this->isAdministrator($actor) || $this->userCan($actor, 'kp.fol.install.manage');
        $canExecute = $this->userCan($actor, 'kp.fol.install.execute');

        if (! $canManage && ! $canExecute && ! $this->userCan($actor, 'kp.fol.view')) {
            abort(403, 'Forbidden.');
        }

        // Technicians always see only their own calendar; managers/admin may filter by tech.
        if ($canManage) {
            $targetId = $technicianUserId ?: (int) $actor->id;
        } elseif ($canExecute) {
            if ($technicianUserId !== null && $technicianUserId !== (int) $actor->id) {
                abort(403, 'Technicians can only view their own calendar.');
            }
            $targetId = (int) $actor->id;
        } else {
            // View-only managers without execute (e.g. report role) — self only unless manage
            $targetId = (int) $actor->id;
        }

        try {
            $start = Carbon::createFromFormat('Y-m', $month, 'Africa/Nairobi')->startOfMonth();
        } catch (\Throwable) {
            $start = now('Africa/Nairobi')->startOfMonth();
            $month = $start->format('Y-m');
        }
        $end = $start->copy()->endOfMonth();

        $tech = User::query()->find($targetId);

        // All assignments for summary (lifetime open/resolved)
        $allAssigned = FolRequest::query()
            ->where('assigned_technician_user_id', $targetId)
            ->with(['lines', 'soLinks'])
            ->orderByDesc('technician_assigned_at')
            ->orderByDesc('id')
            ->get();

        $resolvedStatuses = ['fulfilled'];
        $openAssigned = $allAssigned->filter(fn (FolRequest $r) => ! in_array($r->status, ['fulfilled', 'rejected'], true));
        $resolvedAssigned = $allAssigned->filter(fn (FolRequest $r) => in_array($r->status, $resolvedStatuses, true));

        // Calendar month items: based on assignment date (or decided_at fallback) in EAT
        $monthItems = $allAssigned->filter(function (FolRequest $r) use ($start, $end): bool {
            $anchor = $r->technician_assigned_at ?? $r->decided_at ?? $r->submitted_at ?? $r->created_at;
            if (! $anchor) {
                return false;
            }
            $day = Carbon::parse($anchor)->timezone('Africa/Nairobi');

            return $day->betweenIncluded($start, $end);
        });

        $items = $monthItems->map(fn (FolRequest $r) => $this->presentCalendarItem($r))->values()->all();

        // Group by calendar day
        $byDay = [];
        foreach ($monthItems as $r) {
            $anchor = $r->technician_assigned_at ?? $r->decided_at ?? $r->submitted_at ?? $r->created_at;
            $date = Carbon::parse($anchor)->timezone('Africa/Nairobi')->toDateString();
            if (! isset($byDay[$date])) {
                $byDay[$date] = ['date' => $date, 'open' => 0, 'resolved' => 0, 'items' => []];
            }
            $isResolved = $r->status === 'fulfilled';
            if ($isResolved) {
                $byDay[$date]['resolved']++;
            } elseif ($r->status !== 'rejected') {
                $byDay[$date]['open']++;
            }
            $byDay[$date]['items'][] = $this->presentCalendarItem($r);
        }
        ksort($byDay);

        // Accounts rollup (all assigned, not only this month)
        $accounts = $allAssigned
            ->groupBy('customer_acumatica_id')
            ->map(function (Collection $group, string $customerId): array {
                /** @var FolRequest $first */
                $first = $group->first();
                $open = $group->filter(fn (FolRequest $r) => ! in_array($r->status, ['fulfilled', 'rejected'], true))->count();
                $resolved = $group->filter(fn (FolRequest $r) => $r->status === 'fulfilled')->count();

                return [
                    'customer_acumatica_id' => $customerId,
                    'customer_name' => (string) $first->customer_name,
                    'open' => $open,
                    'resolved' => $resolved,
                    'total' => $group->count(),
                ];
            })
            ->sortByDesc('open')
            ->values()
            ->all();

        $openThisMonth = $monthItems->filter(fn (FolRequest $r) => ! in_array($r->status, ['fulfilled', 'rejected'], true))->count();
        $resolvedThisMonth = $monthItems->filter(fn (FolRequest $r) => $r->status === 'fulfilled')->count();

        return [
            'month' => $month,
            'technician' => $tech ? [
                'id' => $tech->id,
                'name' => $tech->name,
                'email' => $tech->email,
            ] : null,
            'summary' => [
                'allocated_open' => $openAssigned->count(),
                'resolved' => $resolvedAssigned->count(),
                'total_assigned' => $allAssigned->count(),
                'distinct_accounts' => $allAssigned->pluck('customer_acumatica_id')->unique()->count(),
                'resolved_this_month' => $resolvedThisMonth,
                'open_this_month' => $openThisMonth,
            ],
            'days' => array_values($byDay),
            'accounts' => $accounts,
            'items' => $items,
        ];
    }

    /**
     * Technician marks an allocated FOL install as resolved (fulfilled).
     */
    public function resolveByTechnician(User $actor, FolRequest $request, ?string $comment = null): FolRequest
    {
        $canManage = $this->isAdministrator($actor) || $this->userCan($actor, 'kp.fol.install.manage');
        $isAssignee = (int) $request->assigned_technician_user_id === (int) $actor->id
            && $this->userCan($actor, 'kp.fol.install.execute');

        if (! $canManage && ! $isAssignee) {
            abort(403, 'Only the assigned technician (or a tech manager) can resolve this allocation.');
        }

        if (! $request->assigned_technician_user_id) {
            throw ValidationException::withMessages(['technician' => ['No technician is allocated to this FOL.']]);
        }

        if ($request->status === 'fulfilled') {
            return $request->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks', 'assignedTechnician']);
        }

        if ($request->status === 'rejected') {
            throw ValidationException::withMessages(['status' => ['Rejected FOLs cannot be resolved.']]);
        }

        // Prefer SO-linked / ready jobs for complete; allow admin override via manage
        $allowed = ['ready_for_invoicing', 'so_linked', 'invoiced', 'approved_final'];
        if (! in_array($request->status, $allowed, true) && ! $canManage) {
            throw ValidationException::withMessages([
                'status' => ['FOL must be approved / ready before the technician can mark it resolved.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $request, $comment): FolRequest {
            $request->forceFill([
                'status' => 'fulfilled',
                'updated_by' => $actor->id,
            ])->save();

            $this->event($request, 'technician_resolved', $actor, $comment, [
                'resolved_by' => $actor->id,
                'resolved_by_name' => $actor->name,
            ]);

            return $request->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks', 'assignedTechnician']);
        });
    }

    /** @return array<string, mixed> */
    private function presentCalendarItem(FolRequest $r): array
    {
        $anchor = $r->technician_assigned_at ?? $r->decided_at ?? $r->submitted_at ?? $r->created_at;
        $isResolved = $r->status === 'fulfilled';
        $isOpen = ! in_array($r->status, ['fulfilled', 'rejected'], true);

        return [
            'id' => $r->id,
            'public_ref' => $r->public_ref,
            'customer_acumatica_id' => $r->customer_acumatica_id,
            'customer_name' => $r->customer_name,
            'status' => $r->status,
            'resolve_state' => $isResolved ? 'resolved' : ($isOpen ? 'open' : 'closed'),
            'installation_required' => (bool) $r->installation_required,
            'installation_location' => $r->installation_location,
            'issue_types' => $r->issue_types,
            'lines_count' => $r->relationLoaded('lines') ? $r->lines->count() : $r->lines()->count(),
            'linked_so_order_nbrs' => $r->linked_so_order_nbrs,
            'technician_assigned_at' => $r->technician_assigned_at?->toIso8601String(),
            'calendar_date' => $anchor
                ? Carbon::parse($anchor)->timezone('Africa/Nairobi')->toDateString()
                : null,
            'sales_consultant_email' => $r->sales_consultant_email,
        ];
    }

    /**
     * @return array{last_purchase_date: ?string, sales_6m_kes: float, volume_6m: float}
     */
    public function metricsForCustomer(string $customerId, array $inventoryIds = []): array
    {
        $from = now('Africa/Nairobi')->subMonthsNoOverflow($this->settings->consumablesMonths())->startOfDay();
        $query = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->where('o.customer_acumatica_id', $customerId)
            ->where('o.order_date', '>=', $from);

        if ($inventoryIds !== []) {
            $query->whereIn('l.inventory_id', $inventoryIds);
        }

        $row = $query->selectRaw('MAX(o.order_date) as last_purchase_date, COALESCE(SUM(l.order_qty * l.unit_price), 0) as sales, COALESCE(SUM(l.order_qty), 0) as volume')->first();

        return [
            'last_purchase_date' => $row?->last_purchase_date ? Carbon::parse($row->last_purchase_date)->toDateString() : null,
            'sales_6m_kes' => round((float) ($row->sales ?? 0), 2),
            'volume_6m' => round((float) ($row->volume ?? 0), 4),
        ];
    }

    public function priorIssued(string $customerId, string $inventoryId): array
    {
        $rows = DB::table('fol_request_lines as l')
            ->join('fol_requests as r', 'r.id', '=', 'l.fol_request_id')
            ->where('r.customer_acumatica_id', $customerId)
            ->where('l.inventory_id', $inventoryId)
            ->whereIn('r.status', ['approved_final', 'ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled'])
            ->selectRaw('COALESCE(SUM(l.qty_requested), 0) as qty, MAX(r.decided_at) as last_date')
            ->first();

        return [
            'qty' => round((float) ($rows->qty ?? 0), 4),
            'date' => $rows?->last_date ? Carbon::parse($rows->last_date)->toDateString() : null,
        ];
    }

    public function event(FolRequest $request, string $eventType, ?User $actor = null, ?string $comment = null, array $payload = []): void
    {
        FolRequestEvent::create([
            'fol_request_id' => $request->id,
            'event_type' => $eventType,
            'actor_user_id' => $actor?->id,
            'comment' => $comment,
            'payload_json' => $payload ?: null,
        ]);
    }

    private function ensureCustomerAllowed(User $actor, AcumaticaCustomer $customer): void
    {
        if (! DataScope::customerAccessible($actor, (string) $customer->acumatica_id, $customer->customer_class)) {
            abort(403, 'Customer is outside your accessible portfolio.');
        }
    }

    private function ensureKpCustomer(AcumaticaCustomer $customer): void
    {
        $class = strtoupper((string) $customer->customer_class);
        if (! str_starts_with($class, 'KP')) {
            throw ValidationException::withMessages(['customer_acumatica_id' => ['Only KP customers can be used for FOL.']]);
        }
    }

    private function ensureFolLines(array $lines): void
    {
        foreach ($lines as $index => $line) {
            $item = AcumaticaInventoryItem::query()
                ->where('inventory_id', $line['inventory_id'] ?? null)
                ->first();
            if (! $item || ! (bool) $item->is_fol_eligible) {
                throw ValidationException::withMessages(["lines.{$index}.inventory_id" => ['Inventory item is not FOL eligible.']]);
            }
        }
    }

    private function nextPublicRef(): string
    {
        $year = now('Africa/Nairobi')->format('Y');
        $count = FolRequest::query()->where('public_ref', 'like', "FOL-{$year}-%")->count() + 1;

        return sprintf('FOL-%s-%06d', $year, $count);
    }

    private function firstStage(): ?FolApprovalStage
    {
        return $this->settings->stages(activeOnly: true)->first();
    }

    private function nextStage(string $stageKey): ?FolApprovalStage
    {
        $stages = $this->settings->stages(activeOnly: true);
        $current = $stages->firstWhere('key', $stageKey);
        if (! $current) {
            return null;
        }

        return $stages->first(fn (FolApprovalStage $s) => $s->sort_order > $current->sort_order);
    }

    private function canReadAll(User $user): bool
    {
        return $this->isAdministrator($user)
            || in_array($user->org_level, ['executive', 'c_suite'], true)
            || $this->userCan($user, 'kp.fol.report');
    }

    private function stageAllowsUser(string $stageKey, User $user): bool
    {
        // Configurable: Admin may approve any stage (default on for testing continuity).
        if ($this->isAdministrator($user) && $this->settings->allowAdminOnAllStages()) {
            return true;
        }

        return in_array($stageKey, $this->stageKeysForUser($user), true);
    }

    /**
     * Administrators (and super admins) are FOL super-users for testing and ops:
     * create requests, approve any stage (HOD / CCO), assign technicians, invoice/SO link.
     * Stage bypass respects FolSettingsService::allowAdminOnAllStages (admin-editable).
     */
    private function isAdministrator(User $user): bool
    {
        return $user->role === 'Administrator' || (bool) $user->is_super_admin;
    }

    private function technicianQuery(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->where(function (Builder $query) {
                $query->where('role', 'Technician')
                    ->orWhereHas('roles', function (Builder $roleQuery) {
                        $roleQuery->where('name', 'Technician')
                            ->orWhereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery->where('name', 'kp.fol.install.execute'));
                    });
            });
    }

    /** @return list<string> */
    private function stageKeysForUser(User $user): array
    {
        $roleNames = $user->roles()->pluck('roles.name')->push($user->role)->filter()->unique()->values()->all();

        return $this->settings->stages(activeOnly: true)
            ->filter(function (FolApprovalStage $stage) use ($user, $roleNames): bool {
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

    private function notifyStage(FolRequest $request, ?FolApprovalStage $stage, string $template, string $subject, array $context = []): void
    {
        if (! $stage) {
            return;
        }

        $recipients = $this->stageRecipients($stage);
        $this->sendMail($request, $recipients, $template, "{$request->public_ref}: {$subject}", ['stage' => $stage->name, ...$context]);
    }

    private function notifyConsultant(FolRequest $request, string $template, string $subject, array $context = []): void
    {
        $this->sendMail($request, array_filter([$request->sales_consultant_email]), $template, "{$request->public_ref}: {$subject}", $context);
    }

    private function notifyInvoicing(FolRequest $request, string $template, string $subject, array $context = []): void
    {
        // Roles are admin-configurable (Fol settings → invoicing_roles).
        $roles = $this->settings->invoicingRoles();
        $includeSuperAdmin = in_array('Administrator', $roles, true);
        $recipients = User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($roles, $includeSuperAdmin) {
                $query->whereIn('role', $roles)
                    ->orWhereHas('roles', fn ($roleQuery) => $roleQuery->whereIn('name', $roles));
                if ($includeSuperAdmin) {
                    $query->orWhere('is_super_admin', true);
                }
            })
            ->pluck('email')
            ->all();

        $recipients = [...$recipients, ...$this->settings->ccWatcherEmails()];

        $this->sendMail($request, $recipients, $template, "{$request->public_ref}: {$subject}", $context);
    }

    /** @return list<string> */
    private function stageRecipients(FolApprovalStage $stage): array
    {
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

        $emails = [...$emails, ...$this->settings->ccWatcherEmails()];

        return array_values(array_unique(array_filter($emails)));
    }

    /** @param list<string> $recipients */
    private function sendMail(FolRequest $request, array $recipients, string $template, string $subject, array $context = []): void
    {
        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new FolRequestMail($request, $template, $subject, $context));
        }

        $this->event($request, 'email_sent', null, null, [
            'template' => $template,
            'to' => $recipients,
            'subject' => $subject,
        ]);
    }
}
