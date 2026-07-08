<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->decimal('qty_on_shipments', 15, 4)->default(0)->after('shipped_qty');
            $table->string('unfilled_reason_code', 80)->nullable()->after('fill_rate_pct');
            $table->index('unfilled_reason_code');
        });

        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->decimal('qty_on_shipments', 15, 4)->default(0)->after('shipped_qty');
        });

        Schema::table('acumatica_fill_rate_snapshots', function (Blueprint $table) {
            $table->unsignedSmallInteger('out_of_stock_line_count')->default(0)->after('revenue_not_shipped');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_fill_rate_snapshots', function (Blueprint $table) {
            $table->dropColumn('out_of_stock_line_count');
        });

        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->dropColumn('qty_on_shipments');
        });

        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->dropIndex(['unfilled_reason_code']);
            $table->dropColumn(['qty_on_shipments', 'unfilled_reason_code']);
        });
    }
};