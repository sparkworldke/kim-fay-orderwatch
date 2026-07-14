<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaInventoryItem;
use App\Models\FolRequest;
use App\Services\Fol\FolRequestService;
use App\Services\Fol\FolSettingsService;
use App\Support\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class FolController extends Controller
{
    public function __construct(
        private readonly FolRequestService $fol,
        private readonly FolSettingsService $folSettings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.view');

        $query = $this->fol->listQuery($request->user(), $request->input('view'));

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('q')) {
            $q = trim((string) $request->input('q'));
            $query->where(function ($scoped) use ($q) {
                $scoped->where('public_ref', 'like', "%{$q}%")
                    ->orWhere('customer_name', 'like', "%{$q}%")
                    ->orWhere('customer_acumatica_id', 'like', "%{$q}%");
            });
        }

        return response()->json($query->paginate($request->integer('per_page', 50)));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->requestRules());

        $fol = $this->fol->createDraft($request->user(), $validated);

        return response()->json($this->fol->present($fol), 201);
    }

    public function show(Request $request, FolRequest $folRequest): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.view');
        $this->authorizeRequestView($request, $folRequest);

        return response()->json($this->fol->present($folRequest));
    }

    public function submit(Request $request, FolRequest $folRequest): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.request');
        $this->authorizeRequestView($request, $folRequest);

        return response()->json($this->fol->present($this->fol->submit($request->user(), $folRequest)));
    }

    public function decision(Request $request, FolRequest $folRequest): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'comment' => ['required', 'string', 'min:2', 'max:5000'],
        ]);

        $fol = $this->fol->decide(
            $request->user(),
            $folRequest,
            $validated['decision'],
            $validated['comment'],
        );

        return response()->json($this->fol->present($fol));
    }

    public function attach(Request $request, FolRequest $folRequest): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.request');
        $this->authorizeRequestView($request, $folRequest);

        if ($folRequest->status !== 'draft') {
            return response()->json(['message' => 'Attachments can only be added while the request is draft.'], 422);
        }

        $extensions = implode(',', $this->folSettings->attachmentMimes());
        $maxKb = $this->folSettings->maxAttachmentKb();
        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', "mimes:{$extensions}", "max:{$maxKb}"],
        ]);

        foreach ($validated['files'] as $file) {
            $path = $file->store('fol/'.$folRequest->public_ref, 'local');
            $folRequest->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $request->user()->id,
            ]);
            $this->fol->event($folRequest, 'attachment_added', $request->user(), null, [
                'original_name' => $file->getClientOriginalName(),
                'path' => $path,
            ]);
        }

        return response()->json($this->fol->present($folRequest->fresh()));
    }

    public function linkSalesOrder(Request $request, FolRequest $folRequest): JsonResponse
    {
        $validated = $request->validate([
            'acumatica_order_nbr' => ['required', 'string', 'max:50'],
        ]);

        $this->authorizeRequestView($request, $folRequest);

        return response()->json($this->fol->present(
            $this->fol->linkSalesOrder($request->user(), $folRequest, $validated['acumatica_order_nbr']),
        ));
    }

    public function matchPurchaseOrder(Request $request, FolRequest $folRequest): JsonResponse
    {
        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:100'],
        ]);

        $this->authorizeRequestView($request, $folRequest);

        return response()->json($this->fol->present(
            $this->fol->matchPurchaseOrder($request->user(), $folRequest, $validated['po_number']),
        ));
    }

    public function technicians(Request $request): JsonResponse
    {
        return response()->json($this->fol->technicians($request->user()));
    }

    /**
     * Technician calendar: allocations, accounts, open vs resolved counts for a month.
     * GET kp/fol/technician/calendar?month=YYYY-MM&technician_user_id=optional
     */
    public function technicianCalendar(Request $request): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.view');

        $validated = $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
            'technician_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $month = $validated['month'] ?? now('Africa/Nairobi')->format('Y-m');

        return response()->json($this->fol->technicianCalendar(
            $request->user(),
            $month,
            isset($validated['technician_user_id']) ? (int) $validated['technician_user_id'] : null,
        ));
    }

    public function assignTechnician(Request $request, FolRequest $folRequest): JsonResponse
    {
        $validated = $request->validate([
            'technician_user_id' => ['required', 'integer'],
        ]);

        $this->authorizeRequestView($request, $folRequest);

        return response()->json($this->fol->present(
            $this->fol->assignTechnician($request->user(), $folRequest, (int) $validated['technician_user_id']),
        ));
    }

    /** Assigned technician (or manager) marks allocation resolved. */
    public function resolveTechnician(Request $request, FolRequest $folRequest): JsonResponse
    {
        $validated = $request->validate([
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        // Assigned tech may resolve even if portfolio listQuery would exclude — check service
        return response()->json($this->fol->present(
            $this->fol->resolveByTechnician(
                $request->user(),
                $folRequest,
                $validated['comment'] ?? null,
            ),
        ));
    }

    public function searchCustomers(Request $request): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.view');

        $q = trim((string) $request->input('q', ''));
        $query = DataScope::applyCustomerScope(
            AcumaticaCustomer::query()->where('customer_class', 'like', 'KP%')->orderBy('name'),
            $request->user(),
        );

        if ($q !== '') {
            $query->where(function ($scoped) use ($q) {
                $scoped->where('name', 'like', "%{$q}%")
                    ->orWhere('acumatica_id', 'like', "%{$q}%");
            });
        }

        return response()->json($query->limit(25)->get([
            'acumatica_id',
            'name',
            'customer_class',
            'status',
            'email',
            'phone',
            'payment_terms',
        ]));
    }

    public function searchInventory(Request $request): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.view');

        $q = trim((string) $request->input('q', ''));
        $query = AcumaticaInventoryItem::query()
            ->where('is_fol_eligible', true)
            ->orderBy('inventory_id');

        if ($q !== '') {
            $query->where(function ($scoped) use ($q) {
                $scoped->where('inventory_id', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%");
            });
        }

        return response()->json($query->limit(25)->get([
            'inventory_id',
            'description',
            'fol_category',
            'default_uom',
            'qty_on_hand',
        ]));
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->fol->ensureCan($request->user(), 'kp.fol.view');

        $validated = $request->validate([
            'customer_acumatica_id' => ['required', 'string', 'max:50'],
            'inventory_id' => ['sometimes', 'array'],
            'inventory_id.*' => ['string', 'max:100'],
        ]);

        $customer = AcumaticaCustomer::where('acumatica_id', $validated['customer_acumatica_id'])->firstOrFail();
        if (! DataScope::customerAccessible($request->user(), $customer->acumatica_id, $customer->customer_class)) {
            abort(403, 'Forbidden.');
        }

        $metrics = $this->fol->metricsForCustomer(
            $customer->acumatica_id,
            $validated['inventory_id'] ?? [],
        );

        $prior = [];
        foreach ($validated['inventory_id'] ?? [] as $inventoryId) {
            $prior[$inventoryId] = $this->fol->priorIssued($customer->acumatica_id, $inventoryId);
        }

        return response()->json([
            'customer' => $customer,
            'metrics' => $metrics,
            'prior_issued' => $prior,
        ]);
    }

    private function requestRules(): array
    {
        return [
            'customer_acumatica_id' => ['required', 'string', 'max:50'],
            'request_origin' => ['required', Rule::in(['sales_consultant_visit', 'customer_call', 'email', 'other'])],
            'request_origin_other' => ['nullable', 'string', 'max:255'],
            'requestor_first_name' => ['required', 'string', 'max:100'],
            'requestor_last_name' => ['required', 'string', 'max:100'],
            'requestor_phone' => ['required', 'string', 'max:50'],
            'requestor_email' => ['required', 'email', 'max:255'],
            'issue_types' => ['required', 'array', 'min:1'],
            'issue_types.*' => ['string', Rule::in(['new_dispenser', 'fol_batteries', 'maintenance_parts', 'replacement'])],
            'reason_text' => ['required', 'string', 'min:20'],
            'installation_required' => ['sometimes', 'boolean'],
            'installation_location' => ['nullable', 'required_if:installation_required,true', 'string', 'max:2000'],
            'customer_has_submitted_po' => ['sometimes', 'boolean'],
            'consumables_last_purchase_date' => ['nullable', 'date'],
            'consumables_sales_6m_kes' => ['nullable', 'numeric', 'min:0'],
            'consumables_volume_6m' => ['nullable', 'numeric', 'min:0'],
            'consumables_metrics_source' => ['nullable', Rule::in(['system_so', 'manual_override'])],
            'consumables_override_reason' => ['nullable', 'required_if:consumables_metrics_source,manual_override', 'string', 'max:2000'],
            'debt_explanation' => ['required', 'string', 'max:3000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.inventory_id' => ['required', 'string', 'max:100'],
            'lines.*.qty_requested' => ['required', 'numeric', 'min:1'],
            'lines.*.qty_previously_issued' => ['nullable', 'numeric', 'min:0'],
            'lines.*.date_last_issue' => ['nullable', 'date'],
            'lines.*.commitment_sku_ids' => ['nullable', 'array'],
            'lines.*.commitment_sku_ids.*' => ['string', 'max:100'],
        ];
    }

    private function authorizeRequestView(Request $request, FolRequest $folRequest): void
    {
        $allowed = $this->fol->listQuery($request->user())->whereKey($folRequest->id)->exists();
        if (! $allowed) {
            abort(403, 'Forbidden.');
        }
    }
}
