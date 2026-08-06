<?php

namespace App\Jobs;

use App\Models\DtcSyncLog;
use App\Models\User;
use App\Services\Dtc\DtcPriceExcelImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessDtcPriceExcelImportJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public readonly int $logId)
    {
        // Use the default queue — production cron runs `queue:work` without --queue=imports.
    }

    public function handle(DtcPriceExcelImportService $service): void
    {
        $log = DtcSyncLog::findOrFail($this->logId);

        if (! $log->storage_path || ! Storage::disk('local')->exists($log->storage_path)) {
            $log->update([
                'status' => 'failed',
                'error_message' => 'Uploaded file is missing from storage.',
                'finished_at' => now(),
            ]);

            return;
        }

        $absolute = Storage::disk('local')->path((string) $log->storage_path);
        $file = new UploadedFile($absolute, (string) ($log->original_filename ?: 'import.xlsx'), null, null, true);
        $service->import($file, $log->triggered_by ? User::find($log->triggered_by) : null, $log);
    }

    public function failed(?Throwable $exception): void
    {
        DtcSyncLog::whereKey($this->logId)->update([
            'status' => 'failed',
            'error_message' => $exception?->getMessage() ?? 'Queue job failed.',
            'finished_at' => now(),
        ]);
    }
}
