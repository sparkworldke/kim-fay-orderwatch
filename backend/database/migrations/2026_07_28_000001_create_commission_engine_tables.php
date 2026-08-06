<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable()->index();
            $table->json('eligible_user_ids')->nullable();
            $table->json('eligible_team_ids')->nullable();
            $table->json('eligible_inventory_ids')->nullable();
            $table->json('eligible_brands')->nullable();
            $table->json('eligible_customer_classes')->nullable();
            $table->json('excluded_order_types')->nullable();
            $table->string('sales_value_basis', 30)->default('order_total');
            $table->decimal('cap_amount', 15, 2)->nullable();
            $table->decimal('fixed_bonus', 15, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_rule_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->decimal('attainment_from_pct', 8, 2);
            $table->decimal('attainment_to_pct', 8, 2)->nullable();
            $table->decimal('commission_rate_pct', 8, 4);
            $table->timestamps();
        });

        Schema::create('commission_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('period_month');
            $table->decimal('target_amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'period_month']);
        });

        Schema::create('commission_periods', function (Blueprint $table) {
            $table->id();
            $table->date('period_month')->index();
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 30)->default('draft')->index();
            $table->boolean('shadow_mode')->default(true);
            $table->timestamp('calculated_at')->nullable();
            $table->foreignId('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reverses_period_id')->nullable()->constrained('commission_periods')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['period_month', 'version']);
        });

        Schema::create('commission_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('target_amount', 15, 2)->default(0);
            $table->decimal('eligible_sales', 15, 2)->default(0);
            $table->decimal('attainment_pct', 8, 2)->default(0);
            $table->decimal('gross_commission', 15, 2)->default(0);
            $table->decimal('adjustment_amount', 15, 2)->default(0);
            $table->decimal('net_commission', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['commission_period_id', 'user_id']);
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_statement_id')->constrained('commission_statements')->cascadeOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained('acumatica_sales_orders')->nullOnDelete();
            $table->foreignId('commission_rule_id')->nullable()->constrained('commission_rules')->nullOnDelete();
            $table->string('order_nbr', 100);
            $table->date('order_date');
            $table->decimal('sales_value', 15, 2);
            $table->decimal('rate_pct', 8, 4);
            $table->decimal('commission_amount', 15, 2);
            $table->boolean('is_excluded')->default(false);
            $table->text('exclusion_reason')->nullable();
            $table->json('order_snapshot');
            $table->timestamps();
            $table->unique(['commission_statement_id', 'order_nbr']);
        });

        Schema::create('commission_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_statement_id')->constrained('commission_statements')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->string('kind', 30)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('commission_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('commission_period_id')->constrained('commission_periods')->cascadeOnDelete();
            $table->string('event_type', 60)->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_events');
        Schema::dropIfExists('commission_adjustments');
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_statements');
        Schema::dropIfExists('commission_periods');
        Schema::dropIfExists('commission_targets');
        Schema::dropIfExists('commission_rule_tiers');
        Schema::dropIfExists('commission_rules');
    }
};
