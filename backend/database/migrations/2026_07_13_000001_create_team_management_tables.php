<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 100);
            $table->boolean('is_customer_facing')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('department_brands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('brand', 100);
            $table->timestamps();
            $table->unique(['department_id', 'brand']);
        });

        Schema::create('customer_department_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('customer_acumatica_id', 50)->unique();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('user_acumatica_rep_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('acumatica_consultant_id', 50)->nullable();
            $table->string('acumatica_rep_code', 50)->nullable();
            $table->boolean('is_primary')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'is_primary']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('role')->constrained('departments')->nullOnDelete();
            $table->string('department_role', 20)->default('member')->after('department_id');
            $table->boolean('is_consultant')->default(false)->after('department_role');
        });

        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->foreignId('consultant_user_id')->nullable()->after('sales_consultant_name')->constrained('users')->nullOnDelete();
            $table->index('consultant_user_id');
        });

        Schema::create('consultant_assignment_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('acumatica_sales_orders')->cascadeOnDelete();
            $table->foreignId('consultant_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('consultant_role', 50);
            $table->boolean('is_non_traditional_role')->default(false);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 30)->default('manual');
            $table->timestamps();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('login_at');
            $table->timestamp('logout_at')->nullable();
            $table->string('logout_reason', 30)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('login_mode', 20)->default('otp');
            $table->timestamps();
            $table->index(['user_id', 'login_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('consultant_assignment_audits');

        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consultant_user_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['department_role', 'is_consultant']);
        });

        Schema::dropIfExists('user_acumatica_rep_mappings');
        Schema::dropIfExists('customer_department_overrides');
        Schema::dropIfExists('department_brands');
        Schema::dropIfExists('departments');
    }
};