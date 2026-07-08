<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        DB::table('cron_jobs')
            ->where('job_key', 'sales-order-sync-3h')
            ->update([
                'description' => 'Acumatica sales order sync. Rolling 2-hour window each run; deep 3-day scan at 4PM.',
                'settings'    => json_encode([
                    'lookback_hours'          => 2,
                    'deep_scan_hour'          => 16,
                    'deep_scan_lookback_days' => 3,
                ]),
                'updated_at'  => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        DB::table('cron_jobs')
            ->where('job_key', 'sales-order-sync-3h')
            ->update([
                'description' => 'Acumatica sales order sync (full order details) into the dashboard database.',
                'settings'    => json_encode(['sales_order_lookback_days' => 7]),
                'updated_at'  => now(),
            ]);
    }
};
