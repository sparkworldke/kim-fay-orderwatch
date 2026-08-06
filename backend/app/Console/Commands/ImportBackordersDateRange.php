<?php

namespace App\Console\Commands;

use App\Models\AcumaticaBackorderLine;
use App\Models\AcumaticaSalesOrder;
use App\Services\Admin\AcumaticaBackorderSyncService;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use App\Services\Admin\SalesOrderLineFulfillmentDeriver;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-import active backorder lines for a date range (separate from SO sync).
 *
 * Run as TWO steps so long Acumatica pulls do not die with SSH:
 *
 *   # 1) Sales orders only (detach if needed)
 *   nohup php artisan orderwatch:sales-orders-sync \
 *     --date-from=2026-07-22 --date-to=2026-07-25 --source=cli \
 *     > /tmp/so-sync-0722-25.log 2>&1 &
 *
 *   # 2) Backorders only (after step 1 finishes)
 *   nohup php artisan orderwatch:import-backorders \
 *     --from=2026-07-22 --to=2026-07-25 --by-day --summary \
 *     > /tmp/bo-sync-0722-25.log 2>&1 &
 *
 *   # Or summary only (no Acumatica call):
 *   php artisan orderwatch:import-backorders --from=2026-07-22 --to=2026-07-25 --summary-only
 */
class ImportBackordersDateRange extends Command
{
    protected $signature = 'orderwatch:import-backorders
        {--from= : Start date Y-m-d}
        {--to= : End date Y-m-d}
        {--by-day : Process one calendar day at a time (safer for SSH / timeouts)}
        {--with-completed-recon : Also rebuild completed-order shortfalls (slower full pull)}
        {--resync-orders : DEPRECATED combined mode — prefer separate sales-orders-sync command}
        {--orders-only : Only re-sync sales orders (use orderwatch:sales-orders-sync instead)}
        {--summary : Print open_qty × unit_price totals after import}
        {--summary-only : Only print local DB summary (no Acumatica call)}
        {--user-id= : Optional user id for audit}';

    protected $description = 'Import active Acumatica backorders for a date range (open_qty × unit_price). Run separately from SO sync.';

