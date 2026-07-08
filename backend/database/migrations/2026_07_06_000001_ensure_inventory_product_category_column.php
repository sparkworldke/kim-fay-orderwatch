<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('acumatica_inventory_items') || Schema::hasColumn('acumatica_inventory_items', 'product_category_id')) {
            return;
        }

        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_category_id')->nullable()->after('item_class');
            $table->index('product_category_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('acumatica_inventory_items') || ! Schema::hasColumn('acumatica_inventory_items', 'product_category_id')) {
            return;
        }

        Schema::table('acumatica_inventory_items', function (Blueprint $table) {
            $table->dropIndex(['product_category_id']);
            $table->dropColumn('product_category_id');
        });
    }
};
