<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sales_order_id');
            $table->integer('line_nbr')->default(0);
            $table->string('inventory_id', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->decimal('order_qty', 15, 4)->default(0);
            $table->decimal('unit_price', 15, 4)->default(0);
            $table->decimal('ext_cost', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->string('discount_code', 50)->nullable();
            $table->timestamps();

            $table->foreign('sales_order_id')
                ->references('id')
                ->on('acumatica_sales_orders')
                ->cascadeOnDelete();

            $table->index('inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_sales_order_lines');
    }
};
