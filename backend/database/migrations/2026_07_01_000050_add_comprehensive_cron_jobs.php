<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('cron_jobs')->where('job_key', 'email-sales-order-auto-match')->update([
            'is_enabled' => false,
            'status' => 'paused',
            'updated_at' => $now,
        ]);

        $jobs = [
            [
                'job_key' => 'email-sync-3h',
                'name' => 'Email Synchronization',
                'description' => 'Outlook mailbox sync into the dashboard database.',
                'is_enabled' => true,
                'cron_expression' => '5 */3 * * *',
                'frequency_label' => 'Every 3 Hours',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:email-sync',
                'status' => 'active',
                'next_run_at' => $now->copy()->addHours(3),
                'settings' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'job_key' => 'order-matching-3h',
                'name' => 'Order Matching',
                'description' => 'PO extraction and deterministic email-to-order matching.',
                'is_enabled' => true,
                'cron_expression' => '25 */3 * * *',
                'frequency_label' => 'Every 3 Hours',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:order-matching',
                'status' => 'active',
                'next_run_at' => $now->copy()->addHours(3),
                'settings' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'job_key' => 'sales-order-sync-3h',
                'name' => 'Sales Order Synchronization',
                'description' => 'Acumatica sales order sync (full order details) into the dashboard database.',
                'is_enabled' => true,
                'cron_expression' => '45 */3 * * *',
                'frequency_label' => 'Every 3 Hours',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:sales-orders-sync',
                'status' => 'active',
                'next_run_at' => $now->copy()->addHours(3),
                'settings' => json_encode(['sales_order_lookback_days' => 7]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'job_key' => 'sales-order-status-sync',
                'name' => 'Sales Order Status Updates',
                'description' => 'Lightweight status-only sync for SO workflow progression.',
                'is_enabled' => true,
                'cron_expression' => '12,42 * * * *',
                'frequency_label' => 'Every 30 Minutes',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:sales-order-status-sync',
                'status' => 'active',
                'next_run_at' => $now->copy()->addMinutes(30),
                'settings' => json_encode(['status_sync_lookback_days' => 14, 'status_sync_max_orders' => 1500]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'job_key' => 'inventory-sync-5h',
                'name' => 'Inventory Synchronization',
                'description' => 'Acumatica inventory stock sync to keep on-hand levels current.',
                'is_enabled' => true,
                'cron_expression' => '10 */5 * * *',
                'frequency_label' => 'Every 5 Hours',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:inventory-sync',
                'status' => 'active',
                'next_run_at' => $now->copy()->addHours(5),
                'settings' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'job_key' => 'backorders-daily-4pm',
                'name' => 'Backorder Processing',
                'description' => 'Daily backorder validation and processing.',
                'is_enabled' => true,
                'cron_expression' => '0 16 * * *',
                'frequency_label' => 'Daily',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:backorders-process',
                'status' => 'active',
                'next_run_at' => $now->copy()->addDay(),
                'settings' => json_encode([]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'job_key' => 'fill-rate-nightly',
                'name' => 'Fill Rate Synchronization',
                'description' => 'Nightly fill-rate computation from Acumatica order line fulfillment.',
                'is_enabled' => true,
                'cron_expression' => '20 2 * * *',
                'frequency_label' => 'Nightly',
                'trigger_type' => 'scheduler',
                'command' => 'php artisan orderwatch:fill-rate-sync',
                'status' => 'active',
                'next_run_at' => $now->copy()->addDay(),
                'settings' => json_encode(['fill_rate_lookback_days' => 2]),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        foreach ($jobs as $job) {
            DB::table('cron_jobs')->updateOrInsert(['job_key' => $job['job_key']], $job);
        }
    }

    public function down(): void
    {
        DB::table('cron_jobs')->whereIn('job_key', [
            'email-sync-3h',
            'order-matching-3h',
            'sales-order-sync-3h',
            'sales-order-status-sync',
            'inventory-sync-5h',
            'backorders-daily-4pm',
            'fill-rate-nightly',
        ])->delete();
    }
};

