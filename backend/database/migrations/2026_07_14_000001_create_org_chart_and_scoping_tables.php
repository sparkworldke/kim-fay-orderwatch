<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('org_level', 20)->default('sales')->after('department_role');
            $table->foreignId('reports_to_user_id')->nullable()->after('org_level')->constrained('users')->nullOnDelete();
            $table->string('product_type_scope', 20)->default('both')->after('reports_to_user_id');
            $table->string('data_scope_mode', 20)->default('scoped')->after('product_type_scope');
            $table->boolean('is_shared_mailbox')->default(false)->after('data_scope_mode');
            $table->index('reports_to_user_id');
            $table->index('org_level');
        });

        Schema::create('department_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('membership_role', 20)->default('member');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['department_id', 'user_id']);
            $table->index(['user_id', 'is_primary']);
        });

        Schema::create('user_sector_scopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sector', 5);
            $table->timestamps();
            $table->unique(['user_id', 'sector']);
        });

        Schema::create('user_customer_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('customer_acumatica_id', 50);
            $table->string('assignment_type', 20)->default('primary');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'customer_acumatica_id']);
            $table->index('customer_acumatica_id');
        });

        Schema::create('user_brand_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('brand', 100);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'brand']);
        });

        Schema::create('org_chart_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('change_type', 30);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('org_chart_audits');
        Schema::dropIfExists('user_brand_assignments');
        Schema::dropIfExists('user_customer_assignments');
        Schema::dropIfExists('user_sector_scopes');
        Schema::dropIfExists('department_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reports_to_user_id');
            $table->dropColumn([
                'org_level',
                'product_type_scope',
                'data_scope_mode',
                'is_shared_mailbox',
            ]);
        });
    }
};