    public function handle(
        AcumaticaBackorderSyncService $backorders,
        AcumaticaSalesOrderSyncService $salesOrders,
    ): int {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        $from = $this->resolveFrom();
        $to = $this->resolveTo();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $byDay = (bool) $this->option('by-day');
        $withCompletedRecon = (bool) $this->option('with-completed-recon');
        $resyncOrders = (bool) $this->option('resync-orders');
        $ordersOnly = (bool) $this->option('orders-only');
        $summaryOnly = (bool) $this->option('summary-only');
        $printSummary = (bool) $this->option('summary') || $summaryOnly;

        if ($from === null || $to === null) {
            $this->error('Provide --from and --to as Y-m-d (e.g. --from=2026-07-22 --to=2026-07-25).');

            return self::FAILURE;
        }

        if ($from > $to) {
            $this->error('--from must be on or before --to.');

            return self::FAILURE;
        }

        $this->info("Window: {$from} → {$to}");
        $this->line('Active backorders: non-terminal SOs · open_qty × unit_price');
        $this->newLine();
        $this->comment('Tip: full catch-up (SO + backorders) in one force/manual command:');
        $this->line('  nohup php artisan orderwatch:sales-orders-sync --date-from='.$from.' --date-to='.$to.' --source=manual --force > /tmp/so-bo-sync.log 2>&1 &');
        $this->comment('Or backorders only (after SO is already current):');
        $this->line('  nohup php artisan orderwatch:import-backorders --from='.$from.' --to='.$to.' --by-day --summary > /tmp/bo-sync.log 2>&1 &');
        $this->newLine();

        if ($summaryOnly) {
            $this->printPeriodSummary($from, $to);

            return self::SUCCESS;
        }

        $failed = false;

        // --- Optional SO step (discouraged combined path) ---
        if ($resyncOrders || $ordersOnly) {
            $this->warn('Combined SO+backorder mode is slow and often dies on SSH. Prefer separate commands (see tip above).');
            $this->info('Sales-order re-sync '.$from.' → '.$to.' …');
            try {
                foreach ($this->dayChunks($from, $to, $byDay || true) as $dayFrom => $dayTo) {
                    // Always day-chunk SO when combined — full multi-day pull is the usual hang.
                    $this->line('  SO day '.$dayFrom.' …');
                    $soRun = $salesOrders->syncDateRange($dayFrom, $dayTo, $userId, 'manual');
                    $this->line("    status={$soRun->status} orders={$soRun->record_count} ok={$soRun->success_count} fail={$soRun->failed_count}");
                    if ($soRun->status === 'failed') {
                        $failed = true;
                        $this->error('    '.$soRun->error_message);
                    }
                }
            } catch (Throwable $e) {
                $this->error('Sales order sync failed: '.$e->getMessage());
                $failed = true;
            }

            if ($ordersOnly) {
                return $failed ? self::FAILURE : self::SUCCESS;
            }
            if ($failed) {
                $this->warn('Stopping before backorder step because SO sync failed.');

                return self::FAILURE;
            }
        }

        // --- Backorder step only (default) ---
        $this->info('Importing active backorder lines…');
        $options = [
            'active_open_fetch' => true,
            'skip_completed_recon' => ! $withCompletedRecon,
            'on_progress' => function (string $message): void {
                $this->line('  '.now()->format('H:i:s').'  '.$message);
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            },
        ];

        if ($withCompletedRecon) {
            $this->warn('--with-completed-recon enabled: slower full pull + invoice recon.');
            $options['active_open_fetch'] = false;
            $options['skip_completed_recon'] = false;
        }

        try {
            $chunks = $this->dayChunks($from, $to, $byDay);
            $chunkIndex = 0;
            $chunkTotal = count($chunks);
            foreach ($chunks as $dayFrom => $dayTo) {
                $chunkIndex++;
                $label = $dayFrom === $dayTo ? $dayFrom : "{$dayFrom}→{$dayTo}";
                $this->newLine();
                $this->info("[{$chunkIndex}/{$chunkTotal}] Backorders {$label}");

                $boRun = $backorders->run($userId, 'manual', null, $dayFrom, $dayTo, $options);
                $filters = is_array($boRun->filters) ? $boRun->filters : [];
                $this->table(
                    ['Field', 'Value'],
                    [
                        ['sync_log_id', $boRun->id],
                        ['status', $boRun->status],
                        ['mode', $filters['mode'] ?? '—'],
                        ['open_orders', $filters['open_orders_for_active_backorders'] ?? $boRun->record_count],
                        ['lines_upserted', $boRun->success_count],
                        ['failed', $boRun->failed_count],
                        ['error', $boRun->error_message ?: '—'],
                    ],
                );
                if ($boRun->status === 'failed') {
                    $failed = true;
                }
            }
        } catch (Throwable $e) {
            $this->error('Backorder import failed: '.$e->getMessage());
            $failed = true;
        }

        if ($printSummary && ! $failed) {
            $this->newLine();
            $this->printPeriodSummary($from, $to);
        }

        $this->newLine();
        if ($failed) {
            $this->warn('Finished with errors. Check acumatica_sync_logs / dead letters / nohup logs.');

            return self::FAILURE;
        }

        $this->info('Done. Filter the dashboard to this window; Backorder value ≈ Current outstanding (open×price).');

        return self::SUCCESS;
    }

    /**
     * @return array<string, string> map dayFrom => dayTo
     */
    private function dayChunks(string $from, string $to, bool $byDay): array
    {
        if (! $byDay) {
            return [$from => $to];
        }

        $chunks = [];
        $cursor = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();
        while ($cursor->lte($end)) {
            $d = $cursor->toDateString();
            $chunks[$d] = $d;
            $cursor->addDay();
        }

        return $chunks;
    }

    private function resolveFrom(): ?string
    {
        $fromOpt = trim((string) ($this->option('from') ?: ''));
        if ($fromOpt === '' && trim((string) ($this->option('to') ?: '')) === '') {
            return now()->startOfMonth()->toDateString();
        }

        return $this->normalizeDate($fromOpt);
    }

