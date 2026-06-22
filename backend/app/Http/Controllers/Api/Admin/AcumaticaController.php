<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaReconciliationResult;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AcumaticaCustomerSyncService;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Admin\AcumaticaService;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcumaticaController extends Controller
{
    public function __construct(
        private readonly AcumaticaService $acumatica,
        private readonly AcumaticaClient $client,
        private readonly AcumaticaCustomerSyncService $customerSync,
        private readonly AcumaticaSalesOrderSyncService $salesOrderSync,
        private readonly AuditLogger $audit,
    ) {
    }

    // -------------------------------------------------------------------------
    // Config
    // -------------------------------------------------------------------------

    public function index(): JsonResponse
    {
        return response()->json($this->acumatica->summary());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'base_url'      => ['required', 'url', 'max:500'],
            'endpoint'      => ['required', 'string', 'max:100'],
            'version'       => ['required', 'string', 'max:50'],
            'tenant'        => ['required', 'string', 'max:255'],
            'username'      => ['required', 'string', 'max:255'],
            'token_url'     => ['required', 'url', 'max:500'],
            'password'      => ['nullable', 'string'],
            'client_id'     => ['nullable', 'string'],
            'client_secret' => ['nullable', 'string'],
        ]);

        $before = $this->acumatica->present($this->acumatica->config());
        $config = $this->acumatica->update($validated);
        $after  = $this->acumatica->present($config);

        $this->audit->log('acumatica_config_updated', 'acumatica_config', $config->id, [
            'before' => $before,
            'after'  => $after,
        ], $request->user()?->id, $request->ip());

        return response()->json(['config' => $after]);
    }

    public function validateCredentials(Request $request): JsonResponse
    {
        $result = $this->acumatica->validateCredentials();

        if (! $result['success']) {
            $this->audit->log('acumatica_auth_failure', 'acumatica_config', $this->acumatica->config()->id, [
                'message' => $result['message'],
            ], $request->user()?->id, $request->ip());
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // -------------------------------------------------------------------------
    // Sync triggers
    // -------------------------------------------------------------------------

    public function syncCustomers(Request $request): JsonResponse
    {
        $run = $this->customerSync->run($request->user()?->id);

        return response()->json(['sync_run' => $run]);
    }

    public function syncOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date', 'before_or_equal:date_to'],
            'date_to'   => ['required', 'date'],
        ]);

        $run = $this->salesOrderSync->syncDateRange(
            $validated['date_from'],
            $validated['date_to'],
            $request->user()?->id,
        );

        return response()->json(['sync_run' => $run]);
    }

    public function syncCustomerOrders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'customer_ids.*' => ['required', 'string', 'max:50'],
        ]);

        $run = $this->salesOrderSync->syncForCustomers(
            $validated['customer_ids'],
            $request->user()?->id,
        );

        return response()->json(['sync_run' => $run]);
    }

    // -------------------------------------------------------------------------
    // Sync logs
    // -------------------------------------------------------------------------

    public function syncLogs(): JsonResponse
    {
        $logs = AcumaticaSyncLog::orderByDesc('started_at')->limit(50)->get();

        return response()->json($logs);
    }

    // -------------------------------------------------------------------------
    // Reconciliation
    // -------------------------------------------------------------------------

    public function reconciliation(Request $request): JsonResponse
    {
        $query = AcumaticaReconciliationResult::query()
            ->orderByDesc('created_at');

        if ($request->has('resource_type')) {
            $query->where('resource_type', $request->input('resource_type'));
        }

        if ($request->has('severity')) {
            $query->where('severity', $request->input('severity'));
        }

        if ($request->has('status')) {
            $query->where('remediation_status', $request->input('status'));
        }

        return response()->json($query->paginate(50));
    }

    public function updateReconciliationStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'remediation_status' => ['required', 'string', 'in:open,resolved,ignored'],
        ]);

        $result = AcumaticaReconciliationResult::findOrFail($id);
        $result->update(['remediation_status' => $validated['remediation_status']]);

        return response()->json($result);
    }

    // -------------------------------------------------------------------------
    // Dead letters
    // -------------------------------------------------------------------------

    public function deadLetters(Request $request): JsonResponse
    {
        $query = AcumaticaDeadLetter::query()
            ->orderByDesc('created_at');

        if ($request->has('resource_type')) {
            $query->where('resource_type', $request->input('resource_type'));
        }

        return response()->json($query->paginate(50));
    }

    // -------------------------------------------------------------------------
    // Customer search (for selective order sync)
    // -------------------------------------------------------------------------

    public function searchCustomers(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));

        $query = AcumaticaCustomer::query()
            ->select(['id', 'acumatica_id', 'name', 'customer_class', 'email', 'status'])
            ->orderBy('name')
            ->limit(30);

        if ($q !== '') {
            $query->where(function ($qb) use ($q) {
                $qb->where('name', 'like', "%{$q}%")
                   ->orWhere('acumatica_id', 'like', "%{$q}%");
            });
        }

        return response()->json($query->get());
    }

    public function previewCustomer(Request $request, string $customerId): JsonResponse
    {
        $raw = $this->client->fetchCustomer($customerId);

        if ($raw === null) {
            return response()->json(['error' => "Customer '{$customerId}' not found in Acumatica"], 404);
        }

        return response()->json(['customer_id' => $customerId, 'raw' => $raw]);
    }

    public function previewOrder(Request $request, string $orderNbr): JsonResponse
    {
        $raw = $this->client->fetchOrderByNumber($orderNbr);

        if ($raw === null) {
            return response()->json(['error' => "Order '{$orderNbr}' not found in Acumatica"], 404);
        }

        // Return all top-level keys so we can discover correct $expand names
        $topLevelKeys = array_keys($raw);

        return response()->json([
            'order_nbr'      => $orderNbr,
            'top_level_keys' => $topLevelKeys,
            'raw'            => $raw,
        ]);
    }
}
