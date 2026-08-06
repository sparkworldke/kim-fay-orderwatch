<?php

namespace App\Console\Commands;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Models\AcumaticaSalesOrderLine;
use App\Models\FulfillmentHistorySnapshot;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-fetch one or more SOs from Acumatica and refresh SO lines + backorder rows.
 *
 * Example:
 *   php artisan orderwatch:resync-so SO359099
 *   php artisan orderwatch:resync-so SO359099 SO359100 --orders-only
 */
class ResyncSalesOrder extends Command
{
    protected $signature = 'orderwatch:resync-so
        {orders* : One or more Acumatica order numbers (e.g. SO359099)}
        {--orders-only : Only re-sync sales order header/lines (skip backorder table)}
        {--backorders-only : Only refresh backorder lines (skip SO re-sync)}
        {--user-id= : Optional user id for audit}';

    protected $description = 'Re-sync specific sales order(s) from Acumatica by OrderNbr and refresh backorder calculation (open_qty × unit_price).';

    public function handle(
        AcumaticaSalesOrderSyncService $salesOrders,
        AcumaticaBackorderSyncService $backorders,
    ): int {
        $orders = array_values(array_unique(array_map(
            static function (string $n): string {
                $n = strtoupper(trim($n));
                if ($n !== '' && ! str_starts_with($n, 'SO') && ctype_digit($n)) {
                    return 'SO'.$n;
                }

                return $n;
            },
            $this->argument('orders'),
        )));

        $ordersOnly = (bool) $this->option('orders-only');
        $backordersOnly = (bool) $this->option('backorders-only');
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;

        if ($ordersOnly && $backordersOnly) {
            $this->error('Use only one of --orders-only or --backorders-only.');

            return self::FAILURE;
        }

        $this->info('Resync SO: '.implode(', ', $orders));
        $this->line('Formula: revenue_at_risk = open_qty × unit_price (not order total).');

        $failed = false;

        if (! $backordersOnly) {
            $this->newLine();
            $this->info('Step 1 — Fetching sales order(s) from Acumatica…');
            try {
                $soRun = $salesOrders->syncByOrderNumbers($orders, $userId, 'manual');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['sync_log_id', $soRun->id],
                        ['status', $soRun->status],
                        ['orders', $soRun->record_count],
                        ['success', $soRun->success_count],
                        ['failed', $soRun->failed_count],
                        ['error', $soRun->error_message ?: '—'],
                    ],
                );
                if ($soRun->status === 'failed') {
                    $failed = true;
                }
            } catch (Throwable $e) {
                $this->error('Sales order sync failed: '.$e->getMessage());
                $failed = true;
            }
        }

        if (! $ordersOnly) {
            $this->newLine();
            $step = $backordersOnly ? 'Step 1' : 'Step 2';
            $this->info("{$step} — Refreshing backorder lines for these SO(s)…");
            try {
                $boRun = $backorders->runForOrderNumbers($orders, $userId, 'manual');
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['sync_log_id', $boRun->id],
                        ['status', $boRun->status],
                        ['orders_fetched', $boRun->record_count],
                        ['lines_upserted', $boRun->success_count],
                        ['failed', $boRun->failed_count],
                        ['error', $boRun->error_message ?: '—'],
                    ],
                );
                if ($boRun->status === 'failed') {
                    $failed = true;
                }
            } catch (Throwable $e) {
                $this->error('Backorder refresh failed: '.$e->getMessage());
                $failed = true;
            }
        }

        $this->newLine();
        $this->info('Local verification');
        foreach ($orders as $nbr) {
            $this->printLocalState($nbr);
        }

        if ($failed) {
            $this->warn('Finished with errors. Check acumatica_sync_logs / dead letters.');

            return self::FAILURE;
        }

        $this->info('Done. Hard-refresh the SO detail page in the UI.');

        return self::SUCCESS;
    }

    private function printLocalState(string $orderNbr): void
    {
        $order = AcumaticaSalesOrder::query()
            ->where('acumatica_order_nbr', $orderNbr)
            ->first();

        if (! $order) {
            $this->warn("  {$orderNbr}: not in local DB");

            return;
        }

        $this->line("  {$orderNbr}  status={$order->status}  date={$order->order_date}");

        $lines = AcumaticaSalesOrderLine::query()
            ->where('sales_order_id', $order->id)
            ->orderBy('id')
            ->get();

        $rows = [];
        $soBackorderValue = 0.0;
        foreach ($lines as $line) {
            $open = (float) ($line->open_qty ?? 0);
            $price = (float) ($line->unit_price ?? 0);
            $value = round($open * $price, 2);
            $soBackorderValue += $value;
            $rows[] = [
                $line->inventory_id,
                $line->order_qty,
                $line->open_qty,
                $line->shipped_qty,
                $line->unit_price,
                $line->backorder_qty,
                number_format($value, 2, '.', ','),
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Inventory', 'OrderQty', 'OpenQty', 'Shipped', 'UnitPrice', 'BO Qty', 'Open×Price'],
                $rows,
            );
            $this->line('  SO-line backorder sum (open×price): '.number_format($soBackorderValue, 2, '.', ','));
        }

        $bos = AcumaticaBackorderLine::query()
            ->where('order_nbr', $orderNbr)
            ->orderBy('inventory_id')
            ->get();

        if ($bos->isEmpty()) {
            $this->line('  backorder table: (no rows)');
        } else {
            $boRows = [];
            $boSum = 0.0;
            foreach ($bos as $bo) {
                $risk = (float) ($bo->revenue_at_risk ?? 0);
                $boSum += $risk;
                $boRows[] = [
                    $bo->inventory_id,
                    $bo->open_qty,
                    $bo->unit_price,
                    number_format($risk, 2, '.', ','),
                    $bo->reason_code ?: '—',
                ];
            }
            $this->table(
                ['BO Inventory', 'OpenQty', 'UnitPrice', 'Revenue at risk', 'Reason'],
                $boRows,
            );
            $this->line('  backorder table sum: '.number_format($boSum, 2, '.', ','));
        }

        $history = FulfillmentHistorySnapshot::with('lines')->where('order_nbr', $orderNbr)->first();
        if (! $history) {
            $this->line('  historical snapshot: unavailable (current resync does not manufacture history)');
        } else {
            $this->line('  historical snapshot '.$history->observed_at.' source='.$history->source);
            $this->line('  historical shortfall: '.number_format((float) $history->historical_shortfall_amount, 2, '.', ','));
        }
    }
}
