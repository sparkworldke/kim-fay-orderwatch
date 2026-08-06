<?php

namespace App\Services\Fol;

use App\Models\AcumaticaInventoryItem;
use App\Models\AcumaticaSalesOrder;
use App\Models\FolRequest;
use App\Models\FolSoCreateLog;
use App\Models\FolSoLink;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use App\Services\Admin\StructuredLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Creates an Acumatica Sales Order when a FOL reaches final (CCO) approval.
 *
 * Payload shape inspired by inspo/wc-ipay-accumatica Accumatica::createOrder
 * (PUT + customer + inventory lines), adapted to IpayV2 SalesOrder fields.
 *
 * Failures are written to fol_so_create_logs + StructuredLogger + FOL events.
 * A cron job retries FOLs that were final-approved but never got an SO.
 */
class FolAcumaticaSalesOrderService
{
    public function __construct(
        private readonly AcumaticaClient $client,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('fol.create_so_on_final_approval', true);
    }

    /**
     * @return array{
     *   ok: bool,
     *   order_nbr: ?string,
     *   so_status: ?string,
     *   so_total: float|null,
     *   error: ?string,
     *   skipped: bool,
     *   already_linked: bool,
     *   log_id: int|null
     * }
     */
    public function createAndLink(
        FolRequest $request,
        ?User $actor = null,
        string $attemptSource = 'cco_approve',
        ?int $cronRunLogId = null,
    ): array {
        if (! $this->isEnabled()) {
            $result = $this->result(false, null, null, null, null, true, false);
            $this->writeLog($request, $attemptSource, 'skipped', $result, $actor, $cronRunLogId, [
                'reason' => 'fol.create_so_on_final_approval disabled',
            ]);

            return $result;
        }

        $request->loadMissing(['lines', 'soLinks']);

        // Already has any SO link (auto or manual) — treat as linked for cron.
        $existingLink = $request->soLinks->first();
        if ($existingLink && $this->hasAttachedSoNumber($request)) {
            $result = $this->result(
                true,
                (string) $existingLink->acumatica_order_nbr,
                $request->linked_so_status_summary,
                null,
                null,
                false,
                true,
            );
            $this->writeLog($request, $attemptSource, 'already_linked', $result, $actor, $cronRunLogId);

            return $result;
        }

        if ($request->lines->isEmpty()) {
            $result = $this->result(false, null, null, null, 'FOL has no lines to put on a sales order.', false, false);
            $this->writeLog($request, $attemptSource, 'failed', $result, $actor, $cronRunLogId);

            return $result;
        }

        try {
            $lines = $this->buildLines($request);
            $created = $this->client->createSalesOrder(
                customerId: (string) $request->customer_acumatica_id,
                lines: $lines,
                customerOrder: (string) $request->public_ref,
                description: $this->descriptionFor($request),
                orderType: (string) config('fol.so_order_type', 'SO'),
                zeroPrice: (bool) config('fol.so_zero_unit_price', true),
            );

            $orderNbr = $created['order_nbr'];

            // Pull full SO back from Acumatica (same idea as WC plugin getOrder after create).
            $pulled = $this->pullSalesOrder($orderNbr);
            $raw = $pulled !== [] ? $pulled : ($created['raw'] ?? []);

            $this->persistLocalLink($request, $orderNbr, $actor, $raw);

            // Re-read request so linked_so_order_nbrs is current for present()/email.
            $request->refresh();
            $request->load(['soLinks', 'lines']);

            $result = $this->result(
                true,
                $orderNbr,
                AcumaticaClient::scalarVal($raw['Status'] ?? null),
                (float) (AcumaticaClient::val($raw['OrderTotal'] ?? null) ?? 0),
                null,
                false,
                false,
            );
            $this->writeLog($request, $attemptSource, 'success', $result, $actor, $cronRunLogId, [
                'pulled_from_erp' => $pulled !== [],
            ]);

            return $result;
        } catch (Throwable $e) {
            StructuredLogger::write('error', 'fol', 'so_create_failed', [
                'fol_id' => $request->id,
                'public_ref' => $request->public_ref,
                'customer_acumatica_id' => $request->customer_acumatica_id,
                'attempt_source' => $attemptSource,
                'error' => $e->getMessage(),
            ], $actor?->id);

            Log::error('fol_so_create_failed', [
                'fol_id' => $request->id,
                'public_ref' => $request->public_ref,
                'customer' => $request->customer_acumatica_id,
                'attempt_source' => $attemptSource,
                'error' => $e->getMessage(),
            ]);

            $result = $this->result(false, null, null, null, $e->getMessage(), false, false);
            $this->writeLog($request, $attemptSource, 'failed', $result, $actor, $cronRunLogId, [
                'exception_class' => $e::class,
            ]);

            return $result;
        }
    }

