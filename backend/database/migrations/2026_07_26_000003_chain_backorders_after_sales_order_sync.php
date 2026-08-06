<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cron_jobs')) return;

        DB::table('cron_jobs')
            ->where('job_key', 'backorders-daily-4pm')
            ->update([
                'is_enabled' => false,
                'status' => 'paused',
                'notes' => 'Disabled: Backorders now run after each successful scheduled full sales-order sync.',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cron_jobs')) return;

        DB::table('cron_jobs')
            ->where('job_key', 'backorders-daily-4pm')
            ->update([
                'is_enabled' => true,
                'status' => 'active',
                'updated_at' => now(),
            ]);
    }
};
