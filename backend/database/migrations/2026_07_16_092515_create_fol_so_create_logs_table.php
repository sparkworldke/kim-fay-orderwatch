<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fol_so_create_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fol_request_id')->constrained('fol_requests')->cascadeOnDelete();
            $table->string('public_ref', 40)->nullable()->index();
            $table->string('customer_acumatica_id', 50)->nullable()->index();
            /** cco_approve | cron_retry | manual */
            $table->string('attempt_source', 40)->default('cco_approve')->index();
            /** success | failed | skipped | already_linked */
            $table->string('status', 30)->index();
            $table->string('acumatica_order_nbr', 50)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->json('payload_json')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cron_run_log_id')->nullable()->index();
            $table->timestamps();

            $table->index(['fol_request_id', 'status']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fol_so_create_logs');
    }
};
