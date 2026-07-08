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

        $now = now();

        // 1. Inventory: every 5h → twice daily 8AM + 12PM
        DB::table('cron_jobs')->where('job_key', 'inventory-sync-5h')->update([
            'cron_expression' => '0 8,12 * * *',
            'frequency_label' => 'Twice Daily (8AM, 12PM)',
            'updated_at' => $now,
        ]);

        // 2. Email sync: every 3h → every 2h at odd hours (alternates with order sync)
        DB::table('cron_jobs')->where('job_key', 'email-sync-3h')->update([
            'cron_expression' => '0 1,3,5,7,9,11,13,15,17,19,21,23 * * *',
            'frequency_label' => 'Every 2 Hours (offset)',
            'updated_at' => $now,
        ]);

        // 3. Order sync: every 3h → every 2h at even hours
        DB::table('cron_jobs')->where('job_key', 'sales-order-sync-3h')->update([
            'cron_expression' => '0 */2 * * *',
            'frequency_label' => 'Every 2 Hours',
            'updated_at' => $now,
        ]);

        // 4. Backorder: daily 4PM → daily 00:30
        DB::table('cron_jobs')->where('job_key', 'backorders-daily-4pm')->update([
            'cron_expression' => '30 0 * * *',
            'frequency_label' => 'Daily at 00:30',
            'updated_at' => $now,
        ]);

        // 5. Fill rate nightly: 2:20AM → 00:01
        DB::table('cron_jobs')->where('job_key', 'fill-rate-nightly')->update([
            'cron_expression' => '1 0 * * *',
            'frequency_label' => 'Twice Daily (00:01, 12:30PM)',
            'updated_at' => $now,
        ]);

        // 6. New: fill-rate noon run at 12:30PM
        DB::table('cron_jobs')->updateOrInsert(
            ['job_key' => 'fill-rate-noon'],
            [
                'name' => 'Fill Rate Sync (Noon)',
                'description' => 'Midday fill-rate computation from Acumatica order line fulfillment.',
                'is_enabled' => true,
                'cron_expression' => '30 12 * * *',
                'frequency_label' => 'Daily at 12:30PM',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:fill-rate-sync --variant=noon',
                'status' => 'active',
                'next_run_at' => $now->copy()->addDay()->startOfDay()->addHours(12)->addMinutes(30),
                'settings' => json_encode(['fill_rate_lookback_days' => 1]),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        // 7. New: daily system health report at 6AM
        DB::table('cron_jobs')->updateOrInsert(
            ['job_key' => 'system-health-daily'],
            [
                'name' => 'System Health Check',
                'description' => 'Daily system health report emailed to the tech lead.',
                'is_enabled' => true,
                'cron_expression' => '0 6 * * *',
                'frequency_label' => 'Daily at 6AM',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:system-health',
                'status' => 'active',
                'next_run_at' => $now->copy()->addDay()->startOfDay()->addHours(6),
                'settings' => json_encode(['recipient' => 'commercialtechlead@kimfay.com']),
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('cron_jobs')) {
            return;
        }

        $now = now();

        DB::table('cron_jobs')->where('job_key', 'inventory-sync-5h')->update([
            'cron_expression' => '10 */5 * * *',
            'frequency_label' => 'Every 5 Hours',
            'updated_at' => $now,
        ]);

        DB::table('cron_jobs')->where('job_key', 'email-sync-3h')->update([
            'cron_expression' => '5 */3 * * *',
            'frequency_label' => 'Every 3 Hours',
            'updated_at' => $now,
        ]);

        DB::table('cron_jobs')->where('job_key', 'sales-order-sync-3h')->update([
            'cron_expression' => '45 */3 * * *',
            'frequency_label' => 'Every 3 Hours',
            'updated_at' => $now,
        ]);

        DB::table('cron_jobs')->where('job_key', 'backorders-daily-4pm')->update([
            'cron_expression' => '0 16 * * *',
            'frequency_label' => 'Daily',
            'updated_at' => $now,
        ]);

        DB::table('cron_jobs')->where('job_key', 'fill-rate-nightly')->update([
            'cron_expression' => '20 2 * * *',
            'frequency_label' => 'Nightly',
            'updated_at' => $now,
        ]);

        DB::table('cron_jobs')->whereIn('job_key', ['fill-rate-noon', 'system-health-daily'])->delete();
    }
};
