<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_effective_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('customer_acumatica_id', 50);
            $table->string('assignment_type', 20)->default('servicing');
            $table->foreignId('resolved_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('winning_source', 30)->nullable();
            $table->foreignId('assignment_rule_id')->nullable()->constrained('customer_assignment_rules')->nullOnDelete();
            $table->string('sales_channel_code', 30)->nullable();
            $table->string('resolution_status', 20)->default('resolved');
            $table->string('source_hash', 64);
            $table->timestamp('resolved_at');
            $table->timestamps();
            $table->unique(['customer_acumatica_id', 'assignment_type'], 'cea_customer_type_unique');
            $table->index(['resolved_user_id', 'sales_channel_code'], 'cea_user_channel_idx');
        });

        Schema::create('sales_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('period_date');
            $table->string('scope_type', 20);
            $table->string('scope_key', 100);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sales_channel_code', 30)->nullable();
            $table->unsignedInteger('customer_count')->default(0);
            $table->unsignedInteger('so_count')->default(0);
            $table->unsignedInteger('credit_note_count')->default(0);
            $table->decimal('gross_revenue', 18, 2)->default(0);
            $table->decimal('credit_revenue', 18, 2)->default(0);
            $table->decimal('net_revenue', 18, 2)->default(0);
            $table->decimal('ordered_volume', 18, 4)->default(0);
            $table->decimal('credited_volume', 18, 4)->default(0);
            $table->decimal('net_volume', 18, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamp('calculated_at');
            $table->timestamps();
            $table->unique(['period_date', 'scope_type', 'scope_key'], 'sales_snapshot_scope_unique');
        });

        Schema::create('kp_crm_access_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('access_basis', 30);
            $table->boolean('is_active')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('change_reason')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'access_basis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kp_crm_access_assignments');
        Schema::dropIfExists('sales_performance_snapshots');
        Schema::dropIfExists('customer_effective_assignments');
    }
};
