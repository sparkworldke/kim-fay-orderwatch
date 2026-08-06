<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\StockTransferRequestMail;
use App\Models\AcumaticaInventoryItem;
use App\Models\ProductionSkuPlan;
use App\Models\ProductionMachine;
use App\Services\Production\ProductionIntelligenceService;
use App\Services\Production\ProductionPlanningAccess;
use App\Services\Production\ProductionSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class ProductionIntelligenceController extends Controller
{
    public function __construct(
        private readonly ProductionIntelligenceService $service,
        private readonly ProductionPlanningAccess $planningAccess,
    ) {}

    public function inventory(Request $request): JsonResponse
    {
        $request->validate([
            'ownership' => ['nullable', Rule::in(['manufactured', 'partner'])],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'between:1,500'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        return response()->json($this->service->inventory($request));
    }

    public function version(): JsonResponse
    {
        return response()->json($this->service->versions());
    }

    public function reference(): JsonResponse
    {
        return response()->json($this->service->reference());
    }

    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->service->summary($request));
    }

    public function trend(Request $request, string $inventoryId): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        return response()->json($this->service->trend($inventoryId, $request));
    }

    public function warehouses(string $inventoryId): JsonResponse
    {
        return response()->json($this->service->warehouses($inventoryId));
    }

    public function show(Request $request, string $inventoryId): JsonResponse
    {
        return response()->json($this->service->detail($inventoryId, $request));
    }

    public function sales(Request $request): JsonResponse
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        return response()->json($this->service->sales($request));
    }

    public function plans(Request $request): JsonResponse
    {
        $query = ProductionSkuPlan::withTrashed()->with('inventoryItem:id,inventory_id,description,brand')
            ->orderByDesc('updated_at');
        if ($search = trim((string) $request->input('search'))) {
            $query->whereHas('inventoryItem', fn ($q) => $q
                ->where('inventory_id', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }
        return response()->json($query->paginate(min(100, max(1, $request->integer('per_page', 25)))));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $validated = $this->validatedPlan($request);
        $machines = $validated['machines'] ?? null;
        unset($validated['machines']);
        $inventory = AcumaticaInventoryItem::where('inventory_id', $validated['inventory_id'])->firstOrFail();
        unset($validated['inventory_id']);
        $plan = ProductionSkuPlan::withTrashed()->firstOrNew(['inventory_item_id' => $inventory->id]);
        if ($plan->exists && ! $plan->trashed()) {
            return response()->json(['message' => 'A planning record already exists for this SKU.'], 422);
        }
        if ($plan->trashed()) $plan->restore();
        $plan->fill($validated + [
            'created_by' => $plan->created_by ?: $request->user()->id,
            'updated_by' => $request->user()->id,
        ])->save();
        if ($machines !== null) $this->syncMachines($plan, $machines);
        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);
        return response()->json($plan->fresh('inventoryItem'), 201);
    }

    public function update(Request $request, ProductionSkuPlan $plan): JsonResponse
    {
        $this->authorizeManage($request);
        $validated = $this->validatedPlan($request, false);
        $machines = $validated['machines'] ?? null;
        unset($validated['machines']);
        unset($validated['inventory_id']);
        $plan->update($validated + ['updated_by' => $request->user()->id]);
        if ($machines !== null) $this->syncMachines($plan, $machines);
        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);
        return response()->json($plan->fresh('inventoryItem'));
    }

    public function destroy(Request $request, ProductionSkuPlan $plan): JsonResponse
    {
        $this->authorizeManage($request);
        $plan->update(['updated_by' => $request->user()->id]);
        $plan->delete();
        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);
        return response()->json(['message' => 'Planning record deleted.']);
    }

    public function bulkMsi(Request $request): JsonResponse
    {
        $this->authorizeManage($request);
        $rows = $request->validate(['rows' => ['required', 'array', 'min:1', 'max:10000']])['rows'];
        $created = $updated = $unmatched = $skipped = 0;
        $errors = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $inventoryId = strtoupper(trim((string) ($row['inventory_id'] ?? '')));
            if ($inventoryId === '') {
                $errors[] = ['line' => $line, 'inventory_id' => null, 'message' => 'Inventory ID is required.'];
                continue;
            }
            if (isset($seen[$inventoryId])) {
                $errors[] = ['line' => $line, 'inventory_id' => $inventoryId, 'message' => 'Duplicate Inventory ID in this file.'];
                continue;
            }
            $seen[$inventoryId] = true;
            $inventory = AcumaticaInventoryItem::whereRaw('UPPER(inventory_id) = ?', [$inventoryId])->first();
            if (! $inventory) {
                $unmatched++;
                $errors[] = ['line' => $line, 'inventory_id' => $inventoryId, 'message' => 'Inventory ID was not found in synced inventory.'];
                continue;
            }

            $values = [
                'created_by' => null,
                'updated_by' => $request->user()->id,
            ];
            $stockFields = ['msi', 'safety_stock', 'buffer_stock', 'export_msi', 'export_requirement'];
            $hasStockValue = false;
            foreach ($stockFields as $field) {
                if (! array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === null) {
                    continue;
                }
                if (! is_numeric($row[$field]) || (float) $row[$field] < 0) {
                    $errors[] = [
                        'line' => $line,
                        'inventory_id' => $inventoryId,
                        'message' => "{$field} must be a non-negative number.",
                    ];
                    continue 2;
                }
                $values[$field] = (float) $row[$field];
                $hasStockValue = true;
            }

            $hasMachines = array_key_exists('machines', $row)
                && trim(is_array($row['machines']) ? implode(',', $row['machines']) : (string) $row['machines']) !== '';

            if (! $hasStockValue && ! $hasMachines) {
                $skipped++;
                $errors[] = [
                    'line' => $line,
                    'inventory_id' => $inventoryId,
                    'message' => 'Provide at least one of MSI, Safety Stock, Buffer Stock (or machines).',
                ];
                continue;
            }

            $plan = ProductionSkuPlan::withTrashed()->firstOrNew(['inventory_item_id' => $inventory->id]);
            $exists = $plan->exists && ! $plan->trashed();
            if ($plan->trashed()) {
                $plan->restore();
            }
            $values['created_by'] = $plan->created_by ?: $request->user()->id;
            $plan->fill($values)->save();
            if ($hasMachines) {
                $machines = is_array($row['machines'])
                    ? $row['machines']
                    : preg_split('/[,;|]+/', (string) $row['machines']);
                $this->syncMachines($plan, $machines ?: []);
            }
            $exists ? $updated++ : $created++;
        }

        app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);

        return response()->json(compact('created', 'updated', 'unmatched', 'skipped', 'errors') + [
            'message' => 'MSI / safety / buffer stock import completed.',
        ]);
    }

    /**
     * Email FGS stock transfer notifications to one or more recipients.
     * Body is built server-side from the payload the production UI already computed.
     */
    public function emailTransferRequests(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'recipients' => ['required', 'array', 'min:1', 'max:20'],
            'recipients.*' => ['required', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'requests' => ['required', 'array', 'min:1', 'max:2000'],
            'requests.*.inventory_id' => ['required', 'string', 'max:100'],
            'requests.*.product_name' => ['required', 'string', 'max:500'],
            'requests.*.brand' => ['nullable', 'string', 'max:200'],
            'requests.*.source_warehouse' => ['required', 'string', 'max:200'],
            'requests.*.quantity' => ['required', 'numeric', 'min:0'],
            'requests.*.sources' => ['nullable', 'array', 'max:50'],
            'requests.*.sources.*.warehouse_name' => ['required_with:requests.*.sources', 'string', 'max:200'],
            'requests.*.sources.*.qty_on_hand' => ['nullable', 'numeric'],
            'requests.*.sources.*.qty_available' => ['nullable', 'numeric'],
        ]);

        $recipients = collect($validated['recipients'])
            ->map(fn (string $email) => strtolower(trim($email)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($recipients === []) {
            return response()->json(['message' => 'Enter at least one valid email address.'], 422);
        }

        $count = count($validated['requests']);
        $subject = "FGS Transfer Requests — {$count} ".($count === 1 ? 'SKU' : 'SKUs');
        $senderName = $request->user()?->name;

        try {
            Mail::to($recipients)->send(new StockTransferRequestMail(
                $subject,
                $validated['requests'],
                $senderName,
                $validated['note'] ?? null,
            ));
        } catch (\Throwable $e) {
            Log::error('stock_transfer_request_email_failed', [
                'user_id' => $request->user()?->id,
                'recipients' => $recipients,
                'request_count' => $count,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to send transfer request email. Please try again or contact support.',
            ], 500);
        }

        Log::info('stock_transfer_request_email_sent', [
            'user_id' => $request->user()?->id,
            'recipients' => $recipients,
            'request_count' => $count,
        ]);

        return response()->json([
            'message' => 'Transfer request notification sent.',
            'recipients' => $recipients,
            'request_count' => $count,
        ]);
    }

    private function validatedPlan(Request $request, bool $inventoryRequired = true): array
    {
        return $request->validate([
            'inventory_id' => [$inventoryRequired ? 'required' : 'sometimes', 'string', 'max:100'],
            'ownership' => ['nullable', Rule::in(['manufactured', 'partner'])],
            'business_line' => ['nullable', 'string', 'max:80'],
            'site' => ['nullable', 'string', 'max:80'],
            'machine' => ['nullable', 'string', 'max:120'],
            'machines' => ['sometimes', 'array'],
            'machines.*' => ['string', 'max:120'],
            'msi' => ['nullable', 'numeric', 'min:0'],
            'safety_stock' => ['nullable', 'numeric', 'min:0'],
            'buffer_stock' => ['nullable', 'numeric', 'min:0'],
            'export_msi' => ['nullable', 'numeric', 'min:0'],
            'export_requirement' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->planningAccess->canManage($request->user()), 403, 'You are not allowed to manage production planning (MSI / safety / buffer stock).');
    }

    private function syncMachines(ProductionSkuPlan $plan, array $names): void
    {
        $ids = collect($names)->map(fn ($name) => trim((string) $name))->filter()->unique(fn ($name) => strtolower($name))
            ->map(fn ($name) => ProductionMachine::firstOrCreate(['name' => $name])->id);
        $plan->machines()->sync($ids);
        $plan->updateQuietly(['machine' => collect($names)->map(fn ($name) => trim((string) $name))->filter()->first()]);
    }
}
