<?php

namespace App\Services\Fol;

use App\Mail\FolRequestMail;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\FolApprovalAction;
use App\Models\FolApprovalStage;
use App\Models\FolRequest;
use App\Models\FolRequestAttachment;
use App\Models\FolRequestEvent;
use App\Models\FolSoLink;
use App\Services\Crm\CustomerContactService;
use App\Models\NotificationRule;
use App\Models\Role;
use App\Models\User;
use App\Support\DataScope;
use App\Services\Team\AccessTierService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FolRequestService
{
    private const TERMINAL_STATUSES = ['rejected', 'approved_final', 'ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled'];

    public function __construct(
        private readonly FolSettingsService $settings,
        private readonly CustomerContactService $contacts,
        private readonly FolAcumaticaSalesOrderService $folSalesOrders,
        private readonly AccessTierService $accessTier,
    ) {}

    public function userCan(User $user, string $permission): bool
    {
        if (in_array($permission, ['kp.fol.view', 'kp.fol.report'], true)
            && $this->accessTier->hasUnrestrictedBusinessAccess($user)) {
            return true;
        }

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

        $attachments = $request->attachments->map(function ($file) {
            return [
                ...$file->toArray(),
                'download_url' => url("/api/kp/fol/attachments/{$file->id}/download"),
                'view_url' => url("/api/kp/fol/attachments/{$file->id}/view"),
                'preview_url' => url("/api/kp/fol/attachments/{$file->id}/preview"),
                'kind' => $this->attachmentKind($file->mime, $file->original_name),
            ];
        })->values();

        $soNumbers = collect($request->linked_so_order_nbrs ?? [])
            ->filter(fn ($n) => is_string($n) && $n !== '')
            ->values();
        if ($soNumbers->isEmpty()) {
            $soNumbers = $request->soLinks->pluck('acumatica_order_nbr')->filter()->values();
        }
        $primarySo = $soNumbers->first();

        return [
            ...$request->toArray(),
            // Explicit SO attachment fields for UI / clients.
            'so_number' => $primarySo,
            'acumatica_so_number' => $primarySo,
            'so_numbers' => $soNumbers->all(),
            'so_status' => $request->linked_so_status_summary,
            'lines' => $request->lines->values(),
            'attachments' => $attachments,
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

    private function attachmentKind(?string $mime, ?string $name): string
    {
        $mime = strtolower((string) $mime);
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION));

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'], true)) {
            return 'image';
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return 'pdf';
        }
        if (in_array($ext, ['csv', 'xlsx', 'xls', 'xlsm'], true)
            || str_contains($mime, 'spreadsheet')
            || str_contains($mime, 'excel')
            || $mime === 'text/csv') {
            return 'table';
        }
        if (str_starts_with($mime, 'text/')) {
            return 'text';
        }

        return 'binary';
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
        $this->ensureConsumables($data['consumable_inventory_ids'] ?? []);
        $this->ensureDistinctFolPurposes($data['lines'], $data['consumable_inventory_ids'] ?? []);

        return DB::transaction(function () use ($actor, $data, $customer): FolRequest {
            $consumableIds = array_values($data['consumable_inventory_ids'] ?? []);
            $metrics = $this->metricsForCustomer((string) $customer->acumatica_id, $consumableIds);
            $source = $data['consumables_metrics_source'] ?? 'system_so';
            $requestor = $this->contacts->resolveRequestorForFol(
                $actor,
                (string) $customer->acumatica_id,
                $data,
            );

            $request = FolRequest::create([
                'public_ref' => $this->nextPublicRef(),
                'customer_acumatica_id' => $customer->acumatica_id,
                'customer_name' => $customer->name,
                'sales_consultant_user_id' => $actor->id,
                'sales_consultant_email' => $actor->email,
                'sales_consultant_rep_code' => $actor->rep_code,
                'request_origin' => $data['request_origin'],
                'request_origin_other' => $data['request_origin_other'] ?? null,
                'requestor_first_name' => $requestor['first_name'],
                'requestor_last_name' => $requestor['last_name'],
                'requestor_phone' => $requestor['phone'],
                'requestor_email' => $requestor['email'],
                'requestor_contact_id' => $requestor['contact']?->id,
                'issue_types' => $data['issue_types'],
                'reason_text' => $data['reason_text'],
                'installation_required' => (bool) ($data['installation_required'] ?? false),
                'installation_location' => $data['installation_location'] ?? null,
                'customer_has_submitted_po' => (bool) ($data['customer_has_submitted_po'] ?? false),
                'consumable_inventory_ids' => $consumableIds,
                'consumables_last_purchase_date' => $data['consumables_last_purchase_date'] ?? $metrics['last_purchase_date'],
                'consumables_sales_3m_kes' => $data['consumables_sales_3m_kes'] ?? $metrics['sales_3m_kes'],
                'consumables_volume_3m' => $data['consumables_volume_3m'] ?? $metrics['volume_3m'],
                'consumables_sales_6m_kes' => $data['consumables_sales_6m_kes'] ?? $metrics['sales_6m_kes'],
                'consumables_volume_6m' => $data['consumables_volume_6m'] ?? $metrics['volume_6m'],
                'consumables_evidence_json' => $metrics['line_last_purchases'],
                'consumables_metrics_as_of' => now('Africa/Nairobi'),
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
                    'line_type' => 'fol_item',
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

    /**
     * Update an existing draft FOL (owner or admin). Non-draft requests are immutable.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(User $actor, FolRequest $request, array $data): FolRequest
    {
        $this->ensureCan($actor, 'kp.fol.request');
        $this->ensureDraftOwner($actor, $request);

        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft requests can be edited.']]);
        }

        $customer = AcumaticaCustomer::query()
            ->where('acumatica_id', $data['customer_acumatica_id'])
            ->firstOrFail();

        $this->ensureCustomerAllowed($actor, $customer);
        $this->ensureKpCustomer($customer);
        $this->ensureFolLines($data['lines']);
        $this->ensureConsumables($data['consumable_inventory_ids'] ?? []);
        $this->ensureDistinctFolPurposes($data['lines'], $data['consumable_inventory_ids'] ?? []);

        return DB::transaction(function () use ($actor, $request, $data, $customer): FolRequest {
            $consumableIds = array_values($data['consumable_inventory_ids'] ?? []);
            $metrics = $this->metricsForCustomer((string) $customer->acumatica_id, $consumableIds);
            $source = $data['consumables_metrics_source'] ?? 'system_so';
            $requestor = $this->contacts->resolveRequestorForFol(
                $actor,
                (string) $customer->acumatica_id,
                $data,
            );

            $request->forceFill([
                'customer_acumatica_id' => $customer->acumatica_id,
                'customer_name' => $customer->name,
                'request_origin' => $data['request_origin'],
                'request_origin_other' => $data['request_origin_other'] ?? null,
                'requestor_first_name' => $requestor['first_name'],
                'requestor_last_name' => $requestor['last_name'],
                'requestor_phone' => $requestor['phone'],
                'requestor_email' => $requestor['email'],
                'requestor_contact_id' => $requestor['contact']?->id,
                'issue_types' => $data['issue_types'],
                'reason_text' => $data['reason_text'],
                'installation_required' => (bool) ($data['installation_required'] ?? false),
                'installation_location' => $data['installation_location'] ?? null,
                'customer_has_submitted_po' => (bool) ($data['customer_has_submitted_po'] ?? false),
                'consumable_inventory_ids' => $consumableIds,
                'consumables_last_purchase_date' => $data['consumables_last_purchase_date'] ?? $metrics['last_purchase_date'],
                'consumables_sales_3m_kes' => $data['consumables_sales_3m_kes'] ?? $metrics['sales_3m_kes'],
                'consumables_volume_3m' => $data['consumables_volume_3m'] ?? $metrics['volume_3m'],
                'consumables_sales_6m_kes' => $data['consumables_sales_6m_kes'] ?? $metrics['sales_6m_kes'],
                'consumables_volume_6m' => $data['consumables_volume_6m'] ?? $metrics['volume_6m'],
                'consumables_evidence_json' => $metrics['line_last_purchases'],
                'consumables_metrics_as_of' => now('Africa/Nairobi'),
                'consumables_metrics_source' => $source,
                'consumables_override_reason' => $data['consumables_override_reason'] ?? null,
                'debt_explanation' => $data['debt_explanation'],
                'updated_by' => $actor->id,
            ])->save();

            $request->lines()->delete();
            foreach (array_values($data['lines']) as $index => $line) {
                $item = AcumaticaInventoryItem::query()->where('inventory_id', $line['inventory_id'])->firstOrFail();
                $prior = $this->priorIssued((string) $customer->acumatica_id, (string) $line['inventory_id']);
                $request->lines()->create([
                    'line_no' => $index + 1,
                    'inventory_id' => $item->inventory_id,
                    'product_description' => $item->description,
                    'line_type' => 'fol_item',
                    'qty_requested' => $line['qty_requested'],
                    'qty_previously_issued' => $line['qty_previously_issued'] ?? $prior['qty'],
                    'date_last_issue' => $line['date_last_issue'] ?? $prior['date'],
                    'previous_source' => 'prior_fol',
                    'commitment_sku_ids' => $line['commitment_sku_ids'] ?? null,
                ]);
            }

            $this->event($request, 'draft_updated', $actor, null, [
                'source' => 'api',
                'line_count' => count($data['lines']),
            ]);

            return $request->fresh(['lines', 'attachments']);
        });
    }

    public function deleteAttachment(User $actor, FolRequestAttachment $attachment): FolRequest
    {
        $this->ensureCan($actor, 'kp.fol.request');

        $request = $attachment->request;
        if (! $request) {
            abort(404, 'FOL request not found for attachment.');
        }

        $this->ensureDraftOwner($actor, $request);

        if ($request->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Attachments can only be removed while the request is draft.']]);
        }

        return DB::transaction(function () use ($actor, $request, $attachment): FolRequest {
            $meta = [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'path' => $attachment->path,
            ];

            if ($attachment->path && Storage::disk('local')->exists($attachment->path)) {
                Storage::disk('local')->delete($attachment->path);
            }

            $attachment->delete();
            $this->event($request, 'attachment_removed', $actor, null, $meta);

            return $request->fresh(['lines', 'attachments']);
        });
    }

    private function ensureDraftOwner(User $actor, FolRequest $request): void
    {
        if ($request->sales_consultant_user_id !== $actor->id && ! $this->canReadAll($actor) && ! $this->isAdministrator($actor)) {
            abort(403, 'Forbidden.');
        }
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

        $outcome = DB::transaction(function () use ($actor, $request, $decision, $comment): array {
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

                return ['phase' => 'rejected', 'request' => $request->fresh(['lines', 'attachments', 'events', 'approvalActions'])];
            }

            $nextStage = $this->nextStage((string) $request->current_stage_key);
            if ($nextStage) {
                $request->forceFill([
                    'status' => 'in_approval',
                    'current_stage_key' => $nextStage->key,
                ])->save();
                $this->event($request, 'stage_approved', $actor, $comment, ['next_stage' => $nextStage->key]);

                return [
                    'phase' => 'stage',
                    'next_stage' => $nextStage,
                    'request' => $request->fresh(['lines', 'attachments', 'events', 'approvalActions']),
                ];
            }

            // Final stage (CCO / COO) — mark ready; Acumatica SO is created after commit.
            $request->forceFill([
                'status' => 'ready_for_invoicing',
                'current_stage_key' => 'done',
                'decided_at' => now(),
            ])->save();
            $this->event($request, 'final_approved', $actor, $comment);

            return [
                'phase' => 'final',
                'request' => $request->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks']),
            ];
        });

        /** @var FolRequest $fresh */
        $fresh = $outcome['request'];

        if ($outcome['phase'] === 'rejected') {
            $this->notifyConsultant($fresh, 'N6', 'FOL request rejected', ['comment' => $comment]);

            return $fresh;
        }

        if ($outcome['phase'] === 'stage') {
            /** @var FolApprovalStage $nextStage */
            $nextStage = $outcome['next_stage'];
            $this->notifyConsultant($fresh, 'N2', 'FOL request approved by current stage', ['comment' => $comment]);
            $this->notifyStage($fresh, $nextStage, 'N3', 'FOL request pending final approval', ['comment' => $comment]);

            return $fresh;
        }

        // Final CCO approval: create Acumatica SO for customer + FOL lines, then notify sales.
        $soResult = $this->folSalesOrders->createAndLink($fresh, $actor, 'cco_approve');
        $fresh = $fresh->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks']) ?? $fresh;

        $soContext = [
            'comment' => $comment,
            'acumatica_order_nbr' => $soResult['order_nbr'],
            'so_create_ok' => $soResult['ok'],
            'so_create_error' => $soResult['error'],
            'so_already_linked' => $soResult['already_linked'],
            'so_skipped' => $soResult['skipped'],
            'so_create_log_id' => $soResult['log_id'] ?? null,
        ];

        $this->event($fresh, $soResult['ok'] ? 'so_auto_created' : 'so_auto_create_failed', $actor, null, [
            'acumatica_order_nbr' => $soResult['order_nbr'],
            'error' => $soResult['error'],
            'skipped' => $soResult['skipped'],
            'already_linked' => $soResult['already_linked'],
            'so_create_log_id' => $soResult['log_id'] ?? null,
            'attempt_source' => 'cco_approve',
        ]);

        $consultantSubject = $soResult['ok'] && $soResult['order_nbr']
            ? "FOL request fully approved — SO {$soResult['order_nbr']} created"
            : 'FOL request fully approved';

        $this->notifyConsultant($fresh, 'N4', $consultantSubject, $soContext);
        $this->notifyInvoicing($fresh, 'N5', $soResult['ok'] && $soResult['order_nbr']
            ? "FOL approved for invoicing — SO {$soResult['order_nbr']}"
            : 'FOL request approved for invoicing', $soContext);

        return $fresh->fresh(['lines', 'attachments', 'events', 'approvalActions', 'soLinks']) ?? $fresh;
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
     * SO-backed consumables metrics for a KP customer.
     *
     * Only FOL-eligible inventory is counted (is_fol_eligible = true).
     * When $inventoryIds is non-empty, further restricts to those SKUs
     * (typically the lines already on the FOL draft).
     *
     * @param  list<string>  $inventoryIds
     * @return array{
     *   last_purchase_date: ?string,
     *   last_purchase_order_nbr: ?string,
     *   sales_6m_kes: float,
     *   volume_6m: float,
     *   lookback_months: int,
     *   scope: string,
     *   line_last_purchases: array<string, array{date: ?string, order_nbr: ?string, qty: float, value: float}>
     * }
     */
    public function metricsForCustomer(string $customerId, array $inventoryIds = []): array
    {
        $months = 6;
        $asOf = now('Africa/Nairobi');
        $from = $asOf->copy()->subMonthsNoOverflow($months)->startOfDay();
        $fromThreeMonths = $asOf->copy()->subMonthsNoOverflow(3)->startOfDay();

        // Base: SO lines for this customer in lookback window, FOL-eligible inventory only.
        $base = DB::table('acumatica_sales_order_lines as l')
            ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
            ->join('acumatica_inventory_items as i', 'i.inventory_id', '=', 'l.inventory_id')
            ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
            ->where('o.customer_acumatica_id', $customerId)
            ->where('o.order_date', '>=', $from)
            ->where('i.is_fol_eligible', true);

        if ($inventoryIds !== []) {
            $base->whereIn('l.inventory_id', $inventoryIds);
        }

        $row = (clone $base)
            ->selectRaw(
                'MAX(o.order_date) as last_purchase_date,
                 COALESCE(SUM(CASE WHEN o.order_date >= ? THEN l.order_qty * l.unit_price ELSE 0 END), 0) as sales_3m,
                 COALESCE(SUM(CASE WHEN o.order_date >= ? THEN l.order_qty ELSE 0 END), 0) as volume_3m,
                 COALESCE(SUM(l.order_qty * l.unit_price), 0) as sales,
                 COALESCE(SUM(l.order_qty), 0) as volume',
                [$fromThreeMonths, $fromThreeMonths],
            )
            ->first();

        $lastOrderNbr = null;
        $lastDate = $row?->last_purchase_date
            ? Carbon::parse($row->last_purchase_date)->toDateString()
            : null;
        if ($lastDate !== null) {
            $lastOrderNbr = (clone $base)
                ->whereDate('o.order_date', $lastDate)
                ->orderByDesc('o.order_date')
                ->orderByDesc('o.acumatica_order_nbr')
                ->value('o.acumatica_order_nbr');
        }

        // Per FOL-eligible SKU: last SO purchase (from previous SOs).
        $perSku = (clone $base)
            ->selectRaw(
                'l.inventory_id, MAX(o.order_date) as last_date,
                 COALESCE(SUM(CASE WHEN o.order_date >= ? THEN l.order_qty END), 0) as qty_3m,
                 COALESCE(SUM(CASE WHEN o.order_date >= ? THEN l.order_qty * l.unit_price END), 0) as value_3m,
                 COALESCE(SUM(l.order_qty), 0) as qty,
                 COALESCE(SUM(l.order_qty * l.unit_price), 0) as value',
                [$fromThreeMonths, $fromThreeMonths],
            )
            ->groupBy('l.inventory_id')
            ->get();

        $lineLastPurchases = [];
        foreach ($perSku as $skuRow) {
            $sku = (string) $skuRow->inventory_id;
            $skuDate = $skuRow->last_date
                ? Carbon::parse($skuRow->last_date)->toDateString()
                : null;
            $skuOrder = null;
            if ($skuDate !== null) {
                $skuOrder = DB::table('acumatica_sales_order_lines as l')
                    ->join('acumatica_sales_orders as o', 'o.id', '=', 'l.sales_order_id')
                    ->where('o.order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
                    ->where('o.customer_acumatica_id', $customerId)
                    ->where('l.inventory_id', $sku)
                    ->whereDate('o.order_date', $skuDate)
                    ->orderByDesc('o.order_date')
                    ->value('o.acumatica_order_nbr');
            }
            $lineLastPurchases[$sku] = [
                'date' => $skuDate,
                'order_nbr' => $skuOrder ? (string) $skuOrder : null,
                'qty' => round((float) $skuRow->qty, 4),
                'value' => round((float) $skuRow->value, 2),
                'qty_3m' => round((float) $skuRow->qty_3m, 4),
                'value_3m' => round((float) $skuRow->value_3m, 2),
            ];
        }

        // Keep selected consumables visible even when the customer has no history.
        foreach ($inventoryIds as $inventoryId) {
            $lineLastPurchases[$inventoryId] ??= [
                'date' => null,
                'order_nbr' => null,
                'qty' => 0.0,
                'value' => 0.0,
                'qty_3m' => 0.0,
                'value_3m' => 0.0,
            ];
        }

        return [
            'last_purchase_date' => $lastDate,
            'last_purchase_order_nbr' => $lastOrderNbr ? (string) $lastOrderNbr : null,
            'sales_3m_kes' => round((float) ($row->sales_3m ?? 0), 2),
            'volume_3m' => round((float) ($row->volume_3m ?? 0), 4),
            'sales_6m_kes' => round((float) ($row->sales ?? 0), 2),
            'volume_6m' => round((float) ($row->volume ?? 0), 4),
            'lookback_months' => $months,
            'scope' => $inventoryIds !== []
                ? 'fol_eligible_lines_on_request'
                : 'all_fol_eligible_inventory',
            'line_last_purchases' => $lineLastPurchases,
            'metrics_as_of' => $asOf->toIso8601String(),
            // Full customer SO history context (NOT FOL-eligible filtered).
            'recent_orders' => $this->recentSalesOrdersForCustomer($customerId, 3),
            'customer_6m' => $this->customerSixMonthSummary($customerId, 3),
            // Prior Free-On-Loan issues from OrderWatch FOL requests (not SO lines).
            'prior_fol' => $this->priorFolSummaryForCustomer($customerId, $inventoryIds),
        ];
    }

    /**
     * Previous FOL issues for a customer (approved / post-approval statuses).
     * Shown at the top of New FOL so consultants see historical FOLs even when
     * SO-backed FOL-SKU metrics are empty.
     *
     * @param  list<string>  $inventoryIds  Optional cart lines — prioritise these in by_sku.
     * @return array{
     *   request_count: int,
     *   total_qty_issued: float,
     *   last_issue_date: ?string,
     *   last_public_ref: ?string,
     *   last_status: ?string,
     *   recent: list<array{
     *     id: int,
     *     public_ref: string,
     *     status: string,
     *     decided_at: ?string,
     *     submitted_at: ?string,
     *     total_qty: float,
     *     line_count: int,
     *     lines: list<array{inventory_id: string, product_description: ?string, qty: float}>
     *   }>,
     *   by_sku: array<string, array{
     *     inventory_id: string,
     *     product_description: ?string,
     *     qty: float,
     *     date: ?string,
     *     public_ref: ?string
     *   }>
     * }
     */
    public function priorFolSummaryForCustomer(string $customerId, array $inventoryIds = [], int $recentLimit = 5): array
    {
        $recentLimit = max(1, min($recentLimit, 15));
        $issuedStatuses = ['approved_final', 'ready_for_invoicing', 'so_linked', 'invoiced', 'fulfilled'];

        $summary = DB::table('fol_requests as r')
            ->leftJoin('fol_request_lines as l', 'l.fol_request_id', '=', 'r.id')
            ->where('r.customer_acumatica_id', $customerId)
            ->whereIn('r.status', $issuedStatuses)
            ->selectRaw('
                COUNT(DISTINCT r.id) as request_count,
                COALESCE(SUM(l.qty_requested), 0) as total_qty,
                MAX(COALESCE(r.decided_at, r.submitted_at, r.updated_at)) as last_issue_at
            ')
            ->first();

        $last = FolRequest::query()
            ->where('customer_acumatica_id', $customerId)
            ->whereIn('status', $issuedStatuses)
            ->orderByRaw('COALESCE(decided_at, submitted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->first(['id', 'public_ref', 'status', 'decided_at', 'submitted_at']);

        $recentRows = FolRequest::query()
            ->with(['lines' => fn ($q) => $q->orderBy('line_no')])
            ->where('customer_acumatica_id', $customerId)
            ->whereIn('status', $issuedStatuses)
            ->orderByRaw('COALESCE(decided_at, submitted_at, updated_at) DESC')
            ->orderByDesc('id')
            ->limit($recentLimit)
            ->get();

        $recent = $recentRows->map(static function (FolRequest $r): array {
            $lines = $r->lines->map(static fn ($line) => [
                'inventory_id' => (string) $line->inventory_id,
                'product_description' => $line->product_description ? (string) $line->product_description : null,
                'qty' => round((float) $line->qty_requested, 4),
            ])->values()->all();

            return [
                'id' => (int) $r->id,
                'public_ref' => (string) $r->public_ref,
                'status' => (string) $r->status,
                'decided_at' => $r->decided_at ? Carbon::parse($r->decided_at)->toDateString() : null,
                'submitted_at' => $r->submitted_at ? Carbon::parse($r->submitted_at)->toDateString() : null,
                'total_qty' => round((float) $r->lines->sum(fn ($l) => (float) $l->qty_requested), 4),
                'line_count' => $r->lines->count(),
                'lines' => $lines,
            ];
        })->values()->all();

        // Per-SKU lifetime issued qty + last issue date/ref.
        $skuQuery = DB::table('fol_request_lines as l')
            ->join('fol_requests as r', 'r.id', '=', 'l.fol_request_id')
            ->where('r.customer_acumatica_id', $customerId)
            ->whereIn('r.status', $issuedStatuses)
            ->selectRaw('
                l.inventory_id,
                MAX(l.product_description) as product_description,
                COALESCE(SUM(l.qty_requested), 0) as qty,
                MAX(COALESCE(r.decided_at, r.submitted_at, r.updated_at)) as last_date
            ')
            ->groupBy('l.inventory_id')
            ->orderByDesc('qty');

        if ($inventoryIds !== []) {
            // Always include cart lines, even if qty 0 — filled below.
            $skuQuery->whereIn('l.inventory_id', $inventoryIds);
        } else {
            $skuQuery->limit(20);
        }

        $bySku = [];
        foreach ($skuQuery->get() as $row) {
            $inv = (string) $row->inventory_id;
            $lastDate = $row->last_date ? Carbon::parse($row->last_date)->toDateString() : null;
            $lastRef = DB::table('fol_request_lines as l')
                ->join('fol_requests as r', 'r.id', '=', 'l.fol_request_id')
                ->where('r.customer_acumatica_id', $customerId)
                ->whereIn('r.status', $issuedStatuses)
                ->where('l.inventory_id', $inv)
                ->orderByRaw('COALESCE(r.decided_at, r.submitted_at, r.updated_at) DESC')
                ->orderByDesc('r.id')
                ->value('r.public_ref');
            $bySku[$inv] = [
                'inventory_id' => $inv,
                'product_description' => $row->product_description ? (string) $row->product_description : null,
                'qty' => round((float) $row->qty, 4),
                'date' => $lastDate,
                'public_ref' => $lastRef ? (string) $lastRef : null,
            ];
        }

        // Ensure every cart SKU appears even when never issued.
        foreach ($inventoryIds as $invId) {
            $inv = (string) $invId;
            if (! isset($bySku[$inv])) {
                $bySku[$inv] = [
                    'inventory_id' => $inv,
                    'product_description' => null,
                    'qty' => 0.0,
                    'date' => null,
                    'public_ref' => null,
                ];
            }
        }

        return [
            'request_count' => (int) ($summary->request_count ?? 0),
            'total_qty_issued' => round((float) ($summary->total_qty ?? 0), 4),
            'last_issue_date' => $summary?->last_issue_at
                ? Carbon::parse($summary->last_issue_at)->toDateString()
                : null,
            'last_public_ref' => $last?->public_ref ? (string) $last->public_ref : null,
            'last_status' => $last?->status ? (string) $last->status : null,
            'recent' => $recent,
            'by_sku' => $bySku,
        ];
    }

    /**
     * Last N sales orders for a customer — all SKUs / full document total.
     * Used on New FOL to show customer purchasing pattern.
     *
     * @return list<array{order_nbr: string, order_date: ?string, order_total: float, status: ?string, order_type: ?string}>
     */
    public function recentSalesOrdersForCustomer(string $customerId, int $limit = 3): array
    {
        $limit = max(1, min($limit, 10));

        $orders = AcumaticaSalesOrder::query()
            ->where('customer_acumatica_id', $customerId)
            ->where(function ($q) {
                $q->where('order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
                    ->orWhereNull('order_type')
                    ->orWhere('order_type', 'SO');
            })
            ->orderByDesc('order_date')
            ->orderByDesc('acumatica_order_nbr')
            ->limit($limit)
            ->get(['acumatica_order_nbr', 'order_date', 'order_total', 'status', 'order_type']);

        return $orders->map(static function (AcumaticaSalesOrder $o): array {
            return [
                'order_nbr' => (string) $o->acumatica_order_nbr,
                'order_date' => $o->order_date
                    ? Carbon::parse($o->order_date)->toDateString()
                    : null,
                'order_total' => round((float) ($o->order_total ?? 0), 2),
                'status' => $o->status ? (string) $o->status : null,
                'order_type' => $o->order_type ? (string) $o->order_type : null,
            ];
        })->values()->all();
    }

    /**
     * Full-document (not FOL-SKU filtered) 6-month customer purchasing summary.
     *
     * @return array{
     *   months: int,
     *   revenue_total: float,
     *   order_count: int,
     *   aov: float,
     *   orders_per_month: float,
     *   avg_days_between_orders: ?float,
     *   frequency_label: string,
     *   first_order_date: ?string,
     *   last_order_date: ?string
     * }
     */
    public function customerSixMonthSummary(string $customerId, int $months = 6): array
    {
        $months = max(1, min($months, 24));
        $from = now('Africa/Nairobi')->subMonthsNoOverflow($months)->startOfDay();

        $orders = AcumaticaSalesOrder::query()
            ->where('customer_acumatica_id', $customerId)
            ->where(function ($q) {
                $q->where('order_type', AcumaticaSalesOrder::TYPE_SALES_ORDER)
                    ->orWhereNull('order_type')
                    ->orWhere('order_type', 'SO');
            })
            ->where('order_date', '>=', $from)
            ->orderBy('order_date')
            ->get(['order_date', 'order_total']);

        $orderCount = $orders->count();
        $revenue = round((float) $orders->sum(fn ($o) => (float) ($o->order_total ?? 0)), 2);
        $aov = $orderCount > 0 ? round($revenue / $orderCount, 2) : 0.0;
        $ordersPerMonth = $orderCount > 0 ? round($orderCount / $months, 2) : 0.0;

        $avgDaysBetween = null;
        $firstDate = null;
        $lastDate = null;
        if ($orderCount >= 1) {
            $firstDate = Carbon::parse($orders->first()->order_date)->toDateString();
            $lastDate = Carbon::parse($orders->last()->order_date)->toDateString();
        }
        if ($orderCount >= 2) {
            $gaps = [];
            $prev = null;
            foreach ($orders as $o) {
                $d = Carbon::parse($o->order_date)->startOfDay();
                if ($prev !== null) {
                    $gaps[] = $prev->diffInDays($d);
                }
                $prev = $d;
            }
            if ($gaps !== []) {
                $avgDaysBetween = round(array_sum($gaps) / count($gaps), 1);
            }
        }

        return [
            'months' => $months,
            'revenue_total' => $revenue,
            'order_count' => $orderCount,
            'aov' => $aov,
            'orders_per_month' => $ordersPerMonth,
            'avg_days_between_orders' => $avgDaysBetween,
            'frequency_label' => $this->formatOrderFrequencyLabel($orderCount, $months, $ordersPerMonth, $avgDaysBetween),
            'first_order_date' => $firstDate,
            'last_order_date' => $lastDate,
        ];
    }

    private function formatOrderFrequencyLabel(
        int $orderCount,
        int $months,
        float $ordersPerMonth,
        ?float $avgDaysBetween,
    ): string {
        if ($orderCount <= 0) {
            return 'No orders in window';
        }
        if ($orderCount === 1) {
            return '1 order in '.$months.' months';
        }

        // Prefer cadence from average gap when we have multiple orders.
        if ($avgDaysBetween !== null && $avgDaysBetween > 0) {
            if ($avgDaysBetween <= 2.5) {
                return 'Daily / every ~'.max(1, (int) round($avgDaysBetween)).' day'.((int) round($avgDaysBetween) === 1 ? '' : 's');
            }
            if ($avgDaysBetween <= 4.5) {
                return 'Every ~'.(int) round($avgDaysBetween).' days';
            }
            if ($avgDaysBetween <= 9) {
                return 'About weekly (~'.(int) round($avgDaysBetween).' days)';
            }
            if ($avgDaysBetween <= 18) {
                return 'About every 2 weeks (~'.(int) round($avgDaysBetween).' days)';
            }
            if ($avgDaysBetween <= 40) {
                $opm = max(0.1, round(30 / $avgDaysBetween, 1));

                return $opm.' order'.($opm == 1.0 ? '' : 's').'/month';
            }
            if ($avgDaysBetween <= 75) {
                return 'About every 2 months';
            }

            return 'Every ~'.(int) round($avgDaysBetween / 30).' months';
        }

        // Fallback: MoM rate from count / window.
        if ($ordersPerMonth >= 0.95) {
            $rounded = round($ordersPerMonth, 1);
            if (abs($rounded - round($rounded)) < 0.05) {
                $rounded = (int) round($rounded);
            }

            return $rounded.' order'.($rounded == 1 ? '' : 's').'/month';
        }

        return $orderCount.' order'.($orderCount === 1 ? '' : 's').' in '.$months.' months';
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
            if ($item->fol_category === 'consumable') {
                throw ValidationException::withMessages(["lines.{$index}.inventory_id" => ['A consumable cannot be requested as free-on-loan equipment.']]);
            }
        }
    }

    /** @param list<string> $inventoryIds */
    private function ensureConsumables(array $inventoryIds): void
    {
        if ($inventoryIds === []) {
            return;
        }

        $items = AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->get()
            ->keyBy('inventory_id');

        foreach ($inventoryIds as $index => $inventoryId) {
            $item = $items->get($inventoryId);
            if (! $item || ! $item->is_fol_eligible || ! in_array($item->fol_category, ['consumable', 'both'], true)) {
                throw ValidationException::withMessages([
                    "consumable_inventory_ids.{$index}" => ['Inventory item is not classified as an FOL consumable.'],
                ]);
            }
        }
    }

    /** @param array<int, array<string, mixed>> $lines @param list<string> $inventoryIds */
    private function ensureDistinctFolPurposes(array $lines, array $inventoryIds): void
    {
        $lineIds = array_map(fn (array $line) => (string) ($line['inventory_id'] ?? ''), $lines);
        if (array_intersect($lineIds, $inventoryIds) !== []) {
            throw ValidationException::withMessages([
                'consumable_inventory_ids' => ['The same SKU cannot be both free equipment and supporting consumable on one request.'],
            ]);
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
        return $this->accessTier->hasUnrestrictedBusinessAccess($user)
            || $this->isAdministrator($user)
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
        // Consultant + admin CC watchers (admin always gets workflow steps).
        $recipients = array_filter([
            $request->sales_consultant_email,
            ...$this->settings->ccWatcherEmails(),
        ]);
        $this->sendMail($request, $recipients, $template, "{$request->public_ref}: {$subject}", $context);
    }

    private function notifyInvoicing(FolRequest $request, string $template, string $subject, array $context = []): void
    {
        // Do not blast every user in invoicing roles. Notify only admin-added CC
        // watcher emails (and, when configured, named users can be added there).
        // Full role fan-out is disabled during testing and until explicitly needed.
        $recipients = $this->settings->ccWatcherEmails();

        $this->sendMail($request, $recipients, $template, "{$request->public_ref}: {$subject}", $context);
    }

    /**
     * Stage approval emails go to:
     *  - people attached to the stage (user_ids), and
     *  - admin-added CC watcher emails (always receive every step).
     * Role membership is for who may approve in-app — not for mass email.
     *
     * @return list<string>
     */
    private function stageRecipients(FolApprovalStage $stage): array
    {
        $emails = [];

        // Attached people only (named users on the stage).
        if (($stage->user_ids ?? []) !== []) {
            $emails = User::query()
                ->whereIn('id', $stage->user_ids)
                ->where('is_active', true)
                ->pluck('email')
                ->all();
        }

        // Admin-configured CC list (e.g. commercialtechlead@kimfay.com).
        $emails = [...$emails, ...$this->settings->ccWatcherEmails()];

        return array_values(array_unique(array_filter(array_map(
            static fn ($e) => strtolower(trim((string) $e)),
            $emails,
        ))));
    }

    /** @param list<string> $recipients */
    private function sendMail(FolRequest $request, array $recipients, string $template, string $subject, array $context = []): void
    {
        // FOL-N1..FOL-N6 map from template N1..N6. Missing rule = send (workflow default on).
        $ruleKey = 'FOL-'.$template;
        $rule = NotificationRule::query()->where('rule_key', $ruleKey)->first();
        if ($rule && ! $rule->is_enabled) {
            Log::info('fol_email_skipped_rule_disabled', [
                'rule_key' => $ruleKey,
                'public_ref' => $request->public_ref,
                'template' => $template,
            ]);

            return;
        }

        $intended = array_values(array_unique(array_filter(array_map(
            static fn ($e) => strtolower(trim((string) $e)),
            $recipients,
        ))));

        // Testing: only the admin testing mailbox receives FOL mail.
        if ((bool) config('fol.mail_testing_mode', true)) {
            $testingTo = strtolower(trim((string) config('fol.mail_testing_recipient', 'commercialtechlead@kimfay.com')));
            if ($testingTo === '') {
                Log::warning('fol_email_skipped_testing_recipient_empty', [
                    'public_ref' => $request->public_ref,
                    'template' => $template,
                    'intended' => $intended,
                ]);

                return;
            }

            Log::info('fol_email_testing_mode_redirect', [
                'public_ref' => $request->public_ref,
                'template' => $template,
                'intended' => $intended,
                'actual' => [$testingTo],
            ]);
            $recipients = [$testingTo];
        } else {
            $recipients = $intended;
        }

        if ($recipients === []) {
            Log::info('fol_email_skipped_no_recipients', [
                'public_ref' => $request->public_ref,
                'template' => $template,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new FolRequestMail($request, $template, $subject, $context));
        }

        $this->event($request, 'email_sent', null, null, [
            'template' => $template,
            'to' => $recipients,
            'intended' => $intended,
            'testing_mode' => (bool) config('fol.mail_testing_mode', true),
            'subject' => $subject,
        ]);
    }
}
