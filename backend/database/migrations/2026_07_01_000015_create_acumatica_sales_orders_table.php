<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acumatica_sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('acumatica_order_nbr', 50)->unique(); // e.g. "SO358387"
            $table->string('order_type', 10)->default('SO');
            $table->string('customer_acumatica_id', 50)->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->string('status', 50)->nullable();
            $table->date('order_date')->nullable();
            $table->timestamp('last_modified_at')->nullable();
            $table->date('ship_date')->nullable();
            $table->date('requested_on')->nullable();
            $table->decimal('order_total', 15, 2)->default(0);
            $table->string('currency_id', 10)->nullable();
            $table->unsignedBigInteger('sync_run_id')->nullable();
            $table->longText('raw_payload')->nullable(); // JSON
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index('customer_acumatica_id');
            $table->index('order_date');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acumatica_sales_orders');
    }
};
