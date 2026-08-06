<?php

namespace App\Jobs;

use App\Models\ProductImportLog;
use App\Services\Imports\ProductCsvImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;
use Illuminate\Support\Facades\Storage;
use App\Services\Production\ProductionSummaryService;

class ImportProductsCsvJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1200;
    public int $tries = 1;

    public function __construct(public readonly int $logId) {}

    public function handle(ProductCsvImportService $service): void
    {
        $log = ProductImportLog::query()->find($this->logId);
        if (! $log || $log->status === 'completed') return;
        try {
            $service->import(Storage::disk('local')->path($log->file_path), $log, $log->triggered_by_user_id);
            app(ProductionSummaryService::class)->bumpVersion(ProductionSummaryService::VERSION_REFERENCE);
        } catch (Throwable $e) {
            $log->update(['status' => 'failed', 'error_message' => $e->getMessage(), 'finished_at' => now()]);
            throw $e;
        }
    }
}
