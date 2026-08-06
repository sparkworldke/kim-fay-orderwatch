<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_customers', function (Blueprint $table) {
            $table->string('sales_region', 100)->nullable()->after('sales_channel_code');
            $table->index(['sales_channel_code', 'sales_region'], 'customer_channel_region_idx');
        });

        Schema::table('customer_sales_channel_overrides', function (Blueprint $table) {
            $table->string('sales_region', 100)->nullable()->after('sales_channel_code');
            $table->index(['sales_channel_code', 'sales_region', 'is_active'], 'override_channel_region_idx');
        });
    }

    public function down(): void
    {
        Schema::table('customer_sales_channel_overrides', function (Blueprint $table) {
            $table->dropIndex('override_channel_region_idx');
            $table->dropColumn('sales_region');
        });
        Schema::table('acumatica_customers', function (Blueprint $table) {
            $table->dropIndex('customer_channel_region_idx');
            $table->dropColumn('sales_region');
        });
    }
};
