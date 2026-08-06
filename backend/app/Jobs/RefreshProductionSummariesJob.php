<?php

namespace App\Jobs;

use App\Services\Production\ProductionSummaryService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshProductionSummariesJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;
    public int $tries = 2;

    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
        public bool $stock = true,
    ) {}

    public function handle(ProductionSummaryService $service): void
    {
        $from = Carbon::parse($this->from ?? now()->subDays(7))->startOfDay();
        $to = Carbon::parse($this->to ?? now())->endOfDay();
        $service->refreshMonthly($from, $to);
        if ($this->stock) {
            $service->refreshDailyStock();
        }
    }
}
