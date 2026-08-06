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

        $row = DB::table('cron_jobs')->where('job_key', 'sales-order-sync-3h')->first();
        if (! $row) {
            return;
        }

        $settings = [];
        if (! empty($row->settings)) {
            $decoded = is_string($row->settings)
                ? json_decode($row->settings, true)
                : $row->settings;
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        $settings['lookback_days'] = 7;
        // Drop legacy dual-mode keys; command now always uses lookback_days.
        unset($settings['lookback_hours'], $settings['deep_scan_hour'], $settings['deep_scan_lookback_days']);

        DB::table('cron_jobs')
            ->where('job_key', 'sales-order-sync-3h')
            ->update([
                'description' => 'Acumatica sales order sync. Every run imports the last 7 days (SOs, line qty/stock, statuses).',
                'settings' => json_encode($settings),
                'updated_at' => now(),
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
                'description' => 'Acumatica sales order sync. Rolling 2-hour window each run; deep 3-day scan at 4PM.',
                'settings' => json_encode([
                    'lookback_hours' => 2,
                    'deep_scan_hour' => 16,
                    'deep_scan_lookback_days' => 3,
                ]),
                'updated_at' => now(),
            ]);
    }
};
