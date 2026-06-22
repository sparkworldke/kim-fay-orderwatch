<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            // Change date columns to timestamp so time-of-day is preserved
            $table->timestamp('order_date')->nullable()->change();
            $table->timestamp('ship_date')->nullable()->change();
            $table->timestamp('requested_on')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('acumatica_sales_orders', function (Blueprint $table) {
            $table->date('order_date')->nullable()->change();
            $table->date('ship_date')->nullable()->change();
            $table->date('requested_on')->nullable()->change();
        });
    }
};
