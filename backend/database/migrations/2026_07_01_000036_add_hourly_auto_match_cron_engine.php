<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cron_jobs', function (Blueprint $table) {
            $table->string('job_key', 100)->nullable()->after('id');
            $table->boolean('is_enabled')->default(true)->after('description');
            $table->string('frequency_label', 50)->default('Hourly')->after('cron_expression');
            $table->string('trigger_type', 30)->default('scheduler')->after('frequency_label');
            $table->timestamp('last_success_at')->nullable()->after('last_run_at');
            $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
            $table->unsignedInteger('last_duration_ms')->nullable()->after('last_run_status');
            $table->json('settings')->nullable()->after('next_run_at');
            $table->text('notes')->nullable()->after('settings');
            $table->unique('job_key');
        });

        Schema::table('cron_run_logs', function (Blueprint $table) {
            $table->string('trigger_source', 30)->default('scheduler')->after('status');
            $table->unsignedInteger('duration_ms')->nullable()->after('trigger_source');
            $table->unsignedInteger('emails_checked')->default(0)->after('duration_ms');
            $table->unsignedInteger('emails_processed')->default(0)->after('emails_checked');
            $table->unsignedInteger('sales_orders_checked')->default(0)->after('emails_processed');
            $table->unsignedInteger('sales_orders_processed')->default(0)->after('sales_orders_checked');
            $table->unsignedInteger('matches_created')->default(0)->after('sales_orders_processed');
            $table->unsignedInteger('matched_with_discrepancies_count')->default(0)->after('matches_created');
            $table->unsignedInteger('needs_review_count')->default(0)->after('matched_with_discrepancies_count');
            $table->unsignedInteger('unmatched_count')->default(0)->after('needs_review_count');
            $table->unsignedInteger('skipped_count')->default(0)->after('unmatched_count');
            $table->unsignedInteger('error_count')->default(0)->after('skipped_count');
            $table->json('step_status')->nullable()->after('error_count');
            $table->text('error_summary')->nullable()->after('step_status');
            $table->json('metadata')->nullable()->after('error_summary');
            $table->foreignId('triggered_by_user_id')->nullable()->after('cron_job_id')->constrained('users')->nullOnDelete();
            $table->index(['status', 'started_at']);
        });

        foreach (['mailbox_sync_logs', 'acumatica_sync_logs', 'order_match_runs', 'email_match_attempts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('cron_run_log_id')->nullable()->constrained('cron_run_logs')->nullOnDelete();
                $table->index('cron_run_log_id');
            });
        }

        DB::table('cron_jobs')->updateOrInsert(['name' => 'Email ↔ Sales Order Auto Match'], [
            'job_key' => 'email-sales-order-auto-match',
            'name' => 'Email ↔ Sales Order Auto Match',
            'description' => 'Hourly Outlook folder sync, Acumatica Sales Order sync, and guarded deterministic matching.',
            'is_enabled' => true,
            'cron_expression' => '0 * * * *',
            'frequency_label' => 'Hourly',
            'trigger_type' => 'scheduler',
            'command' => 'php artisan orderwatch:hourly-auto-match',
            'status' => 'active',
            'next_run_at' => now()->addHour()->startOfHour(),
            'settings' => json_encode([
                'email_sync_enabled' => true, 'acumatica_sync_enabled' => true,
                'matching_enabled' => true, 'sales_order_lookback_days' => 7,
                'deterministic_auto_link' => true, 'ai_auto_link' => false,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        foreach (['email_match_attempts', 'order_match_runs', 'acumatica_sync_logs', 'mailbox_sync_logs'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['cron_run_log_id']);
                $table->dropIndex(['cron_run_log_id']);
                $table->dropColumn('cron_run_log_id');
            });
        }
        Schema::table('cron_run_logs', function (Blueprint $table) {
            $table->dropForeign(['triggered_by_user_id']);
            $table->dropIndex(['status', 'started_at']);
            $table->dropColumn([
                'triggered_by_user_id', 'trigger_source', 'duration_ms', 'emails_checked', 'emails_processed',
                'sales_orders_checked', 'sales_orders_processed', 'matches_created',
                'matched_with_discrepancies_count', 'needs_review_count', 'unmatched_count',
                'skipped_count', 'error_count', 'step_status', 'error_summary', 'metadata',
            ]);
        });
        DB::table('cron_jobs')->where('job_key', 'email-sales-order-auto-match')->delete();
        Schema::table('cron_jobs', function (Blueprint $table) {
            $table->dropUnique(['job_key']);
            $table->dropColumn([
                'job_key', 'is_enabled', 'frequency_label', 'trigger_type', 'last_success_at',
                'last_failure_at', 'last_duration_ms', 'settings', 'notes',
            ]);
        });
    }
};
