<?php

namespace App\Jobs;

use App\Models\ExportDownload;
use App\Services\Exports\ExportDownloadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessExportDownloadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Large Excel builds can take several minutes. */
    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public readonly int $exportDownloadId) {}

    public function handle(ExportDownloadService $exports): void
    {
        $download = ExportDownload::query()->find($this->exportDownloadId);
        if (! $download) {
            return;
        }

        if (in_array($download->status, [ExportDownload::STATUS_READY, ExportDownload::STATUS_EXPIRED], true)) {
            return;
        }

        $exports->process($download);
    }
}
