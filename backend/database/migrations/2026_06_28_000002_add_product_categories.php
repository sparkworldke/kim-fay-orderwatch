<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acumatica_product_categories')) {
            Schema::create('acumatica_product_categories', function (Blueprint $table) {
                $table->id();
                $table->string('acumatica_id', 100)->unique(); // ItemClass ClassID from Acumatica
                $table->string('description', 255)->nullable();
                $table->string('item_type', 50)->nullable();   // e.g. Finished Good, Raw Material
                $table->string('default_uom', 20)->nullable();
                $table->unsignedBigInteger('sync_run_id')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('acumatica_inventory_items') && ! Schema::hasColumn('acumatica_inventory_items', 'product_category_id')) {
            Schema::table('acumatica_inventory_items', function (Blueprint $table) {
                $table->unsignedBigInteger('product_category_id')->nullable()->after('item_class');
                $table->index('item_class');

                $table->foreign('product_category_id')
                    ->references('id')
                    ->on('acumatica_product_categories')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('acumatica_inventory_items') && Schema::hasColumn('acumatica_inventory_items', 'product_category_id')) {
            Schema::table('acumatica_inventory_items', function (Blueprint $table) {
                $table->dropForeign(['product_category_id']);
                $table->dropIndex(['item_class']);
                $table->dropColumn('product_category_id');
            });
        }

        Schema::dropIfExists('acumatica_product_categories');
    }
};
