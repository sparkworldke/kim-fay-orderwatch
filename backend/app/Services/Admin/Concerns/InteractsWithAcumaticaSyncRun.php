<?php

namespace App\Services\Admin\Concerns;

use App\Exceptions\AcumaticaSyncStoppedException;
use App\Models\AcumaticaSyncLog;

trait InteractsWithAcumaticaSyncRun
{
    protected function createSyncRun(array $attributes): AcumaticaSyncLog
    {
        return AcumaticaSyncLog::create($attributes + [
            'heartbeat_at' => now(),
        ]);
    }

    protected function assertNoActiveSync(array $syncTypes, string $message): void
    {
        AcumaticaSyncLog::failStaleRunning($syncTypes);
        AcumaticaSyncLog::failLongRunning($syncTypes);

        $hasActiveRun = AcumaticaSyncLog::query()
            ->whereIn('sync_type', $syncTypes)
            ->activeRunning()
            ->exists();

        if ($hasActiveRun) {
            throw new \RuntimeException($message);
        }
    }

    protected function touchSyncRun(AcumaticaSyncLog $run): void
    {
        $run->refresh();

        if ($run->stop_requested_at !== null) {
            throw new AcumaticaSyncStoppedException();
        }

        $run->markHeartbeat();
    }

    protected function stopSyncRun(AcumaticaSyncLog $run, ?string $message = null): AcumaticaSyncLog
    {
        $run->update([
            'ended_at'      => now(),
            'heartbeat_at'  => now(),
            'status'        => 'stopped',
            'error_message' => $message ?? 'Sync stopped by user.',
        ]);

        return $run;
    }
}
