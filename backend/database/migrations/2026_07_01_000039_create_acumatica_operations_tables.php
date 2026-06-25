<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->decimal('shipped_qty', 15, 4)->default(0)->after('order_qty');
            $table->decimal('open_qty', 15, 4)->default(0)->after('shipped_qty');
            $table->string('warehouse_id', 50)->nullable()->after('open_qty');
            $table->string('uom', 20)->nullable()->after('warehouse_id');
        });

        Schema::create('acumatica_inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_id', 100)->unique();
            $table->string('description', 500)->nullable();
            $table->string('item_class', 100)->nullable();
            $table->string('default_uom', 20)->nullable();
            $table->string('valuation_method', 50)->nullable();
            $table->boolean('is_stock_item')->default(true);
            $table->decimal('sales_price', 15, 4)->default(0);
            $table->string('default_warehouse_id', 50)->nullable();
            $table->decimal('qty_on_hand', 15, 4)->default(0);
            $table->decimal('qty_available', 15, 4)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->longText('raw_payload')->nullable();
            $table->timestamps();

            $table->index('default_warehouse_id');
            $table->index('qty_on_hand');
        });

        Schema::create('acumatica_inventory_run_rate_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventory_item_id');
            $table->string('inventory_id', 100);
            $table->decimal('qty_on_hand', 15, 4);
            $table->decimal('qty_delta', 15, 4)->nullable();
            $table->decimal('daily_run_rate', 15, 4)->nullable();
            $table->unsignedSmallInteger('days_until_stockout')->nullable();
            $table->string('prediction_status', 30)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('logged_at');
            $table->timestamps();

            $table->foreign('inventory_item_id')
                ->references('id')
                ->on('acumatica_inventory_items')
                ->cascadeOnDelete();

            $table->index(['inventory_id', 'logged_at']);
        });

        Schema::create('acumatica_backorder_lines', function (Blueprint $table) {
            $table->id();
            $table->string('order_nbr', 50);
            $table->string('inventory_id', 100);
            $table->string('customer_acumatica_id', 50)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->decimal('order_qty', 15, 4)->default(0);
            $table->decimal('shipped_qty', 15, 4)->default(0);
            $table->decimal('open_qty', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('revenue_at_risk', 15, 2)->default(0);
            $table->string('warehouse_id', 50)->nullable();
            $table->string('currency_id', 10)->nullable();
            $table->date('scheduled_shipment_date')->nullable();
            $table->date('requested_on')->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['order_nbr', 'inventory_id']);
            $table->index('customer_acumatica_id');
            $table->index('open_qty');
        });

        Schema::create('acumatica_fill_rate_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id')->nullable();
            $table->string('order_nbr', 50)->unique();
            $table->string('customer_acumatica_id', 50)->nullable();
            $table->string('status', 50)->nullable();
            $table->decimal('total_ordered_qty', 15, 4)->default(0);
            $table->decimal('total_shipped_qty', 15, 4)->default(0);
            $table->decimal('fill_rate_pct', 6, 2)->nullable();
            $table->string('fill_rate_status', 20)->default('na');
            $table->decimal('revenue_not_shipped', 15, 2)->default(0);
            $table->string('currency_id', 10)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->foreign('sales_order_id')
                ->references('id')
                ->on('acumatica_sales_orders')
                ->nullOnDelete();

            $table->index('fill_rate_status');
            $table->index('fill_rate_pct');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_fill_rate_snapshots');
        Schema::dropIfExists('acumatica_backorder_lines');
        Schema::dropIfExists('acumatica_inventory_run_rate_logs');
        Schema::dropIfExists('acumatica_inventory_items');

        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->dropColumn(['shipped_qty', 'open_qty', 'warehouse_id', 'uom']);
        });
    }
};