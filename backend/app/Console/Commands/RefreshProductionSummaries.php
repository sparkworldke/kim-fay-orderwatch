<?php

namespace App\Console\Commands;

use App\Services\Production\ProductionSummaryService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RefreshProductionSummaries extends Command
{
    protected $signature = 'production:summaries-refresh
        {--from= : First order date to aggregate}
        {--to= : Last order date to aggregate}
        {--recent : Refresh the rolling seven days and current stock}
        {--stock-only : Refresh only the current stock snapshot}';

    protected $description = 'Build production stock and missed-opportunity summary tables.';

    public function handle(ProductionSummaryService $service): int
    {
        if ($this->option('stock-only')) {
            $count = $service->refreshDailyStock();
            $this->info("Refreshed {$count} stock summary rows.");
            return self::SUCCESS;
        }

        $from = $this->option('from')
            ? Carbon::parse((string) $this->option('from'))
            : ($this->option('recent') ? now()->subDays(7) : now()->startOfYear());
        $to = $this->option('to') ? Carbon::parse((string) $this->option('to')) : now();
        $total = 0;

        for ($month = $from->copy()->startOfMonth(); $month->lte($to); $month->addMonth()) {
            $rangeFrom = $month->copy()->max($from);
            $rangeTo = $month->copy()->endOfMonth()->min($to);
            $count = $service->refreshMonthly($rangeFrom, $rangeTo);
            $total += $count;
            $this->line("{$month->format('Y-m')}: {$count} aggregate rows");
        }

        $stock = $service->refreshDailyStock();
        $this->info("Completed: {$total} monthly rows and {$stock} current stock rows.");
        return self::SUCCESS;
    }
}
