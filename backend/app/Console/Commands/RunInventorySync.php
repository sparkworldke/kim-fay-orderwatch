<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\CronRunLog;
use App\Services\Admin\AcumaticaInventorySyncService;
use App\Services\Cron\CronExecutionService;
use Illuminate\Console\Command;

class RunInventorySync extends Command
{
    protected $signature = 'orderwatch:inventory-sync
                            {--source=scheduler}
                            {--user-id=}
                            {--full : Run a full item sync (creates new items) instead of stocks-only update}
                            {--warehouse= : Filter by Acumatica DefaultWarehouseID (e.g. MAIN, RETAIL)}
                            {--category= : Filter by Acumatica ItemClass / product category}
                            {--min-qty= : Only sync items with QtyOnHand >= this value}';

    protected $description = 'Synchronize Acumatica inventory into the local database. Use --full for initial population.';

    public function handle(CronExecutionService $cron, AcumaticaInventorySyncService $inventory): int
    {
        $job    = CronJob::inventorySync();
        $userId = $this->option('user-id') !== null ? (int) $this->option('user-id') : null;
        $full   = (bool) $this->option('full');

        // CLI options take precedence over cron_jobs.settings defaults
        $settings = $job->settings ?? [];
        $filters  = array_filter([
            'warehouse_id' => $this->option('warehouse') ?? $settings['warehouse_id'] ?? null,
            'item_class'   => $this->option('category')  ?? $settings['item_class']   ?? null,
            'min_qty'      => $this->option('min-qty') !== null
                ? (float) $this->option('min-qty')
                : (isset($settings['min_qty']) ? (float) $settings['min_qty'] : null),
        ], fn ($v) => $v !== null);

        if ($full) {
            $this->info('Running full inventory sync (creates + updates all items)…');
        }

        $run = $cron->run(
            $job,
            fn (CronRunLog $run) => $this->perform($run, $inventory, (string) $this->option('source'), $userId, $filters, $full),
            (string) $this->option('source'),
            $userId,
            3 * 60 * 60, // 3h — allows the 4-hour gap between 8AM and 12PM runs
        );

        $this->info("Cron run {$run->id}: {$run->status}");
        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function perform(CronRunLog $run, AcumaticaInventorySyncService $inventory, string $source, ?int $userId, array $filters, bool $full): array
    {
        $started = hrtime(true);
        $sync = $full
            ? $inventory->run($userId, $source, $run->id, $filters)
            : $inventory->runStocksOnly($userId, $source, $run->id, $filters);

        $failed  = $sync->status === 'failed';
        $partial = ! $failed && $sync->failed_count > 0;
        $status  = $failed ? 'failed' : ($partial ? 'partial' : 'success');

        $syncFilters    = $sync->filters ?? [];
        $skippedLowQty  = (int) ($syncFilters['skipped_low_qty'] ?? 0);
        $activeFilters  = array_filter([
            'warehouse' => $syncFilters['warehouse_id'] ?? null,
            'category'  => $syncFilters['item_class']   ?? null,
            'min_qty'   => isset($syncFilters['min_qty']) ? (string) $syncFilters['min_qty'] : null,
        ]);

        $filterDesc = $activeFilters
            ? ' [filters: ' . implode(', ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($activeFilters), $activeFilters)) . ']'
            : '';

        $output = match ($status) {
            'success' => "Inventory sync completed{$filterDesc}.",
            'partial' => "Inventory sync completed with partial failures{$filterDesc}.",
            default   => "Inventory sync failed{$filterDesc}.",
        };

        return [
            'status'        => $status,
            'output'        => $output,
            'skipped_count' => $skippedLowQty,
            'error_count'   => (int) $sync->failed_count + ($failed ? 1 : 0),
            'error_summary' => $sync->error_message ? $this->sanitize($sync->error_message) : null,
            'step_status'   => [
                'inventory_sync' => [
                    'status'      => $status,
                    'duration_ms' => $this->milliseconds($started),
                    'metrics'     => [
                        'items_checked'    => (int) $sync->record_count,
                        'items_processed'  => (int) $sync->success_count,
                        'failed_records'   => (int) $sync->failed_count,
                        'skipped_low_qty'  => $skippedLowQty,
                        'filter_warehouse' => $syncFilters['warehouse_id'] ?? null,
                        'filter_category'  => $syncFilters['item_class']   ?? null,
                        'filter_min_qty'   => isset($syncFilters['min_qty']) ? (string) $syncFilters['min_qty'] : null,
                    ],
                    'errors' => $sync->error_message ? [$this->sanitize($sync->error_message)] : [],
                ],
            ],
            'metadata' => [
                'acumatica_sync_log_id' => $sync->id,
                'acumatica_sync_type'   => $sync->sync_type,
            ],
        ];
    }

    private function milliseconds(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000_000));
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/(token|secret|password|credential)([=: ]+)[^\s&]+/i', '$1$2[REDACTED]', $message);
        return mb_substr((string) $message, 0, 500);
    }
}
