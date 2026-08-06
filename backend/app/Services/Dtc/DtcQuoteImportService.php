<?php

namespace App\Services\Dtc;

use App\Models\AcumaticaCustomer;
use App\Models\DtcQuote;
use App\Models\DtcSyncLog;
use App\Models\User;
use App\Services\Admin\AcumaticaClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

class DtcQuoteImportService
{
    public function __construct(private readonly AcumaticaClient $client) {}

    /**
     * Import QT documents for the POS customer.
     *
     * @param  string|null  $dateFrom  YYYY-MM-DD inclusive (null + null dateTo = all)
     * @param  string|null  $dateTo    YYYY-MM-DD inclusive
     * @return array{ok:bool,customer_id:string,date_from:?string,date_to:?string,scope:string,fetched:int,created:int,updated:int,total_amount:float}
     */
    public function import(?string $dateFrom, ?string $dateTo, User $actor): array
    {
        // Backward compat: single-day callers pass dateFrom only.
        if ($dateFrom !== null && $dateTo === null) {
            $dateTo = $dateFrom;
        }

        $scope = ($dateFrom && $dateTo) ? 'range' : 'all';
        $log = DtcSyncLog::create([
            'sync_type' => 'quotes',
            'status' => 'running',
            'triggered_by' => $actor->id,
            'started_at' => now(),
            'metadata' => [
                'customer_id' => DtcQuoteService::POS_ORDER_CUSTOMER_ID,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'scope' => $scope,
            ],
        ]);

        try {
            $rows = $this->client->fetchDtcCustomerQuotes($dateFrom, $dateTo);
            $created = 0;
            $updated = 0;
            $totalAmount = 0.0;

            foreach ($rows as $raw) {
                $nbr = trim((string) AcumaticaClient::scalarVal($raw['OrderNbr'] ?? null));
                if ($nbr === '') {
                    continue;
                }
                $existing = DtcQuote::where('acumatica_quote_nbr', $nbr)->first();
                DB::transaction(function () use ($raw, $nbr, $actor, &$created, &$updated, &$totalAmount, $existing) {
                    $customerId = (string) (AcumaticaClient::scalarVal($raw['CustomerID'] ?? $raw['Customer'] ?? null) ?: DtcQuoteService::POS_ORDER_CUSTOMER_ID);
                    $customerName = AcumaticaCustomer::where('acumatica_id', $customerId)->value('name') ?? 'Calltronix DTC/DTB';
                    $quote = $existing ?? new DtcQuote(['public_ref' => $this->importRef($nbr), 'created_by' => $actor->id]);
                    $details = is_array($raw['Details'] ?? null) ? $raw['Details'] : [];
                    $lines = [];
                    foreach ($details as $line) {
                        $inventory = (string) AcumaticaClient::scalarVal($line['InventoryID'] ?? $line['InventoryId'] ?? null);
                        $qty = (float) (AcumaticaClient::val($line['OrderQty'] ?? $line['Quantity'] ?? null) ?? 0);
                        if ($inventory === '' || $qty <= 0) {
                            continue;
                        }
                        $price = (float) (AcumaticaClient::val($line['UnitPrice'] ?? null) ?? 0);
                        $lines[] = [
                            'inventory_id' => $inventory,
                            'description' => AcumaticaClient::scalarVal($line['LineDescription'] ?? $line['Description'] ?? null),
                            'uom' => (string) (AcumaticaClient::scalarVal($line['UOM'] ?? null) ?: 'PIECE'),
                            'warehouse_id' => AcumaticaClient::scalarVal($line['WarehouseID'] ?? null),
                            'quantity' => $qty,
                            'unit_price' => $price,
                            'line_total' => round($qty * $price, 2),
                        ];
                    }
                    $detailsSnap = DtcQuoteService::extractCustomerDetails($raw, $customerName);
                    $quotedTotal = (float) (AcumaticaClient::val($raw['OrderTotal'] ?? null) ?? collect($lines)->sum('line_total'));
                    $totalAmount += $quotedTotal;
                    $quote->fill([
                        'acumatica_quote_nbr' => $nbr,
                        'acumatica_quote_id' => isset($raw['id']) ? (string) $raw['id'] : null,
                        'customer_acumatica_id' => $customerId,
                        'customer_name' => $detailsSnap['name'] ?: $customerName,
                        'customer_details_snapshot' => $detailsSnap,
                        'status' => $quote->status === 'converted' ? 'converted' : 'submitted',
                        'description' => AcumaticaClient::scalarVal($raw['Description'] ?? null),
                        'quoted_total' => $quotedTotal,
                        'currency_id' => (string) (AcumaticaClient::scalarVal($raw['CurrencyID'] ?? null) ?: 'KES'),
                        'submitted_at' => $this->dateTime(AcumaticaClient::scalarVal($raw['Date'] ?? null)) ?? now(),
                        'acumatica_response' => $this->safe($raw),
                    ])->save();
                    $quote->lines()->delete();
                    if ($lines !== []) {
                        $quote->lines()->createMany($lines);
                    }
                    $existing ? $updated++ : $created++;
                });
            }

            $log->update([
                'status' => 'success',
                'records_processed' => $created + $updated,
                'finished_at' => now(),
                'metadata' => array_merge($log->metadata ?? [], [
                    'created' => $created,
                    'updated' => $updated,
                    'total_amount' => round($totalAmount, 2),
                ]),
            ]);

            return [
                'ok' => true,
                'customer_id' => DtcQuoteService::POS_ORDER_CUSTOMER_ID,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'scope' => $scope,
                'fetched' => count($rows),
                'created' => $created,
                'updated' => $updated,
                'total_amount' => round($totalAmount, 2),
            ];
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }
    }

    private function importRef(string $nbr): string
    {
        return substr('ACQ-'.$nbr, 0, 30);
    }

    private function dateTime(mixed $value): ?Carbon
    {
        try {
            return $value ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function safe(array $raw): array
    {
        return array_filter([
            'id' => $raw['id'] ?? null,
            'OrderNbr' => $raw['OrderNbr'] ?? null,
            'OrderType' => $raw['OrderType'] ?? null,
            'Status' => $raw['Status'] ?? null,
            'Date' => $raw['Date'] ?? null,
            'OrderTotal' => $raw['OrderTotal'] ?? null,
            'CurrencyID' => $raw['CurrencyID'] ?? null,
            'import_customer_id' => DtcQuoteService::POS_ORDER_CUSTOMER_ID,
        ], fn ($v) => $v !== null);
    }
}