    private function resolveTo(): ?string
    {
        $toOpt = trim((string) ($this->option('to') ?: ''));
        if ($toOpt === '' && trim((string) ($this->option('from') ?: '')) === '') {
            return now()->toDateString();
        }

        return $this->normalizeDate($toOpt);
    }

    private function printPeriodSummary(string $from, string $to): void
    {
        $this->info("Period summary (active_backorder, order_date {$from} → {$to}):");

        $dateExpr = 'COALESCE(DATE(aso.order_date), acumatica_backorder_lines.requested_on, acumatica_backorder_lines.scheduled_shipment_date, DATE(acumatica_backorder_lines.synced_at))';

        $rows = AcumaticaBackorderLine::query()
            ->leftJoin('acumatica_sales_orders as aso', 'acumatica_backorder_lines.order_nbr', '=', 'aso.acumatica_order_nbr')
            ->where(function ($q) {
                $q->where('acumatica_backorder_lines.shortfall_kind', 'active_backorder')
                    ->orWhereNull('acumatica_backorder_lines.shortfall_kind');
            })
            ->whereRaw($dateExpr.' >= ?', [$from])
            ->whereRaw($dateExpr.' <= ?', [$to])
            ->get([
                'acumatica_backorder_lines.order_nbr',
                'acumatica_backorder_lines.inventory_id',
                'acumatica_backorder_lines.order_qty',
                'acumatica_backorder_lines.shipped_qty',
                'acumatica_backorder_lines.qty_on_shipments',
                'acumatica_backorder_lines.cancelled_qty',
                'acumatica_backorder_lines.open_qty',
                'acumatica_backorder_lines.unit_price',
                'acumatica_backorder_lines.revenue_at_risk',
                'aso.status as so_status',
            ]);

        $orderValue = 0.0;
        $invoicedValue = 0.0;
        $backorderValue = 0.0;
        $orders = [];
        $skus = [];
        $statusCounts = [];

        foreach ($rows as $row) {
            $orderQty = (float) ($row->order_qty ?? 0);
            $shippedQty = (float) ($row->shipped_qty ?? 0);
            $qtyOnShipments = (float) ($row->qty_on_shipments ?? 0);
            $cancelledQty = (float) ($row->cancelled_qty ?? 0);
            $storedOpen = (float) ($row->open_qty ?? 0);
            $unitPrice = max(0.0, (float) ($row->unit_price ?? 0));
            $net = max(0.0, $orderQty - max(0.0, $cancelledQty));
            $delivered = SalesOrderLineFulfillmentDeriver::deliveredQty($shippedQty, $qtyOnShipments);
            $openQty = SalesOrderLineFulfillmentDeriver::residualOpenQty(
                $orderQty,
                $shippedQty,
                $qtyOnShipments,
                $cancelledQty,
                $storedOpen > 0 ? $storedOpen : null,
            );

            $orderValue += $orderQty * $unitPrice;
            $invoicedValue += min($delivered, $net) * $unitPrice;
            $backorderValue += SalesOrderLineFulfillmentDeriver::openLineValue($openQty, $unitPrice);
            $orders[$row->order_nbr] = true;
            $skus[$row->inventory_id] = true;
            $st = $row->so_status ?: '?';
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
        }

        $this->table(
            ['Metric', 'Value'],
            [
                ['open_lines', $rows->count()],
                ['open_orders', count($orders)],
                ['skus', count($skus)],
                ['order_value', number_format($orderValue, 2, '.', ',')],
                ['invoiced_value', number_format($invoicedValue, 2, '.', ',')],
                ['backorder_value (open×price)', number_format($backorderValue, 2, '.', ',')],
                ['sum revenue_at_risk column', number_format((float) $rows->sum('revenue_at_risk'), 2, '.', ',')],
                ['so_status_line_counts', json_encode($statusCounts)],
            ],
        );

        $headerBo = AcumaticaSalesOrder::query()
            ->whereDate('order_date', '>=', $from)
            ->whereDate('order_date', '<=', $to)
            ->whereRaw('LOWER(TRIM(status)) = ?', ['back order'])
            ->count();
        $this->line("Local SO table — Status=Back Order headers in window: {$headerBo}");
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }

        return date('Y-m-d', $ts);
    }
}