    /**
     * Final-approved FOLs that should have an SO but do not yet.
     *
     * @return Collection<int, FolRequest>
     */
    public function missingSalesOrderRequests(int $limit = 50): Collection
    {
        $limit = max(1, min($limit, 200));

        return $this->missingSalesOrderQuery()
            ->with(['lines', 'soLinks'])
            ->orderBy('decided_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function missingSalesOrderQuery(): Builder
    {
        return FolRequest::query()
            ->whereIn('status', ['ready_for_invoicing', 'approved_final'])
            ->whereNotNull('decided_at')
            ->where(function (Builder $q) {
                $q->whereNull('linked_so_order_nbrs')
                    ->orWhere('linked_so_order_nbrs', '[]')
                    ->orWhere('linked_so_order_nbrs', 'null')
                    ->orWhere('linked_so_order_nbrs', '');
            })
            ->whereDoesntHave('soLinks');
    }

    /**
     * Cron/manual retry: create SOs for FOLs missing an attachment.
     *
     * @return array{
     *   checked: int,
     *   created: int,
     *   failed: int,
     *   skipped: int,
     *   already_linked: int,
     *   failures: list<array{fol_id: int, public_ref: string, error: ?string}>
     * }
     */
    public function retryMissing(
        int $limit = 50,
        string $attemptSource = 'cron_retry',
        ?int $cronRunLogId = null,
        ?User $actor = null,
    ): array {
        $requests = $this->missingSalesOrderRequests($limit);
        $created = 0;
        $failed = 0;
        $skipped = 0;
        $alreadyLinked = 0;
        $failures = [];

        foreach ($requests as $request) {
            $result = $this->createAndLink($request, $actor, $attemptSource, $cronRunLogId);

            if ($result['already_linked']) {
                $alreadyLinked++;
                continue;
            }
            if ($result['skipped']) {
                $skipped++;
                continue;
            }
            if ($result['ok'] && $result['order_nbr']) {
                $created++;
                continue;
            }

            $failed++;
            $failures[] = [
                'fol_id' => $request->id,
                'public_ref' => (string) $request->public_ref,
                'error' => $result['error'],
            ];
        }

        StructuredLogger::write('info', 'fol', 'so_retry_batch_completed', [
            'attempt_source' => $attemptSource,
            'cron_run_log_id' => $cronRunLogId,
            'checked' => $requests->count(),
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped,
            'already_linked' => $alreadyLinked,
        ], $actor?->id);

        return [
            'checked' => $requests->count(),
            'created' => $created,
            'failed' => $failed,
            'skipped' => $skipped,
            'already_linked' => $alreadyLinked,
            'failures' => $failures,
        ];
    }

    private function hasAttachedSoNumber(FolRequest $request): bool
    {
        $numbers = $request->linked_so_order_nbrs;
        if (is_array($numbers)) {
            foreach ($numbers as $n) {
                if (is_string($n) && trim($n) !== '') {
                    return true;
                }
            }
        }

        return $request->soLinks->isNotEmpty();
    }

    /**
     * @return array{
     *   ok: bool,
     *   order_nbr: ?string,
     *   so_status: ?string,
     *   so_total: float|null,
     *   error: ?string,
     *   skipped: bool,
     *   already_linked: bool,
     *   log_id: int|null
     * }
     */
    private function result(
        bool $ok,
        ?string $orderNbr,
        ?string $soStatus,
        ?float $soTotal,
        ?string $error,
        bool $skipped,
        bool $alreadyLinked,
    ): array {
        return [
            'ok' => $ok,
            'order_nbr' => $orderNbr,
            'so_status' => $soStatus,
            'so_total' => $soTotal,
            'error' => $error,
            'skipped' => $skipped,
            'already_linked' => $alreadyLinked,
            'log_id' => null,
        ];
    }

    /**
     * Persist an attempt log row (always, especially on failure).
     *
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $extra
     */
    private function writeLog(
        FolRequest $request,
        string $attemptSource,
        string $status,
        array &$result,
        ?User $actor,
        ?int $cronRunLogId,
        array $extra = [],
    ): void {
        try {
            $log = FolSoCreateLog::create([
                'fol_request_id' => $request->id,
                'public_ref' => $request->public_ref,
                'customer_acumatica_id' => $request->customer_acumatica_id,
                'attempt_source' => $attemptSource,
                'status' => $status,
                'acumatica_order_nbr' => $result['order_nbr'] ?? null,
                'error_message' => $result['error'] ?? null,
                'payload_json' => [
                    ...$extra,
                    'so_status' => $result['so_status'] ?? null,
                    'so_total' => $result['so_total'] ?? null,
                    'skipped' => $result['skipped'] ?? false,
                    'already_linked' => $result['already_linked'] ?? false,
                    'lines_count' => $request->relationLoaded('lines') ? $request->lines->count() : null,
                ],
                'actor_user_id' => $actor?->id,
                'cron_run_log_id' => $cronRunLogId,
            ]);
            $result['log_id'] = $log->id;
        } catch (Throwable $e) {
            // Never break the FOL workflow because logging failed.
            Log::error('fol_so_create_log_write_failed', [
                'fol_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }

        if ($status === 'failed') {
            StructuredLogger::write('warning', 'fol', 'so_create_log_recorded', [
                'fol_id' => $request->id,
                'public_ref' => $request->public_ref,
                'attempt_source' => $attemptSource,
                'error' => $result['error'] ?? null,
                'log_id' => $result['log_id'] ?? null,
            ], $actor?->id);
        }
    }

    /**
     * Fetch the newly created SO from Acumatica so FOL stores the real OrderNbr + status.
     *
     * @return array<string, mixed>
     */
    private function pullSalesOrder(string $orderNbr): array
    {
        try {
            $rows = $this->client->fetchSalesOrdersByNumbers([$orderNbr]);
            if ($rows === []) {
                return [];
            }

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $nbr = AcumaticaClient::scalarVal($row['OrderNbr'] ?? null);
                if ($nbr !== null && strcasecmp($nbr, $orderNbr) === 0) {
                    return $row;
                }
            }

            return is_array($rows[0] ?? null) ? $rows[0] : [];
        } catch (Throwable $e) {
            Log::warning('fol_so_pull_failed', [
                'order_nbr' => $orderNbr,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<array{inventory_id: string, qty: float, unit_price: float, warehouse_id: ?string, description: ?string}>
     */
    private function buildLines(FolRequest $request): array
    {
        $inventoryIds = $request->lines->pluck('inventory_id')->filter()->unique()->values()->all();
        $warehouses = AcumaticaInventoryItem::query()
            ->whereIn('inventory_id', $inventoryIds)
            ->pluck('default_warehouse_id', 'inventory_id');

        $defaultWarehouse = config('fol.so_default_warehouse_id');

        $lines = [];
        foreach ($request->lines as $line) {
            $qty = (float) $line->qty_requested;
            if ($qty <= 0) {
                continue;
            }

            $wh = $warehouses->get($line->inventory_id)
                ?: ($defaultWarehouse ? (string) $defaultWarehouse : null);

            $lines[] = [
                'inventory_id' => (string) $line->inventory_id,
                'qty' => $qty,
                'unit_price' => 0.0,
                'warehouse_id' => $wh ? (string) $wh : null,
                'description' => $line->product_description ? (string) $line->product_description : null,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('No positive-qty FOL lines available for sales order.');
        }

        return $lines;
    }

    private function descriptionFor(FolRequest $request): string
    {
        $bits = [
            'FOL Free On Loan',
            (string) $request->public_ref,
            (string) $request->customer_name,
        ];

        return mb_substr(implode(' — ', array_filter($bits)), 0, 240);
    }

    /** @param  array<string, mixed>  $raw */
    private function persistLocalLink(FolRequest $request, string $orderNbr, ?User $actor, array $raw): void
    {
        $status = AcumaticaClient::scalarVal($raw['Status'] ?? null) ?? 'Open';
        $orderDate = AcumaticaClient::scalarVal($raw['Date'] ?? $raw['OrderDate'] ?? null);
        $customerOrder = AcumaticaClient::scalarVal($raw['CustomerOrder'] ?? null) ?: $request->public_ref;
        $orderTotal = (float) (AcumaticaClient::val($raw['OrderTotal'] ?? null) ?? 0);

        $pulledNbr = AcumaticaClient::scalarVal($raw['OrderNbr'] ?? null);
        if ($pulledNbr) {
            $orderNbr = $pulledNbr;
        }

        $localOrder = AcumaticaSalesOrder::query()->updateOrCreate(
            ['acumatica_order_nbr' => $orderNbr],
            [
                'order_type' => AcumaticaSalesOrder::TYPE_SALES_ORDER,
                'customer_acumatica_id' => $request->customer_acumatica_id,
                'customer_name' => $request->customer_name,
                'customer_order' => $customerOrder,
                'status' => $status,
                'order_date' => $orderDate ?: now('Africa/Nairobi')->toDateString(),
                'order_total' => $orderTotal,
                'import_source' => 'fol_auto_cco',
                'raw_payload' => $raw !== [] ? $raw : null,
                'synced_at' => now(),
            ],
        );

        FolSoLink::updateOrCreate(
            [
                'fol_request_id' => $request->id,
                'acumatica_order_nbr' => $orderNbr,
            ],
            [
                'sales_order_id' => $localOrder->id,
                'link_type' => 'auto_cco_approve',
                'matched_at' => now(),
                'matched_by' => $actor?->id,
            ],
        );

        $links = $request->soLinks()->pluck('acumatica_order_nbr')->push($orderNbr)->unique()->values()->all();
        $request->forceFill([
            'status' => 'so_linked',
            'linked_so_order_nbrs' => array_values($links),
            'linked_so_status_summary' => (string) $status,
        ])->save();
    }
}
