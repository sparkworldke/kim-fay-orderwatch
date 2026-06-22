<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->string('customer_order', 100)->nullable()->after('customer_name'); // PO number (Customer Ord. field)
            $table->string('location_id', 100)->nullable()->after('customer_order');   // Acumatica LocationID (branch)

            $table->index('customer_order');
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->dropIndex(['customer_order']);
            $table->dropColumn(['customer_order', 'location_id']);
        });
    }
};
