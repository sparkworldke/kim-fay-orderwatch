<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_report_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->default('Daily Management Report');
            $table->boolean('is_enabled')->default(true);
            $table->string('send_time', 5)->default('08:00');
            $table->string('timezone', 64)->default('Africa/Nairobi');
            $table->json('recipients_json')->nullable();
            $table->string('subject_template', 255)->default('OrderWatch Daily Brief – {report_date}');
            $table->boolean('include_ai_insights')->default(true);
            $table->boolean('include_comparison')->default(true);
            $table->boolean('include_mtd')->default(true);
            $table->boolean('include_customer_highlights')->default(true);
            $table->timestamps();
        });

        Schema::create('daily_report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_config_id')->constrained('daily_report_configs')->cascadeOnDelete();
            $table->date('report_date');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('ai_status', 30)->nullable();
            $table->string('delivery_status', 30)->nullable();
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error_summary')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();

            $table->index(['report_date', 'status']);
        });

        Schema::create('daily_report_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_report_run_id')->constrained('daily_report_runs')->cascadeOnDelete();
            $table->string('recipient_email');
            $table->string('delivery_status', 30)->default('pending');
            $table->string('provider_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });

        DB::table('daily_report_configs')->insert([
            'name' => 'Daily Management Report',
            'is_enabled' => true,
            'send_time' => '08:00',
            'timezone' => 'Africa/Nairobi',
            'recipients_json' => json_encode([]),
            'subject_template' => 'OrderWatch Daily Brief – {report_date}',
            'include_ai_insights' => true,
            'include_comparison' => true,
            'include_mtd' => true,
            'include_customer_highlights' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_delivery_logs');
        Schema::dropIfExists('daily_report_runs');
        Schema::dropIfExists('daily_report_configs');
    }
};