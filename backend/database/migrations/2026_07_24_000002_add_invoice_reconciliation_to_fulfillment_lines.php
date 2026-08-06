<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->decimal('invoiced_qty', 15, 4)->nullable()->after('qty_on_shipments');
            $table->string('invoice_reconciliation_status', 30)->nullable()->after('invoiced_qty');
            $table->string('fulfillment_source', 40)->nullable()->after('invoice_reconciliation_status');
        });

        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->decimal('invoiced_qty', 15, 4)->nullable()->after('qty_on_shipments');
            $table->string('shortfall_kind', 30)->default('active_backorder')->after('invoiced_qty');
            $table->string('order_status', 50)->nullable()->after('shortfall_kind');
            $table->string('invoice_reconciliation_status', 30)->nullable()->after('order_status');
            $table->string('fulfillment_source', 40)->nullable()->after('invoice_reconciliation_status');
            $table->index('shortfall_kind');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_backorder_lines', function (Blueprint $table) {
            $table->dropIndex(['shortfall_kind']);
            $table->dropColumn([
                'invoiced_qty',
                'shortfall_kind',
                'order_status',
                'invoice_reconciliation_status',
                'fulfillment_source',
            ]);
        });

        Schema::table('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->dropColumn([
                'invoiced_qty',
                'invoice_reconciliation_status',
                'fulfillment_source',
            ]);
        });
    }
};
