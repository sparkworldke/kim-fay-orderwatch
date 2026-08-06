<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DTC Price List — inventory-linked prices for PriceCode DTCACCOUNT.
 * Designed after the WooCommerce Acumatica plugin:
 * 1) Sync products (StockItem / local inventory)
 * 2) PUT SalesPricesInquiry → match InventoryID → store dtc_price
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dtc_price_list', function (Blueprint $table) {
            $table->id();
            $table->string('inventory_id', 80)->unique();
            $table->string('description', 255)->nullable();
            $table->string('uom', 30)->default('PIECE');
            $table->decimal('dtc_price', 18, 6)->nullable();
            $table->decimal('default_price', 18, 6)->nullable()->comment('Fallback from StockItem DefaultPrice / sales_price');
            $table->string('currency_id', 10)->default('KES');
            $table->string('price_code', 40)->default('DTCACCOUNT');
            $table->string('default_warehouse_id', 40)->nullable();
            $table->string('item_status', 40)->nullable();
            $table->decimal('qty_available', 18, 4)->nullable();
            $table->decimal('qty_on_hand', 18, 4)->nullable();
            $table->boolean('in_catalog')->default(true);
            $table->timestamp('product_synced_at')->nullable();
            $table->timestamp('price_synced_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['price_code', 'dtc_price']);
            $table->index('item_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dtc_price_list');
    }
};
