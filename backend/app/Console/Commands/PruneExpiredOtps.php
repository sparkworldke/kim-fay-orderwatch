<?php

namespace App\Console\Commands;

use App\Models\Otp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneExpiredOtps extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    // --source is accepted for scheduler/cron_jobs compatibility (console.php appends it).
    protected $signature = 'otp:prune {--source= : Run source label (scheduler|manual|api)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete all expired OTP records from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deleted = Otp::where('expires_at', '<', now())->delete();

        Log::info('otp:prune', [
            'deleted' => $deleted,
            'source' => $this->option('source') ?: 'manual',
            'at' => now()->toIso8601String(),
        ]);

        $this->info("Pruned {$deleted} expired OTP record(s).");

        return Command::SUCCESS;
    }
}
