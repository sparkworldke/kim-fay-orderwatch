<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_channel_category_rules', function (Blueprint $table) {
            $table->id();
            $table->string('customer_category', 50);
            $table->string('sales_channel_code', 30);
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason')->nullable();
            $table->timestamps();
            $table->unique(['customer_category', 'sales_channel_code'], 'channel_category_unique');
            $table->index(['customer_category', 'is_active', 'priority'], 'channel_category_resolve_idx');
        });

        Schema::create('customer_sales_channel_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('customer_acumatica_id', 50)->unique();
            $table->string('sales_channel_code', 30);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_reason');
            $table->timestamps();
            $table->index(['sales_channel_code', 'is_active'], 'customer_channel_override_idx');
        });

        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->index(
                ['order_type', 'order_date', 'customer_acumatica_id'],
                'aso_type_date_customer_idx',
            );
        });

        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->index(['sales_order_id', 'inventory_id'], 'asol_order_inventory_idx');
        });

        Schema::table('inventory_warehouse_balances', function (Blueprint $table) {
            $table->index(['inventory_id', 'warehouse_id'], 'iwb_inventory_warehouse_idx');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_warehouse_balances', fn (Blueprint $table) => $table->dropIndex('iwb_inventory_warehouse_idx'));
        Schema::table('acumatica_sales_order_lines', fn (Blueprint $table) => $table->dropIndex('asol_order_inventory_idx'));
        Schema::table('acumatica_sales_orders', fn (Blueprint $table) => $table->dropIndex('aso_type_date_customer_idx'));
        Schema::dropIfExists('customer_sales_channel_overrides');
        Schema::dropIfExists('sales_channel_category_rules');
    }
};
