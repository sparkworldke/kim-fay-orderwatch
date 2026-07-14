<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_change_requests', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 40)->unique();
            $table->string('customer_acumatica_id', 50)->index();
            $table->string('customer_name')->nullable();
            $table->string('customer_price_class', 100)->nullable();
            $table->string('customer_payment_terms', 100)->nullable();
            $table->string('inventory_id', 100)->index();
            $table->string('product_description')->nullable();
            $table->decimal('current_selling_price', 15, 4)->nullable();
            $table->decimal('proposed_selling_price', 15, 4);
            $table->decimal('base_price_snapshot', 15, 4)->nullable();
            $table->decimal('margin_pct_snapshot', 8, 4)->nullable();
            $table->decimal('margin_kes_snapshot', 15, 4)->nullable();
            $table->string('currency_id', 10)->default('KES');
            $table->text('justification');
            $table->date('effective_date_requested')->nullable();
            $table->string('status', 40)->default('submitted')->index();
            $table->string('current_stage_key', 80)->nullable()->index();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('duplicate_ack_required')->default(false)->index();
            $table->foreignId('duplicate_acked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('duplicate_acked_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('acumatica_apply_notified_at')->nullable();
            $table->timestamp('acumatica_applied_at')->nullable();
            $table->foreignId('acumatica_applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_acumatica_id', 'inventory_id', 'created_at'], 'pcr_customer_sku_created_idx');
        });

        Schema::create('price_change_approval_stages', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(1)->index();
            $table->string('assignee_mode', 40)->default('role');
            $table->json('role_names')->nullable();
            $table->json('user_ids')->nullable();
            $table->boolean('require_comment_on_reject')->default(true);
            $table->unsignedInteger('sla_hours')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('price_change_approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_change_request_id')->constrained('price_change_requests')->cascadeOnDelete();
            $table->string('stage_key', 80)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision', 40);
            $table->text('comment')->nullable();
            $table->decimal('margin_seen_pct', 8, 4)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('price_change_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_change_request_id')->constrained('price_change_requests')->cascadeOnDelete();
            $table->string('event_type', 80)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('comment')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });

        Schema::create('price_change_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value_json')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_change_settings');
        Schema::dropIfExists('price_change_events');
        Schema::dropIfExists('price_change_approval_actions');
        Schema::dropIfExists('price_change_approval_stages');
        Schema::dropIfExists('price_change_requests');
    }
};
