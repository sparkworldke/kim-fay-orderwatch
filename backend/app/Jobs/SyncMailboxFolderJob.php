<?php

namespace App\Jobs;

use App\Services\OrderMatch\OrderMatchFolderSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMailboxFolderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public readonly int $runId,
        public readonly string $from,
        public readonly string $to,
    ) {}

    public function handle(OrderMatchFolderSyncService $folderSync): void
    {
        Log::channel('mailbox_sync')->info('folder_date_range_sync_job_started', [
            'sync_run_id' => $this->runId,
            'from' => $this->from,
            'to' => $this->to,
        ]);

        $folderSync->execute($this->runId, $this->from, $this->to);
    }
}