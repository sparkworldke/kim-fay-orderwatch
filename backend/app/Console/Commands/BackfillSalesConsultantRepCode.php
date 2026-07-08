<?php

namespace App\Console\Commands;

use App\Models\AcumaticaSalesOrder;
use App\Services\Admin\AcumaticaSalesOrderSyncService;
use Illuminate\Console\Command;

class BackfillSalesConsultantRepCode extends Command
{
    protected $signature = 'acumatica:backfill-sales-consultant';

    protected $description = 'Populate sales_consultant_rep_code on already-synced orders from their stored raw_payload (SalespersonID lives on line items, and was not extracted until this fix)';

    public function handle(AcumaticaSalesOrderSyncService $syncService): int
    {
        $updated = 0;
        $scanned = 0;

        AcumaticaSalesOrder::query()
            ->whereNull('sales_consultant_rep_code')
            ->whereNotNull('raw_payload')
            ->chunkById(500, function ($orders) use ($syncService, &$updated, &$scanned) {
                foreach ($orders as $order) {
                    $scanned++;
                    $repCode = $syncService->salespersonRepCode($order->raw_payload ?? []);

                    if ($repCode !== null) {
                        $order->update(['sales_consultant_rep_code' => $repCode]);
                        $updated++;
                    }
                }
            });

        $this->info("Scanned {$scanned} order(s), backfilled {$updated} with a rep code.");

        return Command::SUCCESS;
    }
}
