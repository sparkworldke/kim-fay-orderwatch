<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtc_prices', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_id', 80);
            $table->string('description', 255)->nullable();
            $table->string('price_code', 40)->default('DTCACCOUNT');
            $table->string('uom', 30)->default('PIECE');
            $table->decimal('price', 18, 6);
            $table->string('currency_id', 10)->default('KES');
            $table->decimal('break_qty', 18, 4)->default(0);
            $table->date('effective_date')->nullable();
            $table->date('expiration_date')->nullable();
            $table->string('acumatica_record_id', 100)->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
            $table->unique(['inventory_id', 'uom', 'effective_date', 'break_qty'], 'dtc_price_identity');
            $table->index(['price_code', 'effective_date', 'expiration_date']);
        });

        Schema::create('dtc_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 30)->unique();
            $table->string('acumatica_quote_nbr', 50)->nullable()->unique();
            $table->string('acumatica_quote_id', 100)->nullable();
            $table->string('customer_acumatica_id', 50)->index();
            $table->string('customer_name', 255);
            $table->string('status', 30)->default('draft');
            $table->string('description', 255)->nullable();
            $table->decimal('quoted_total', 18, 2)->default(0);
            $table->string('currency_id', 10)->default('KES');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->json('acumatica_response')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'status', 'created_at']);
        });

        Schema::create('dtc_quote_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained('dtc_quotes')->cascadeOnDelete();
            $table->string('inventory_id', 80);
            $table->string('description', 255)->nullable();
            $table->string('uom', 30)->default('PIECE');
            $table->string('warehouse_id', 30)->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_price', 18, 6);
            $table->decimal('line_total', 18, 2);
            $table->date('price_effective_date')->nullable();
            $table->timestamps();
        });

        Schema::create('dtc_quote_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->unique()->constrained('dtc_quotes')->cascadeOnDelete();
            $table->string('status', 30)->default('pending');
            $table->string('acumatica_order_nbr', 50)->nullable()->unique();
            $table->string('acumatica_order_id', 100)->nullable();
            $table->decimal('order_total', 18, 2)->nullable();
            $table->decimal('price_variance', 18, 2)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->json('response_snapshot')->nullable();
            $table->timestamps();
        });

        Schema::create('dtc_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->string('sync_type', 30);
            $table->string('status', 30);
            $table->unsignedInteger('records_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        $permissionIds = [];
        foreach (['dtc.view', 'dtc.quotes.create', 'dtc.quotes.convert'] as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name], ['description' => 'DTC/DTB Calltronix ordering', 'updated_at' => now(), 'created_at' => now()]);
            $permissionIds[] = DB::table('permissions')->where('name', $name)->value('id');
        }
        $roleIds = DB::table('roles')->whereIn('name', ['Administrator', 'Sales Consultant', 'Sales Operations', 'Executive', 'HOD', 'Customer Service Manager'])->pluck('id');
        foreach ($roleIds as $roleId) foreach ($permissionIds as $permissionId) {
            DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('dtc_sync_logs');
        Schema::dropIfExists('dtc_quote_conversions');
        Schema::dropIfExists('dtc_quote_lines');
        Schema::dropIfExists('dtc_quotes');
        Schema::dropIfExists('dtc_prices');
        DB::table('permissions')->whereIn('name', ['dtc.view', 'dtc.quotes.create', 'dtc.quotes.convert'])->delete();
    }
};
