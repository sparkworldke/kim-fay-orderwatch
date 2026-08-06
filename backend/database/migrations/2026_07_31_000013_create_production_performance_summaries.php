<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_sku_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_id', 100);
            $table->string('warehouse_id', 50)->default('');
            $table->date('month');
            $table->decimal('ordered_qty', 18, 4)->default(0);
            $table->decimal('delivered_qty', 18, 4)->default(0);
            $table->decimal('missed_qty', 18, 4)->default(0);
            $table->decimal('missed_revenue', 20, 4)->nullable();
            $table->decimal('priced_missed_qty', 18, 4)->default(0);
            $table->boolean('revenue_complete')->default(true);
            $table->string('currency_id', 10)->nullable();
            $table->string('source_version', 64)->nullable();
            $table->timestamp('source_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_id', 'warehouse_id', 'month'], 'monthly_sku_summary_identity');
            $table->index(['month', 'inventory_id', 'warehouse_id'], 'monthly_sku_summary_lookup');
        });

        Schema::create('daily_stock_summaries', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_id', 100);
            $table->string('warehouse_id', 50);
            $table->date('summary_date');
            $table->decimal('qty_on_hand', 18, 4)->nullable();
            $table->decimal('qty_available', 18, 4)->nullable();
            $table->decimal('qty_allocated', 18, 4)->nullable();
            $table->decimal('msi', 18, 4)->nullable();
            $table->decimal('months_of_cover', 12, 4)->nullable();
            $table->string('msi_status', 20)->nullable();
            $table->string('source_version', 64)->nullable();
            $table->timestamp('source_refreshed_at')->nullable();
            $table->timestamps();

            $table->unique(['inventory_id', 'warehouse_id', 'summary_date'], 'daily_stock_summary_identity');
            $table->index(['summary_date', 'inventory_id', 'warehouse_id'], 'daily_stock_summary_lookup');
        });

        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->index(['order_type', 'order_date', 'id'], 'aso_type_date_id_idx');
        });
        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->index(['inventory_id', 'sales_order_id', 'warehouse_id'], 'asol_inventory_order_wh_idx');
        });
        Schema::table('inventory_warehouse_balances', function (Blueprint $table) {
            $table->index(['warehouse_id', 'inventory_id'], 'iwb_warehouse_inventory_idx');
        });
        Schema::table('products', function (Blueprint $table) {
            $table->index(['ownership', 'brand_id', 'category_id', 'trading_group_id'], 'products_production_filters_idx');
        });
        Schema::table('production_sku_plans', function (Blueprint $table) {
            $table->index(['ownership', 'site', 'business_line'], 'production_plans_filter_idx');
        });
    }

    public function down(): void
    {
        Schema::table('production_sku_plans', fn (Blueprint $table) => $table->dropIndex('production_plans_filter_idx'));
        Schema::table('products', fn (Blueprint $table) => $table->dropIndex('products_production_filters_idx'));
        Schema::table('inventory_warehouse_balances', fn (Blueprint $table) => $table->dropIndex('iwb_warehouse_inventory_idx'));
        Schema::table('acumatica_sales_order_lines', fn (Blueprint $table) => $table->dropIndex('asol_inventory_order_wh_idx'));
        Schema::table('acumatica_sales_orders', fn (Blueprint $table) => $table->dropIndex('aso_type_date_id_idx'));
        Schema::dropIfExists('daily_stock_summaries');
        Schema::dropIfExists('monthly_sku_summaries');
    }
};
