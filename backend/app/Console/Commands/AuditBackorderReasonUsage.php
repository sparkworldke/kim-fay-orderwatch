<?php

namespace App\Console\Commands;

use App\Models\AcumaticaBackorderLine;
use Illuminate\Console\Command;

class AuditBackorderReasonUsage extends Command
{
    protected $signature = 'orderwatch:audit-backorder-reasons {--days=90 : Recent usage window} {--json : Emit JSON}';

    protected $description = 'Audit usage of the controlled backorder reason vocabulary without changing it';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $rows = collect(AcumaticaBackorderLine::REASON_CODES)->map(function (string $code) use ($cutoff) {
            $query = AcumaticaBackorderLine::query()->where('reason_code', $code);
            $stored = (clone $query)->count();
            $recent = (clone $query)->where('synced_at', '>=', $cutoff)->count();

            return [
                'reason_code' => $code,
                'recent_usage' => $recent,
                'stored_references' => $stored,
                'recommendation' => $recent === 0 && $stored === 0 ? 'retire' : 'keep',
            ];
        })->values();

        if ($this->option('json')) {
            $this->line($rows->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Reason code', "Last {$days} days", 'Stored references', 'Recommendation'], $rows->all());
            $this->warn('Stored references reflect rows currently retained; this table is not an immutable reason-history ledger.');
        }

        return self::SUCCESS;
    }
}
