<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dtc_quotes', function (Blueprint $table) {
            $table->json('customer_details_snapshot')->nullable()->after('customer_name');
        });
        Schema::table('dtc_quote_conversions', function (Blueprint $table) {
            $table->json('customer_details_snapshot')->nullable()->after('acumatica_order_id');
            $table->json('pos_lines_snapshot')->nullable()->after('customer_details_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('dtc_quote_conversions', fn (Blueprint $table) => $table->dropColumn(['customer_details_snapshot', 'pos_lines_snapshot']));
        Schema::table('dtc_quotes', fn (Blueprint $table) => $table->dropColumn('customer_details_snapshot'));
    }
};
