<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->string('approved_by_id', 50)->nullable()->after('approved_at');
        });

        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->decimal('cancelled_qty', 15, 4)->default(0)->after('open_qty');
            $table->decimal('qty_at_approval', 15, 4)->nullable()->after('cancelled_qty');
            $table->decimal('backorder_qty', 15, 4)->default(0)->after('qty_at_approval');
            $table->decimal('fill_rate_pct', 6, 2)->nullable()->after('backorder_qty');
            $table->string('line_type', 30)->nullable()->after('fill_rate_pct');
            $table->boolean('completed')->default(false)->after('line_type');
            $table->string('fulfillment_status', 60)->nullable()->after('completed');
        });

        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->decimal('cancelled_qty', 15, 4)->default(0)->after('open_qty');
            $table->decimal('backorder_qty', 15, 4)->default(0)->after('cancelled_qty');
            $table->string('fulfillment_status', 60)->nullable()->after('backorder_qty');
            $table->decimal('qty_at_approval', 15, 4)->nullable()->after('fulfillment_status');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->dropColumn(['cancelled_qty', 'backorder_qty', 'fulfillment_status', 'qty_at_approval']);
        });

        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->dropColumn([
                'cancelled_qty',
                'qty_at_approval',
                'backorder_qty',
                'fill_rate_pct',
                'line_type',
                'completed',
                'fulfillment_status',
            ]);
        });

        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropColumn('approved_by_id');
        });
    }
};