<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pause all automated notification emails except:
 * - OrderWatch System Health [CRITICAL] (system-health-daily cron)
 * - Daily Notification / management report (daily-report-fixed-scheduler)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_rules')) {
            DB::table('notification_rules')->update(['is_enabled' => false]);
            // Keep pure in-app rule usable for UI toggles later; email volume is still off.
            DB::table('notification_rules')
                ->where('rule_key', 'R4')
                ->update(['is_enabled' => true]);
        }

        if (Schema::hasTable('cron_jobs')) {
            DB::table('cron_jobs')
                ->whereIn('job_key', [
                    'sync-monitor-alerts',
                    'order-match-notification-evaluation',
                ])
                ->update([
                    'is_enabled' => false,
                    'status' => 'paused',
                    'updated_at' => now(),
                ]);

            // Ensure the two allowed email jobs stay enabled.
            DB::table('cron_jobs')
                ->whereIn('job_key', [
                    'system-health-daily',
                    'daily-report-fixed-scheduler',
                ])
                ->update([
                    'is_enabled' => true,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('notification_rules')) {
            DB::table('notification_rules')->update(['is_enabled' => true]);
        }

        if (Schema::hasTable('cron_jobs')) {
            DB::table('cron_jobs')
                ->whereIn('job_key', [
                    'sync-monitor-alerts',
                    'order-match-notification-evaluation',
                ])
                ->update([
                    'is_enabled' => true,
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
        }
    }
};
