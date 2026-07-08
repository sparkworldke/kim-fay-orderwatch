<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcumaticaCustomer;
use App\Models\AcumaticaDeadLetter;
use App\Models\AcumaticaReconciliationResult;
use App\Models\AcumaticaSyncLog;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Admin\AcumaticaCustomerSyncService;
use App\Services\Admin\AcumaticaFillRateSyncService;
use App\Services\Admin\AcumaticaInventorySyncService;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Admin\AcumaticaShippingZoneSyncService;
use App\Services\Admin\AcumaticaService;
use App\Services\Admin\AcumaticaSyncDiagnosticsService;
use App\Services\Admin\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AcumaticaController extends Controller
{
    private const INVENTORY_WAREHOUSES = ['DTC', 'FGS', 'PRMS', 'RMS1', 'TRMS'];

    private const LOOKUP_DEFINITIONS = [
        'inventory_id' => [
            'label' => 'Inventory ID',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'InventoryItem', 'field' => 'InventoryID'],
                ['entity' => 'StockItem', 'field' => 'InventoryID'],
            ],
        ],
        'customer_id' => [
            'label' => 'Customer ID',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'Customer', 'field' => 'CustomerID'],
            ],
        ],
        'rep_code' => [
            'label' => 'Rep Code',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'Consultant', 'field' => 'ConsultantID'],
                ['entity' => 'SalesPerson', 'field' => 'SalesPersonID'],
            ],
        ],
        'consultant_id' => [
            'label' => 'Consultant ID',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'Consultant', 'field' => 'ConsultantID'],
            ],
        ],
        'salesperson_id' => [
            'label' => 'SalesPerson ID',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'SalesPerson', 'field' => 'SalesPersonID'],
            ],
        ],
        'zone_id' => [
            'label' => 'Zone ID',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'Zone', 'field' => 'ZoneID'],
            ],
        ],
        'route_code' => [
            'label' => 'Route Code',
            'validation' => 'code',
            'targets' => [
                ['entity' => 'Route', 'field' => 'RouteCode'],
            ],
        ],
        'route_name' => [
            'label' => 'Route Name',
            'validation' => 'name',
            'targets' => [
                ['entity' => 'Route', 'field' => 'RouteName'],
            ],
        ],
    ];

    public function __construct(
        private readonly AcumaticaService $acumatica,
        private readonly AcumaticaClient $client,
        private readonly AcumaticaCustomerSyncService $customerSync,
        private readonly AcumaticaShippingZoneSyncService $shippingZoneSync,
        private readonly AcumaticaSalesOrderSyncService $salesOrderSync,
        private readonly AcumaticaInventorySyncService $inventorySync,
        private readonly AcumaticaBackorderSyncService $backorderSync,
        private readonly AcumaticaFillRateSyncService $fillRateSync,
        private readonly AuditLogger $audit,
        private readonly AcumaticaSyncDiagnosticsService $diagnostics,
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

    public function syncShippingZones(Request $request): JsonResponse
    {
        $run = $this->shippingZoneSync->run($request->user()?->id);

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

    public function refreshOrderStatuses(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date', 'before_or_equal:date_to'],
            'date_to' => ['required', 'date'],
        ]);

        $today = now()->toDateString();
        $statusDateTo = max($validated['date_to'], $today);

        $todayImport = $this->salesOrderSync->syncDateRange(
            $today,
            $today,
            $request->user()?->id,
            'manual-status-refresh',
        );

        $statusSync = $this->salesOrderSync->syncStatusUpdatesForDateRange(
            $validated['date_from'],
            $statusDateTo,
            5000,
            $request->user()?->id,
            'manual-status-refresh',
        );

        return response()->json([
            'message' => 'Order status refresh completed.',
            'today_import' => $todayImport,
            'status_sync' => $statusSync,
            'date_from' => $validated['date_from'],
            'date_to' => $statusDateTo,
            'today' => $today,
        ]);
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

    public function syncInventory(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'string', 'in:' . implode(',', self::INVENTORY_WAREHOUSES)],
        ]);

        $run = $this->inventorySync->run(
            $request->user()?->id,
            filters: array_filter([
                'warehouse_id' => $validated['warehouse_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        );

        return response()->json(['sync_run' => $run]);
    }

    public function syncInventoryStocks(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'string', 'in:' . implode(',', self::INVENTORY_WAREHOUSES)],
        ]);

        $run = $this->inventorySync->runStocksOnly(
            $request->user()?->id,
            filters: array_filter([
                'warehouse_id' => $validated['warehouse_id'] ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
        );

        return response()->json(['sync_run' => $run]);
    }

    public function syncBackorders(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date', 'required_with:date_to', 'before_or_equal:date_to'],
            'date_to' => ['nullable', 'date', 'required_with:date_from'],
        ]);

        $run = $this->backorderSync->run(
            $request->user()?->id,
            'manual',
            null,
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null,
        );

        return response()->json(['sync_run' => $run]);
    }

    public function syncFillRate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date', 'before_or_equal:date_to'],
            'date_to'   => ['required', 'date'],
        ]);

        $run = $this->fillRateSync->syncDateRange(
            $validated['date_from'],
            $validated['date_to'],
            $request->user()?->id,
        );

        return response()->json(['sync_run' => $run]);
    }

    public function syncCreditNotesAndMore(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['required', 'date', 'before_or_equal:date_to'],
            'date_to'   => ['required', 'date'],
            'document_type' => ['nullable', 'string', 'in:QT,RC,CM,PL,all'],
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['required', 'string', 'in:QT,RC,CM,PL'],
        ]);

        $documentTypes = $validated['document_types'] ?? null;
        if (! $documentTypes && isset($validated['document_type']) && $validated['document_type'] !== 'all') {
            $documentTypes = [$validated['document_type']];
        }

        $run = $this->salesOrderSync->syncCreditNotesAndMore(
            $validated['date_from'],
            $validated['date_to'],
            $request->user()?->id,
            $documentTypes,
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

    /**
     * AI-generated read of recent sync run health — reads the last 20 runs and
     * asks OpenAI for likely causes/next steps. Falls back to a rule-based
     * summary (still useful, just not AI-authored) when no OpenAI key is
     * configured or the call fails, so this endpoint never 500s on AI issues.
     */
    public function diagnoseSyncHealth(): JsonResponse
    {
        return response()->json($this->diagnostics->diagnose());
    }

    public function stopSync(AcumaticaSyncLog $syncLog): JsonResponse
    {
        AcumaticaSyncLog::failStaleRunning([$syncLog->sync_type]);
        $syncLog->refresh();

        if (! $syncLog->isActivelyRunning()) {
            return response()->json([
                'message' => 'This sync is not currently running.',
            ], 422);
        }

        if ($syncLog->stop_requested_at !== null) {
            return response()->json([
                'message' => 'A stop request has already been sent for this sync.',
                'sync_run' => $syncLog,
            ]);
        }

        $syncLog->requestStop();

        return response()->json([
            'message' => 'Stop request sent. The sync will halt after the current batch.',
            'sync_run' => $syncLog->fresh(),
        ]);
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

        return response()->json([
            'customer_id' => $customerId,
            'shipping_zone_id' => AcumaticaClient::val($raw['ShippingZoneID'] ?? null),
            'raw' => $raw,
        ]);
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

    public function lookup(Request $request): JsonResponse
    {
        $type = trim((string) $request->query('type', ''));
        $id = trim((string) $request->query('id', ''));
        $definition = self::LOOKUP_DEFINITIONS[$type] ?? null;

        if ($definition === null) {
            return response()->json([
                'message' => 'The selected lookup type is not supported.',
                'errors' => ['type' => ['The selected lookup type is not supported.']],
            ], 422);
        }

        $validationError = $this->validateLookupId($id, $definition['validation']);
        if ($validationError !== null) {
            return response()->json([
                'message' => $validationError,
                'errors' => ['id' => [$validationError]],
            ], 422);
        }

        $endpointErrors = [];

        foreach ($definition['targets'] as $target) {
            try {
                $raw = $this->client->fetchFirstByField($target['entity'], $target['field'], $id);
            } catch (RuntimeException $e) {
                $endpointErrors[] = [
                    'entity' => $target['entity'],
                    'field' => $target['field'],
                    'message' => $e->getMessage(),
                ];
                continue;
            }

            if ($raw === null) {
                continue;
            }

            return response()->json([
                'lookup_type' => $type,
                'lookup_label' => $definition['label'],
                'lookup_id' => $id,
                'entity' => $target['entity'],
                'field' => $target['field'],
                'top_level_keys' => array_keys($raw),
                'raw' => $raw,
            ]);
        }

        if (count($endpointErrors) === count($definition['targets'])) {
            return response()->json([
                'error' => "The {$definition['label']} lookup is not available on the configured Acumatica endpoint.",
                'lookup_type' => $type,
                'lookup_id' => $id,
                'attempts' => $endpointErrors,
            ], 502);
        }

        return response()->json([
            'error' => "{$definition['label']} '{$id}' was not found in Acumatica.",
            'lookup_type' => $type,
            'lookup_id' => $id,
        ], 404);
    }

    private function validateLookupId(string $id, string $mode): ?string
    {
        if ($mode === 'name') {
            if (strlen($id) < 2 || strlen($id) > 100) {
                return 'Enter a route name between 2 and 100 characters.';
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
                return 'Route name cannot contain control characters.';
            }

            return null;
        }

        if ($id === '' || strlen($id) > 50) {
            return 'Enter an ID between 1 and 50 characters.';
        }

        if (preg_match('/^[A-Za-z0-9 ._\/-]+$/', $id) !== 1) {
            return 'IDs may only contain letters, numbers, spaces, periods, underscores, hyphens, or slashes.';
        }

        return null;
    }
}
