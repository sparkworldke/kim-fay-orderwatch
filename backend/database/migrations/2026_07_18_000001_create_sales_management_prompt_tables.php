<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_management_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('prompt_type', 60);
            $table->string('status', 40)->default('open')->index();
            $table->string('severity', 30)->default('info')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->string('period_key', 40)->nullable()->index();
            $table->string('customer_acumatica_id', 50)->index();
            $table->string('customer_name')->nullable();
            $table->foreignId('consultant_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('consultant_rep_code', 50)->nullable()->index();
            $table->string('consultant_name')->nullable();
            $table->date('source_from')->nullable();
            $table->date('source_to')->nullable();
            $table->date('last_order_date')->nullable();
            $table->unsignedInteger('expected_cycle_days')->nullable();
            $table->unsignedInteger('days_since_last_order')->nullable();
            $table->date('due_date')->nullable()->index();
            $table->timestamp('snoozed_until')->nullable();
            $table->decimal('value_snapshot', 15, 2)->default(0);
            $table->unsignedInteger('order_count_snapshot')->default(0);
            $table->text('reason');
            $table->json('payload_json')->nullable();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->foreignId('dismissed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('dismiss_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['prompt_type', 'status', 'due_date'], 'sm_prompts_type_status_due_idx');
            $table->index(['consultant_user_id', 'status', 'due_date'], 'sm_prompts_consultant_status_due_idx');
            $table->index(['customer_acumatica_id', 'prompt_type', 'status'], 'sm_prompts_customer_type_status_idx');
        });

        Schema::create('sales_management_prompt_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_management_prompt_id');
            $table->foreign('sales_management_prompt_id', 'sm_prompt_events_prompt_fk')
                ->references('id')
                ->on('sales_management_prompts')
                ->cascadeOnDelete();
            $table->string('event_type', 80)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('sales_management_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_management_settings');
        Schema::dropIfExists('sales_management_prompt_events');
        Schema::dropIfExists('sales_management_prompts');
    }
};
